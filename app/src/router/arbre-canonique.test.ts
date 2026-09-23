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
  it('sert `ressorts` — et non plus la sortie brute du moteur', async () => {
    const routeur = table()
    await routeur.push('/arbre')

    expect(routeur.currentRoute.value.name).toBe('arbre')
    expect(routeur.currentRoute.value.meta['disposition']).toBe('ressorts')
  })

  it('est bien celle où tout lecteur connecté atterrit', () => {
    // Le lien entre les deux moitiés du défaut : sans lui, « /arbre sert
    // ressorts » ne dirait rien de ce que les membres voient réellement.
    for (const regime of ['en_attente', 'membre', 'administrateur'] as const) {
      expect(routeParDefaut(regime)).toEqual({ name: 'arbre' })
    }
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
