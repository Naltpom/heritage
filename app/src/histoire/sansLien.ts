/**
 * **Les personnes sans lien connu** : celles qu'aucun chemin ne relie au lecteur.
 *
 * Un îlot détaché — une fiche ajoutée puis jamais rattachée, une famille saisie à
 * part en attendant le mariage qui la reliera — n'a pas de place dans l'arbre du
 * lecteur. La disposition le pose dans une zone à part, au-delà du bord droit
 * (`histoire/lignees.ts`, « sans lien connu · position estimée »).
 *
 * Décision du 2026-10-04 : cette zone est **masquée par défaut**, et montrée par la
 * bascule « Personnes sans lien connu » des filtres de `/arbre` — une préférence de ce
 * navigateur, retenue une fois allumée, comme les familles par alliance. Masquée, elle
 * est RETIRÉE avant la disposition (l'arbre se resserre), pas estompée.
 *
 * Le chemin se lit sur **tous** les liens du graphe ENTIER (union et filiation, dans
 * les deux sens) : une famille par alliance ou celle du conjoint sont reliées, elles
 * relèvent de leurs propres bascules et pas de celle-ci. Les nœuds de famille dont
 * tous les membres sont détachés partent avec eux.
 *
 * Ce n'est **pas** un filtre de confidentialité : le serveur décide de ce qui est
 * servi, ce module ne choisit que ce qui est dessiné.
 *
 * Pur, sans DOM : tout se vérifie dans `sansLien.test.ts`.
 */
import type { Graphe } from '@/lib/api'

const cache = new WeakMap<Graphe, Set<string>>()

/**
 * Les identifiants des nœuds (personnes et familles) qu'aucun chemin ne relie à
 * `graphe.ego`. Vide si l'ego n'est pas dans le graphe : « sans lien avec qui ? »
 * n'a pas de réponse, et on ne retire rien au hasard. Mémoïsé par graphe.
 */
export function noeudsSansLien(graphe: Graphe): Set<string> {
  const deja = cache.get(graphe)
  if (deja) return deja
  const detaches = new Set<string>()
  const ids = new Set(graphe.noeuds.map((n) => n.id))
  if (ids.has(graphe.ego)) {
    const voisins = new Map<string, string[]>()
    const relier = (a: string, b: string): void => {
      const l = voisins.get(a)
      if (l) l.push(b)
      else voisins.set(a, [b])
    }
    for (const a of graphe.aretes) {
      relier(a.de, a.vers)
      relier(a.vers, a.de)
    }
    const atteints = new Set([graphe.ego])
    const file = [graphe.ego]
    while (file.length) {
      for (const v of voisins.get(file.pop()!) ?? []) {
        if (atteints.has(v) || !ids.has(v)) continue
        atteints.add(v)
        file.push(v)
      }
    }
    for (const id of ids) if (!atteints.has(id)) detaches.add(id)
  }
  cache.set(graphe, detaches)
  return detaches
}

/** Le nombre de PERSONNES sans lien connu, pour le libellé de la bascule (0 : pas de bascule). */
export function effectifSansLien(graphe: Graphe): number {
  const detaches = noeudsSansLien(graphe)
  let n = 0
  for (const noeud of graphe.noeuds) if (noeud.type === 'personne' && detaches.has(noeud.id)) n++
  return n
}

/**
 * La personne `personId` est-elle sans lien connu ? C'est la question que pose
 * « Aller à une personne » : la recherche (par l'API) la trouve même quand la zone est
 * masquée, et la choisir doit allumer la bascule.
 */
export function estSansLien(graphe: Graphe, personId: number): boolean {
  const noeud = graphe.noeuds.find((n) => n.type === 'personne' && n.personId === personId)
  return noeud !== undefined && noeudsSansLien(graphe).has(noeud.id)
}

/**
 * `graphe` sans les nœuds de `retires` ni les liens qui les touchent. `retires` est
 * calculé sur le graphe ENTIER ({@link noeudsSansLien}) ; `graphe` peut être déjà
 * réduit par les autres bascules. Rien à retirer : le même objet, pour les mémoïsations.
 */
export function sansPersonnesSansLien(graphe: Graphe, retires: Set<string>): Graphe {
  if (!retires.size || !graphe.noeuds.some((n) => retires.has(n.id))) return graphe
  return {
    ...graphe,
    noeuds: graphe.noeuds.filter((n) => !retires.has(n.id)),
    aretes: graphe.aretes.filter((a) => !retires.has(a.de) && !retires.has(a.vers)),
  }
}
