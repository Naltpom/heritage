import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'
import { routes } from '@/router/routes'
import { adresseAbsolue, copierTexte, partager, partagerOuCopier, peutPartager, type Navigateur } from './partage'

/**
 * Partager un lien : la feuille du système, ou le presse-papiers.
 *
 * Ce qui se casse en silence : une annulation prise pour une panne (un
 * message d'erreur parce qu'on a fermé la feuille), une feuille refusée qui ne
 * se rabat pas sur la copie (bouton mort sur un bureau), et une adresse
 * partagée qui emporterait plus que la route de la fiche.
 */

const LIEN = { titre: 'Marne Sureau — Heritage', url: 'https://heritage.test/personnes/17' }

function erreurNommee(name: string): Error {
  return Object.assign(new Error(name), { name })
}

function navigateur(options: { share?: () => Promise<void>; copie?: () => Promise<void> } = {}) {
  const nav: Navigateur & { share?: ReturnType<typeof vi.fn>; clipboard?: { writeText: ReturnType<typeof vi.fn> } } = {}
  if (options.share) nav.share = vi.fn(options.share)
  if (options.copie) nav.clipboard = { writeText: vi.fn(options.copie) }
  return nav
}

describe('partagerOuCopier', () => {
  it('ouvre la feuille de partage quand elle existe, avec le titre et l’adresse seuls', async () => {
    const nav = navigateur({ share: () => Promise.resolve(), copie: () => Promise.resolve() })

    expect(await partagerOuCopier(LIEN, nav)).toBe('partage')
    expect(nav.share).toHaveBeenCalledWith({ title: LIEN.titre, url: LIEN.url })
    expect(nav.clipboard?.writeText).not.toHaveBeenCalled()
  })

  it('la feuille fermée sans rien choisir (`AbortError`) : « annule », et rien de copié', async () => {
    const nav = navigateur({ share: () => Promise.reject(erreurNommee('AbortError')), copie: () => Promise.resolve() })

    expect(await partagerOuCopier(LIEN, nav)).toBe('annule')
    expect(nav.clipboard?.writeText).not.toHaveBeenCalled()
  })

  it('sans feuille de partage (bureau) : le lien est copié', async () => {
    const nav = navigateur({ copie: () => Promise.resolve() })

    expect(await partagerOuCopier(LIEN, nav)).toBe('copie')
    expect(nav.clipboard?.writeText).toHaveBeenCalledWith(LIEN.url)
  })

  it('une feuille refusée pour une autre raison se rabat sur la copie', async () => {
    const nav = navigateur({ share: () => Promise.reject(erreurNommee('NotAllowedError')), copie: () => Promise.resolve() })

    expect(await partagerOuCopier(LIEN, nav)).toBe('copie')
  })

  it('ni feuille ni presse-papiers, ou presse-papiers refusé : « echec »', async () => {
    expect(await partagerOuCopier(LIEN, navigateur())).toBe('echec')
    expect(await partagerOuCopier(LIEN, null)).toBe('echec')
    expect(await partagerOuCopier(LIEN, navigateur({ copie: () => Promise.reject(erreurNommee('NotAllowedError')) }))).toBe(
      'echec',
    )
  })
})

describe('les briques', () => {
  it('peutPartager ne croit que ce qui est une fonction', () => {
    expect(peutPartager(navigateur({ share: () => Promise.resolve() }))).toBe(true)
    expect(peutPartager(navigateur())).toBe(false)
    expect(peutPartager(null)).toBe(false)
  })

  it('copierTexte rend faux sur un refus, sans lever', async () => {
    expect(await copierTexte('x', navigateur({ copie: () => Promise.reject(new Error('refus')) }))).toBe(false)
  })

  it('partager transmet le texte d’accompagnement quand il y en a un', async () => {
    const nav = navigateur({ share: () => Promise.resolve() })
    await partager({ ...LIEN, texte: 'Pour toi' }, nav)
    expect(nav.share).toHaveBeenCalledWith({ title: LIEN.titre, url: LIEN.url, text: 'Pour toi' })
  })
})

describe('adresseAbsolue', () => {
  const routeur = createRouter({ history: createMemoryHistory(), routes })

  it('rend l’adresse canonique de la fiche : origine + route `personne.detail`, et rien d’autre', () => {
    const adresse = adresseAbsolue(routeur, { name: 'personne.detail', params: { id: 17 } }, 'https://heritage.test')
    expect(adresse).toBe('https://heritage.test/personnes/17')
  })

  it('hors navigateur, sans origine, rend le chemin seul', () => {
    expect(adresseAbsolue(routeur, { name: 'personne.detail', params: { id: 17 } }, '')).toBe('/personnes/17')
  })
})
