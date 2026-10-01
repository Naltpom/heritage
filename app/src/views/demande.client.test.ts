/**
 * `/demandes/{id}` — « Voir la fiche » et « Annuler ma demande », **montés et
 * cliqués**.
 *
 * Les autres tests de rendu passent par le SSR, qui ne sait ni attendre une
 * lecture ni cliquer. Or les deux gestes de ce lot n'existent qu'après une
 * lecture (la demande) et ne se prouvent qu'au clic (la fiche s'ouvre, la
 * demande passe à « Annulée »). On monte donc la vue dans un **rendu en
 * mémoire** — `createRenderer` de Vue, sur des nœuds de quelques lignes —, sans
 * DOM ni dépendance de test supplémentaire. La chaîne qu'on lit à la fin est la
 * même forme que celle du SSR (`test-utils/rendu-memoire.ts`).
 *
 * Ce que ces tests tiennent :
 *
 * 1. **le bouton « Voir la fiche » n'existe que si la demande désigne une
 *    fiche**, et il ouvre la **même** fiche en colonne (`max-md:hidden`) et en
 *    modale (`md:hidden`) — une seule lecture ;
 * 2. une création **en attente** montre la fiche du parent ou du conjoint ;
 * 3. **« Annuler ma demande »** : visible pour l'auteur seulement, en attente ou
 *    en brouillon seulement ; confirmé avant l'envoi ; la pastille dit ensuite
 *    « Annulée » ; un `409` relit la demande et le dit ; sous la vue empruntée,
 *    le bouton est éteint et dit pourquoi.
 */
import { defineComponent, h } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Demande, FichePersonneComplete } from '@/lib/api'
import { cliquer, monterEnMemoire, parAttribut, boutonNomme, texte, un, versHtml, type Noeud } from '@/test-utils/rendu-memoire'

/* ------------------------------------------------------------------ */
/* Les dépendances de la vue                                           */
/* ------------------------------------------------------------------ */

const etat = vi.hoisted(() => ({
  session: null as unknown as { value: unknown },
  administrateur: null as unknown as { value: boolean },
  vueEmpruntee: null as unknown as { value: unknown },
  incarnation: null as unknown as { value: unknown },
}))

vi.mock('@/composables/useSession', async () => {
  const { shallowRef } = await import('vue')
  etat.session = shallowRef(null)
  etat.administrateur = shallowRef(false)
  etat.vueEmpruntee = shallowRef(null)
  etat.incarnation = shallowRef(null)
  return {
    useSession: () => ({
      session: etat.session,
      administrateur: etat.administrateur,
      vueEmpruntee: etat.vueEmpruntee,
      incarnation: etat.incarnation,
    }),
    rafraichirSession: () => Promise.resolve(),
  }
})

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '12' }, query: {} }),
  useRouter: () => ({ push: () => Promise.resolve(), replace: () => Promise.resolve() }),
}))

const api = vi.hoisted(() => ({
  lireDemande: vi.fn(),
  lireDemandeAdministration: vi.fn(),
  lireFichePersonne: vi.fn(),
  lireFicheFamille: vi.fn(),
  retirerDemande: vi.fn(),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  ...api,
}))

import { ErreurApi } from '@/lib/api'
import DemandeView from './DemandeView.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

/* ------------------------------------------------------------------ */
/* Les charges                                                         */
/* ------------------------------------------------------------------ */

function demandeDe(surcharge: Partial<Demande> = {}): Demande {
  return {
    id: 12,
    kind: 'modification_personne',
    status: 'en_attente',
    authorUserId: 17,
    authorLabel: 'Opale Bourrache',
    targetKind: 'personne',
    targetId: 41,
    targetLabel: 'Marne Sureau',
    message: null,
    decisionMessage: null,
    autoValidated: false,
    duplicateOfId: null,
    submittedAt: '2026-09-16T10:56:18+00:00',
    createdAt: '2026-09-16T10:56:18+00:00',
    ops: [],
    brouillons: [],
    liens: [],
    revisions: [],
    ...surcharge,
  }
}

function ficheDe(id: number, displayName: string): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id,
    gedcomXref: null,
    displayName,
    givenNames: 'Marne',
    surname: 'Sureau',
    marriedName: null,
    nickname: null,
    sex: 'feminin',
    deceased: true,
    vivant: false,
    birth: null,
    death: null,
    ligneDates: '1901 – 1987',
    branch: 'pm',
    generation: -3,
    bestQuay: 2,
    citationCount: 0,
    forcePrivacy: false,
    privacyNote: null,
    version: 8,
    parente: null,
    portrait: null,
    parents: [],
    fratrie: [],
    unions: [],
    faits: [],
    citations: [],
    notes: [],
    medias: [],
    enAttente: {},
    demandesEnAttente: [12],
  } as unknown as FichePersonneComplete
}

function monter(): Promise<Noeud> {
  return monterEnMemoire(DemandeView, { RouterLink: LienFactice })
}

beforeEach(() => {
  vi.stubGlobal('window', { addEventListener: () => undefined, removeEventListener: () => undefined })
  Object.values(api).forEach((f) => f.mockReset())
  etat.session.value = { utilisateur: { id: 17, displayName: 'Opale Bourrache' } }
  etat.administrateur.value = false
  etat.vueEmpruntee.value = null
  etat.incarnation.value = null
  api.lireFichePersonne.mockImplementation((id: number) => Promise.resolve(ficheDe(id, `Personne ${id}`)))
})

/* ------------------------------------------------------------------ */
/* A — « Voir la fiche »                                               */
/* ------------------------------------------------------------------ */

describe('« Voir la fiche » ouvre la fiche visée, en lecture seule', () => {
  it('partage l’écran sur ordinateur et ouvre une modale sur téléphone, d’une seule lecture', async () => {
    api.lireDemande.mockResolvedValue(demandeDe())
    const racine = await monter()

    const bouton = un(racine, parAttribut('data-voir-fiche'))
    expect(bouton).not.toBeNull()
    expect(String(bouton?.attributs['aria-expanded'])).toBe('false')
    // On ne lit pas la fiche tant qu'on ne la demande pas.
    expect(api.lireFichePersonne).not.toHaveBeenCalled()

    await cliquer(bouton)

    expect(api.lireFichePersonne).toHaveBeenCalledTimes(1)
    expect(api.lireFichePersonne.mock.calls[0]?.[0]).toBe(41)

    const colonne = un(racine, parAttribut('data-fiche-partagee'))
    const modale = un(racine, parAttribut('data-fiche-modale'))
    expect(colonne).not.toBeNull()
    expect(modale).not.toBeNull()
    // L'une pour l'ordinateur, l'autre pour le téléphone — jamais les deux à la fois.
    expect(String(colonne?.attributs['class'])).toContain('max-md:hidden')
    expect(String(modale?.attributs['class'])).toMatch(/(^|\s)md:hidden(\s|$)/)
    expect(modale?.attributs['role']).toBe('dialog')
    expect(String(modale?.attributs['aria-modal'])).toBe('true')

    // La fiche est là, avec ses vraies sections, et un lien vers la vraie page.
    for (const bloc of [colonne!, modale!]) {
      const contenu = texte(bloc)
      expect(contenu).toContain('Personne 41')
      expect(contenu).toContain('La fiche visée par la demande')
      expect(contenu).toContain('Identité')
      expect(contenu).toContain('Famille')
      expect(contenu).toContain('Sources et notes')
      expect(contenu).toContain('Ouvrir la page de Personne 41')
      // Lecture seule : aucun geste de proposition.
      expect(contenu).not.toMatch(/Proposer|Joindre un document|Demander la suppression/)
    }

    // Le partage élargit la page et pose la grille à deux colonnes.
    expect(versHtml(racine)).toContain('md:max-w-6xl')
    expect(versHtml(racine)).toContain('md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]')
    expect(String(un(racine, parAttribut('data-voir-fiche'))?.attributs['aria-expanded'])).toBe('true')

    // Le même bouton referme.
    await cliquer(un(racine, parAttribut('data-voir-fiche')))
    expect(un(racine, parAttribut('data-fiche-partagee'))).toBeNull()
    expect(un(racine, parAttribut('data-fiche-modale'))).toBeNull()
  })

  it('« × » ferme la fiche', async () => {
    api.lireDemande.mockResolvedValue(demandeDe())
    const racine = await monter()
    await cliquer(un(racine, parAttribut('data-voir-fiche')))

    await cliquer(un(racine, (n) => n.tag === 'button' && n.attributs['aria-label'] === 'Fermer la fiche'))

    expect(un(racine, parAttribut('data-fiche-modale'))).toBeNull()
  })

  it('une création en attente montre la fiche du parent auquel elle se rattache', async () => {
    api.lireDemande.mockResolvedValue(
      demandeDe({
        kind: 'creation_personne',
        targetKind: null,
        targetId: null,
        targetLabel: null,
        brouillons: [
          {
            localRef: '#p1',
            noeudId: '#12/p1',
            givenNames: 'Céleste',
            surname: 'Sureau',
            sex: 'feminin',
            deceased: true,
            resolvedPersonId: null,
          },
        ],
        liens: [{ seq: 1, kind: 'enfant_de', subjectDraftRef: '#p1', otherPersonId: 9, otherLabel: 'Personne 9' }],
      }),
    )
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-voir-fiche')))

    expect(api.lireFichePersonne.mock.calls[0]?.[0]).toBe(9)
    expect(texte(un(racine, parAttribut('data-fiche-modale'))!)).toContain(
      'La personne à qui la proposition se rattache',
    )
  })

  it('pas de bouton quand la demande ne désigne aucune fiche', async () => {
    api.lireDemande.mockResolvedValue(
      demandeDe({ kind: 'creation_personne', targetKind: null, targetId: null, targetLabel: null }),
    )
    const racine = await monter()

    expect(un(racine, parAttribut('data-voir-fiche'))).toBeNull()
  })
})

/* ------------------------------------------------------------------ */
/* B — « Annuler ma demande »                                          */
/* ------------------------------------------------------------------ */

describe('« Annuler ma demande »', () => {
  it('confirme, envoie, puis la pastille dit « Annulée »', async () => {
    api.lireDemande.mockResolvedValue(demandeDe())
    api.retirerDemande.mockResolvedValue(demandeDe({ status: 'annulee' }))
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-retirer')))

    // Rien n'est parti : on demande d'abord.
    expect(api.retirerDemande).not.toHaveBeenCalled()
    const dialogue = un(racine, (n) => n.tag === 'dialog')
    expect(dialogue?.open).toBe(true)
    expect(texte(dialogue!)).toContain('Annuler la demande n° 12 ?')

    await cliquer(un(dialogue!, boutonNomme('Annuler ma demande')))

    expect(api.retirerDemande).toHaveBeenCalledWith(12)
    expect(texte(racine)).toContain('Annulée par son auteur')
    expect(un(racine, parAttribut('data-retirer'))).toBeNull()
    expect(dialogue?.open).toBe(false)
  })

  it('« Annuler » dans la question ne retire rien', async () => {
    api.lireDemande.mockResolvedValue(demandeDe())
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-retirer')))
    await cliquer(un(un(racine, (n) => n.tag === 'dialog')!, boutonNomme('Annuler')))

    expect(api.retirerDemande).not.toHaveBeenCalled()
    expect(un(racine, parAttribut('data-retirer'))).not.toBeNull()
  })

  it('s’offre aussi sur un brouillon, que le serveur accepte de retirer', async () => {
    api.lireDemande.mockResolvedValue(demandeDe({ status: 'brouillon' }))
    const racine = await monter()

    expect(un(racine, parAttribut('data-retirer'))).not.toBeNull()
  })

  it('ne s’offre ni sur la demande d’un autre, ni sur une demande tranchée', async () => {
    for (const surcharge of [
      { authorUserId: 99 },
      { status: 'validee' as const },
      { status: 'refusee' as const },
      { status: 'caduque' as const },
      { status: 'annulee' as const },
    ]) {
      api.lireDemande.mockResolvedValue(demandeDe(surcharge))
      const racine = await monter()
      expect(un(racine, parAttribut('data-retirer'))).toBeNull()
    }
  })

  it('validée entre-temps : le 409 se dit, et la demande est relue', async () => {
    api.lireDemande
      .mockResolvedValueOnce(demandeDe())
      .mockResolvedValueOnce(demandeDe({ status: 'validee' }))
    api.retirerDemande.mockRejectedValue(
      new ErreurApi(409, 'demande_deja_decidee', 'Cette demande a déjà été tranchée.', null, { statut: 'validee' }),
    )
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-retirer')))
    await cliquer(un(un(racine, (n) => n.tag === 'dialog')!, boutonNomme('Annuler ma demande')))

    expect(api.lireDemande).toHaveBeenCalledTimes(2)
    const contenu = texte(racine)
    expect(contenu).toContain('Votre demande n’a pas pu être annulée.')
    expect(contenu).toContain('Cette demande a déjà été tranchée.')
    expect(un(racine, parAttribut('data-retirer'))).toBeNull()
  })

  it('sous la vue empruntée, le bouton reste visible, éteint, et dit pourquoi', async () => {
    etat.vueEmpruntee.value = { memberId: 7, memberLabel: 'Cobalt Romarin', depuis: '2026-09-15T21:12:00+02:00' }
    api.lireDemande.mockResolvedValue(demandeDe())
    const racine = await monter()

    const bouton = un(racine, parAttribut('data-retirer'))
    expect(bouton?.attributs['disabled']).toBe(true)
    expect(String(bouton?.attributs['title'])).toContain('Cobalt Romarin')

    await cliquer(bouton)
    expect(un(racine, (n) => n.tag === 'dialog')?.open).toBe(false)
  })

  it('sous incarnation, la question dit au nom de qui le geste est tracé', async () => {
    etat.incarnation.value = { par: { id: 1, displayName: 'Natron' } }
    api.lireDemande.mockResolvedValue(demandeDe())
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-retirer')))

    expect(texte(un(racine, (n) => n.tag === 'dialog')!)).toContain('tracé au nom de Natron')
  })
})
