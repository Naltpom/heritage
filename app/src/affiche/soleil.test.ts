import { describe, expect, it } from 'vitest'
import { ArbreAffiche, fusionnerCharges } from './donnees'
import { arbreDeCousins, arbreFictif, coupleSeul, type ArbreDEssai } from './essai/arbreFictif'
import { installerLesPolicesDuDisque } from './essai/polices'
import { radians, Reglages } from './eventail'
import type { AfficheProduite } from './fabriquer'
import { afficheSoleil, type CentreDuSoleil } from './soleil'
import { versSvg } from './svg'

installerLesPolicesDuDisque()

function soleil(essai: ArbreDEssai, options: { rotation?: number; centre?: CentreDuSoleil } = {}): AfficheProduite {
  return afficheSoleil(new ArbreAffiche(essai.charge), essai.mari, essai.femme, {
    date: new Date(2026, 9, 24),
    centre: options.centre ?? 'couple',
    reglages: new Reglages({ rotation: options.rotation ?? 0 }),
  })
}

const boites = (a: AfficheProduite) => a.scene.elements.filter((e) => e.type === 'rect' && e.rayon === 1.2)
/** Les textes des boîtes de descendants : posés par une translation seule (les textes rayonnants tournent aussi). */
const dansUneBoite = (t: AfficheProduite['scene']['textes'][number]): boolean => t.type === 'texte' && Boolean(t.transform?.startsWith('translate(')) && !t.transform!.includes('rotate')
const textesDesBoites = (a: AfficheProduite): string[] => a.scene.textes.filter(dansUneBoite).map((t) => t.texte)

/** Vrai si le rectangle tient dans le disque central. */
function dansLeDisque(a: AfficheProduite, x: number, y: number, l: number, h: number): boolean {
  const [cx, cy] = a.centre
  return [
    [x, y],
    [x + l, y],
    [x, y + h],
    [x + l, y + h],
  ].every(([px, py]) => Math.hypot(px! - cx, py! - cy) <= a.eventail.rC + 1e-6)
}

describe('le soleil', () => {
  const s = soleil(arbreFictif())

  it('fait le tour entier : lui sur une moitié, elle sur l’autre', () => {
    const E = s.eventail
    expect(E.h.a2 - E.f.a1).toBeCloseTo(360, 9)
    expect(E.h.a1).toBeCloseTo(E.f.a2, 9)
    expect(versSvg(s.scene)).not.toMatch(/NaN|Infinity/)
  })

  it('pose le titre et les descendants dans le disque central', () => {
    expect(boites(s).length).toBeGreaterThan(5)
    for (const b of boites(s)) if (b.type === 'rect') expect(dansLeDisque(s, b.x, b.y, b.l, b.h)).toBe(true)
    const titre = s.scene.textes.find((t) => t.type === 'texte' && t.texte === 'Romarin · Sureau')
    expect(titre?.type === 'texte' && Math.hypot(titre.x - s.centre[0], titre.y - s.centre[1])).toBeLessThan(s.eventail.rC)
    expect(s.echelleDescendants).toBeGreaterThanOrEqual(0.55)
    expect(s.echelleDescendants).toBeLessThanOrEqual(1.4)
  })

  it('range les descendants en bandes plutôt qu’en une seule rangée trop large', () => {
    const ys = new Set(boites(s).map((b) => (b.type === 'rect' ? Math.round(b.y) : 0)))
    expect(ys.size).toBeGreaterThan(2)
  })

  it.each([
    [0, (c: number) => c < -0.5, 'à gauche'],
    [90, (_: number, si: number) => si > 0.5, 'en haut'],
    [180, (c: number) => c > 0.5, 'à droite'],
    [270, (_: number, si: number) => si < -0.5, 'en bas'],
  ] as const)('tourné de %i°, met le mari %s', (rotation, attendu, _cote) => {
    const a = soleil(arbreFictif(), { rotation })
    const nom = a.scene.textes.find((t) => t.type === 'texteSurArc' && t.texte.startsWith('Natron'))
    expect(nom?.type).toBe('texteSurArc')
    if (nom?.type !== 'texteSurArc') return
    const milieu = radians((nom.a1 + nom.a2) / 2)
    expect(attendu(Math.cos(milieu), Math.sin(milieu))).toBe(true)
  })
})

describe('le soleil des quatre parents', () => {
  const essai = arbreDeCousins()
  const s = soleil(essai, { centre: 'parents' })

  it('met les quatre parents au premier anneau, et plus le couple', () => {
    const arcs = s.scene.textes.filter((t) => t.type === 'texteSurArc').map((t) => t.texte)
    expect(arcs.some((t) => t.startsWith('Natron'))).toBe(false)
    for (const prenoms of ['Basalte', 'Agate', 'Quartz', 'Ambre']) expect(arcs.some((t) => t.startsWith(prenoms)), prenoms).toBe(true)
  })

  it('pose au centre le couple, ses frères et sœurs et leurs descendants', () => {
    const t = textesDesBoites(s)
    for (const prenoms of ['Natron', 'Gypse', 'Opale', 'Jade', 'Craie', 'Silex', 'Topaze']) expect(t, prenoms).toContain(prenoms)
    for (const b of boites(s)) if (b.type === 'rect') expect(dansLeDisque(s, b.x, b.y, b.l, b.h)).toBe(true)
  })

  it('montre les enfants du couple une seule fois, sous le mari', () => {
    const t = textesDesBoites(s)
    expect(t.filter((x) => x === 'Silex')).toHaveLength(1)
    expect(t.filter((x) => x === 'Topaze')).toHaveLength(1)
  })

  it('sépare les deux familles : celle du mari à gauche, celle de la femme à droite', () => {
    const x = (prenoms: string): number => {
      const texte = s.scene.textes.find((t) => dansUneBoite(t) && t.texte === prenoms)
      const m = texte?.type === 'texte' ? /translate\(([\d.]+),/.exec(texte.transform ?? '') : null
      return m ? Number(m[1]) : NaN
    }
    expect(x('Gypse')).toBeLessThan(s.centre[0])
    expect(x('Opale')).toBeGreaterThan(s.centre[0])
    expect(x('Jade')).toBeGreaterThan(s.centre[0])
  })

  it('retombe sur le couple quand les parents d’un époux manquent', () => {
    const a = soleil(coupleSeul(), { centre: 'parents' })
    expect(a.scene.textes.some((t) => t.type === 'texteSurArc' && t.texte.startsWith('Onyx'))).toBe(true)
  })
})

describe('la fusion des lectures', () => {
  it('garde tous les enfants d’une famille lue dans deux affiches', () => {
    const { charge } = arbreDeCousins()
    const seule = { ...charge, familles: charge.familles.map((f) => ({ ...f, enfants: f.enfants.slice(0, 1) })) }
    const fusion = fusionnerCharges(seule, charge)
    for (const f of charge.familles) expect(fusion.familles.find((x) => x.id === f.id)!.enfants.map((e) => e.personId)).toEqual(f.enfants.map((e) => e.personId))
    expect(fusion.personnes).toHaveLength(charge.personnes.length)
    expect(fusion.famille).toBe(seule.famille)
  })
})
