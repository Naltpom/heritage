import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Branche, Graphe, NoeudFamille, NoeudPersonne, Parente, Sexe } from '@/lib/api'
import { sansFamillesParAlliance } from '@/histoire/alliances'
import { genealogieSynthetique } from '@/histoire/banc/genealogie'
import { auFormat, GABARIT_PORTRAIT } from '@/histoire/cartes'
import { sansFamilleDuConjoint } from '@/histoire/lignees'
import { noeudsSansLien, sansPersonnesSansLien } from '@/histoire/sansLien'
import { disposerEnGroupes, type DispositionGroupes } from '@/arbre2/groupes'
import { brancherCommeLeServeur } from './banc/branches'
import { COULOIR_MIN, ECART_COUPLE, disposerEnRevue } from './disposition'
import { mesurer, segmentsDe } from './mesures'

/**
 * Ce que ces tests protègent : les promesses de `/arbre-review` — aucun trait sous une
 * carte ni au travers d'un rectangle, des cartes qui ne se touchent jamais et gardent leur
 * air, des rectangles qui ne se chevauchent pas, le moins de croisements (zéro quand
 * l'arbre le permet, l'optimum connu quand il ne le permet pas), un dessin déterministe —,
 * et qu'il dessine les MÊMES données que `/arbre` : chacun dans son groupe, sur sa rangée.
 */

/* ------------------------------------------------------------------ */
/* Des arbres faits à la main                                          */
/* ------------------------------------------------------------------ */

function nouvelArbre() {
  let n = 1
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const id: Record<string, string> = {}
  const personne = (cle: string, sex: Sexe, annee: number | null, branch: Branche, parente: Parente = 'famille'): string => {
    const k = n++
    const p: NoeudPersonne = {
      id: `p${k}`,
      type: 'personne',
      personId: k,
      x: 0,
      y: 0,
      w: 160,
      h: 56,
      displayName: cle,
      etiquetteNom: `${cle} Nom${k}`,
      sex,
      generation: 0,
      branch,
      parente,
      vivant: false,
      visibilite: 'complete',
      ...(annee !== null ? { etiquetteDates: `${annee} – ` } : {}),
    }
    noeuds.push(p)
    id[cle] = p.id
    return p.id
  }
  const famille = (epoux: string[], enfants: string[], divorced = false): string => {
    const f: NoeudFamille = { id: `f${n++}`, type: 'famille', familyId: n, x: 0, y: 0, w: 10, h: 10, divorced }
    noeuds.push(f)
    for (const e of epoux) aretes.push({ de: e, vers: f.id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: f.id, vers: e, genre: 'filiation' })
    return f.id
  }
  const finir = (ego: string): Graphe => ({
    empreinte: '0'.repeat(64),
    ego,
    boite: { x: 0, y: 0, w: 1000, h: 1000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  })
  return { personne, famille, finir, id, noeuds }
}

/**
 * L'arbre d'essai de `/arbre` (`groupes.test.ts`), dans ses grandes lignes : le tronc, les
 * quatre quarts avec leurs ascendants, leurs oncles et leurs cousins, un remariage divorcé,
 * la famille du conjoint, une famille par alliance, un îlot sans lien.
 */
function arbreDEssai(): { graphe: Graphe; id: Record<string, string> } {
  const a = nouvelArbre()
  const { personne: p, famille: f } = a
  const ego = p('ego', 'masculin', 1998, 'tr')
  const pere = p('pere', 'masculin', 1969, 'tr')
  const mere = p('mere', 'feminin', 1971, 'tr')
  const soeur = p('soeur', 'feminin', 2001, 'tr')
  f([pere, mere], [soeur, ego])
  const conjoint = p('conjoint', 'feminin', 1999, 'tr')
  f([ego, conjoint], [p('enfant', 'masculin', 2024, 'tr')])
  // pp
  const gpp = p('gpp', 'masculin', 1946, 'pp')
  const tante = p('tante', 'feminin', 1972, 'pp')
  const oncle = p('oncle', 'masculin', 1977, 'pp')
  const tanteAlliee = p('tanteAlliee', 'feminin', 1978, 'pp')
  f([oncle, tanteAlliee], [p('cousin1', 'masculin', 2004, 'pp'), p('cousin2', 'feminin', 2007, 'pp')])
  f([p('agp', 'masculin', 1913, 'pp'), p('agm', 'feminin', 1917, 'pp')], [p('grandTante', 'feminin', 1943, 'pp'), gpp])
  // pm
  const gmp = p('gmp', 'feminin', 1949, 'pm')
  f([gpp, gmp], [pere, tante, oncle])
  const grandOncleD = p('grandOncleD', 'masculin', 1951, 'pm')
  f([p('agpD', 'masculin', 1920, 'pm'), p('agmD', 'feminin', 1922, 'pm')], [gmp, grandOncleD])
  f([grandOncleD], [p('cousinD', 'masculin', 1980, 'pm')])
  // mp, avec une alliance et un remariage divorcé
  const gpm = p('gpm', 'masculin', 1930, 'mp')
  const grandTanteV = p('grandTanteV', 'feminin', 1927, 'mp')
  f([p('agpV', 'masculin', 1900, 'mp'), p('agmV', 'feminin', 1903, 'mp')], [grandTanteV, gpm])
  const allie = p('allie', 'masculin', 1925, 'mp')
  f([allie, grandTanteV], [])
  f([p('pereAllie', 'masculin', 1898, 'mp', 'alliance'), p('mereAllie', 'feminin', 1900, 'mp', 'alliance')], [allie])
  f([gpm, p('secondeEpouse', 'feminin', 1940, 'mp')], [p('demiTante', 'feminin', 1965, 'mp')], true)
  // mm
  const gmm = p('gmm', 'feminin', 1937, 'mm')
  f([gpm, gmm], [p('tanteM', 'feminin', 1958, 'mp'), mere])
  f([p('agpF', 'masculin', 1905, 'mm'), p('agmF', 'feminin', 1908, 'mm')], [gmm, p('grandOncleF', 'masculin', 1934, 'mm')])
  // La famille du conjoint.
  const beauPere = p('beauPere', 'masculin', 1970, 'cpp', 'conjoint')
  const belleMere = p('belleMere', 'feminin', 1972, 'cmp', 'conjoint')
  f([beauPere, belleMere], [conjoint, p('beauFrere', 'masculin', 2001, 'cpp', 'conjoint')])
  f([p('gpcp', 'masculin', 1940, 'cpp', 'conjoint'), p('gmcp', 'feminin', 1942, 'cpm', 'conjoint')], [beauPere])
  f([p('gpcm', 'masculin', 1941, 'cmp', 'conjoint'), p('gmcm', 'feminin', 1944, 'cmm', 'conjoint')], [belleMere])
  // Un îlot.
  f([p('antoine', 'masculin', 1912, 'tr'), p('jeanne', 'feminin', 1915, 'tr')], [p('jean', 'masculin', 1941, 'tr')])
  for (const n of a.noeuds) if (n.type === 'personne' && n.parente === 'alliance') n.pont = allie
  return { graphe: a.finir(ego), id: a.id }
}

/** Le chemin de l'écran : sans alliances ni îlots, comme par défaut. */
const parDefaut = (g: Graphe): Graphe => sansPersonnesSansLien(sansFamillesParAlliance(g), noeudsSansLien(g))

function chevauchements(d: DispositionGroupes): string[] {
  const parRangee = new Map<number, typeof d.fiches>()
  for (const f of d.fiches) parRangee.set(f.y, [...(parRangee.get(f.y) ?? []), f])
  const fautes: string[] = []
  for (const l of parRangee.values()) {
    const tri = [...l].sort((x, y) => x.x - y.x)
    for (let i = 1; i < tri.length; i++) if (tri[i]!.x < tri[i - 1]!.x + tri[i - 1]!.w) fautes.push(`${tri[i - 1]!.id} / ${tri[i]!.id}`)
  }
  return fautes
}

/** L'id de la famille de ces deux époux. */
function familleDe(d: DispositionGroupes, a: string, b: string): string {
  return d.traces.familles.find((t) => t.personnes.slice(0, 2).includes(a) && t.personnes.slice(0, 2).includes(b))!.id
}

/** Les croisements du dessin, chacun par la paire de familles qui se coupent (traits fusionnés comme le fait `mesurer`). */
function croisementsEntre(d: DispositionGroupes): [string, string][] {
  const h: { y: number; x1: number; x2: number; f: string }[] = []
  const v: { x: number; y1: number; y2: number; f: string }[] = []
  for (const t of d.traces.familles)
    for (const [x1, y1, x2, y2] of segmentsDe(t.d)) {
      if (y1 === y2 && x1 !== x2) h.push({ y: y1, x1: Math.min(x1, x2), x2: Math.max(x1, x2), f: t.id })
      else if (x1 === x2 && y1 !== y2) v.push({ x: x1, y1: Math.min(y1, y2), y2: Math.max(y1, y2), f: t.id })
    }
  const vus = new Set<string>()
  const paires: [string, string][] = []
  for (const a of h)
    for (const b of v) {
      if (a.f === b.f || !(b.x > a.x1 + 0.5 && b.x < a.x2 - 0.5 && a.y > b.y1 + 0.5 && a.y < b.y2 - 0.5)) continue
      const cle = `${b.x}|${a.y}`
      if (vus.has(cle)) continue
      vus.add(cle)
      paires.push([a.f, b.f])
    }
  return paires
}

/** Deux rectangles qui partagent une hauteur ne se chevauchent pas. */
function rectanglesQuiSeChevauchent(d: DispositionGroupes): string[] {
  const fautes: string[] = []
  d.groupes.forEach((r, i) =>
    d.groupes.forEach((q, j) => {
      if (j <= i) return
      const x = r.x < q.x + q.w && q.x < r.x + r.w
      const y = r.y < q.y + q.h && q.y < r.y + r.h
      if (x && y) fautes.push(`${r.libelle} / ${q.libelle}`)
    }),
  )
  return fautes
}

/* ------------------------------------------------------------------ */
/* Les promesses                                                       */
/* ------------------------------------------------------------------ */

describe('/arbre-review sur l’arbre d’essai', () => {
  const { graphe, id } = arbreDEssai()
  const g = parDefaut(graphe)
  const d = disposerEnRevue(g)
  const m = mesurer(d, d.groupes)

  it('pose chaque personne une fois, sans que deux cartes se touchent', () => {
    const personnes = g.noeuds.filter((n) => n.type === 'personne')
    expect(d.fiches).toHaveLength(personnes.length)
    expect(new Set(d.fiches.map((f) => f.id)).size).toBe(personnes.length)
    expect(chevauchements(d)).toEqual([])
    expect(m.ecartMinFiches).toBeGreaterThanOrEqual(ECART_COUPLE)
    expect(m.ecartMinRangees).toBeGreaterThanOrEqual(COULOIR_MIN)
  })

  it('ne passe sous aucune carte, ne traverse aucun rectangle, n’entre dans aucun groupe étranger', () => {
    expect(m.sousUneCarte).toBe(0)
    expect(m.traverseUnGroupe).toBe(0)
    expect(m.dansUnGroupeEtranger).toBe(0)
  })

  it('ne croise que là où c’est inévitable, et garde les couples côte à côte', () => {
    // Le tronc se pose contre les quarts du conjoint (pour que le conjoint rejoigne ses
    // parents par leur couloir) : le lien des parents du père file donc vers lui au-dessus
    // des descendances des quarts intérieurs, et coupe chacune de leurs chutes — celle du
    // cousin de la grand-mère (pm), celles de la demi-tante et de la tante maternelle (mp).
    // Toute autre disposition des mêmes rectangles en coupe autant ; rien d'autre ne se croise.
    const peres = familleDe(d, id.gpp!, id.gmp!)
    const paires = croisementsEntre(d)
    expect(paires).toHaveLength(3)
    for (const paire of paires) expect(paire).toContain(peres)
    expect(m.croisements).toBe(3)
    expect(m.chevauchements).toBe(0)
    expect(m.couplesSepares).toBe(0)
  })

  it('sans la famille du conjoint, le tronc au milieu : un croisement par quart intérieur', () => {
    const sans = disposerEnRevue(parDefaut(sansFamilleDuConjoint(graphe)))
    const paires = croisementsEntre(sans)
    // Le cousin de la grand-mère paternelle sous le lien du père, la demi-tante sous celui de la mère.
    expect(paires).toHaveLength(2)
    expect(paires.flat()).toContain(familleDe(sans, id.gpp!, id.gmp!))
    expect(paires.flat()).toContain(familleDe(sans, id.gpm!, id.gmm!))
    const tronc = sans.groupes.find((r) => r.cle === 'tr')!
    const pm = sans.groupes.filter((r) => r.cle === 'pm')
    const mp = sans.groupes.filter((r) => r.cle === 'mp')
    expect(Math.max(...pm.map((r) => r.x))).toBeLessThan(tronc.x)
    expect(Math.min(...mp.map((r) => r.x))).toBeGreaterThan(tronc.x)
  })

  it('dessine les mêmes données que /arbre : chacun sur sa rangée', () => {
    const arbre = disposerEnGroupes(g)
    for (const f of d.fiches) expect(f.rangee, f.id).toBe(arbre.parId.get(f.id)!.rangee)
  })

  it('range chaque fiche dans un rectangle de son groupe, et les rectangles ne se chevauchent pas', () => {
    const arbre = disposerEnGroupes(g)
    const groupeDe = new Map<string, string>()
    for (const r of arbre.groupes) for (const f of arbre.fiches) if (f.cx >= r.x && f.cx <= r.x + r.w && f.y >= r.y && f.y <= r.y + r.h) groupeDe.set(f.id, r.cle)
    for (const f of d.fiches) {
      const dedans = d.groupes.filter((r) => f.x >= r.x && f.x + f.w <= r.x + r.w && f.y >= r.y && f.y + f.h <= r.y + r.h)
      expect(dedans.map((r) => r.cle), f.id).toEqual([groupeDe.get(f.id)])
    }
    expect(rectanglesQuiSeChevauchent(d)).toEqual([])
  })

  it('coupe un quart en son ascendance et sa descendance, de part et d’autre d’un couloir', () => {
    const pp = d.groupes.filter((r) => r.cle === 'pp')
    expect(pp).toHaveLength(2)
    const [haut, bas] = [...pp].sort((x, y) => x.y - y.y) as [(typeof pp)[0], (typeof pp)[0]]
    expect(haut.y + haut.h).toBeLessThan(bas.y)
    // Le grand-père en haut, l'oncle en bas.
    const dans = (fid: string, r: typeof haut): boolean => {
      const f = d.parId.get(fid)!
      return f.y >= r.y && f.y + f.h <= r.y + r.h
    }
    expect(dans(id.gpp!, haut)).toBe(true)
    expect(dans(id.oncle!, bas)).toBe(true)
  })

  it('relie le père à ses parents par le couloir, entre les rectangles du haut et ceux du bas', () => {
    const pere = d.parId.get(id.pere!)!
    const gpp = d.parId.get(id.gpp!)!
    // Le lien de la famille des grands-parents paternels : ses traits horizontaux sous la
    // rangée des grands-parents passent au-dessus de tous les rectangles du bas.
    const famille = d.traces.familles.find((t) => t.personnes.includes(id.pere!) && t.personnes.includes(id.gpp!))!
    const hauts = [...famille.d.matchAll(/M(-?[\d.]+) (-?[\d.]+)L(-?[\d.]+) (-?[\d.]+)/g)]
      .map((x) => x.slice(1).map(Number) as [number, number, number, number])
      .filter(([, y1, , y2]) => y1 === y2 && y1 > gpp.y + gpp.h)
    expect(hauts.length).toBeGreaterThan(0)
    const quarts = d.groupes.filter((r) => ['pp', 'pm', 'mp', 'mm'].includes(r.cle))
    const basDesHauts = Math.max(...quarts.filter((r) => r.y < gpp.y).map((r) => r.y + r.h))
    const hautDesBas = Math.min(...quarts.filter((r) => r.y > gpp.y).map((r) => r.y))
    for (const [, y] of hauts) {
      expect(y).toBeGreaterThan(basDesHauts)
      expect(y).toBeLessThan(hautDesBas)
      expect(y).toBeLessThan(pere.y)
    }
  })

  it('pose les grands-parents face à face, chacun au bord de son rectangle, unis d’un trait droit', () => {
    for (const [mari, femme] of [
      [id.gpp!, id.gmp!],
      [id.gpm!, id.gmm!],
    ] as const) {
      expect(entreLesEpoux(d, mari, femme)).toEqual([])
      expect(unionDroite(d, mari, femme)).toBe(true)
    }
  })
})

/** Les fiches posées, sur leur rangée, entre deux époux. */
function entreLesEpoux(d: DispositionGroupes, a: string, b: string): string[] {
  const fa = d.parId.get(a)!
  const fb = d.parId.get(b)!
  const [g, dr] = fa.x < fb.x ? [fa, fb] : [fb, fa]
  return d.fiches.filter((f) => f.y === g.y && f.x > g.x && f.x < dr.x).map((f) => f.id)
}

/** L'union de deux époux voisins est-elle un trait droit, à mi-hauteur, de l'un à l'autre ? */
function unionDroite(d: DispositionGroupes, a: string, b: string): boolean {
  const fa = d.parId.get(a)!
  const fb = d.parId.get(b)!
  const [g, dr] = fa.x < fb.x ? [fa, fb] : [fb, fa]
  const t = d.traces.familles.find((x) => x.personnes.slice(0, 2).includes(a) && x.personnes.slice(0, 2).includes(b))!
  return segmentsDe(t.d).some(
    ([x1, y1, x2, y2]) => y1 === y2 && Math.abs(y1 - (g.y + g.h / 2)) < 1 && Math.min(x1, x2) <= g.x + g.w + 0.5 && Math.max(x1, x2) >= dr.x - 0.5,
  )
}

describe('les croisements sur des cas d’école', () => {
  it('un arbre sans implexe se dessine sans un croisement', () => {
    const a = nouvelArbre()
    const ego = a.personne('ego', 'masculin', 1990, 'tr')
    const pere = a.personne('pere', 'masculin', 1960, 'tr')
    const mere = a.personne('mere', 'feminin', 1962, 'tr')
    a.famille([pere, mere], [a.personne('frere', 'masculin', 1988, 'tr'), ego, a.personne('soeur', 'feminin', 1993, 'tr')])
    const epouse = a.personne('epouse', 'feminin', 1991, 'tr')
    const enfants = ['e1', 'e2', 'e3'].map((c, i) => a.personne(c, i % 2 ? 'feminin' : 'masculin', 2015 + i, 'tr'))
    a.famille([ego, epouse], enfants)
    a.famille([enfants[0]!, a.personne('bru', 'feminin', 2016, 'tr')], [a.personne('pe1', 'masculin', 2040, 'tr'), a.personne('pe2', 'feminin', 2042, 'tr')])
    const d = disposerEnRevue(a.finir(ego))
    expect(mesurer(d, d.groupes).croisements).toBe(0)
    expect(d.croisements).toBe(0)
  })

  it('deux frères qui épousent deux sœurs : un croisement, l’optimum', () => {
    // Quelle que soit la disposition, les deux couples liant les deux fratries forcent un
    // croisement (et un seul suffit).
    const a = nouvelArbre()
    const p1 = a.personne('p1', 'masculin', 1930, 'tr')
    const p2 = a.personne('p2', 'feminin', 1932, 'tr')
    const q1 = a.personne('q1', 'masculin', 1931, 'tr')
    const q2 = a.personne('q2', 'feminin', 1933, 'tr')
    const a1 = a.personne('a1', 'masculin', 1960, 'tr')
    const a2 = a.personne('a2', 'masculin', 1962, 'tr')
    const b1 = a.personne('b1', 'feminin', 1961, 'tr')
    const b2 = a.personne('b2', 'feminin', 1963, 'tr')
    a.famille([p1, p2], [a1, a2])
    a.famille([q1, q2], [b1, b2])
    a.famille([a1, b1], [])
    a.famille([a2, b2], [])
    const d = disposerEnRevue(a.finir(a1))
    expect(d.croisements).toBe(1)
    expect(mesurer(d, d.groupes).croisements).toBe(1)
  })
})

describe('les cas durs', () => {
  it('un remariage : chaque lit sous son union, sans croisement ni carte touchée', () => {
    const a = nouvelArbre()
    const ego = a.personne('ego', 'masculin', 1950, 'tr')
    const e1 = a.personne('premiere', 'feminin', 1951, 'tr')
    const e2 = a.personne('seconde', 'feminin', 1955, 'tr')
    a.famille([e1, ego], [a.personne('a', 'masculin', 1975, 'tr'), a.personne('b', 'feminin', 1977, 'tr')], true)
    a.famille([ego, e2], [a.personne('c', 'masculin', 1985, 'tr'), a.personne('d', 'feminin', 1988, 'tr')])
    const d = disposerEnRevue(a.finir(ego))
    const m = mesurer(d, d.groupes)
    expect(chevauchements(d)).toEqual([])
    expect(m.croisements).toBe(0)
    expect(m.couplesSepares).toBe(0)
    expect(m.sousUneCarte).toBe(0)
    // L'ego entre ses deux épouses : chacune de son côté.
    const x = (cle: string): number => d.parId.get(a.id[cle]!)!.cx
    expect(Math.min(x('premiere'), x('seconde'))).toBeLessThan(x('ego'))
    expect(Math.max(x('premiere'), x('seconde'))).toBeGreaterThan(x('ego'))
    // Les enfants de chaque lit, du côté de leur mère.
    const [gauche, droite] = x('premiere') < x('seconde') ? [['a', 'b'], ['c', 'd']] : [['c', 'd'], ['a', 'b']]
    expect(Math.max(...gauche!.map(x))).toBeLessThan(Math.min(...droite!.map(x)))
  })

  it('un grand-père remarié deux fois dans son quart : au bout de son foyer, face à la grand-mère', () => {
    const a = nouvelArbre()
    const ego = a.personne('ego', 'masculin', 1990, 'tr')
    const pere = a.personne('pere', 'masculin', 1960, 'tr')
    const mere = a.personne('mere', 'feminin', 1962, 'tr')
    a.famille([pere, mere], [ego])
    const gp = a.personne('gp', 'masculin', 1930, 'pp')
    const gm = a.personne('gm', 'feminin', 1932, 'pm')
    a.famille([gp, gm], [pere, a.personne('oncle', 'masculin', 1958, 'pp')])
    // Deux autres épouses, posées avec lui dans pp : au milieu d'elles, l'union avec la
    // grand-mère (dans pm) contournerait l'une des deux.
    a.famille([gp, a.personne('w1', 'feminin', 1931, 'pp')], [a.personne('demi1', 'masculin', 1955, 'pp')])
    a.famille([gp, a.personne('w2', 'feminin', 1935, 'pp')], [a.personne('demi2', 'feminin', 1965, 'pp')])
    a.famille([a.personne('agp', 'masculin', 1900, 'pm'), a.personne('agm', 'feminin', 1902, 'pm')], [gm, a.personne('grandOncle', 'masculin', 1934, 'pm')])
    a.famille([a.personne('gpm', 'masculin', 1933, 'mp'), a.personne('gmm', 'feminin', 1936, 'mm')], [mere])
    const d = disposerEnRevue(a.finir(ego))
    expect(entreLesEpoux(d, gp, gm)).toEqual([])
    expect(unionDroite(d, gp, gm)).toBe(true)
    expect(chevauchements(d)).toEqual([])
    expect(mesurer(d, d.groupes).sousUneCarte).toBe(0)
  })

  it('un implexe (deux cousins qui s’épousent) : tout est posé, le couple sur une rangée', () => {
    const a = nouvelArbre()
    const ego = a.personne('ego', 'masculin', 1990, 'tr')
    const ap = a.personne('ap', 'masculin', 1900, 'tr')
    const am = a.personne('am', 'feminin', 1902, 'tr')
    const f1 = a.personne('f1', 'masculin', 1930, 'tr')
    const f2 = a.personne('f2', 'feminin', 1932, 'tr')
    a.famille([ap, am], [f1, f2])
    const c1 = a.personne('c1', 'masculin', 1960, 'tr')
    const c2 = a.personne('c2', 'feminin', 1962, 'tr')
    a.famille([f1, a.personne('ep1', 'feminin', 1931, 'tr')], [c1])
    a.famille([a.personne('ep2', 'masculin', 1929, 'tr'), f2], [c2])
    a.famille([c1, c2], [ego])
    const d = disposerEnRevue(a.finir(ego))
    expect(d.fiches).toHaveLength(9)
    expect(d.parId.get(a.id.c1!)!.y).toBe(d.parId.get(a.id.c2!)!.y)
    expect(chevauchements(d)).toEqual([])
    expect(mesurer(d, d.groupes).sousUneCarte).toBe(0)
  })

  it('un mariage entre deux générations : le lien rejoint le flanc de l’époux, sous aucune carte', () => {
    const a = nouvelArbre()
    const ego = a.personne('ego', 'masculin', 1990, 'tr')
    const pere = a.personne('pere', 'masculin', 1950, 'tr')
    const mere = a.personne('mere', 'feminin', 1952, 'tr')
    a.famille([pere, mere], [ego, a.personne('soeur', 'feminin', 1992, 'tr')])
    // L'oncle (rangée −1) épouse sa nièce (rangée 0).
    const oncle = a.personne('oncle', 'masculin', 1955, 'tr')
    a.famille([a.personne('gp', 'masculin', 1920, 'tr'), a.personne('gm', 'feminin', 1922, 'tr')], [pere, oncle])
    const niece = a.personne('niece', 'feminin', 1985, 'tr')
    a.famille([a.personne('beauFrere', 'masculin', 1950, 'tr'), a.personne('tante', 'feminin', 1953, 'tr')], [niece])
    a.famille([oncle, niece], [a.personne('petit', 'masculin', 2010, 'tr')])
    const d = disposerEnRevue(a.finir(ego))
    const m = mesurer(d, d.groupes)
    expect(chevauchements(d)).toEqual([])
    expect(m.sousUneCarte).toBe(0)
    // Chaque famille a son trait.
    const familles = a.noeuds.filter((n) => n.type === 'famille').map((n) => n.id)
    for (const f of familles) expect(d.traces.familles.some((t) => t.id === f), f).toBe(true)
  })

  it('les familles par alliance : auprès de leur pont, dans son rectangle, « à part » comme « dans mon arbre »', () => {
    const { graphe, id } = arbreDEssai()
    const avecAlliances = sansPersonnesSansLien(graphe, noeudsSansLien(graphe))
    for (const alliances of ['a-part', 'dans-l-arbre'] as const) {
      const d = disposerEnRevue(avecAlliances, { alliances })
      const pont = d.parId.get(id.allie!)!
      const pere = d.parId.get(id.pereAllie!)!
      const rect = d.groupes.find((r) => pont.cx >= r.x && pont.cx <= r.x + r.w && pont.y >= r.y && pont.y <= r.y + r.h)!
      expect(pere.cx >= rect.x && pere.cx <= rect.x + rect.w, alliances).toBe(true)
      expect(pere.rangee).toBe(pont.rangee - 1)
      const m = mesurer(d, d.groupes)
      expect(m.sousUneCarte, alliances).toBe(0)
      expect(m.traverseUnGroupe, alliances).toBe(0)
      expect(d.alliances.length > 0, alliances).toBe(alliances === 'a-part')
    }
  })

  it('les personnes sans lien : leur rectangle, au bout de l’arbre', () => {
    const { graphe } = arbreDEssai()
    const d = disposerEnRevue(sansFamillesParAlliance(graphe))
    const ilot = d.groupes.find((r) => r.cle === 'sansLien')!
    expect(ilot).toBeDefined()
    for (const r of d.groupes) if (r !== ilot) expect(r.x + r.w).toBeLessThan(ilot.x)
    expect(d.zoneDetachee?.effectif).toBe(3)
  })
})

describe('les options de l’écran', () => {
  it('range les fratries par ordre de naissance quand on le demande', () => {
    const a = nouvelArbre()
    const pere = a.personne('pere', 'masculin', 1960, 'tr')
    const mere = a.personne('mere', 'feminin', 1962, 'tr')
    // Dans le désordre du graphe, exprès.
    const d1 = a.personne('d', 'masculin', 2001, 'tr')
    const ego = a.personne('ego', 'masculin', 1998, 'tr')
    const b = a.personne('b', 'feminin', 1995, 'tr')
    const a1 = a.personne('a', 'masculin', 1990, 'tr')
    a.famille([pere, mere], [d1, ego, b, a1])
    a.famille([a.personne('mari', 'masculin', 1993, 'tr'), b], [])
    const d = disposerEnRevue(a.finir(ego), { fratriesParNaissance: true })
    const x = (cle: string): number => d.parId.get(a.id[cle]!)!.cx
    expect(x('a')).toBeLessThan(x('b'))
    expect(x('b')).toBeLessThan(x('ego'))
    expect(x('ego')).toBeLessThan(x('d'))
  })

  it('le format portrait garde ses écarts', () => {
    const { graphe } = arbreDEssai()
    const d = disposerEnRevue(auFormat(parDefaut(graphe), 'portrait'))
    expect(d.fiches.every((f) => f.w === GABARIT_PORTRAIT.w && f.h === GABARIT_PORTRAIT.h)).toBe(true)
    expect(chevauchements(d)).toEqual([])
    const m = mesurer(d, d.groupes)
    expect(m.ecartMinFiches).toBeGreaterThanOrEqual(ECART_COUPLE)
    expect(m.sousUneCarte).toBe(0)
  })
})

describe('sur un arbre synthétique de 1 000 personnes', () => {
  const graphe = parDefaut(brancherCommeLeServeur(genealogieSynthetique({ personnes: 1000, graine: 7 })))
  const d = disposerEnRevue(graphe)
  const m = mesurer(d, d.groupes)

  it('ne passe sous aucune carte, et ne touche aucune carte', () => {
    expect(m.sousUneCarte).toBe(0)
    expect(chevauchements(d)).toEqual([])
    expect(m.ecartMinFiches).toBeGreaterThanOrEqual(ECART_COUPLE)
  })

  it('garde ses rectangles disjoints', () => {
    expect(rectanglesQuiSeChevauchent(d)).toEqual([])
  })

  it('croise bien moins que /arbre sur le même arbre', () => {
    const arbre = disposerEnGroupes(graphe)
    expect(m.croisements).toBeLessThan(mesurer(arbre, arbre.groupes).croisements)
  })

  it('est déterministe : le même arbre donne le même dessin', () => {
    const encore = disposerEnRevue(graphe)
    expect(encore.fiches.map((f) => `${f.id}@${f.x},${f.y}`)).toEqual(d.fiches.map((f) => `${f.id}@${f.x},${f.y}`))
    expect(encore.traces).toEqual(d.traces)
    expect(encore.groupes).toEqual(d.groupes)
  })
})
