import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement : la table seule.
import { routes } from '@/router/routes'
import { resoudreAcces, type NiveauRequis } from '@/router/acces'
import { ROUTE_OUVERTURE_NOTIFICATION, lienDepuisRequetePush } from '@/lib/notifications'

/**
 * `/notification/:id` — l'adresse qu'ouvre le toucher d'une notification push.
 *
 * Ce que ces tests protègent : **un contrat avec le service worker.** `sw.js`
 * écrit `/notification/<id>?r=<linkRoute>&p=<linkParams JSON>` dans chaque
 * notification qu'il affiche ; une notification déjà posée sur un téléphone
 * ne se réécrit pas. Si le chemin tombait dans l'attrape-tout, le toucher
 * ouvrirait « Page introuvable » — sans que rien d'autre ne casse.
 */

function routeur() {
  return createRouter({ history: createMemoryHistory(), routes })
}

describe('la route d’ouverture d’une notification', () => {
  it('résout le chemin du service worker sur son nom', () => {
    const r = routeur().resolve('/notification/42?r=demande.detail&p=%7B%22id%22%3A6%7D')

    expect(r.name).toBe(ROUTE_OUVERTURE_NOTIFICATION)
    expect(r.name).toBe('notification.ouvrir')
    expect(r.params['id']).toBe('42')
    expect(r.query['r']).toBe('demande.detail')
  })

  it('n’accepte qu’un identifiant entier', () => {
    expect(routeur().resolve('/notification/abc').name).toBe('introuvable')
  })

  it('est ouverte à tout compte connecté, même en attente, et sans cadre', () => {
    const trouvee = routes.find((route) => route.name === 'notification.ouvrir')

    expect(trouvee?.meta?.['acces'] as NiveauRequis).toBe('attente')
    expect(trouvee?.meta?.['cadre']).toBe(false)
    expect(resoudreAcces('attente', 'en_attente', '/notification/1').autorise).toBe(true)
  })

  it('renvoie un anonyme à la connexion, qui le ramènera ici', () => {
    const decision = resoudreAcces('attente', 'anonyme', '/notification/1?r=arbre')

    expect(decision.autorise).toBe(false)
  })
})

describe('le lien calculé depuis la requête', () => {
  it('résout une route à paramètres', () => {
    expect(lienDepuisRequetePush(routeur(), 'demande.detail', '{"id":6}')).toEqual({
      name: 'demande.detail',
      params: { id: 6 },
    })
  })

  it('résout une route sans paramètre', () => {
    expect(lienDepuisRequetePush(routeur(), 'arbre', undefined)).toEqual({ name: 'arbre', params: {} })
  })

  it('rend null — donc l’accueil — pour tout ce qui ne se résout pas', () => {
    const r = routeur()
    expect(lienDepuisRequetePush(r, undefined, undefined)).toBeNull()
    expect(lienDepuisRequetePush(r, 'ecran.disparu', undefined)).toBeNull()
    expect(lienDepuisRequetePush(r, 'demande.detail', undefined)).toBeNull()
    expect(lienDepuisRequetePush(r, 'demande.detail', 'pas du json')).toBeNull()
    expect(lienDepuisRequetePush(r, 'demande.detail', '[6]')).toBeNull()
    expect(lienDepuisRequetePush(r, ['arbre'], undefined)).toBeNull()
  })

  it('ne renvoie jamais sur elle-même', () => {
    expect(lienDepuisRequetePush(routeur(), 'notification.ouvrir', '{"id":1}')).toBeNull()
  })
})
