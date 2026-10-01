import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement : la table seule.
import { routes } from '@/router/routes'
import { resoudreAcces } from '@/router/acces'

/**
 * Ce que ces tests protègent : **le lien personnel d'un tirage s'ouvre sans
 * compte**, sur l'écran nu.
 *
 * Un lien qui renverrait vers la connexion serait, pour une personne sans
 * compte, un lien qui ne marche pas — sans qu'aucune erreur ne se voie ailleurs.
 */

function routeur() {
  return createRouter({ history: createMemoryHistory(), routes })
}

const CLE = 'kJ8s-2Qb1nR7xTmA0vL5wYcE9hP3dZfG6uIoN4jXqSs'

describe('le lien personnel d’un tirage', () => {
  it('ouvre `tirage.lien` avec la clé dans le chemin', () => {
    const resolue = routeur().resolve(`/t/${CLE}`)

    expect(resolue.name).toBe('tirage.lien')
    expect(resolue.params['cle']).toBe(CLE)
  })

  it('est public, sans cadre, et passe la clé en prop', () => {
    const route = routes.find((r) => r.name === 'tirage.lien')

    expect(route?.meta?.acces).toBe('public')
    expect(route?.meta?.cadre).toBe(false)
    expect(route?.props).toBe(true)
  })

  it('laisse passer un visiteur sans session', () => {
    expect(resoudreAcces('public', 'anonyme', `/t/${CLE}`)).toEqual({ autorise: true })
  })
})
