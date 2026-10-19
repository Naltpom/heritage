/**
 * Découvrir à qui j'offre, sur l'accueil — la carte montée et cliquée
 * (`test-utils/rendu-memoire.ts`).
 *
 * Ce que ces tests protègent : **le receveur est un secret**. Rien n'est
 * demandé au serveur avant le geste ; « Découvrir » le demande et l'affiche —
 * photo ou initiales, nom, âge, lien, budget ; « Cacher » le retire du DOM, et
 * le redécouvrir ne redemande rien. Un `404` le dit sans alarme.
 *
 * Noms inventés.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ErreurApi, type ResumeDuTirageDeNoel } from '@/lib/api'
import { boutonNomme, cliquer, demonterTout, monterEnMemoire, parAttribut, texte, un } from '@/test-utils/rendu-memoire'
import { h } from 'vue'

const api = vi.hoisted(() => ({ lireMonAttributionDeNoel: vi.fn() }))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  ...api,
}))

import CarteNoelAccueil from './CarteNoelAccueil.vue'

const TIRE: ResumeDuTirageDeNoel = {
  code: 'ROMA26',
  titre: 'Noël des Romarin',
  dateTirage: '2026-12-01T00:00:00+01:00',
  dateEvenement: '2026-12-24T00:00:00+01:00',
  statut: 'tire',
  estCreateur: false,
  reponse: 'oui',
  nbParticipants: 6,
  budget: 30,
  lieu: null,
}

function monter() {
  return monterEnMemoire({ render: () => h(CarteNoelAccueil, { tirage: TIRE, aujourdhui: new Date(2026, 11, 2, 12) }) })
}

// Des accolades : une fonction rendue par `beforeEach` est appelée au nettoyage —
// et `mockReset()` rend l'espion lui-même.
beforeEach(() => {
  api.lireMonAttributionDeNoel.mockReset()
})
afterEach(demonterTout)

describe('CarteNoelAccueil — découvrir à qui j’offre', () => {
  it('ne demande rien avant le geste, puis montre le receveur ; « Cacher » le retire', async () => {
    api.lireMonAttributionDeNoel.mockResolvedValue({ userId: 2, prenom: 'Cobalt', nom: 'Romarin', photoUrl: null, age: 12, lien: 'cousin germain' })
    const racine = await monter()

    expect(api.lireMonAttributionDeNoel).not.toHaveBeenCalled()
    expect(texte(racine)).not.toContain('Cobalt')

    await cliquer(un(racine, parAttribut('data-decouvrir')))

    expect(api.lireMonAttributionDeNoel).toHaveBeenCalledWith('ROMA26', expect.anything())
    const attribution = un(racine, parAttribut('data-attribution'))
    expect(attribution).not.toBeNull()
    const lu = texte(attribution!)
    expect(lu).toContain('Tu offres à')
    expect(lu).toContain('Cobalt Romarin')
    expect(lu).toContain('Cousin germain')
    expect(lu).toContain('12 ans')
    expect(lu).toContain('budget 30 €')
    expect(lu).toContain('CR') // les initiales, faute de photo

    await cliquer(un(racine, boutonNomme('Cacher')))
    expect(texte(racine)).not.toContain('Cobalt')

    // Redécouvrir ne redemande rien : la réponse est gardée.
    await cliquer(un(racine, parAttribut('data-decouvrir')))
    expect(texte(racine)).toContain('Cobalt Romarin')
    expect(api.lireMonAttributionDeNoel).toHaveBeenCalledTimes(1)
  })

  it('montre la photo quand il y en a une', async () => {
    api.lireMonAttributionDeNoel.mockResolvedValue({ userId: 2, prenom: 'Cobalt', nom: 'Romarin', photoUrl: '/api/noel/photos/2', age: null, lien: null })
    const racine = await monter()
    await cliquer(un(racine, parAttribut('data-decouvrir')))

    expect(un(racine, (n) => n.tag === 'img' && n.attributs['src'] === '/api/noel/photos/2')).not.toBeNull()
  })

  it('un 404 se dit sans alarme', async () => {
    api.lireMonAttributionDeNoel.mockImplementation(() => Promise.reject(new ErreurApi(404, 'introuvable', 'Aucune attribution pour toi dans ce tirage.')))
    const racine = await monter()
    await cliquer(un(racine, parAttribut('data-decouvrir')))

    expect(texte(racine)).toContain('Aucun receveur ne t’est attribué dans ce tirage.')
    expect(un(racine, parAttribut('data-attribution'))).toBeNull()
  })
})
