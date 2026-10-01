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
 * ── Les analyses, l'une après l'autre ───────────────────────────────────────
 *
 * Une analyse prend 20 à 60 secondes et consomme un quota **global** de 40 par
 * 24 heures, commun à tous les administrateurs. Les lancer toutes à l'ouverture
 * de l'onglet, en parallèle, ferait partir dix appels d'un coup, dont la moitié
 * finirait en `429` — et chacun tiendrait un processus PHP. D'où
 * {@link FileDAnalyses} : une seule à la fois, dans l'ordre des cartes.
 */

import { analyserErreur, type CompteARattacher, type SuggestionDeRattachement } from '@/lib/api'

/* ------------------------------------------------------------------ */
/* L'état de l'analyse d'une carte                                     */
/* ------------------------------------------------------------------ */

/**
 * Les états d'une suggestion, du point de vue de la carte.
 *
 *  - `attente`        — pas encore demandée : une autre carte passe avant ;
 *  - `en_cours`       — le serveur interroge le modèle (20 à 60 s) ;
 *  - `prete`          — la suggestion est là ;
 *  - `non_configuree` — `503 assistant_indisponible` : pas de clé, rien en
 *    cache. Ce n'est pas une panne, c'est un réglage : pas de « Relancer » ;
 *  - `echec`          — `502`, `429` ou autre : un message, et « Relancer ».
 */
export type EtatDAnalyse =
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
        'Le quota d’analyses automatiques est atteint pour aujourd’hui. ' +
        'Vous pouvez rattacher ce compte à la main, ou relancer plus tard.',
      attenteSecondes: analyse.attenteSecondes,
    }
  }
  if (analyse.statut === 502) {
    return {
      etat: 'echec',
      message: 'L’analyse automatique a échoué. Vous pouvez la relancer, ou décider à la main.',
      attenteSecondes: null,
    }
  }
  return { etat: 'echec', message: analyse.message, attenteSecondes: null }
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
 * Une clé déjà en attente n'est pas ajoutée deux fois : l'ouverture de l'onglet
 * puis un clic impatient sur « Relancer » ne doivent pas coûter deux analyses
 * sur le quota. Une clé **en cours** peut en revanche être remise en file — c'est
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
