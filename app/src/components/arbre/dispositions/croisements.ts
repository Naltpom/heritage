/**
 * **Disposition « minimisation de croisements »** — sans route, et gardée quand même.
 *
 * Sa maquette `/arbre-4` a été retirée ; le module reste parce que `ArbreView`
 * importe `compterLesCroisements` pour le bandeau de mesure, et que
 * `ressorts.test.ts` compare la stabilité de `ressorts` à la sienne.
 *
 * ────────────────────────────────────────────────────────────────────
 * **LA MÉTHODE DE GRAPHVIZ, APPLIQUÉE À L'ARBRE.**
 *
 * C'est ce que fait `dot`, et ce que font les outils généalogiques qui dessinent
 * le graphe entier — Gramps produit ses graphes de parenté en `dot`. Trois temps,
 * dont seuls les deux derniers nous concernent : les rangs sont déjà posés par le
 * moteur, il reste à **ordonner** chaque rangée, puis à **placer**.
 *
 * L'ordre se cherche par balayages de **barycentre** : chaque nœud se replace au
 * milieu de ses voisins de la rangée d'à côté, on retrie, on descend puis on
 * remonte, et on garde le meilleur ordre rencontré — mesuré en nombre de
 * croisements, pas à l'œil.
 *
 * ── Ce qu'elle corrige, et ce qu'elle coûte ─────────────────────────────────
 *
 * Elle corrige les **36** couples séparés, et pas seulement les 15 que la
 * disposition « conjoints accolés » sait traiter : en réordonnant les fratries
 * entières, elle peut rapprocher deux groupes qui s'allient, ce qu'aucun échange
 * local ne permet.
 *
 * Le prix est l'**instabilité**, et c'est exactement le défaut signalé sur l'arbre
 * actuel, en pire. Le moteur fige et insère où il y a de la place ; celle-ci
 * réorganise. Ajouter une personne peut renvoyer une branche entière de l'autre
 * côté de l'écran, et deux captures d'écran prises à une semaine d'intervalle ne
 * se comparent plus.
 *
 * C'est le choix à faire, et il ne se fait pas sur le papier : d'où cette route.
 *
 * ── Le graphe est déjà en couches, et ce n'est pas un hasard ────────────────
 *
 * Les losanges vivent 46 px sous la rangée de leurs époux. Les couches alternent
 * donc personnes, losanges, personnes — et toutes les arêtes joignent deux
 * couches **voisines**. C'est la forme que les algorithmes de dessin par niveaux
 * exigent, et le modèle « la famille est un nœud » la donne gratuitement. Sans
 * lui, un couple et ses enfants seraient une hyper-arête, qu'aucun d'eux ne sait
 * traiter.
 * ────────────────────────────────────────────────────────────────────
 */

import type { Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
import { boiteEnglobante, ESPACE, type OptionsDeDisposition } from './socle'

/** Balayages descendants puis montants. Au-delà, le gain mesuré devient nul. */
const BALAYAGES = 8

/** Passes de tassement des abscisses, une fois l'ordre arrêté. */
const PASSES_DE_PLACEMENT = 12

export function croisementsMinimises(graphe: Graphe, _options: OptionsDeDisposition): Graphe {
  const couches = enCouches(graphe.noeuds)

  if (couches.length < 2) return graphe

  const voisins = voisinage(graphe)
  const ordre = ordonner(couches, voisins)
  const abscisses = placer(ordre, voisins)

  const noeuds = graphe.noeuds.map((noeud) => {
    const x = abscisses.get(noeud.id)
    return x === undefined || x === noeud.x ? noeud : { ...noeud, x }
  })

  return { ...graphe, noeuds, boite: boiteEnglobante(noeuds, graphe.boite) }
}

/* ------------------------------------------------------------------ */
/* Couches et voisinage                                                */
/* ------------------------------------------------------------------ */

/** Les nœuds groupés par ordonnée, de haut en bas, chacun trié par abscisse. */
function enCouches(noeuds: readonly NoeudGraphe[]): NoeudGraphe[][] {
  const parY = new Map<number, NoeudGraphe[]>()

  for (const noeud of noeuds) {
    const couche = parY.get(noeud.y)
    if (couche === undefined) parY.set(noeud.y, [noeud])
    else couche.push(noeud)
  }

  return [...parY.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([, couche]) => couche.sort((a, b) => a.x - b.x || (a.id < b.id ? -1 : 1)))
}

/** Les voisins de chaque nœud, **sans distinguer le sens** : un barycentre se
 *  calcule sur la couche d'à côté, qu'elle soit au-dessus ou en dessous. */
function voisinage(graphe: Graphe): Map<IdentifiantNoeud, IdentifiantNoeud[]> {
  const carte = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()

  const ajouter = (a: IdentifiantNoeud, b: IdentifiantNoeud): void => {
    const liste = carte.get(a)
    if (liste === undefined) carte.set(a, [b])
    else liste.push(b)
  }

  for (const arete of graphe.aretes) {
    ajouter(arete.de, arete.vers)
    ajouter(arete.vers, arete.de)
  }

  return carte
}

/* ------------------------------------------------------------------ */
/* L'ordre : balayages de barycentre                                   */
/* ------------------------------------------------------------------ */

/**
 * Descend, remonte, et **garde le meilleur ordre rencontré**.
 *
 * Garder le dernier serait plus simple et parfois pire : la suite des balayages
 * n'est pas monotone, elle oscille. On compte donc les croisements à chaque
 * passage et l'on conserve le minimum — c'est la seule façon de promettre que le
 * résultat n'est jamais moins bon que le point de départ.
 */
function ordonner(
  couches: NoeudGraphe[][],
  voisins: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
): NoeudGraphe[][] {
  let courant = couches.map((couche) => [...couche])
  let meilleur = courant.map((couche) => [...couche])
  let minimum = compterCroisements(courant, voisins)

  for (let passage = 0; passage < BALAYAGES; passage += 1) {
    const descendant = passage % 2 === 0

    for (let i = 1; i < courant.length; i += 1) {
      const index = descendant ? i : courant.length - 1 - i
      const reference = courant[descendant ? index - 1 : index + 1]
      const cible = courant[index]

      if (reference === undefined || cible === undefined) continue

      courant[index] = trierParBarycentre(cible, reference, voisins)
    }

    const croisements = compterCroisements(courant, voisins)

    if (croisements < minimum) {
      minimum = croisements
      meilleur = courant.map((couche) => [...couche])
    }
  }

  return meilleur
}

/**
 * Retrie une couche par la **médiane** des positions de ses voisins.
 *
 * La médiane et non la moyenne : elle résiste au nœud qui a un voisin très
 * lointain, et c'est le cas de l'ancêtre commun à deux branches éloignées — la
 * moyenne l'enverrait au milieu de nulle part, entre deux groupes dont il ne fait
 * partie ni de l'un ni de l'autre.
 *
 * Un nœud **sans voisin** dans la couche de référence garde sa place : le faire
 * glisser en tête ou en queue ferait bouger des gens pour rien.
 */
function trierParBarycentre(
  couche: readonly NoeudGraphe[],
  reference: readonly NoeudGraphe[],
  voisins: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
): NoeudGraphe[] {
  const rang = new Map<IdentifiantNoeud, number>()
  reference.forEach((noeud, index) => rang.set(noeud.id, index))

  const cles = couche.map((noeud, index) => {
    const positions = (voisins.get(noeud.id) ?? [])
      .map((id) => rang.get(id))
      .filter((r): r is number => r !== undefined)
      .sort((a, b) => a - b)

    return { noeud, index, cle: positions.length === 0 ? null : mediane(positions) }
  })

  return cles
    .map((entree, position) => ({
      ...entree,
      // Sans voisin : on garde sa position relative actuelle.
      effective: entree.cle ?? interpoler(cles, position),
    }))
    .sort((a, b) => a.effective - b.effective || a.index - b.index)
    .map((entree) => entree.noeud)
}

function mediane(triees: readonly number[]): number {
  const milieu = Math.floor(triees.length / 2)

  if (triees.length % 2 === 1) return triees[milieu]!

  return (triees[milieu - 1]! + triees[milieu]!) / 2
}

/** La clé d'un nœud sans voisin : interpolée entre ses deux plus proches
 *  camarades qui en ont un, pour qu'il ne parte pas à un bout de la rangée. */
function interpoler(
  cles: readonly { cle: number | null }[],
  position: number,
): number {
  for (let ecart = 1; ecart < cles.length; ecart += 1) {
    const gauche = cles[position - ecart]?.cle
    const droite = cles[position + ecart]?.cle

    if (typeof gauche === 'number' && typeof droite === 'number') return (gauche + droite) / 2
    if (typeof gauche === 'number') return gauche + 0.5
    if (typeof droite === 'number') return droite - 0.5
  }

  return position
}

/**
 * Les croisements entre chaque paire de couches voisines.
 *
 * Comptage direct, en `O(arêtes²)` par paire : sur cet arbre, quelques centaines
 * d'arêtes par couche. L'algorithme en `O(a log a)` de Barth–Jünger–Mutzel
 * n'apporterait rien ici et demanderait un arbre de segments à relire.
 */
function compterCroisements(
  couches: readonly NoeudGraphe[][],
  voisins: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
): number {
  let total = 0

  for (let i = 0; i + 1 < couches.length; i += 1) {
    const haut = couches[i]!
    const bas = couches[i + 1]!

    const rangHaut = new Map<IdentifiantNoeud, number>()
    haut.forEach((noeud, index) => rangHaut.set(noeud.id, index))

    const rangBas = new Map<IdentifiantNoeud, number>()
    bas.forEach((noeud, index) => rangBas.set(noeud.id, index))

    const paires: [number, number][] = []

    for (const noeud of haut) {
      for (const voisin of voisins.get(noeud.id) ?? []) {
        const b = rangBas.get(voisin)
        if (b !== undefined) paires.push([rangHaut.get(noeud.id)!, b])
      }
    }

    for (let a = 0; a < paires.length; a += 1) {
      for (let b = a + 1; b < paires.length; b += 1) {
        const [ha, ba] = paires[a]!
        const [hb, bb] = paires[b]!

        if ((ha - hb) * (ba - bb) < 0) total += 1
      }
    }
  }

  return total
}

/* ------------------------------------------------------------------ */
/* Les abscisses                                                        */
/* ------------------------------------------------------------------ */

/**
 * Tasse chaque couche à gauche, puis rapproche chaque nœud du barycentre de ses
 * voisins **sans jamais changer l'ordre**.
 *
 * Deux passes par itération, l'une vers la droite et l'autre vers la gauche : une
 * seule direction pousserait tout l'arbre du même côté à chaque tour, et le
 * résultat dépendrait du nombre d'itérations plutôt que de la structure.
 */
function placer(
  couches: readonly NoeudGraphe[][],
  voisins: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
): Map<IdentifiantNoeud, number> {
  const x = new Map<IdentifiantNoeud, number>()

  for (const couche of couches) {
    let bord = 0

    for (const noeud of couche) {
      x.set(noeud.id, bord + noeud.w / 2)
      bord += noeud.w + ESPACE
    }
  }

  for (let passe = 0; passe < PASSES_DE_PLACEMENT; passe += 1) {
    for (const couche of couches) {
      for (const noeud of couche) {
        const positions = (voisins.get(noeud.id) ?? [])
          .map((id) => x.get(id))
          .filter((v): v is number => v !== undefined)

        if (positions.length === 0) continue

        x.set(noeud.id, positions.reduce((s, v) => s + v, 0) / positions.length)
      }

      separer(couche, x)
    }
  }

  return x
}

/**
 * Rétablit l'écart minimal dans une couche, **des deux côtés**.
 *
 * Vers la droite d'abord — c'est la règle du moteur, le camp de gauche ne bouge
 * pas —, puis vers la gauche, pour que la couche ne dérive pas indéfiniment vers
 * la droite au fil des passes.
 */
function separer(couche: readonly NoeudGraphe[], x: Map<IdentifiantNoeud, number>): void {
  for (let i = 1; i < couche.length; i += 1) {
    const gauche = couche[i - 1]!
    const droite = couche[i]!
    const minimum = x.get(gauche.id)! + gauche.w / 2 + ESPACE + droite.w / 2

    if (x.get(droite.id)! < minimum) x.set(droite.id, minimum)
  }

  for (let i = couche.length - 2; i >= 0; i -= 1) {
    const gauche = couche[i]!
    const droite = couche[i + 1]!
    const maximum = x.get(droite.id)! - droite.w / 2 - ESPACE - gauche.w / 2

    if (x.get(gauche.id)! > maximum) x.set(gauche.id, maximum)
  }
}

/** Le nombre de croisements d'un graphe, pour l'annoncer avant et après. */
export function compterLesCroisements(graphe: Graphe): number {
  return compterCroisements(enCouches(graphe.noeuds), voisinage(graphe))
}
