import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement, qui lit
// `window.location` : c'est la table seule qu'on importe.
import { routes } from '@/router/routes'

/**
 * Ce que ces tests protègent : **`/deconnexion` est une adresse, pas seulement un
 * bouton.**
 *
 * Le défaut d'origine : la barre haute portait « Se déconnecter » et fonctionnait,
 * parce qu'elle appelle `deconnecter()` puis pousse vers `connexion` sans jamais
 * passer par une URL. Le chemin `/deconnexion`, lui, n'existait dans aucune table
 * — il tombait donc sur l'attrape-tout `/:chemin(.*)*` et rendait « Page
 * introuvable ». C'est le genre de manque qu'aucun test ne signale et qu'aucun
 * parcours d'écran ne rencontre : il ne se voit que quand quelqu'un tape l'adresse,
 * la met en favori, ou l'envoie à un autre membre de la famille.
 *
 * Trois choses se cassent en silence ici, et chacune a son test :
 *
 *  1. **La route disparaît** — par un renommage, une refonte de la table, ou une
 *     fusion malheureuse. Le symptôme est un écran d'erreur générique, identique à
 *     celui d'une URL inventée : rien ne dit que c'était une route du produit.
 *
 *  2. **La route existe mais ne déconnecte plus.** C'est ce qui arrive si on
 *     remplace la garde par une `redirect` de déclaration, qui paraît plus simple
 *     et l'est en apparence : `vue-router` résout une `redirect` **avant** les
 *     gardes d'entrée, donc `beforeEnter` n'est jamais appelé. On atterrit sur la
 *     connexion avec sa session toujours ouverte — une déconnexion qui ne
 *     déconnecte pas, sur un poste partagé.
 *
 *  3. **Le niveau d'accès glisse de `public` à `anonyme`.** « Réservé à qui n'a
 *     pas de session » est le réflexe pour un écran de sortie, et c'est l'inverse
 *     de ce qu'il faut : la garde globale lit le régime d'AVANT la déconnexion,
 *     voit un membre connecté, et le renvoie vers l'arbre sans rien fermer.
 */

/** Le composable est simulé : la garde ne doit pas partir en requête réseau, et
 *  on veut pouvoir observer que la déconnexion a bien été demandée. */
const deconnecter = vi.fn(async () => {})
let connecte = true

vi.mock('@/composables/useSession', () => ({
  deconnecter: () => deconnecter(),
  useSession: () => ({ connecte: { value: connecte } }),
}))

function routeur() {
  return createRouter({ history: createMemoryHistory(), routes })
}

beforeEach(() => {
  deconnecter.mockClear()
  connecte = true
})

describe('/deconnexion est une route du produit', () => {
  it('existe, et n’est pas avalée par l’attrape-tout', () => {
    const resolue = routeur().resolve('/deconnexion')

    expect(resolue.name).toBe('deconnexion')
    // L'assertion qui porte vraiment : c'est sur « introuvable » que la route
    // retombait, et c'est ce retour-là qu'on veut voir échouer.
    expect(resolue.name).not.toBe('introuvable')
  })

  it('n’est déclarée qu’une fois', () => {
    // Une route en double se résout sans broncher — la première déclarée gagne —
    // et les deux divergent ensuite sans que rien ne le dise. On affirme donc la
    // taille de l'ensemble, et pas seulement sa non-vacuité.
    const declarations = routes.filter((route) => route.name === 'deconnexion')

    expect(declarations).toHaveLength(1)
  })

  it('est ouverte à un visiteur connecté, donc « public » et non « anonyme »', () => {
    const route = routes.find((r) => r.name === 'deconnexion')

    // `anonyme` ferait renvoyer un membre connecté vers l'arbre par la garde
    // globale — avant que `beforeEnter` n'ait pu fermer quoi que ce soit.
    expect(route?.meta?.['acces']).toBe('public')
  })
})

describe('la route déconnecte vraiment, puis mène à la connexion', () => {
  it('appelle la déconnexion et atterrit sur l’écran de connexion', async () => {
    const r = routeur()
    await r.push('/deconnexion')

    expect(deconnecter).toHaveBeenCalledTimes(1)
    expect(r.currentRoute.value.name).toBe('connexion')
  })

  it('n’appelle rien quand il n’y a pas de session, et mène quand même à la connexion', async () => {
    // Sans session, l'appel se solderait par une `401` que le rattrapage global
    // traite comme une session perdue : une seconde redirection concurrente, avec
    // un `?suite=/deconnexion` parasite qui ramènerait ici après la reconnexion.
    connecte = false

    const r = routeur()
    await r.push('/deconnexion')

    expect(deconnecter).not.toHaveBeenCalled()
    expect(r.currentRoute.value.name).toBe('connexion')
    expect(r.currentRoute.value.query['suite']).toBeUndefined()
  })
})
