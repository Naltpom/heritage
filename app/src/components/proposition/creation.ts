/**
 * Proposer **une personne et ses liens** — § 6.3, et § 6.4 du dossier.
 *
 * « Sur ce même principe » : c'est l'instruction centrale du commanditaire, et
 * elle se lit dans ce fichier plutôt que dans un écran. Une création n'est pas
 * un formulaire de création — c'est **une demande**, de nature
 * `creation_personne`, qui part dans la même file, se valide et se refuse de la
 * même façon, avec le même message obligatoire au refus. Il n'y a qu'un
 * mécanisme (ADR 0004).
 *
 * ────────────────────────────────────────────────────────────────────
 * **Les quatre règles que ce module fait respecter à la source**, parce
 * qu'elles sont des `CHECK` en base et que les découvrir en `422` après avoir
 * rempli quatre blocs de liens est une façon sûre de perdre un contributeur :
 *
 * 1. **Au moins un lien**, « sinon la personne flotterait hors de l'arbre ».
 *    Contrôlé à la **soumission**, pas à l'enregistrement : on peut garder un
 *    brouillon incomplet, on ne peut pas le soumettre (`demande_sans_lien`).
 * 2. **Une filiation désigne soit un couple existant (`familyId`), soit un ou
 *    deux parents (`otherPersonId` + `other2PersonId`). Jamais les deux**
 *    (`ck_prp_link_cible`, `ck_prp_link_famille_filiation`) — « le service ne
 *    saurait pas laquelle des deux intentions respecter ».
 * 3. **Les dates d'union ne valent que sur `conjoint_de`**
 *    (`ck_prp_link_dates_union`), et **toutes sont facultatives : une union
 *    sans date reste une union**. Une rupture sans date reste une rupture.
 * 4. **La demande ne décrit aucune famille.** Il n'existe pas de brouillon de
 *    famille : la demande dit l'intention (« conjoint de X »), « le service en
 *    déduit les familles à créer au moment de la validation ». Un front qui
 *    enverrait un `familleRef` serait refusé en `422`.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Le sens des trois natures de lien se lit du sujet vers l'autre**, et c'est
 * le piège du § 6.4 du dossier :
 *
 * | `kind` | Ce que `otherPersonId` désigne |
 * |---|---|
 * | `enfant_de` | **les parents** de la personne proposée (un, deux, ou un couple) |
 * | `parent_de` | **un enfant** de la personne proposée |
 * | `conjoint_de` | **un conjoint** |
 *
 * C'est confirmé par le modèle : `other2_person_id` y est décrit comme « le
 * **second parent**, quand `kind = 'enfant_de'` ». Lire l'inverse produirait un
 * arbre à l'envers, sans qu'aucun type ne s'en plaigne.
 */

import type {
  BrouillonPropose,
  CorpsCreationPersonne,
  LienPropose,
  Sexe,
  ValeurDate,
} from '@/lib/api'
import { saisieVide, valeurAEnvoyer, type SaisieDate } from './dates'
import { texteOuNull, type OptionsDepot } from './operations'

/* ------------------------------------------------------------------ */
/* L'état du formulaire                                                */
/* ------------------------------------------------------------------ */

/**
 * Une personne existante désignée à l'écran.
 *
 * Le **libellé** est celui que le serveur a rendu dans la recherche (§ 5.5) : on
 * ne le recompose pas, et on n'en fabrique pas pour une personne que le serveur
 * n'a pas nommée. « Sélectionner une personne existante, ce n'est pas lui donner
 * accès » — la recherche est déjà filtrée par le cercle de visibilité, et ce qui
 * n'en sort pas n'a pas à réapparaître ici.
 */
export interface PersonneChoisie {
  personId: number
  libelle: string
}

/**
 * Une personne **proposée et non encore validée**, désignée à l'écran.
 *
 * C'est la réponse au défaut signalé par le commanditaire : « le père que j'ai
 * proposé juste avant, non validé encore mais proposé par moi, il ne m'est pas
 * proposé ». Elle n'a pas de `personId` — elle n'existe pas dans l'arbre —, et
 * ce qu'on reposte est l'identifiant de sa **ligne**, `draftPersonId`.
 *
 * `demandeId` est porté pour l'écran seulement : « proposée dans votre demande
 * n° 12 » est ce qui évite qu'un contributeur croie désigner une personne
 * validée. Le serveur ne s'en sert pas — il retrouve la demande depuis la ligne.
 */
export interface FicheProposeeChoisie {
  draftPersonId: number
  demandeId: number
  libelle: string
}

/**
 * L'un ou l'autre — **jamais les deux**, et c'est un `CHECK` en base
 * (`ck_prp_link_cible`).
 *
 * Une union discriminée plutôt qu'un objet à deux champs facultatifs : c'est le
 * typeur qui empêche alors d'écrire les deux clés dans un lien, là où deux
 * `number | undefined` laisseraient passer un corps que le serveur refuserait
 * en `422` — et le front ne saurait pas dire pourquoi.
 */
export type ChoixDePersonne = PersonneChoisie | FicheProposeeChoisie

export function estFicheProposee(choix: ChoixDePersonne): choix is FicheProposeeChoisie {
  return 'draftPersonId' in choix
}

/**
 * La clé d'un choix, pour un `:key` de liste et pour « déjà désigné ».
 *
 * Préfixée par la nature : une personne n° 7 de l'arbre et une fiche proposée
 * n° 7 sont deux choses différentes, et une clé qui les confondrait éteindrait
 * le bouton de l'une parce que l'autre est déjà dans le formulaire.
 */
export function cleDuChoix(choix: ChoixDePersonne): string {
  return estFicheProposee(choix) ? 'brouillon:'.concat(String(choix.draftPersonId)) : 'personne:'.concat(String(choix.personId))
}

/**
 * Les clés d'un lien qui désignent ce choix à l'autre extrémité.
 *
 * Écrit **une fois** : c'est l'endroit où l'on choisit entre `otherPersonId` et
 * `otherDraftPersonId`, et trois appelants faisaient le même `if` — jusqu'au
 * jour où l'un des trois ne l'aurait pas fait.
 *
 * Deux fonctions et non une avec un paramètre de préfixe : une seule rendrait
 * l'union des quatre formes, et le typeur laisserait alors écrire un
 * `other2PersonId` sur une union — que `ck_prp_link_second_parent` refuse.
 */
export function autreDuChoix(
  choix: ChoixDePersonne,
): { otherPersonId: number } | { otherDraftPersonId: number } {
  return estFicheProposee(choix)
    ? { otherDraftPersonId: choix.draftPersonId }
    : { otherPersonId: choix.personId }
}

/** Le **second parent** d'une filiation — `enfant_de` seulement. */
export function secondParentDuChoix(
  choix: ChoixDePersonne,
): { other2PersonId: number } | { other2DraftPersonId: number } {
  return estFicheProposee(choix)
    ? { other2DraftPersonId: choix.draftPersonId }
    : { other2PersonId: choix.personId }
}

/** Un couple existant. Le formulaire ne propose pas de le désigner — mais une
 *  reprise peut en rapporter un, et « `family_id` est conservé » (§ 6.10). */
export interface FamilleChoisie {
  familyId: number
  libelle: string
}

/** Les parents de la personne proposée : un couple, **ou** un ou deux parents. */
export interface SaisieFiliation {
  famille: FamilleChoisie | null
  parents: ChoixDePersonne[]
}

/** Un enfant de la personne proposée. `rangFratrie` est le « c'était l'aîné »
 *  du modèle : absent, l'enfant est rattaché à la fin. */
export interface SaisieEnfant {
  personne: ChoixDePersonne
  rangFratrie: number | null
}

/**
 * Une union.
 *
 * `rompue` et `rupture` sont **distincts** : `rompue` dit qu'il y a eu rupture,
 * `rupture` en porte la date, qui peut manquer. Exiger la date pour enregistrer
 * la rupture l'effacerait — et sur un arbre ancien, la date manque le plus
 * souvent.
 */
export interface SaisieUnion {
  personne: ChoixDePersonne
  mariage: SaisieDate
  rompue: boolean
  rupture: SaisieDate
  note: string
}

/** La fiche de la personne proposée. Mêmes champs qu'une modification, moins
 *  les deux réglages de confidentialité — qui ne s'appliquent qu'à une fiche
 *  déjà en base — et plus une note de justification. */
export interface SaisieBrouillon {
  givenNames: string
  surname: string
  marriedName: string
  nickname: string
  sex: Sexe
  deceased: boolean
  naissance: SaisieDate
  deces: SaisieDate
  note: string
}

export interface SaisieCreation {
  /** `#p<n>`, unique dans la demande. **Conservé tel quel par une reprise** :
   *  le changer casserait le rattachement des médias, qui le portent (§ 6.10). */
  localRef: string
  brouillon: SaisieBrouillon
  filiation: SaisieFiliation
  enfants: SaisieEnfant[]
  unions: SaisieUnion[]
}

export function brouillonVide(): SaisieBrouillon {
  return {
    givenNames: '',
    surname: '',
    marriedName: '',
    nickname: '',
    sex: 'inconnu',
    deceased: false,
    naissance: saisieVide(),
    deces: saisieVide(),
    note: '',
  }
}

export function creationVide(): SaisieCreation {
  return {
    localRef: '#p1',
    brouillon: brouillonVide(),
    filiation: { famille: null, parents: [] },
    enfants: [],
    unions: [],
  }
}

export function unionVide(personne: ChoixDePersonne): SaisieUnion {
  return { personne, mariage: saisieVide(), rompue: false, rupture: saisieVide(), note: '' }
}

/* ------------------------------------------------------------------ */
/* Le résultat de la validation                                        */
/* ------------------------------------------------------------------ */

/** Une erreur rattachée à un endroit précis du formulaire, pour que le message
 *  s'affiche **sous le champ** et non dans un bandeau générique. */
export interface ErreurSaisie {
  /** `identite.surname`, `naissance`, `unions.0.mariage`, `liens`… */
  champ: string
  message: string
}

export type ResultatCreation =
  | { valide: true; corps: CorpsCreationPersonne }
  | { valide: false; erreurs: ErreurSaisie[] }

/* ------------------------------------------------------------------ */
/* Construction des liens                                              */
/* ------------------------------------------------------------------ */

/**
 * Les liens, dans l'ordre du formulaire : filiation, unions, enfants.
 *
 * L'ordre des `seq` est celui de lecture de l'écran. Il n'a pas d'incidence
 * métier — le service déduit les familles à la validation — mais il doit être
 * **stable** : « les ops se rejouent dans cet ordre, toujours », et la même
 * exigence vaut pour les liens, dont `uniq_prp_draft_link_seq` garantit l'ordre
 * total.
 */
export function construireLiens(saisie: SaisieCreation): {
  liens: LienPropose[]
  erreurs: ErreurSaisie[]
} {
  const liens: LienPropose[] = []
  const erreurs: ErreurSaisie[] = []
  const sujet = saisie.localRef

  /* --- Filiation : un couple, ou un ou deux parents --------------- */
  const { famille, parents } = saisie.filiation

  if (famille !== null && parents.length > 0) {
    erreurs.push({
      champ: 'filiation',
      message:
        'Désignez soit le couple, soit les parents un par un — pas les deux : le service ne saurait pas laquelle des deux intentions respecter.',
    })
  } else if (famille !== null) {
    liens.push({ seq: liens.length + 1, kind: 'enfant_de', subjectDraftRef: sujet, familyId: famille.familyId })
  } else if (parents.length > 0) {
    if (parents.length > 2) {
      erreurs.push({ champ: 'filiation', message: 'Une personne ne peut avoir plus de deux parents désignés.' })
    } else if (parents.length === 2 && cleDuChoix(parents[0]!) === cleDuChoix(parents[1]!)) {
      erreurs.push({ champ: 'filiation', message: 'Les deux parents désignés sont la même personne.' })
    } else {
      const lien: LienPropose = {
        seq: liens.length + 1,
        kind: 'enfant_de',
        subjectDraftRef: sujet,
        ...autreDuChoix(parents[0]!),
      }
      // `ck_prp_link_second_parent` : `other2` n'a de sens que sur `enfant_de`,
      // et il n'est posé que s'il existe — `exactOptionalPropertyTypes` interdit
      // de l'écrire à `undefined`, et le serveur compte les clés non nulles.
      if (parents.length === 2) Object.assign(lien, secondParentDuChoix(parents[1]!))
      liens.push(lien)
    }
  }

  /* --- Unions ------------------------------------------------------ */
  saisie.unions.forEach((union, index) => {
    const lien: LienPropose = {
      seq: liens.length + 1,
      kind: 'conjoint_de',
      subjectDraftRef: sujet,
      ...autreDuChoix(union.personne),
      // `divorced` est toujours posé : c'est un booléen non nul en base
      // (`DEFAULT false`), et l'omettre laisserait le serveur deviner.
      divorced: union.rompue,
    }

    const mariage = valeurAEnvoyer(union.mariage)
    if (!mariage.valide) {
      erreurs.push({ champ: `unions.${index}.mariage`, message: mariage.message })
    } else if (mariage.valeur !== null) {
      lien.marriage = mariage.valeur
    }

    const rupture = valeurAEnvoyer(union.rupture)
    if (!rupture.valide) {
      erreurs.push({ champ: `unions.${index}.rupture`, message: rupture.message })
    } else if (rupture.valeur !== null) {
      if (!union.rompue) {
        // On ne devine pas une rupture à partir d'une date : le lecteur a pu
        // saisir la date avant de cocher. On le lui dit plutôt que de trancher
        // à sa place — c'est un fait d'état civil, pas un détail d'affichage.
        erreurs.push({
          champ: `unions.${index}.rupture`,
          message: 'Une date de rupture est indiquée : cochez « union rompue », ou effacez la date.',
        })
      } else {
        lien.divorce = rupture.valeur
      }
    }

    const note = texteOuNull(union.note)
    if (note !== null) lien.note = note

    liens.push(lien)
  })

  /* --- Enfants ----------------------------------------------------- */
  saisie.enfants.forEach((enfant) => {
    const lien: LienPropose = {
      seq: liens.length + 1,
      kind: 'parent_de',
      subjectDraftRef: sujet,
      ...autreDuChoix(enfant.personne),
    }
    if (enfant.rangFratrie !== null) lien.siblingOrder = enfant.rangFratrie
    liens.push(lien)
  })

  return { liens, erreurs }
}

/* ------------------------------------------------------------------ */
/* Construction du brouillon                                           */
/* ------------------------------------------------------------------ */

function construireBrouillon(saisie: SaisieCreation): {
  brouillon: BrouillonPropose | null
  erreurs: ErreurSaisie[]
} {
  const erreurs: ErreurSaisie[] = []
  const fiche = saisie.brouillon

  const givenNames = texteOuNull(fiche.givenNames)
  const surname = texteOuNull(fiche.surname)
  if (givenNames === null && surname === null) {
    // Rien dans le contrat n'impose un nom ; mais une fiche anonyme est
    // indécidable pour l'administrateur, et un doublon probable se rapproche
    // « par nom + année de naissance » (§ 9.5). Sans nom, la demande n'a aucune
    // chance d'être ni validée ni rapprochée.
    erreurs.push({ champ: 'identite', message: 'Indiquez au moins un prénom ou un nom.' })
  }

  let birth: ValeurDate | null = null
  const naissance = valeurAEnvoyer(fiche.naissance)
  if (!naissance.valide) erreurs.push({ champ: 'naissance', message: naissance.message })
  else birth = naissance.valeur

  let death: ValeurDate | null = null
  const deces = valeurAEnvoyer(fiche.deces)
  if (!deces.valide) erreurs.push({ champ: 'deces', message: deces.message })
  else death = deces.valeur

  // `deceased` est le `DEAT` **stocké** ; une date de décès sans lui produirait
  // une fiche qui se dit vivante avec une date de mort. On le signale plutôt
  // que de cocher la case en douce : c'est ce que le contributeur a écrit qui
  // doit partir, pas ce qu'on a supposé à sa place.
  if (death !== null && death.qualifier !== 'inconnue' && !fiche.deceased) {
    erreurs.push({
      champ: 'deceased',
      message: 'Une date de décès est indiquée : cochez « décès attesté », ou effacez la date.',
    })
  }

  if (erreurs.length > 0) return { brouillon: null, erreurs }

  return {
    brouillon: {
      localRef: saisie.localRef,
      givenNames,
      surname,
      marriedName: texteOuNull(fiche.marriedName),
      nickname: texteOuNull(fiche.nickname),
      sex: fiche.sex,
      deceased: fiche.deceased,
      birth,
      death,
      note: texteOuNull(fiche.note),
    },
    erreurs,
  }
}

/* ------------------------------------------------------------------ */
/* Le corps de la demande                                              */
/* ------------------------------------------------------------------ */

/**
 * Compose le corps du § 6.3, ou rend la liste de ce qui manque.
 *
 * **« Au moins un lien » n'est contrôlé que si l'on soumet.** C'est la règle du
 * contrat, et elle a un sens pour le contributeur : on enregistre un brouillon
 * incomplet un soir, on le complète le lendemain. Bloquer l'enregistrement
 * reviendrait à exiger que la contribution se fasse d'un trait.
 */
export function corpsCreationPersonne(
  saisie: SaisieCreation,
  options: OptionsDepot,
): ResultatCreation {
  const fiche = construireBrouillon(saisie)
  const liens = construireLiens(saisie)
  const erreurs = [...fiche.erreurs, ...liens.erreurs]

  if (options.soumettre && liens.liens.length === 0) {
    erreurs.push({
      champ: 'liens',
      message:
        'Rattachez la personne à au moins un parent, un enfant ou un conjoint : sans lien, elle flotterait hors de l’arbre.',
    })
  }

  if (erreurs.length > 0 || fiche.brouillon === null) {
    return { valide: false, erreurs }
  }

  const corps: CorpsCreationPersonne = {
    kind: 'creation_personne',
    soumettre: options.soumettre,
    brouillons: [fiche.brouillon],
    liens: liens.liens,
  }

  const message = texteOuNull(options.message)
  if (message !== null) corps.message = message
  if (options.origine !== undefined) corps.origine = options.origine

  return { valide: true, corps }
}

/** Les erreurs, indexées par champ, pour les brancher sur `ChampTexte`. */
export function erreursParChamp(erreurs: readonly ErreurSaisie[]): Record<string, string> {
  const carte: Record<string, string> = {}
  for (const erreur of erreurs) {
    // La première l'emporte : deux messages sur un même champ tiennent rarement
    // sur une ligne, et le premier est celui qu'on peut corriger en premier.
    carte[erreur.champ] ??= erreur.message
  }
  return carte
}

/** Un compte de liens, pour l'aide affichée sous le bloc. Jamais un compteur de
 *  résultats masqués — ce nombre-là ne sort de nulle part (§ 5.5). */
export function nombreDeLiens(saisie: SaisieCreation): number {
  const filiation = saisie.filiation.famille !== null || saisie.filiation.parents.length > 0 ? 1 : 0
  return filiation + saisie.unions.length + saisie.enfants.length
}
