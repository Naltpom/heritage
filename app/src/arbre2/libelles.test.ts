import { describe, expect, it } from 'vitest'
import { genealogieSynthetique } from '@/histoire/banc/genealogie'
import { auFormat } from '@/histoire/cartes'
import type { FicheHistoire } from '@/histoire/lignees'
import type { Canevas } from '@/histoire/vue'
import {
  ALPHA_HORS_FILTRE,
  ALPHA_HORS_RECHERCHE,
  alphaDeFiche,
  attenuer,
  stylesDesTraits,
  styleDeFiche,
} from './apparence'
import { disposerEnGroupes } from './groupes'
import { MARGE, anneauDe, creerMemoireDesTextes, libellesDesFiches, libellesDesFoyers, type LibelleCompact } from './libelles'

/**
 * Ce que ces tests protègent : ce que `/arbre` écrit par-dessus ses boîtes, et comment
 * il estompe — sans `opacity` (ADR 0007, règle 2), et sans réajuster un seul texte
 * pendant un glisser.
 */
const d = disposerEnGroupes(auFormat(genealogieSynthetique({ personnes: 300, graine: 3 }), 'compact'))
const canevas: Canevas = { largeur: 1400, hauteur: 800, largeurMonde: d.largeur, hauteurMonde: d.hauteur }
const tous = new Set(d.fiches.map((f) => f.id))
const ego = d.parId.get(d.ego)!
/** Le cadrage qui pose l'ego au centre de l'écran, à l'échelle `s`. */
const surEgo = (s: number, dx = 0) => ({ s, tx: canevas.largeur / 2 - ego.cx * s + dx, ty: canevas.hauteur / 2 - ego.y * s })
const parametres = (s: number, dx = 0) => ({
  fiches: d.fiches,
  passent: tous,
  cadrage: surEgo(s, dx),
  canevas,
  niveau: (s >= 0.45 ? 2 : s >= 0.16 ? 1 : 0) as 0 | 1 | 2,
  portrait: false,
  directe: () => false,
  estompe: () => false,
})

describe('les textes des fiches visibles', () => {
  it('n’écrivent que les fiches à l’écran (marge comprise) et qui passent les filtres', () => {
    const l = libellesDesFiches(parametres(1))
    expect(l.length).toBeGreaterThan(0)
    expect(l.length).toBeLessThan(d.fiches.length)
    expect(l.some((x) => x.id === ego.id)).toBe(true)
    const sansEgo = new Set([...tous].filter((id) => id !== ego.id))
    expect(libellesDesFiches({ ...parametres(1), passent: sansEgo }).some((x) => x.id === ego.id)).toBe(false)
    // Au niveau Ensemble, rien ne s'écrit.
    expect(libellesDesFiches(parametres(0.05))).toEqual([])
  })

  it('sont posés jusqu’à la marge au-delà du bord, pour qu’un glisser ne découvre pas des fiches muettes', () => {
    const avec = libellesDesFiches(parametres(1)).length
    const sans = libellesDesFiches({ ...parametres(1), marge: 0 }).length
    expect(MARGE).toBeGreaterThan(0)
    expect(avec).toBeGreaterThan(sans)
  })

  it('ne réajustent aucun texte pendant un glisser : seule l’échelle les refait', () => {
    const memoire = creerMemoireDesTextes()
    const premier = libellesDesFiches(parametres(1), memoire)
    const texteEgo = memoire.textes.get(ego.id)
    expect(texteEgo).toBeDefined()
    // Un repère : s'il ressort, c'est que le texte n'a pas été recalculé.
    memoire.textes.set(ego.id, { t1: 'REPÈRE', t2: '', taille: 12 })
    const glisse = libellesDesFiches(parametres(1, -40), memoire)
    const e = glisse.find((x) => x.id === ego.id) as LibelleCompact
    expect(e.t1).toBe('REPÈRE')
    // Mais la place, elle, suit le glisser.
    const avant = premier.find((x) => x.id === ego.id)!
    expect(e.x).toBeCloseTo(avant.x - 40, 6)
    // Une autre échelle : tout est réajusté.
    const zoome = libellesDesFiches(parametres(1.2), memoire).find((x) => x.id === ego.id) as LibelleCompact
    expect(zoome.t1).not.toBe('REPÈRE')
  })

  it('oublient leurs textes avec la disposition', () => {
    const memoire = creerMemoireDesTextes()
    libellesDesFiches(parametres(1), memoire)
    memoire.textes.set(ego.id, { t1: 'REPÈRE', t2: '', taille: 12 })
    const autres: FicheHistoire[] = [...d.fiches]
    const e = libellesDesFiches({ ...parametres(1), fiches: autres }, memoire).find((x) => x.id === ego.id) as LibelleCompact
    expect(e.t1).not.toBe('REPÈRE')
  })
})

describe('les noms de foyer et les anneaux', () => {
  it('ne s’écrivent qu’au niveau Familles', () => {
    expect(libellesDesFoyers(d.foyers, surEgo(1), canevas, 2, false)).toEqual([])
    expect(libellesDesFoyers(d.foyers, surEgo(0.3), canevas, 1, false).length).toBeGreaterThan(0)
  })

  it('un anneau entoure sa fiche à l’écran, et rien hors de la fenêtre', () => {
    const a = anneauDe(ego, surEgo(1), canevas, 4)!
    expect(a.w).toBeCloseTo(ego.w + 8, 6)
    expect(a.x).toBeCloseTo(canevas.largeur / 2 - ego.cx + ego.x - 4, 6)
    expect(anneauDe(ego, { s: 1, tx: 1e6, ty: 0 }, canevas, 4)).toBeNull()
  })
})

describe('l’estompage, dans la peinture (règle 2)', () => {
  it('rend une couleur transparente, jamais une opacité', () => {
    expect(attenuer('var(--x)', 1)).toBe('var(--x)')
    expect(attenuer('var(--x)', 0.25)).toBe('color-mix(in srgb, var(--x) 25%, transparent)')
    expect(alphaDeFiche(false, new Set(), 'a')).toBe(ALPHA_HORS_FILTRE)
    expect(alphaDeFiche(true, new Set(['b']), 'a')).toBe(ALPHA_HORS_RECHERCHE)
    expect(alphaDeFiche(true, new Set(['a']), 'a')).toBe(1)
  })

  it('le style d’une boîte estompée ne porte aucune `opacity`, mais des couleurs atténuées', () => {
    for (const ensemble of [true, false]) {
      const css = styleDeFiche(ego, { ensemble, portrait: false, alpha: ALPHA_HORS_FILTRE, place: null, branche: 'pp' })
      expect(css).not.toMatch(/opacity/)
      expect(css).toContain('color-mix(in srgb')
      expect(css).toContain(`left:${ego.x}px`)
    }
    const plein = styleDeFiche(ego, { ensemble: false, portrait: true, alpha: 1, place: 'ascendant', branche: 'pp' })
    expect(plein).toContain('border:1px solid var(--h-accent)')
    expect(plein).toContain('border-top:4px solid var(--h-branche-pp)')
  })

  it('les traits se règlent par `stroke-opacity`', () => {
    const t = stylesDesTraits(0, true, false)
    expect(t.ordinaires['stroke-opacity']).toBe('0')
    expect(stylesDesTraits(2, false, false).ordinaires['stroke-opacity']).toBe('0.9')
    expect(stylesDesTraits(1, true, true).directs).toEqual({ stroke: 'var(--h-accent)', 'stroke-width': '2', 'stroke-opacity': '1' })
    for (const style of Object.values(t)) expect(Object.keys(style)).not.toContain('opacity')
  })
})

describe('une proposition en attente se voit (demande du 22/10/2026)', () => {
  const proposee: FicheHistoire = { ...ego, id: '#12/p1', noeud: { ...ego.noeud, id: '#12/p1', personId: null, enAttente: true } }
  const contexte = { portrait: false, alpha: 1, place: null, branche: 'pp' } as const

  it('en pointillé épais de l’accent, le fond teinté de l’accent ; au niveau Ensemble, l’accent relevé du texte', () => {
    const css = styleDeFiche(proposee, { ...contexte, ensemble: false })
    expect(css).toContain('border:2px dashed var(--h-accent)')
    expect(css).toMatch(/background:color-mix\(in srgb, var\(--h-accent\) 30%, var\(--h-\w+\)\)/)
    const loin = styleDeFiche(proposee, { ...contexte, ensemble: true })
    expect(loin).toContain('background:color-mix(in srgb, var(--h-accent) 60%, var(--h-texte))')
    // Pas la teinte de sa branche : en sombre, l'accent est celle de la branche paternelle du père.
    expect(loin).not.toContain('var(--h-branche-pp)')
    // Une fiche validée garde son trait fin et sa couleur.
    const validee = styleDeFiche(ego, { ...contexte, ensemble: false })
    expect(validee).not.toContain('2px dashed')
    expect(validee).not.toContain('var(--h-accent) 30%')
    // Estompée par un filtre, elle l'est dans la peinture, comme les autres.
    expect(styleDeFiche(proposee, { ...contexte, ensemble: false, alpha: ALPHA_HORS_FILTRE })).not.toMatch(/opacity/)
  })

  it('ses liens, en pointillé de l’accent, se voient à tous les niveaux, Ensemble compris', () => {
    for (const niveau of [0, 1, 2] as const)
      for (const surligner of [false, true]) {
        const attente = stylesDesTraits(niveau, surligner, false).attente
        expect(attente.stroke).toBe('var(--h-accent)')
        expect(Number(attente['stroke-opacity'])).toBeGreaterThanOrEqual(0.85)
        expect(Number(attente['stroke-width'])).toBeGreaterThan(Number(stylesDesTraits(niveau, surligner, false).ordinaires['stroke-width']))
      }
  })
})

describe('une recherche close se voit (ADR 0023) : bord brique et mur', () => {
  const contexte = { portrait: false, branche: 'pp' } as const

  it('le bord brique remplace la bordure ordinaire, à tous les niveaux et alphas, sans `opacity`', () => {
    for (const alpha of [1, ALPHA_HORS_RECHERCHE, ALPHA_HORS_FILTRE])
      for (const portrait of [false, true]) {
        const css = styleDeFiche(ego, { ...contexte, portrait, ensemble: false, alpha, place: null, fin: true })
        expect(css).toContain('border:2px solid')
        expect(css).toContain('var(--h-fin-de-recherche)')
        expect(css).not.toMatch(/opacity/)
        // Sans, la bordure ordinaire du sexe.
        expect(styleDeFiche(ego, { ...contexte, portrait, ensemble: false, alpha, place: null })).not.toContain('--h-fin-de-recherche')
      }
    expect(styleDeFiche(ego, { ...contexte, ensemble: false, alpha: 1, place: null, fin: true })).toContain(
      'border:2px solid var(--h-fin-de-recherche)',
    )
  })

  it('au niveau Ensemble, le rectangle garde sa teinte et prend un filet intérieur brique', () => {
    const css = styleDeFiche(ego, { ...contexte, ensemble: true, alpha: 1, place: null, fin: true })
    expect(css).toContain('var(--h-branche-pp)')
    expect(css).toContain('box-shadow:inset 0 0 0 2px var(--h-fin-de-recherche)')
    expect(css).not.toMatch(/opacity/)
    expect(styleDeFiche(ego, { ...contexte, ensemble: true, alpha: ALPHA_HORS_FILTRE, place: null, fin: true })).toContain(
      'color-mix(in srgb, var(--h-fin-de-recherche)',
    )
    expect(styleDeFiche(ego, { ...contexte, ensemble: true, alpha: 1, place: null })).not.toContain('box-shadow')
  })

  it('priorités : en attente > ligne directe > fin de recherche > ordinaire', () => {
    const proposee: FicheHistoire = { ...ego, id: '#12/p1', noeud: { ...ego.noeud, id: '#12/p1', personId: null, enAttente: true } }
    const attente = styleDeFiche(proposee, { ...contexte, ensemble: false, alpha: 1, place: null, fin: true })
    expect(attente).toContain('border:2px dashed var(--h-accent)')
    expect(attente).not.toContain('--h-fin-de-recherche')
    const directe = styleDeFiche(ego, { ...contexte, ensemble: false, alpha: 1, place: 'ascendant', fin: true })
    expect(directe).toContain('border:1px solid var(--h-accent)')
    expect(directe).not.toContain('--h-fin-de-recherche')
  })

  it('`libellesDesFiches` pose le mur sur la fiche close, et seulement elle', () => {
    const l = libellesDesFiches({ ...parametres(1), mur: (f) => f.id === ego.id })
    const murs = l.filter((x) => x.mur)
    expect(murs.map((x) => x.id)).toEqual([ego.id])
    // Le mur est calé sur le coin haut droit de la carte à l'écran.
    const c = surEgo(1)
    expect(murs[0]!.coin).toEqual({ x: c.tx + (ego.x + ego.w), y: c.ty + ego.y })
    // Sans prédicat, aucune fiche n'a de mur.
    expect(libellesDesFiches(parametres(1)).some((x) => x.mur)).toBe(false)
  })
})
