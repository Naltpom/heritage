/**
 * La ligne directe d'une personne : ses ANCÊTRES (elle comprise) et ses DESCENDANTS,
 * lus sur les liens du graphe — union (personne → famille) et filiation (famille →
 * enfant). Tout le graphe reçu, alliances et îlots compris : ce qui n'est pas affiché
 * n'est simplement pas dessiné.
 *
 * Pur, sans DOM : `ligneDirecte.test.ts`.
 */
import type { Graphe } from '@/lib/api'

export interface LigneDirecte {
  /** Les ancêtres, et la personne elle-même. */
  ascendants: Set<string>
  descendants: Set<string>
}

export function ligneDe(graphe: Graphe, racine: string): LigneDirecte {
  const personnes = new Set(graphe.noeuds.filter((n) => n.type === 'personne').map((n) => n.id))
  const epoux = new Map<string, string[]>()
  const enfants = new Map<string, string[]>()
  const famillesDOrigine = new Map<string, string[]>()
  const famillesDUnion = new Map<string, string[]>()
  const ajouter = (m: Map<string, string[]>, k: string, v: string): void => {
    const l = m.get(k)
    if (l) l.push(v)
    else m.set(k, [v])
  }
  for (const a of graphe.aretes) {
    if (a.genre === 'union') {
      if (!personnes.has(a.de)) continue
      ajouter(epoux, a.vers, a.de)
      ajouter(famillesDUnion, a.de, a.vers)
    } else {
      if (!personnes.has(a.vers)) continue
      ajouter(enfants, a.de, a.vers)
      ajouter(famillesDOrigine, a.vers, a.de)
    }
  }
  const parcourir = (suivants: (id: string) => string[]): Set<string> => {
    const vus = new Set<string>()
    const file = [racine]
    while (file.length) {
      for (const s of suivants(file.shift()!)) {
        if (vus.has(s) || s === racine) continue
        vus.add(s)
        file.push(s)
      }
    }
    return vus
  }
  const ascendants = parcourir((id) => (famillesDOrigine.get(id) ?? []).flatMap((f) => epoux.get(f) ?? []))
  ascendants.add(racine)
  const descendants = parcourir((id) => (famillesDUnion.get(id) ?? []).flatMap((f) => enfants.get(f) ?? []))
  return { ascendants, descendants }
}

/**
 * Le graphe RÉDUIT à la ligne directe de `racine` : ses ancêtres, elle-même et ses
 * descendants, et les familles qui les relient — rien d'autre. C'est le mode « seule »
 * de la ligne directe (05/10/2026) : les autres fiches ne sont plus estompées, elles
 * quittent l'écran, et la disposition se refait sur ce qui reste.
 *
 * Une famille est gardée si elle relie un membre de la ligne (comme époux) à un autre
 * (comme enfant) : le couple d'ancêtres au-dessus d'un ancêtre, le foyer d'un
 * descendant au-dessus de son enfant. Le conjoint d'un descendant, qui n'est pas de la
 * ligne, n'est pas gardé : la famille garde alors un seul parent.
 *
 * L'ego reste celui du graphe s'il est de la ligne (la ligne de l'un de ses ancêtres le
 * contient) ; sinon c'est la racine : la disposition numérote depuis l'ego, et un ego
 * absent la ferait partir d'une fiche au hasard.
 */
export function seulementLaLigne(graphe: Graphe, racine: string): Graphe {
  const { ascendants, descendants } = ligneDe(graphe, racine)
  const gardees = new Set([...ascendants, ...descendants])
  const avecEpoux = new Set<string>()
  const avecEnfant = new Set<string>()
  for (const a of graphe.aretes) {
    if (a.genre === 'union' && gardees.has(a.de)) avecEpoux.add(a.vers)
    else if (a.genre !== 'union' && gardees.has(a.vers)) avecEnfant.add(a.de)
  }
  for (const f of avecEpoux) if (avecEnfant.has(f)) gardees.add(f)
  return {
    ...graphe,
    ego: gardees.has(graphe.ego) ? graphe.ego : racine,
    noeuds: graphe.noeuds.filter((n) => gardees.has(n.id)),
    aretes: graphe.aretes.filter((a) => gardees.has(a.de) && gardees.has(a.vers)),
  }
}
