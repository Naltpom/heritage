/**
 * **Les inscriptions à rattacher** — ce que la carte décide, hors du gabarit.
 *
 * Un compte `a_rattacher` est né d'un « Je ne me trouve pas » (ADR 0013) : la
 * personne n'a pas désigné de fiche, elle a décrit sa famille en texte libre.
 * Son compte attend, **sans fiche et sans cercle**, qu'un administrateur dise
 * qui elle est. L'onglet « À rattacher » pose une carte par compte ; ce module
 * en tient la logique, `CarteARattacher.vue` et `SuggestionRattachement.vue`
 * l'affichent.
 *
 * ── Trois décisions, et aucune n'est automatique ────────────────────────────
 *
 *  1. **Rattacher à une fiche existante** — celle que l'analyse désigne, ou une
 *     autre, choisie au sélecteur de personne. Passe par `activerMembre(id,
 *     {personId})`, la route de toujours.
 *  2. **Créer la fiche et rattacher** — une transaction côté serveur : demande
 *     de création déposée, validée, compte rattaché et activé. Le corps de la
 *     demande est construit **par le serveur** ; le front ne transmet que les
 *     parents choisis.
 *  3. **Refuser l'inscription** — le compte est suspendu et sa déclaration
 *     purgée.
 *
 * La suggestion de Gemini **éclaire** la première ou la deuxième ; elle ne
 * déclenche rien. Et la carte doit rester utilisable **sans** elle : clé
 * absente, modèle en panne, quota épuisé — la main de l'administrateur suffit.
 *
 * ── Rien ne part tout seul (3 octobre 2026) ─────────────────────────────────
 *
 * Jusqu'au 2 octobre, l'ouverture de l'onglet lançait, carte par carte, la
 * transcription du message vocal puis la recherche. Le propriétaire a demandé
 * l'inverse : « ne pas lancer la recherche IA directement au chargement de la
 * page, me mettre le bouton », et pouvoir **corriger la transcription avant**.
 * Désormais l'ouverture ne fait que **lire** — la liste, et la suggestion déjà
 * calculée s'il y en a une (`GET`, gratuit). Chaque appel au modèle part d'un
 * clic : « Transcrire le message vocal », « Lancer la recherche ».
 *
 * Chaque appel consomme un quota **global** de 40 par 24 heures, commun à tous
 * les administrateurs : la carte affiche ce qu'il en reste quand le serveur le
 * dit ({@link quotaDesRattachements}).
 *
 * ── Les recherches, l'une après l'autre ─────────────────────────────────────
 *
 * Une recherche prend 20 à 60 secondes. Trois clics rapides sur trois cartes ne
 * doivent pas faire trois appels simultanés, chacun tenant un processus PHP :
 * {@link FileDAnalyses} les passe une à la fois, dans l'ordre des clics.
 *
 * ── La transcription, relue avant la recherche ──────────────────────────────
 *
 * Un message vocal est **transcrit** par Gemini (2 octobre 2026, soir), et la
 * recherche lit la transcription comme la description. Les noms propres y sont
 * « tels qu'entendus » : l'administrateur peut les **corriger** dans le champ
 * et enregistrer — le serveur note la correction, et la recherche suivante lit
 * le texte corrigé. Une recherche faite sur une autre version est dite
 * **périmée** (`perimee`) : « La transcription a changé : relancez la
 * recherche ». La description **écrite**, elle, n'est jamais modifiable :
 * c'est la parole du candidat.
 */

import {
  TRANSCRIPTION_MAX,
  analyserErreur,
  type CompteARattacher,
  type QuotaGlobal,
  type SuggestionDeRattachement,
  type TranscriptionARattacher,
} from '@/lib/api'

/* ------------------------------------------------------------------ */
/* L'état de l'analyse d'une carte                                     */
/* ------------------------------------------------------------------ */

/**
 * Les états d'une suggestion, du point de vue de la carte.
 *
 *  - `lecture`        — l'écran relit la suggestion déjà calculée (`GET`,
 *    gratuit) ;
 *  - `aucune`         — aucune recherche n'a été lancée : « Lancer la
 *    recherche » ;
 *  - `attente`        — demandée, mais une autre carte passe avant ;
 *  - `en_cours`       — le serveur interroge le modèle (20 à 60 s) ;
 *  - `prete`          — la suggestion est là (`perimee` : faite sur une autre
 *    version de la transcription) ;
 *  - `non_configuree` — `503 assistant_indisponible` : pas de clé. Ce n'est
 *    pas une panne, c'est un réglage : pas de « Relancer » ;
 *  - `echec`          — `502`, `429` ou autre : un message, et « Relancer ».
 */
export type EtatDAnalyse =
  | { etat: 'lecture' }
  | { etat: 'aucune' }
  | { etat: 'attente' }
  | { etat: 'en_cours' }
  | { etat: 'prete'; suggestion: SuggestionDeRattachement }
  | { etat: 'non_configuree' }
  | { etat: 'echec'; message: string; attenteSecondes: number | null }

/**
 * L'état qu'une erreur de lecture de suggestion produit.
 *
 * Le `503` est séparé des autres : il ne se répare pas en relançant, et un
 * bouton « Relancer » qui échoue toujours de la même façon use la confiance
 * dans tous les autres boutons de l'écran.
 */
export function etatApresErreur(cause: unknown): EtatDAnalyse {
  const analyse = analyserErreur(cause)
  if (analyse.statut === 503) return { etat: 'non_configuree' }
  if (analyse.statut === 429) {
    return {
      etat: 'echec',
      message:
        'Quota d’appels à l’IA atteint pour aujourd’hui : rattachez à la main, ou relancez plus tard.',
      attenteSecondes: analyse.attenteSecondes,
    }
  }
  if (analyse.statut === 502) {
    return {
      etat: 'echec',
      message: 'La recherche a échoué : relancez-la, ou décidez à la main.',
      attenteSecondes: null,
    }
  }
  return { etat: 'echec', message: analyse.message, attenteSecondes: null }
}

/* ------------------------------------------------------------------ */
/* L'état de la transcription d'un message vocal                       */
/* ------------------------------------------------------------------ */

/**
 * Les états de la transcription, du point de vue de la carte.
 *
 *  - `a_faire`  — jamais tentée : « Transcrire le message vocal » ;
 *  - `en_cours` — le serveur fait écouter le message à Gemini ;
 *  - `faite`    — le texte est là, modifiable ; `corrigeeLe` dit qu'un
 *    administrateur l'a corrigé et enregistré ;
 *  - `echec`    — la dernière tentative a échoué. `reessayable` : faux pour
 *    `503` (pas de clé) — un « Réessayer » qui échoue toujours pareil use la
 *    confiance dans les autres boutons, même règle que l'analyse.
 */
export type EtatDeTranscription =
  | { etat: 'a_faire' }
  | { etat: 'en_cours' }
  | { etat: 'faite'; texte: string; le: string | null; corrigeeLe: string | null }
  | { etat: 'echec'; message: string; reessayable: boolean }

/** L'état d'une transcription telle que le serveur la décrit. */
export function etatDeTranscription(transcription: TranscriptionARattacher): EtatDeTranscription {
  if (transcription.statut === 'faite' && transcription.texte !== null) {
    return {
      etat: 'faite',
      texte: transcription.texte,
      le: transcription.le,
      corrigeeLe: transcription.corrigeeLe ?? null,
    }
  }
  if (transcription.statut === 'echec') {
    return {
      etat: 'echec',
      message: transcription.erreur ?? 'La transcription a échoué.',
      reessayable: true,
    }
  }
  return { etat: 'a_faire' }
}

/** L'état qu'une erreur de la route de transcription produit (le `404` est
 *  traité à part : {@link compteDisparu}). */
export function transcriptionApresErreur(cause: unknown): EtatDeTranscription {
  const analyse = analyserErreur(cause)
  if (analyse.statut === 503) {
    return {
      etat: 'echec',
      message: 'La transcription automatique n’est pas configurée sur cette installation : écoutez le message.',
      reessayable: false,
    }
  }
  if (analyse.statut === 429) {
    return {
      etat: 'echec',
      message: 'Quota d’appels à l’IA atteint pour aujourd’hui : écoutez le message, ou réessayez plus tard.',
      reessayable: true,
    }
  }
  return { etat: 'echec', message: analyse.message, reessayable: true }
}

/**
 * La hauteur du champ de la transcription, en lignes : de 3 à 14, selon la
 * longueur. Cinq minutes de parole font 4 000 à 6 000 caractères ; au-delà de
 * 14 lignes le champ défile plutôt que de pousser les décisions hors de
 * l'écran.
 */
export function lignesDuChamp(texte: string): number {
  const estimees = Math.ceil(texte.length / 70) + (texte.match(/\n/g)?.length ?? 0)
  return Math.min(14, Math.max(3, estimees))
}

/**
 * Ce que « Enregistrer » ferait du brouillon : rien (`possible: false`, pas de
 * motif) s'il est identique au texte enregistré ; refusé, avec le motif, s'il
 * est vide ou trop long — les mêmes règles que le serveur (`422`).
 */
export function brouillonEnregistrable(
  brouillon: string,
  enregistre: string | null,
): { possible: boolean; motif: string | null } {
  const texte = brouillon.trim()
  if (texte === (enregistre ?? '').trim()) return { possible: false, motif: null }
  if (texte === '') return { possible: false, motif: 'La transcription ne peut pas être vide.' }
  if (texte.length > TRANSCRIPTION_MAX) {
    return {
      possible: false,
      motif: `La transcription dépasse ${TRANSCRIPTION_MAX.toLocaleString('fr-FR')} caractères.`,
    }
  }
  return { possible: true, motif: null }
}

/**
 * Ce que la carte dit du lien entre la transcription et la recherche affichée,
 * ou `null` quand il n'y a rien à dire (pas de transcription, ou pas de
 * recherche).
 */
export function mentionDeLAnalyse(
  transcription: EtatDeTranscription | null,
  analyse: EtatDAnalyse,
): string | null {
  if (transcription?.etat !== 'faite' || analyse.etat !== 'prete') return null
  if (analyse.suggestion.perimee === true) {
    return 'La recherche ci-dessous a été faite sur une autre version de ce texte : relancez-la.'
  }
  return analyse.suggestion.transcriptionPriseEnCompte
    ? 'La recherche ci-dessous en a tenu compte.'
    : 'La recherche ci-dessous a été faite sans elle : relancez-la.'
}

/* ------------------------------------------------------------------ */
/* Le bouton « Lancer la recherche »                                   */
/* ------------------------------------------------------------------ */

/**
 * Le libellé du bouton de recherche, ou `null` quand il n'a pas lieu d'être :
 * pendant la relecture, en file, en cours — et sans clé (`non_configuree`), où
 * il échouerait toujours pareil.
 */
export function libelleDuLancement(analyse: EtatDAnalyse): string | null {
  if (analyse.etat === 'aucune') return 'Lancer la recherche'
  if (analyse.etat === 'prete' || analyse.etat === 'echec') return 'Relancer la recherche'
  return null
}

/**
 * Pourquoi la recherche ne peut pas partir **maintenant**, ou `null`.
 *
 *  - la transcription est en cours : la recherche la lirait à moitié faite ;
 *  - une correction n'est pas enregistrée : la recherche lit la version
 *    **enregistrée**, et l'administrateur croirait qu'elle a lu la sienne.
 */
export function motifDeBlocageDuLancement(
  transcription: EtatDeTranscription | null,
  brouillonModifie: boolean,
): string | null {
  if (transcription?.etat === 'en_cours') return 'Attendez la fin de la transcription.'
  if (brouillonModifie) {
    return 'Enregistrez (ou annulez) votre correction : la recherche lit la transcription enregistrée.'
  }
  return null
}

/** Un avis, pas un blocage : la recherche peut partir sans la transcription. */
export function avisAvantLancement(transcription: EtatDeTranscription | null): string | null {
  if (transcription?.etat === 'a_faire' || transcription?.etat === 'echec') {
    return 'Le message vocal n’est pas transcrit : la recherche ne l’entendra pas.'
  }
  return null
}

/* ------------------------------------------------------------------ */
/* Le quota                                                            */
/* ------------------------------------------------------------------ */

/** La clé du quota global des rattachements assistés (`QuotasDeLAssistant`). */
export const CLE_DU_QUOTA = 'assistant.rattachements'

/** Ce qu'il reste du quota global, tel que le serveur le compte. */
export interface QuotaRestant {
  restant: number
  limite: number
  fenetre: string
}

/**
 * Le quota des rattachements assistés, lu dans `GET /api/administration/quotas`
 * — ou `null` si le serveur ne le rend pas : **on n'invente rien**.
 */
export function quotaDesRattachements(quotas: readonly QuotaGlobal[]): QuotaRestant | null {
  const quota = quotas.find((q) => q.cle === CLE_DU_QUOTA)
  if (quota === undefined) return null
  return { restant: Math.max(0, quota.limite - quota.consomme), limite: quota.limite, fenetre: quota.fenetre }
}

/** « 38 appels à l’IA restants sur 40 (par 24 h, …) ». */
export function libelleDuQuota(quota: QuotaRestant): string {
  if (quota.limite === 0) return 'Les appels à l’IA sont fermés sur cette installation.'
  const appels = quota.restant > 1 ? 'appels à l’IA restants' : 'appel à l’IA restant'
  return `${quota.restant} ${appels} sur ${quota.limite} (${quota.fenetre}, transcriptions et recherches de tous les administrateurs).`
}

/** Une erreur de lecture qui dit « ce compte a été traité ailleurs » : la carte
 *  n'a plus lieu d'être (un autre administrateur l'a rattaché ou refusé). */
export function compteDisparu(cause: unknown): boolean {
  return analyserErreur(cause).statut === 404
}

/* ------------------------------------------------------------------ */
/* La file des analyses                                                */
/* ------------------------------------------------------------------ */

/** Une analyse à mener. Le signal coupe l'appel quand la file est vidée. */
export type TacheDAnalyse = (signal: AbortSignal) => Promise<void>

/**
 * Exécute les analyses **une à la fois**, dans l'ordre d'arrivée.
 *
 * Une clé déjà en attente n'est pas ajoutée deux fois : deux clics impatients
 * sur « Lancer la recherche » ne doivent pas coûter deux recherches sur le
 * quota. Une clé **en cours** peut en revanche être remise en file — c'est
 * « Relancer » après un échec qui arriverait pendant l'analyse suivante.
 *
 * `vider()` abandonne ce qui attend et coupe l'appel en cours (sortie de
 * l'écran). Une tâche qui échoue n'arrête pas la file : ses erreurs sont à elle
 * de les traiter, et la carte suivante n'a pas à en pâtir.
 */
export class FileDAnalyses {
  private readonly attente: { cle: number; tache: TacheDAnalyse }[] = []
  private enCours: { cle: number; controleur: AbortController } | null = null
  private boucle: Promise<void> | null = null

  /** Ajoute une analyse. Rend la promesse de fin de la file (utile aux tests). */
  ajouter(cle: number, tache: TacheDAnalyse): Promise<void> {
    if (!this.attente.some((element) => element.cle === cle)) this.attente.push({ cle, tache })
    this.boucle ??= this.derouler().finally(() => {
      this.boucle = null
    })
    return this.boucle
  }

  /** Retire une clé qui attend encore (la carte vient d'être traitée). */
  retirer(cle: number): void {
    const index = this.attente.findIndex((element) => element.cle === cle)
    if (index >= 0) this.attente.splice(index, 1)
    if (this.enCours?.cle === cle) this.enCours.controleur.abort()
  }

  /** Abandonne tout : ce qui attend, et l'appel en cours. */
  vider(): void {
    this.attente.length = 0
    this.enCours?.controleur.abort()
  }

  /** La clé en cours d'analyse, ou `null`. */
  get cleEnCours(): number | null {
    return this.enCours?.cle ?? null
  }

  private async derouler(): Promise<void> {
    for (let suivante = this.attente.shift(); suivante !== undefined; suivante = this.attente.shift()) {
      const controleur = new AbortController()
      this.enCours = { cle: suivante.cle, controleur }
      try {
        await suivante.tache(controleur.signal)
      } catch {
        // La tâche traite ses erreurs ; une erreur qui s'échappe quand même ne
        // doit pas priver les cartes suivantes de leur analyse.
      } finally {
        this.enCours = null
      }
    }
  }
}

/* ------------------------------------------------------------------ */
/* Ce qu'on affiche de la déclaration                                  */
/* ------------------------------------------------------------------ */

const MOIS = [
  'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
] as const

/**
 * `1998-04-12` → « 12 avril 1998 », **sans passer par `Date`**.
 *
 * `new Date('1998-04-12')` est lu en UTC, puis affiché dans le fuseau du
 * navigateur : à l'ouest de Greenwich, la date de naissance recule d'un jour.
 * Sur une donnée d'état civil, c'est l'erreur à ne pas faire.
 */
export function dateDeclaree(iso: string): string {
  const morceaux = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)
  if (morceaux === null) return iso
  const [, annee, mois, jour] = morceaux
  const nomDuMois = MOIS[Number(mois) - 1]
  if (nomDuMois === undefined) return iso
  const quantieme = Number(jour)
  return `${quantieme === 1 ? '1er' : String(quantieme)} ${nomDuMois} ${annee}`
}

/** « Léa Marie Dupont » — prénoms puis nom, comme les fiches. */
export function identiteDeclaree(compte: CompteARattacher): string {
  const { givenNames, surname } = compte.declaration
  return [givenNames.trim(), surname.trim()].filter((morceau) => morceau !== '').join(' ')
}

/** Les initiales du portrait de repli : première lettre du premier prénom et du nom. */
export function initialesDeclarees(compte: CompteARattacher): string {
  const prenom = compte.declaration.givenNames.trim().charAt(0)
  const nom = compte.declaration.surname.trim().charAt(0)
  return `${prenom}${nom}`.toLocaleUpperCase('fr-FR') || '?'
}

/* ------------------------------------------------------------------ */
/* Ce que la suggestion permet                                         */
/* ------------------------------------------------------------------ */

export const LIBELLES_CONFIANCE: Record<'haute' | 'moyenne' | 'faible', string> = {
  haute: 'confiance haute',
  moyenne: 'confiance moyenne',
  faible: 'confiance faible',
}

export const LIBELLES_VERDICT: Record<SuggestionDeRattachement['verdict'], string> = {
  fiche_existante: 'Probablement déjà dans l’arbre',
  absente: 'Probablement absente de l’arbre',
  indecise: 'L’analyse n’a pas pu trancher',
}

/**
 * La fiche que « Rattacher à … » désignerait, ou `null` si le bouton n'a pas
 * lieu d'être. Une fiche **déjà réclamée** par un autre compte reste montrée —
 * cacher ce que l'analyse a trouvé serait pire — mais le bouton est éteint :
 * le serveur rendrait `409 personne_deja_rattachee`.
 */
export function ficheARattacher(
  suggestion: SuggestionDeRattachement | null,
): { personId: number; displayName: string; possible: boolean } | null {
  const fiche = suggestion?.fiche ?? null
  if (fiche === null) return null
  return { personId: fiche.personId, displayName: fiche.displayName, possible: !fiche.dejaRattachee }
}

/** Au-delà, `creer-et-rattacher` rend `422` : un enfant a deux parents. */
export const PARENTS_MAXIMUM = 2

/**
 * Ce que « Créer la fiche et rattacher » exige de l'administrateur.
 *
 * Le serveur construit le corps : depuis la création suggérée si elle existe,
 * sinon depuis la seule déclaration — qui ne porte **aucun lien**. Or une
 * création sans lien vers l'arbre est refusée (`422 demande_sans_lien`). D'où
 * la règle : sans création recevable, **au moins un parent** doit être choisi ;
 * avec, les parents sont facultatifs et remplacent la filiation proposée.
 */
export function exigencesDeCreation(suggestion: SuggestionDeRattachement | null): {
  parentsRequis: boolean
  motif: string | null
} {
  const creation = suggestion?.creation ?? null
  if (creation === null) {
    return {
      parentsRequis: true,
      motif: 'Sans analyse, la fiche serait créée sans lien : choisissez au moins un parent.',
    }
  }
  if (!creation.recevable) {
    return {
      parentsRequis: true,
      motif: creation.motifIrrecevable ?? 'Aucun lien vers l’arbre : choisissez au moins un parent.',
    }
  }
  return { parentsRequis: false, motif: null }
}

/** Un parent choisi au sélecteur. */
export interface ParentChoisi {
  personId: number
  libelle: string
}

/**
 * Ajoute un parent à la liste, **sans doublon et sans dépasser deux**. Rend la
 * liste inchangée dans les deux cas : le sélecteur n'a pas à le savoir, le
 * bouton est déjà éteint.
 */
export function ajouterParent(parents: readonly ParentChoisi[], choix: ParentChoisi): ParentChoisi[] {
  if (parents.length >= PARENTS_MAXIMUM) return [...parents]
  if (parents.some((parent) => parent.personId === choix.personId)) return [...parents]
  return [...parents, choix]
}

/** « Créer la fiche et rattacher » peut-il partir ? */
export function creationPossible(
  suggestion: SuggestionDeRattachement | null,
  parents: readonly ParentChoisi[],
): boolean {
  if (parents.length > PARENTS_MAXIMUM) return false
  return !exigencesDeCreation(suggestion).parentsRequis || parents.length > 0
}
