import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe } from '@/lib/api'
import { ligneDe, seulementLaLigne } from './ligneDirecte'

/**
 * La ligne directe de n'importe quelle personne : ses ancêtres et ses descendants,
 * jamais ses collatéraux ni les conjoints de ses descendants.
 */
function graphe(): Graphe {
  const personnes = ['gp', 'gm', 'pere', 'mere', 'oncle', 'moi', 'conjoint', 'enfant', 'petit', 'belleFille']
  const aretes: AreteGraphe[] = []
  const famille = (id: string, epoux: string[], enfants: string[]): void => {
    for (const e of epoux) aretes.push({ de: e, vers: id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: id, vers: e, genre: 'filiation' })
  }
  famille('f1', ['gp', 'gm'], ['pere', 'oncle'])
  famille('f2', ['pere', 'mere'], ['moi'])
  famille('f3', ['moi', 'conjoint'], ['enfant'])
  famille('f4', ['enfant', 'belleFille'], ['petit'])
  return {
    empreinte: '0'.repeat(64),
    ego: 'moi',
    boite: { x: 0, y: 0, w: 1, h: 1 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds: [
      ...personnes.map((id) => ({ id, type: 'personne' }) as Graphe['noeuds'][number]),
      ...['f1', 'f2', 'f3', 'f4'].map((id) => ({ id, type: 'famille' }) as Graphe['noeuds'][number]),
    ],
    aretes,
  }
}

describe('ligne directe', () => {
  it('rassemble les ancêtres et les descendants, pas les collatéraux ni les conjoints', () => {
    const l = ligneDe(graphe(), 'moi')
    expect([...l.ascendants].sort()).toEqual(['gm', 'gp', 'mere', 'moi', 'pere'])
    expect([...l.descendants].sort()).toEqual(['enfant', 'petit'])
  })

  it('suit n’importe quelle personne, pas seulement le lecteur', () => {
    const l = ligneDe(graphe(), 'oncle')
    expect([...l.ascendants].sort()).toEqual(['gm', 'gp', 'oncle'])
    expect(l.descendants.size).toBe(0)
  })
})

describe('la ligne directe seule', () => {
  const ids = (g: Graphe): string[] => g.noeuds.map((n) => n.id).sort()

  it('ne garde que la ligne et les familles qui la relient', () => {
    const g = seulementLaLigne(graphe(), 'moi')
    expect(ids(g)).toEqual(['enfant', 'f1', 'f2', 'f3', 'f4', 'gm', 'gp', 'mere', 'moi', 'pere', 'petit'])
    // Ni l'oncle, ni le conjoint, ni la belle-fille — ni les liens qui les touchaient.
    expect(g.aretes.some((a) => ['oncle', 'conjoint', 'belleFille'].includes(a.de) || ['oncle', 'conjoint', 'belleFille'].includes(a.vers))).toBe(false)
    expect(g.ego).toBe('moi')
  })

  it('prend la racine pour ego quand le lecteur n’est pas de sa ligne', () => {
    const g = seulementLaLigne(graphe(), 'oncle')
    expect(ids(g)).toEqual(['f1', 'gm', 'gp', 'oncle'])
    expect(g.ego).toBe('oncle')
  })

  it('garde le lecteur pour ego quand il descend de la racine', () => {
    const g = seulementLaLigne(graphe(), 'gp')
    expect(g.ego).toBe('moi')
    // Les descendants du grand-père, collatéraux du lecteur compris.
    expect(ids(g)).toContain('oncle')
    expect(ids(g)).not.toContain('gm')
  })
})
