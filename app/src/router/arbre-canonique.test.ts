import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement, qui lit
// `window.location` : c'est la table seule qu'on importe.
import { routes } from '@/router/routes'
import { routeParDefaut } from '@/router/acces'
import { DISPOSITIONS, PRESENTATIONS, type NomDeDisposition } from '@/components/arbre/dispositions'

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

  it('garde l’écran précédent, `ressorts`, sous `/arbre/ancien`', async () => {
    const routeur = table()
    await routeur.push('/arbre/ancien')

    expect(routeur.currentRoute.value.name).toBe('arbre.ancien')
    // Et non la sortie brute du moteur : c'est le défaut que ce fichier protège.
    expect(routeur.currentRoute.value.meta['disposition']).toBe('ressorts')
  })

  it('renvoie `/histoire` vers `/arbre`', async () => {
    const routeur = table()
    await routeur.push('/histoire')

    expect(routeur.currentRoute.value.name).toBe('arbre')
  })

  it('est la première porte de l’accueil, où tout lecteur connecté atterrit', async () => {
    // Depuis le 28/09/2026, on atterrit sur `/home`, et c'est de là qu'on part
    // vers l'arbre : la route canonique reste `arbre`, l'accueil y mène.
    for (const regime of ['en_attente', 'membre', 'administrateur'] as const) {
      expect(routeParDefaut(regime)).toEqual({ name: 'accueil' })
    }

    const routeur = table()
    await routeur.push('/home')
    expect(routeur.currentRoute.value.name).toBe('accueil')
  })

  it('reste ouverte au régime « en attente »', async () => {
    const routeur = table()
    await routeur.push('/arbre')

    expect(routeur.currentRoute.value.meta['acces']).toBe('attente')
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

describe('le registre et la table ne peuvent plus diverger', () => {
  /*
   * `PRESENTATIONS` est un `Record<NomDeDisposition, …>`, donc `vue-tsc` refuse
   * déjà une entrée dont la clé n'est plus dans `DISPOSITIONS`. Ce qu'il ne voit
   * pas, c'est une disposition DÉCLARÉE que plus aucune route ne demande : elle
   * resterait dans le registre sans écran, et rien ne le dirait.
   */
  it('chaque disposition déclarée est demandée par une route', () => {
    const demandees = new Set(
      table()
        .getRoutes()
        .map((r) => r.meta['disposition'])
        .filter((d): d is NomDeDisposition => d !== undefined),
    )

    expect([...DISPOSITIONS].sort()).toEqual([...demandees].sort())
    expect(Object.keys(PRESENTATIONS).sort()).toEqual([...demandees].sort())
  })
})
