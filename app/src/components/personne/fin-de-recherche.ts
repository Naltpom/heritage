/**
 * L'état de l'encart « Fin de recherche » de la fiche (ADR 0023) — **pur**, hors Vue.
 *
 * `FinDeRechercheFiche.vue` ne décide de rien : ce qu'un côté dit, les gestes qu'il
 * offre, le dépôt de la demande et la traduction des refus du serveur sont ici, où
 * ils se prouvent sans écran. Les règles de fond (motifs, état d'un sommet, ops)
 * viennent de `@/lib/finDeRecherche`, partagé avec l'arbre et l'affiche ; ce module
 * n'en redit aucune.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Ce que l'encart dit d'un côté
 *
 * | `etat` | `fin` | `enAttente` | l'encart dit |
 * |---|---|---|---|
 * | `close` | oui | non | le motif, le texte, la date, l'auteur |
 * | `connus` | oui | non | idem, plus « un parent est désormais lié » — la fin de recherche est restée enregistrée, et c'est la levée qui l'efface |
 * | `connus` | non | non | « Parent connu » |
 * | `a-chercher` | non | non | « Pas encore cherché » |
 * | (tous) | — | oui | la valeur validée, puis le calque : pose, modification ou levée |
 *
 * Un parent connu l'emporte sur la fin de recherche (`etatDuSommet`) : `etat === 'connus'`
 * avec `fin !== null` est le cas qui appelle la levée.
 * ────────────────────────────────────────────────────────────────────
 */
import { analyserErreur, creerDemande, type Demande, type EntreeCalque, type FichePersonneComplete } from '@/lib/api'
import type { CoteDeRecherche, ChampDeFinDeRecherche, FinDeRecherche, ValeurFinDeRecherche } from '@/lib/api'
import {
  CHAMP_DU_COTE,
  COTES_DE_RECHERCHE,
  corpsDeFinDeRecherche,
  cotesDesParentsDeFiche,
  cotesDuChoix,
  erreursDeFinDeRecherche,
  estValeurDeFinDeRecherche,
  etatDuSommet,
  normaliserValeur,
  type ChoixDeCote,
  type EtatDuSommet,
} from '@/lib/finDeRecherche'

/** Le calque d'un côté : une pose (`actuel: null`), une modification, ou une levée (`propose: null`). */
export type CalqueDeCote = EntreeCalque<ValeurFinDeRecherche | null>

/** Ce que l'encart sait d'un côté. */
export interface EtatDeCote {
  cote: CoteDeRecherche
  /** Un parent est lié de ce côté de la fiche. */
  parentConnu: boolean
  /** La fin de recherche **validée**, ou `null`. */
  fin: FinDeRecherche | null
  /** La demande en attente sur ce côté, ou `null`. */
  enAttente: CalqueDeCote | null
  etat: EtatDuSommet
}

/**
 * L'encart n'existe que si le serveur sait ce qu'est une fin de recherche : une charge
 * antérieure à l'ADR 0023 ne porte aucune des deux clés (« absentes = serveur
 * antérieur »), et un bloc « Pas encore cherché » y serait faux.
 */
export function encartDisponible(fiche: object): boolean {
  return 'finDeRecherchePere' in fiche && 'finDeRechercheMere' in fiche
}

/** Le calque d'un champ, s'il en porte un **de fin de recherche** (une garde, pas un cast). */
function calqueDuChamp(fiche: FichePersonneComplete, champ: ChampDeFinDeRecherche): CalqueDeCote | null {
  const entree = (fiche.enAttente ?? {})[champ]
  if (entree === undefined) return null
  const { propose, actuel } = entree
  const valide = (v: unknown): v is ValeurFinDeRecherche | null => v === null || estValeurDeFinDeRecherche(v)
  if (!valide(propose) || !valide(actuel)) return null
  return { ...entree, propose, actuel }
}

/** L'état d'un côté, lu sur la fiche. */
export function etatDeCote(fiche: FichePersonneComplete, cote: CoteDeRecherche): EtatDeCote {
  const champ = CHAMP_DU_COTE[cote]
  const parentConnu = cotesDesParentsDeFiche(fiche.parents)[cote]
  const fin = fiche[champ] ?? null
  return { cote, parentConnu, fin, enAttente: calqueDuChamp(fiche, champ), etat: etatDuSommet(parentConnu, fin) }
}

/** L'état des deux côtés, le père d'abord. */
export function etatsDeFiche(fiche: FichePersonneComplete): EtatDeCote[] {
  return COTES_DE_RECHERCHE.map((cote) => etatDeCote(fiche, cote))
}

/** Une demande attend sur ce côté : une pose, une modification ou une levée. */
export type NatureDuCalque = 'pose' | 'modification' | 'levee'

export function natureDuCalque(calque: CalqueDeCote): NatureDuCalque {
  if (calque.propose === null) return 'levee'
  return calque.actuel === null ? 'pose' : 'modification'
}

/** Les gestes qu'un côté offre. Jamais plus d'un seul « fond » : clore, ou modifier / rouvrir. */
export interface GestesDeCote {
  clore: boolean
  modifier: boolean
  rouvrir: boolean
  /** Le côté attend une décision : aucun geste, et la phrase le dit. */
  bloque: boolean
}

export const AUCUN_GESTE: Readonly<GestesDeCote> = { clore: false, modifier: false, rouvrir: false, bloque: false }

/**
 * Ce qu'on peut faire d'un côté. Rien sans `peutProposer` (la condition des autres gestes
 * de la fiche) ; rien tant qu'une demande attend la validation de ce côté ; on ne clôt pas
 * un côté où un parent est lié ; on ne modifie ni ne lève que ce qui est enregistré. Quand
 * un parent est lié depuis, la fin de recherche reste enregistrée mais ne se **modifie**
 * plus (le serveur répond `422` « Un parent est déjà lié ») : seule la levée est offerte.
 */
export function gestesDuCote(etat: EtatDeCote, peutProposer: boolean): GestesDeCote {
  if (!peutProposer) return AUCUN_GESTE
  if (etat.enAttente !== null) return { ...AUCUN_GESTE, bloque: true }
  if (etat.fin !== null) return { clore: false, modifier: etat.etat === 'close', rouvrir: true, bloque: false }
  return { ...AUCUN_GESTE, clore: etat.etat === 'a-chercher' }
}

/**
 * Le choix « les deux » n'est offert que pour **clore** deux côtés à la fois : chacun sans
 * parent, sans fin de recherche et sans demande en attente. Une modification se fait sur un
 * seul côté — deux fins différentes ne sont pas un raccourci.
 */
export function lesDeuxOfferts(etats: readonly EtatDeCote[], peutProposer: boolean): boolean {
  return peutProposer && etats.length === 2 && etats.every((e) => gestesDuCote(e, true).clore)
}

/** Les choix de côté du formulaire de clôture, dans l'ordre : les côtés libres, puis « les deux ». */
export function choixDeClotureOfferts(etats: readonly EtatDeCote[], peutProposer: boolean): ChoixDeCote[] {
  const libres: ChoixDeCote[] = etats.filter((e) => gestesDuCote(e, peutProposer).clore).map((e) => e.cote)
  return lesDeuxOfferts(etats, peutProposer) ? [...libres, 'les-deux'] : libres
}

/* ------------------------------------------------------------------ */
/* La saisie                                                           */
/* ------------------------------------------------------------------ */

/** Ce que le formulaire tient : l'année en texte, comme un `<input type="number">` la rend. */
export interface SaisieDeFormulaire {
  reason: string
  beforeYear: string
  body: string
}

export const SAISIE_VIDE: Readonly<SaisieDeFormulaire> = { reason: '', beforeYear: '', body: '' }

/** La saisie préremplie par une fin de recherche enregistrée (le geste « Modifier »). */
export function saisieDepuis(fin: FinDeRecherche | ValeurFinDeRecherche | null): SaisieDeFormulaire {
  if (fin === null) return { ...SAISIE_VIDE }
  return { reason: fin.reason, beforeYear: fin.beforeYear === null ? '' : String(fin.beforeYear), body: fin.body ?? '' }
}

/** La saisie en valeur d'op, ou `null` tant qu'aucun motif n'est choisi. L'année vide est `null`. */
export function valeurDeSaisie(saisie: SaisieDeFormulaire): ValeurFinDeRecherche | null {
  if (saisie.reason === '') return null
  const brut = saisie.beforeYear.trim()
  const annee = brut === '' ? null : Number(brut)
  // `Number('12abc')` vaut `NaN` : l'année invalide reste visible des contrôles, qui la refusent.
  return normaliserValeur({ reason: saisie.reason as ValeurFinDeRecherche['reason'], beforeYear: annee, body: saisie.body })
}

/** Les refus affichables, par champ du formulaire ; `general` pour ce qui n'a pas de champ. */
export interface ErreursDeFormulaire {
  reason?: string
  beforeYear?: string
  body?: string
  message?: string
  general?: string
}

/**
 * Les refus `422` du serveur, posés sur le champ du formulaire qui les a causés :
 * `ops[0].newValue.reason` → `reason`, `ops[1].newValue.beforeYear` → `beforeYear`,
 * `message` → `message`. Le même champ refusé par les deux ops (« les deux ») n'est dit
 * qu'une fois. Tout le reste va dans `general`.
 */
export function erreursDuServeur(champs: Readonly<Record<string, string>>): ErreursDeFormulaire {
  const erreurs: ErreursDeFormulaire = {}
  for (const [cle, texte] of Object.entries(champs)) {
    const m = /^ops\[\d+\]\.newValue\.(reason|beforeYear|body)$/.exec(cle)
    if (m !== null) {
      const champ = m[1] as 'reason' | 'beforeYear' | 'body'
      erreurs[champ] ??= texte
    } else if (cle === 'message') {
      erreurs.message ??= texte
    } else {
      erreurs.general ??= texte
    }
  }
  return erreurs
}

/** Ce qui est arrivé d'un dépôt. */
export type IssueDeDepot =
  | { type: 'envoyee'; demandeId: number }
  /** Rien ne change : la saisie est déjà la valeur enregistrée. Aucune demande partie. */
  | { type: 'inchangee' }
  /** Refus de la saisie, local ou `422` du serveur : à poser sur les champs. */
  | { type: 'refusee'; erreurs: ErreursDeFormulaire }
  /** La fiche a changé depuis qu'elle est affichée (`409`) : il faut la recharger. */
  | { type: 'conflit' }
  /** Toute autre erreur, à montrer par `MessageErreur`. */
  | { type: 'echec'; erreur: unknown }

/**
 * Dépose la demande. `nouvelle` à `null` lève la fin de recherche (rouvrir la recherche).
 * La saisie est contrôlée **avant** l'envoi, pour chaque côté visé ; le serveur reste juge.
 */
export async function deposerFinDeRecherche(
  fiche: FichePersonneComplete,
  choix: ChoixDeCote,
  nouvelle: ValeurFinDeRecherche | null,
  message = '',
  anneeCourante: number = new Date().getFullYear(),
): Promise<IssueDeDepot> {
  const cotes = cotesDuChoix(choix)

  if (nouvelle !== null) {
    for (const cote of cotes) {
      const refus = erreursDeFinDeRecherche(nouvelle, cote, anneeCourante)
      if (Object.keys(refus).length > 0) return { type: 'refusee', erreurs: refus }
    }
  }

  const corps = corpsDeFinDeRecherche(fiche, cotes, nouvelle, { message })
  if (corps === null) return { type: 'inchangee' }

  let depot: Demande
  try {
    depot = await creerDemande(corps)
  } catch (cause) {
    const analyse = analyserErreur(cause)
    if (analyse.statut === 422 && Object.keys(analyse.champs).length > 0) {
      return { type: 'refusee', erreurs: erreursDuServeur(analyse.champs) }
    }
    if (analyse.statut === 409) return { type: 'conflit' }
    return { type: 'echec', erreur: cause }
  }
  return { type: 'envoyee', demandeId: depot.id }
}
