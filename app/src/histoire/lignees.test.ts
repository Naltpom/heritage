import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Branche, Graphe, NoeudFamille, NoeudPersonne, Sexe } from '@/lib/api'
import {
  anneeDe,
  disposerLignees,
  nomDeFamilleDe,
  prenomDe,
  sansFamilleDuConjoint,
  type DispositionHistoire,
} from './lignees'

/**
 * Ce que ces tests protègent : les règles de placement de la disposition
 * `lignees` — aucune fiche perdue ni posée deux fois, aucun chevauchement par
 * rangée, la lignée directe ordonnée selon Sosa, et l'union de chaque couple
 * d'ancêtres qui ne traverse aucune fiche.
 */

let prochain = 1

function construire(): { graphe: Graphe; id: Record<string, string> } {
  prochain = 1
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const id: Record<string, string> = {}

  const personne = (cle: string, nom: string, sex: Sexe, annee: number | null, w = 140): string => {
    const n = prochain++
    const p: NoeudPersonne = {
      id: `p${n}`,
      type: 'personne',
      personId: n,
      x: 0,
      y: 0,
      w,
      h: 56,
      displayName: nom,
      etiquetteNom: nom,
      sex,
      generation: 0,
      branch: 'tr',
      vivant: annee !== null && annee > 1950,
      visibilite: 'complete',
      ...(annee !== null ? { etiquetteDates: `${annee} – ` } : {}),
    }
    noeuds.push(p)
    id[cle] = p.id
    return p.id
  }
  const famille = (epoux: string[], enfants: string[], divorced = false): string => {
    const f: NoeudFamille = { id: `f${prochain++}`, type: 'famille', familyId: prochain, x: 0, y: 0, w: 10, h: 10, divorced }
    noeuds.push(f)
    for (const e of epoux) aretes.push({ de: e, vers: f.id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: f.id, vers: e, genre: 'filiation' })
    return f.id
  }

  const ego = personne('ego', 'Nathan Provost', 'masculin', 1998)
  const pere = personne('pere', 'Laurent Provost', 'masculin', 1969)
  const mere = personne('mere', 'Emmanuelle Voulgre', 'feminin', 1971)
  const soeur = personne('soeur', 'Lucie Provost', 'feminin', 2001)
  famille([pere, mere], [soeur, ego])

  const gpp = personne('gpp', 'Daniel Provost', 'masculin', 1946)
  const gmp = personne('gmp', 'Elisabeth Destombes', 'feminin', 1949)
  const tante = personne('tante', 'Céline Provost', 'feminin', 1972)
  const oncle = personne('oncle', 'Stéphane Provost', 'masculin', 1977)
  famille([gpp, gmp], [pere, tante, oncle])
  // L'oncle a une famille : un conjoint et deux cousins.
  const tanteAlliee = personne('tanteAlliee', 'Anne Martin', 'feminin', 1978)
  const cousin1 = personne('cousin1', 'Hugo Provost', 'masculin', 2004)
  const cousin2 = personne('cousin2', 'Léa Provost', 'feminin', 2007)
  famille([oncle, tanteAlliee], [cousin1, cousin2])

  const gpm = personne('gpm', 'Gabriel Voulgre', 'masculin', 1930)
  const gmm = personne('gmm', 'Marie Figarol', 'feminin', 1937)
  const tanteM = personne('tanteM', 'Nicole Voulgre', 'feminin', 1958)
  famille([gpm, gmm], [tanteM, mere])
  // Côté Voulgre : une grand-tante mariée, dont le mari a ses parents (des alliés).
  const agpV = personne('agpV', 'Pierre Voulgre', 'masculin', 1900)
  const agmV = personne('agmV', 'Irène Marboeuf', 'feminin', 1903)
  const grandTanteV = personne('grandTanteV', 'Marie Voulgre', 'feminin', 1927)
  famille([agpV, agmV], [grandTanteV, gpm])
  const allie = personne('allie', 'Jean Vallaize', 'masculin', 1925)
  famille([allie, grandTanteV], [])
  const pereAllie = personne('pereAllie', 'Léonard Vallaize', 'masculin', 1898)
  const mereAllie = personne('mereAllie', 'Françoise Nadaud', 'feminin', 1900)
  famille([pereAllie, mereAllie], [allie])
  // Côté Figarol, sur la même rangée : les parents de la grand-mère et leur fratrie.
  const agpF = personne('agpF', 'Louis Figarol', 'masculin', 1905)
  const agmF = personne('agmF', 'Jeanne Amelot', 'feminin', 1908)
  const grandOncleF = personne('grandOncleF', 'Paul Figarol', 'masculin', 1934)
  famille([agpF, agmF], [gmm, grandOncleF])
  const fratrieF = ['Henri', 'Émile', 'Léon'].map((p, i) => personne(`frF${i}`, `${p} Amelot`, 'masculin', 1904 + i))
  const bisaieuxF = personne('bisaieuxF', 'Victor Amelot', 'masculin', 1880)
  famille([bisaieuxF], [agmF, ...fratrieF])

  // Arrière-grands-parents du côté du père du père.
  const agp = personne('agp', 'Alain Provost', 'masculin', 1913)
  const agm = personne('agm', 'Aimée Samara', 'feminin', 1917)
  const grandOncle = personne('grandOncle', 'Solange Provost', 'feminin', 1943)
  famille([agp, agm], [grandOncle, gpp])

  // Remariage du grand-père maternel : une demi-tante.
  const secondeEpouse = personne('secondeEpouse', 'Rose Bernard', 'feminin', 1940)
  const demiTante = personne('demiTante', 'Odile Voulgre', 'feminin', 1965)
  famille([gpm, secondeEpouse], [demiTante], true)

  // Le conjoint de l'ego, ses enfants, et les parents du conjoint.
  const conjoint = personne('conjoint', 'Camille Durand', 'feminin', 1999)
  const enfant = personne('enfant', 'Arthur Provost', 'masculin', 2024)
  famille([ego, conjoint], [enfant])
  const beauPere = personne('beauPere', 'Paul Durand', 'masculin', 1970)
  const belleMere = personne('belleMere', 'Anne Lefebvre', 'feminin', 1972)
  const beauFrere = personne('beauFrere', 'Hugo Durand', 'masculin', 2001)
  famille([beauPere, belleMere], [conjoint, beauFrere])
  // L'ascendance du conjoint, sur deux générations : la famille du conjoint.
  const gpcp = personne('gpcp', 'Louis Durand', 'masculin', 1940)
  const gmcp = personne('gmcp', 'Marthe Roux', 'feminin', 1942)
  famille([gpcp, gmcp], [beauPere])
  const gpcm = personne('gpcm', 'Henri Lefebvre', 'masculin', 1941)
  const gmcm = personne('gmcm', 'Irène Colin', 'feminin', 1944)
  famille([gpcm, gmcm], [belleMere])
  const brancheDe: Record<string, Branche> = {
    beauPere: 'cpp',
    beauFrere: 'cpp',
    gpcp: 'cpp',
    gmcp: 'cpm',
    belleMere: 'cmp',
    gpcm: 'cmp',
    gmcm: 'cmm',
  }
  for (const n of noeuds) {
    const cle = Object.keys(id).find((k) => id[k] === n.id)
    if (n.type === 'personne' && cle !== undefined && brancheDe[cle]) (n as NoeudPersonne).branch = brancheDe[cle]
  }

  // Un îlot, relié à personne.
  personne('ilot', 'Personne Isolée', 'inconnu', null)
  // Un couple détaché de l'arbre, avec des dates : sa rangée s'estime d'après elles.
  const antoine = personne('antoine', 'Antoine Leonard', 'masculin', 1912)
  const jeanne = personne('jeanne', 'Jeanne Leonard', 'feminin', 1915)
  const jean = personne('jean', 'Jean Leonard', 'masculin', 1941)
  famille([antoine, jeanne], [jean])

  const graphe: Graphe = {
    empreinte: '0'.repeat(64),
    ego,
    boite: { x: 0, y: 0, w: 1000, h: 1000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
  return { graphe, id }
}

function chevauchements(d: DispositionHistoire): string[] {
  const parRangee = new Map<number, typeof d.fiches>()
  for (const f of d.fiches) parRangee.set(f.y, [...(parRangee.get(f.y) ?? []), f])
  const fautes: string[] = []
  for (const rangee of parRangee.values()) {
    const tri = [...rangee].sort((a, b) => a.x - b.x)
    for (let i = 1; i < tri.length; i++) {
      if (tri[i]!.x < tri[i - 1]!.x + tri[i - 1]!.w) fautes.push(`${tri[i - 1]!.id} / ${tri[i]!.id}`)
    }
  }
  return fautes
}

describe('disposition lignees', () => {
  const { graphe, id } = construire()
  const d = disposerLignees(graphe)
  const f = (cle: string) => d.parId.get(id[cle]!)!

  it('pose chaque personne une fois et une seule', () => {
    const personnes = graphe.noeuds.filter((n) => n.type === 'personne')
    expect(d.fiches).toHaveLength(personnes.length)
    expect(new Set(d.fiches.map((x) => x.id)).size).toBe(personnes.length)
  })

  it('ne fait se chevaucher aucune fiche sur une rangée', () => {
    expect(chevauchements(d)).toEqual([])
  })

  it('garde la largeur et la hauteur du moteur', () => {
    for (const fiche of d.fiches) {
      expect(fiche.w).toBe(fiche.noeud.w)
      expect(fiche.h).toBe(fiche.noeud.h)
    }
  })

  it('met une génération par rangée', () => {
    expect(f('pere').y).toBe(f('mere').y)
    expect(f('gpp').y).toBe(f('gmm').y)
    expect(f('pere').y).toBeLessThan(f('ego').y)
    expect(f('gpp').y).toBeLessThan(f('pere').y)
    expect(f('agp').y).toBeLessThan(f('gpp').y)
    expect(f('enfant').y).toBeGreaterThan(f('ego').y)
    expect(f('conjoint').y).toBe(f('ego').y)
  })

  it('numérote la lignée directe selon Sosa', () => {
    expect(f('ego').sosa).toBe(1)
    expect(f('pere').sosa).toBe(2)
    expect(f('mere').sosa).toBe(3)
    expect(f('gpp').sosa).toBe(4)
    expect(f('gmm').sosa).toBe(7)
    expect(f('agm').sosa).toBe(9)
    expect(f('tante').directe).toBe(false)
  })

  it('range le côté père à gauche et le côté mère à droite, à tous les niveaux', () => {
    expect(f('pere').cx).toBeLessThan(f('mere').cx)
    expect(f('gpp').cx).toBeLessThan(f('gmp').cx)
    expect(f('gmp').cx).toBeLessThan(f('gpm').cx)
    expect(f('gpm').cx).toBeLessThan(f('gmm').cx)
    expect(f('agp').cx).toBeLessThan(f('agm').cx)
  })

  it('range les collatéraux à l’extérieur de la lignée directe', () => {
    // Le père est père dans son couple : sa fratrie est à sa gauche.
    expect(f('tante').cx).toBeLessThan(f('pere').cx)
    expect(f('oncle').cx).toBeLessThan(f('pere').cx)
    expect(f('cousin1').cx).toBeLessThan(f('pere').cx)
    // La mère est mère dans son couple : sa fratrie est à sa droite.
    expect(f('tanteM').cx).toBeGreaterThan(f('mere').cx)
  })

  it('ne fait traverser aucune fiche par l’union d’un couple d’ancêtres', () => {
    for (const [a, b] of [['pere', 'mere'], ['gpp', 'gmp'], ['gpm', 'gmm'], ['agp', 'agm']] as const) {
      const g = f(a)
      const m = f(b)
      const entre = d.fiches.filter((x) => x.y === g.y && x !== g && x !== m && x.x < m.x && x.x + x.w > g.x + g.w)
      expect(entre.map((x) => x.noeud.displayName)).toEqual([])
    }
  })

  it('n’oublie ni les remariages, ni l’ascendance du conjoint, ni les îlots', () => {
    for (const cle of ['secondeEpouse', 'demiTante', 'beauPere', 'belleMere', 'ilot', 'antoine', 'jean']) {
      expect(d.parId.has(id[cle]!)).toBe(true)
    }
    expect(f('demiTante').y).toBe(f('mere').y)
    expect(f('beauPere').y).toBe(f('pere').y)
  })

  it('pose l’ascendance d’un allié au-dessus de lui, pas au bord de l’arbre', () => {
    // Constaté en prod : les parents d'un allié Voulgre partaient au-delà de la
    // dernière fiche de leur rangée, au-dessus des Figarol.
    const enfant = f('allie')
    for (const cle of ['pereAllie', 'mereAllie']) {
      expect(f(cle).rangee).toBe(enfant.rangee - 1)
      // Du côté Voulgre : à gauche des Figarol de la même rangée.
      expect(f(cle).cx).toBeLessThan(f('agpF').cx)
    }
  })

  it('pose l’ascendance du conjoint d’un seul tenant, à droite de celle de l’ego', () => {
    // Constaté en prod, vue d'un père : rattachée famille par famille, l'ascendance de
    // sa femme s'éparpillait à gauche de ses Provost, en ordre inverse.
    const famille = ['beauPere', 'belleMere', 'beauFrere', 'gpcp', 'gmcp', 'gpcm', 'gmcm']
    const ids = new Set(famille.map((c) => id[c]!))
    for (const cle of famille) {
      const x = f(cle)
      const cote = d.fiches.filter((y) => y.rangee === x.rangee && !ids.has(y.id) && !y.detache && y.id !== id['conjoint'])
      for (const y of cote) expect(x.x, `${cle} à droite de ${y.noeud.displayName}`).toBeGreaterThan(y.x)
    }
    // Structurée comme celle de l'ego : côté père à gauche, côté mère à droite.
    expect(f('gpcp').cx).toBeLessThan(f('gmcp').cx)
    expect(f('gmcp').cx).toBeLessThan(f('gpcm').cx)
    expect(f('gpcm').cx).toBeLessThan(f('gmcm').cx)
    expect(f('beauPere').cx).toBeLessThan(f('belleMere').cx)
    expect(f('beauPere').rangee).toBe(f('conjoint').rangee - 1)
    expect(f('gpcp').rangee).toBe(f('conjoint').rangee - 2)
    // Ni ancêtres de l'ego, ni ligne directe.
    expect(f('beauPere').directe).toBe(false)
    expect(f('beauPere').sosa).toBeNull()
    // Le conjoint reste à côté de l'ego.
    expect(f('conjoint').rangee).toBe(f('ego').rangee)
  })

  it('masquer la famille du conjoint la retire et ne déplace rien du côté de l’ego', () => {
    const sans = sansFamilleDuConjoint(graphe)
    const d2 = disposerLignees(sans)
    for (const cle of ['beauPere', 'belleMere', 'beauFrere', 'gpcp', 'gmcm']) expect(d2.parId.has(id[cle]!)).toBe(false)
    for (const cle of ['conjoint', 'enfant', 'ego', 'pere', 'mere']) expect(d2.parId.has(id[cle]!)).toBe(true)
    expect(chevauchements(d2)).toEqual([])
    // Le côté de l'ego est construit avant celui du conjoint : il ne bouge pas.
    for (const cle of ['ego', 'pere', 'mere', 'gpp', 'gmm', 'agp', 'tante', 'cousin1', 'conjoint', 'enfant']) {
      expect(d2.parId.get(id[cle]!)!.x, cle).toBeCloseTo(f(cle).x, 6)
    }
    expect(d2.largeur).toBeLessThan(d.largeur)
  })

  it('estime la rangée des personnes détachées d’après leurs dates, dans une zone à part', () => {
    // 1912 : la génération des arrière-grands-parents (Alain Provost, 1913).
    expect(f('antoine').rangee).toBe(-3)
    expect(f('jeanne').rangee).toBe(-3)
    expect(f('jean').rangee).toBe(-2)
    expect(f('antoine').detache).toBe(true)
    expect(f('ego').detache).toBe(false)
    expect(f('beauPere').detache).toBe(false)
    // Au-delà du bord droit de l'arbre relié.
    const bordDroit = Math.max(...d.fiches.filter((x) => !x.detache).map((x) => x.x + x.w))
    for (const x of d.fiches.filter((x) => x.detache)) expect(x.x).toBeGreaterThan(bordDroit)
    expect(d.zoneDetachee?.effectif).toBe(4)
  })

  it('fait descendre l’enfant direct tout droit sous l’union de ses parents', () => {
    for (const [p, m, e] of [['gpp', 'gmp', 'pere'], ['gpm', 'gmm', 'mere'], ['agp', 'agm', 'gpp']] as const) {
      const enfant = f(e)
      expect(enfant.cx).toBeGreaterThan(f(p).x + f(p).w)
      expect(enfant.cx).toBeLessThan(f(m).x)
    }
  })

  it('dessine la ligne directe et le divorce à part', () => {
    expect(d.traces.directs.length).toBeGreaterThan(0)
    expect(d.traces.divorces.length).toBeGreaterThan(0)
    expect(d.traces.ordinaires.length).toBeGreaterThan(0)
  })

  it('libelle chaque génération relativement à l’ego', () => {
    const libelles = d.generations.map((g) => g.libelle)
    expect(libelles).toEqual(['Trisaïeux', 'Arrière-grands-parents', 'Grands-parents', 'Parents', 'Votre génération', 'Enfants'])
    expect(d.generations.find((g) => g.rangee === -1)!.annees).toBe('vers 1970')
  })

  it('donne un nom à chaque foyer à partir du mari', () => {
    const noms = d.foyers.map((x) => x.nom)
    expect(noms).toContain('Provost')
    expect(noms).toContain('Voulgre')
    expect(d.foyers.find((x) => x.nom === 'Voulgre' && x.ancetres)).toBeDefined()
  })
})

describe('lecture des étiquettes', () => {
  const noeud = (etiquetteNom: string, etiquetteDates?: string): NoeudPersonne => ({
    id: 'p1',
    type: 'personne',
    personId: 1,
    x: 0,
    y: 0,
    w: 100,
    h: 56,
    displayName: etiquetteNom,
    etiquetteNom,
    sex: 'inconnu',
    generation: 0,
    branch: 'tr',
    vivant: false,
    visibilite: 'complete',
    ...(etiquetteDates !== undefined ? { etiquetteDates } : {}),
  })

  it('le prénom est le premier mot, le nom de famille garde ses espaces', () => {
    const n = noeud('Jade de la Tour')
    expect(prenomDe(n)).toBe('Jade')
    expect(nomDeFamilleDe(n)).toBe('de la Tour')
  })

  it('ne lit aucune année sur un nœud restreint ou vivant', () => {
    expect(anneeDe(noeud('Jade Tour'))).toBeNull()
    expect(anneeDe(noeud('Jade Tour', 'vivant·e'))).toBeNull()
    expect(anneeDe(noeud('Jade Tour', '1901-1909 – 1987'))).toBe(1901)
  })
})

describe('disposition lignees — plusieurs conjoints', () => {
  // L'ego s'est marié deux fois ; chaque épouse a ses parents, un frère ou une sœur.
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const id: Record<string, string> = {}
  let n = 1
  const personne = (cle: string, nom: string, sex: Sexe, annee: number): string => {
    const p: NoeudPersonne = {
      id: `p${n}`,
      type: 'personne',
      personId: n++,
      x: 0,
      y: 0,
      w: 140,
      h: 56,
      displayName: nom,
      etiquetteNom: nom,
      sex,
      generation: 0,
      branch: 'tr',
      vivant: false,
      visibilite: 'complete',
      etiquetteDates: `${annee} – `,
    }
    noeuds.push(p)
    id[cle] = p.id
    return p.id
  }
  const famille = (epoux: string[], enfants: string[]): void => {
    const f: NoeudFamille = { id: `f${n++}`, type: 'famille', familyId: n, x: 0, y: 0, w: 10, h: 10, divorced: false }
    noeuds.push(f)
    for (const e of epoux) aretes.push({ de: e, vers: f.id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: f.id, vers: e, genre: 'filiation' })
  }
  const ego = personne('ego', 'Laurent Provost', 'masculin', 1960)
  famille([personne('pere', 'Daniel Provost', 'masculin', 1930), personne('mere', 'Aimée Magnol', 'feminin', 1932)], [ego])
  const f1 = personne('f1', 'Emma Voulgre', 'feminin', 1962)
  const f2 = personne('f2', 'Rose Martin', 'feminin', 1965)
  famille([ego, f1], [personne('e1', 'Nathan Provost', 'masculin', 1990)])
  famille([ego, f2], [personne('e2', 'Léa Provost', 'feminin', 2000)])
  famille(
    [personne('p1', 'Gabriel Voulgre', 'masculin', 1935), personne('m1', 'Marie Figarol', 'feminin', 1937)],
    [f1, personne('s1', 'Nicole Voulgre', 'feminin', 1958)],
  )
  famille(
    [personne('p2', 'Paul Martin', 'masculin', 1938), personne('m2', 'Anne Colin', 'feminin', 1940)],
    [f2, personne('s2', 'Hugo Martin', 'masculin', 1968)],
  )
  const graphe: Graphe = {
    empreinte: '0'.repeat(64),
    ego,
    boite: { x: 0, y: 0, w: 1000, h: 1000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
  const d = disposerLignees(graphe)
  const f = (cle: string) => d.parId.get(id[cle]!)!
  const bornes = (cles: string[]): [number, number] => [
    Math.min(...cles.map((c) => f(c).x)),
    Math.max(...cles.map((c) => f(c).x + f(c).w)),
  ]

  it('range chaque famille de conjoint en colonne, dans l’ordre des unions', () => {
    expect(chevauchementsDe(d)).toEqual([])
    const egoCote = bornes(['pere', 'mere'])
    const premiere = bornes(['p1', 'm1', 's1'])
    const seconde = bornes(['p2', 'm2', 's2'])
    expect(premiere[0]).toBeGreaterThan(egoCote[1])
    expect(seconde[0]).toBeGreaterThan(premiere[1])
    // Les épouses restent à côté de l'ego, sur sa rangée.
    expect(f('f1').rangee).toBe(f('ego').rangee)
    expect(f('f2').rangee).toBe(f('ego').rangee)
  })

  it('masque les familles de tous les conjoints, pas seulement la première', () => {
    const sans = disposerLignees(sansFamilleDuConjoint(graphe))
    for (const cle of ['p1', 'm1', 's1', 'p2', 'm2', 's2']) expect(sans.parId.has(id[cle]!)).toBe(false)
    for (const cle of ['ego', 'pere', 'mere', 'f1', 'f2', 'e1', 'e2']) expect(sans.parId.has(id[cle]!)).toBe(true)
  })
})

function chevauchementsDe(d: DispositionHistoire): string[] {
  const fautes: string[] = []
  const parRangee = new Map<number, typeof d.fiches>()
  for (const x of d.fiches) parRangee.set(x.rangee, [...(parRangee.get(x.rangee) ?? []), x])
  for (const r of parRangee.values()) {
    const tri = [...r].sort((a, b) => a.x - b.x)
    for (let i = 1; i < tri.length; i++) if (tri[i]!.x < tri[i - 1]!.x + tri[i - 1]!.w) fautes.push(tri[i]!.id)
  }
  return fautes
}
