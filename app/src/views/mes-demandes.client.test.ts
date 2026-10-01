/**
 * « Mes demandes » — l'icône « Annuler la demande », **montée et cliquée**
 * (`test-utils/rendu-memoire.ts`).
 *
 * `mes-demandes-rendu.test.ts` tient ce que la liste imprime ; ici, on tient ce
 * que le geste fait : la question avant l'envoi, la ligne qui passe à
 * « Annulée » **sur place** — sans relire la liste —, et la relecture quand le
 * serveur répond que la demande a été tranchée entre-temps.
 */
import { defineComponent, h } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ResumeDemande } from '@/lib/api'
import { boutonNomme, cliquer, monterEnMemoire, parAttribut, texte, tous, un, type Noeud } from '@/test-utils/rendu-memoire'

const etat = vi.hoisted(() => ({
  session: null as unknown as { value: unknown },
  vueEmpruntee: null as unknown as { value: unknown },
}))

vi.mock('@/composables/useSession', async () => {
  const { shallowRef } = await import('vue')
  etat.session = shallowRef(null)
  etat.vueEmpruntee = shallowRef(null)
  return {
    useSession: () => ({
      session: etat.session,
      administrateur: shallowRef(false),
      vueEmpruntee: etat.vueEmpruntee,
      incarnation: shallowRef(null),
    }),
    rafraichirSession: () => Promise.resolve(),
  }
})

const api = vi.hoisted(() => ({
  listerDemandes: vi.fn(),
  lireDemande: vi.fn(),
  retirerDemande: vi.fn(),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  ...api,
}))

import { ErreurApi } from '@/lib/api'
import MesDemandesView from './MesDemandesView.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

function resume(fragment: Partial<ResumeDemande> & { id: number }): ResumeDemande {
  return {
    kind: 'modification_personne',
    status: 'en_attente',
    authorUserId: 17,
    authorLabel: 'Opale Bourrache',
    targetKind: 'personne',
    targetId: 41,
    targetLabel: 'Marne Sureau',
    message: null,
    autoValidated: false,
    submittedAt: '2026-09-16T10:56:18+00:00',
    createdAt: '2026-09-16T10:56:18+00:00',
    ...fragment,
  }
}

function liste(elements: ResumeDemande[]) {
  return { elements, total: elements.length, page: 1, parPage: 100 }
}

/** L'icône d'annulation d'une demande, par son numéro. */
function icone(racine: Noeud, id: number): Noeud | null {
  return un(racine, (n) => 'data-retirer' in n.attributs && n.attributs['aria-label'] === `Annuler la demande n° ${id}`)
}

function monter(): Promise<Noeud> {
  return monterEnMemoire(MesDemandesView, { RouterLink: LienFactice })
}

beforeEach(() => {
  // `BarreEcran` écoute les clics hors de son feuillet : rien à écouter ici.
  vi.stubGlobal('document', { addEventListener: () => undefined, removeEventListener: () => undefined })
  vi.stubGlobal('window', {
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    matchMedia: () => ({ matches: false, addEventListener: () => undefined, removeEventListener: () => undefined }),
  })
  Object.values(api).forEach((f) => f.mockReset())
  etat.session.value = { utilisateur: { id: 17, displayName: 'Opale Bourrache' } }
  etat.vueEmpruntee.value = null
})

describe('« Mes demandes » : annuler une demande depuis la liste', () => {
  it('une icône par demande annulable, et seulement là', async () => {
    api.listerDemandes.mockResolvedValue(
      liste([
        resume({ id: 3 }),
        resume({ id: 4, status: 'brouillon' }),
        resume({ id: 5, status: 'validee' }),
        resume({ id: 6, status: 'annulee' }),
      ]),
    )
    const racine = await monter()

    const icones = tous(racine, parAttribut('data-retirer'))
    expect(icones.map((b) => b.attributs['aria-label'])).toEqual([
      'Annuler la demande n° 3',
      'Annuler la demande n° 4',
    ])
    // Une icône seule porte son libellé deux fois : survol et lecteur d'écran.
    for (const icone of icones) expect(icone.attributs['title']).toBe(icone.attributs['aria-label'])
  })

  it('confirme, envoie, puis la ligne passe à « Annulée » sans relire la liste', async () => {
    api.listerDemandes.mockResolvedValue(liste([resume({ id: 3 }), resume({ id: 4 })]))
    api.retirerDemande.mockResolvedValue({ ...resume({ id: 3 }), status: 'annulee' })
    const racine = await monter()

    await cliquer(icone(racine, 3))
    expect(api.retirerDemande).not.toHaveBeenCalled()
    const dialogue = un(racine, (n) => n.tag === 'dialog')!
    expect(dialogue.open).toBe(true)
    expect(texte(dialogue)).toContain('Annuler la demande n° 3 ?')

    await cliquer(un(dialogue, boutonNomme('Annuler ma demande')))

    expect(api.retirerDemande).toHaveBeenCalledWith(3)
    expect(api.listerDemandes).toHaveBeenCalledTimes(1)
    expect(dialogue.open).toBe(false)
    const icones = tous(racine, parAttribut('data-retirer'))
    expect(icones.map((b) => b.attributs['aria-label'])).toEqual(['Annuler la demande n° 4'])
    expect(texte(racine)).toContain('Annulée par vous')
  })

  it('tranchée entre-temps : l’échec se dit sur la ligne, et la liste est relue', async () => {
    api.listerDemandes
      .mockResolvedValueOnce(liste([resume({ id: 3 })]))
      .mockResolvedValueOnce(liste([resume({ id: 3, status: 'validee' })]))
    api.retirerDemande.mockRejectedValue(
      new ErreurApi(409, 'demande_deja_decidee', 'Cette demande a déjà été tranchée.', null, { statut: 'validee' }),
    )
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-retirer')))
    await cliquer(un(un(racine, (n) => n.tag === 'dialog')!, boutonNomme('Annuler ma demande')))

    expect(api.listerDemandes).toHaveBeenCalledTimes(2)
    const contenu = texte(racine)
    expect(contenu).toContain('Cette demande n’a pas pu être annulée.')
    expect(contenu).toContain('Cette demande a déjà été tranchée.')
    expect(un(racine, parAttribut('data-retirer'))).toBeNull()
  })
})
