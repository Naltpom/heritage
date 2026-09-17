/**
 * La taille et la couleur des bulles.
 *
 * Les deux encodages de la carte, isolés du dessin. Une erreur de formule y est
 * invisible à l'œil — une carte fausse reste une carte plausible — et c'est
 * exactement le genre de panne que cette maison fait tenir par un test.
 */
import { describe, expect, it } from 'vitest'
import {
  RAYON_MAX,
  RAYON_MIN,
  paliersDeCouleur,
  paliersDeTaille,
  rayon,
  teinte,
  teinteParDate,
  teinteParNombre,
} from './echelles'

/** L'aire d'un disque, à la constante π près. */
const aire = (r: number): number => r * r

describe('la taille encode le nombre par l’aire, pas par le rayon', () => {
  it('double l’aire utile quand le nombre double', () => {
    /*
     * LA RÈGLE, ET CE QU'ELLE EMPÊCHE. La quantité qu'un disque donne à
     * percevoir est son aire, qui varie comme le carré du rayon. Un rayon
     * proportionnel au nombre surestimerait au carré : cent événements
     * paraîtraient cent fois un, au lieu de dix fois.
     *
     * On mesure au-dessus du plancher, qui écraserait l'écart en bas d'échelle.
     */
    const a = aire(rayon(20, 100)) - aire(RAYON_MIN)
    const b = aire(rayon(40, 100)) - aire(RAYON_MIN)

    expect(b / a).toBeCloseTo(2, 1)
  })

  it('garde un rayon plancher, sinon un lieu à un seul événement n’est plus cliquable', () => {
    expect(rayon(1, 500)).toBeGreaterThanOrEqual(RAYON_MIN)
  })

  it('plafonne le plus gros lieu', () => {
    expect(rayon(100, 100)).toBeCloseTo(RAYON_MAX, 6)
  })

  it('ne divise pas par zéro quand la carte est vide ou qu’un seul lieu porte tout', () => {
    expect(rayon(0, 0)).toBe(RAYON_MIN)
    expect(rayon(3, 0)).toBe(RAYON_MIN)
    expect(rayon(1, 1)).toBeCloseTo(RAYON_MAX, 6)
  })

  it('reste borné si un nombre dépasse le maximum annoncé', () => {
    expect(rayon(500, 100)).toBeCloseTo(RAYON_MAX, 6)
  })
})

describe('les deux dégradés ne contiennent aucun hexadécimal', () => {
  it('rendent une expression color-mix sur des jetons du thème', () => {
    /*
     * C'est ce qui réconcilie « dégradé continu » et « jamais de couleur en dur
     * dans un composant ». On ne rend pas une couleur, on rend un calcul : le
     * navigateur lit les jetons au moment de peindre, donc le thème sombre suit.
     */
    const parDate = teinteParDate(1850, { min: 1700, max: 2000 })
    const parNombre = teinteParNombre(10, 40)

    for (const expression of [parDate, parNombre]) {
      expect(expression).toContain('color-mix(in oklab')
      expect(expression).toContain('var(--color-')
      expect(expression).not.toMatch(/#[0-9a-fA-F]{3,8}/)
    }
  })

  it('emploie les couleurs de branche, seules déclarées stables au thème', () => {
    /* La charte : « ces cinq couleurs ne changent pas avec le thème — ce sont
       des étiquettes de donnée ». C'est la propriété qu'un encodage exige. */
    expect(teinteParDate(1850, { min: 1700, max: 2000 })).toContain('--color-branche-')
    expect(teinteParNombre(10, 40)).toContain('--color-branche-pm')
  })

  it('place la borne basse du dégradé « par date » sur la plus ancienne année', () => {
    const ancien = teinteParDate(1700, { min: 1700, max: 2000 })
    const recent = teinteParDate(2000, { min: 1700, max: 2000 })

    expect(ancien).toContain('0%')
    expect(recent).toContain('100%')
    expect(ancien).not.toBe(recent)
  })

  it('retombe sur une teinte unique quand toutes les années sont égales', () => {
    /* Bornes confondues : il n'y a pas d'écart à montrer, et surtout rien à
       diviser. */
    expect(() => teinteParDate(1900, { min: 1900, max: 1900 })).not.toThrow()
    expect(teinteParDate(1900, { min: 1900, max: 1900 })).toContain('100%')
  })

  it('ne rend jamais la couleur du fond pour une bulle isolée', () => {
    /* À 0 %, une bulle serait exactement de la couleur du fond, donc invisible —
       et c'est le cas le plus fréquent de cette carte. */
    expect(teinteParNombre(1, 40)).not.toContain(' 0%')
  })
})

describe('la bascule date / nombre', () => {
  const bulle = { anneeMediane: 1850, total: 10 }

  it('suit le mode demandé', () => {
    const bornes = { min: 1700, max: 2000 }
    expect(teinte('date', bulle, bornes, 40)).toBe(teinteParDate(1850, bornes))
    expect(teinte('nombre', bulle, bornes, 40)).toBe(teinteParNombre(10, 40))
  })

  it('retombe sur le nombre quand aucune borne de date n’existe', () => {
    /* Une carte peut n'avoir aucune bulle datée ; le mode « date » n'a alors
       rien à encoder, et un écran sans couleur serait pire qu'un écran qui
       encode autre chose. */
    expect(teinte('date', bulle, null, 40)).toBe(teinteParNombre(10, 40))
  })
})

describe('la légende dit les vrais nombres', () => {
  it('étiquette ses cercles avec des comptes réellement présents', () => {
    /* Une légende calculée sur autre chose que les données affichées finit par
       mentir dès la première retouche. */
    const paliers = paliersDeTaille(40)

    expect(paliers.map((p) => p.nombre)).toEqual([1, 10, 40])
    expect(paliers.map((p) => p.rayon)).toEqual([rayon(1, 40), rayon(10, 40), rayon(40, 40)])
  })

  it('ne répète pas un palier quand le maximum est petit', () => {
    expect(paliersDeTaille(1).map((p) => p.nombre)).toEqual([1])
  })

  it('n’affiche aucun palier sur une carte vide', () => {
    expect(paliersDeTaille(0)).toEqual([])
    expect(paliersDeCouleur('nombre', null, 0)).toEqual([])
    expect(paliersDeCouleur('date', null, 40)).toEqual([])
  })

  it('borne le dégradé « par date » aux années réelles', () => {
    const paliers = paliersDeCouleur('date', { min: 1700, max: 2000 }, 40)

    expect(paliers).toHaveLength(5)
    expect(paliers[0]?.libelle).toBe('1700')
    expect(paliers[4]?.libelle).toBe('2000')
  })
})
