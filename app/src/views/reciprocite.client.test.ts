/**
 * La réciprocité des vivants (ADR 0016) — les gestes, écrans montés et
 * cliqués (`test-utils/rendu-memoire.ts`).
 *
 *  1. **Utilisateurs** : l'icône calendrier lève la restriction de date de
 *     naissance d'un compte — `PATCH` avec `ignoreRestrictionNaissance` —, et la
 *     ligne dit ce que le membre partage ;
 *  2. **Mon compte** : le choix ne part qu'à « Enregistrer », la session est
 *     relue ; une case cochée verrouillée est éteinte, avec le lien vers la
 *     demande RGPD ; un `409 partage_verrouille` se dit sans casser l'écran ;
 *  3. **la fiche** : l'autorisation signée se dépose en `multipart` avec ses
 *     trois cases, se retire ; un compte rattaché ferme le dépôt.
 *
 * Noms inventés.
 */
import { defineComponent, h } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  ErreurApi,
  type EtatAutorisationNaissance,
  type PartageNaissanceEnregistre,
  type UtilisateurAdministration,
} from '@/lib/api'
import { boutonNomme, cliquer, monterEnMemoire, parAttribut, texte, tous, un, type Noeud } from '@/test-utils/rendu-memoire'

const etat = vi.hoisted(() => ({
  vueEmpruntee: null as unknown as { value: unknown },
  session: null as unknown as { value: unknown },
  rafraichir: vi.fn(() => Promise.resolve()),
}))

vi.mock('@/composables/useSession', async () => {
  const { shallowRef } = await import('vue')
  etat.vueEmpruntee = shallowRef(null)
  etat.session = shallowRef({ utilisateur: { id: 1, displayName: 'Natron' }, reciprocite: { restrictionIgnoree: false, photo: true } })
  return {
    useSession: () => ({
      session: etat.session,
      administrateur: shallowRef(true),
      vueEmpruntee: etat.vueEmpruntee,
      incarnation: shallowRef(null),
    }),
    rafraichirSession: etat.rafraichir,
  }
})

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: () => Promise.resolve(), replace: () => Promise.resolve() }),
}))

const api = vi.hoisted(() => ({
  listerUtilisateurs: vi.fn(),
  listerMembresEnAttente: vi.fn(),
  listerQuotasGlobaux: vi.fn(),
  modifierUtilisateur: vi.fn(),
  modifierPartageNaissance: vi.fn(),
  lireAutorisationNaissance: vi.fn(),
  deposerAutorisationNaissance: vi.fn(),
  retirerAutorisationNaissance: vi.fn(),
  quitterVueComme: vi.fn(),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  ...api,
}))

import UtilisateursView from './administration/UtilisateursView.vue'
import SectionPartageNaissance from '@/components/compte/SectionPartageNaissance.vue'
import AutorisationNaissance from '@/components/personne/AutorisationNaissance.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

const nomme = (libelle: string) => (n: Noeud) => n.tag === 'button' && n.attributs['aria-label'] === libelle

/** Coche ou décoche, comme un clic sur la case. */
async function cocher(racine: Noeud, cle: string, valeur: boolean): Promise<void> {
  const caseACocher = un(racine, (n) => n.tag === 'input' && n.attributs['data-composante'] === cle)
  if (caseACocher === null) throw new Error(`Case absente : ${cle}`)
  const ecouteur = caseACocher.ecouteurs['change']
  if (typeof ecouteur !== 'function') throw new Error('La case n’écoute pas.')
  ecouteur({ target: { checked: valeur } })
  await new Promise((fin) => setTimeout(fin, 0))
}

/** « Envoie » le formulaire qui contient ce nœud. */
async function soumettre(racine: Noeud): Promise<void> {
  const formulaire = un(racine, (n) => n.tag === 'form')
  const ecouteur = formulaire?.ecouteurs['submit']
  if (typeof ecouteur !== 'function') throw new Error('Pas de formulaire.')
  ecouteur({ preventDefault: () => undefined })
  for (let i = 0; i < 6; i++) await new Promise((fin) => setTimeout(fin, 0))
}

function utilisateur(sur: Partial<UtilisateurAdministration> = {}): UtilisateurAdministration {
  return {
    id: 12,
    email: 'ambre@example.net',
    displayName: 'Ambre Serpolet',
    roles: ['ROLE_USER'],
    status: 'actif',
    totpEnabledAt: null,
    lastLoginAt: null,
    createdAt: '2026-09-01T10:00:00+02:00',
    membre: {
      id: 7,
      status: 'actif',
      personId: 71,
      personLabel: 'Ambre Serpolet',
      matchKind: 'pre_lie',
      invitationId: null,
      linkedAt: null,
      activatedAt: '2026-09-02T10:00:00+02:00',
      partageNaissance: { annee: false, mois: true, jour: true, modifieLe: null },
    },
    quotas: [],
    ignoreRestrictionNaissance: false,
    ...sur,
  }
}

beforeEach(() => {
  vi.stubGlobal('document', { addEventListener: () => undefined, removeEventListener: () => undefined })
  vi.stubGlobal('window', {
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    matchMedia: () => ({ matches: false, addEventListener: () => undefined, removeEventListener: () => undefined }),
  })
  Object.values(api).forEach((f) => f.mockReset())
  etat.rafraichir.mockClear()
  etat.vueEmpruntee.value = null
  api.listerMembresEnAttente.mockResolvedValue({ elements: [], total: 0, page: 1, parPage: 100 })
  api.listerQuotasGlobaux.mockResolvedValue({ quotas: [] })
})

/* ------------------------------------------------------------------ */

describe('Utilisateurs — la restriction de date de naissance', () => {
  it('la ligne dit le partage du membre, et l’icône lève la restriction', async () => {
    api.listerUtilisateurs.mockResolvedValue({ elements: [utilisateur()], total: 1, page: 1, parPage: 100 })
    api.modifierUtilisateur.mockResolvedValue(utilisateur({ ignoreRestrictionNaissance: true }))

    const racine = await monterEnMemoire(UtilisateursView, { RouterLink: LienFactice })
    expect(texte(un(racine, parAttribut('data-partage-du-membre'))!)).toContain('jour et mois')
    expect(un(racine, parAttribut('data-restriction-ignoree'))).toBeNull()

    api.listerUtilisateurs.mockResolvedValue({
      elements: [utilisateur({ ignoreRestrictionNaissance: true })],
      total: 1,
      page: 1,
      parPage: 100,
    })
    await cliquer(un(racine, nomme('Ignorer la restriction de date de naissance')))

    expect(api.modifierUtilisateur).toHaveBeenCalledWith(12, { ignoreRestrictionNaissance: true })
    expect(un(racine, parAttribut('data-restriction-ignoree'))).not.toBeNull()
    expect(un(racine, nomme('Rétablir la restriction de date de naissance'))).not.toBeNull()
  })

  it('sous une vue empruntée, l’icône est éteinte', async () => {
    etat.vueEmpruntee.value = { memberId: 7, memberLabel: 'Cobalt Romarin', depuis: '2026-10-03T10:00:00+02:00' }
    api.listerUtilisateurs.mockResolvedValue({ elements: [utilisateur()], total: 1, page: 1, parPage: 100 })

    const racine = await monterEnMemoire(UtilisateursView, { RouterLink: LienFactice })
    await cliquer(un(racine, nomme('Ignorer la restriction de date de naissance')))
    expect(api.modifierUtilisateur).not.toHaveBeenCalled()
  })
})

/* ------------------------------------------------------------------ */

function monterSection(partage: PartageNaissanceEnregistre): Promise<Noeud> {
  return monterEnMemoire(defineComponent({ render: () => h(SectionPartageNaissance, { partage }) }), {
    RouterLink: LienFactice,
  })
}

describe('Mon compte — le partage de ma date de naissance', () => {
  const ENREGISTRE: PartageNaissanceEnregistre = {
    annee: true,
    mois: true,
    jour: true,
    modifieLe: '2026-09-20T10:00:00+02:00',
  }

  it('ne part qu’à « Enregistrer », avec les trois cases, puis relit la session', async () => {
    api.modifierPartageNaissance.mockResolvedValue({ ...ENREGISTRE, annee: false })
    const racine = await monterSection(ENREGISTRE)

    expect(texte(racine)).toContain('Choix modifié le 20 septembre 2026')
    // Tout est partagé, rien n'est verrouillé : on peut décocher.
    expect(tous(racine, (n) => n.tag === 'input' && n.attributs['disabled'] === true)).toHaveLength(0)
    expect(un(racine, boutonNomme('Enregistrer'))?.attributs['disabled']).toBe(true)

    await cocher(racine, 'annee', false)
    expect(api.modifierPartageNaissance).not.toHaveBeenCalled()
    await soumettre(racine)

    expect(api.modifierPartageNaissance).toHaveBeenCalledWith({ annee: false, mois: true, jour: true })
    expect(etat.rafraichir).toHaveBeenCalledTimes(1)
  })

  it('l’avertissement des trente jours est là avant de cocher, et appuyé une fois coché', async () => {
    const racine = await monterSection({ ...ENREGISTRE, annee: false })
    const avertissement = () => un(racine, parAttribut('data-avertissement-partage'))

    expect(texte(avertissement()!)).toContain('Une information partagée reste visible des autres membres au moins 30 jours.')
    expect(String(avertissement()!.attributs['class'])).not.toContain('text-alerte')

    await cocher(racine, 'annee', true)
    expect(String(avertissement()!.attributs['class'])).toContain('text-alerte')
  })

  it('une case cochée verrouillée est éteinte, avec sa date et le lien vers la demande RGPD', async () => {
    const recus: string[] = []
    const racine = await monterEnMemoire(
      defineComponent({
        render: () =>
          h(SectionPartageNaissance, {
            partage: { ...ENREGISTRE, annee: false, verrouJusquAu: { mois: '2026-11-02T12:00:00+01:00' } },
            onDemandeRgpd: (cle: string) => recus.push(cle),
          }),
      }),
      { RouterLink: LienFactice },
    )
    const caseDe = (cle: string) => un(racine, (n) => n.tag === 'input' && n.attributs['data-composante'] === cle)
    expect(caseDe('mois')?.attributs['disabled']).toBe(true)
    expect(caseDe('jour')?.attributs['disabled']).toBe(false)
    // Non cochée : partager est immédiat, jamais éteint.
    expect(caseDe('annee')?.attributs['disabled']).toBe(false)
    expect(texte(un(racine, parAttribut('data-verrou'))!)).toContain('Partagé jusqu’au 2 novembre 2026 au minimum')

    await cliquer(un(racine, (n) => n.tag === 'button' && n.attributs['data-demande-rgpd'] === 'mois'))
    expect(recus).toEqual(['mois'])
  })

  it('un 409 partage_verrouille se dit, et la session est relue', async () => {
    api.modifierPartageNaissance.mockRejectedValue(
      new ErreurApi(409, 'partage_verrouille', 'Trop tôt.', null, { jusquAu: { mois: '2026-11-02T12:00:00+01:00' } }),
    )
    const racine = await monterSection(ENREGISTRE)
    await cocher(racine, 'mois', false)
    await soumettre(racine)

    expect(un(racine, parAttribut('data-refus-verrou'))).not.toBeNull()
    expect(texte(racine)).toContain('ne peut pas encore être retirée')
    expect(etat.rafraichir).toHaveBeenCalledTimes(1)
  })
})

/* ------------------------------------------------------------------ */

function autorisation(sur: Partial<EtatAutorisationNaissance> = {}): EtatAutorisationNaissance {
  return { compteRattache: false, autorisation: null, historique: [], ...sur }
}

const ACTIVE = {
  id: 3,
  annee: false,
  mois: true,
  jour: true,
  note: 'Signée à la cousinade',
  mime: 'application/pdf',
  taille: 2048,
  sha256: 'ab'.repeat(32),
  accordeeLe: '2026-09-15T10:00:00+02:00',
  accordeeParUserId: 1,
  archiveeLe: null,
  raisonArchivage: null,
  documentUrl: '/api/administration/autorisations-naissance/3/document',
}

function monterAutorisation(): Promise<Noeud> {
  return monterEnMemoire(defineComponent({ render: () => h(AutorisationNaissance, { personId: 71 }) }))
}

describe('L’autorisation signée d’un vivant sans compte', () => {
  it('se dépose avec son document et ses trois cases', async () => {
    api.lireAutorisationNaissance.mockResolvedValue(autorisation())
    api.deposerAutorisationNaissance.mockResolvedValue(autorisation({ autorisation: ACTIVE }))

    const racine = await monterAutorisation()
    expect(api.lireAutorisationNaissance).toHaveBeenCalledWith(71)
    expect(un(racine, parAttribut('data-etat'))?.attributs['data-etat']).toBe('aucune')

    const fichier = new File(['%PDF-1.4'], 'autorisation.pdf', { type: 'application/pdf' })
    const champ = un(racine, (n) => n.tag === 'input' && n.attributs['type'] === 'file')
    ;(champ!.ecouteurs['change'] as (e: unknown) => void)({ target: { files: [fichier] } })
    await cocher(racine, 'jour', true)
    await cocher(racine, 'mois', true)
    await soumettre(racine)

    expect(api.deposerAutorisationNaissance).toHaveBeenCalledWith(71, {
      annee: false,
      mois: true,
      jour: true,
      document: fichier,
      note: '',
    })
    expect(un(racine, parAttribut('data-etat'))?.attributs['data-etat']).toBe('active')
    expect(texte(racine)).toContain('jour et mois')
    const liens = tous(racine, (n) => n.tag === 'a').map((a) => a.attributs['href'])
    expect(liens).toContain('/api/administration/autorisations-naissance/3/document')
  })

  it('« Retirer » archive l’autorisation active', async () => {
    api.lireAutorisationNaissance.mockResolvedValue(autorisation({ autorisation: ACTIVE }))
    api.retirerAutorisationNaissance.mockResolvedValue(
      autorisation({ historique: [{ ...ACTIVE, archiveeLe: '2026-10-03T10:00:00+02:00', raisonArchivage: 'retiree' }] }),
    )
    const racine = await monterAutorisation()
    await cliquer(un(racine, boutonNomme('Retirer')))

    expect(api.retirerAutorisationNaissance).toHaveBeenCalledWith(71)
    expect(un(racine, parAttribut('data-etat'))?.attributs['data-etat']).toBe('aucune')
    expect(texte(racine)).toContain('Historique (1)')
  })

  it('un compte rattaché ferme le dépôt : seul son choix compte', async () => {
    api.lireAutorisationNaissance.mockResolvedValue(autorisation({ compteRattache: true }))
    const racine = await monterAutorisation()
    expect(texte(racine)).toContain('seul son propre choix de partage compte')
    expect(un(racine, (n) => n.tag === 'form')).toBeNull()
  })
})
