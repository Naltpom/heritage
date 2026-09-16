/**
 * **Refaire une demande caduque** — § 6.10.
 *
 * > « Une caducité sans ce bouton est une contribution perdue, et un membre dont
 * > la première contribution se perd n'en dépose pas de seconde. » (§ 9.6)
 *
 * `GET /api/demandes/{id}/refaire` est en **lecture seule** et rend un corps
 * « postable en l'état », pré-rempli sur les valeurs courantes. Ce module le
 * relit pour en refaire un état de formulaire.
 *
 * Trois points du contrat que la relecture doit respecter, et qui se trompent
 * dans cet ordre :
 *
 * 1. **`localRef` est conservé** (`#p1` reste `#p1`). Le changer casserait le
 *    rattachement des médias, qui le portent.
 * 2. **`resolvedPersonId` et `resolvedFamilyId` ne sont jamais repris** — le
 *    serveur ne les renvoie d'ailleurs pas. « Une reprise redécrit une
 *    *intention*, pas un résultat » : la demande refaite doit redire « conjoint
 *    de X », pas « rattaché à la famille 47 ». `familyId`, lui, **est** repris :
 *    c'est l'intention de l'auteur, pas le résultat de la déduction.
 * 3. **`origine` doit repartir dans le `POST`.** C'est sa seule fonction : faire
 *    suivre vers la demande neuve les médias déjà téléversés, qui pointent
 *    encore vers la demande morte. L'oublier laisse les pièces jointes
 *    accrochées à une demande que plus personne ne regardera.
 *
 * Les **avertissements** sont affichés tels quels : une référence disparue a été
 * retirée du corps par le serveur, et le contributeur doit savoir qu'un de ses
 * liens a sauté — sans quoi il redéposerait une demande amputée sans le voir.
 *
 * **Les libellés de personnes ne sont pas dans le corps rendu**, qui ne porte
 * que des identifiants. Ils sont résolus par une lecture de fiche : afficher
 * « personne n° 55 » mettrait un identifiant sous les yeux du lecteur, ce que le
 * dépôt s'interdit partout ailleurs.
 */

import {
  lireFichePersonne,
  lireRepriseDemande,
  type NatureDemande,
  type QualificatifDate,
  type ValeurDate,
  type OrigineReprise,
  type Sexe,
  type StatutDemande,
} from '@/lib/api'
import { CHAMPS_PERSONNE, type ChampPersonne } from './champs'
import { designeUneDate, saisieDepuisBloc, type SaisieDate } from './dates'
import {
  brouillonVide,
  creationVide,
  unionVide,
  type PersonneChoisie,
  type SaisieCreation,
} from './creation'

/* ------------------------------------------------------------------ */
/* Lecture défensive                                                   */
/* ------------------------------------------------------------------ */

function objet(valeur: unknown): Record<string, unknown> | null {
  return typeof valeur === 'object' && valeur !== null && !Array.isArray(valeur)
    ? (valeur as Record<string, unknown>)
    : null
}

function tableau(valeur: unknown): unknown[] {
  return Array.isArray(valeur) ? valeur : []
}

function chaine(valeur: unknown): string {
  return typeof valeur === 'string' ? valeur : ''
}

function nombre(valeur: unknown): number | null {
  return typeof valeur === 'number' && Number.isFinite(valeur) ? valeur : null
}

/**
 * Les qualificatifs acceptés à la relecture — **dérivés, jamais recopiés**.
 *
 * La table `Record<QualificatifDate, true>` n'est pas une coquetterie : c'est
 * elle qui fait **échouer la compilation** le jour où le contrat ajoute une
 * valeur. La version précédente était une liste typée `readonly string[]`, où le
 * compilateur ne voyait rien, et il y manquait `mois`, `depuis` et `jusqu_a`.
 *
 * Conséquence de ce manque, et elle était silencieuse : une date `JAN 1920` était
 * refusée ici, donc `lireBlocDate` rendait `null`, donc la date **disparaissait
 * du formulaire de reprise** — sur le chemin même dont l'en-tête de ce fichier
 * dit qu'« une contribution s'y perd ».
 */
const QUALIFICATIFS_CONNUS: Record<QualificatifDate, true> = {
  exacte: true,
  annee: true,
  mois: true,
  environ: true,
  avant: true,
  apres: true,
  entre: true,
  depuis: true,
  jusqu_a: true,
  inconnue: true,
}

function estQualificatif(valeur: string): valeur is QualificatifDate {
  return Object.hasOwn(QUALIFICATIFS_CONNUS, valeur)
}

/**
 * Relit un bloc date, ou rend `null`.
 *
 * Le contrôle porte sur `qualifier` : c'est lui qui porte l'incertitude, et un
 * bloc sans qualificatif reconnu ne peut pas être remis dans un champ de saisie
 * sans risquer d'y perdre sa nature. Mieux vaut un champ vide qu'une date
 * silencieusement transformée.
 */
export function lireBlocDate(valeur: unknown): ValeurDate | null {
  const bloc = objet(valeur)
  if (bloc === null) return null
  const qualifier = chaine(bloc['qualifier'])
  if (!estQualificatif(qualifier)) return null

  // `ValeurDate` et non `BlocDate` : le corps de reprise est « postable en
  // l'état », donc à cinq clés, sans `texte` (§ 14.1). Fabriquer un `texte`
  // ici — fût-il vide — ferait repartir une sixième clé dans le `POST`, que la
  // comparaison `JSONB` de la détection de conflit ferait diverger (§ 9.6).
  return {
    raw: typeof bloc['raw'] === 'string' ? bloc['raw'] : null,
    qualifier,
    min: typeof bloc['min'] === 'string' ? bloc['min'] : null,
    max: typeof bloc['max'] === 'string' ? bloc['max'] : null,
    place: typeof bloc['place'] === 'string' ? bloc['place'] : null,
  }
}

/**
 * Le bloc date **tel qu'il entre dans un champ de saisie** — ou rien.
 *
 * `lireBlocDate()` répond à « ce bloc est-il lisible ? ». Celle-ci répond à la
 * question suivante, qui n'est pas la même : « y a-t-il une date à remettre dans
 * le formulaire ? ». La distinction n'a rien de théorique.
 *
 * `GET /api/demandes/{id}/refaire` rend, sur un lien `conjoint_de` qui n'a
 * jamais été rompu, `divorced: false` **avec** un bloc `divorce` non nul :
 * `qualifier: "inconnue"`, les quatre autres clés à `null`. Ce bloc ne désigne
 * aucune date. Le laisser entrer dans le champ enclenchait alors une mécanique
 * en trois temps, dont aucun n'est visible :
 *
 * 1. `saisieDepuisBloc()` en tire une saisie **vide** mais d'`origine` non
 *    nulle — il n'y a rien à afficher, et pourtant il y a quelque chose à
 *    préserver ;
 * 2. `relectureFidele()` ne sait pas recomposer « rien » en cet objet-là, donc
 *    le champ se **verrouille** : le contributeur ne peut ni le remplir ni le
 *    vider ;
 * 3. `valeurAEnvoyer()` ressort l'origine telle quelle, et
 *    `corpsCreationPersonne()` en conclut qu'« une date de rupture est
 *    indiquée » et réclame de cocher « union rompue » **ou d'effacer la date**.
 *
 * L'auteur d'un ajout refusé qui portait un conjoint lisait donc « effacez la
 * date » devant un champ vide et verrouillé. Le seul contournement — retirer le
 * conjoint, puis le rajouter — ne se devine pas. Reprendre sa demande, c'est-à-
 * dire précisément ce que le § 6.10 appelle ne pas perdre une contribution,
 * était impossible.
 *
 * On lit donc l'absence pour ce qu'elle est : le champ s'ouvre **vide et
 * modifiable**, et rien ne repart. Le traitement vaut pour les quatre blocs
 * d'une reprise — naissance, décès, mariage, rupture — parce que le défaut est
 * dans la lecture, pas dans le champ qui le reçoit.
 *
 * *(Ce bloc `divorce` non nul sous un `divorced: false` est signalé au lot
 * serveur ; le front n'a pas à en dépendre pour autant — une reprise doit
 * survivre à un bloc qui ne dit rien, d'où qu'il vienne.)*
 */
function blocDeSaisie(valeur: unknown): ValeurDate | null {
  const bloc = lireBlocDate(valeur)
  return designeUneDate(bloc) ? bloc : null
}

function lireSexe(valeur: unknown): Sexe {
  const sexe = chaine(valeur)
  return sexe === 'masculin' || sexe === 'feminin' ? sexe : 'inconnu'
}

/* ------------------------------------------------------------------ */
/* Ce que la relecture rend                                            */
/* ------------------------------------------------------------------ */

export interface Avertissement {
  code: string
  message: string
}

/** Les valeurs proposées, par champ. Seuls les champs de la surface éditable
 *  sont retenus : le serveur n'en produit pas d'autres, et un champ inconnu
 *  n'aurait nulle part où s'afficher. */
export type ValeursProposees = Partial<Record<ChampPersonne, unknown>>

export interface RepriseModification {
  kind: 'modification_personne'
  message: string
  origine: OrigineReprise | null
  targetId: number | null
  proposees: ValeursProposees
  avertissements: Avertissement[]
}

export interface RepriseCreation {
  kind: 'creation_personne'
  message: string
  origine: OrigineReprise | null
  saisie: SaisieCreation
  /** Les personnes à nommer : le corps ne porte que des identifiants. */
  aResoudre: number[]
  avertissements: Avertissement[]
}

export type Reprise = RepriseModification | RepriseCreation

const CHAMPS_CONNUS = new Set<string>(CHAMPS_PERSONNE.map((description) => description.champ))

function lireOrigine(valeur: unknown): OrigineReprise | null {
  const bloc = objet(valeur)
  if (bloc === null) return null
  const demandeId = nombre(bloc['demandeId'])
  if (demandeId === null) return null
  return { demandeId, statut: chaine(bloc['statut']) as StatutDemande }
}

function lireAvertissements(valeur: unknown): Avertissement[] {
  return tableau(valeur)
    .map((brut) => objet(brut))
    .filter((bloc): bloc is Record<string, unknown> => bloc !== null)
    .map((bloc) => ({ code: chaine(bloc['code']), message: chaine(bloc['message']) }))
    .filter((avertissement) => avertissement.message.length > 0)
}

/**
 * Relit un corps de reprise.
 *
 * **Seules les valeurs proposées sont reprises d'une modification**, jamais
 * `oldValue` ni `baseVersion` : le formulaire les reconstruit depuis la fiche
 * qu'il vient de lire, et c'est la lecture de l'auteur qui doit faire foi. Les
 * reprendre du corps rendu ferait voyager une version que personne n'a eue sous
 * les yeux.
 */
export function analyserReprise(brut: unknown): Reprise | null {
  const corps = objet(brut)
  if (corps === null) return null

  const kind = chaine(corps['kind']) as NatureDemande
  const message = chaine(corps['message'])
  const origine = lireOrigine(corps['origine'])
  const avertissements = lireAvertissements(corps['avertissements'])

  if (kind === 'modification_personne') {
    const proposees: ValeursProposees = {}
    for (const op of tableau(corps['ops'])) {
      const bloc = objet(op)
      if (bloc === null) continue
      const field = chaine(bloc['field'])
      if (!CHAMPS_CONNUS.has(field)) continue
      proposees[field as ChampPersonne] = bloc['newValue'] ?? null
    }

    return {
      kind,
      message,
      origine,
      targetId: nombre(corps['targetId']),
      proposees,
      avertissements,
    }
  }

  if (kind === 'creation_personne') {
    const saisie = creationVide()
    const aResoudre: number[] = []

    const brouillon = objet(tableau(corps['brouillons'])[0])
    if (brouillon !== null) {
      const localRef = chaine(brouillon['localRef'])
      // « `localRef` est conservé » : le changer casserait le rattachement des
      // médias, qui le portent.
      if (localRef.length > 0) saisie.localRef = localRef
      saisie.brouillon = {
        ...brouillonVide(),
        givenNames: chaine(brouillon['givenNames']),
        surname: chaine(brouillon['surname']),
        marriedName: chaine(brouillon['marriedName']),
        nickname: chaine(brouillon['nickname']),
        sex: lireSexe(brouillon['sex']),
        deceased: brouillon['deceased'] === true,
        naissance: saisieDepuisBloc(blocDeSaisie(brouillon['birth'])),
        deces: saisieDepuisBloc(blocDeSaisie(brouillon['death'])),
        note: chaine(brouillon['note']),
      }
    }

    for (const lien of tableau(corps['liens'])) {
      const bloc = objet(lien)
      if (bloc === null) continue
      const nature = chaine(bloc['kind'])
      const autre = nombre(bloc['otherPersonId'])

      if (nature === 'enfant_de') {
        const familyId = nombre(bloc['familyId'])
        if (familyId !== null) {
          // `family_id` est **l'intention** de l'auteur (« enfant de ce couple
          // existant ») ; `resolved_family_id` est le résultat d'une déduction
          // passée, et lui n'est jamais repris.
          saisie.filiation.famille = { familyId, libelle: `couple n° ${familyId}` }
          continue
        }
        for (const parent of [autre, nombre(bloc['other2PersonId'])]) {
          if (parent === null) continue
          saisie.filiation.parents.push(reference(parent))
          aResoudre.push(parent)
        }
        continue
      }

      if (autre === null) continue

      if (nature === 'conjoint_de') {
        const union = unionVide(reference(autre))
        union.mariage = saisieDepuisBloc(blocDeSaisie(bloc['marriage']))
        union.rompue = bloc['divorced'] === true
        union.rupture = saisieDepuisBloc(blocDeSaisie(bloc['divorce']))
        union.note = chaine(bloc['note'])
        saisie.unions.push(union)
        aResoudre.push(autre)
        continue
      }

      if (nature === 'parent_de') {
        saisie.enfants.push({ personne: reference(autre), rangFratrie: nombre(bloc['siblingOrder']) })
        aResoudre.push(autre)
      }
    }

    return { kind, message, origine, saisie, aResoudre, avertissements }
  }

  return null
}

/** Un libellé d'attente, remplacé dès que la fiche répond. Il n'est jamais
 *  affiché tel quel dans le cas nominal — c'est un garde-fou, pas un rendu. */
function reference(personId: number): PersonneChoisie {
  return { personId, libelle: '…' }
}

/* ------------------------------------------------------------------ */
/* Résolution des libellés                                             */
/* ------------------------------------------------------------------ */

/**
 * Remplace les libellés d'attente par les noms rendus par l'API.
 *
 * Une fiche **restreinte** porte elle aussi `displayName` : une personne vivante
 * hors cercle garde un nom d'affichage, c'est tout ce qu'elle expose (§ 11.1).
 * On n'a donc jamais besoin d'exposer un identifiant à l'écran.
 *
 * Une lecture qui échoue n'interrompt rien : le lien reste, avec son libellé
 * d'attente remplacé par une mention honnête. Perdre le formulaire entier parce
 * qu'un nom manque serait le pire des deux maux.
 */
export async function resoudreLibelles(reprise: RepriseCreation): Promise<void> {
  const noms = new Map<number, string>()

  await Promise.all(
    [...new Set(reprise.aResoudre)].map(async (personId) => {
      try {
        const fiche = await lireFichePersonne(personId)
        noms.set(personId, fiche.displayName)
      } catch {
        noms.set(personId, 'personne introuvable')
      }
    }),
  )

  const nommer = (personne: PersonneChoisie): void => {
    const nom = noms.get(personne.personId)
    if (nom !== undefined) personne.libelle = nom
  }

  reprise.saisie.filiation.parents.forEach(nommer)
  reprise.saisie.unions.forEach((union) => nommer(union.personne))
  reprise.saisie.enfants.forEach((enfant) => nommer(enfant.personne))
}

/** Lit la reprise et la rend prête à afficher. */
export async function chargerReprise(demandeId: number): Promise<Reprise | null> {
  const reprise = analyserReprise(await lireRepriseDemande(demandeId))
  if (reprise !== null && reprise.kind === 'creation_personne') await resoudreLibelles(reprise)
  return reprise
}

/** Applique les valeurs proposées d'une reprise à un champ de saisie de date. */
export function saisieDepuisProposee(valeur: unknown): SaisieDate {
  return saisieDepuisBloc(blocDeSaisie(valeur))
}

/**
 * La phrase qui annonce d'où vient la reprise — **avec l'état réel de la demande
 * reprise**, jamais un état supposé.
 *
 * `RefaireDemande` n'ouvre la reprise que sur **deux** fins de course, et elles ne
 * se confondent pas : « `refaire` n'a de sens que sur une demande morte :
 * `caduque` ou `refusee` » (`Proposal/Exception/Conflit.php`, et le contrôle lui-
 * même dans `Proposal/Service/RefaireDemande.php`). L'écran de modification
 * écrivait « devenue caduque » **en dur**, donc annonçait une caducité à l'auteur
 * d'une demande que quelqu'un avait **refusée** — le seul des deux cas où une
 * personne a tranché, et le seul où l'auteur a une raison de relire ce qu'il
 * redépose. « Personne n'a refusé une demande caduque, le monde a bougé »
 * (ADR 0004) : dire l'un pour l'autre efface précisément cette distinction, que
 * `PastilleEtat` prend par ailleurs soin de tenir.
 *
 * Le statut arrive d'un `as StatutDemande` non contrôlé ({@see lireOrigine}) : il
 * peut donc être vide ou inconnu si le serveur change. Dans ce cas on ne qualifie
 * **rien** plutôt que de deviner — une phrase muette est honnête, une phrase
 * fausse ne l'est pas.
 */
export function phraseOrigine(origine: OrigineReprise): string {
  const qualificatif =
    origine.statut === 'refusee'
      ? ', refusée'
      : origine.statut === 'caduque'
        ? ', devenue caduque'
        : ''

  return `Cette demande reprend la demande n° ${origine.demandeId}${qualificatif}.`
}
