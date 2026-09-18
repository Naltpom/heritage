/**
 * **Le quilt** — `/arbre-6`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **ON NE CORRIGE PAS LES CROISEMENTS : ON SUPPRIME LES TRAITS.**
 *
 * C'est la réponse de la recherche, et elle est radicale. GeneaQuilts — Bezerianos,
 * Dragicevic, Fekete et Watson, InfoVis 2010 — part du constat que les quatre
 * autres dispositions contournent : passé quelques centaines de personnes, un
 * graphe de parenté en boîtes-et-traits devient illisible, **quelle que soit** la
 * qualité du placement. Les croisements ne sont pas un défaut d'algorithme, ils
 * sont une propriété de l'objet.
 *
 * Le quilt abandonne donc les traits. Ce qui reste est une **matrice** :
 *
 * - chaque **personne** est une ligne horizontale ;
 * - chaque **famille** est une colonne verticale ;
 * - une **case** à l'intersection dit que cette personne appartient à cette
 *   famille — pleine si elle en est parent, creuse si elle en est enfant.
 *
 * Il n'y a plus d'arête, donc plus aucun croisement possible. La parenté ne se
 * suit plus du doigt le long d'un trait : elle se lit à l'alignement, comme dans
 * un tableau à double entrée.
 *
 * ── La forme en escalier, et pourquoi elle est nécessaire ───────────────────
 *
 * Les bandes alternent : les personnes d'une génération, puis les familles
 * qu'elles forment, puis les personnes de la génération suivante. Chaque bande
 * commence **plus bas et plus à droite** que la précédente, d'où l'escalier qui
 * descend vers la droite.
 *
 * Ce décalage n'est pas décoratif. Il donne à chaque personne une ligne qui
 * s'étend de la colonne de la famille dont elle est **enfant** — à sa gauche,
 * dans la bande précédente — jusqu'aux colonnes des familles dont elle est
 * **parent** — à sa droite. Sans l'escalier, les deux rôles tomberaient dans la
 * même colonne et l'on ne saurait plus lequel on lit.
 *
 * ── L'ordre se cherche comme ailleurs ──────────────────────────────────────
 *
 * Une matrice se lit d'autant mieux que ses cases sont groupées près de la
 * diagonale. On y arrive par les mêmes balayages de barycentre que la disposition
 * « croisements » : les personnes se replacent au milieu de leurs familles, les
 * familles au milieu de leurs personnes, et l'on recommence. La mesure n'est plus
 * le nombre de croisements — il n'y en a plus — mais **l'étalement**, la somme des
 * distances entre une case et le milieu de sa colonne.
 *
 * ── Ce que ce module ne fait pas ───────────────────────────────────────────
 *
 * Il ne dessine rien. Il rend une **description géométrique** que
 * {@link ../CanevasQuilt.vue} traduit en SVG. C'est la même séparation que
 * partout ailleurs ici : l'algorithme se teste sans navigateur, le rendu ne
 * décide de rien.
 * ────────────────────────────────────────────────────────────────────
 */

import type { Graphe, IdentifiantNoeud } from '@/lib/api'

/** Hauteur d'une ligne de personne, en unités du `viewBox`. */
export const H_LIGNE = 16

/** Largeur d'une colonne de famille. */
export const L_COLONNE = 16

/** Largeur réservée à l'étiquette d'une personne, à gauche de sa ligne. */
export const L_ETIQUETTE = 150

/** Balayages de barycentre. Au-delà, l'étalement mesuré ne bouge plus. */
const BALAYAGES = 10

export interface LignePersonne {
  id: IdentifiantNoeud
  nom: string
  /** Le rang absolu de la ligne, toutes générations confondues. */
  rangee: number
  y: number
  /** Bornes horizontales du segment, étiquette comprise. */
  x: number
  largeur: number
  /** Où poser le nom : juste à gauche de la première case. */
  xEtiquette: number
  generation: number
  vivant: boolean
  enAttente: boolean
}

export interface ColonneFamille {
  id: IdentifiantNoeud
  x: number
  /** Bornes verticales du segment : du parent le plus haut à l'enfant le plus bas. */
  y: number
  hauteur: number
  divorce: boolean
}

export interface CaseQuilt {
  personne: IdentifiantNoeud
  famille: IdentifiantNoeud
  x: number
  y: number
  role: 'parent' | 'enfant'
}

export interface DispositionQuilt {
  lignes: LignePersonne[]
  colonnes: ColonneFamille[]
  cases: CaseQuilt[]
  boite: { x: number; y: number; w: number; h: number }
  /** La somme des écarts entre une case et le milieu de sa colonne. */
  etalement: number
}

const VIDE: DispositionQuilt = {
  lignes: [],
  colonnes: [],
  cases: [],
  boite: { x: 0, y: 0, w: 1, h: 1 },
  etalement: 0,
}

export function quilt(graphe: Graphe): DispositionQuilt {
  const personnes = graphe.noeuds.filter((noeud) => noeud.type === 'personne')

  if (personnes.length === 0) return VIDE

  const parents = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()
  const enfants = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()

  for (const arete of graphe.aretes) {
    if (arete.genre === 'union') pousser(parents, arete.vers, arete.de)
    if (arete.genre === 'filiation') pousser(enfants, arete.de, arete.vers)
  }

  // La génération d'une personne est son ordonnée d'origine : le moteur la pose
  // à `génération × 150`, et c'est la seule chose qu'on lui reprend.
  const generationDe = new Map<IdentifiantNoeud, number>()
  const rangs = [...new Set(personnes.map((p) => p.y))].sort((a, b) => a - b)

  for (const personne of personnes) {
    generationDe.set(personne.id, rangs.indexOf(personne.y))
  }

  /**
   * Une famille appartient à la bande qui suit la génération de ses **parents**.
   * À défaut de parent — cela n'arrive pas sur des données saines, mais le rendu
   * ne suppose jamais qu'une règle d'écriture a tenu —, on la range d'après ses
   * enfants, une génération plus haut.
   */
  const bandeDe = new Map<IdentifiantNoeud, number>()

  for (const noeud of graphe.noeuds) {
    if (noeud.type !== 'famille') continue

    const desParents = (parents.get(noeud.id) ?? [])
      .map((id) => generationDe.get(id))
      .filter((g): g is number => g !== undefined)

    if (desParents.length > 0) {
      bandeDe.set(noeud.id, Math.min(...desParents))
      continue
    }

    const desEnfants = (enfants.get(noeud.id) ?? [])
      .map((id) => generationDe.get(id))
      .filter((g): g is number => g !== undefined)

    if (desEnfants.length > 0) bandeDe.set(noeud.id, Math.min(...desEnfants) - 1)
  }

  const parGeneration = grouper(personnes.map((p) => p.id), (id) => generationDe.get(id) ?? 0)
  const parBande = grouper([...bandeDe.keys()], (id) => bandeDe.get(id) ?? 0)

  ordonner(parGeneration, parBande, parents, enfants)

  return composer(graphe, parGeneration, parBande, parents, enfants)
}

/* ------------------------------------------------------------------ */
/* L'ordre                                                             */
/* ------------------------------------------------------------------ */

/**
 * Les mêmes balayages de barycentre que la disposition « croisements », sur une
 * matrice au lieu d'un graphe par niveaux.
 *
 * Les personnes se replacent au milieu de leurs familles, les familles au milieu
 * de leurs personnes, et l'on recommence. **On modifie les tableaux sur place** :
 * ils sont locaux à `quilt()`, et en rendre des copies à chaque passe alourdirait
 * une boucle qui tourne vingt fois sur 274 lignes pour rien.
 */
function ordonner(
  parGeneration: Map<number, IdentifiantNoeud[]>,
  parBande: Map<number, IdentifiantNoeud[]>,
  parents: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
  enfants: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
): void {
  const rangDe = new Map<IdentifiantNoeud, number>()

  const relireLesRangs = (): void => {
    rangDe.clear()
    let rang = 0

    for (const generation of [...parGeneration.keys()].sort((a, b) => a - b)) {
      for (const id of parGeneration.get(generation) ?? []) rangDe.set(id, rang++)
    }
  }

  const colonneDe = new Map<IdentifiantNoeud, number>()

  const relireLesColonnes = (): void => {
    colonneDe.clear()
    let colonne = 0

    for (const bande of [...parBande.keys()].sort((a, b) => a - b)) {
      for (const id of parBande.get(bande) ?? []) colonneDe.set(id, colonne++)
    }
  }

  relireLesRangs()
  relireLesColonnes()

  for (let passe = 0; passe < BALAYAGES; passe += 1) {
    // Les familles suivent leurs personnes.
    for (const familles of parBande.values()) {
      trierPar(familles, (famille) =>
        mediane([...(parents.get(famille) ?? []), ...(enfants.get(famille) ?? [])], rangDe),
      )
    }

    relireLesColonnes()

    // Puis les personnes suivent leurs familles.
    for (const personnes of parGeneration.values()) {
      trierPar(personnes, (personne) =>
        mediane(famillesDe(personne, parents, enfants), colonneDe),
      )
    }

    relireLesRangs()
  }
}

function famillesDe(
  personne: IdentifiantNoeud,
  parents: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
  enfants: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
): IdentifiantNoeud[] {
  const sortie: IdentifiantNoeud[] = []

  for (const [famille, membres] of parents) if (membres.includes(personne)) sortie.push(famille)
  for (const [famille, membres] of enfants) if (membres.includes(personne)) sortie.push(famille)

  return sortie
}

/**
 * Trie sur place par une clé, **en gardant l'ordre existant pour les ex æquo**.
 *
 * Un nœud sans voisin n'a pas de clé ; il garde sa place plutôt que de partir à
 * un bout de la bande, où il ferait bouger tout le monde pour rien.
 */
function trierPar(
  liste: IdentifiantNoeud[],
  cle: (id: IdentifiantNoeud) => number | null,
): void {
  const avant = new Map(liste.map((id, index) => [id, index]))
  const cles = new Map(liste.map((id) => [id, cle(id)]))

  liste.sort((a, b) => {
    const ca = cles.get(a)
    const cb = cles.get(b)

    if (ca === null || ca === undefined || cb === null || cb === undefined) {
      return (avant.get(a) ?? 0) - (avant.get(b) ?? 0)
    }

    return ca - cb || (avant.get(a) ?? 0) - (avant.get(b) ?? 0)
  })
}

/** La médiane des positions connues, ou `null` s'il n'y en a aucune. */
function mediane(
  ids: readonly IdentifiantNoeud[],
  position: Map<IdentifiantNoeud, number>,
): number | null {
  const valeurs = ids
    .map((id) => position.get(id))
    .filter((v): v is number => v !== undefined)
    .sort((a, b) => a - b)

  if (valeurs.length === 0) return null

  const milieu = Math.floor(valeurs.length / 2)

  return valeurs.length % 2 === 1
    ? valeurs[milieu]!
    : (valeurs[milieu - 1]! + valeurs[milieu]!) / 2
}

/* ------------------------------------------------------------------ */
/* La géométrie                                                        */
/* ------------------------------------------------------------------ */

function composer(
  graphe: Graphe,
  parGeneration: Map<number, IdentifiantNoeud[]>,
  parBande: Map<number, IdentifiantNoeud[]>,
  parents: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
  enfants: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
): DispositionQuilt {
  const parId = new Map(graphe.noeuds.map((noeud) => [noeud.id, noeud]))

  const generations = [...parGeneration.keys()].sort((a, b) => a - b)
  const bandes = [...parBande.keys()].sort((a, b) => a - b)

  // L'escalier : chaque bande de personnes descend du nombre de lignes de la
  // précédente, chaque bande de familles avance du nombre de colonnes.
  const yDeLaGeneration = new Map<number, number>()
  const xDeLaBande = new Map<number, number>()

  let y = 0
  for (const generation of generations) {
    yDeLaGeneration.set(generation, y)
    y += (parGeneration.get(generation)?.length ?? 0) * H_LIGNE
  }

  let x = L_ETIQUETTE
  for (const bande of bandes) {
    xDeLaBande.set(bande, x)
    x += (parBande.get(bande)?.length ?? 0) * L_COLONNE
  }

  const yDeLaPersonne = new Map<IdentifiantNoeud, number>()
  const xDeLaFamille = new Map<IdentifiantNoeud, number>()

  for (const generation of generations) {
    const base = yDeLaGeneration.get(generation) ?? 0
    ;(parGeneration.get(generation) ?? []).forEach((id, index) => {
      yDeLaPersonne.set(id, base + index * H_LIGNE)
    })
  }

  for (const bande of bandes) {
    const base = xDeLaBande.get(bande) ?? 0
    ;(parBande.get(bande) ?? []).forEach((id, index) => {
      xDeLaFamille.set(id, base + index * L_COLONNE)
    })
  }

  const cases: CaseQuilt[] = []

  for (const [famille, xf] of xDeLaFamille) {
    for (const parent of parents.get(famille) ?? []) {
      const yp = yDeLaPersonne.get(parent)
      if (yp !== undefined) cases.push({ personne: parent, famille, x: xf, y: yp, role: 'parent' })
    }

    for (const enfant of enfants.get(famille) ?? []) {
      const yp = yDeLaPersonne.get(enfant)
      if (yp !== undefined) cases.push({ personne: enfant, famille, x: xf, y: yp, role: 'enfant' })
    }
  }

  const casesParPersonne = grouper(
    cases.map((_, index) => index),
    (index) => cases[index]!.personne,
  )

  const lignes: LignePersonne[] = []
  let rangee = 0

  for (const generation of generations) {
    for (const id of parGeneration.get(generation) ?? []) {
      const noeud = parId.get(id)
      if (noeud === undefined || noeud.type !== 'personne') continue

      const miennes = (casesParPersonne.get(id) ?? []).map((index) => cases[index]!.x)
      const gauche = miennes.length > 0 ? Math.min(...miennes) : L_ETIQUETTE
      const droite = miennes.length > 0 ? Math.max(...miennes) + L_COLONNE : L_ETIQUETTE

      lignes.push({
        id,
        nom: noeud.etiquetteNom,
        rangee: rangee++,
        y: yDeLaPersonne.get(id) ?? 0,
        x: gauche - L_ETIQUETTE,
        largeur: droite - gauche + L_ETIQUETTE,
        xEtiquette: gauche - 4,
        generation,
        vivant: noeud.vivant,
        enAttente: noeud.enAttente === true,
      })
    }
  }

  const casesParFamille = grouper(
    cases.map((_, index) => index),
    (index) => cases[index]!.famille,
  )

  const colonnes: ColonneFamille[] = []

  for (const [famille, xf] of xDeLaFamille) {
    const noeud = parId.get(famille)
    const miennes = (casesParFamille.get(famille) ?? []).map((index) => cases[index]!.y)

    if (miennes.length === 0) continue

    const haut = Math.min(...miennes)
    const bas = Math.max(...miennes) + H_LIGNE

    colonnes.push({
      id: famille,
      x: xf,
      y: haut,
      hauteur: bas - haut,
      divorce: noeud !== undefined && noeud.type === 'famille' && noeud.divorced,
    })
  }

  return {
    lignes,
    colonnes,
    cases,
    boite: boiteDe(lignes, colonnes),
    etalement: mesurerEtalement(cases, casesParFamille),
  }
}

function boiteDe(
  lignes: readonly LignePersonne[],
  colonnes: readonly ColonneFamille[],
): DispositionQuilt['boite'] {
  if (lignes.length === 0) return VIDE.boite

  const marge = 40
  const xMin = Math.min(...lignes.map((ligne) => ligne.x))
  const xMax = Math.max(...lignes.map((ligne) => ligne.x + ligne.largeur))
  const yMin = Math.min(...lignes.map((ligne) => ligne.y), ...colonnes.map((c) => c.y))
  const yMax = Math.max(
    ...lignes.map((ligne) => ligne.y + H_LIGNE),
    ...colonnes.map((c) => c.y + c.hauteur),
  )

  return {
    x: xMin - marge,
    y: yMin - marge,
    w: xMax - xMin + 2 * marge,
    h: yMax - yMin + 2 * marge,
  }
}

/**
 * **L'étalement remplace le nombre de croisements.**
 *
 * Il n'y a plus de trait, donc plus rien à croiser ; mais une matrice se lit
 * d'autant mieux que ses cases sont serrées. On mesure donc, colonne par colonne,
 * la somme des écarts entre chaque case et le milieu de sa colonne. C'est le
 * chiffre que le bandeau annonce, et celui qui dit si les balayages ont servi.
 */
function mesurerEtalement(
  cases: readonly CaseQuilt[],
  parFamille: Map<IdentifiantNoeud, number[]>,
): number {
  let total = 0

  for (const indices of parFamille.values()) {
    const ys = indices.map((index) => cases[index]!.y)
    if (ys.length < 2) continue

    const milieu = ys.reduce((somme, v) => somme + v, 0) / ys.length

    for (const y of ys) total += Math.abs(y - milieu)
  }

  return Math.round(total)
}

/* ------------------------------------------------------------------ */

function pousser<C, V>(carte: Map<C, V[]>, cle: C, valeur: V): void {
  const liste = carte.get(cle)
  if (liste === undefined) carte.set(cle, [valeur])
  else liste.push(valeur)
}

function grouper<T, C>(elements: readonly T[], cle: (element: T) => C): Map<C, T[]> {
  const carte = new Map<C, T[]>()

  for (const element of elements) pousser(carte, cle(element), element)

  return carte
}
