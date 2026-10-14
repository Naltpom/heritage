/**
 * `/recherche?q=` — la page de tous les résultats, montée en mémoire
 * (`test-utils/rendu-memoire.ts`).
 *
 * Ce que ces cas protègent : la page part de l'adresse, demande le plafond de
 * l'API (50) et non les huit de l'en-tête, et garde les cinq états distincts —
 * surtout, **une panne n'y devient jamais « Personne ne correspond »**.
 *
 * Noms inventés.
 */
import { defineComponent, h } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ResultatDeRecherche } from '@/lib/api'
import { attendre, boutonNomme, cliquer, demonterTout, monterEnMemoire, parAttribut, texte, tous, un, type Noeud } from '@/test-utils/rendu-memoire'

const api = vi.hoisted(() => ({ rechercherPersonnes: vi.fn() }))
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  rechercherPersonnes: api.rechercherPersonnes,
}))

const etat = vi.hoisted(() => ({
  route: { query: {} as Record<string, unknown> },
  replace: vi.fn(() => Promise.resolve()),
}))
vi.mock('vue-router', () => ({
  useRoute: () => etat.route,
  useRouter: () => ({ replace: etat.replace, push: () => Promise.resolve() }),
}))

import { CODE_RESEAU, ErreurApi } from '@/lib/api/erreurs'
import RechercheView, { saisieDeLAdresse } from './RechercheView.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (props, { slots }) => () => h('a', { href: '#', 'data-vers': JSON.stringify(props.to) }, slots['default']?.()),
})

function resultat(personId: number, displayName: string): ResultatDeRecherche {
  return { personId, displayName, sex: 'masculin', generation: 1, branch: 'mp', vivant: false, visibilite: 'complete', ligneDates: '1901 – 1977' }
}

/** Monte la page sur `?q=…` et laisse passer le délai de frappe (200 ms) et la réponse. */
async function ouvrir(q?: string): Promise<Noeud> {
  etat.route.query = q === undefined ? {} : { q }
  const racine = await monterEnMemoire(RechercheView, { RouterLink: LienFactice })
  await new Promise((fin) => setTimeout(fin, 240))
  await attendre()
  return racine
}

beforeEach(() => {
  vi.stubGlobal('Document', class {})
  vi.stubGlobal('ShadowRoot', class {})
  api.rechercherPersonnes.mockReset()
  etat.replace.mockClear()
})
afterEach(() => {
  demonterTout()
  vi.unstubAllGlobals()
})

describe('saisieDeLAdresse', () => {
  it('lit `?q=` comme une chaîne, le premier d’un tableau, rien sinon', () => {
    expect(saisieDeLAdresse('bo')).toBe('bo')
    expect(saisieDeLAdresse(['so', 'bo'])).toBe('so')
    expect(saisieDeLAdresse(undefined)).toBe('')
    expect(saisieDeLAdresse(null)).toBe('')
  })
})

describe('la page de tous les résultats', () => {
  it('demande tout ce que l’API rend, et le dit quand c’est tronqué', async () => {
    const elements = Array.from({ length: 50 }, (_, i) => resultat(i + 1, `Côme Sureau ${i + 1}`))
    api.rechercherPersonnes.mockResolvedValue({ elements, tronque: true })

    const racine = await ouvrir('sureau')

    expect(api.rechercherPersonnes).toHaveBeenCalledOnce()
    expect(api.rechercherPersonnes.mock.calls[0]?.slice(0, 2)).toEqual(['sureau', 50])
    expect(tous(racine, parAttribut('data-resultat'))).toHaveLength(50)
    expect(texte(un(racine, parAttribut('data-compte'))!)).toContain('les 50 premières')
    // Chaque ligne mène à la fiche et à l'arbre, centré sur la personne.
    const liens = tous(tous(racine, parAttribut('data-resultat'))[0]!, (n) => n.tag === 'a').map((a) =>
      JSON.parse(String(a.attributs['data-vers'])),
    )
    expect(liens).toEqual([
      { name: 'personne.detail', params: { id: 1 } },
      { name: 'arbre', query: { personne: 1 } },
    ])
    expect(texte(racine)).toContain('Père de la mère')
  })

  it('ne dit rien à chercher sans saisie, et n’appelle pas l’API', async () => {
    const racine = await ouvrir()

    expect(un(racine, parAttribut('data-inactif'))).not.toBeNull()
    expect(api.rechercherPersonnes).not.toHaveBeenCalled()
  })

  it('montre le chargement avant la réponse, jamais « Personne ne correspond »', async () => {
    api.rechercherPersonnes.mockReturnValue(new Promise(() => undefined))

    const racine = await ouvrir('bo')

    expect(un(racine, parAttribut('data-chargement'))).not.toBeNull()
    expect(un(racine, parAttribut('data-vide'))).toBeNull()
  })

  it('dit « Personne ne correspond » après une réponse vide', async () => {
    api.rechercherPersonnes.mockResolvedValue({ elements: [], tronque: false })

    const racine = await ouvrir('zz')

    expect(texte(un(racine, parAttribut('data-vide'))!)).toContain('« zz »')
  })

  it('dit une panne comme une panne, et « Réessayer » relance', async () => {
    api.rechercherPersonnes.mockRejectedValueOnce(new ErreurApi(0, CODE_RESEAU, "Le serveur n'a pas répondu."))

    const racine = await ouvrir('bo')

    expect(texte(racine)).toContain("Le serveur n'a pas répondu. Vérifiez votre connexion.")
    expect(un(racine, parAttribut('data-vide'))).toBeNull()

    api.rechercherPersonnes.mockResolvedValueOnce({ elements: [resultat(7, 'Côme Sureau')], tronque: false })
    await cliquer(un(racine, boutonNomme('Réessayer')))

    expect(tous(racine, parAttribut('data-resultat'))).toHaveLength(1)
  })

  it('reporte une nouvelle saisie dans l’adresse, sans entrée d’historique', async () => {
    api.rechercherPersonnes.mockResolvedValue({ elements: [], tronque: false })
    const racine = await ouvrir('bo')
    const champ = un(racine, (n) => n.tag === 'input')!

    Object.assign(champ, { value: ' sureau ' })
    const ecouteurs = champ.ecouteurs['input']
    for (const f of Array.isArray(ecouteurs) ? ecouteurs : [ecouteurs]) if (typeof f === 'function') f({ target: champ })
    await attendre()

    expect(etat.replace).toHaveBeenCalledWith({ query: { q: 'sureau' } })
  })
})
