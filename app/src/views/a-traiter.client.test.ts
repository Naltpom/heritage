/**
 * « À traiter » — la file unique du mode administration (handoff « mode
 * Administration », § 4.1), écran monté et cliqué (`test-utils/rendu-memoire.ts`).
 *
 * Ce que ce fichier tient :
 *
 *  1. **une file, trois sources** : demandes en attente, inscriptions à
 *     rattacher, demandes RGPD sans décision, du plus ancien au plus récent ; les
 *     puces comptent, la pastille d'« À traiter » suit ;
 *  2. **le lien profond** `administration.rgpd` (→ `?type=rgpd`) ne montre que
 *     le RGPD ;
 *  3. **les décisions** : le message est exigé pour refuser, l'élément quitte
 *     la file, un toast le dit, le suivant s'ouvre ; un `409` « déjà traité »
 *     relit la file ;
 *  4. **sous « voir comme »**, ni rattachements ni RGPD ne se lisent.
 *
 * Le détail des rattachements (transcription, recherche) est tenu par
 * `rattachements-a-la-demande.client.test.ts`. Noms inventés.
 */
import { defineComponent, h } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  ErreurApi,
  type CompteARattacher,
  type Demande,
  type DemandeAdministration,
  type DemandeRgpdAdministration,
} from '@/lib/api'
import { boutonNomme, cliquer, monterEnMemoire, parAttribut, texte, tous, un, type Noeud } from '@/test-utils/rendu-memoire'
import { reinitialiserModeAdmin, useModeAdmin } from '@/composables/modeAdmin'
import { oublierToast, useToast } from '@/composables/toast'

const etat = vi.hoisted(() => ({
  vueEmpruntee: null as unknown as { value: unknown },
  route: null as unknown as { params: Record<string, string>; query: Record<string, string> },
}))

vi.mock('@/composables/useSession', async () => {
  const { shallowRef } = await import('vue')
  etat.vueEmpruntee = shallowRef(null)
  return {
    useSession: () => ({
      session: shallowRef({ utilisateur: { id: 1, displayName: 'Natron' } }),
      administrateur: shallowRef(true),
      vueEmpruntee: etat.vueEmpruntee,
      incarnation: shallowRef(null),
    }),
    rafraichirSession: () => Promise.resolve(),
  }
})

// Une route réactive : l'écran tient le filtre et l'élément ouvert dans
// l'adresse, et `replace` la change comme le ferait le routeur.
vi.mock('vue-router', async () => {
  const { reactive } = await import('vue')
  etat.route = reactive({ params: {}, query: {} })
  const naviguer = (vers: { query?: Record<string, string> }): Promise<void> => {
    etat.route.query = { ...(vers.query ?? {}) }
    return Promise.resolve()
  }
  return {
    useRoute: () => etat.route,
    useRouter: () => ({ push: naviguer, replace: naviguer, back: () => undefined }),
  }
})

const api = vi.hoisted(() => ({
  listerDemandesAdministration: vi.fn(),
  lireDemandeAdministration: vi.fn(),
  validerDemande: vi.fn(),
  refuserDemande: vi.fn(),
  invaliderGraphe: vi.fn(),
  listerRattachements: vi.fn(),
  lireSuggestionDeRattachement: vi.fn(),
  listerQuotasGlobaux: vi.fn(),
  listerDemandesRgpd: vi.fn(),
  traiterDemandeRgpd: vi.fn(),
  quitterVueComme: vi.fn(),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  ...api,
}))

import ATraiterView from './administration/ATraiterView.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

/* ------------------------------------------------------------------ */
/* Les charges                                                         */
/* ------------------------------------------------------------------ */

function demande(sur: Partial<Demande> & { id: number }): Demande {
  return {
    kind: 'modification_personne',
    status: 'en_attente',
    authorLabel: 'Opale Romarin',
    targetKind: 'personne',
    targetId: 17,
    targetLabel: 'Marne Dolomite Sureau',
    message: null,
    decisionMessage: null,
    autoValidated: false,
    duplicateOfId: null,
    submittedAt: '2026-10-01T10:00:00+02:00',
    createdAt: '2026-10-01T10:00:00+02:00',
    ops: [],
    brouillons: [],
    liens: [],
    revisions: [],
    ...sur,
  }
}

function complete(d: Demande): DemandeAdministration {
  return {
    ...d,
    resolutionPrevue: 'application',
    ops: [
      {
        seq: 1,
        opKind: 'modification',
        subjectKind: 'personne',
        subjectId: 17,
        field: 'surname',
        valueKind: 'texte',
        oldValue: 'Sureau',
        newValue: 'Sureaux',
        libelleDiff: 'Nom',
      },
    ],
  } as DemandeAdministration
}

function compte(memberId: number, inscritLe: string): CompteARattacher {
  return {
    memberId,
    userId: memberId + 100,
    email: `inscrit${memberId}@example.net`,
    inscritLe,
    invitationId: 3,
    invitationPersonId: null,
    invitationPersonLabel: null,
    declaration: { surname: 'Serpolet', givenNames: 'Léa', birthDate: '1998-04-12', description: 'Ma grand-mère est Ambre.' },
    messageVocal: null,
    photo: null,
    suggestionCalculeeLe: null,
  }
}

function rgpd(sur: Partial<DemandeRgpdAdministration> = {}): DemandeRgpdAdministration {
  return {
    id: 4,
    nature: 'retrait_partage',
    aRetirer: { annee: true, mois: false, jour: false },
    message: 'Je préfère garder mon âge pour moi.',
    creeeLe: '2026-10-03T10:00:00+02:00',
    traiteeLe: null,
    decision: null,
    reponse: null,
    memberId: 7,
    userId: 12,
    userEmail: 'ambre@example.net',
    ...sur,
  }
}

/* ------------------------------------------------------------------ */

function lignes(racine: Noeud): string[] {
  return tous(racine, (n) => n.tag === 'button' && typeof n.attributs['data-cle'] === 'string').map((n) =>
    String(n.attributs['data-cle']),
  )
}

const detail = (racine: Noeud): Noeud | null => un(racine, parAttribut('data-detail'))

async function saisir(champ: Noeud | null, valeur: string): Promise<void> {
  const ecouteur = champ?.ecouteurs['input']
  if (typeof ecouteur !== 'function') throw new Error('Le champ n’écoute pas la saisie.')
  ecouteur({ target: { value: valeur } })
  await new Promise((fin) => setTimeout(fin, 0))
}

function monter(): Promise<Noeud> {
  return monterEnMemoire(ATraiterView, { RouterLink: LienFactice })
}

beforeEach(() => {
  vi.stubGlobal('document', { addEventListener: () => undefined, removeEventListener: () => undefined })
  vi.stubGlobal('window', {
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    matchMedia: () => ({ matches: false, addEventListener: () => undefined, removeEventListener: () => undefined }),
  })
  Object.values(api).forEach((f) => f.mockReset())
  etat.vueEmpruntee.value = null
  etat.route.query = {}
  api.listerQuotasGlobaux.mockResolvedValue({ quotas: [] })
  api.listerDemandesAdministration.mockResolvedValue({
    elements: [demande({ id: 42, submittedAt: '2026-10-02T10:00:00+02:00' })],
    total: 1,
    page: 1,
    parPage: 100,
  })
  api.lireDemandeAdministration.mockImplementation((id: number) => Promise.resolve(complete(demande({ id }))))
  api.listerRattachements.mockResolvedValue({
    elements: [compte(14, '2026-10-01T09:00:00+02:00')],
    total: 1,
    page: 1,
    parPage: 100,
  })
  api.listerDemandesRgpd.mockResolvedValue({
    elements: [rgpd(), rgpd({ id: 2, decision: 'appliquee', traiteeLe: '2026-10-01T10:00:00+02:00' })],
  })
})

afterEach(() => {
  reinitialiserModeAdmin()
  oublierToast()
})

describe('« À traiter » : une file, trois sources', () => {
  it('range demandes, inscriptions et RGPD en attente du plus ancien au plus récent', async () => {
    const racine = await monter()

    // L'inscription du 1ᵉʳ, la demande du 2, le RGPD du 3 ; le RGPD traité n'y est pas.
    expect(lignes(racine)).toEqual(['r:14', 'd:42', 'g:4'])
    expect(texte(un(racine, (n) => n.attributs['data-puce'] === 'tout')!)).toContain('3')
    expect(texte(un(racine, (n) => n.attributs['data-puce'] === 'rgpd')!)).toContain('1')
    // Sur bureau, la plus ancienne est ouverte d'office.
    expect(detail(racine)?.attributs['data-detail']).toBe('r:14')
    // La pastille d'« À traiter » suit la file.
    expect(useModeAdmin().aTraiter.value).toBe(3)
  })

  it('suit le lien profond `administration.rgpd` : le RGPD seul', async () => {
    etat.route.query = { type: 'rgpd' }
    const racine = await monter()

    expect(lignes(racine)).toEqual(['g:4'])
    const ouvert = detail(racine)!
    expect(texte(ouvert)).toContain('Retirer une date de naissance partagée')
    expect(texte(ouvert)).toContain('ambre@example.net')
    expect(texte(ouvert)).toContain('À retirer : année')
    expect(texte(ouvert)).toContain('Je préfère garder mon âge pour moi.')
  })

  it('filtre par les puces, et ouvre l’élément touché', async () => {
    const racine = await monter()

    await cliquer(un(racine, (n) => n.attributs['data-puce'] === 'demande'))
    expect(etat.route.query['type']).toBe('demande')
    expect(lignes(racine)).toEqual(['d:42'])

    await cliquer(un(racine, (n) => n.attributs['data-cle'] === 'd:42'))
    expect(detail(racine)?.attributs['data-detail']).toBe('d:42')
    expect(api.lireDemandeAdministration).toHaveBeenCalledWith(42)
    // L'aperçu du diff : l'ancienne valeur, la nouvelle.
    expect(texte(un(racine, parAttribut('data-apercu-diff'))!)).toContain('Sureau')
    expect(texte(un(racine, parAttribut('data-apercu-diff'))!)).toContain('Sureaux')
  })

  it('dit quand rien n’attend', async () => {
    api.listerDemandesAdministration.mockResolvedValue({ elements: [], total: 0, page: 1, parPage: 100 })
    api.listerRattachements.mockResolvedValue({ elements: [], total: 0, page: 1, parPage: 100 })
    api.listerDemandesRgpd.mockResolvedValue({ elements: [] })
    const racine = await monter()

    expect(texte(racine)).toContain('Rien n’attend de décision.')
    expect(detail(racine)).toBeNull()
  })
})

describe('« À traiter » : les décisions', () => {
  it('une demande : le message est exigé pour refuser, puis elle part et la suivante s’ouvre', async () => {
    etat.route.query = { sel: 'd:42' }
    api.refuserDemande.mockResolvedValue(demande({ id: 42, status: 'refusee' }))
    const racine = await monter()

    await cliquer(un(detail(racine)!, boutonNomme('Refuser')))
    expect(api.refuserDemande).not.toHaveBeenCalled()
    expect(un(racine, parAttribut('data-motif-manquant'))).not.toBeNull()

    await saisir(un(racine, parAttribut('data-motif')), '  Source manquante.  ')
    await cliquer(un(detail(racine)!, boutonNomme('Refuser')))

    expect(api.refuserDemande).toHaveBeenCalledWith(42, { message: 'Source manquante.' })
    expect(lignes(racine)).toEqual(['r:14', 'g:4'])
    expect(useToast().message.value).toBe('Demande n° 42 refusée.')
    // La suivante dans la liste affichée : le RGPD, qui venait après.
    expect(etat.route.query['sel']).toBe('g:4')
    expect(useModeAdmin().aTraiter.value).toBe(2)
  })

  it('une demande validée quitte la file, et le graphe est périmé', async () => {
    etat.route.query = { type: 'demande' }
    api.validerDemande.mockResolvedValue({ resultat: 'validee', demande: demande({ id: 42 }), revisions: [] })
    const racine = await monter()

    await cliquer(un(detail(racine)!, boutonNomme('Valider')))

    expect(api.validerDemande).toHaveBeenCalledWith(42)
    expect(api.invaliderGraphe).toHaveBeenCalledTimes(1)
    expect(useToast().message.value).toBe('Demande n° 42 validée.')
    expect(texte(racine)).toContain('Rien n’attend de décision.')
  })

  it('RGPD : « Appliquer » part sans réponse ; « Refuser » exige une réponse', async () => {
    etat.route.query = { type: 'rgpd' }
    api.traiterDemandeRgpd.mockResolvedValue(rgpd({ decision: 'appliquee' }))
    const racine = await monter()

    await cliquer(un(detail(racine)!, boutonNomme('Refuser')))
    expect(api.traiterDemandeRgpd).not.toHaveBeenCalled()
    expect(un(racine, parAttribut('data-reponse-manquante'))).not.toBeNull()

    await cliquer(un(detail(racine)!, boutonNomme('Appliquer')))
    expect(api.traiterDemandeRgpd).toHaveBeenCalledWith(4, 'appliquee', undefined)
    expect(useToast().message.value).toBe('Demande RGPD appliquée.')
    expect(lignes(racine)).toEqual([])
  })

  it('RGPD : un refus envoie la réponse', async () => {
    etat.route.query = { type: 'rgpd' }
    api.traiterDemandeRgpd.mockResolvedValue(rgpd({ decision: 'refusee', reponse: 'Déjà retiré.' }))
    const racine = await monter()

    await saisir(un(racine, parAttribut('data-reponse-rgpd')), 'Déjà retiré.')
    await cliquer(un(detail(racine)!, boutonNomme('Refuser')))

    expect(api.traiterDemandeRgpd).toHaveBeenCalledWith(4, 'refusee', 'Déjà retiré.')
    expect(useToast().message.value).toBe('Demande RGPD refusée.')
  })

  it('un 409 demande_deja_traitee relit la file et le dit', async () => {
    etat.route.query = { type: 'rgpd' }
    api.traiterDemandeRgpd.mockRejectedValue(new ErreurApi(409, 'demande_deja_traitee', 'Déjà traitée.'))
    const racine = await monter()
    api.listerDemandesRgpd.mockResolvedValue({ elements: [rgpd({ decision: 'appliquee' })] })

    await cliquer(un(detail(racine)!, boutonNomme('Appliquer')))

    expect(api.listerDemandesRgpd).toHaveBeenCalledTimes(2)
    expect(un(racine, parAttribut('data-deja-traitee'))).not.toBeNull()
    expect(lignes(racine)).toEqual([])
  })
})

describe('« À traiter » sous « voir comme »', () => {
  it('ne lit ni les rattachements ni le RGPD, et le dit ; les décisions sont éteintes', async () => {
    etat.vueEmpruntee.value = { memberId: 7, memberLabel: 'Cobalt Romarin', depuis: '2026-10-03T10:00:00+02:00' }
    const racine = await monter()

    expect(api.listerRattachements).not.toHaveBeenCalled()
    expect(api.listerDemandesRgpd).not.toHaveBeenCalled()
    expect(texte(racine)).toContain('ne s’affichent pas sous une vue empruntée')
    expect(lignes(racine)).toEqual(['d:42'])
    expect(un(detail(racine)!, boutonNomme('Valider'))?.attributs['disabled']).toBe(true)
  })
})
