/**
 * La logique de l'assistant de proposition — **sans Vue, sans réseau**.
 *
 * Tout ce qui se décide ici se teste sur des valeurs : quels fichiers entrent,
 * ce que dit l'écran d'une valeur absente, à quelle fiche on joint les
 * documents, et dans quel ordre les dépôts s'enchaînent. Les composants ne font
 * qu'afficher ce que ces fonctions rendent.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Deux règles tiennent l'écran, et elles sont écrites ici pour qu'aucun
 * gabarit ne les contourne.**
 *
 * 1. **Rien n'est déposé sans le clic du membre.** `planDeDepot()` ne part que
 *    des demandes **cochées** ; l'exécution (`executerDepot()`) n'est appelée que
 *    par le bouton « Déposer ».
 * 2. **Un échec n'interrompt pas les suivants.** Les dépôts et les envois de
 *    pièces sont **séquentiels** (le limiteur de téléversement compte par heure :
 *    six requêtes parallèles produiraient six `429` au lieu d'un), et chaque
 *    étape garde son propre état et son propre message — même philosophie que
 *    `TeleversementMedia.vue`.
 * ────────────────────────────────────────────────────────────────────
 */

import {
  analyserErreur,
  estErreurApi,
  type ChangementPropose,
  type ConfianceAssistant,
  type DemandeProposee,
  type PersonneCitee,
  type PortraitPropose,
} from '@/lib/api'
import { tailleLisible, verifierAvantEnvoi } from '@/components/medias/medias'

/* ------------------------------------------------------------------ */
/* Les bornes de l'entrée                                              */
/* ------------------------------------------------------------------ */

/** Nombre maximal de documents par analyse (contrat de l'assistant). */
export const NOMBRE_MAX_DOCUMENTS = 6
/** 10 Mio par document — plus bas que les 20 Mio d'un média : ces octets
 *  partent entiers vers le modèle, dans une requête qui en porte six. */
export const TAILLE_MAX_DOCUMENT = 10 * 1024 * 1024
/** Longueur maximale du texte libre. */
export const TEXTE_MAX = 8000
/** Tous documents confondus. Le trajet est borné à 25 Mo par requête, et le modèle
 *  refuse au-delà d'environ 20 Mo de documents en ligne (voir `reduireImage.ts`). */
export const TAILLE_MAX_TOTALE = 18 * 1024 * 1024
/** Le quota annoncé avant le premier appel ; ensuite, `restant` fait foi. */
export const QUOTA_QUOTIDIEN = 20

export interface VerdictDocuments {
  /** Les fichiers qui entrent, dans l'ordre de sélection. */
  acceptes: File[]
  /** Un message par fichier refusé, en clair. */
  refus: string[]
}

/**
 * Trie une sélection de fichiers : la taille et le nombre, **rien d'autre**.
 *
 * Le type n'est pas contrôlé ici, pour la même raison que dans
 * `components/medias/medias.ts` : `File.type` est le type *annoncé*, et c'est le
 * serveur qui renifle les octets. L'attribut `accept` reste un confort.
 */
export function trierDocuments(
  dejaJoints: number,
  selection: readonly File[],
  poidsDejaJoint = 0,
): VerdictDocuments {
  const acceptes: File[] = []
  const refus: string[] = []
  let places = Math.max(0, NOMBRE_MAX_DOCUMENTS - dejaJoints)
  let poids = poidsDejaJoint

  for (const fichier of selection) {
    const verdict = verifierAvantEnvoi(fichier, TAILLE_MAX_DOCUMENT)
    if (!verdict.accepte) {
      refus.push(`« ${fichier.name} » : ${verdict.message}`)
      continue
    }
    if (places === 0) {
      refus.push(
        `« ${fichier.name} » n’a pas été ajouté : ${NOMBRE_MAX_DOCUMENTS} documents au plus par analyse.`,
      )
      continue
    }
    if (poids + fichier.size > TAILLE_MAX_TOTALE) {
      refus.push(
        `« ${fichier.name} » n’a pas été ajouté : ${tailleLisible(TAILLE_MAX_TOTALE)} au plus pour tous les documents.`,
      )
      continue
    }
    acceptes.push(fichier)
    poids += fichier.size
    places--
  }
  return { acceptes, refus }
}

/** Le rappel des bornes, tel que l'écran l'affiche sous les boutons. */
export function rappelDesBornes(): string {
  return `Images, PDF ou enregistrements audio, ${NOMBRE_MAX_DOCUMENTS} documents au plus, ${tailleLisible(TAILLE_MAX_TOTALE)} en tout. Les photos sont allégées avant l’envoi.`
}

/** Un PDF ne se met pas dans une `<img>` : il a sa pastille. Le nom sert de
 *  repli quand le navigateur n'annonce aucun type (courant sur mobile). */
export function estPdf(fichier: Pick<File, 'type' | 'name'>): boolean {
  return fichier.type === 'application/pdf' || fichier.name.toLowerCase().endsWith('.pdf')
}

/** Un enregistrement (ou un fichier audio importé) : une source que le modèle
 *  écoute. Le nom sert de repli, comme pour le PDF. */
export function estAudio(fichier: Pick<File, 'type' | 'name'>): boolean {
  return fichier.type.startsWith('audio/') || /\.(m4a|mp3|wav|ogg|oga|opus|webm|flac|aac)$/i.test(fichier.name)
}

/** Une image se prévisualise par une URL d'objet. */
export function estApercuImage(fichier: Pick<File, 'type'>): boolean {
  return fichier.type.startsWith('image/')
}

/** Il faut au moins un texte ou un document, et un texte dans sa borne. */
export function peutAnalyser(texte: string, nombreDocuments: number): boolean {
  if (texte.length > TEXTE_MAX) return false
  return texte.trim().length > 0 || nombreDocuments > 0
}

/** La mention du quota : avant le premier appel on ne sait que la règle. */
export function mentionQuota(restant: number | null): string {
  if (restant === null) return `${QUOTA_QUOTIDIEN} analyses par jour`
  if (restant <= 0) return 'Plus aucune analyse possible aujourd’hui'
  return restant === 1
    ? 'Encore 1 analyse possible aujourd’hui'
    : `Encore ${restant} analyses possibles aujourd’hui`
}

/* ------------------------------------------------------------------ */
/* Les erreurs de l'analyse                                            */
/* ------------------------------------------------------------------ */

/**
 * Le titre posé au-dessus du message du serveur.
 *
 * Le message fait foi (§ 15.1), mais « 429 », « 502 » et « 503 » appellent trois
 * attitudes différentes — attendre demain, réessayer, revenir plus tard — et le
 * titre les dit avant qu'on lise la phrase. `null` : pas de titre, le message
 * suffit.
 */
export function titreErreurAnalyse(erreur: unknown): string | null {
  const { statut, code } = analyserErreur(erreur)
  if (statut === 429) return 'Quota du jour atteint'
  if (statut === 503 || code === 'assistant_indisponible') return 'Assistant indisponible'
  if (statut === 502 || code === 'assistant_erreur') return 'L’assistant n’a pas pu conclure'
  return null
}

/** Une annulation par le membre n'est pas une erreur à afficher. */
export function estAnnulation(erreur: unknown): boolean {
  return estErreurApi(erreur) && erreur.code === 'client_requete_annulee'
}

/* ------------------------------------------------------------------ */
/* La relecture                                                        */
/* ------------------------------------------------------------------ */

export const LIBELLES_CONFIANCE: Record<ConfianceAssistant, string> = {
  haute: 'Confiance haute',
  moyenne: 'Confiance moyenne',
  faible: 'Confiance faible',
}

/** Une personne restreinte n'a que son nom : **jamais** de dates, même si la
 *  réponse en portait. C'est la règle du graphe, tenue ici plutôt que dans le
 *  gabarit, pour qu'un test la voie. */
export function datesAffichables(personne: PersonneCitee): string | null {
  if (personne.visibilite !== 'complete') return null
  const dates = personne.ligneDates?.trim() ?? ''
  return dates.length > 0 ? dates : null
}

/** La fiche existante que vise une demande, ou `null` pour une création. */
export function cibleExistante(demande: Pick<DemandeProposee, 'corps'>): number | null {
  const corps = demande.corps
  if (corps.kind === 'modification_personne' || corps.kind === 'ajout_lien') return corps.targetId
  return null
}

export const VALEUR_MASQUEE = '(valeur masquée)'

/**
 * Ce que l'écran écrit avant la flèche — ou `null` : pas de « avant » du tout.
 *
 * `avant === null` n'a pas un seul sens :
 *
 * - sur une **modification**, c'est une valeur que le lecteur n'a pas le droit
 *   de lire (fiche restreinte) — le serveur ne l'a pas envoyée, et l'écran dit
 *   « (valeur masquée) » plutôt qu'un tiret qui ferait croire le champ vide ;
 * - sur une **création** ou un **ajout de lien**, rien n'existait : on n'écrit
 *   que la valeur proposée.
 */
export function libelleAvant(
  changement: ChangementPropose,
  demande: Pick<DemandeProposee, 'corps'>,
): string | null {
  if (changement.avant !== null) return changement.avant
  return demande.corps.kind === 'modification_personne' ? VALEUR_MASQUEE : null
}

/** Les clés cochées au départ : **toutes**. Décocher est le geste du membre. */
export function clesCocheesParDefaut(demandes: readonly DemandeProposee[]): Set<string> {
  return new Set(demandes.map((demande) => demande.cle))
}

/**
 * Le libellé du bouton de dépôt : « Déposer la demande », « Déposer les 3
 * demandes », « Déposer les 2 demandes et le portrait », « Envoyer les 2
 * portraits ». `portraits` : ceux qui partiront vraiment (`portraitsPartants`).
 */
export function libelleDepot(nombre: number, portraits = 0): string {
  const lesPortraits = portraits === 1 ? 'le portrait' : `les ${portraits} portraits`
  if (nombre <= 0) return portraits > 0 ? `Envoyer ${lesPortraits}` : 'Aucune demande cochée'
  const demandes = nombre === 1 ? 'Déposer la demande' : `Déposer les ${nombre} demandes`
  return portraits > 0 ? `${demandes} et ${lesPortraits}` : demandes
}

/* ------------------------------------------------------------------ */
/* Le dépôt                                                            */
/* ------------------------------------------------------------------ */

export type EtatEtape = 'attente' | 'en_cours' | 'reussi' | 'echec'

export interface EnvoiPreuve {
  /** Index du document dans la liste jointe à l'analyse. */
  index: number
  nom: string
  etat: EtatEtape
  erreur: unknown
}

export interface EtapeDepot {
  cle: string
  titre: string
  etat: EtatEtape
  /** La demande créée, une fois déposée. */
  demandeId: number | null
  erreur: unknown
  /** La fiche existante qui recevra les documents, ou `null`. */
  cibleDesPreuves: number | null
  /** Vide si aucune pièce n'est à joindre ici — création, option décochée, ou
   *  fiche qui les reçoit déjà par une demande précédente. */
  preuves: EnvoiPreuve[]
}

/**
 * Le plan de dépôt : une étape par demande **cochée**, dans l'ordre de l'analyse.
 *
 * Les documents vont aux **personnes existantes** visées (modification, ajout de
 * lien) — une création n'a pas encore de fiche. Deux demandes sur la même fiche
 * ne font pas deux fois les mêmes envois : la première les porte, la seconde
 * n'en porte aucun.
 *
 * **Un enregistrement n'est jamais joint** : la médiathèque ne garde que des
 * images et des PDF. Il a servi à l'analyse, il ne part pas plus loin. Les
 * autres documents gardent leur rang d'origine (`index`).
 */
export function planDeDepot(
  demandes: readonly DemandeProposee[],
  cochees: ReadonlySet<string>,
  documents: readonly Pick<File, 'name' | 'type'>[],
  joindrePreuves: boolean,
): EtapeDepot[] {
  const dejaServies = new Set<number>()
  const plan: EtapeDepot[] = []
  const joignables = documents
    .map((document, index) => ({ document, index }))
    .filter(({ document }) => !estAudio(document))

  for (const demande of demandes) {
    if (!cochees.has(demande.cle)) continue
    const cible = cibleExistante(demande)
    const recoit = joindrePreuves && cible !== null && !dejaServies.has(cible) && joignables.length > 0
    if (recoit) dejaServies.add(cible)

    plan.push({
      cle: demande.cle,
      titre: demande.titre,
      etat: 'attente',
      demandeId: null,
      erreur: null,
      cibleDesPreuves: recoit ? cible : null,
      preuves: recoit
        ? joignables.map(({ document, index }) => ({ index, nom: document.name, etat: 'attente', erreur: null }))
        : [],
    })
  }
  return plan
}

/** Légende d'une preuve jointe : d'où elle vient, bornée aux 255 caractères
 *  du § 7.1. */
export function legendeDePreuve(demandeId: number, titre: string): string {
  const legende = `Joint à la demande n° ${demandeId} (assistant) : ${titre}`
  return legende.length > 255 ? `${legende.slice(0, 254)}…` : legende
}

export interface OperationsDeDepot {
  deposer: (cle: string) => Promise<{ id: number }>
  televerser: (envoi: { index: number; personId: number; legende: string }) => Promise<unknown>
}

/**
 * Exécute le plan, **une étape après l'autre**, en écrivant l'état dans les
 * objets reçus — l'écran passe un tableau réactif, et chaque transition se voit.
 *
 * Une demande qui échoue n'emporte ni les suivantes, ni ses pièces : ses pièces
 * ne partent simplement pas (elles n'auraient pas de demande à accompagner). Une
 * pièce qui échoue n'emporte pas les autres.
 */
export async function executerDepot(plan: EtapeDepot[], operations: OperationsDeDepot): Promise<void> {
  for (const etape of plan) {
    // Une demande déjà déposée ne se redépose pas (le serveur rendrait `409
    // deja_deposee`) ; on passe directement à ses pièces restantes.
    if (etape.etat !== 'reussi') {
      etape.etat = 'en_cours'
      etape.erreur = null
      try {
        const demande = await operations.deposer(etape.cle)
        etape.demandeId = demande.id
        etape.etat = 'reussi'
      } catch (cause) {
        etape.etat = 'echec'
        etape.erreur = cause
        continue
      }
    }

    if (etape.cibleDesPreuves === null) continue
    for (const preuve of etape.preuves) {
      if (preuve.etat === 'reussi') continue
      preuve.etat = 'en_cours'
      preuve.erreur = null
      try {
        await operations.televerser({
          index: preuve.index,
          personId: etape.cibleDesPreuves,
          legende: legendeDePreuve(etape.demandeId ?? 0, etape.titre),
        })
        preuve.etat = 'reussi'
      } catch (cause) {
        preuve.etat = 'echec'
        preuve.erreur = cause
      }
    }
  }
}

/**
 * Le message d'un dépôt refusé.
 *
 * Deux cas propres à l'assistant se disent autrement que par le message brut :
 * une analyse **expirée** (`404`, deux heures) — relancer est la seule issue —
 * et une demande **déjà déposée** (`409 deja_deposee`), qui n'est pas un échec
 * pour le membre : elle est dans la file.
 */
export function messageEchecDepot(erreur: unknown): string {
  const analyse = analyserErreur(erreur)
  if (analyse.statut === 404) return 'L’analyse a expiré, relancez-la.'
  if (analyse.code === 'deja_deposee') return 'Cette demande a déjà été déposée : elle est dans la file.'
  return analyse.message
}

/** Récapitulatif de fin. */
export function bilanDepot(plan: readonly EtapeDepot[]): { reussis: number; echecs: number } {
  return {
    reussis: plan.filter((etape) => etape.etat === 'reussi').length,
    echecs: plan.filter((etape) => etape.etat === 'echec').length,
  }
}

/* ------------------------------------------------------------------ */
/* Les portraits repérés                                               */
/* ------------------------------------------------------------------ */

/*
 * Une photo jointe montre un visage ; l'assistant propose d'en faire le
 * portrait d'une personne — existante, ou proposée par une demande de création
 * de la même analyse. Le front a encore les fichiers : il recadre et téléverse
 * lui-même, **après** les demandes, parce qu'un portrait de personne proposée a
 * besoin de l'identifiant de la demande créée (`brouillon: { demandeId,
 * brouillonRef }`).
 *
 * Mêmes deux règles que les demandes : rien ne part sans la case cochée, et un
 * échec n'arrête pas les suivants.
 */

/** Les portraits de l'analyse — `[]` si le serveur n'en envoie pas. */
export function portraitsDe(analyse: { portraits?: PortraitPropose[] | undefined }): PortraitPropose[] {
  return analyse.portraits ?? []
}

/** Comparer `#p1` et `p1` : le serveur écrit `brouillonRef` sans dièse, le
 *  `localRef` d'un brouillon le porte. */
function sansDiese(ref: string): string {
  return ref.startsWith('#') ? ref.slice(1) : ref
}

/**
 * Le nom d'une personne **proposée** : celui du brouillon `#<brouillonRef>` de la
 * demande de création `demandeCle`. `null` si on ne le retrouve pas (demande
 * retirée par le serveur, référence inconnue) — l'écran dit alors seulement
 * « nouvelle personne proposée ».
 */
export function nomDuBrouillon(
  demandes: readonly DemandeProposee[],
  demandeCle: string | null,
  brouillonRef: string | null,
): string | null {
  if (demandeCle === null || brouillonRef === null) return null
  const demande = demandes.find((d) => d.cle === demandeCle)
  if (demande === undefined || demande.corps.kind !== 'creation_personne') return null
  const cherche = sansDiese(brouillonRef)
  const brouillon = demande.corps.brouillons.find((b) => sansDiese(b.localRef) === cherche)
  if (brouillon === undefined) return null
  const nom = [brouillon.givenNames, brouillon.surname]
    .map((morceau) => morceau?.trim() ?? '')
    .filter((morceau) => morceau.length > 0)
    .join(' ')
  if (nom.length > 0) return nom
  const surnom = brouillon.nickname?.trim() ?? ''
  return surnom.length > 0 ? surnom : null
}

/** Ce que la carte d'un portrait dit de la personne. */
export interface PersonneDuPortrait {
  /** Le nom à afficher, ou `null` (brouillon introuvable). */
  nom: string | null
  /** La fiche existante, pour le lien ; `null` pour une personne proposée. */
  personId: number | null
  /** Les dates, **jamais** pour une personne restreinte (`datesAffichables`). */
  dates: string | null
  nouvelle: boolean
}

export function personneDuPortrait(
  portrait: PortraitPropose,
  demandes: readonly DemandeProposee[],
): PersonneDuPortrait {
  if (portrait.personId !== null) {
    return {
      nom: portrait.personne?.displayName ?? `Personne n° ${portrait.personId}`,
      personId: portrait.personId,
      dates: portrait.personne ? datesAffichables(portrait.personne) : null,
      nouvelle: false,
    }
  }
  return {
    nom: nomDuBrouillon(demandes, portrait.demandeCle, portrait.brouillonRef),
    personId: null,
    dates: null,
    nouvelle: true,
  }
}

/** Le nom d'un portrait dans le bilan : « Portrait de Marie Dupont ». */
export function titreDuPortrait(portrait: PortraitPropose, demandes: readonly DemandeProposee[]): string {
  const { nom, nouvelle } = personneDuPortrait(portrait, demandes)
  if (nom !== null) return `Portrait de ${nom}${nouvelle ? ' (nouvelle personne)' : ''}`
  return 'Portrait d’une nouvelle personne proposée'
}

/**
 * Le fichier d'où recadrer : le document au rang `portrait.document` **dans
 * l'ordre d'envoi**. `null` s'il manque ou si c'est un PDF — le serveur n'en
 * propose pas, mais une réponse fausse ne doit pas faire dessiner un PDF.
 */
export function documentDuPortrait<F extends Pick<File, 'type' | 'name'>>(
  portrait: Pick<PortraitPropose, 'document'>,
  fichiers: readonly F[],
): F | null {
  const fichier = Number.isInteger(portrait.document) ? fichiers[portrait.document] : undefined
  if (fichier === undefined || estPdf(fichier)) return null
  return fichier
}

/** Les portraits cochés au départ : **tous**, comme les demandes. */
export function clesPortraitsParDefaut(portraits: readonly PortraitPropose[]): Set<string> {
  return new Set(portraits.map((portrait) => portrait.cle))
}

/**
 * Pourquoi un portrait coché ne partira pas, vu **avant** le dépôt — ou `null`.
 * Une personne proposée n'a de fiche (de brouillon) que si sa demande de
 * création part avec : décochée, le portrait n'a nulle part où aller.
 */
export function empechementDuPortrait(
  portrait: PortraitPropose,
  demandesCochees: ReadonlySet<string>,
): string | null {
  if (portrait.personId !== null) return null
  if (portrait.demandeCle === null || portrait.brouillonRef === null) {
    return 'La personne visée est introuvable : ce portrait ne partira pas.'
  }
  if (!demandesCochees.has(portrait.demandeCle)) {
    return 'La demande qui crée cette personne est décochée : ce portrait ne partira pas.'
  }
  return null
}

/** Le nombre de portraits qui partiront : cochés, et sans empêchement. */
export function portraitsPartants(
  portraits: readonly PortraitPropose[],
  cochees: ReadonlySet<string>,
  demandesCochees: ReadonlySet<string>,
): number {
  return portraits.filter((p) => cochees.has(p.cle) && empechementDuPortrait(p, demandesCochees) === null).length
}

/** Où un portrait est téléversé, une fois les demandes passées. */
export type DestinationPortrait =
  | { type: 'personne'; personId: number }
  | { type: 'brouillon'; demandeId: number; brouillonRef: string }
  | { type: 'saute'; motif: string }

/**
 * La destination d'un portrait, **au vu du résultat des demandes** (`plan`, tel
 * que `executerDepot` l'a laissé) :
 *
 * - personne existante → sa fiche ;
 * - personne proposée dont la demande de création a été **déposée** → le
 *   brouillon de la demande créée (`demandeId`, `brouillonRef` sans dièse) ;
 * - sinon (demande décochée, en échec, introuvable) → sauté, avec le motif que
 *   le bilan affiche.
 */
export function destinationDuPortrait(
  portrait: PortraitPropose,
  plan: readonly Pick<EtapeDepot, 'cle' | 'etat' | 'demandeId'>[],
): DestinationPortrait {
  if (portrait.personId !== null) return { type: 'personne', personId: portrait.personId }
  if (portrait.demandeCle === null || portrait.brouillonRef === null) {
    return { type: 'saute', motif: 'La personne visée est introuvable.' }
  }
  const etape = plan.find((e) => e.cle === portrait.demandeCle)
  if (etape === undefined) {
    return { type: 'saute', motif: 'Sa demande de création n’a pas été cochée : pas de fiche où le joindre.' }
  }
  if (etape.etat !== 'reussi' || etape.demandeId === null) {
    return { type: 'saute', motif: 'Sa demande de création n’a pas pu être déposée : pas de fiche où le joindre.' }
  }
  return { type: 'brouillon', demandeId: etape.demandeId, brouillonRef: sansDiese(portrait.brouillonRef) }
}

/** `saute` : ne partira pas, et le bilan dit pourquoi. */
export type EtatPortrait = EtatEtape | 'saute'

export interface EtapePortrait {
  cle: string
  titre: string
  portrait: PortraitPropose
  etat: EtatPortrait
  /** Pourquoi il a été sauté. */
  motif: string | null
  erreur: unknown
}

/** Une étape par portrait **coché**, dans l'ordre de l'analyse. Les portraits
 *  sans avenir y restent : le bilan doit dire qu'ils ont été sautés. */
export function planDesPortraits(
  portraits: readonly PortraitPropose[],
  cochees: ReadonlySet<string>,
  demandes: readonly DemandeProposee[],
): EtapePortrait[] {
  return portraits
    .filter((portrait) => cochees.has(portrait.cle))
    .map((portrait) => ({
      cle: portrait.cle,
      titre: titreDuPortrait(portrait, demandes),
      portrait,
      etat: 'attente',
      motif: null,
      erreur: null,
    }))
}

/** Légende d'un portrait repéré : d'où il vient, bornée aux 255 caractères du § 7.1. */
export function legendeDePortrait(portrait: Pick<PortraitPropose, 'justification'>): string {
  const justification = portrait.justification.trim()
  const legende = justification.length > 0 ? `Repéré par l’assistant : ${justification}` : 'Repéré par l’assistant'
  return legende.length > 255 ? `${legende.slice(0, 254)}…` : legende
}

/**
 * Téléverse les portraits, **un après l'autre**, après les demandes. Écrit
 * l'état dans les étapes reçues (tableau réactif côté écran). Un portrait déjà
 * envoyé ne repart pas ; un portrait sauté est réévalué — si sa demande a
 * réussi entre-temps (« Réessayer »), il part.
 */
export async function executerPortraits(
  etapes: EtapePortrait[],
  plan: readonly Pick<EtapeDepot, 'cle' | 'etat' | 'demandeId'>[],
  televerser: (envoi: {
    portrait: PortraitPropose
    destination: Exclude<DestinationPortrait, { type: 'saute' }>
    legende: string
  }) => Promise<unknown>,
): Promise<void> {
  for (const etape of etapes) {
    if (etape.etat === 'reussi') continue
    const destination = destinationDuPortrait(etape.portrait, plan)
    if (destination.type === 'saute') {
      etape.etat = 'saute'
      etape.motif = destination.motif
      continue
    }
    etape.etat = 'en_cours'
    etape.motif = null
    etape.erreur = null
    try {
      await televerser({ portrait: etape.portrait, destination, legende: legendeDePortrait(etape.portrait) })
      etape.etat = 'reussi'
    } catch (cause) {
      etape.etat = 'echec'
      etape.erreur = cause
    }
  }
}

/** Récapitulatif des portraits. */
export function bilanPortraits(etapes: readonly EtapePortrait[]): { envoyes: number; echecs: number; sautes: number } {
  return {
    envoyes: etapes.filter((e) => e.etat === 'reussi').length,
    echecs: etapes.filter((e) => e.etat === 'echec').length,
    sautes: etapes.filter((e) => e.etat === 'saute').length,
  }
}

/* ------------------------------------------------------------------ */
/* Les enregistrements audio                                           */
/* ------------------------------------------------------------------ */

/**
 * Les formats que l'on demande à `MediaRecorder`, dans l'ordre de préférence :
 * AAC dans du MP4 (Safari, Chrome de Google) et Ogg/Opus (Firefox) sont dans la
 * liste des formats que le modèle documente ; WebM/Opus (Chromium) ensuite, puis
 * ce que le navigateur appelle « MP4 » sans préciser. Un Chrome sans AAC
 * produit de l'Opus même en MP4 : autant le mettre dans son conteneur usuel. Le
 * serveur renifle les octets de toute façon.
 */
const FORMATS_D_ENREGISTREMENT = [
  { mime: 'audio/mp4;codecs=mp4a.40.2', extension: 'm4a' },
  { mime: 'audio/ogg;codecs=opus', extension: 'ogg' },
  { mime: 'audio/webm;codecs=opus', extension: 'webm' },
  { mime: 'audio/mp4', extension: 'm4a' },
  { mime: 'audio/webm', extension: 'webm' },
] as const

export interface FormatDEnregistrement {
  /** `''` : on laisse le navigateur choisir. */
  mime: string
  extension: string
}

/** Le premier format que le navigateur sait enregistrer, sinon le sien. */
export function choisirFormatEnregistrement(estPris: (mime: string) => boolean): FormatDEnregistrement {
  const format = FORMATS_D_ENREGISTREMENT.find((f) => estPris(f.mime))
  return format ? { mime: format.mime, extension: format.extension } : { mime: '', extension: extensionDEnregistrement('') }
}

/** L'extension d'un type produit par le navigateur, qui peut différer du type demandé. */
export function extensionDEnregistrement(mime: string): string {
  if (mime.startsWith('audio/mp4') || mime.startsWith('video/mp4')) return 'm4a'
  if (mime.startsWith('audio/ogg')) return 'ogg'
  return 'webm'
}

/** 32 kbit/s suffisent à la voix : 10 Mio font plus de quarante minutes. */
export const DEBIT_ENREGISTREMENT = 32_000
/** Au-delà, l'enregistrement s'arrête seul : il doit tenir dans un document. */
export const DUREE_MAX_ENREGISTREMENT = 20 * 60

/** Le nom du fichier produit : « enregistrement-1.m4a ». */
export function nomDEnregistrement(rang: number, extension: string): string {
  return `enregistrement-${rang}.${extension}`
}

/** « 1:05 », « 12:00 ». */
export function dureeLisible(secondes: number): string {
  const s = Math.max(0, Math.floor(secondes))
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}
