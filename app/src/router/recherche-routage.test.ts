import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement, qui lit
// `window.location` : c'est la table seule qu'on importe.
import { routes, sectionDe } from '@/router/routes'
import { resoudreAcces } from '@/router/acces'

/**
 * Ce que ces tests protègent : **« Voir tous les résultats » mène quelque part.**
 *
 * Le lien de l'en-tête pousse vers le nom `recherche` ; sans la route, il
 * tomberait sur l'attrape-tout, sans erreur — c'est ainsi que `/deconnexion` a
 * disparu. Le chemin compte aussi : `/recherche?q=…` se partage et se met en
 * favori. Et la page lit l'arbre : elle est fermée à un compte en attente,
 * comme lui.
 */
function routeur() {
  return createRouter({ history: createMemoryHistory(), routes })
}

describe('la page de tous les résultats', () => {
  it('résout `/recherche?q=` sur son écran, chargé à la demande', () => {
    const resolue = routeur().resolve('/recherche?q=bourrache')

    expect(resolue.name).toBe('recherche')
    expect(resolue.query['q']).toBe('bourrache')
    // Un import dynamique : la page n'alourdit pas le premier chargement.
    const declaree = routes.find((r) => r.name === 'recherche')
    expect(String(declaree?.component)).toContain('RechercheView')
    expect(typeof declaree?.component).toBe('function')
  })

  it('se construit depuis son nom, celui que pousse l’en-tête', () => {
    expect(routeur().resolve({ name: 'recherche', query: { q: 'bo' } }).fullPath).toBe('/recherche?q=bo')
  })

  it('exige un rattachement validé, comme l’arbre', () => {
    const declaree = routes.find((r) => r.name === 'recherche')
    expect(declaree?.meta?.['acces']).toBe('membre')

    const decision = resoudreAcces('membre', 'en_attente', '/recherche?q=bo')
    expect(decision.autorise).toBe(false)
  })

  it('allume l’onglet « Arbre » et remonte vers lui sur téléphone', () => {
    const declaree = routes.find((r) => r.name === 'recherche')!

    expect(sectionDe('recherche', declaree.meta ?? {})).toBe('arbre')
    expect(declaree.meta?.['parent']).toEqual({ nom: 'arbre', libelle: 'Arbre' })
    expect(declaree.meta?.['titre']).toBe('Recherche')
  })
})
