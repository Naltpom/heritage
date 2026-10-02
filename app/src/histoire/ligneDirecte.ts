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
