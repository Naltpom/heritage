/**
 * Les demandes RGPD (ADR 0016, révision du 3 octobre 2026) — les gestes,
 * écrans montés et cliqués (`test-utils/rendu-memoire.ts`).
 *
 *  1. **Mon compte** : « Faire une demande RGPD » ouvre le formulaire ; le
 *     contrôle refuse un retrait sans composante et une demande sans message ;
 *     l'envoi part avec le bon corps et la demande rejoint la liste ; le lien
 *     d'une case verrouillée ouvre le formulaire, la composante cochée ; sous
 *     « voir comme », tout est éteint ;
 *  2. **Administration** : la file, « Appliquer » (réponse facultative),
 *     « Refuser » (réponse exigée), `409 demande_deja_traitee`.
 *
 * Noms inventés.
 */
import { defineComponent, h, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ErreurApi, type DemandeRgpd, type DemandeRgpdAdministration } from '@/lib/api'
import { boutonNomme, cliquer, monterEnMemoire, parAttribut, texte, tous, un, type Noeud } from '@/test-utils/rendu-memoire'

const etat = vi.hoisted(() => ({
  vueEmpruntee: null as unknown as { value: unknown },
  rafraichir: vi.fn(() => Promise.resolve()),
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
    rafraichirSession: etat.rafraichir,
  }
})

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: () => Promise.resolve(), replace: () => Promise.resolve() }),
}))

const api = vi.hoisted(() => ({
  listerMesDemandesRgpd: vi.fn(),
  creerDemandeRgpd: vi.fn(),
  listerDemandesRgpd: vi.fn(),
  traiterDemandeRgpd: vi.fn(),
  quitterVueComme: vi.fn(),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  ...api,
}))

import SectionDemandesRgpd from '@/components/compte/SectionDemandesRgpd.vue'
import DemandesRgpdView from './administration/DemandesRgpdView.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

async function attendre(): Promise<void> {
  for (let i = 0; i < 6; i++) await new Promise((fin) => setTimeout(fin, 0))
}

/** Déclenche l'écouteur `evenement` d'un nœud, comme le navigateur. */
async function declencher(n: Noeud | null, evenement: string, cible: Record<string, unknown>): Promise<void> {
  const ecouteur = n?.ecouteurs[evenement]
  if (typeof ecouteur !== 'function') throw new Error(`Rien n’écoute « ${evenement} ».`)
  ecouteur({ target: cible })
  await attendre()
}

/** « Envoie » le formulaire `formulaire`. */
async function soumettre(formulaire: Noeud | null): Promise<void> {
  const ecouteur = formulaire?.ecouteurs['submit']
  if (typeof ecouteur !== 'function') throw new Error('Pas de formulaire.')
  ecouteur({ preventDefault: () => undefined })
  await attendre()
}

function demande(sur: Partial<DemandeRgpd> = {}): DemandeRgpd {
  return {
    id: 4,
    nature: 'retrait_partage',
    aRetirer: { annee: true, mois: false, jour: false },
    message: null,
    creeeLe: '2026-10-03T10:00:00+02:00',
    traiteeLe: null,
    decision: null,
    reponse: null,
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
})

/* ------------------------------------------------------------------ */

/** La section, avec un bouton qui fait ce que fait la vue au clic du lien d'une case verrouillée. */
function monterSection(): Promise<Noeud> {
  return monterEnMemoire(
    defineComponent({
      setup() {
        const section = ref<InstanceType<typeof SectionDemandesRgpd> | null>(null)
        return () => [
          h('button', { 'data-lien-verrou': '', onClick: () => section.value?.ouvrir('mois') }, 'lien'),
          h(SectionDemandesRgpd, { ref: section }),
        ]
      },
    }),
    { RouterLink: LienFactice },
  )
}

const formulaire = (racine: Noeud) => un(racine, parAttribut('data-formulaire-rgpd'))
const aRetirer = (racine: Noeud, cle: string) =>
  un(racine, (n) => n.tag === 'input' && n.attributs['data-a-retirer'] === cle)

describe('Mon compte — les demandes RGPD', () => {
  it('liste les demandes avec leur état et la réponse', async () => {
    api.listerMesDemandesRgpd.mockResolvedValue({
      elements: [
        demande(),
        demande({
          id: 3,
          nature: 'rectification',
          aRetirer: { annee: false, mois: false, jour: false },
          message: 'Mon second prénom est Lucien.',
          traiteeLe: '2026-10-02T10:00:00+02:00',
          decision: 'refusee',
          reponse: 'Aucun acte ne le confirme.',
        }),
      ],
    })
    const racine = await monterSection()
    const lignes = tous(racine, parAttribut('data-ma-demande-rgpd'))

    expect(lignes).toHaveLength(2)
    expect(texte(lignes[0]!)).toContain('Retirer une date de naissance partagée')
    expect(texte(lignes[0]!)).toContain('En attente')
    expect(texte(lignes[0]!)).toContain('À retirer : année')
    expect(texte(lignes[1]!)).toContain('Refusée le 2 octobre 2026')
    expect(texte(lignes[1]!)).toContain('Réponse : Aucun acte ne le confirme.')
  })

  it('refuse un retrait sans composante, puis envoie le retrait et l’ajoute à la liste', async () => {
    api.listerMesDemandesRgpd.mockResolvedValue({ elements: [] })
    api.creerDemandeRgpd.mockResolvedValue(demande({ id: 9, aRetirer: { annee: false, mois: false, jour: true } }))
    const racine = await monterSection()
    expect(texte(racine)).toContain('Aucune demande pour l’instant.')

    await cliquer(un(racine, boutonNomme('Faire une demande RGPD')))
    expect(formulaire(racine)).not.toBeNull()

    await soumettre(formulaire(racine))
    expect(api.creerDemandeRgpd).not.toHaveBeenCalled()
    expect(texte(racine)).toContain('Cochez au moins ce qu’il faut retirer.')

    await declencher(aRetirer(racine, 'jour'), 'change', { checked: true })
    await soumettre(formulaire(racine))

    expect(api.creerDemandeRgpd).toHaveBeenCalledWith({ nature: 'retrait_partage', annee: false, mois: false, jour: true })
    expect(formulaire(racine)).toBeNull()
    expect(texte(racine)).toContain('Demande envoyée.')
    expect(tous(racine, parAttribut('data-ma-demande-rgpd'))).toHaveLength(1)
  })

  it('exige un message hors retrait, et dit les refus du serveur', async () => {
    api.listerMesDemandesRgpd.mockResolvedValue({ elements: [] })
    api.creerDemandeRgpd.mockRejectedValue(
      new ErreurApi(422, 'validation', 'Invalide.', { message: 'Trop vague.' }),
    )
    const racine = await monterSection()
    await cliquer(un(racine, boutonNomme('Faire une demande RGPD')))

    await declencher(un(racine, parAttribut('data-nature-rgpd')), 'change', { value: 'rectification' })
    expect(aRetirer(racine, 'annee')).toBeNull()
    await soumettre(formulaire(racine))
    expect(api.creerDemandeRgpd).not.toHaveBeenCalled()
    expect(texte(racine)).toContain('Décrivez votre demande.')

    await declencher(un(racine, parAttribut('data-message-rgpd')), 'input', { value: 'Mon nom est mal écrit.' })
    await soumettre(formulaire(racine))
    expect(api.creerDemandeRgpd).toHaveBeenCalledWith({ nature: 'rectification', message: 'Mon nom est mal écrit.' })
    expect(texte(racine)).toContain('Trop vague.')
    expect(formulaire(racine)).not.toBeNull()
  })

  it('le lien d’une case verrouillée ouvre le formulaire, la composante cochée', async () => {
    api.listerMesDemandesRgpd.mockResolvedValue({ elements: [] })
    const racine = await monterSection()
    await cliquer(un(racine, parAttribut('data-lien-verrou')))

    expect(formulaire(racine)).not.toBeNull()
    expect(un(racine, parAttribut('data-nature-rgpd'))?.attributs['value']).toBe('retrait_partage')
    expect(aRetirer(racine, 'mois')?.attributs['checked']).toBe(true)
    expect(aRetirer(racine, 'annee')?.attributs['checked']).toBe(false)
  })

  it('sous « voir comme », rien ne se lit et le bouton est éteint', async () => {
    etat.vueEmpruntee.value = { memberId: 7, memberLabel: 'Cobalt Romarin', depuis: '2026-10-03T10:00:00+02:00' }
    const racine = await monterSection()

    expect(api.listerMesDemandesRgpd).not.toHaveBeenCalled()
    expect(un(racine, boutonNomme('Faire une demande RGPD'))?.attributs['disabled']).toBe(true)
    await cliquer(un(racine, parAttribut('data-lien-verrou')))
    expect(formulaire(racine)).toBeNull()
  })
})

/* ------------------------------------------------------------------ */

function demandeAdmin(sur: Partial<DemandeRgpdAdministration> = {}): DemandeRgpdAdministration {
  return { ...demande(), memberId: 7, userId: 12, userEmail: 'ambre@example.net', ...sur }
}

const carte = (racine: Noeud, id: number) =>
  un(racine, (n) => n.tag === 'article' && n.attributs['data-demande-rgpd'] === id)

describe('Administration — la file des demandes RGPD', () => {
  it('liste les demandes : courriel, nature, composantes, message, état', async () => {
    api.listerDemandesRgpd.mockResolvedValue({
      elements: [
        demandeAdmin({ message: 'Je préfère garder mon âge pour moi.' }),
        demandeAdmin({ id: 2, decision: 'appliquee', traiteeLe: '2026-10-01T10:00:00+02:00' }),
      ],
    })
    const racine = await monterEnMemoire(DemandesRgpdView, { RouterLink: LienFactice })
    const premiere = texte(carte(racine, 4)!)

    expect(premiere).toContain('ambre@example.net')
    expect(premiere).toContain('Retirer une date de naissance partagée')
    expect(premiere).toContain('À retirer : année')
    expect(premiere).toContain('Je préfère garder mon âge pour moi.')
    expect(premiere).toContain('En attente')
    expect(texte(carte(racine, 2)!)).toContain('Appliquée le 1 octobre 2026')
    // Une demande traitée n'a plus de boutons.
    expect(tous(carte(racine, 2)!, (n) => n.tag === 'button')).toHaveLength(0)
    expect(texte(un(racine, parAttribut('data-compte-en-attente'))!)).toContain('1 en attente.')
  })

  it('« Appliquer » part sans réponse, et la ligne traitée prend la place', async () => {
    api.listerDemandesRgpd.mockResolvedValue({ elements: [demandeAdmin()] })
    api.traiterDemandeRgpd.mockResolvedValue(
      demandeAdmin({ decision: 'appliquee', traiteeLe: '2026-10-03T12:00:00+02:00' }),
    )
    const racine = await monterEnMemoire(DemandesRgpdView, { RouterLink: LienFactice })
    await soumettre(un(carte(racine, 4)!, (n) => n.tag === 'form'))

    expect(api.traiterDemandeRgpd).toHaveBeenCalledWith(4, 'appliquee', undefined)
    expect(texte(carte(racine, 4)!)).toContain('Appliquée le 3 octobre 2026')
    expect(un(carte(racine, 4)!, (n) => n.tag === 'form')).toBeNull()
  })

  it('« Refuser » exige une réponse, puis l’envoie', async () => {
    api.listerDemandesRgpd.mockResolvedValue({ elements: [demandeAdmin()] })
    api.traiterDemandeRgpd.mockResolvedValue(
      demandeAdmin({ decision: 'refusee', traiteeLe: '2026-10-03T12:00:00+02:00', reponse: 'Déjà retiré.' }),
    )
    const racine = await monterEnMemoire(DemandesRgpdView, { RouterLink: LienFactice })

    await cliquer(un(carte(racine, 4)!, boutonNomme('Refuser')))
    expect(api.traiterDemandeRgpd).not.toHaveBeenCalled()
    expect(un(racine, parAttribut('data-reponse-manquante'))).not.toBeNull()

    await declencher(un(racine, parAttribut('data-reponse-rgpd')), 'input', { value: 'Déjà retiré.' })
    await cliquer(un(carte(racine, 4)!, boutonNomme('Refuser')))

    expect(api.traiterDemandeRgpd).toHaveBeenCalledWith(4, 'refusee', 'Déjà retiré.')
    expect(texte(carte(racine, 4)!)).toContain('Refusée le 3 octobre 2026')
    expect(texte(carte(racine, 4)!)).toContain('Réponse : Déjà retiré.')
  })

  it('un 409 demande_deja_traitee relit la file et le dit', async () => {
    api.listerDemandesRgpd.mockResolvedValueOnce({ elements: [demandeAdmin()] })
    api.listerDemandesRgpd.mockResolvedValueOnce({
      elements: [demandeAdmin({ decision: 'appliquee', traiteeLe: '2026-10-03T12:00:00+02:00' })],
    })
    api.traiterDemandeRgpd.mockRejectedValue(new ErreurApi(409, 'demande_deja_traitee', 'Déjà traitée.'))
    const racine = await monterEnMemoire(DemandesRgpdView, { RouterLink: LienFactice })
    await soumettre(un(carte(racine, 4)!, (n) => n.tag === 'form'))

    expect(api.listerDemandesRgpd).toHaveBeenCalledTimes(2)
    expect(un(racine, parAttribut('data-deja-traitee'))).not.toBeNull()
    expect(texte(carte(racine, 4)!)).toContain('Appliquée le 3 octobre 2026')
  })

  it('sous « voir comme », la file ne se lit pas', async () => {
    etat.vueEmpruntee.value = { memberId: 7, memberLabel: 'Cobalt Romarin', depuis: '2026-10-03T10:00:00+02:00' }
    const racine = await monterEnMemoire(DemandesRgpdView, { RouterLink: LienFactice })

    expect(api.listerDemandesRgpd).not.toHaveBeenCalled()
    expect(texte(racine)).toContain('Les demandes RGPD ne s’affichent pas sous une vue empruntée.')
  })
})
