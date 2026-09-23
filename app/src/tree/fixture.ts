/**
 * Un graphe familial synthétique et déterministe, pour les tests du placement :
 * ascendance sur plusieurs générations, fratries, cousins, remariages, famille
 * du conjoint, et un îlot sans lien avec le reste.
 */
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'

export function syntheticGraph(seed = 7, depth = 5): Graphe {
  let s = seed
  const rnd = (): number => ((s = (s * 1103515245 + 12345) % 2147483648) / 2147483648)
  const int = (a: number, b: number): number => a + Math.floor(rnd() * (b - a + 1))
  const noeuds: NoeudGraphe[] = []
  const aretes: AreteGraphe[] = []
  let pid = 0
  let fid = 0
  const given = ['Jean', 'Marie', 'Louis', 'Anne', 'Paul', 'Jeanne', 'Pierre', 'Louise', 'Henri', 'Rose']
  const sur = ['Martin', 'Bernard', 'Dubois', 'Laurent', 'Petit', 'Leroy', 'Moreau', 'Garnier', 'de la Tour']
  const person = (gen: number, surname?: string, sex?: 'masculin' | 'feminin'): string => {
    const id = `p${++pid}`
    const name = `${given[int(0, given.length - 1)]} ${surname ?? sur[int(0, sur.length - 1)]}`
    const year = 1995 - gen * 28 + int(-4, 4)
    noeuds.push({
      id, type: 'personne', personId: pid, x: 0, y: 0, w: 0, h: 0,
      displayName: name, etiquetteNom: name, sex: sex ?? (pid % 2 ? 'masculin' : 'feminin'),
      generation: gen, branch: 'tr', vivant: gen < 2, visibilite: 'complete',
      etiquetteDates: gen < 2 ? 'vivant·e' : `${year} – ${year + int(40, 90)}`,
    })
    return id
  }
  const family = (parents: string[], children: string[], divorced = false): string => {
    const id = `f${++fid}`
    noeuds.push({ id, type: 'famille', familyId: fid, x: 0, y: 0, w: 0, h: 0, divorced })
    for (const p of parents) aretes.push({ de: p, vers: id, genre: 'union', divorce: divorced })
    for (const c of children) aretes.push({ de: id, vers: c, genre: 'filiation' })
    return id
  }
  const sexOf = (id: string): string => (noeuds.find((n) => n.id === id) as { sex: string }).sex
  const other = (id: string): 'masculin' | 'feminin' => (sexOf(id) === 'masculin' ? 'feminin' : 'masculin')
  const descend = (a: string, gen: number, maxDown: number, keep?: string): string[] => {
    const b = person(gen, undefined, other(a))
    const kids: string[] = []
    const n = int(1, 4)
    for (let i = 0; i < n; i++) kids.push(person(gen - 1))
    if (keep) kids.splice(int(0, kids.length), 0, keep)
    family([a, b], kids)
    if (gen - 1 > -maxDown)
      for (const k of kids) if (k !== keep && rnd() < 0.55) descend(k, gen - 1, maxDown)
    return kids
  }
  const ego = person(0, 'Martin', 'masculin')
  const ancestors = (child: string, gen: number): void => {
    const f = person(gen, undefined, 'masculin')
    const m = person(gen, undefined, 'feminin')
    const others: string[] = []
    const n = int(0, 3)
    for (let i = 0; i < n; i++) others.push(person(gen - 1))
    family([f, m], [child, ...others])
    for (const o of others) if (rnd() < 0.5) descend(o, gen - 1, 2)
    if (gen < depth) {
      if (rnd() < 0.85) ancestors(f, gen + 1)
      if (rnd() < 0.85) ancestors(m, gen + 1)
    }
  }
  ancestors(ego, 1)
  // Moi : conjointe, ses parents, nos enfants, et un premier mariage.
  const wife = person(0, undefined, 'feminin')
  const k1 = person(-1)
  const k2 = person(-1)
  family([ego, wife], [k1, k2])
  family([person(1, undefined, 'masculin'), person(1, undefined, 'feminin')], [wife, person(0)])
  const ex = person(0, undefined, 'feminin')
  family([ego, ex], [person(-1)], true)
  // Un îlot.
  family([person(3), person(3)], [person(2), person(2)])
  return {
    empreinte: '0'.repeat(64), ego, boite: { x: 0, y: 0, w: 0, h: 0 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds, aretes,
  }
}
