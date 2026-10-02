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
import { disposerLignees, LIBELLES_QUART } from '@/histoire/lignees'

/**
 * Charge le graphe du lecteur et en tire les invitables. Un appel simple, sans
 * la revalidation par `ETag` de `chargerGraphe()` : une réponse `304` ne
 * porterait rien, et cet écran ne garde pas le graphe entre deux visites —
 * c'est le geste de `HistoireView`.
 */
export async function chargerLesInvitables(signal?: AbortSignal): Promise<Invitable[]> {
  const graphe = await requeteApi<Graphe>('/arbre/graphe', signal === undefined ? {} : { signal })
  return invitablesDuGraphe(graphe, nomsDesGroupes(graphe))
}

export interface Invitable {
  personId: number
  libelle: string
  /** La branche du lecteur (`pp`, `tr`, `cmm`…) ou `alliance` — pour les raccourcis. */
  groupe: string
  /** Comment l'arbre nomme ce groupe : « Provost », « Tronc », « Par alliance »… */
  nomDuGroupe: string
}

/**
 * Les noms des groupes **tels que l'arbre les affiche** : un quart porte le nom
 * de famille de son grand-parent de tête (« Provost », « Destombes »), comme la
 * bande en haut de l'arbre et les boutons « Branches » de ses filtres — pas le
 * nom court de la branche (« Romarin »), que personne ne reconnaît ici. On le
 * demande à `disposerLignees`, qui le calcule déjà ; à défaut (quart sans tête
 * connue), le rôle : « Père du père ».
 */
export function nomsDesGroupes(graphe: Graphe): Record<string, string> {
  const noms: Record<string, string> = { tr: 'Tronc', alliance: 'Par alliance' }
  let quarts: { branche: string; nom: string }[] = []
  try {
    quarts = disposerLignees(graphe).quarts
  } catch {
    // un graphe que la disposition ne sait pas lire : les rôles suffiront
  }
  for (const [cle, role] of Object.entries(LIBELLES_QUART)) {
    const nom = quarts.find((q) => q.branche === cle)?.nom ?? ''
    noms[cle] = nom === '' ? role : cle.startsWith('c') ? `${nom} (conjoint)` : nom
  }
  return noms
}

/** Un nom qui n'en est pas un (« ? », vide) : la fiche n'est pas proposable. */
const sansNom = (libelle: string): boolean => !/\p{L}/u.test(libelle)

/** L'ordre des raccourcis : les quatre quarts, le tronc, ceux du conjoint, puis les alliances. */
const ORDRE_DES_GROUPES = ['pp', 'pm', 'mp', 'mm', 'tr', 'cpp', 'cpm', 'cmp', 'cmm', 'alliance']

/**
 * Les invitables d'un graphe, triés par nom.
 *
 * Une personne entrée par **alliance** (la famille du mari d'une tante) n'est
 * rangée dans aucune branche du lecteur — le front ne lit jamais sa `branch`
 * (révision du 2026-10-01) : elle forme son propre groupe.
 */
export function invitablesDuGraphe(graphe: Pick<Graphe, 'noeuds'>, noms: Record<string, string> = {}): Invitable[] {
  const vus = new Set<number>()
  const invitables: Invitable[] = []

  for (const noeud of graphe.noeuds) {
    if (noeud.type !== 'personne') continue
    const p: NoeudPersonne = noeud
    if (p.personId === null || p.enAttente === true || !p.vivant || p.visibilite !== 'complete') continue
    if (vus.has(p.personId) || sansNom(p.displayName)) continue
    vus.add(p.personId)
    const groupe = p.parente === 'alliance' ? 'alliance' : p.branch
    invitables.push({
      personId: p.personId,
      libelle: p.displayName,
      groupe,
      nomDuGroupe: noms[groupe] ?? libelleDuGroupe(groupe),
    })
  }

  return invitables.sort((a, b) => a.libelle.localeCompare(b.libelle, 'fr'))
}

/** Le libellé de repli d'un groupe, sans graphe : le nom court de la branche, ou « Par alliance ». */
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
    .map(([cle, nombre]) => ({ cle, libelle: invitables.find((i) => i.groupe === cle)?.nomDuGroupe ?? libelleDuGroupe(cle), nombre }))
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
