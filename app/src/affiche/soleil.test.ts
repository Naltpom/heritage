import { describe, expect, it } from 'vitest'
import { ArbreAffiche, fusionnerCharges } from './donnees'
import { arbreDeCousins, arbreFictif, arbreTouffu, avecFinsDeRecherche, avecLeCoupleEnFinDeRecherche, coupleSeul, legendeDuMurPresente, mursAttendus, mursDeLaScene, type ArbreDEssai } from './essai/arbreFictif'
import { installerLesPolicesDuDisque } from './essai/polices'
import { radians, Reglages } from './eventail'
import type { AfficheProduite, CentreDeLAffiche, FormeDesDescendants } from './fabriquer'
import { afficheSoleil } from './soleil'
import { versSvg } from './svg'

installerLesPolicesDuDisque()

function soleil(essai: ArbreDEssai, options: { rotation?: number; centre?: CentreDeLAffiche; descendants?: FormeDesDescendants } = {}): AfficheProduite {
  return afficheSoleil(new ArbreAffiche(essai.charge), essai.mari, essai.femme, {
    date: new Date(2026, 9, 24),
    centre: options.centre ?? 'couple',
    ...(options.descendants ? { descendants: options.descendants } : {}),
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
    [45, (c: number, si: number) => c < -0.3 && si > 0.3, 'en haut à gauche'],
    [200, (c: number, si: number) => c > 0.5 && si < 0.5, 'à droite, un peu dessous'],
  ] as const)('tourné de %i°, met le mari %s', (rotation, attendu, _cote) => {
    const a = soleil(arbreFictif(), { rotation })
    const nom = a.scene.textes.find((t) => t.type === 'texteSurArc' && t.texte.startsWith('Natron'))
    expect(nom?.type).toBe('texteSurArc')
    if (nom?.type !== 'texteSurArc') return
    const milieu = radians((nom.a1 + nom.a2) / 2)
    expect(attendu(Math.cos(milieu), Math.sin(milieu))).toBe(true)
  })
})

describe('la page du soleil', () => {
  it.each([
    ['fictif', arbreFictif],
    ['touffu', arbreTouffu],
  ] as const)('est carrée, la légende et le pied en bas (%s)', (_, arbre) => {
    const s = soleil(arbre())
    expect(s.scene.largeur).toBeCloseTo(s.scene.hauteur, 6)
    const [, cy] = s.centre
    for (const debut of ['LES QUATRE LIGNÉES', 'Arbre établi']) {
      const t = s.scene.textes.find((x) => x.type === 'texte' && x.texte.startsWith(debut))
      expect(t?.type === 'texte' ? t.y : -Infinity, debut).toBeGreaterThan(cy)
    }
  })
})

describe('le soleil des quatre parents', () => {
  const essai = arbreDeCousins()
  const s = soleil(essai, { centre: 'parents' })
  /** L'abscisse du centre de la boîte qui porte ces prénoms. */
  const xDe = (prenoms: string): number => {
    const texte = s.scene.textes.find((t) => dansUneBoite(t) && t.texte === prenoms)
    const m = texte?.type === 'texte' ? /translate\(([\d.]+),/.exec(texte.transform ?? '') : null
    return m ? Number(m[1]) : NaN
  }

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

  it('montre chacun une seule fois, la femme comprise', () => {
    const t = textesDesBoites(s)
    for (const prenoms of ['Natron', 'Marne', 'Silex', 'Topaze', 'Opale']) expect(t.filter((x) => x === prenoms), prenoms).toHaveLength(1)
  })

  it('met le couple au milieu, ses enfants dessous, et les fratries de part et d’autre', () => {
    const [cx] = s.centre
    expect(xDe('Natron')).toBeLessThan(cx)
    expect(xDe('Marne')).toBeGreaterThan(cx)
    // Les enfants sous le couple, autour du milieu ; les fratries au-delà des époux.
    for (const p of ['Silex', 'Topaze']) expect(Math.abs(xDe(p) - cx)).toBeLessThan(Math.abs(xDe('Gypse') - cx))
    expect(xDe('Gypse')).toBeLessThan(xDe('Natron'))
    expect(xDe('Opale')).toBeGreaterThan(xDe('Marne'))
    expect(xDe('Jade')).toBeGreaterThan(xDe('Marne'))
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

describe.each([
  ['le soleil du couple', 'couple', arbreFictif],
  ['le soleil des quatre parents', 'parents', arbreDeCousins],
] as const)('le mur de %s', (_, centre, arbre) => {
  const { essai: avec } = avecFinsDeRecherche(arbre())
  const close = soleil(avec, { centre })

  it('dessine un mur par sommet clos, et sa ligne de légende', () => {
    const attendus = mursAttendus(avec)
    expect(attendus).toBeGreaterThan(0)
    expect(mursDeLaScene(close.scene)).toHaveLength(attendus + 1)
    // Le nombre, en dur : les trois sommets clos par `avecFinsDeRecherche()`, plus la ligne de légende.
    expect(attendus).toBe(3)
    expect(mursDeLaScene(close.scene)).toHaveLength(3 + 1)
    expect(legendeDuMurPresente(close.scene)).toBe(true)
    expect(versSvg(close.scene)).not.toMatch(/NaN|Infinity/)
  })

  it('ne dessine aucun mur ni légende sans fin de recherche', () => {
    const sans = soleil(arbre(), { centre })
    expect(mursDeLaScene(sans.scene)).toHaveLength(0)
    expect(legendeDuMurPresente(sans.scene)).toBe(false)
  })

  it('ne change ni les anneaux ni le disque central', () => {
    const sans = soleil(arbre(), { centre })
    expect(close.eventail.rExt).toBeCloseTo(sans.eventail.rExt, 9)
    expect(close.eventail.rC).toBeCloseTo(sans.eventail.rC, 9)
  })
})

describe.each(['arbre', 'soleil', 'medaillons'] as const)('le mur du couple, descendants %s', (descendants) => {
  // La femme a une mère liée et un père clos : un seul mur, celui du couple.
  const couple = avecLeCoupleEnFinDeRecherche(arbreDeCousins())

  it.each(['couple', 'parents'] as const)('le couple porte son mur, centre %s, et la légende le dit', (centre) => {
    const a = soleil(couple, { centre, descendants })
    expect(mursAttendus(couple)).toBe(1)
    // Le mur du couple, plus la ligne de légende — au premier anneau ou au centre.
    expect(mursDeLaScene(a.scene)).toHaveLength(1 + 1)
    expect(legendeDuMurPresente(a.scene)).toBe(true)
    expect(versSvg(a.scene)).not.toMatch(/NaN|Infinity/)
  })

  it('les quatre parents au centre gardent le mur du couple en plus de ceux des ancêtres', () => {
    const tous = avecLeCoupleEnFinDeRecherche(avecFinsDeRecherche(arbreDeCousins()).essai)
    expect(mursDeLaScene(soleil(tous, { centre: 'parents', descendants }).scene)).toHaveLength(3 + 1 + 1)
  })

  it('ne pose aucun mur sans fin de recherche', () => {
    const sans = soleil(arbreDeCousins(), { centre: 'parents', descendants })
    expect(mursDeLaScene(sans.scene)).toHaveLength(0)
    expect(legendeDuMurPresente(sans.scene)).toBe(false)
  })
})

describe('la hauteur de la page avec un mur', () => {
  it('réserve la ligne du mur sous le dessin : 5,4·kl de plus que sans mur', () => {
    const avec = soleil(avecFinsDeRecherche(arbreDeCousins()).essai)
    const sans = soleil(arbreDeCousins())
    // Les cousins : la légende ne tient pas dans un coin, elle passe sous le dessin.
    expect(avec.echelleLegende).toBe(1)
    expect(avec.scene.hauteur - sans.scene.hauteur).toBeCloseTo(5.4 * avec.echelleLegende, 9)
  })
})
