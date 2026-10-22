/**
 * **Le calque « en attente »** : les personnes proposées et les liens à valider, que le
 * serveur ne sert qu'à l'auteur des demandes et aux administrateurs (§ 13 du contrat
 * d'API). Une personne proposée a un identifiant provisoire (`#12/p1`), un losange
 * provisoire aussi (`#12/f1`) ; tous deux portent `enAttente: true`, comme toute arête
 * qui en touche un (`NormaliseurDeGraphe::aretes`).
 *
 * Demande du 22/10/2026 : « En attente de validation », une bascule des filtres de
 * `/arbre` et de `/arbre-review`, ACTIVÉE par défaut — le lecteur y voit ses demandes en
 * attente, en pointillé (`arbre2/apparence.ts`). Décochée, l'arbre ne montre que les
 * données validées. Comme les autres bascules de « Qui apparaît », le calque est RETIRÉ
 * avant la disposition, pas estompé : l'arbre se resserre.
 *
 * Ce qui part : les nœuds marqués `enAttente`, les arêtes marquées `enAttente`, toute
 * arête qui touchait un nœud parti (aucun trait ne pend dans le vide), et un losange
 * que ce retrait laisse sans aucun lien. Une personne VALIDÉE ne part jamais, même
 * quand une demande seule la reliait à l'arbre : elle est alors « sans lien connu »,
 * ce qu'elle est dans l'arbre validé — d'où l'ordre de `disposer()`, qui retire le
 * calque AVANT de chercher les îlots, les alliances et les branches.
 *
 * Ce n'est pas un filtre de confidentialité : le serveur décide de ce qui est servi
 * (§ 13.3), ce module ne choisit que ce qui est dessiné.
 *
 * Pur, sans DOM : tout se vérifie dans `enAttente.test.ts`.
 */
import type { CalqueDuGraphe, Graphe } from '@/lib/api'

/** Le calque d'un graphe qui n'en montre rien : les compteurs à zéro, présents (§ 13). */
const CALQUE_VIDE: CalqueDuGraphe = { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] }

const cache = new WeakMap<Graphe, Graphe>()

/**
 * `graphe` sans son calque « en attente ». Rien en attente : le même objet, pour les
 * mémoïsations en aval (`noeudsSansLien`, `classerParente`…). Mémoïsé par graphe : le
 * même graphe rend le même objet filtré.
 */
export function sansDemandesEnAttente(graphe: Graphe): Graphe {
  const deja = cache.get(graphe)
  if (deja) return deja
  const partis = new Set<string>()
  for (const n of graphe.noeuds) if (n.enAttente === true) partis.add(n.id)
  let resultat = graphe
  if (partis.size || graphe.aretes.some((a) => a.enAttente === true)) {
    const aretes = graphe.aretes.filter((a) => a.enAttente !== true && !partis.has(a.de) && !partis.has(a.vers))
    // Un losange validé que seules des demandes reliaient n'a plus rien à unir.
    const reliesAvant = new Set<string>()
    for (const a of graphe.aretes) reliesAvant.add(a.de).add(a.vers)
    const reliesApres = new Set<string>()
    for (const a of aretes) reliesApres.add(a.de).add(a.vers)
    const noeuds = graphe.noeuds.filter(
      (n) => !partis.has(n.id) && (n.type !== 'famille' || !reliesAvant.has(n.id) || reliesApres.has(n.id)),
    )
    resultat = { ...graphe, calque: CALQUE_VIDE, noeuds, aretes }
  }
  cache.set(graphe, resultat)
  return resultat
}

/** Ce que le calque dessine : de quoi dire la bascule (rien : pas de bascule). */
export interface EffectifEnAttente {
  /** Les personnes proposées, à l'écran quand la bascule est allumée. */
  personnes: number
  /** Les liens à valider, ceux des personnes proposées compris. */
  liens: number
}

/** Les personnes proposées et les liens à valider que le graphe porte. */
export function effectifEnAttente(graphe: Graphe): EffectifEnAttente {
  let personnes = 0
  for (const n of graphe.noeuds) if (n.type === 'personne' && n.enAttente === true) personnes++
  let liens = 0
  for (const a of graphe.aretes) if (a.enAttente === true) liens++
  return { personnes, liens }
}
