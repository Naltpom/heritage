/**
 * **Masquer une branche** : les filtres « Branches » de `/arbre` RETIRENT les fiches
 * d'un quart avant la disposition, pour que l'arbre se refasse sans lui.
 *
 * Décision du 2026-10-07 : jusque-là, un quart masqué était seulement estompé, à sa
 * place ; il laissait un grand creux pâle au milieu de l'arbre. Masqué, il QUITTE
 * l'écran, comme la famille du conjoint ou les familles par alliance, et l'arbre se
 * resserre.
 *
 * Ce qui part :
 *  - les personnes rangées dans un quart masqué (sa branche servie) ;
 *  - les familles par alliance rattachées à ce quart — la branche de leur pont, en
 *    remontant si le pont est lui-même par alliance (`histoire/alliances.ts`) ;
 *  - les foyers qui n'ont plus aucun époux (le couple des grands-parents d'un quart :
 *    le parent du lecteur, du tronc, reste sans eux) ;
 *  - ce qui ne tenait au lecteur QUE par ce quart, pour ne rien laisser flotter.
 *
 * Ce qui reste, toujours : le lecteur et tout le tronc — ses parents, ses frères et
 * sœurs, ses descendants, son conjoint —, puisqu'ils ne sont d'aucun quart.
 *
 * Pur, sans DOM : tout se vérifie dans `branches.test.ts`.
 */
import type { Branche, Graphe, NoeudPersonne } from '@/lib/api'
import { classerParente } from './alliances'
import type { BrancheDeQuart } from './lignees'
import { noeudsSansLien } from './sansLien'

/**
 * La branche où une personne se range pour les filtres : la sienne, ou — pour une
 * famille par alliance — celle de qui la relie. Les classes se lisent sur `reference`,
 * le graphe ENTIER : c'est lui que le serveur a classé.
 */
export function brancheDeRattachement(reference: Graphe): (id: string) => Branche | null {
  const classes = classerParente(reference)
  const personnes = new Map<string, NoeudPersonne>()
  for (const n of reference.noeuds) if (n.type === 'personne') personnes.set(n.id, n)
  return (id) => {
    let courant = id
    for (let i = 0; i < 50; i++) {
      const c = classes.get(courant)
      if (c?.parente !== 'alliance' || c.pont === null) break
      courant = c.pont
    }
    return personnes.get(courant)?.branch ?? null
  }
}

/** Les quarts présents dans un graphe : ceux qu'un filtre peut masquer. */
export function quartsPresents(graphe: Graphe, reference: Graphe = graphe): Set<BrancheDeQuart> {
  const branche = brancheDeRattachement(reference)
  const presents = new Set<BrancheDeQuart>()
  for (const n of graphe.noeuds) {
    if (n.type !== 'personne' || n.id === graphe.ego) continue
    const b = branche(n.id)
    if (b !== null && b !== 'tr') presents.add(b)
  }
  return presents
}

/**
 * Le graphe sans les quarts masqués. `reference` est le graphe entier, pour la parenté
 * des familles par alliance ; le graphe rendu tel quel quand rien n'est masqué.
 */
export function sansBranches(graphe: Graphe, masquees: readonly BrancheDeQuart[], reference: Graphe = graphe): Graphe {
  if (!masquees.length) return graphe
  const masque = new Set<Branche>(masquees)
  const branche = brancheDeRattachement(reference)
  const retires = new Set<string>()
  for (const n of graphe.noeuds) {
    if (n.type !== 'personne' || n.id === graphe.ego) continue
    const b = branche(n.id)
    if (b !== null && masque.has(b)) retires.add(n.id)
  }
  if (!retires.size) return graphe

  // Les foyers sans plus aucun époux partent avec eux.
  const avecEpoux = new Set<string>()
  for (const a of graphe.aretes) {
    if (a.genre !== 'union') continue
    if (!retires.has(a.de)) avecEpoux.add(a.vers)
    if (!retires.has(a.vers)) avecEpoux.add(a.de)
  }
  for (const n of graphe.noeuds) if (n.type === 'famille' && !avecEpoux.has(n.id)) retires.add(n.id)

  const reduit: Graphe = {
    ...graphe,
    noeuds: graphe.noeuds.filter((n) => !retires.has(n.id)),
    aretes: graphe.aretes.filter((a) => !retires.has(a.de) && !retires.has(a.vers)),
  }
  // Ce qui tenait au lecteur avant, et plus maintenant, ne tenait que par le quart masqué.
  const detachesAvant = noeudsSansLien(graphe)
  const orphelins = [...noeudsSansLien(reduit)].filter((id) => !detachesAvant.has(id))
  if (!orphelins.length) return reduit
  const parti = new Set(orphelins)
  return {
    ...reduit,
    noeuds: reduit.noeuds.filter((n) => !parti.has(n.id)),
    aretes: reduit.aretes.filter((a) => !parti.has(a.de) && !parti.has(a.vers)),
  }
}
