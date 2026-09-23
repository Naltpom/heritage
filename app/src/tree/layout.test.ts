import { describe, expect, it } from 'vitest'
import { CARD_H, CARD_W, countCrossings, layoutTree, type TreeLayout } from './layout'
import { syntheticGraph } from './fixture'

function ancestors(layout: TreeLayout, id: string): Set<string> {
  const out = new Set<string>()
  const stack = [id]
  while (stack.length) {
    const p = stack.pop()!
    for (const f of layout.asChild.get(p) ?? [])
      for (const q of layout.families.get(f)!.parents) if (!out.has(q)) (out.add(q), stack.push(q))
  }
  return out
}

describe('layoutTree', () => {
  const graph = syntheticGraph()
  const layout = layoutTree(graph)
  const ego = layout.byId.get(graph.ego)!
  const parentsOfEgo = layout.families.get(layout.asChild.get(graph.ego)![0]!)!.parents.map(
    (p) => layout.byId.get(p)!,
  )

  it('place toutes les personnes, une seule fois', () => {
    const people = graph.noeuds.filter((n) => n.type === 'personne')
    const real = layout.persons.filter((p) => p.ref === undefined)
    expect(real).toHaveLength(people.length)
    expect(new Set(real.map((p) => p.id)).size).toBe(people.length)
    // Un renvoi pointe toujours vers une personne posée.
    for (const p of layout.persons) if (p.ref) expect(layout.byId.has(p.ref)).toBe(true)
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
    expect(ego.level).toBe(0)
    for (const d of layout.drawn)
      for (const p of d.parents)
        for (const c of d.children) expect(layout.byId.get(p)!.y).toBeLessThan(layout.byId.get(c)!.y)
  })

  it('range toute l’ascendance paternelle à gauche de la maternelle', () => {
    const [father, mother] = parentsOfEgo as [(typeof parentsOfEgo)[0], (typeof parentsOfEgo)[0]]
    expect(father.node.sex).toBe('masculin')
    const pat = [father.id, ...ancestors(layout, father.id)].map((id) => layout.byId.get(id)!)
    const mat = [mother.id, ...ancestors(layout, mother.id)].map((id) => layout.byId.get(id)!)
    expect(Math.max(...pat.map((p) => p.x))).toBeLessThan(Math.min(...mat.map((p) => p.x)))
  })

  it('met mes parents de part et d’autre au-dessus de moi', () => {
    const [father, mother] = parentsOfEgo as [(typeof parentsOfEgo)[0], (typeof parentsOfEgo)[0]]
    expect(father.x).toBeLessThan(mother.x)
    const mid = (father.x + mother.x) / 2
    // Le couple est centré sur la fratrie, dont je fais partie.
    expect(Math.abs(mid - ego.x)).toBeLessThan(CARD_W * 4)
  })

  it('pose les conjoints côte à côte', () => {
    const spouses = (layout.asParent.get(graph.ego) ?? [])
      .flatMap((f) => layout.families.get(f)!.parents)
      .filter((p) => p !== graph.ego)
      .map((p) => layout.byId.get(p)!)
    for (const s of spouses) {
      expect(s.y).toBe(ego.y)
      expect(Math.abs(s.x - ego.x)).toBeLessThan(CARD_W * 2.5)
    }
  })

  it('ne croise aucun trait', () => {
    // Les alliances passent par des renvois : aucun trait ne traverse l'arbre.
    expect(countCrossings(layout)).toBe(0)
  })

  it('ne glisse aucune fiche entre deux conjoints', () => {
    for (const d of layout.drawn) {
      if (d.parents.length !== 2) continue
      const [a, b] = d.parents.map((id) => layout.byId.get(id)!).sort((u, v) => u.x - v.x) as [
        (typeof layout.persons)[0],
        (typeof layout.persons)[0],
      ]
      expect(a.level).toBe(b.level)
      const between = layout.persons.filter((p) => p.level === a.level && p.x > a.x && p.x < b.x)
      expect(between.map((p) => p.id), `${a.id} / ${b.id}`).toEqual([])
    }
  })

  it('est déterministe', () => {
    const again = layoutTree(syntheticGraph())
    expect(again.persons.map((p) => [p.x, p.y])).toEqual(layout.persons.map((p) => [p.x, p.y]))
  })
})
