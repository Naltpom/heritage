import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement, qui lit
// `window.location` : c'est la table seule qu'on importe.
import { routes, sectionDe, titreDEntete } from '@/router/routes'
import { resoudreAcces, type NiveauRequis } from '@/router/acces'
import type { Section } from '@/composables/entete'

/**
 * Ce que ces tests protègent : **Explorer rassemble trois écrans sans en
 * renommer aucun** (refonte du 30/09/2026).
 *
 * Trois choses se cassent en silence ici :
 *
 * 1. **Les noms de route sont un contrat.** Les notifications, l'accueil et les
 *    liens partagés renvoient à `chronologie`, `carte` et `anniversaires`. Que
 *    l'onglet s'appelle désormais « Calendrier » ne change pas le nom : une
 *    route renommée ferait tomber chaque lien qui y mène sur l'écran
 *    « introuvable ».
 * 2. **Les anciennes adresses redirigent.** `/chronologie`, `/carte` et
 *    `/anniversaires` sont dans des favoris et des messages : elles doivent
 *    ouvrir le bon onglet — avec leur ancre et leur requête.
 * 3. **La section de l'en-tête.** La barre du bas marque « Explorer » pour les
 *    trois onglets ; un onglet qui retomberait sur « autre » laisserait la
 *    barre sans entrée active, et le lecteur ne saurait plus où il est.
 */

function routeur() {
  return createRouter({ history: createMemoryHistory(), routes })
}

async function allerA(chemin: string) {
  const r = routeur()
  await r.push(chemin)
  return r.currentRoute.value
}

describe('Explorer ouvre un onglet, jamais une page vide', () => {
  it('/explorer mène à la chronologie, le premier onglet', async () => {
    const route = await allerA('/explorer')

    expect(route.name).toBe('chronologie')
    expect(route.path).toBe('/explorer/chronologie')
  })

  it.each([
    ['/explorer/chronologie', 'chronologie'],
    ['/explorer/carte', 'carte'],
    ['/explorer/calendrier', 'anniversaires'],
  ])('%s résout la route « %s »', (chemin, nom) => {
    expect(routeur().resolve(chemin).name).toBe(nom)
  })

  it('le calendrier garde le nom de route « anniversaires », sous l’adresse /explorer/calendrier', () => {
    expect(routeur().resolve({ name: 'anniversaires' }).path).toBe('/explorer/calendrier')
  })

  it('les trois onglets vivent sous la route parente d’Explorer', () => {
    for (const nom of ['chronologie', 'carte', 'anniversaires']) {
      const resolue = routeur().resolve({ name: nom })
      expect(resolue.matched.map((m) => m.name)).toEqual(['explorer', nom])
    }
  })
})

describe('les anciennes adresses mènent au bon onglet', () => {
  it.each([
    ['/chronologie', 'chronologie', '/explorer/chronologie'],
    ['/carte', 'carte', '/explorer/carte'],
    ['/anniversaires', 'anniversaires', '/explorer/calendrier'],
  ])('%s redirige vers « %s »', async (ancien, nom, chemin) => {
    const route = await allerA(ancien)

    expect(route.name).toBe(nom)
    expect(route.path).toBe(chemin)
  })

  it('garde l’ancre et la requête d’un lien d’avant', async () => {
    // `connexion.test.ts` accepte `/chronologie#1901` comme suite après
    // connexion : il faut qu'elle arrive encore quelque part, et entière.
    const route = await allerA('/chronologie?bande=10#1901')

    expect(route.name).toBe('chronologie')
    expect(route.hash).toBe('#1901')
    expect(route.query['bande']).toBe('10')
  })
})

describe('la section de l’en-tête', () => {
  it.each(['chronologie', 'carte', 'anniversaires', 'explorer'])(
    '« %s » appartient à Explorer',
    (nom) => {
      const resolue = routeur().resolve({ name: nom })
      const section = sectionDe(nom, resolue.meta as { section?: Section })

      expect(section).toBe('explorer')
    },
  )
})

describe('le niveau d’accès', () => {
  it.each(['/explorer/chronologie', '/explorer/carte', '/explorer/calendrier'])(
    '%s est ouvert au compte en attente, fermé à l’anonyme',
    (chemin) => {
      // Ces écrans sont faits d'événements datés : une personne vivante hors
      // cercle n'en fournit aucun, et le compte en attente voit un écran plein
      // de défunts. L'anonyme, lui, repart vers la connexion.
      const niveau = routeur().resolve(chemin).meta['acces'] as NiveauRequis

      expect(niveau).toBe('attente')
      expect(resoudreAcces(niveau, 'en_attente', chemin).autorise).toBe(true)
      expect(resoudreAcces(niveau, 'anonyme', chemin).autorise).toBe(false)
    },
  )
})

describe('le titre de l’en-tête du téléphone', () => {
  it('dit « Explorer » sur les trois onglets, que la rangée nomme déjà', async () => {
    for (const chemin of ['/explorer/chronologie', '/explorer/carte', '/explorer/calendrier']) {
      const route = await allerA(chemin)
      expect(titreDEntete(String(route.name), route.meta), chemin).toBe('Explorer')
    }
  })

  it('garde le titre de la route partout ailleurs', async () => {
    const route = await allerA('/moi')
    expect(titreDEntete(String(route.name), route.meta)).toBe(route.meta.titre)
  })
})
