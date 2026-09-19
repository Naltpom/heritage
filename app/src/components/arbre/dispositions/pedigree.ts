/**
 * **L'éventail d'ascendance** — la mise en page qu'on attend quand on coche
 * « ascendance seule », et que le filtre seul ne donne pas.
 *
 * ────────────────────────────────────────────────────────────────────
 * Retirer tout ce qui n'est pas un ancêtre laisse un arbre binaire ; mais un
 * arbre binaire dessiné **aux places de l'arbre complet** reste éparpillé : chaque
 * ancêtre garde l'abscisse que lui avaient value ses frères, ses cousins et sa
 * belle-famille — qui ne sont plus là. Il faut donc le replacer, et pour un
 * arbre binaire il n'y a rien à optimiser : la place de chacun se déduit de sa
 * position dans l'arbre, comme sur tous les éventails de généalogie.
 *
 * Chaque ancêtre reçoit une **génération** `g` et un **créneau** `s` :
 * le lecteur est en `(0, 0)` ; le père d'un ancêtre en `(g, s)` est en
 * `(g+1, 2s)`, sa mère en `(g+1, 2s+1)`. À la génération la plus haute `G`, les
 * créneaux sont contigus ; en dessous, chaque génération a deux fois moins de
 * créneaux, donc un pas deux fois plus large — et chaque couple se retrouve
 * **exactement au-dessus de son enfant**, sans qu'aucune règle ne le demande.
 *
 * Le losange d'un couple se pose au milieu de ses deux époux ; les ordonnées
 * ne bougent pas — la génération est une donnée, pas un choix de dessin.
 *
 * ── L'implexe ────────────────────────────────────────────────────────
 *
 * Le même ancêtre atteint par deux chemins (deux cousins qui se sont épousés)
 * n'existe qu'une fois dans le graphe : il garde le premier créneau rencontré,
 * et le second trait est simplement plus long. C'est rare, et un doublon
 * dessiné serait un mensonge sur le nombre d'ancêtres.
 * ────────────────────────────────────────────────────────────────────
 */

import type { AreteGraphe, Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
import { ESPACE, epouxParFamille, grapheReplace } from './socle'

interface Creneau {
  generation: number
  rang: number
}

export function pedigree(graphe: Graphe): Graphe {
  const parId = new Map<IdentifiantNoeud, NoeudGraphe>()
  for (const noeud of graphe.noeuds) parId.set(noeud.id, noeud)
  if (!parId.has(graphe.ego)) return graphe

  const familleDesParents = new Map<IdentifiantNoeud, IdentifiantNoeud>()
  for (const arete of graphe.aretes) {
    if (arete.genre === 'filiation') familleDesParents.set(arete.vers, arete.de)
  }
  const epoux = epouxParFamille(graphe.aretes)

  const creneaux = new Map<IdentifiantNoeud, Creneau>()
  creneaux.set(graphe.ego, { generation: 0, rang: 0 })
  const file: IdentifiantNoeud[] = [graphe.ego]
  let plusHaute = 0

  while (file.length > 0) {
    const courant = file.shift()
    if (courant === undefined) continue
    const place = creneaux.get(courant)
    const famille = place === undefined ? undefined : familleDesParents.get(courant)
    if (place === undefined || famille === undefined) continue

    const parents = pereDAbord(epoux.get(famille) ?? [], parId)
    parents.forEach((parent, cote) => {
      if (creneaux.has(parent)) return
      const generation = place.generation + 1
      creneaux.set(parent, { generation, rang: 2 * place.rang + cote })
      plusHaute = Math.max(plusHaute, generation)
      file.push(parent)
    })
  }

  // Le pas de base : la fiche la plus large parmi les ancêtres, plus l'espace
  // réglementaire. À la génération la plus haute, deux créneaux voisins sont
  // donc exactement à cette distance — le rang est plein, sans être serré.
  let largeurMax = 0
  for (const id of creneaux.keys()) {
    largeurMax = Math.max(largeurMax, parId.get(id)?.w ?? 0)
  }
  const pas = largeurMax + ESPACE

  const abscisses = new Map<IdentifiantNoeud, number>()
  for (const [id, { generation, rang }] of creneaux) {
    const largeur = pas * 2 ** (plusHaute - generation)
    const centre = (2 ** generation - 1) / 2
    abscisses.set(id, (rang - centre) * largeur)
  }

  // Les losanges : au milieu de leurs époux, une fois ceux-ci posés.
  for (const [famille, membres] of epoux) {
    const xs = membres.map((id) => abscisses.get(id)).filter((x): x is number => x !== undefined)
    if (xs.length > 0 && parId.has(famille)) {
      abscisses.set(famille, (Math.min(...xs) + Math.max(...xs)) / 2)
    }
  }

  return grapheReplace(graphe, abscisses)
}

/**
 * Le père à gauche, la mère à droite — la convention de tout l'arbre (I6). À
 * sexe inconnu ou identique, l'ordre des arêtes, qui est celui du GEDCOM.
 */
function pereDAbord(
  membres: readonly IdentifiantNoeud[],
  parId: ReadonlyMap<IdentifiantNoeud, NoeudGraphe>,
): IdentifiantNoeud[] {
  if (membres.length !== 2) return [...membres]
  const [a, b] = membres
  const sexeDe = (id: IdentifiantNoeud): string => {
    const noeud = parId.get(id)
    return noeud !== undefined && noeud.type === 'personne' ? noeud.sex : 'inconnu'
  }
  return sexeDe(a) === 'feminin' && sexeDe(b) === 'masculin' ? [b, a] : [a, b]
}

/** Exposé pour le test : ce que le filtre laisse doit être un arbre — deux parents au plus. */
export function nombreDeParentsMax(aretes: readonly AreteGraphe[]): number {
  const epoux = epouxParFamille(aretes)
  let max = 0
  for (const arete of aretes) {
    if (arete.genre === 'filiation') max = Math.max(max, (epoux.get(arete.de) ?? []).length)
  }
  return max
}
