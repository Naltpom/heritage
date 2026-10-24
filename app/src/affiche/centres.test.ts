import { describe, expect, it } from 'vitest'
import { CORPS_MIN } from './centres'
import { ArbreAffiche } from './donnees'
import { arbreDeCousins, arbreFictif, coupleSeul, type ArbreDEssai } from './essai/arbreFictif'
import { installerLesPolicesDuDisque } from './essai/polices'
import { Reglages } from './eventail'
import { afficheEventail, type AfficheProduite, type CentreDeLAffiche, type FormeDesDescendants } from './fabriquer'
import { afficheSoleil } from './soleil'
import { versSvg } from './svg'

installerLesPolicesDuDisque()

const DATE = new Date(2026, 9, 24)

function soleil(essai: ArbreDEssai, descendants: FormeDesDescendants, centre: CentreDeLAffiche = 'couple'): AfficheProduite {
  return afficheSoleil(new ArbreAffiche(essai.charge), essai.mari, essai.femme, { date: DATE, centre, descendants })
}

function eventail(essai: ArbreDEssai, descendants: FormeDesDescendants, sens: 'haut' | 'bas' = 'haut', centre: CentreDeLAffiche = 'couple'): AfficheProduite {
  return afficheEventail(new ArbreAffiche(essai.charge), essai.mari, essai.femme, { date: DATE, descendants, centre, reglages: new Reglages({ sens }) })
}

/** Tous les textes de la scène, quel que soit leur mode de pose. */
const textes = (a: AfficheProduite): string[] => a.scene.textes.map((t) => t.texte)
/** Le texte entier d'une ligne posée morceau par morceau : les prénoms et le nom sont deux morceaux. */
const contient = (a: AfficheProduite, mot: string): boolean => textes(a).some((t) => t.trim() === mot || t.startsWith(`${mot} `))

/** La position (x, y) d'un texte sur la page : arcs, et repères translatés puis tournés compris. */
function position(t: AfficheProduite['scene']['textes'][number]): [number, number] {
  if (t.type === 'texteSurArc') {
    const a = (((t.a1 + t.a2) / 2) * Math.PI) / 180
    return [t.cx + t.r * Math.cos(a), t.cy - t.r * Math.sin(a)]
  }
  const m = /translate\(([-\d.]+),([-\d.]+)\)(?: rotate\(([-\d.]+)\))?/.exec(t.transform ?? '')
  if (!m) return [t.x, t.y]
  const th = (Number(m[3] ?? 0) * Math.PI) / 180
  return [Number(m[1]) + t.x * Math.cos(th) - t.y * Math.sin(th), Number(m[2]) + t.x * Math.sin(th) + t.y * Math.cos(th)]
}

/** Les textes de ces prénoms exacts (un morceau « Prénoms » ou « Prénoms␣ » avant le NOM). */
const morceaux = (a: AfficheProduite, prenoms: string) => a.scene.textes.filter((t) => t.texte.trim() === prenoms)

describe('le soleil intérieur', () => {
  const s = soleil(arbreDeCousins(), 'soleil', 'parents')

  /** Le texte de ces prénoms qui tombe dans le disque central (les ancêtres portent parfois les mêmes). */
  const auCentre = (prenoms: string): [number, number] | null => {
    const [cx, cy] = s.centre
    const t = morceaux(s, prenoms).find((x) => Math.hypot(position(x)[0] - cx, position(x)[1] - cy) < s.eventail.rC)
    return t ? position(t) : null
  }

  it('pose chaque famille dans le disque : le couple, les fratries, leurs enfants', () => {
    for (const p of ['Natron', 'Marne', 'Gypse', 'Opale', 'Jade', 'Craie', 'Silex', 'Topaze']) expect(auCentre(p), p).not.toBeNull()
  })

  it('met le couple en haut, la fratrie de la femme à droite et celle du mari à gauche', () => {
    const [cx, cy] = s.centre
    expect(auCentre('Natron')![1]).toBeLessThan(cy)
    expect(auCentre('Opale')![0]).toBeGreaterThan(cx)
    expect(auCentre('Jade')![0]).toBeGreaterThan(cx)
    expect(auCentre('Gypse')![0]).toBeLessThan(cx)
  })

  it('partage tout le secteur du couple entre ses enfants, sans trou entre eux', () => {
    // Le couple est centré en haut (90°) : ses deux enfants, qui s'y partagent tout son angle,
    // sont de part et d'autre, à égale distance.
    const [cx, cy] = s.centre
    const angle = (prenoms: string): number => {
      const [x, y] = auCentre(prenoms)!
      return (Math.atan2(cy - y, x - cx) * 180) / Math.PI
    }
    expect((angle('Silex') + angle('Topaze')) / 2).toBeCloseTo(90, -0.5)
  })

  it('écrit le titre au cœur, et ne descend pas sous le corps minimal', () => {
    expect(textes(s)).toContain('DESCENDANCE')
    expect(s.echelleDescendants * 13).toBeGreaterThanOrEqual(CORPS_MIN - 1e-9)
    expect(versSvg(s.scene)).not.toMatch(/NaN|Infinity/)
  })
})

describe('la rosace de médaillons', () => {
  const s = soleil(arbreDeCousins(), 'medaillons', 'parents')
  const medaillons = (a: AfficheProduite) => a.scene.elements.filter((e) => e.type === 'chemin' && ['#E3EAE5', '#F4E9D9', '#EFE6D6'].includes(e.fond))

  it('pose un médaillon par fratrie autour du couple, teinté de son côté', () => {
    // Le frère du mari, les deux sœurs de la femme.
    expect(medaillons(s)).toHaveLength(3)
    expect(medaillons(s).filter((e) => e.type === 'chemin' && e.fond === '#E3EAE5')).toHaveLength(1)
    expect(medaillons(s).filter((e) => e.type === 'chemin' && e.fond === '#F4E9D9')).toHaveLength(2)
  })

  it('met le couple et ses enfants au cœur, et la descendance de chaque fratrie dans son médaillon', () => {
    for (const p of ['Natron', 'Marne', 'Gypse', 'Opale', 'Jade', 'Craie']) expect(contient(s, p), p).toBe(true)
    expect(textes(s).some((t) => t.includes('Silex et Topaze'))).toBe(true)
    expect(textes(s).some((t) => t.startsWith('∞ Galène'))).toBe(true)
  })

  it('reste lisible', () => {
    expect(s.echelleDescendants * 13).toBeGreaterThanOrEqual(CORPS_MIN - 1e-9)
    expect(versSvg(s.scene)).not.toMatch(/NaN|Infinity/)
  })
})

describe('l’éventail et ses descendants dans son demi-disque', () => {
  /** Vrai si le texte tombe dans le demi-disque central : au-dessus de la base quand l'éventail monte, en dessous sinon. */
  const dansLeDemiDisque = (a: AfficheProduite, t: AfficheProduite['scene']['textes'][number], sens: 'haut' | 'bas'): boolean => {
    const [x, y] = position(t)
    const [cx, cy] = a.centre
    return Math.hypot(x - cx, y - cy) < a.eventail.rC && (sens === 'haut' ? y < cy : y > cy)
  }

  it.each(['soleil', 'medaillons'] as const)('pose le %s dans le demi-disque central, le titre en son cœur', (forme) => {
    for (const sens of ['haut', 'bas'] as const) {
      const a = eventail(arbreFictif(), forme, sens)
      // Deux des enfants du couple fictif (les ancêtres ne portent pas ces prénoms-là).
      const enfants = [...morceaux(a, 'Cobalt Pyroxène'), ...morceaux(a, 'Lapis Grenat Quartz Zircon Onyx')]
      expect(enfants.length, `${forme} ${sens}`).toBeGreaterThan(0)
      for (const t of enfants) expect(dansLeDemiDisque(a, t, sens), `${forme} ${sens} ${t.texte}`).toBe(true)
      // Le titre du cœur remplace celui du disque.
      const titre = a.scene.textes.filter((t) => t.texte === 'DESCENDANCE')
      expect(titre, `${forme} ${sens}`).toHaveLength(1)
      expect(dansLeDemiDisque(a, titre[0]!, sens), `${forme} ${sens}`).toBe(true)
      expect(textes(a)).not.toContain('Ascendance et descendance')
      expect(a.echelleDescendants * 13).toBeGreaterThanOrEqual(CORPS_MIN - 1e-9)
      expect(versSvg(a.scene)).not.toMatch(/NaN|Infinity/)
    }
  })

  it('garde l’arbre de boîtes au choix, sous la base', () => {
    const a = eventail(arbreFictif(), 'arbre')
    expect(a.scene.elements.filter((e) => e.type === 'rect' && e.rayon === 1.2).length).toBeGreaterThan(5)
    expect(textes(a)).toContain('Ascendance et descendance')
  })
})

describe('l’éventail des quatre parents', () => {
  /**
   * Les textes de ces prénoms côté descendants : dans le demi-disque central pour le soleil
   * intérieur et la rosace ; sous la base (au-dessus quand l'éventail descend) pour l'arbre.
   */
  const descendants = (a: AfficheProduite, forme: FormeDesDescendants, prenoms: string[], sens: 'haut' | 'bas') =>
    a.scene.textes.filter((t) => {
      const [x, y] = position(t)
      const [cx, cy] = a.centre
      if (!prenoms.includes(t.texte.trim())) return false
      const auDessus = y < cy
      if (forme === 'arbre') return sens === 'haut' ? !auDessus : auDessus
      return Math.hypot(x - cx, y - cy) < a.eventail.rC && (sens === 'haut' ? auDessus : !auDessus)
    })

  it.each(['soleil', 'medaillons', 'arbre'] as const)('met les quatre parents au premier anneau, et leurs descendants en %s', (forme) => {
    for (const sens of ['haut', 'bas'] as const) {
      const a = eventail(arbreDeCousins(), forme, sens, 'parents')
      const [cx] = a.centre
      expect(a.eventail.R.premierAnneauMasque, `${forme} ${sens}`).toBe(true)
      // Le couple et ses enfants (au cœur de la rosace, sur une ligne), les fratries et la cousine.
      const enfantsDuCouple = forme === 'medaillons' ? ['Silex et Topaze'] : ['Silex', 'Topaze']
      for (const p of ['Natron', ...enfantsDuCouple, 'Gypse', 'Opale', 'Jade', 'Craie']) expect(descendants(a, forme, [p], sens), `${forme} ${sens} ${p}`).not.toHaveLength(0)
      // La femme une seule fois : avec le mari, plus dans sa fratrie.
      expect(descendants(a, forme, ['Marne', '∞ Marne'], sens), `${forme} ${sens}`).toHaveLength(1)
      // Le frère du mari à gauche, les sœurs de la femme à droite — dans la rosace, à pas égal,
      // celle du milieu peut tomber sur l'axe.
      const x = (p: string): number => position(descendants(a, forme, [p], sens)[0]!)[0]
      expect(x('Gypse'), `${forme} ${sens}`).toBeLessThan(cx)
      expect(x('Jade'), `${forme} ${sens}`).toBeGreaterThan(cx)
      expect(x('Gypse'), `${forme} ${sens}`).toBeLessThan(x('Opale'))
      expect(x('Opale'), `${forme} ${sens}`).toBeLessThan(x('Jade'))
      // Le titre ne date pas le mariage du couple : ses chiffres sont ceux des quatre parents.
      expect(textes(a).filter((t) => t.startsWith('mariés')), `${forme} ${sens}`).toHaveLength(0)
      expect(versSvg(a.scene)).not.toMatch(/NaN|Infinity/)
    }
    expect(textes(eventail(arbreDeCousins(), 'arbre')).filter((t) => t.startsWith('mariés'))).toHaveLength(1)
  })

  it('retombe sur le couple quand les parents d’un époux manquent', () => {
    const a = eventail(coupleSeul(), 'soleil', 'haut', 'parents')
    expect(a.eventail.R.premierAnneauMasque).toBe(false)
    expect(contient(a, 'Onyx')).toBe(true)
  })
})
