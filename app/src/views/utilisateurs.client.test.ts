/**
 * Utilisateurs — la liste **paginée par l'API** (2026-10-14), écran monté et
 * cliqué (`test-utils/rendu-memoire.ts`).
 *
 * Ce que ce fichier tient : la puce et la recherche partent au serveur — filtrées
 * dans le navigateur, elles n'auraient filtré que la page reçue —, les
 * compteurs des puces viennent de leur route, et « Suivante » relit la page 2.
 * Le reste de l'écran (réciprocité, quotas) a ses propres tests. Noms inventés.
 */
import { defineComponent, h } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { UtilisateurAdministration } from '@/lib/api'
import { attendre, cliquer, demonterTout, monterEnMemoire, parAttribut, texte, tous, un, type Noeud } from '@/test-utils/rendu-memoire'

vi.mock('@/composables/useSession', async () => {
  const { shallowRef } = await import('vue')
  return {
    useSession: () => ({
      session: shallowRef({ utilisateur: { id: 1, displayName: 'Natron' } }),
      administrateur: shallowRef(true),
      vueEmpruntee: shallowRef(null),
      incarnation: shallowRef(null),
    }),
    rafraichirSession: () => Promise.resolve(),
  }
})

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: () => Promise.resolve(), replace: () => Promise.resolve() }),
}))

const api = vi.hoisted(() => ({
  listerUtilisateurs: vi.fn(),
  compterUtilisateurs: vi.fn(),
  listerMembresEnAttente: vi.fn(),
  listerQuotasGlobaux: vi.fn(),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  ...api,
}))

import UtilisateursView from './administration/UtilisateursView.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

function utilisateur(id: number, sur: Partial<UtilisateurAdministration> = {}): UtilisateurAdministration {
  return {
    id,
    email: `compte${id}@example.net`,
    displayName: `Compte ${id}`,
    roles: ['ROLE_USER'],
    status: 'actif',
    totpEnabledAt: null,
    lastLoginAt: null,
    createdAt: '2026-09-01T10:00:00+02:00',
    membre: null,
    quotas: [],
    ignoreRestrictionNaissance: false,
    ...sur,
  }
}

function page(ids: number[], total: number, numero = 1) {
  return { elements: ids.map((id) => utilisateur(id)), total, page: numero, parPage: 25 }
}

const lignes = (racine: Noeud): number[] =>
  tous(racine, (n) => typeof n.attributs['data-utilisateur'] === 'number').map((n) => Number(n.attributs['data-utilisateur']))

const puce = (racine: Noeud, libelle: string): Noeud | null =>
  un(racine, (n) => n.tag === 'button' && n.attributs['aria-pressed'] !== undefined && texte(n).startsWith(libelle))

beforeEach(() => {
  vi.stubGlobal('document', { addEventListener: () => undefined, removeEventListener: () => undefined })
  vi.stubGlobal('window', {
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    matchMedia: () => ({ matches: false, addEventListener: () => undefined, removeEventListener: () => undefined }),
  })
  Object.values(api).forEach((f) => f.mockReset())
  api.listerMembresEnAttente.mockResolvedValue({ elements: [], total: 0, page: 1, parPage: 100 })
  api.listerQuotasGlobaux.mockResolvedValue({ quotas: [] })
  api.compterUtilisateurs.mockResolvedValue({ tous: 30, en_attente: 2, actif: 26, suspendu: 2, admins: 1 })
  api.listerUtilisateurs.mockResolvedValue(page([1, 2, 3], 30))
})

afterEach(() => demonterTout())

describe('Utilisateurs — la liste paginée par l’API', () => {
  it('lit la première page de 25, et compte les puces par leur route', async () => {
    const racine = await monterEnMemoire(UtilisateursView, { RouterLink: LienFactice })
    await attendre()

    expect(api.listerUtilisateurs).toHaveBeenCalledWith({ status: undefined, role: undefined, q: '', page: 1, parPage: 25 })
    expect(lignes(racine)).toEqual([1, 2, 3])
    expect(texte(puce(racine, 'Tous')!)).toBe('Tous30')
    expect(texte(puce(racine, 'Admins')!)).toBe('Admins1')
    expect(texte(un(racine, parAttribut('data-pagination'))!)).toContain('1–25 sur 30 · page 1 sur 2')
  })

  it('« Suivante » relit la page 2 ; une puce y part au serveur et ramène à la page 1', async () => {
    const racine = await monterEnMemoire(UtilisateursView, { RouterLink: LienFactice })
    await attendre()

    api.listerUtilisateurs.mockResolvedValue(page([26, 27], 30, 2))
    await cliquer(un(racine, parAttribut('data-page-suivante')))
    expect(api.listerUtilisateurs).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }))
    expect(lignes(racine)).toEqual([26, 27])

    api.listerUtilisateurs.mockResolvedValue(page([4], 1))
    await cliquer(puce(racine, 'Admins'))
    expect(api.listerUtilisateurs).toHaveBeenLastCalledWith({ status: undefined, role: 'admin', q: '', page: 1, parPage: 25 })
    expect(lignes(racine)).toEqual([4])
    // Une seule page : plus de pagination.
    expect(un(racine, parAttribut('data-pagination'))).toBeNull()

    await cliquer(puce(racine, 'Suspendus'))
    expect(api.listerUtilisateurs).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'suspendu', role: undefined }))
  })

  it('la recherche part au serveur après une courte pause', async () => {
    const racine = await monterEnMemoire(UtilisateursView, { RouterLink: LienFactice })
    await attendre()
    api.listerUtilisateurs.mockClear()

    const champ = un(racine, (n) => n.tag === 'input' && n.attributs['type'] === 'search')
    const ecouteur = champ?.ecouteurs['input']
    if (typeof ecouteur !== 'function') throw new Error('Le champ n’écoute pas la saisie.')
    ecouteur({ target: { value: ' Romarin ' } })
    expect(api.listerUtilisateurs).not.toHaveBeenCalled()

    await new Promise((fin) => setTimeout(fin, 350))
    await attendre()
    expect(api.listerUtilisateurs).toHaveBeenCalledWith(expect.objectContaining({ q: 'Romarin', page: 1 }))
  })
})
