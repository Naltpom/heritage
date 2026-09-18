/**
 * **« Ascendance seule »** — le seul arbre qui ne peut pas avoir de croisement.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE N'EST PAS UNE DISPOSITION, C'EST UN FILTRE.**
 *
 * Les dispositions cherchent à mieux placer un graphe difficile. Celle-ci ne
 * cherche rien : elle **retire ce qui rend le graphe difficile**, et ce qui reste
 * est un arbre au sens strict.
 *
 * Chacun a au plus deux parents. En ne gardant que le lecteur et ses ascendants,
 * on obtient donc un arbre binaire — et **un arbre binaire dessiné par niveaux ne
 * peut pas avoir de croisement**, c'est une propriété de sa forme, pas un résultat
 * d'algorithme. C'est la raison pour laquelle les éventails d'ascendance de tous
 * les logiciels de généalogie sont lisibles là où les vues complètes ne le sont
 * pas : ils ne montrent pas le même genre d'objet.
 *
 * Ce qui disparaît est exactement ce qui fabrique les croisements : les fratries,
 * les cousins, les descendants, et les conjoints qui ne sont pas des ancêtres. Un
 * couple d'ancêtres, lui, reste entier — les deux époux **sont** des ancêtres, et
 * leur losange avec.
 *
 * ── Pourquoi c'est une case à cocher, et non une URL de plus ────────────────
 *
 * Parce qu'elle **se combine** avec n'importe quelle disposition : on peut vouloir
 * l'ascendance seule bornée à trois générations, ou l'ascendance seule avec les
 * conjoints accolés — même si dans ce dernier cas il n'y aura rien à accoler,
 * puisqu'un conjoint rapporté n'est l'ancêtre de personne. Une URL par
 * combinaison en ferait une dizaine.
 * ────────────────────────────────────────────────────────────────────
 */

import type { Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
import { boiteEnglobante, epouxParFamille } from './socle'

export function ascendanceSeule(graphe: Graphe): Graphe {
  const gardes = ascendantsDe(graphe)

  if (gardes.size === 0) return graphe

  const epoux = epouxParFamille(graphe.aretes)
  const familles = new Set<IdentifiantNoeud>()

  for (const [famille, membres] of epoux) {
    // Un losange n'est gardé que si **tous** ses époux le sont. Un couple dont un
    // seul membre est ancêtre n'a pas sa place : garder le losange y ferait
    // pendre un trait vers quelqu'un qu'on ne dessine plus.
    if (membres.length > 0 && membres.every((id) => gardes.has(id))) familles.add(famille)
  }

  const noeuds = graphe.noeuds.filter(
    (noeud) => (noeud.type === 'personne' ? gardes.has(noeud.id) : familles.has(noeud.id)),
  )

  const presents = new Set(noeuds.map((noeud) => noeud.id))
  const aretes = graphe.aretes.filter(
    (arete) => presents.has(arete.de) && presents.has(arete.vers),
  )

  return { ...graphe, noeuds, aretes, boite: boiteEnglobante(noeuds, graphe.boite) }
}

/**
 * Le lecteur et tout ce qui remonte de lui.
 *
 * On monte par les **filiations** : de la personne vers la famille dont elle est
 * l'enfant, puis vers les époux de cette famille. Le parcours ne redescend
 * jamais, ce qui est toute la définition — et ce qui garantit qu'aucun nœud gardé
 * n'a plus de deux parents.
 */
function ascendantsDe(graphe: Graphe): Set<IdentifiantNoeud> {
  if (!graphe.noeuds.some((noeud: NoeudGraphe) => noeud.id === graphe.ego)) return new Set()

  const familleDesParents = new Map<IdentifiantNoeud, IdentifiantNoeud>()

  for (const arete of graphe.aretes) {
    if (arete.genre === 'filiation') familleDesParents.set(arete.vers, arete.de)
  }

  const epoux = epouxParFamille(graphe.aretes)
  const gardes = new Set<IdentifiantNoeud>([graphe.ego])
  const file: IdentifiantNoeud[] = [graphe.ego]

  while (file.length > 0) {
    const courant = file.shift()
    if (courant === undefined) continue

    const famille = familleDesParents.get(courant)
    if (famille === undefined) continue

    for (const parent of epoux.get(famille) ?? []) {
      // L'implexe — le même ancêtre atteint par deux chemins — est fréquent sur
      // les vieilles familles. Le `Set` le règle : on ne le visite qu'une fois, et
      // il n'apparaît qu'une fois dans le rendu.
      if (!gardes.has(parent)) {
        gardes.add(parent)
        file.push(parent)
      }
    }
  }

  return gardes
}
