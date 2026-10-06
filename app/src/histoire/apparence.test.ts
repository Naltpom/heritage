import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudPersonne } from '@/lib/api'
import { ALPHA_HORS_FILTRE, ALPHA_HORS_RECHERCHE, alphaDeFiche, styleDeFiche, stylesDesTraits } from './apparence'
import { attenuer, couleurBranche, libelleBranche } from './branches'
import { anneauDe, libellesDesFiches, libellesDesFoyers } from './libelles'
import { disposerLignees, type FicheHistoire } from './lignees'
import { cadrageDesCibles } from './useRechercheArbre'
import { CLE_FRATRIES, ecrirePreference, lirePreference } from './usePreferencesArbre'
import type { Canevas } from './vue'

/**
 * Ce que ces tests protègent : ce qui a été sorti de `HistoireView` en fonctions
 * pures — l'apparence des boîtes et des traits (sans `opacity`, ADR 0007), les
 * textes posés par-dessus, le cadrage des cibles d'une recherche, les préférences.
 */
function personne(id: string, nom: string, sex: NoeudPersonne['sex'], vivant = false): NoeudPersonne {
  return {
    id,
    type: 'personne',
    personId: Number(id.slice(1)),
    x: 0,
    y: 0,
    w: 140,
    h: 56,
    displayName: nom,
    etiquetteNom: nom,
    sex,
    generation: 0,
    branch: 'pp',
    vivant,
    visibilite: 'complete',
    etiquetteDates: '1912 – 1989',
  }
}

function fiches(): FicheHistoire[] {
  const aretes: AreteGraphe[] = [
    { de: 'p2', vers: 'f1', genre: 'union' },
    { de: 'p3', vers: 'f1', genre: 'union' },
    { de: 'f1', vers: 'p1', genre: 'filiation' },
  ]
  const g: Graphe = {
    empreinte: '0'.repeat(64),
    ego: 'p1',
    boite: { x: 0, y: 0, w: 1, h: 1 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds: [
      personne('p1', 'Opale Bourrache', 'feminin', true),
      personne('p2', 'Marne Bourrache', 'masculin'),
      personne('p3', 'Ambre Sureau', 'feminin'),
      { id: 'f1', type: 'famille', familyId: 1, x: 0, y: 0, w: 10, h: 10, divorced: false },
    ],
    aretes,
  }
  return disposerLignees(g).fiches
}

const canevas: Canevas = { largeur: 1200, hauteur: 700, largeurMonde: 2000, hauteurMonde: 400 }

describe('attenuer', () => {
  it('rend la couleur telle quelle à 1, et la mêle de transparent sinon', () => {
    expect(attenuer('var(--h-homme)', 1)).toBe('var(--h-homme)')
    expect(attenuer('var(--h-homme)', 0.25)).toBe('color-mix(in srgb, var(--h-homme) 25%, transparent)')
    expect(attenuer('red', 0.7 * 0.1)).toBe('color-mix(in srgb, red 7%, transparent)')
  })

  it('libelle et teinte les branches, inconnues comprises', () => {
    expect(couleurBranche('pp')).toBe('var(--h-branche-pp)')
    expect(couleurBranche('zz')).toBe('var(--h-branche-hors)')
    expect(libelleBranche('tr')).toBe('Tronc')
    expect(libelleBranche('zz')).toBe('Hors branche')
  })
})

describe('l’apparence d’une boîte', () => {
  const [f] = fiches()

  it('estompe hors filtre, puis hors recherche, sinon rien', () => {
    expect(alphaDeFiche(false, new Set(), 'p1')).toBe(ALPHA_HORS_FILTRE)
    expect(alphaDeFiche(true, new Set(['p2']), 'p1')).toBe(ALPHA_HORS_RECHERCHE)
    expect(alphaDeFiche(true, new Set(['p1']), 'p1')).toBe(1)
    expect(alphaDeFiche(true, new Set(), 'p1')).toBe(1)
  })

  it('n’a jamais de clé `opacity` : l’estompage est dans la couleur', () => {
    for (const ensemble of [true, false]) {
      for (const alpha of [1, ALPHA_HORS_RECHERCHE, ALPHA_HORS_FILTRE]) {
        const style = styleDeFiche(f!, { ensemble, portrait: false, alpha, place: null, branche: 'pp' })
        expect(Object.keys(style)).not.toContain('opacity')
        if (alpha < 1) expect(style.background).toContain('transparent')
      }
    }
  })

  it('garde les couleurs d’origine à pleine opacité', () => {
    const style = styleDeFiche(f!, { ensemble: false, portrait: false, alpha: 1, place: null, branche: 'pp' })
    expect(style.background).toBe(`var(--h-${f!.noeud.sex === 'feminin' ? 'femme' : 'homme'})`)
    expect(style.borderLeft).toBe('4px solid var(--h-branche-pp)')
    expect(style.borderRadius).toBe('7px')
  })

  it('pose le liseré en haut en Portrait, et la teinte de branche au niveau Ensemble', () => {
    expect(styleDeFiche(f!, { ensemble: false, portrait: true, alpha: 1, place: null, branche: 'mm' }).borderTop).toBe(
      '4px solid var(--h-branche-mm)',
    )
    expect(styleDeFiche(f!, { ensemble: true, portrait: false, alpha: 1, place: null, branche: 'mm' }).background).toBe(
      'color-mix(in srgb, var(--h-branche-mm) 70%, transparent)',
    )
  })
})

describe('les traits', () => {
  it('passent par `stroke-opacity`, jamais `opacity`', () => {
    for (const niveau of [0, 1, 2] as const) {
      for (const s of [true, false]) {
        for (const style of Object.values(stylesDesTraits(niveau, s, s))) {
          expect(Object.keys(style)).toEqual(['stroke', 'stroke-width', 'stroke-opacity'])
        }
      }
    }
  })

  it('reprennent les valeurs d’avant le découpage', () => {
    expect(stylesDesTraits(0, false, false).ordinaires['stroke-opacity']).toBe('0.35')
    expect(stylesDesTraits(0, true, false).ordinaires['stroke-opacity']).toBe('0')
    expect(stylesDesTraits(1, false, false).ordinaires['stroke-opacity']).toBe('0.55')
    expect(stylesDesTraits(2, false, false).divorces['stroke-opacity']).toBe('0.7')
    expect(stylesDesTraits(0, false, false).divorces['stroke-opacity']).toBe('0')
    expect(stylesDesTraits(0, true, true).directs).toEqual({ stroke: 'var(--h-accent)', 'stroke-width': '2.5', 'stroke-opacity': '1' })
  })
})

describe('les textes posés sur les boîtes', () => {
  const fs = fiches()
  const base = {
    fiches: fs,
    canevas,
    portrait: false,
    directe: () => false,
    estompe: (f: FicheHistoire) => f.id !== 'p1',
  }

  it('ne pose rien au niveau Ensemble, des photos seules au niveau Familles', () => {
    expect(libellesDesFiches({ ...base, cadrage: { s: 0.1, tx: 0, ty: 0 }, niveau: 0 })).toEqual([])
    const familles = libellesDesFiches({ ...base, cadrage: { s: 0.3, tx: 0, ty: 0 }, niveau: 1 })
    expect(familles.length).toBe(fs.length)
    for (const l of familles) expect(l.forme === 'compact' && l.t1).toBe('')
  })

  it('écrit le nom au niveau Fiches, à la place de la boîte, et dit qui est estompé', () => {
    const cadrage = { s: 1, tx: 40, ty: 30 }
    const ls = libellesDesFiches({ ...base, cadrage, niveau: 2 })
    const moi = ls.find((l) => l.id === 'p1')!
    const f = fs.find((x) => x.id === 'p1')!
    expect(moi.forme).toBe('compact')
    expect(moi.forme === 'compact' && moi.t1).toBe('Opale Bourrache')
    expect(moi.y).toBe(30 + f.y)
    expect(moi.estompe).toBe(false)
    expect(ls.find((l) => l.id !== 'p1')?.estompe).toBe(true)
  })

  it('ne pose rien loin hors de la fenêtre', () => {
    expect(libellesDesFiches({ ...base, cadrage: { s: 1, tx: -100000, ty: 0 }, niveau: 2 })).toEqual([])
    expect(libellesDesFoyers([], { s: 0.3, tx: 0, ty: 0 }, canevas, 2, false)).toEqual([])
  })

  it('entoure une fiche visible, pas une fiche hors champ', () => {
    const f = fs[0]!
    expect(anneauDe(f, { s: 1, tx: 0, ty: 0 }, canevas, 4)).toMatchObject({ x: f.x - 4, w: f.w + 8 })
    expect(anneauDe(f, { s: 1, tx: -100000, ty: 0 }, canevas, 4)).toBeNull()
  })
})

describe('le cadrage des cibles d’une recherche', () => {
  it('englobe toutes les cibles, sans dépasser l’échelle 1', () => {
    const fs = fiches()
    const c = cadrageDesCibles(fs, 900)!
    const x0 = Math.min(...fs.map((f) => f.x))
    const x1 = Math.max(...fs.map((f) => f.x + f.w))
    expect(c.x).toBe((x0 + x1) / 2)
    expect(c.s).toBeLessThanOrEqual(1)
    expect(cadrageDesCibles([], 900)).toBeNull()
  })
})

describe('les préférences de l’arbre', () => {
  it('lit le défaut quand rien n’est écrit, et « masquee » comme non', () => {
    const memoire = new Map<string, string>()
    const stockage = { getItem: (c: string) => memoire.get(c) ?? null, setItem: (c: string, v: string) => void memoire.set(c, v) }
    expect(lirePreference(CLE_FRATRIES, false, stockage)).toBe(false)
    ecrirePreference(CLE_FRATRIES, true, stockage)
    expect(lirePreference(CLE_FRATRIES, false, stockage)).toBe(true)
    ecrirePreference(CLE_FRATRIES, false, stockage)
    expect(lirePreference(CLE_FRATRIES, true, stockage)).toBe(false)
  })

  it('ne lève pas quand le navigateur refuse le stockage', () => {
    const refus = {
      getItem: () => {
        throw new Error('SecurityError')
      },
      setItem: () => {
        throw new Error('QuotaExceededError')
      },
    }
    expect(lirePreference(CLE_FRATRIES, true, refus)).toBe(true)
    expect(() => ecrirePreference(CLE_FRATRIES, true, refus)).not.toThrow()
  })
})
