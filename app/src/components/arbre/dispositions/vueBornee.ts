/**
 * **Disposition « vue bornée »** — `/arbre-3`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **C'EST LA RÉPONSE DU MÉTIER, ET ELLE NE CORRIGE RIEN.**
 *
 * MyHeritage, Ancestry, Geni, FamilySearch, webtrees : aucun ne dessine le graphe
 * entier. Ils dessinent une **fenêtre** — une personne au centre, quelques
 * générations au-dessus, quelques-unes en dessous — et recentrent au clic. Les
 * croisements ne sont pas résolus : ils sortent du cadre.
 *
 * C'est moins une reculade qu'il n'y paraît. Un arbre de 377 nœuds sur une seule
 * toile n'est pas lisible, quelle que soit la qualité du placement : à cette
 * largeur on ne voit plus les étiquettes, et à cette échelle on ne voit plus la
 * structure. Borner rend l'écran regardable **sur téléphone**, ce qu'aucune
 * amélioration de mise en page ne fera.
 *
 * ── Ce qu'elle fait exactement ──────────────────────────────────────────────
 *
 * Elle **ne déplace rien**. Elle retire des nœuds et recalcule le cadre. C'est ce
 * qui la rend directement comparable aux autres : mis côte à côte avec `/arbre`,
 * ce qui reste est **au même endroit**.
 *
 * Le parcours part du lecteur et dépense deux budgets distincts :
 *
 * - monter vers ses parents coûte **une génération de haut** ;
 * - descendre vers ses enfants coûte **une génération de bas** ;
 * - rejoindre un conjoint ne coûte **rien** — il est sur la même rangée, et une
 *   fenêtre qui montre quelqu'un sans son conjoint ne montre pas un couple.
 *
 * Les deux budgets sont indépendants, ce qui donne exactement ce qu'on attend :
 * à `haut=2, bas=2`, on voit ses grands-parents (2,0), ses frères et sœurs (1,1),
 * ses oncles (2,1), ses cousins (2,2) et ses petits-enfants (0,2).
 *
 * ── Le piège qu'elle évite ──────────────────────────────────────────────────
 *
 * Borner par la **génération seule** — « tout le monde entre la rangée −3 et la
 * rangée +2 » — garderait des bandes entières de parfaits inconnus : l'arbre
 * resterait aussi large, en perdant sa profondeur. La largeur est le problème ;
 * c'est donc la distance au lecteur qu'il faut borner, pas l'ordonnée.
 * ────────────────────────────────────────────────────────────────────
 */

import type { AreteGraphe, Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
import { boiteEnglobante, epouxParFamille, type OptionsDeDisposition } from './socle'

export function vueBornee(graphe: Graphe, options: OptionsDeDisposition): Graphe {
  const gardees = personnesAtteintes(graphe, options)

  if (gardees.size === 0) return graphe

  const noeuds = graphe.noeuds.filter((noeud) =>
    noeud.type === 'personne' ? gardees.has(noeud.id) : false,
  )

  const epoux = epouxParFamille(graphe.aretes)

  // Un losange ne survit que s'il joint quelqu'un qu'on garde. Le garder sans
  // aucun époux visible dessinerait un nœud de jonction qui ne joint rien.
  const famillesGardees = new Set<IdentifiantNoeud>()

  for (const noeud of graphe.noeuds) {
    if (noeud.type !== 'famille') continue

    const membres = epoux.get(noeud.id) ?? []
    if (membres.some((id) => gardees.has(id))) famillesGardees.add(noeud.id)
  }

  const tous = [...noeuds, ...graphe.noeuds.filter((n) => famillesGardees.has(n.id))]
  const presents = new Set(tous.map((noeud) => noeud.id))

  // Une arête dont une extrémité est partie serait un trait qui sort de l'écran
  // vers rien. On les retire toutes les deux ou aucune.
  const aretes = graphe.aretes.filter(
    (arete) => presents.has(arete.de) && presents.has(arete.vers),
  )

  return { ...graphe, noeuds: tous, aretes, boite: boiteEnglobante(tous, graphe.boite) }
}

/**
 * Le parcours en largeur, avec ses deux budgets.
 *
 * L'état visité est `(personne, haut restant, bas restant)` et non `personne`
 * seule : quelqu'un atteint par un chemin coûteux peut l'être plus tard par un
 * chemin économe, qui ouvre alors plus loin. Ne retenir que la première visite
 * amputerait des branches accessibles — et le défaut ne se verrait qu'aux bords,
 * c'est-à-dire tard.
 */
function personnesAtteintes(graphe: Graphe, options: OptionsDeDisposition): Set<IdentifiantNoeud> {
  const depart = graphe.ego

  if (!graphe.noeuds.some((noeud) => noeud.id === depart)) return new Set()

  const familleDesParents = new Map<IdentifiantNoeud, IdentifiantNoeud>()
  const enfantsDe = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()
  const famillesOuIlEstEpoux = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()

  indexer(graphe.aretes, familleDesParents, enfantsDe, famillesOuIlEstEpoux)

  const epoux = epouxParFamille(graphe.aretes)
  const gardees = new Set<IdentifiantNoeud>()
  const meilleur = new Map<IdentifiantNoeud, string>()

  const file: { id: IdentifiantNoeud; haut: number; bas: number }[] = [
    { id: depart, haut: options.haut, bas: options.bas },
  ]

  while (file.length > 0) {
    const courant = file.shift()
    if (courant === undefined) continue

    // Un budget déjà vu, meilleur ou égal sur les deux axes, n'apprend rien.
    const cle = `${courant.haut}/${courant.bas}`
    const vu = meilleur.get(courant.id)
    if (vu !== undefined && auMoinsAussiBon(vu, cle)) continue
    meilleur.set(courant.id, cle)

    gardees.add(courant.id)

    // Le conjoint : gratuit, et sur la même rangée.
    for (const famille of famillesOuIlEstEpoux.get(courant.id) ?? []) {
      for (const conjoint of epoux.get(famille) ?? []) {
        if (conjoint !== courant.id) file.push({ id: conjoint, haut: courant.haut, bas: courant.bas })
      }

      // Les enfants de cette union : une génération de bas.
      if (courant.bas > 0) {
        for (const enfant of enfantsDe.get(famille) ?? []) {
          file.push({ id: enfant, haut: courant.haut, bas: courant.bas - 1 })
        }
      }
    }

    // Les parents : une génération de haut.
    const familleParentale = familleDesParents.get(courant.id)

    if (familleParentale !== undefined && courant.haut > 0) {
      for (const parent of epoux.get(familleParentale) ?? []) {
        file.push({ id: parent, haut: courant.haut - 1, bas: courant.bas })
      }
    }
  }

  return gardees
}

function auMoinsAussiBon(vu: string, candidat: string): boolean {
  const [hv, bv] = vu.split('/').map(Number) as [number, number]
  const [hc, bc] = candidat.split('/').map(Number) as [number, number]

  return hv >= hc && bv >= bc
}

function indexer(
  aretes: readonly AreteGraphe[],
  familleDesParents: Map<IdentifiantNoeud, IdentifiantNoeud>,
  enfantsDe: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
  famillesOuIlEstEpoux: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
): void {
  for (const arete of aretes) {
    if (arete.genre === 'filiation') {
      familleDesParents.set(arete.vers, arete.de)
      pousser(enfantsDe, arete.de, arete.vers)
    }

    if (arete.genre === 'union') pousser(famillesOuIlEstEpoux, arete.de, arete.vers)
  }
}

function pousser(
  carte: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
  cle: IdentifiantNoeud,
  valeur: IdentifiantNoeud,
): void {
  const liste = carte.get(cle)
  if (liste === undefined) carte.set(cle, [valeur])
  else liste.push(valeur)
}

/** Le nombre de personnes qu'une vue bornée garde, pour l'annoncer avant/après. */
export function compterPersonnes(graphe: Graphe): number {
  return graphe.noeuds.filter((noeud: NoeudGraphe) => noeud.type === 'personne').length
}
