import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement, qui lit
// `window.location` : c'est la table seule qu'on importe.
import { routes } from '@/router/routes'
import { routeParDefaut } from '@/router/acces'

/**
 * ════════════════════════════════════════════════════════════════════
 * **`/arbre` SERT UNE DISPOSITION, ET PLUS LA SORTIE BRUTE DU MOTEUR.**
 *
 * Le défaut que ces cas protègent était **silencieux et total** : `routeParDefaut()`
 * renvoie `{ name: 'arbre' }` pour tous les régimes, et `/arbre` ne portait aucune
 * `meta.disposition`. Tout membre atterrissait donc sur la mise en page brute du
 * moteur — la seule des sept qui n'avait été retenue par personne —, pendant que le
 * travail de mise en page vivait sur `/arbre-7`, une URL que personne ne tapait.
 *
 * Rien ne rougissait : les deux routes montaient le même composant, et la seule
 * différence était une clé de `meta` absente.
 *
 * Le second cas tient l'autre moitié : les sept maquettes ont été **retirées**, pas
 * masquées. Une route qu'on croit supprimée mais qui subsiste reste une porte
 * ouverte sur les mêmes données ; ici, elle doit tomber sur l'attrape-tout.
 * ════════════════════════════════════════════════════════════════════
 */
function table() {
  return createRouter({ history: createMemoryHistory(), routes })
}

describe('la route de l’arbre', () => {
  it('sert l’arbre à niveaux de détail, sous le nom `arbre`', async () => {
    const routeur = table()
    await routeur.push('/arbre')

    expect(routeur.currentRoute.value.name).toBe('arbre')
    // Le 24/09/2026, `/arbre` est passé à l'écran de `/histoire`, qui ne prend pas
    // de disposition : il porte sa propre mise en page, `histoire/lignees.ts`.
    expect(routeur.currentRoute.value.meta['disposition']).toBeUndefined()
  })

  it('a retiré `/tree` et `/arbre/ancien` : un seul arbre', async () => {
    const routeur = table()

    for (const chemin of ['/tree', '/arbre/ancien']) {
      await routeur.push(chemin)
      expect(routeur.currentRoute.value.name, chemin).toBe('introuvable')
    }
  })

  it('renvoie `/histoire` vers `/arbre`', async () => {
    const routeur = table()
    await routeur.push('/histoire')

    expect(routeur.currentRoute.value.name).toBe('arbre')
  })

  it('est la première porte de l’accueil, où tout membre atterrit', async () => {
    // Depuis le 28/09/2026, on atterrit sur `/home`, et c'est de là qu'on part
    // vers l'arbre : la route canonique reste `arbre`, l'accueil y mène.
    for (const regime of ['membre', 'administrateur'] as const) {
      expect(routeParDefaut(regime)).toEqual({ name: 'accueil' })
    }
    // Le compte en attente, lui, atterrit sur l'écran d'attente (ADR 0003,
    // révision du 01/10/2026) : ni l'accueil ni l'arbre ne lui sont ouverts.
    expect(routeParDefaut('en_attente')).toEqual({ name: 'inscription.etat' })

    const routeur = table()
    await routeur.push('/home')
    expect(routeur.currentRoute.value.name).toBe('accueil')
  })

  it('est fermée au régime « en attente » — membres validés seulement', async () => {
    // ADR 0003, révision du 01/10/2026 : un compte en attente ne voit plus rien
    // de l'arbre, ni vivants ni défunts. L'accueil et la fiche suivent.
    const routeur = table()

    for (const chemin of ['/arbre', '/home', '/personnes/17', '/histoire']) {
      await routeur.push(chemin)
      expect(routeur.currentRoute.value.meta['acces'], chemin).toBe('membre')
    }
  })
})

describe('les maquettes retirées', () => {
  it('ne résolvent plus — aucune des sept', async () => {
    const routeur = table()

    for (const chemin of ['/arbre-2', '/arbre-3', '/arbre-4', '/arbre-5', '/arbre-6', '/arbre-7', '/arbre-8']) {
      await routeur.push(chemin)
      expect(routeur.currentRoute.value.name, chemin).toBe('introuvable')
    }
  })

  it('n’ont plus de nom dans la table', () => {
    const noms = new Set(table().getRoutes().map((r) => r.name))

    for (const nom of ['arbre.conjoints', 'arbre.bornee', 'arbre.croisements', 'arbre.duplication', 'arbre.ressorts', 'arbre.blocs', 'arbre.quilt']) {
      expect(noms.has(nom), nom).toBe(false)
    }
  })
})
