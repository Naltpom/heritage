import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Branche, Graphe, NoeudFamille, NoeudPersonne, Parente, Sexe } from '@/lib/api'
import { sansFamillesParAlliance } from '@/histoire/alliances'
import { genealogieSynthetique } from '@/histoire/banc/genealogie'
import { auFormat, GABARIT_PORTRAIT } from '@/histoire/cartes'
import { sansFamilleDuConjoint, type FicheHistoire } from '@/histoire/lignees'
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

/**
 * Les familles dont un trait traverse un rectangle de part en part, et si chacune relie
 * plusieurs groupes (un parent d'un quart, son enfant dans le tronc : le seul lien qui doit
 * traverser, depuis qu'il n'y a plus qu'un rectangle par quart).
 */
function traversees(d: DispositionGroupes): { famille: string; entreGroupes: boolean }[] {
  const groupeDe = (id: string): string | undefined => {
    const f = d.parId.get(id)
    if (!f) return undefined
    return d.groupes.find((r) => f.cx >= r.x && f.cx <= r.x + r.w && f.y >= r.y && f.y + f.h <= r.y + r.h)?.cle
  }
  const sortie: { famille: string; entreGroupes: boolean }[] = []
  for (const t of d.traces.familles)
    for (const [x1, y1, x2, y2] of segmentsDe(t.d))
      for (const r of d.groupes) {
        const h = y1 === y2 && y1 > r.y && y1 < r.y + r.h && Math.min(x1, x2) < r.x && Math.max(x1, x2) > r.x + r.w
        const v = x1 === x2 && x1 > r.x && x1 < r.x + r.w && Math.min(y1, y2) < r.y && Math.max(y1, y2) > r.y + r.h
        if (h || v) sortie.push({ famille: t.id, entreGroupes: new Set(t.personnes.map(groupeDe)).size > 1 })
      }
  return sortie
}

/** L'écart entre le centre d'une fratrie (conjoints de son foyer compris) et le milieu de ses parents. */
function ecartSousLesParents(d: DispositionGroupes, parents: string[], enfants: string[], conjoints: string[] = []): number {
  const p = parents.map((id) => d.parId.get(id)!)
  const membres = [...enfants, ...conjoints].map((id) => d.parId.get(id)!)
  const lo = Math.min(...membres.map((f) => f.x))
  const hi = Math.max(...membres.map((f) => f.x + f.w))
  return Math.abs((lo + hi) / 2 - p.reduce((t, f) => t + f.cx, 0) / p.length)
}

/**
 * Les fratries qui ne sont pas centrées sous leurs parents alors que leur place est libre :
 * la fratrie (ses conjoints posés à côté compris), centrée, garderait l'écart d'une famille
 * avec ses voisines de rangée (176 px), et celui de deux rectangles (2 cartes et 2 marges)
 * avec une fiche d'un autre rectangle. On laisse de côté ce que l'alignement, par principe,
 * ne déplace pas : une fratrie coupée par une autre famille, posée hors du rectangle de ses
 * deux parents (la mère, dans le tronc), ou qui partage un couple avec une autre fratrie (le
 * couple de la génération suivante, posé ENTRE ses deux couples de parents).
 */
function fratriesLibresDecentrees(d: DispositionGroupes, graphe: Graphe): string[] {
  const epoux = new Map<string, string[]>()
  const enfants = new Map<string, string[]>()
  const parentsDe = new Map<string, string>()
  const unions = new Map<string, string[]>()
  for (const a of graphe.aretes) {
    if (a.genre === 'union') {
      epoux.set(a.vers, [...(epoux.get(a.vers) ?? []), a.de])
      unions.set(a.de, [...(unions.get(a.de) ?? []), a.vers])
    } else {
      enfants.set(a.de, [...(enfants.get(a.de) ?? []), a.vers])
      parentsDe.set(a.vers, a.de)
    }
  }
  const rectDe = (f: FicheHistoire): string | undefined =>
    d.groupes.find((r) => f.cx >= r.x && f.cx <= r.x + r.w && f.y >= r.y && f.y + f.h <= r.y + r.h)?.cle
  const lignes = new Map<number, FicheHistoire[]>()
  for (const f of d.fiches) lignes.set(f.rangee, [...(lignes.get(f.rangee) ?? []), f])
  for (const l of lignes.values()) l.sort((a, b) => a.x - b.x)
  const ecartDeRectangles = 2 * 160 + 2 * 22
  const fautes: string[] = []
  for (const [fid, ens] of enfants) {
    const ep = (epoux.get(fid) ?? []).map((id) => d.parId.get(id)).filter((f): f is FicheHistoire => !!f)
    if (!ep.length) continue
    const rf = Math.max(...ep.map((f) => f.rangee))
    const parents = ep.filter((f) => f.rangee === rf)
    const fratrie = ens.map((id) => d.parId.get(id)).filter((f): f is FicheHistoire => !!f && f.rangee === rf + 1)
    if (!fratrie.length) continue
    const ligne = lignes.get(rf + 1)!
    const groupe = new Set(fratrie.map((f) => f.id))
    let encore = true
    while (encore) {
      encore = false
      for (const id of [...groupe])
        for (const u of unions.get(id) ?? [])
          for (const c of epoux.get(u) ?? []) {
            const fc = d.parId.get(c)
            if (!fc || groupe.has(c) || fc.rangee !== rf + 1) continue
            const fi = d.parId.get(id)!
            const i = ligne.indexOf(fc)
            const j = ligne.indexOf(fi)
            // Posé à côté : voisin dans la rangée, et plus près qu'un écart de deux familles.
            if (Math.abs(i - j) !== 1 || Math.max(fc.x - (fi.x + fi.w), fi.x - (fc.x + fc.w)) >= 176) continue
            groupe.add(c)
            encore = true
          }
    }
    const membres = [...groupe].map((id) => d.parId.get(id)!).sort((a, b) => a.x - b.x)
    // Hors du champ de l'alignement : partagée avec une autre fratrie, coupée, hors du rectangle des parents.
    if (membres.some((m) => !ens.includes(m.id) && parentsDe.has(m.id) && (enfants.get(parentsDe.get(m.id)!) ?? []).some((x) => d.parId.get(x)?.rangee === rf + 1))) continue
    const i0 = ligne.indexOf(membres[0]!)
    const i1 = ligne.indexOf(membres[membres.length - 1]!)
    if (i1 - i0 + 1 !== membres.length) continue
    const rect = rectDe(membres[0]!)
    if (membres.some((m) => rectDe(m) !== rect) || !parents.some((p) => rectDe(p) === rect)) continue
    const milieu = parents.reduce((t, f) => t + f.cx, 0) / parents.length
    const largeur = membres[membres.length - 1]!.x + membres[membres.length - 1]!.w - membres[0]!.x
    const centre = membres[0]!.x + largeur / 2
    const ecart = Math.abs(centre - milieu)
    if (ecart < 1) continue
    const a = milieu - largeur / 2
    const b = milieu + largeur / 2
    const g = ligne[i0 - 1]
    const dr = ligne[i1 + 1]
    // Une voisine d'un autre rectangle : c'est son rectangle entier qu'il faut laisser à distance
    // (deux rectangles ne se chevauchent sur aucune rangée).
    const boite = (f: FicheHistoire) => d.groupes.find((r) => r.cle === rectDe(f))!
    const libreG = !g || (rectDe(g) === rect ? a - (g.x + g.w) >= 176 : a - (boite(g).x + boite(g).w - 22) >= ecartDeRectangles)
    const libreD = !dr || (rectDe(dr) === rect ? dr.x - b >= 176 : boite(dr).x + 22 - b >= ecartDeRectangles)
    if (libreG && libreD) fautes.push(`${fid} : ${Math.round(ecart)} px`)
  }
  return fautes
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

  it('ne passe sous aucune carte ; seuls les liens entre deux groupes traversent un rectangle', () => {
    expect(m.sousUneCarte).toBe(0)
    // Un rectangle par quart, comme sur `/arbre` : le lien du père (de la mère, du conjoint)
    // vers le couple de ses parents passe au travers du quart qui les sépare — et lui seul.
    const t = traversees(d)
    for (const x of t) expect(x.entreGroupes, x.famille).toBe(true)
  })

  it('ne croise que là où c’est inévitable, et garde les couples côte à côte', () => {
    // Le lien des parents du père (de la mère) vers lui traverse le quart d'entre eux et le
    // tronc, et peut y couper une chute — celle du cousin de la grand-mère paternelle, celle
    // de la demi-tante. L'ordre des rectangles est choisi pour en couper le moins ; rien
    // d'autre ne se croise.
    const peres = familleDe(d, id.gpp!, id.gmp!)
    const meres = familleDe(d, id.gpm!, id.gmm!)
    const paires = croisementsEntre(d)
    expect(paires.length).toBeLessThanOrEqual(2)
    for (const paire of paires) expect(paire.includes(peres) || paire.includes(meres)).toBe(true)
    expect(m.croisements).toBe(paires.length)
    expect(m.chevauchements).toBe(0)
    expect(m.couplesSepares).toBe(0)
  })

  it('sans la famille du conjoint, le tronc au milieu : au plus un croisement par quart intérieur', () => {
    const sans = disposerEnRevue(parDefaut(sansFamilleDuConjoint(graphe)))
    const paires = croisementsEntre(sans)
    // Le cousin de la grand-mère paternelle sous le lien du père, la demi-tante sous celui de la mère.
    expect(paires.length).toBeLessThanOrEqual(2)
    for (const paire of paires) expect(paire.includes(familleDe(sans, id.gpp!, id.gmp!)) || paire.includes(familleDe(sans, id.gpm!, id.gmm!))).toBe(true)
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

  it('un seul rectangle par quart, ses descendants dedans (demande du 22/10/2026)', () => {
    for (const cle of ['pp', 'pm', 'mp', 'mm', 'tr', 'cpp', 'cpm', 'cmp', 'cmm']) expect(d.groupes.filter((r) => r.cle === cle).length, cle).toBeLessThanOrEqual(1)
    expect(d.groupes.some((r) => r.libelle.includes('descendance'))).toBe(false)
    // Le grand-père et son fils l'oncle, et les enfants de l'oncle : un même rectangle.
    const pp = d.groupes.find((r) => r.cle === 'pp')!
    for (const cle of ['gpp', 'oncle', 'cousin1', 'cousin2']) {
      const f = d.parId.get(id[cle]!)!
      expect(f.x >= pp.x && f.x + f.w <= pp.x + pp.w && f.y >= pp.y && f.y + f.h <= pp.y + pp.h, cle).toBe(true)
    }
  })

  it('centre chaque fratrie sous ses parents quand la place est libre', () => {
    // Les cousins sous l'oncle et sa femme ; le cousin de la grand-mère sous son grand-oncle ;
    // l'enfant de l'ego sous l'ego et sa femme.
    expect(ecartSousLesParents(d, [id.oncle!, id.tanteAlliee!], [id.cousin1!, id.cousin2!])).toBeLessThan(1)
    expect(ecartSousLesParents(d, [id.grandOncleD!], [id.cousinD!])).toBeLessThan(1)
    expect(ecartSousLesParents(d, [id.ego!, id.conjoint!], [id.enfant!])).toBeLessThan(1)
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

describe('les fratries sous leurs parents (demande du 22/10/2026)', () => {
  it('une fratrie et le conjoint de l’un d’eux, centrés ensemble sous les parents', () => {
    const a = nouvelArbre()
    const ego = a.personne('ego', 'masculin', 1990, 'tr')
    const pere = a.personne('pere', 'masculin', 1960, 'tr')
    const mere = a.personne('mere', 'feminin', 1962, 'tr')
    a.famille([pere, mere], [ego])
    // Dans le quart du grand-père paternel : un grand-oncle, sa femme, leurs deux enfants,
    // dont l'un marié (sa femme, sans parents posés).
    const gp = a.personne('gp', 'masculin', 1930, 'pp')
    const gm = a.personne('gm', 'feminin', 1932, 'pm')
    a.famille([gp, gm], [pere])
    const bisaieul = a.personne('bisaieul', 'masculin', 1900, 'pp')
    const bisaieule = a.personne('bisaieule', 'feminin', 1902, 'pp')
    const grandOncle = a.personne('grandOncle', 'masculin', 1935, 'pp')
    a.famille([bisaieul, bisaieule], [gp, grandOncle])
    const grandTanteAlliee = a.personne('grandTanteAlliee', 'feminin', 1937, 'pp')
    const christine = a.personne('christine', 'feminin', 1961, 'pp')
    const jerome = a.personne('jerome', 'masculin', 1963, 'pp')
    const carine = a.personne('carine', 'feminin', 1964, 'pp')
    a.famille([grandOncle, grandTanteAlliee], [christine, jerome])
    a.famille([jerome, carine], [])
    const d = disposerEnRevue(a.finir(ego))
    expect(ecartSousLesParents(d, [grandOncle, grandTanteAlliee], [christine, jerome], [carine])).toBeLessThan(1)
    expect(mesurer(d, d.groupes).sousUneCarte).toBe(0)
  })

  it('deux couples de la même rangée, chacun ses enfants juste dessous quand la place y est', () => {
    // Le cas des captures : deux sœurs du grand-père, mariées, et leurs enfants — la place
    // sous chaque couple est libre (une fratrie de trois sous un couple seul au bout de sa
    // rangée, un enfant sous l'autre), chaque fratrie y est, droite.
    const a = nouvelArbre()
    const ego = a.personne('ego', 'masculin', 1990, 'tr')
    const pere = a.personne('pere', 'masculin', 1960, 'tr')
    const mere = a.personne('mere', 'feminin', 1962, 'tr')
    a.famille([pere, mere], [ego])
    const gpm = a.personne('gpm', 'masculin', 1930, 'mp')
    const gmm = a.personne('gmm', 'feminin', 1932, 'mm')
    a.famille([gpm, gmm], [mere])
    const s1 = a.personne('soeur1', 'feminin', 1931, 'mp')
    const s2 = a.personne('soeur2', 'feminin', 1934, 'mp')
    a.famille([a.personne('bisaieul', 'masculin', 1900, 'mp'), a.personne('bisaieule', 'feminin', 1902, 'mp')], [s1, s2, gpm])
    const m1 = a.personne('mari1', 'masculin', 1929, 'mp')
    const m2 = a.personne('mari2', 'masculin', 1933, 'mp')
    const e1 = [a.personne('c1', 'feminin', 1955, 'mp')]
    const e2 = [a.personne('d1', 'masculin', 1960, 'mp')]
    a.famille([m1, s1], e1)
    a.famille([m2, s2], e2)
    const d = disposerEnRevue(a.finir(ego))
    expect(ecartSousLesParents(d, [m1, s1], e1)).toBeLessThan(1)
    expect(ecartSousLesParents(d, [m2, s2], e2)).toBeLessThan(1)
  })

  it('sur un arbre synthétique, toute fratrie dont la place est libre est centrée sous ses parents', () => {
    // « Libre » : la fratrie, centrée sous ses parents, garderait l'écart d'une famille avec
    // ses voisines de rangée (et celui de deux rectangles avec un autre rectangle).
    for (const [personnes, graine] of [
      [1000, 7],
      [4000, 42],
    ] as const) {
      const graphe = parDefaut(brancherCommeLeServeur(genealogieSynthetique({ personnes, graine })))
      const d = disposerEnRevue(graphe)
      expect(fratriesLibresDecentrees(d, graphe), `${personnes}`).toEqual([])
    }
    // Deux dispositions entières, dont une de 4 000 personnes : quelques secondes sur une
    // machine occupée, au-delà des 5 s par défaut.
  }, 60_000)

  it('dans l’ascendance, le couple de la génération suivante entre ses deux couples de parents', () => {
    // Un arrière-grand-père remarié : sa première femme lui donne la future épouse, la
    // seconde cinq enfants. L'époux, lui, a ses parents à gauche. Le couple se pose entre les
    // deux couples de parents, ses demi-sœurs de l'autre côté — et rien ne se croise.
    const a = nouvelArbre()
    const ego = a.personne('ego', 'masculin', 1990, 'tr')
    const pere = a.personne('pere', 'masculin', 1960, 'tr')
    const mere = a.personne('mere', 'feminin', 1962, 'tr')
    a.famille([pere, mere], [ego])
    const gpm = a.personne('gpm', 'masculin', 1930, 'mp')
    const gmm = a.personne('gmm', 'feminin', 1932, 'mm')
    a.famille([gpm, gmm], [mere])
    const jp = a.personne('jeanPierre', 'masculin', 1900, 'mm')
    const constance = a.personne('constance', 'feminin', 1902, 'mm')
    a.famille([jp, constance], [gmm])
    const pereJp = a.personne('pereJP', 'masculin', 1870, 'mm')
    const mereJp = a.personne('mereJP', 'feminin', 1872, 'mm')
    a.famille([pereJp, mereJp], [jp])
    const coffy = a.personne('coffy', 'masculin', 1868, 'mm')
    const larcillon = a.personne('larcillon', 'feminin', 1870, 'mm')
    const mariage = a.personne('mariage', 'feminin', 1875, 'mm')
    const rosalie = a.personne('rosalie', 'feminin', 1904, 'mm')
    a.famille([coffy, larcillon], [constance, rosalie])
    const demis = ['h1', 'h2', 'h3', 'h4', 'h5'].map((k, i) => a.personne(k, i % 2 ? 'masculin' : 'feminin', 1906 + i, 'mm'))
    a.famille([coffy, mariage], demis)
    const d = disposerEnRevue(a.finir(ego))
    const x = (id: string): number => d.parId.get(id)!.cx
    const couple = (x(jp) + x(constance)) / 2
    const parentsJp = (x(pereJp) + x(mereJp)) / 2
    const parentsConstance = (x(coffy) + x(larcillon)) / 2
    expect(couple).toBeGreaterThan(Math.min(parentsJp, parentsConstance) - 1)
    expect(couple).toBeLessThan(Math.max(parentsJp, parentsConstance) + 1)
    expect(mesurer(d, d.groupes).croisements).toBe(0)
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
      for (const x of traversees(d)) expect(x.entreGroupes, `${alliances} ${x.famille}`).toBe(true)
      expect(d.alliances.length > 0, alliances).toBe(alliances === 'a-part')
    }
  })

  it('les personnes proposées (en attente) : dans le groupe de qui les rattache, leurs liens en pointillés', () => {
    const { graphe, id } = arbreDEssai()
    const propose = (cle: string, sex: Sexe): NoeudPersonne => ({
      id: `#9/${cle}`,
      type: 'personne',
      personId: null,
      enAttente: true,
      x: 0,
      y: 0,
      w: 140,
      h: 56,
      displayName: cle,
      etiquetteNom: `${cle} Proposé`,
      sex,
      generation: 0,
      branch: null as unknown as Branche,
      parente: 'famille',
      vivant: false,
      visibilite: 'complete',
    })
    const epouse = propose('epouse', 'feminin')
    const fils = propose('fils', 'masculin')
    const f: NoeudFamille = { id: 'f-propose', type: 'famille', familyId: 9999, x: 0, y: 0, w: 10, h: 10, divorced: false }
    const avec: Graphe = {
      ...graphe,
      noeuds: [...graphe.noeuds, epouse, fils, f],
      aretes: [
        ...graphe.aretes,
        { de: id.grandOncleD!, vers: f.id, genre: 'union' },
        { de: epouse.id, vers: f.id, genre: 'union', enAttente: true },
        { de: f.id, vers: fils.id, genre: 'filiation', enAttente: true },
      ],
    }
    const d = disposerEnRevue(parDefaut(avec))
    for (const n of [epouse, fils]) {
      const fiche = d.parId.get(n.id)!
      const rect = d.groupes.find((r) => fiche.x >= r.x && fiche.x + fiche.w <= r.x + r.w && fiche.y >= r.y && fiche.y + fiche.h <= r.y + r.h)
      expect(rect?.cle, n.id).toBe('pm')
    }
    expect(d.traces.attente).not.toBe('')
    expect(mesurer(d, d.groupes).sousUneCarte).toBe(0)
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
  }, 30_000)
})
