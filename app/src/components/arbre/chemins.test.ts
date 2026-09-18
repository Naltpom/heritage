/**
 * Le tracé des arêtes, comparé **caractère par caractère** au SVG du viewer
 * d'origine.
 *
 * Les chaînes attendues ci-dessous sont recopiées de `arbre.html` (lignes 210
 * et suivantes), c'est-à-dire de la sortie de `rendu.py`. C'est la seule façon
 * de prouver qu'un portage de tracé est fidèle : un rendu « qui ressemble »
 * n'est pas un rendu identique, et la différence ne se voit qu'à la loupe, sur
 * un écran qu'on n'a pas.
 */
import { describe, expect, it } from 'vitest'
import {
  hauteursDeBarre,
  hauteursDUnion,
  pointsFiliation,
  pointsUnion,
  trace,
  type BarreDUnion,
  type BoiteNoeud,
  type PeigneDeFamille,
} from './chemins'

describe('trace', () => {
  it('reproduit à l’identique une arête d’union de `arbre.html`', () => {
    // I500003 (x = -4700.1, bas de fiche à -130) vers F500001 (x = 140,
    // sommet à -110), barre à mi-hauteur.
    const d = trace([
      { x: -4700.1, y: -130 },
      { x: -4700.1, y: -120 },
      { x: 140, y: -120 },
      { x: 140, y: -110 },
    ])
    expect(d).toBe(
      'M-4700.1,-130.0 L-4700.1,-125.0 Q-4700.1,-120.0 -4691.1,-120.0 ' +
        'L131.0,-120.0 Q140.0,-120.0 140.0,-115.0 L140.0,-110.0',
    )
  })

  it('reproduit à l’identique une arête de filiation de `arbre.html`', () => {
    const d = trace([
      { x: 140, y: -98 },
      { x: 140, y: -78.8 },
      { x: 0, y: -78.8 },
      { x: 0, y: -20 },
    ])
    expect(d).toBe(
      'M140.0,-98.0 L140.0,-87.8 Q140.0,-78.8 131.0,-78.8 ' +
        'L9.0,-78.8 Q0.0,-78.8 0.0,-69.8 L0.0,-20.0',
    )
  })

  it('rogne le rayon à la moitié du segment le plus court', () => {
    // Segment vertical de 10 : le coin ne peut pas mordre plus de 5.
    const d = trace([
      { x: 0, y: 0 },
      { x: 0, y: 10 },
      { x: 200, y: 10 },
    ])
    expect(d).toContain('L0.0,5.0')
  })

  it('fusionne deux points confondus plutôt que de diviser par zéro', () => {
    const d = trace([
      { x: 0, y: 0 },
      { x: 0, y: 0.1 },
      { x: 0, y: 100 },
    ])
    expect(d).toBe('M0.0,0.0 L0.0,100.0')
    expect(d).not.toContain('NaN')
  })

  it('rend une chaîne vide quand il ne reste qu’un point', () => {
    expect(trace([{ x: 3, y: 4 }])).toBe('')
    expect(trace([])).toBe('')
  })

  it('n’émet jamais de NaN, même sur un coin dégénéré', () => {
    const d = trace([
      { x: -4700.1, y: -248 },
      { x: -4700.1, y: -213.4 },
      { x: -4700.1, y: -204.4 },
      { x: -4700.1, y: -170 },
    ])
    expect(d).not.toContain('NaN')
  })
})

describe('pointsUnion', () => {
  const epoux: BoiteNoeud = { x: -4700.1, y: -150, w: 127, h: 40 }
  const famille: BoiteNoeud = { x: 140, y: -104, w: 22, h: 14 }

  it('part du bas de la fiche et arrive sur la pointe haute du losange', () => {
    const p = pointsUnion(epoux, famille)
    expect(p[0]).toEqual({ x: -4700.1, y: -130 })
    expect(p[3]).toEqual({ x: 140, y: -111 })
  })

  it('passe par une barre 16 unités au-dessus du centre du losange', () => {
    const p = pointsUnion(epoux, famille)
    expect(p[1].y).toBe(-120)
    expect(p[2].y).toBe(-120)
    expect(p[1].x).toBe(epoux.x)
    expect(p[2].x).toBe(famille.x)
  })
})

describe('pointsFiliation', () => {
  it('descend du losange, longe la barre partagée, puis rejoint l’enfant', () => {
    const famille: BoiteNoeud = { x: 140, y: -104, w: 22, h: 14 }
    const enfant: BoiteNoeud = { x: 0, y: 0, w: 120, h: 40 }
    const p = pointsFiliation(famille, enfant, -78.8)
    expect(p).toEqual([
      { x: 140, y: -97 },
      { x: 140, y: -78.8 },
      { x: 0, y: -78.8 },
      { x: 0, y: -20 },
    ])
  })
})

describe('hauteursDeBarre', () => {
  const losange = (x: number, y: number): BoiteNoeud => ({ x, y, w: 22, h: 14 })
  const fiche = (x: number, y: number): BoiteNoeud => ({ x, y, w: 120, h: 40 })

  it('ignore une famille sans enfant placé', () => {
    const hauteurs = hauteursDeBarre([{ id: 'f1', famille: losange(0, -104), enfants: [] }])
    expect(hauteurs.has('f1')).toBe(false)
  })

  it('place une famille seule de sa rangée à 30 % de la descente', () => {
    // Losange à -104, haut de l'enfant à -20 : descente de 84, donc 30 % = 25,2.
    const hauteurs = hauteursDeBarre([
      { id: 'f1', famille: losange(0, -104), enfants: [fiche(0, 0)] },
    ])
    expect(hauteurs.get('f1')).toBeCloseTo(-104 + 84 * 0.3, 9)
  })

  it('fait passer le peigne étroit au-dessus du large — ils ne se croisent plus', () => {
    const peignes: PeigneDeFamille[] = [
      // Large : les enfants s'étalent sur 2 000 unités.
      { id: 'large', famille: losange(0, -104), enfants: [fiche(-1000, 0), fiche(1000, 0)] },
      // Étroit : 40 unités.
      { id: 'etroit', famille: losange(500, -104), enfants: [fiche(480, 0), fiche(520, 0)] },
    ]
    const hauteurs = hauteursDeBarre(peignes)
    const etroit = hauteurs.get('etroit')
    const large = hauteurs.get('large')
    expect(etroit).toBeDefined()
    expect(large).toBeDefined()
    if (etroit === undefined || large === undefined) return
    // « Au-dessus » veut dire plus proche des parents, donc un `y` plus petit.
    expect(etroit).toBeLessThan(large)
    expect(etroit).toBeCloseTo(-104 + 84 * 0.3, 9)
    expect(large).toBeCloseTo(-104 + 84 * 0.85, 9)
  })

  it('traite chaque rangée séparément', () => {
    const hauteurs = hauteursDeBarre([
      { id: 'haut', famille: losange(0, -404), enfants: [fiche(0, -300)] },
      { id: 'bas', famille: losange(0, -104), enfants: [fiche(0, 0)] },
    ])
    expect(hauteurs.get('haut')).toBeCloseTo(-404 + (-320 - -404) * 0.3, 9)
    expect(hauteurs.get('bas')).toBeCloseTo(-104 + 84 * 0.3, 9)
  })
})

describe('hauteursDUnion', () => {
  /** Un couple : l'époux à `x`, le losange 46 px plus bas, à `xf`. */
  function barre(cle: string, x: number, xf: number, y = 0): BarreDUnion {
    return {
      cle,
      epoux: { x, y, w: 120, h: 40 },
      famille: { x: xf, y: y + 46, w: 22, h: 14 },
    }
  }

  it('laisse la hauteur historique quand rien ne se recouvre', () => {
    // Deux barres loin l'une de l'autre : aucune raison de les déplacer, et une
    // entrée absente vaut « garde `famille.y - RECUL_UNION` ».
    const hauteurs = hauteursDUnion([barre('a', 0, 60), barre('b', 1000, 1060)])
    expect(hauteurs.size).toBe(0)
  })

  it('sépare deux barres qui se recouvrent', () => {
    // 'longue' va de 0 à 900, 'courte' de 400 à 460 : elles se traversent, et
    // c'est exactement le cas qui produisait deux traits confondus.
    const hauteurs = hauteursDUnion([barre('longue', 0, 900), barre('courte', 400, 460)])
    expect(hauteurs.get('longue')).toBeDefined()
    expect(hauteurs.get('courte')).toBeDefined()
    expect(hauteurs.get('longue')).not.toBeCloseTo(hauteurs.get('courte') ?? 0, 6)
  })

  it('n’ouvre que deux hauteurs quand deux suffisent, même à cinq barres', () => {
    // Une longue qui traverse quatre courtes disjointes entre elles : le nombre
    // minimal de hauteurs est 2, et c'est la propriété des graphes d'intervalles
    // qu'on exploite. Trois hauteurs pour ce cas seraient un étalement inutile,
    // donc des barres plus serrées pour rien.
    const hauteurs = hauteursDUnion([
      barre('longue', 0, 2000),
      barre('c1', 100, 160),
      barre('c2', 600, 660),
      barre('c3', 1100, 1160),
      barre('c4', 1600, 1660),
    ])
    const distinctes = new Set([...hauteurs.values()].map((v) => v.toFixed(6)))
    expect(distinctes.size).toBe(2)
  })

  it('ne laisse aucune paire superposée, et reste dans la bande disponible', () => {
    const barres = [
      barre('a', 0, 800),
      barre('b', 200, 260),
      barre('c', 240, 1400),
      barre('d', 1300, 1360),
      barre('e', 600, 1500),
    ]
    const hauteurs = hauteursDUnion(barres)

    for (const b of barres) {
      const h = hauteurs.get(b.cle)
      if (h === undefined) continue
      // La bande : du bas des fiches à la pointe haute du losange. Sortir par le
      // haut ferait passer le trait dans le losange, par le bas dans la fiche.
      expect(h).toBeGreaterThanOrEqual(b.epoux.y + b.epoux.h / 2)
      expect(h).toBeLessThanOrEqual(b.famille.y - b.famille.h / 2)
    }

    for (let i = 0; i < barres.length; i++) {
      for (let j = i + 1; j < barres.length; j++) {
        const a = barres[i]
        const b = barres[j]
        const recouvre =
          Math.min(Math.max(a.epoux.x, a.famille.x), Math.max(b.epoux.x, b.famille.x)) -
          Math.max(Math.min(a.epoux.x, a.famille.x), Math.min(b.epoux.x, b.famille.x))
        if (recouvre <= 0) continue
        expect(hauteurs.get(a.cle)).not.toBeCloseTo(hauteurs.get(b.cle) ?? -1, 6)
      }
    }
  })

  it('traite chaque rangée séparément', () => {
    // Deux barres identiques sur deux rangées différentes ne se voient pas : leur
    // donner des hauteurs différentes serait déplacer un trait sans raison.
    const hauteurs = hauteursDUnion([barre('haut', 0, 900, -300), barre('bas', 0, 900, 0)])
    expect(hauteurs.size).toBe(0)
  })
})
