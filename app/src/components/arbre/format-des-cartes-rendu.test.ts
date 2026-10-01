/**
 * La fenêtre « Comment afficher les fiches de l'arbre ? » et le réglage
 * « Affichage des fiches », rendus côté serveur.
 *
 * Ce qu'on tient ici : une vraie **boîte de dialogue** pour les lecteurs
 * d'écran, deux options en groupe radio avec leur aperçu, l'option actuelle
 * cochée, notre voile, et **rien** quand elle est fermée. Le rendu serveur n'a
 * pas de DOM : ce qui se joue à l'exécution dans le navigateur — `show()` et
 * jamais `showModal()`, Échap, le focus — se lit dans la source, comme
 * `reglesMotDePasse.test.ts` le fait pour l'inscription. Quand elle s'ouvre
 * est l'affaire de `composables/formatDesCartes.test.ts`.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import FenetreFormatDesCartes from './FenetreFormatDesCartes.vue'
import InvitationFormatDesCartes from './InvitationFormatDesCartes.vue'
import ReglageFormatDesCartes from './ReglageFormatDesCartes.vue'
import { choisirFormat, reinitialiserFormatDesCartes } from '@/composables/formatDesCartes'
import type { FormatDeCarte } from '@/histoire/cartes'

function rendre(ouverte: boolean, actuel: FormatDeCarte = 'compact'): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(FenetreFormatDesCartes, { ouverte, actuel }) }))
}

/** Les options du groupe radio : `[format, coché]`, dans l'ordre. */
function radios(html: string): [string, boolean][] {
  return [...html.matchAll(/<button[^>]*role="radio"[^>]*>/g)].map((m) => [
    /data-option-format="([^"]+)"/.exec(m[0])?.[1] ?? '',
    /aria-checked="true"/.test(m[0]),
  ])
}

const SOURCE_FENETRE = readFileSync(fileURLToPath(new URL('./FenetreFormatDesCartes.vue', import.meta.url)), 'utf8')
/** Le code seul, sans les commentaires qui, eux, parlent de `showModal()`. */
const CODE_FENETRE = SOURCE_FENETRE.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '')

beforeEach(() => {
  const m = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => m.get(k) ?? null,
    setItem: (k: string, v: string) => void m.set(k, v),
    removeItem: (k: string) => void m.delete(k),
  })
  reinitialiserFormatDesCartes()
})

afterEach(() => {
  vi.unstubAllGlobals()
  reinitialiserFormatDesCartes()
})

describe('la fenêtre ouverte', () => {
  it('est une boîte de dialogue modale, titrée et décrite', async () => {
    const html = await rendre(true)
    expect(html).toMatch(/<dialog[^>]*role="dialog"/)
    expect(html).toMatch(/<dialog[^>]*aria-modal="true"/)
    const titre = /aria-labelledby="([^"]+)"/.exec(html)?.[1]
    const message = /aria-describedby="([^"]+)"/.exec(html)?.[1]
    expect(titre).toBeTruthy()
    expect(html).toMatch(new RegExp(`<h2[^>]*id="${titre}"[^>]*>\\s*Comment afficher les fiches de l’arbre ?`))
    expect(html).toMatch(new RegExp(`id="${message}"[^>]*>\\s*Modifiable à tout moment dans Filtres`))
  })

  it('pose son propre voile, sous la fenêtre', async () => {
    const html = await rendre(true)
    expect(html).toMatch(/<div[^>]*class="fixed inset-0 z-\[600\] bg-black\/50"[^>]*data-voile-format-des-cartes/)
    expect(html).toMatch(/<dialog[^>]*class="[^"]*z-\[610\]/)
  })

  it('propose Compact puis Portrait en groupe radio, l’actuel coché', async () => {
    const html = await rendre(true)
    expect(html).toContain('role="radiogroup"')
    expect(radios(html)).toEqual([
      ['compact', true],
      ['portrait', false],
    ])
    expect(radios(await rendre(true, 'portrait'))).toEqual([
      ['compact', false],
      ['portrait', true],
    ])
  })

  it('montre un aperçu de chaque format, avec une personne inventée', async () => {
    const html = await rendre(true)
    expect(html).toContain('Compact')
    expect(html).toContain('Portrait')
    expect(html.match(/ROMARIN/g)?.length).toBe(2)
    expect(html).toContain('1902 – 1981')
  })

  it('« Garder l’actuel » est là', async () => {
    expect(await rendre(true)).toMatch(/<button[^>]*>[\s\S]*?Garder l’actuel/)
  })
})

describe('fermée', () => {
  it('ne rend que la coquille du `<dialog>`, sans voile, contenu ni bouton', async () => {
    const html = await rendre(false)
    expect(html).toContain('<dialog')
    expect(html).not.toContain(' open')
    expect(html).not.toContain('data-voile-format-des-cartes')
    expect(html).not.toContain('<button')
  })

  it('montée par l’arbre, elle ne s’ouvre pas d’elle-même au rendu', async () => {
    for (const pret of [true, false]) {
      const html = await renderToString(createSSRApp({ render: () => h(InvitationFormatDesCartes, { pret }) }))
      expect(html).not.toContain('<button')
    }
  })
})

describe('ce qui se joue dans le navigateur (lu dans la source)', () => {
  it('ouvre par `show()`, jamais par `showModal()`', () => {
    expect(CODE_FENETRE).toMatch(/\.show\(\)/)
    expect(CODE_FENETRE).not.toMatch(/showModal/)
  })

  it('Échap, `cancel` et le voile valent « garder »', () => {
    expect(CODE_FENETRE).toMatch(/evenement\.key === 'Escape'\)\s*\{\s*surAnnulation\(evenement\)/)
    expect(CODE_FENETRE).toMatch(/function surAnnulation[\s\S]*?emit\('garder'\)/)
    expect(CODE_FENETRE).toMatch(/@cancel="surAnnulation"/)
    expect(CODE_FENETRE).toMatch(/@keydown="surTouche"/)
    expect(CODE_FENETRE).toMatch(/data-voile-format-des-cartes\s*@click="emit\('garder'\)"/)
  })

  it('le focus part sur l’option actuelle', () => {
    expect(CODE_FENETRE).toMatch(/OPTIONS\.findIndex\(\(o\) => o\.valeur === actuel\)/)
    expect(CODE_FENETRE).toMatch(/options\.value\[Math\.max\(0, i\)\]\?\.focus\(\)/)
  })
})

describe('le réglage « Affichage des fiches »', () => {
  it('reflète le format courant en `aria-pressed`', async () => {
    const rendreReglage = (): Promise<string> =>
      renderToString(createSSRApp({ render: () => h(ReglageFormatDesCartes) }))

    const avant = await rendreReglage()
    expect(avant).toContain('Affichage des fiches')
    expect(avant).toContain('aria-label="Format des fiches"')
    expect(avant).toMatch(/aria-pressed="true"[^>]*>\s*Compact/)
    expect(avant).toMatch(/aria-pressed="false"[^>]*>\s*Portrait/)

    choisirFormat('portrait')
    const apres = await rendreReglage()
    expect(apres).toMatch(/aria-pressed="false"[^>]*>\s*Compact/)
    expect(apres).toMatch(/aria-pressed="true"[^>]*>\s*Portrait/)
  })

  it('émet vers `choisirFormat` (lu dans la source)', () => {
    const source = readFileSync(fileURLToPath(new URL('./ReglageFormatDesCartes.vue', import.meta.url)), 'utf8')
    expect(source).toMatch(/@choisir="choisirFormat"/)
  })
})
