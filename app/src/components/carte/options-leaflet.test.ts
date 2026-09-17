/**
 * Les options passées à Leaflet.
 *
 * Ce fichier n'importe de `leaflet` que des **types**, qui disparaissent à la
 * compilation. C'est ce qui lui permet d'exister : un import de valeur ferait
 * lire `document.documentElement.style` à l'évaluation du module, et il n'y a
 * pas de `document` sous l'environnement `node` de vitest.
 */
import { describe, expect, it } from 'vitest'
import { GABARIT_TUILES, HOTE_TUILES, optionsBulle, optionsPays } from './options-leaflet'
import { RAYON_MAX, RAYON_MIN, rayon } from './echelles'

describe('les options d’une bulle respectent l’ADR 0007', () => {
  it('ne passe que « opacity » et « fillOpacity », que Leaflet écrit en attributs', () => {
    /*
     * L'ADR 0007 interdit la propriété CSS `opacity` sur un élément SVG et
     * prescrit `fill-opacity` / `stroke-opacity`. Vérifié dans
     * `leaflet/src/layer/vector/SVG.js` : `_updateStyle()` écrit ces deux
     * attributs-là. L'emploi est donc conforme — ce qui serait fautif, c'est un
     * `style.opacity` ou une classe `opacity-*` sur l'élément.
     */
    const options = optionsBulle(10, 40, 'var(--color-branche-pm)', false)

    expect(options.opacity).toBeTypeOf('number')
    expect(options.fillOpacity).toBeTypeOf('number')
    expect(Object.keys(options)).not.toContain('style')
  })

  it('laisse le dégradé à « fillColor » — aucune classe ne déclare « fill »', () => {
    /* Leaflet écrit `fill` en attribut ; une règle CSS de même nom le battrait
       et toutes les bulles deviendraient de la même couleur, sans erreur. */
    const teinte = 'color-mix(in oklab, var(--color-branche-pm) 60%, var(--color-fond))'
    const options = optionsBulle(10, 40, teinte, false)

    expect(options.fillColor).toBe(teinte)
    expect(options.color).toBe(teinte)
  })

  it('donne à la bulle le rayon que l’échelle calcule, et rien d’autre', () => {
    /* Le plancher est la valeur en zéro, pas la valeur en un : une bulle à un
       seul événement sur quarante est déjà au-dessus. C'est ce qui distingue
       l'interpolation sur l'aire de la forme naïve, où le plancher s'ajoutait. */
    expect(optionsBulle(10, 40, 'x', false).radius).toBe(rayon(10, 40))
    expect(optionsBulle(1, 40, 'x', false).radius ?? 0).toBeGreaterThanOrEqual(RAYON_MIN)
    expect(optionsBulle(0, 40, 'x', false).radius).toBe(RAYON_MIN)
    expect(optionsBulle(40, 40, 'x', false).radius).toBeCloseTo(RAYON_MAX, 6)
  })

  it('garde un trait épais : c’est la cible tactile autant que la décoration', () => {
    /* Le test de collision d'un tracé SVG inclut la largeur du trait. Sur un
       téléphone, c'est ce qui rend atteignable un lieu à un seul événement. */
    expect(optionsBulle(1, 40, 'x', false).weight ?? 0).toBeGreaterThanOrEqual(8)
  })

  it('distingue la bulle sélectionnée par une classe, pas par une couleur en dur', () => {
    expect(optionsBulle(1, 40, 'x', true).className).toContain('bulle-selection')
    expect(optionsBulle(1, 40, 'x', false).className).toBe('bulle')
  })

  it('n’avale pas le clic du fond : la bulle ne propage pas ses événements', () => {
    expect(optionsBulle(1, 40, 'x', false).bubblingMouseEvents).toBe(false)
  })
})

describe('le fond vectoriel', () => {
  it('n’est pas interactif — sinon les pays avalent les clics des bulles', () => {
    expect(optionsPays().interactive).toBe(false)
  })

  it('ne porte aucune couleur : « carte.css » les peint depuis le thème', () => {
    const options = optionsPays()

    expect(options.fillColor).toBeUndefined()
    expect(options.color).toBeUndefined()
    expect(options.className).toBe('pays')
  })
})

describe('l’unique hôte externe du produit', () => {
  it('est nommé une seule fois, et en https', () => {
    /* Le même hôte doit figurer dans la CSP de `app/nginx.conf`. Deux endroits
       qui l'écrivent, c'est un jour où ils diffèrent et où le fond détaillé ne
       charge plus rien, sans message. */
    expect(HOTE_TUILES).toBe('https://tile.openstreetmap.org')
    expect(GABARIT_TUILES.startsWith(HOTE_TUILES)).toBe(true)
    expect(GABARIT_TUILES).toContain('{z}/{x}/{y}')
  })

  it('n’est qu’un gabarit : aucune requête ne part de ce module', () => {
    /* Une URL de tuile est un `{z}/{x}/{y}` — une zone regardée, jamais une
       personne. Et rien ne part tant que le lecteur n'a pas appuyé. */
    expect(GABARIT_TUILES).not.toMatch(/\d+\/\d+\/\d+/)
  })
})
