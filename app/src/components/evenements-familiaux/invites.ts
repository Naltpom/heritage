/**
 * Le choix des invités, sans navigateur : qui peut être invité, la recherche
 * par nom, les raccourcis par branche, le compteur. Suite : `invites.test.ts`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Les invitables se lisent dans le graphe, et seulement ses fiches complètes.**
 *
 * « Tous les vivants » demande la liste entière, ce que la recherche ne rend
 * pas — elle exige deux caractères et plafonne ses résultats, à dessein : une
 * recherche qui se pagine est une énumération déguisée. Le graphe, lui, est la
 * charge que l'arbre sert déjà à ce lecteur, en entier.
 *
 * Mais `SelecteurPersonne` rappelle pourquoi on ne bâtit pas d'index sur le
 * graphe : une personne vivante hors du cercle y figure, en nœud **restreint**.
 * On ne garde donc que les nœuds `visibilite: 'complete'` — ceux dont le
 * lecteur lit déjà le nom en clair dans l'arbre. Un nœud restreint n'est pas
 * proposé « masqué » : il est **absent**, et rien ne dit qu'il existe. C'est la
 * même règle que le serveur applique à l'invitation (le cercle du lecteur) : ce
 * que l'écran propose, l'API l'accepte.
 *
 * Et seulement les **vivants** (`vivant`, la règle unique du produit) : on
 * n'invite pas un aïeul. Les fiches en attente (`enAttente`, sans `personId`)
 * n'existent pas encore : elles ne sont pas invitables.
 * ────────────────────────────────────────────────────────────────────
 */

import { requeteApi, type Graphe, type NoeudPersonne } from '@/lib/api'
import { nomBranche } from '@/components/personne/champs'

/**
 * Charge le graphe du lecteur et en tire les invitables. Un appel simple, sans
 * la revalidation par `ETag` de `chargerGraphe()` : une réponse `304` ne
 * porterait rien, et cet écran ne garde pas le graphe entre deux visites —
 * c'est le geste de `HistoireView`.
 */
export async function chargerLesInvitables(signal?: AbortSignal): Promise<Invitable[]> {
  const graphe = await requeteApi<Graphe>('/arbre/graphe', signal === undefined ? {} : { signal })
  return invitablesDuGraphe(graphe)
}

export interface Invitable {
  personId: number
  libelle: string
  /** La branche du lecteur (`pp`, `tr`, `cmm`…) ou `alliance` — pour les raccourcis. */
  groupe: string
}

/** L'ordre des raccourcis : les quatre quarts, le tronc, ceux du conjoint, puis les alliances. */
const ORDRE_DES_GROUPES = ['pp', 'pm', 'mp', 'mm', 'tr', 'cpp', 'cpm', 'cmp', 'cmm', 'alliance']

/**
 * Les invitables d'un graphe, triés par nom.
 *
 * Une personne entrée par **alliance** (la famille du mari d'une tante) n'est
 * rangée dans aucune branche du lecteur — le front ne lit jamais sa `branch`
 * (révision du 2026-10-01) : elle forme son propre groupe.
 */
export function invitablesDuGraphe(graphe: Pick<Graphe, 'noeuds'>): Invitable[] {
  const vus = new Set<number>()
  const invitables: Invitable[] = []

  for (const noeud of graphe.noeuds) {
    if (noeud.type !== 'personne') continue
    const p: NoeudPersonne = noeud
    if (p.personId === null || p.enAttente === true || !p.vivant || p.visibilite !== 'complete') continue
    if (vus.has(p.personId)) continue
    vus.add(p.personId)
    invitables.push({
      personId: p.personId,
      libelle: p.displayName,
      groupe: p.parente === 'alliance' ? 'alliance' : p.branch,
    })
  }

  return invitables.sort((a, b) => a.libelle.localeCompare(b.libelle, 'fr'))
}

/** Le libellé d'un groupe : le nom court de la branche (« Romarin »), ou « Par alliance ». */
export function libelleDuGroupe(groupe: string): string {
  return groupe === 'alliance' ? 'Par alliance' : nomBranche(groupe)
}

/** Les groupes présents, dans l'ordre de la légende, avec leur effectif. */
export function groupesDisponibles(invitables: readonly Invitable[]): { cle: string; libelle: string; nombre: number }[] {
  const effectifs = new Map<string, number>()
  for (const i of invitables) effectifs.set(i.groupe, (effectifs.get(i.groupe) ?? 0) + 1)

  const rang = (cle: string): number => {
    const r = ORDRE_DES_GROUPES.indexOf(cle)
    return r === -1 ? ORDRE_DES_GROUPES.length : r
  }

  return [...effectifs.entries()]
    .sort(([a], [b]) => rang(a) - rang(b) || a.localeCompare(b))
    .map(([cle, nombre]) => ({ cle, libelle: libelleDuGroupe(cle), nombre }))
}

/** Minuscules, sans accents : « Élise » se trouve en tapant « elise ». */
export function normaliser(texte: string): string {
  return texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim()
}

/** Les invitables dont le nom contient **chaque mot** tapé, dans n'importe quel ordre. */
export function filtrerParNom<T extends { libelle: string }>(invitables: readonly T[], terme: string): T[] {
  const mots = normaliser(terme).split(/\s+/).filter(Boolean)
  if (mots.length === 0) return [...invitables]
  return invitables.filter((i) => {
    const nom = normaliser(i.libelle)
    return mots.every((m) => nom.includes(m))
  })
}

/**
 * Coche ou décoche un lot d'un coup — « Tous les vivants », une branche, le
 * résultat d'une recherche. Rend un **nouvel** ensemble : l'état reste immuable
 * côté composant, et Vue voit le changement.
 */
export function basculer(selection: ReadonlySet<number>, personIds: readonly number[], cocher: boolean): Set<number> {
  const suivante = new Set(selection)
  for (const id of personIds) {
    if (cocher) suivante.add(id)
    else suivante.delete(id)
  }
  return suivante
}

/** Tout le lot est-il déjà coché ? (Le raccourci propose alors de décocher.) */
export function toutCoche(selection: ReadonlySet<number>, personIds: readonly number[]): boolean {
  return personIds.length > 0 && personIds.every((id) => selection.has(id))
}
