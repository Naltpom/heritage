import { describe, expect, it } from 'vitest'
import { ArbreAffiche } from './donnees'
import { arbreFictif, coupleSeul, type ArbreDEssai } from './essai/arbreFictif'
import { installerLesPolicesDuDisque } from './essai/polices'
import { afficheSablier, type SablierProduit } from './fabriquer'
import type { ModeDeSablier } from './sablier'
import { versSvg } from './svg'

// Dès le chargement : les `describe` produisent leur affiche à la collecte des cas.
installerLesPolicesDuDisque()

const DATE = new Date(2026, 9, 22)

function produire(essai: ArbreDEssai, mode: ModeDeSablier, masque = false): SablierProduit {
  const A = new ArbreAffiche(essai.charge)
  if (masque) A.masquerVivants()
  return afficheSablier(A, essai.mari, essai.femme, mode, { date: DATE })
}

describe('fidélité du sablier à l’affiche d’origine', () => {
  /*
   * Les valeurs de `affiche_sablier()` (Python) sur le même arbre fictif. Mesuré le
   * 22/10/2026 : tous les éléments des SVG coïncident au millième de millimètre, sauf la
   * naissance « jour et mois » partagée, que l'original ne savait pas écrire.
   */
  it.each([
    ['normal', 943.0572914990844, 762.7929946229754, 4, 1.2578125000000002],
    ['dense', 602.7954983515151, 768.877853397773, 3, 1.77607421875],
  ] as const)('mode %s : dimensions, rangées couchées et grossissement', (mode, l, h, couches, grossissement) => {
    const { scene, sablier } = produire(arbreFictif(), mode)
    expect(scene.largeur).toBeCloseTo(l, 6)
    expect(scene.hauteur).toBeCloseTo(h, 6)
    expect(sablier.R.couches).toBe(couches)
    expect(sablier.grossissement).toBeCloseTo(grossissement, 12)
  })
})

describe.each(['normal', 'dense'] as const)('la disposition du sablier (%s)', (mode) => {
  const { sablier: S } = produire(arbreFictif(), mode)

  it('pose chaque génération sur sa rangée, au-dessus de celle de ses enfants', () => {
    for (const n of S.noeuds) {
      expect(n.y + n.h).toBeCloseTo(S.basRang.get(n.niveau)!, 9)
      for (const p of n.parents) expect(p.y + p.h).toBeLessThan(n.y)
    }
  })

  it('ne fait se chevaucher aucune case d’une même rangée', () => {
    const parRang = new Map<number, typeof S.noeuds>()
    for (const n of S.noeuds) parRang.set(n.niveau, [...(parRang.get(n.niveau) ?? []), n])
    for (const ns of parRang.values()) {
      const tries = [...ns].sort((a, b) => a.x - b.x)
      for (let i = 1; i < tries.length; i++) {
        const [a, b] = [tries[i - 1]!, tries[i]!]
        expect(b.x - b.w / 2, `${a.p.prenoms} / ${b.p.prenoms}`).toBeGreaterThanOrEqual(a.x + a.w / 2 - 1e-6)
      }
    }
  })

  it('rapproche le couple au centre, lui à gauche, elle à droite', () => {
    expect(S.h.x).toBeLessThan(S.f.x)
    expect(S.f.x - S.f.w / 2 - (S.h.x + S.h.w / 2)).toBeCloseTo(S.R.ecart(0, true), 9)
  })

  it('met debout les cases au-delà des rangées couchées', () => {
    expect(S.debout(S.R.couches)).toBe(true)
    expect(S.debout(S.R.couches - 1)).toBe(false)
  })
})

describe('le sablier, cas limites et vivants', () => {
  it('dessine un couple sans ascendance ni descendance (l’original divisait par zéro)', () => {
    for (const mode of ['normal', 'dense'] as const) {
      const { scene } = produire(coupleSeul(), mode)
      expect(Number.isFinite(scene.largeur) && Number.isFinite(scene.hauteur)).toBe(true)
      expect(versSvg(scene)).not.toMatch(/NaN|Infinity/)
    }
  })

  it('masque les dates des vivants, et garde les signes des unions et le titre', () => {
    const textes = produire(arbreFictif(), 'normal', true).scene.textes.map((t) => t.texte)
    expect(textes.some((t) => t.includes('1936'))).toBe(false)
    expect(textes).toContain('Silex')
    expect(textes).toContain('Romarin · Sureau')
    expect(textes).toContain('veuf')
    expect(textes).toContain('div.')
    expect(textes).toContain('Arbre établi avec Heritage — octobre 2026')
  })
})
