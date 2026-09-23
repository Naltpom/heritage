import { describe, expect, it } from 'vitest'
import { CARD_H, CARD_W, isotonic, layoutTree } from './layout'
import { syntheticGraph } from './fixture'

describe('layoutTree', () => {
  const graph = syntheticGraph()
  const layout = layoutTree(graph)

  it('place toutes les personnes, une seule fois', () => {
    const people = graph.noeuds.filter((n) => n.type === 'personne')
    expect(layout.persons).toHaveLength(people.length)
    expect(new Set(layout.persons.map((p) => p.id)).size).toBe(people.length)
  })

  it('ne superpose aucune fiche', () => {
    const ps = layout.persons
    for (let i = 0; i < ps.length; i++)
      for (let j = i + 1; j < ps.length; j++) {
        const a = ps[i]!
        const b = ps[j]!
        const overlap =
          a.x < b.x + CARD_W && b.x < a.x + CARD_W && a.y < b.y + CARD_H && b.y < a.y + CARD_H
        expect(overlap, `${a.id} / ${b.id}`).toBe(false)
      }
  })

  it('met les parents au-dessus des enfants et moi en génération 0', () => {
    expect(layout.byId.get(graph.ego)!.level).toBe(0)
    for (const f of layout.families.values())
      for (const p of f.parents)
        for (const c of f.children) expect(layout.byId.get(p)!.y).toBeLessThan(layout.byId.get(c)!.y)
  })

  it('pose les conjoints côte à côte', () => {
    const ego = layout.byId.get(graph.ego)!
    const spouses = (layout.asParent.get(graph.ego) ?? [])
      .flatMap((f) => layout.families.get(f)!.parents)
      .filter((p) => p !== graph.ego)
      .map((p) => layout.byId.get(p)!)
    for (const s of spouses) {
      expect(s.y).toBe(ego.y)
      expect(Math.abs(s.x - ego.x)).toBeLessThan(CARD_W * 2.5)
    }
  })

  it('est déterministe', () => {
    const again = layoutTree(syntheticGraph())
    expect(again.persons.map((p) => [p.x, p.y])).toEqual(layout.persons.map((p) => [p.x, p.y]))
  })
})

describe('isotonic', () => {
  it('respecte ordre et écarts en bougeant le moins possible', () => {
    const xs = isotonic([0, 0, 100], [10, 10, 10], [5, 5], [1, 1, 1])
    expect(xs[1]! - xs[0]!).toBe(15)
    expect(xs[0]).toBe(-7.5)
    expect(xs[2]).toBe(100)
  })
})
