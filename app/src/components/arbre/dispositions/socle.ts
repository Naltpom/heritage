/**
 * Le socle des **dispositions** : des transformations pures de la charge du graphe.
 *
 * ────────────────────────────────────────────────────────────────────
 * **POURQUOI CÔTÉ CLIENT, ET PAS DANS LE MOTEUR.**
 *
 * Le moteur de placement du serveur est un **portage fidèle** de `graphe.py`, et
 * sa preuve est un fichier de référence qui vit hors dépôt — la sortie du script
 * d'origine sur l'arbre réel. Le test qui la tient *saute* en intégration
 * continue et ne tourne que sur la machine du commanditaire. Toucher au moteur
 * détruirait donc cette preuve sans qu'on puisse la reconstruire, sauf à
 * modifier le script d'origine.
 *
 * Tant qu'aucune disposition n'est choisie, on n'y touche pas. Chacune retravaille
 * la **même charge** `GET /api/arbre/graphe`, dans le navigateur : rien côté
 * serveur, rien dans le cache, rien dans le moteur. Deux conséquences qui valent
 * qu'on les dise :
 *
 * - **elles sont comparables** — exactement les mêmes données, la même charge,
 *   le même rendu ; seules les positions diffèrent ;
 * - **elles se jettent sans rien laisser derrière.** Une disposition qu'on
 *   abandonne est un fichier supprimé, pas une migration à défaire.
 *
 * **Ce sont des fonctions pures, donc éprouvables sans navigateur.** Aucune ne
 * touche au DOM, aucune ne lit l'horloge : elles prennent un `Graphe` et en
 * rendent un autre. C'est ce qui permet de les tester sur des cas construits à la
 * main, et de mesurer leur effet — combien de couples séparés avant, combien
 * après — plutôt que de juger à l'œil.
 * ────────────────────────────────────────────────────────────────────
 */

import type { AreteGraphe, Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'

/**
 * L'écart horizontal minimal entre deux fiches d'une même rangée.
 *
 * C'est `MoteurDePlacement::ESPACE`, recopié. **Recopié et non deviné** : une
 * disposition qui replace des nœuds doit rendre les mêmes écarts que le moteur,
 * sinon la variante se distingue de l'originale par son espacement autant que par
 * ce qu'on voulait éprouver — et la comparaison ne dit plus rien.
 */
export const ESPACE = 20

/** `MoteurDePlacement::MARGE_BOITE`, recopiée pour la même raison qu'`ESPACE`. */
export const MARGE_BOITE = 60

/** Une disposition : la même charge, autrement placée. */
export type Disposition = (graphe: Graphe) => Graphe

export const DISPOSITIONS = ['conjoints', 'bornee', 'croisements', 'duplication'] as const

export type NomDeDisposition = (typeof DISPOSITIONS)[number]

/* ------------------------------------------------------------------ */
/* Lectures de topologie, communes à toutes les dispositions           */
/* ------------------------------------------------------------------ */

/**
 * Les deux époux de chaque losange, dans l'ordre des arêtes.
 *
 * Un losange peut n'en porter qu'un — un parent seul —, et c'est licite : le
 * contrat ne promet pas deux époux, il promet un nœud de jonction. Les
 * dispositions qui ont besoin d'un couple filtrent sur la longueur.
 */
export function epouxParFamille(aretes: readonly AreteGraphe[]): Map<IdentifiantNoeud, IdentifiantNoeud[]> {
  const parFamille = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()

  for (const arete of aretes) {
    if (arete.genre !== 'union') continue
    const liste = parFamille.get(arete.vers)
    if (liste === undefined) parFamille.set(arete.vers, [arete.de])
    else liste.push(arete.de)
  }

  return parFamille
}

/**
 * Qui a des parents **dans cet arbre**.
 *
 * C'est la question qui décide si une personne peut être déplacée sans dommage :
 * sans parents, elle n'a aucun trait qui monte, donc aucune fratrie à quitter.
 * « Rapportée » au sens généalogique — une pièce rapportée par alliance.
 *
 * On la lit dans les **arêtes** et non dans une liste de parents : les arêtes
 * sont ce que le rendu dessine, et c'est précisément le trait qu'on cherche à ne
 * pas allonger.
 */
export function personnesAvecParents(aretes: readonly AreteGraphe[]): Set<IdentifiantNoeud> {
  const avec = new Set<IdentifiantNoeud>()

  for (const arete of aretes) {
    if (arete.genre === 'filiation') avec.add(arete.vers)
  }

  return avec
}

/**
 * Les personnes groupées par rangée, chacune triée de gauche à droite.
 *
 * Les losanges sont **exclus** : ils vivent 26 px sous la rangée de leurs époux,
 * donc sur une ordonnée à eux, et le moteur ne les décroche jamais. Les mêler aux
 * fiches ferait des rangées d'un seul losange.
 */
export function rangeesDePersonnes(noeuds: readonly NoeudGraphe[]): Map<number, NoeudGraphe[]> {
  const rangees = new Map<number, NoeudGraphe[]>()

  for (const noeud of noeuds) {
    if (noeud.type !== 'personne') continue
    const rangee = rangees.get(noeud.y)
    if (rangee === undefined) rangees.set(noeud.y, [noeud])
    else rangee.push(noeud)
  }

  for (const rangee of rangees.values()) {
    rangee.sort((a, b) => a.x - b.x || (a.id < b.id ? -1 : 1))
  }

  return rangees
}

/* ------------------------------------------------------------------ */
/* Mesure : ce qu'une disposition améliore, en nombres                 */
/* ------------------------------------------------------------------ */

export interface MesureDeLisibilite {
  /** Couples dont les deux époux sont sur la même rangée. */
  couples: number
  /** Ceux dont les deux époux sont voisins immédiats. */
  accoles: number
  /** Ceux qui ne le sont pas, et combien de personnes s'intercalent en tout. */
  separes: number
  intercales: number
}

/**
 * **La mesure vient avant le jugement à l'œil.**
 *
 * Une disposition se compare à une autre par des nombres, pas par une impression :
 * on peut très bien rendre un arbre plus joli localement en dégradant le reste, et
 * l'œil ne le voit pas sur 377 nœuds. `intercales` compte les personnes assises
 * entre deux époux, ce qui distingue « séparés d'un rang » de « séparés de dix-neuf ».
 */
export function mesurerLisibilite(graphe: Graphe): MesureDeLisibilite {
  const epoux = epouxParFamille(graphe.aretes)
  const rangees = rangeesDePersonnes(graphe.noeuds)
  const rangeeDe = new Map<IdentifiantNoeud, { rangee: NoeudGraphe[]; index: number }>()

  for (const rangee of rangees.values()) {
    rangee.forEach((noeud, index) => rangeeDe.set(noeud.id, { rangee, index }))
  }

  let couples = 0
  let accoles = 0
  let separes = 0
  let intercales = 0

  for (const membres of epoux.values()) {
    if (membres.length !== 2) continue

    const a = rangeeDe.get(membres[0]!)
    const b = rangeeDe.get(membres[1]!)

    if (a === undefined || b === undefined || a.rangee !== b.rangee) continue

    couples += 1
    const entre = Math.abs(a.index - b.index) - 1

    if (entre === 0) accoles += 1
    else {
      separes += 1
      intercales += entre
    }
  }

  return { couples, accoles, separes, intercales }
}

/* ------------------------------------------------------------------ */
/* Replacement                                                          */
/* ------------------------------------------------------------------ */

/**
 * Écarte les chevauchements d'une rangée **par poussée vers la droite**, sans
 * jamais réordonner.
 *
 * C'est `CalculDePlacement::decrocheLesChevauchements()`, recopié terme pour
 * terme. Le réordonnancement, quand une disposition en fait un, a déjà eu lieu :
 * cette passe ne fait que rendre les positions cohérentes avec l'ordre qu'on lui
 * donne. Les mêler produirait une disposition dont on ne saurait plus dire ce
 * qu'elle a décidé.
 */
export function ecarterVersLaDroite(rangee: readonly NoeudGraphe[]): Map<IdentifiantNoeud, number> {
  const x = new Map<IdentifiantNoeud, number>()

  rangee.forEach((noeud, index) => {
    if (index === 0) {
      x.set(noeud.id, noeud.x)
      return
    }

    const gauche = rangee[index - 1]!
    const minimum = (x.get(gauche.id) ?? gauche.x) + gauche.w / 2 + ESPACE + noeud.w / 2

    x.set(noeud.id, Math.max(noeud.x, minimum))
  })

  return x
}

/**
 * Applique de nouvelles abscisses, replace les losanges touchés au barycentre de
 * leur couple, et **recalcule la boîte englobante**.
 *
 * La boîte alimente le `viewBox` initial du canevas. La laisser telle quelle après
 * avoir déplacé des nœuds ouvrirait la vue sur un cadre qui ne contient plus tout
 * l'arbre — et le défaut ne se verrait qu'au bord, c'est-à-dire tard.
 *
 * @param abscisses les nouvelles abscisses, par identifiant ; les nœuds absents
 *                  gardent la leur
 */
export function grapheReplace(graphe: Graphe, abscisses: Map<IdentifiantNoeud, number>): Graphe {
  const noeuds = graphe.noeuds.map((noeud) => {
    const x = abscisses.get(noeud.id)
    return x === undefined || x === noeud.x ? noeud : { ...noeud, x }
  })

  const parId = new Map(noeuds.map((noeud) => [noeud.id, noeud]))
  const epoux = epouxParFamille(graphe.aretes)

  const recales = noeuds.map((noeud) => {
    if (noeud.type !== 'famille') return noeud

    const membres = (epoux.get(noeud.id) ?? [])
      .map((id) => parId.get(id))
      .filter((n): n is NoeudGraphe => n !== undefined)

    if (membres.length === 0) return noeud

    // Le barycentre du couple, comme le moteur : un losange qui ne suit pas ses
    // époux pointe à côté du couple qu'il joint, et l'arête part de travers.
    const x = membres.reduce((somme, membre) => somme + membre.x, 0) / membres.length

    return x === noeud.x ? noeud : { ...noeud, x }
  })

  return { ...graphe, noeuds: recales, boite: boiteEnglobante(recales, graphe.boite) }
}

/**
 * La boîte qui contient tous les nœuds, avec la marge du moteur.
 *
 * Elle alimente le `viewBox` initial. La laisser telle quelle après avoir déplacé
 * des nœuds ouvrirait la vue sur un cadre qui ne contient plus tout l'arbre — et le
 * défaut ne se verrait qu'au bord, c'est-à-dire tard.
 */
export function boiteEnglobante(noeuds: readonly NoeudGraphe[], origine: Graphe['boite']): Graphe['boite'] {
  if (noeuds.length === 0) return origine

  let xMin = Number.POSITIVE_INFINITY
  let xMax = Number.NEGATIVE_INFINITY
  let yMin = Number.POSITIVE_INFINITY
  let yMax = Number.NEGATIVE_INFINITY

  for (const noeud of noeuds) {
    xMin = Math.min(xMin, noeud.x - noeud.w / 2)
    xMax = Math.max(xMax, noeud.x + noeud.w / 2)
    yMin = Math.min(yMin, noeud.y - noeud.h / 2)
    yMax = Math.max(yMax, noeud.y + noeud.h / 2)
  }

  return {
    x: xMin - MARGE_BOITE,
    y: yMin - MARGE_BOITE,
    w: xMax - xMin + 2 * MARGE_BOITE,
    h: yMax - yMin + 2 * MARGE_BOITE,
  }
}
