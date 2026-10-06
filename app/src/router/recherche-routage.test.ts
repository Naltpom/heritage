import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement : la table seule.
import { routes, sectionDe } from '@/router/routes'

/**
 * `/recherche` — « Voir tous les résultats », depuis la recherche de l'en-tête.
 *
 * Une route **ajoutée** : aucune autre n'a été renommée (les notifications portent
 * des noms de route, § 8). Elle est chargée à la demande, comme les autres écrans,
 * et fermée au régime « en attente », comme l'arbre qu'elle lit.
 */
function table() {
  return createRouter({ history: createMemoryHistory(), routes })
}

describe('la route des résultats de recherche', () => {
  it('sert `/recherche?q=…` sous le nom `recherche`, avec sa saisie', async () => {
    const routeur = table()
    await routeur.push({ name: 'recherche', query: { q: 'bourrache' } })

    expect(routeur.currentRoute.value.path).toBe('/recherche')
    expect(routeur.currentRoute.value.query['q']).toBe('bourrache')
    expect(routeur.currentRoute.value.meta['acces']).toBe('membre')
    expect(routeur.currentRoute.value.meta['titre']).toBe('Recherche')
  })

  it('est chargée à la demande, comme les autres écrans', () => {
    const route = routes.find((r) => r.name === 'recherche')

    expect(typeof route?.component).toBe('function')
  })

  it('allume l’onglet Arbre, et remonte vers l’arbre sur téléphone', () => {
    const route = routes.find((r) => r.name === 'recherche')!

    expect(sectionDe('recherche', route.meta ?? {})).toBe('arbre')
    expect(route.meta?.parent?.nom).toBe('arbre')
  })

  it('n’a pris la place d’aucune route existante', () => {
    const noms = routes.flatMap((r) => [r.name, ...(r.children ?? []).map((e) => e.name)]).filter(Boolean)

    expect(noms.filter((n) => n === 'recherche')).toHaveLength(1)
    for (const nom of ['arbre', 'personne.detail', 'histoire', 'demande.detail']) expect(noms, nom).toContain(nom)
  })
})
