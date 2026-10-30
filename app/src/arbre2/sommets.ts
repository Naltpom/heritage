/**
 * Le sommet de chaque fiche de `/arbre` : parents connus, recherche close, ou pas encore
 * cherché (ADR 0023 § 4) — côté par côté, une fois par graphe.
 *
 * L'état se lit sur **toutes les arêtes du graphe reçu**, jamais sur les seules cartes
 * dessinées : un ancêtre que le filtre ou le cadrage cache reste un parent connu, et ne
 * laisse pas croire à un mur. Les nœuds et arêtes en attente comptent (un parent proposé
 * est dessiné au-dessus : la branche continue). Un parent lié l'emporte sur une fin de
 * recherche restée enregistrée (`etatDuSommet`, `@/lib/finDeRecherche`).
 *
 * Seuls les nœuds de personne **complets** peuvent être clos : la fin de recherche suit
 * la règle des notes, jamais servie sur un nœud `restreinte`. Un nœud restreint n'a pas
 * la clé ; il est en plus écarté ici, par prudence.
 *
 * Pur, sans DOM.
 */
import type { AreteGraphe, CoteDeRecherche, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
import {
  cotesConnusDansLeGraphe,
  cotesConnusDe,
  estEnFinDeRecherche,
  etatsDuSommet,
  infobulleDuSommet,
  type CotesConnus,
  type EtatDuSommet,
  type FinsDeRecherche,
} from '@/lib/finDeRecherche'

/** Ce que le rendu sait d'un sommet. */
export interface Sommet {
  /** L'état de chaque côté. */
  etats: Record<CoteDeRecherche, EtatDuSommet>
  /** Au moins un côté est clos : la carte porte le bord brique et le mur. */
  close: boolean
  /** Une ligne par côté clos (« Recherche close côté père : … »), `null` sinon. */
  infobulle: string | null
}

/** Le sommet de chaque personne **complète** du graphe, par identifiant de nœud. */
export type TableDesSommets = ReadonlyMap<IdentifiantNoeud, Sommet>

/** Aucun sommet : l'écran n'a pas encore de graphe. */
export const AUCUN_SOMMET: TableDesSommets = new Map()

/** Le sommet d'une personne dont on connaît les côtés liés et les fins de recherche. */
export function sommetDe(parents: CotesConnus, fins: FinsDeRecherche): Sommet {
  const etats = etatsDuSommet(parents, fins)
  return { etats, close: estEnFinDeRecherche(etats), infobulle: infobulleDuSommet(parents, fins) }
}

/**
 * La table des sommets d'un graphe : calculée **une fois par graphe** (un `computed` de
 * l'écran), pas par image. Une personne restreinte n'y figure pas.
 */
export function sommetsDuGraphe(noeuds: readonly NoeudGraphe[], aretes: readonly AreteGraphe[]): TableDesSommets {
  const parents = cotesConnusDansLeGraphe(noeuds, aretes)
  const table = new Map<IdentifiantNoeud, Sommet>()
  for (const n of noeuds) {
    if (n.type !== 'personne' || n.visibilite === 'restreinte') continue
    table.set(n.id, sommetDe(cotesConnusDe(parents, n.id), n))
  }
  return table
}
