/**
 * « Partager » sur la fiche — monté et cliqué (`test-utils/rendu-memoire.ts`).
 *
 *  1. **Téléphone** : la feuille de partage reçoit le nom et l'adresse
 *     canonique de la fiche, et l'écran n'ajoute rien ;
 *  2. **bureau** : le lien est copié, « Lien copié » s'affiche dans la région
 *     annoncée, puis s'efface ;
 *  3. **annulation** : rien ; **refus** : un message, et l'adresse à copier à
 *     la main.
 *
 * Noms inventés.
 */
import { defineComponent, h } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { FichePersonneComplete } from '@/lib/api'
import { cliquer, monterEnMemoire, parAttribut, texte, un, type Noeud } from '@/test-utils/rendu-memoire'
import PartageFiche from './PartageFiche.vue'

vi.mock('vue-router', () => ({
  useRouter: () => ({
    resolve: (cible: { name: string; params: { id: number } }) => ({
      href: cible.name === 'personne.detail' ? `/personnes/${cible.params.id}` : '/ailleurs',
    }),
  }),
}))

const FICHE = { id: 17, displayName: 'Marne Dolomite Sureau' } as FichePersonneComplete

function navigateur(options: { share?: () => Promise<void>; copie?: () => Promise<void> }) {
  return {
    share: options.share ? vi.fn(options.share) : undefined,
    clipboard: options.copie ? { writeText: vi.fn(options.copie) } : undefined,
  }
}

async function monter(): Promise<Noeud> {
  return monterEnMemoire(defineComponent({ render: () => h(PartageFiche, { fiche: FICHE }) }))
}

const bouton = (racine: Noeud) => un(racine, (n) => n.tag === 'button' && texte(n).includes('Partager'))
const region = (racine: Noeud) => un(racine, (n) => n.attributs['role'] === 'status')

beforeEach(() => {
  vi.stubGlobal('location', { origin: 'https://heritage.test' })
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('« Partager » sur la fiche', () => {
  it('la région annoncée existe avant tout clic, vide', async () => {
    vi.stubGlobal('navigator', navigateur({}))
    const racine = await monter()
    expect(region(racine)).not.toBeNull()
    expect(region(racine)?.attributs['aria-live']).toBe('polite')
    expect(texte(region(racine)!).trim()).toBe('')
  })

  it('téléphone : la feuille de partage reçoit le nom et l’adresse canonique, sans rien d’autre', async () => {
    const nav = navigateur({ share: () => Promise.resolve(), copie: () => Promise.resolve() })
    vi.stubGlobal('navigator', nav)
    const racine = await monter()

    await cliquer(bouton(racine))

    expect(nav.share).toHaveBeenCalledWith({
      title: 'Marne Dolomite Sureau — Heritage',
      url: 'https://heritage.test/personnes/17',
    })
    expect(nav.clipboard?.writeText).not.toHaveBeenCalled()
    expect(texte(region(racine)!).trim()).toBe('')
  })

  it('bureau : le lien est copié, « Lien copié » s’affiche puis s’efface', async () => {
    const nav = navigateur({ copie: () => Promise.resolve() })
    vi.stubGlobal('navigator', nav)
    const racine = await monter()

    vi.useFakeTimers()
    const ecouteur = bouton(racine)!.ecouteurs['click'] as (e: unknown) => void
    ecouteur({})
    await vi.advanceTimersByTimeAsync(0)

    expect(nav.clipboard?.writeText).toHaveBeenCalledWith('https://heritage.test/personnes/17')
    expect(un(racine, parAttribut('data-partage-copie'))).not.toBeNull()
    expect(texte(region(racine)!)).toContain('Lien copié')

    await vi.advanceTimersByTimeAsync(4000)
    expect(un(racine, parAttribut('data-partage-copie'))).toBeNull()
  })

  it('la feuille fermée sans rien choisir : aucun message', async () => {
    const annulation = Object.assign(new Error('Share canceled'), { name: 'AbortError' })
    vi.stubGlobal('navigator', navigateur({ share: () => Promise.reject(annulation), copie: () => Promise.resolve() }))
    const racine = await monter()

    await cliquer(bouton(racine))

    expect(texte(region(racine)!).trim()).toBe('')
  })

  it('copie refusée : on le dit, et l’adresse s’affiche à copier à la main', async () => {
    vi.stubGlobal('navigator', navigateur({ copie: () => Promise.reject(new Error('refus')) }))
    const racine = await monter()

    await cliquer(bouton(racine))

    const echec = un(racine, parAttribut('data-partage-echec'))
    expect(echec).not.toBeNull()
    expect(texte(echec!)).toContain('n’a pas pu être copié')
    expect(texte(echec!)).toContain('https://heritage.test/personnes/17')

    await cliquer(un(echec!, (n) => n.tag === 'button' && texte(n).trim() === 'Fermer'))
    expect(un(racine, parAttribut('data-partage-echec'))).toBeNull()
  })
})
