import { describe, expect, it } from 'vitest'
import { syDe, type Cadrage, type Canevas } from '@/histoire/vue'
import { creerCadence, type Horloge } from './cadence'
import { dansLaFenetre, transformationMonde, transformationRelative, viewBoxDe } from './cadrage'

/**
 * Ce que ces tests protègent : l'écriture du cadrage de `/arbre` dans le DOM, sans Vue
 * (ADR 0007, révision du 2026-10-14). Si le `viewBox` des traits, la transformation
 * des boîtes et celle de la couche des textes ne montrent pas la même chose, les
 * traits glissent sous les fiches — et rien ne l'attrape sans navigateur, sauf ces
 * calculs.
 */
const canevas: Canevas = { largeur: 1200, hauteur: 700, largeurMonde: 24000, hauteurMonde: 900 }

/** Un point monde, projeté à l'écran par le cadrage (la référence). */
function ecran(v: Cadrage, x: number, y: number): [number, number] {
  return [v.tx + x * v.s, v.ty + y * syDe(v.s, canevas)]
}

/** Le même point, projeté par un `viewBox` dans un `<svg preserveAspectRatio="none">`. */
function parViewBox(viewBox: string, x: number, y: number): [number, number] {
  const [vx, vy, vw, vh] = viewBox.split(' ').map(Number) as [number, number, number, number]
  return [((x - vx) * canevas.largeur) / vw, ((y - vy) * canevas.hauteur) / vh]
}

/** Le même point, projeté par `translate(…px, …px) scale(a, b)` (origine en haut à gauche). */
function parTransformation(t: string, x: number, y: number): [number, number] {
  const n = [...t.matchAll(/\(([^)]*)\)/g)].flatMap((m) => m[1]!.split(',').map((x) => parseFloat(x))) as [number, number, number, number]
  return [n[0] + x * n[2], n[1] + y * n[3]]
}

describe('le viewBox des traits (règle 1)', () => {
  it('montre exactement ce que montre le cadrage, échelle verticale séparée comprise', () => {
    for (const v of [
      { s: 0.05, tx: 150, ty: 300 },
      { s: 0.3, tx: -2000, ty: -40 },
      { s: 1.4, tx: -9000, ty: -600 },
    ]) {
      for (const [x, y] of [[0, 0], [5000, 450], [23000, 880]] as const) {
        const [ax, ay] = ecran(v, x, y)
        const [bx, by] = parViewBox(viewBoxDe(v, canevas), x, y)
        const [cx, cy] = parTransformation(transformationMonde(v, canevas), x, y)
        expect(bx).toBeCloseTo(ax, 6)
        expect(by).toBeCloseTo(ay, 6)
        // Les boîtes, sous leur unique transformation, tombent au même endroit que les traits.
        expect(cx).toBeCloseTo(ax, 4)
        expect(cy).toBeCloseTo(ay, 4)
      }
    }
  })

  it('ne rend jamais une boîte vide, même avant la première mesure', () => {
    const [, , w, h] = viewBoxDe({ s: 1, tx: 0, ty: 0 }, { ...canevas, largeur: 0, hauteur: 0 }).split(' ').map(Number)
    expect(w).toBeGreaterThan(0)
    expect(h).toBeGreaterThan(0)
  })
})

describe('la transformation relative de la couche des textes', () => {
  /** Applique `matrix(a, 0, 0, d, e, f)` à un point écran. */
  function appliquer(m: string, X: number, Y: number): [number, number] {
    if (m === 'none') return [X, Y]
    const [a, , , d, e, f] = m.slice(7, -1).split(',').map(Number) as number[]
    return [a! * X + e!, d! * Y + f!]
  }

  it('amène un point posé pour l’instantané à sa place sous le cadrage courant', () => {
    const depuis = { s: 0.5, tx: -300, ty: -100 }
    for (const vers of [
      { s: 0.5, tx: -420, ty: -60 }, // un glisser
      { s: 0.9, tx: -700, ty: -300 }, // un zoom
      { s: 0.1, tx: 100, ty: 200 }, // un dézoom où l'échelle verticale sature
    ]) {
      const m = transformationRelative(depuis, vers, canevas)
      for (const [x, y] of [[100, 50], [3000, 700]] as const) {
        const [X1, Y1] = appliquer(m, ...ecran(depuis, x, y))
        const [ax, ay] = ecran(vers, x, y)
        expect(X1).toBeCloseTo(ax, 6)
        expect(Y1).toBeCloseTo(ay, 6)
      }
    }
  })

  it('rend `none` quand l’instantané est à jour, une translation pendant un glisser', () => {
    const v = { s: 0.5, tx: 1, ty: 2 }
    expect(transformationRelative(v, v, canevas)).toBe('none')
    expect(transformationRelative(v, { ...v }, canevas)).toBe('none')
    expect(transformationRelative({ s: 0.5, tx: 0, ty: 0 }, { s: 0.5, tx: -12, ty: 7 }, canevas)).toBe('matrix(1, 0, 0, 1, -12, 7)')
  })
})

describe('dansLaFenetre', () => {
  const v = { s: 1, tx: 0, ty: 0 }

  it('garde ce qui touche la fenêtre, écarte le reste — comme l’écran le faisait', () => {
    expect(dansLaFenetre(v, canevas, 10, 10, 100, 50)).toBe(true)
    expect(dansLaFenetre(v, canevas, 1300, 10, 100, 50)).toBe(false)
    expect(dansLaFenetre(v, canevas, -150, 10, 100, 50)).toBe(false)
    // Un bord qui touche à peine (0 px) n'est pas dedans.
    expect(dansLaFenetre(v, canevas, -100, 10, 100, 50)).toBe(false)
  })

  it('élargit la fenêtre d’une fraction de sa taille avec une marge', () => {
    expect(dansLaFenetre(v, canevas, 1300, 10, 100, 50, 0.3)).toBe(true)
    expect(dansLaFenetre(v, canevas, 1200 + 360, 10, 100, 50, 0.3)).toBe(false)
  })
})

describe('la cadence de publication à Vue', () => {
  function horloge(): Horloge & { t: number; avancer: (ms: number) => void } {
    let file: { f: () => void; a: number }[] = []
    const h = {
      t: 0,
      maintenant: () => h.t,
      programmer: (f: () => void, ms: number) => {
        const tache = { f, a: h.t + ms }
        file.push(tache)
        return tache
      },
      annuler: (jeton: unknown) => {
        file = file.filter((x) => x !== jeton)
      },
      avancer(ms: number) {
        h.t += ms
        const pretes = file.filter((x) => x.a <= h.t)
        file = file.filter((x) => x.a > h.t)
        pretes.forEach((x) => x.f())
      },
    }
    return h
  }

  it('publie au plus une fois par intervalle pendant un geste, et la dernière demande n’est pas perdue', () => {
    const h = horloge()
    let publications = 0
    const c = creerCadence(160, () => publications++, h)
    // Soixante images d'un glisser, à 16 ms : six ou sept publications, pas soixante.
    for (let i = 0; i < 60; i++) {
      c.demander()
      h.avancer(16)
    }
    expect(publications).toBeGreaterThanOrEqual(6)
    expect(publications).toBeLessThanOrEqual(7)
    const avant = publications
    c.demander()
    h.avancer(200)
    expect(publications).toBe(avant + 1)
  })

  it('publie tout de suite à la fin d’un geste, et oublie l’échéance en attente', () => {
    const h = horloge()
    let publications = 0
    const c = creerCadence(160, () => publications++, h)
    c.demander()
    h.avancer(10)
    c.demander()
    c.maintenant()
    expect(publications).toBe(2)
    h.avancer(500)
    expect(publications).toBe(2)
  })

  it('ne publie plus rien une fois arrêtée', () => {
    const h = horloge()
    let publications = 0
    const c = creerCadence(160, () => publications++, h)
    c.demander()
    c.demander()
    h.avancer(10)
    c.demander()
    c.arreter()
    h.avancer(500)
    expect(publications).toBe(1)
  })
})
