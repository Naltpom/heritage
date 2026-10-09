/**
 * `/personnes/proposer?mode=formulaire` — **ce que la page fait d'un dépôt**,
 * autour d'un formulaire factice.
 *
 * Deux issues, deux conduites :
 *
 * 1. **une personne proposée** : la page mène au suivi de sa demande — c'est là
 *    que se lira la décision ;
 * 2. **une fiche complétée** (« C'est elle : compléter sa fiche ») : plusieurs
 *    demandes sur une personne qui existait, aucune n'est « la » demande à suivre.
 *    La page reste, les annonce, et le formulaire repart d'une saisie vierge.
 */
import { defineComponent, h } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { boutonNomme, cliquer, monterEnMemoire, parAttribut, texte, un } from '@/test-utils/rendu-memoire'
import type { PersonneDeposee } from '@/components/proposition/depot'

vi.mock('@/composables/useSession', async () => {
  const { computed } = await import('vue')
  return {
    useSession: () => ({
      rattache: computed(() => true),
      connecte: computed(() => true),
      administrateur: computed(() => false),
      vueEmpruntee: computed(() => null),
    }),
  }
})

const routeur = vi.hoisted(() => ({ push: vi.fn(() => Promise.resolve()) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: { mode: 'formulaire' }, params: {} }),
  useRouter: () => ({ push: routeur.push, replace: routeur.push }),
}))

const CREATION: PersonneDeposee = {
  nature: 'creation',
  demandeIds: [8000],
  noeudId: '#8000/p1',
  personId: null,
  libelle: 'Marie Dupont',
  soumise: true,
}
const FICHE: PersonneDeposee = {
  nature: 'fiche',
  demandeIds: [41, 42],
  noeudId: null,
  personId: 17,
  libelle: 'Marie Dupont',
  soumise: true,
}

const formulaire = vi.hoisted(() => ({ reinitialiser: vi.fn() }))

vi.mock('@/components/proposition/FormulaireCreationPersonne.vue', async () => {
  const { defineComponent: definir, h: rendre } = await import('vue')
  return {
    default: definir({
      name: 'FormulaireCreationPersonne',
      props: { contexte: { type: String, required: true }, refaire: { type: Number, default: null } },
      emits: ['deposee', 'refaire'],
      setup(props, { emit, expose }) {
        expose({ reinitialiser: formulaire.reinitialiser })
        return () =>
          rendre('div', { 'data-formulaire': '', 'data-contexte': props.contexte }, [
            rendre('button', { type: 'button', onClick: () => emit('deposee', CREATION) }, 'Créer'),
            rendre('button', { type: 'button', onClick: () => emit('deposee', FICHE) }, 'Compléter'),
          ])
      },
    }),
  }
})

import PropositionCreationView from './PropositionCreationView.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (props, { slots }) => () => h('a', { href: JSON.stringify(props.to) }, slots['default']?.()),
})

beforeEach(() => {
  routeur.push.mockClear()
  formulaire.reinitialiser.mockClear()
})

describe('la page « Ajouter une personne », après un dépôt', () => {
  it('mène au suivi de la demande d’une personne proposée', async () => {
    const racine = await monterEnMemoire(PropositionCreationView, { RouterLink: LienFactice })
    expect(un(racine, parAttribut('data-formulaire'))?.attributs['data-contexte']).toBe('page')

    await cliquer(un(racine, boutonNomme('Créer')))

    expect(routeur.push).toHaveBeenCalledWith({ name: 'demande.detail', params: { id: 8000 } })
    expect(un(racine, parAttribut('data-fiche-completee'))).toBeNull()
  })

  it('reste sur une fiche complétée : annonce les demandes, et repart d’une saisie vierge', async () => {
    const racine = await monterEnMemoire(PropositionCreationView, { RouterLink: LienFactice })

    await cliquer(un(racine, boutonNomme('Compléter')))

    expect(routeur.push).not.toHaveBeenCalled()
    expect(formulaire.reinitialiser).toHaveBeenCalledTimes(1)
    const bandeau = un(racine, parAttribut('data-fiche-completee'))
    expect(bandeau?.attributs['role']).toBe('status')
    expect(texte(bandeau!)).toContain('Demandes n° 41 et 42 déposées')
    expect(texte(bandeau!)).toContain('Vos compléments à la fiche de Marie Dupont attendent la validation')
    expect(texte(bandeau!)).toContain('Voir sa fiche')
    expect(texte(bandeau!)).toContain('Suivre mes demandes')
  })
})
