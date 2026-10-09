import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Branche, Graphe, NoeudFamille, NoeudPersonne, Parente, Sexe } from '@/lib/api'
import { sansFamillesParAlliance } from '@/histoire/alliances'
import { sansBranches } from '@/histoire/branches'
import { auFormat, GABARIT_PORTRAIT } from '@/histoire/cartes'
import { sansFamilleDuConjoint } from '@/histoire/lignees'
import { noeudsSansLien, sansPersonnesSansLien } from '@/histoire/sansLien'
import { compterCroisements, isotone } from './agencement'
import { disposerEnGroupes, type DispositionGroupes } from './groupes'

/**
 * Ce que ces tests protègent : les règles de `/arbre-2` — chaque famille dans son
 * rectangle, les rectangles séparés de deux cartes au moins, une génération par rangée,
 * les couples côte à côte, et des filtres qui retirent un rectangle sans rien casser.
 */

let prochain = 1

function construire(): { graphe: Graphe; id: Record<string, string> } {
  prochain = 1
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const id: Record<string, string> = {}

  const personne = (cle: string, nom: string, sex: Sexe, annee: number | null, branch: Branche, parente: Parente = 'famille'): string => {
    const n = prochain++
    const p: NoeudPersonne = {
      id: `p${n}`,
      type: 'personne',
      personId: n,
      x: 0,
      y: 0,
      w: 140,
      h: 56,
      displayName: nom,
      etiquetteNom: nom,
      sex,
      generation: 0,
      branch,
      parente,
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

  // Le tronc.
  const ego = personne('ego', 'Nathan Provost', 'masculin', 1998, 'tr')
  const pere = personne('pere', 'Laurent Provost', 'masculin', 1969, 'tr')
  const mere = personne('mere', 'Emmanuelle Voulgre', 'feminin', 1971, 'tr')
  const soeur = personne('soeur', 'Lucie Provost', 'feminin', 2001, 'tr')
  famille([pere, mere], [soeur, ego])
  const conjoint = personne('conjoint', 'Camille Durand', 'feminin', 1999, 'tr')
  const enfant = personne('enfant', 'Arthur Provost', 'masculin', 2024, 'tr')
  famille([ego, conjoint], [enfant])

  // pp : le père du père, ses ascendants, ses enfants autres que le père et leurs familles.
  const gpp = personne('gpp', 'Daniel Provost', 'masculin', 1946, 'pp')
  const tante = personne('tante', 'Céline Provost', 'feminin', 1972, 'pp')
  const oncle = personne('oncle', 'Stéphane Provost', 'masculin', 1977, 'pp')
  const tanteAlliee = personne('tanteAlliee', 'Anne Martin', 'feminin', 1978, 'pp')
  const cousin1 = personne('cousin1', 'Hugo Provost', 'masculin', 2004, 'pp')
  const cousin2 = personne('cousin2', 'Léa Provost', 'feminin', 2007, 'pp')
  famille([oncle, tanteAlliee], [cousin1, cousin2])
  const agp = personne('agp', 'Alain Provost', 'masculin', 1913, 'pp')
  const agm = personne('agm', 'Aimée Samara', 'feminin', 1917, 'pp')
  const grandTante = personne('grandTante', 'Solange Provost', 'feminin', 1943, 'pp')
  famille([agp, agm], [grandTante, gpp])

  // pm : la mère du père et ses parents.
  const gmp = personne('gmp', 'Elisabeth Destombes', 'feminin', 1949, 'pm')
  famille([gpp, gmp], [pere, tante, oncle])
  const agpD = personne('agpD', 'Paul Destombes', 'masculin', 1920, 'pm')
  const agmD = personne('agmD', 'Rose Leroy', 'feminin', 1922, 'pm')
  const grandOncleD = personne('grandOncleD', 'Marc Destombes', 'masculin', 1951, 'pm')
  const cousinD = personne('cousinD', 'Luc Destombes', 'masculin', 1980, 'pm')
  famille([agpD, agmD], [gmp, grandOncleD])
  famille([grandOncleD], [cousinD])

  // mp : le père de la mère, sa sœur mariée (dont le mari a des parents : une alliance).
  const gpm = personne('gpm', 'Gabriel Voulgre', 'masculin', 1930, 'mp')
  const agpV = personne('agpV', 'Pierre Voulgre', 'masculin', 1900, 'mp')
  const agmV = personne('agmV', 'Irène Marboeuf', 'feminin', 1903, 'mp')
  const grandTanteV = personne('grandTanteV', 'Marie Voulgre', 'feminin', 1927, 'mp')
  famille([agpV, agmV], [grandTanteV, gpm])
  const allie = personne('allie', 'Jean Vallaize', 'masculin', 1925, 'mp')
  famille([allie, grandTanteV], [])
  const pereAllie = personne('pereAllie', 'Léonard Vallaize', 'masculin', 1898, 'mp', 'alliance')
  const mereAllie = personne('mereAllie', 'Françoise Nadaud', 'feminin', 1900, 'mp', 'alliance')
  famille([pereAllie, mereAllie], [allie])
  // Remariage du grand-père maternel : une demi-tante.
  const secondeEpouse = personne('secondeEpouse', 'Rose Bernard', 'feminin', 1940, 'mp')
  const demiTante = personne('demiTante', 'Odile Voulgre', 'feminin', 1965, 'mp')
  famille([gpm, secondeEpouse], [demiTante], true)

  // mm : la mère de la mère.
  const gmm = personne('gmm', 'Marie Figarol', 'feminin', 1937, 'mm')
  const tanteM = personne('tanteM', 'Nicole Voulgre', 'feminin', 1958, 'mp')
  famille([gpm, gmm], [tanteM, mere])
  const agpF = personne('agpF', 'Louis Figarol', 'masculin', 1905, 'mm')
  const agmF = personne('agmF', 'Jeanne Amelot', 'feminin', 1908, 'mm')
  const grandOncleF = personne('grandOncleF', 'Paul Figarol', 'masculin', 1934, 'mm')
  famille([agpF, agmF], [gmm, grandOncleF])

  // La famille du conjoint.
  const beauPere = personne('beauPere', 'Paul Durand', 'masculin', 1970, 'cpp', 'conjoint')
  const belleMere = personne('belleMere', 'Anne Lefebvre', 'feminin', 1972, 'cmp', 'conjoint')
  const beauFrere = personne('beauFrere', 'Hugo Durand', 'masculin', 2001, 'cpp', 'conjoint')
  famille([beauPere, belleMere], [conjoint, beauFrere])
  const gpcp = personne('gpcp', 'Louis Durand', 'masculin', 1940, 'cpp', 'conjoint')
  const gmcp = personne('gmcp', 'Marthe Roux', 'feminin', 1942, 'cpm', 'conjoint')
  famille([gpcp, gmcp], [beauPere])
  const gpcm = personne('gpcm', 'Henri Lefebvre', 'masculin', 1941, 'cmp', 'conjoint')
  const gmcm = personne('gmcm', 'Irène Colin', 'feminin', 1944, 'cmm', 'conjoint')
  famille([gpcm, gmcm], [belleMere])

  // Un couple détaché de l'arbre, avec des dates.
  const antoine = personne('antoine', 'Antoine Leonard', 'masculin', 1912, 'tr')
  const jeanne = personne('jeanne', 'Jeanne Leonard', 'feminin', 1915, 'tr')
  const jean = personne('jean', 'Jean Leonard', 'masculin', 1941, 'tr')
  famille([antoine, jeanne], [jean])

  for (const n of noeuds) if (n.type === 'personne' && n.parente === 'alliance') n.pont = allie

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

/** Le chemin de l'écran : alliances, conjoint, sans lien, branches — puis le moteur. */
function filtrer(
  graphe: Graphe,
  { alliances = true, conjoint = true, sansLien = true, masquees = [] as Parameters<typeof sansBranches>[1] } = {},
): Graphe {
  const a = alliances ? graphe : sansFamillesParAlliance(graphe)
  const c = conjoint ? a : sansFamilleDuConjoint(a)
  const s = sansLien ? c : sansPersonnesSansLien(c, noeudsSansLien(graphe))
  return sansBranches(s, masquees, graphe)
}

function chevauchements(d: DispositionGroupes): string[] {
  const parRangee = new Map<number, typeof d.fiches>()
  for (const f of d.fiches) parRangee.set(f.y, [...(parRangee.get(f.y) ?? []), f])
  const fautes: string[] = []
  for (const rangee of parRangee.values()) {
    const tri = [...rangee].sort((a, b) => a.x - b.x)
    for (let i = 1; i < tri.length; i++) if (tri[i]!.x < tri[i - 1]!.x + tri[i - 1]!.w) fautes.push(`${tri[i - 1]!.id} / ${tri[i]!.id}`)
  }
  return fautes
}

const cles = (d: DispositionGroupes): string[] => d.groupes.map((g) => g.cle)

describe('les outils', () => {
  it('la régression isotone rend la suite croissante la plus proche', () => {
    expect(isotone([1, 3, 2, 4], [1, 1, 1, 1])).toEqual([1, 2.5, 2.5, 4])
    expect(isotone([5, 1], [3, 1])).toEqual([4, 4])
  })

  it('compte les croisements, pas les fratries', () => {
    expect(compterCroisements([{ haut: 0, bas: 10 }, { haut: 10, bas: 0 }])).toBe(1)
    expect(compterCroisements([{ haut: 0, bas: 0 }, { haut: 0, bas: 10 }, { haut: 10, bas: 20 }])).toBe(0)
  })
})

describe('disposition en groupes', () => {
  it('pose chaque personne une fois, sans chevauchement', () => {
    const { graphe } = construire()
    const d = disposerEnGroupes(graphe)
    const personnes = graphe.noeuds.filter((n) => n.type === 'personne')
    expect(d.fiches).toHaveLength(personnes.length)
    expect(new Set(d.fiches.map((f) => f.id)).size).toBe(personnes.length)
    expect(chevauchements(d)).toEqual([])
  })

  it('fait onze rectangles quand tout est affiché à part, dans l’ordre de la parenté', () => {
    const { graphe } = construire()
    const d = disposerEnGroupes(graphe)
    expect(cles(d)).toHaveLength(11)
    // Le côté paternel à gauche du tronc (la paire dans le sens qui croise le moins).
    expect([...cles(d).slice(0, 2)].sort()).toEqual(['pm', 'pp'])
    expect(cles(d)[2]).toBe('tr')
    expect(cles(d).slice(-2)).toEqual(['alliances', 'sansLien'])
  })

  it('range chaque fiche dans le rectangle de son groupe', () => {
    const { graphe, id } = construire()
    const d = disposerEnGroupes(graphe)
    const rect = (cle: string) => d.groupes.find((g) => g.cle === cle)!
    const dedans = (fid: string, cle: string): boolean => {
      const f = d.parId.get(fid)!
      const r = rect(cle)
      return f.x >= r.x && f.x + f.w <= r.x + r.w && f.y >= r.y && f.y + f.h <= r.y + r.h
    }
    expect(dedans(id.ego!, 'tr')).toBe(true)
    expect(dedans(id.cousin1!, 'pp')).toBe(true)
    expect(dedans(id.gmp!, 'pm')).toBe(true)
    expect(dedans(id.gmcm!, 'cmm')).toBe(true)
    expect(dedans(id.pereAllie!, 'alliances')).toBe(true)
    expect(dedans(id.jean!, 'sansLien')).toBe(true)
    // Et TOUTES : aucune fiche hors de son rectangle.
    for (const g of d.groupes) {
      const membres = d.fiches.filter((f) => g.x <= f.cx && f.cx <= g.x + g.w)
      for (const f of membres) expect(f.x >= g.x && f.x + f.w <= g.x + g.w, `${f.id} dans ${g.cle}`).toBe(true)
    }
  })

  it('sépare deux rectangles d’au moins deux cartes', () => {
    const { graphe } = construire()
    const d = disposerEnGroupes(graphe)
    expect(d.ecartGroupes).toBe(280)
    const tries = [...d.groupes].sort((a, b) => a.x - b.x)
    for (let i = 1; i < tries.length; i++) expect(tries[i]!.x - (tries[i - 1]!.x + tries[i - 1]!.w)).toBeGreaterThanOrEqual(280 - 0.5)
  })

  it('pose une génération par rangée, pour tout l’arbre', () => {
    const { graphe, id } = construire()
    const d = disposerEnGroupes(graphe)
    const y = (k: string): number => d.parId.get(id[k]!)!.y
    expect(y('cousin1')).toBe(y('ego'))
    expect(y('cousinD')).toBe(y('pere'))
    expect(y('beauFrere')).toBe(y('ego'))
    expect(y('gpp')).toBe(y('gmcm'))
    expect(y('pere')).toBeLessThan(y('ego'))
    expect(y('gpp')).toBeLessThan(y('pere'))
  })

  it('met les grands-parents face à face, de part et d’autre du couloir', () => {
    const { graphe, id } = construire()
    const d = disposerEnGroupes(graphe)
    const f = (k: string) => d.parId.get(id[k]!)!
    const tries = [...d.groupes].sort((x, y) => x.x - y.x)
    const rect = (x: { cx: number }) => tries.find((r) => r.x <= x.cx && x.cx <= r.x + r.w)!
    for (const [a, b] of [
      ['gpp', 'gmp'],
      ['gpm', 'gmm'],
      ['beauPere', 'belleMere'],
    ] as const) {
      const [g, dr] = [f(a), f(b)].sort((x, y) => x.cx - y.cx) as [ReturnType<typeof f>, ReturnType<typeof f>]
      // Deux rectangles VOISINS : l'union ne traverse que le couloir qui les sépare…
      expect(tries.indexOf(rect(dr)) - tries.indexOf(rect(g)), `${a}–${b}`).toBe(1)
      // … et aucune fiche entre les deux époux.
      expect(d.fiches.filter((x) => x.y === g.y && x.cx > g.cx && x.cx < dr.cx).map((x) => x.id), `${a}–${b}`).toEqual([])
    }
  })

  it('garde les couples côte à côte dans un même groupe', () => {
    const { graphe, id } = construire()
    const d = disposerEnGroupes(graphe)
    const f = (k: string) => d.parId.get(id[k]!)!
    for (const [a, b] of [
      ['ego', 'conjoint'],
      ['pere', 'mere'],
      ['oncle', 'tanteAlliee'],
      ['agp', 'agm'],
    ] as const) {
      const [g, dr] = [f(a), f(b)].sort((x, y) => x.x - y.x)
      expect(dr!.x - (g!.x + g!.w), `${a}–${b}`).toBeLessThanOrEqual(12.5)
    }
  })

  it('ne croise aucun lien sur ce petit arbre', () => {
    const { graphe } = construire()
    const d = disposerEnGroupes(filtrer(graphe, { alliances: false, sansLien: false }))
    expect(d.croisements).toBe(0)
  })
})

describe('les fratries par ordre de naissance', () => {
  /** Des parents et quatre enfants : seul (1990), mariée (1995), ego (1998), seul (2001). */
  function fratrie(): { graphe: Graphe; id: Record<string, string> } {
    const noeuds: Graphe['noeuds'] = []
    const aretes: AreteGraphe[] = []
    const id: Record<string, string> = {}
    let n = 1
    const personne = (cle: string, sex: Sexe, annee: number): string => {
      const p: NoeudPersonne = {
        id: `p${n}`,
        type: 'personne',
        personId: n++,
        x: 0,
        y: 0,
        w: 140,
        h: 56,
        displayName: cle,
        etiquetteNom: `${cle} Martin`,
        etiquetteDates: `${annee} – `,
        sex,
        generation: 0,
        branch: 'tr',
        vivant: true,
        visibilite: 'complete',
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
    const pere = personne('pere', 'masculin', 1960)
    const mere = personne('mere', 'feminin', 1962)
    // Dans le désordre du graphe, exprès : seule l'option doit les ranger.
    const d = personne('d', 'masculin', 2001)
    const ego = personne('ego', 'masculin', 1998)
    const b = personne('b', 'feminin', 1995)
    const a = personne('a', 'masculin', 1990)
    famille([pere, mere], [d, ego, b, a])
    const mari = personne('mari', 'masculin', 1993)
    famille([mari, b], [])
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

  it('range l’aîné à gauche, même quand une sœur est mariée', () => {
    const { graphe, id } = fratrie()
    const d = disposerEnGroupes(graphe, { fratriesParNaissance: true })
    const x = (k: string): number => d.parId.get(id[k]!)!.cx
    expect(x('a')).toBeLessThan(x('b'))
    expect(x('b')).toBeLessThan(x('ego'))
    expect(x('ego')).toBeLessThan(x('d'))
    expect(chevauchements(d)).toEqual([])
  })
})

describe('les filtres', () => {
  it('masquer une branche retire son rectangle, et lui seul', () => {
    const { graphe } = construire()
    const tout = disposerEnGroupes(filtrer(graphe))
    const sansPm = disposerEnGroupes(filtrer(graphe, { masquees: ['pm'] }))
    expect(cles(sansPm)).not.toContain('pm')
    expect(cles(tout).filter((c) => c !== 'pm')).toEqual(cles(sansPm))
    expect(chevauchements(sansPm)).toEqual([])
    // Remise : le rectangle revient.
    expect(cles(disposerEnGroupes(filtrer(graphe)))).toEqual(cles(tout))
  })

  it('sans la famille du conjoint, plus aucun rectangle du conjoint', () => {
    const { graphe } = construire()
    const d = disposerEnGroupes(filtrer(graphe, { conjoint: false }))
    expect(cles(d).filter((c) => c.startsWith('c'))).toEqual([])
    expect(cles(d)).toContain('tr')
  })

  it('les familles par alliance : à part, un rectangle ; dans mon arbre, le groupe du pont', () => {
    const { graphe, id } = construire()
    const aPart = disposerEnGroupes(filtrer(graphe))
    expect(cles(aPart)).toContain('alliances')
    expect(aPart.alliances.map((a) => a.pont)).toEqual([id.allie])
    const dansLArbre = disposerEnGroupes(filtrer(graphe), { alliances: 'dans-l-arbre' })
    expect(cles(dansLArbre)).not.toContain('alliances')
    const mp = dansLArbre.groupes.find((g) => g.cle === 'mp')!
    const f = dansLArbre.parId.get(id.pereAllie!)!
    expect(f.x >= mp.x && f.x + f.w <= mp.x + mp.w).toBe(true)
    const masquees = disposerEnGroupes(filtrer(graphe, { alliances: false }))
    expect(cles(masquees)).not.toContain('alliances')
  })

  it('les personnes sans lien : un rectangle quand on les affiche, rien sinon', () => {
    const { graphe } = construire()
    expect(cles(disposerEnGroupes(filtrer(graphe)))).toContain('sansLien')
    const sans = disposerEnGroupes(filtrer(graphe, { sansLien: false }))
    expect(cles(sans)).not.toContain('sansLien')
    expect(sans.zoneDetachee).toBeNull()
  })

  it('le format portrait garde l’écart de deux cartes', () => {
    const { graphe } = construire()
    const d = disposerEnGroupes(auFormat(filtrer(graphe), 'portrait'))
    expect(d.ecartGroupes).toBe(2 * GABARIT_PORTRAIT.w)
    expect(chevauchements(d)).toEqual([])
  })
})
