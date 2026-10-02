import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement : la table seule.
import { routes, sectionDe } from '@/router/routes'
import { resoudreAcces } from '@/router/acces'

/**
 * Ce que ces tests protègent : **le lien personnel d'un invité s'ouvre sans
 * compte**, sur l'écran nu — et les écrans des événements sont ceux qu'on
 * croit.
 *
 * Un lien qui renverrait vers la connexion serait, pour la grand-mère sans
 * compte, un lien qui ne marche pas — sans qu'aucune erreur ne se voie ailleurs.
 * Et `evenement.detail` est un **contrat** : les notifications déjà écrites en
 * base y mènent par son nom.
 */

function routeur() {
  return createRouter({ history: createMemoryHistory(), routes })
}

const CLE = 'kJ8s-2Qb1nR7xTmA0vL5wYcE9hP3dZfG6uIoN4jXqSs'

describe('le lien personnel d’un invité', () => {
  it('ouvre `evenement.lien` avec la clé dans le chemin', () => {
    const resolue = routeur().resolve(`/e/${CLE}`)

    expect(resolue.name).toBe('evenement.lien')
    expect(resolue.params['cle']).toBe(CLE)
  })

  it('est public, sans cadre, et passe la clé en prop', () => {
    const route = routes.find((r) => r.name === 'evenement.lien')

    expect(route?.meta?.acces).toBe('public')
    expect(route?.meta?.cadre).toBe(false)
    expect(route?.props).toBe(true)
  })

  it('laisse passer un visiteur sans session', () => {
    expect(resoudreAcces('public', 'anonyme', `/e/${CLE}`)).toEqual({ autorise: true })
  })
})

describe('les écrans des événements', () => {
  it('distingue la création du détail, et le détail de sa modification', () => {
    const r = routeur()

    expect(r.resolve('/evenements').name).toBe('evenements')
    expect(r.resolve('/evenements/nouveau').name).toBe('evenement.nouveau')
    expect(r.resolve('/evenements/12').name).toBe('evenement.detail')
    expect(r.resolve('/evenements/12/modifier').name).toBe('evenement.modifier')
  })

  it('résout le lien des notifications par son nom, qui ne change pas', () => {
    expect(routeur().resolve({ name: 'evenement.detail', params: { id: 7 } }).fullPath).toBe('/evenements/7')
  })

  it('exige un membre validé, et allume l’onglet « Moi »', () => {
    for (const nom of ['evenements', 'evenement.nouveau', 'evenement.detail', 'evenement.modifier']) {
      const route = routes.find((r) => r.name === nom)
      expect(route?.meta?.acces, nom).toBe('membre')
      expect(sectionDe(nom, {}), nom).toBe('moi')
    }
  })
})
