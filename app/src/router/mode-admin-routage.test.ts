import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement : la table seule.
import { routes, sectionDe } from '@/router/routes'
import { definirPage, reinitialiserEntete } from '@/composables/entete'
import { memoriserRoute, reinitialiserModeAdmin, useModeAdmin } from '@/composables/modeAdmin'

/**
 * Le mode administration (handoff « mode Administration », 1c) **se déduit de
 * la route** : toute route `administration.*` allume la section
 * `administration`, et les anciens noms de route — un contrat, que portent les
 * notifications déjà écrites — mènent à la file « À traiter », filtrée.
 */

function routeur() {
  return createRouter({ history: createMemoryHistory(), routes })
}

afterEach(() => {
  reinitialiserEntete()
  reinitialiserModeAdmin()
})

describe('les routes du mode administration', () => {
  it('rangent toute route d’administration dans la section `administration`', () => {
    for (const nom of [
      'administration.a-traiter',
      'administration.demandes',
      'administration.utilisateurs',
      'administration.invitations',
      'administration.sources',
    ]) {
      expect(sectionDe(nom, {}), nom).toBe('administration')
    }
    expect(sectionDe('moi', {})).toBe('moi')
    expect(sectionDe('demande.detail', {})).toBe('moi')
  })

  it('ouvrent « À traiter » par défaut', async () => {
    const r = routeur()
    await r.push('/administration')
    expect(r.currentRoute.value.name).toBe('administration.a-traiter')
  })

  it('mènent le lien profond `administration.rgpd` à la file filtrée sur le RGPD', async () => {
    const r = routeur()
    await r.push({ name: 'administration.rgpd' })

    expect(r.currentRoute.value.name).toBe('administration.a-traiter')
    expect(r.currentRoute.value.query['type']).toBe('rgpd')
  })

  it('mènent `administration.rattachements` à la file filtrée sur les rattachements', async () => {
    const r = routeur()
    await r.push({ name: 'administration.rattachements' })

    expect(r.currentRoute.value.name).toBe('administration.a-traiter')
    expect(r.currentRoute.value.query['type']).toBe('rattachement')
  })

  it('gardent `administration.demandes` : l’historique des demandes traitées', () => {
    const r = routeur().resolve({ name: 'administration.demandes' })
    expect(r.name).toBe('administration.demandes')
    expect(r.meta.titre).toBe('Historique des demandes')
  })

  it('exigent l’administration, enfant par enfant', () => {
    const parent = routes.find((route) => route.name === 'administration')
    for (const enfant of parent?.children ?? []) {
      expect(enfant.meta?.['acces'], String(enfant.name)).toBe('administration')
    }
  })
})

describe('l’interrupteur Membre / Admin', () => {
  it('lit le mode dans la section publiée par le routeur', () => {
    const { estAdmin } = useModeAdmin()

    definirPage({ section: 'arbre', titre: 'Arbre', parent: null })
    expect(estAdmin.value).toBe(false)
    definirPage({ section: 'administration', titre: 'Sources', parent: null })
    expect(estAdmin.value).toBe(true)
  })

  it('mène par défaut à « À traiter », puis à l’accueil', () => {
    const { cibleDeBascule } = useModeAdmin()

    definirPage({ section: 'arbre', titre: 'Arbre', parent: null })
    expect(cibleDeBascule.value).toEqual({ name: 'administration.a-traiter' })
    definirPage({ section: 'administration', titre: 'À traiter', parent: null })
    expect(cibleDeBascule.value).toEqual({ name: 'accueil' })
  })

  it('ramène à la dernière route visitée de l’autre mode', () => {
    const { cibleDeBascule } = useModeAdmin()

    memoriserRoute('arbre', '/arbre?personne=12', true)
    memoriserRoute('administration', '/administration/sources', true)
    // Les impasses et les écrans sans cadre ne sont pas des endroits où revenir.
    memoriserRoute('autre', '/introuvable', true)
    memoriserRoute('moi', '/connexion', false)

    definirPage({ section: 'arbre', titre: 'Arbre', parent: null })
    expect(cibleDeBascule.value).toBe('/administration/sources')
    definirPage({ section: 'administration', titre: 'Sources', parent: null })
    expect(cibleDeBascule.value).toBe('/arbre?personne=12')
  })
})
