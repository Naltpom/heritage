import { describe, expect, it } from 'vitest'

/**
 * L'ADR 0007, vérifié en relecture — par un test, puisque c'est le but d'une
 * règle « vérifiable en relecture » : l'agent qui touchera ces fichiers dans six
 * mois ne lira peut-être pas l'ADR, mais il verra ce test rougir.
 *
 * On lit le SOURCE des composants de l'arbre (le `?raw`, idiome de la maison :
 * `views/cadre.test.ts`). C'est grossier, et c'est exprès : un test qui
 * appellerait une fonction ne dirait rien d'un gabarit qui lie `opacity`.
 */
const SOURCES = {
  ...import.meta.glob<string>('./*.vue', { query: '?raw', import: 'default', eager: true }),
  ...import.meta.glob<string>('../views/HistoireView.vue', { query: '?raw', import: 'default', eager: true }),
}

describe('l’arbre respecte l’ADR 0007', () => {
  it('lit bien les composants de l’arbre', () => {
    expect(Object.keys(SOURCES)).toEqual(
      expect.arrayContaining(['./TracesArbre.vue', './FichesMonde.vue', './CalqueLibelles.vue', '../views/HistoireView.vue']),
    )
  })

  it('ne pose `opacity` nulle part : `fill-opacity`, `stroke-opacity` ou une couleur transparente (règle 2)', () => {
    for (const [fichier, source] of Object.entries(SOURCES)) {
      // `opacity:` en CSS ou dans un objet de style, `:opacity=` lié, `opacity="…"` en attribut.
      expect(source, fichier).not.toMatch(/(?<![-\w])opacity\s*:/)
      expect(source, fichier).not.toMatch(/[\s:]opacity\s*=/)
      // Les utilitaires Tailwind `opacity-75`, `bg-x/opacity`… ne sont pas mieux.
      expect(source, fichier).not.toMatch(/(?<![-\w])opacity-\d/)
    }
  })

  it('ne zoome pas le `<svg>` par une transformation : son `viewBox` (règle 1)', () => {
    for (const [fichier, source] of Object.entries(SOURCES)) {
      expect(source, fichier).not.toMatch(/<g[^>]*transform/)
      expect(source, fichier).not.toMatch(/:transform=/)
    }
    expect(SOURCES['./TracesArbre.vue']).toContain("setAttribute('viewBox'")
    expect(SOURCES['./TracesArbre.vue']).toContain('preserveAspectRatio="none"')
  })

  it('ne rend aucun trait ni aucune boîte par `v-for` (règle 3)', () => {
    // Le `<svg>` des traits est vide dans le gabarit : ses chemins sont construits à la main.
    expect(SOURCES['./TracesArbre.vue']).toMatch(/<svg[^>]*\/>/)
    expect(SOURCES['./FichesMonde.vue']).not.toContain('v-for')
    expect(SOURCES['../views/HistoireView.vue']).not.toMatch(/v-for="[^"]*disposition\.(fiches|traces)/)
  })

  it('n’écrit le cadrage dans aucun gabarit : il passe par le DOM, pas par un rendu (règle 3)', () => {
    const vue = SOURCES['../views/HistoireView.vue']!
    // L'ancien `:style="{ transform: transformationMonde }"` rendait l'écran à chaque image.
    expect(vue).not.toMatch(/:style="\{\s*transform/)
    expect(vue).not.toContain('transformationTraits')
  })
})
