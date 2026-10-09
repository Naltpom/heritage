/**
 * `VoletProposition` — **monté et cliqué**, autour d'un formulaire factice.
 *
 * Le formulaire a ses propres tests (`formulaire-creation.client.test.ts`). Ici,
 * c'est ce que le volet fait de ses annonces qui compte :
 *
 * 1. **« Effacer » n'existe qu'avec une saisie, et demande confirmation** : vingt
 *    champs ne se perdent pas d'un clic ;
 * 2. **après le dépôt, il confirme sur place** — numéro de la demande, et ce que
 *    l'arbre redessiné a trouvé — puis repart d'une saisie vierge sur « Ajouter une
 *    autre personne », ou quand on le ferme, de quelque façon que ce soit ;
 * 3. **la croix, avant dépôt, garde la saisie** ;
 * 4. **« Refaire cette demande » recharge le formulaire ici**, sur la demande morte.
 */
import { defineComponent, h, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { attendre, boutonNomme, cliquer, monterEnMemoire, parAttribut, texte, un, type Noeud } from '@/test-utils/rendu-memoire'
import type { PersonneDeposee } from '@/components/proposition/depot'
import type { PlaceDansLArbre } from './voletProposition'

const DEPOT: PersonneDeposee = {
  nature: 'creation',
  demandeIds: [8000],
  noeudId: '#8000/p1',
  personId: null,
  libelle: 'Marie Dupont',
  soumise: true,
}

const formulaire = vi.hoisted(() => ({ reinitialiser: vi.fn(), montages: 0 }))

vi.mock('@/components/proposition/FormulaireCreationPersonne.vue', async () => {
  const { defineComponent: definir, h: rendre } = await import('vue')
  return {
    default: definir({
      name: 'FormulaireCreationPersonne',
      props: { contexte: { type: String, required: true }, refaire: { type: Number, default: null } },
      emits: ['deposee', 'refaire', 'sale'],
      setup(props, { emit, expose }) {
        formulaire.montages++
        expose({ reinitialiser: formulaire.reinitialiser })
        return () =>
          rendre('div', { 'data-formulaire': '', 'data-contexte': props.contexte, 'data-refaire': String(props.refaire) }, [
            rendre('button', { type: 'button', onClick: () => emit('sale', true) }, 'Saisir'),
            rendre('button', { type: 'button', onClick: () => emit('deposee', DEPOT) }, 'Déposer'),
            rendre('button', { type: 'button', onClick: () => emit('refaire', 91) }, 'Refaire'),
          ])
      },
    }),
  }
})

import VoletProposition from './VoletProposition.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

interface Monte {
  racine: Noeud
  place: { value: PlaceDansLArbre }
  ouvert: { value: boolean }
  fermetures: number[]
  deposees: PersonneDeposee[]
}

/** Monté comme l'arbre le monte : `fermer` repasse `ouvert` à `false`. */
async function monter(): Promise<Monte> {
  const place = ref<PlaceDansLArbre>('recherche')
  const ouvert = ref(true)
  const fermetures: number[] = []
  const deposees: PersonneDeposee[] = []
  const Hote = defineComponent({
    setup: () => () =>
      h(VoletProposition, {
        ouvert: ouvert.value,
        place: place.value,
        onFermer: () => {
          fermetures.push(1)
          ouvert.value = false
        },
        onDeposee: (d: PersonneDeposee) => deposees.push(d),
      }),
  })
  const racine = await monterEnMemoire(Hote, { RouterLink: LienFactice })
  return { racine, place, ouvert, fermetures, deposees }
}

/** Le formulaire est-il à l'écran ? `v-show` masque son enveloppe. */
function formulaireVisible(racine: Noeud): boolean {
  const enveloppe = un(racine, parAttribut('data-formulaire'))?.parent
  return enveloppe?.style?.['display'] !== 'none'
}

beforeEach(() => {
  formulaire.reinitialiser.mockClear()
  formulaire.montages = 0
})

describe('le volet « Ajouter une personne »', () => {
  it('porte le formulaire en contexte volet', async () => {
    const { racine } = await monter()

    expect(un(racine, parAttribut('data-formulaire'))?.attributs['data-contexte']).toBe('volet')
  })

  it('n’offre « Effacer » qu’avec une saisie, et le fait confirmer', async () => {
    const { racine } = await monter()
    expect(un(racine, parAttribut('data-effacer'))).toBeNull()

    await cliquer(un(racine, boutonNomme('Saisir')))
    await cliquer(un(racine, parAttribut('data-effacer')))
    expect(texte(racine)).toContain('Effacer toute la saisie ?')
    expect(formulaire.reinitialiser).not.toHaveBeenCalled()

    // « Garder » : rien ne part.
    await cliquer(un(racine, boutonNomme('Garder')))
    expect(formulaire.reinitialiser).not.toHaveBeenCalled()

    await cliquer(un(racine, parAttribut('data-effacer')))
    await cliquer(un(racine, boutonNomme('Effacer')))
    expect(formulaire.reinitialiser).toHaveBeenCalledTimes(1)
  })

  it('ferme sans rien effacer avant le dépôt : la saisie est gardée', async () => {
    const { racine, fermetures } = await monter()
    await cliquer(un(racine, boutonNomme('Saisir')))

    const croix = un(racine, (n) => n.tag === 'button' && n.attributs['aria-label'] === 'Fermer')
    expect(croix?.attributs['title']).toBe('Fermer — votre saisie est gardée')
    await cliquer(croix)

    expect(fermetures).toHaveLength(1)
    expect(formulaire.reinitialiser).not.toHaveBeenCalled()
  })

  it('confirme le dépôt sur place, et dit ce que l’arbre a trouvé', async () => {
    const { racine, place, deposees } = await monter()
    await cliquer(un(racine, boutonNomme('Déposer')))

    expect(deposees).toEqual([DEPOT])
    const confirmation = un(racine, parAttribut('data-confirmation-depot'))
    expect(texte(confirmation!)).toContain('Proposition envoyée — demande n° 8000')
    expect(texte(confirmation!)).toContain('prend sa place dans l’arbre')
    expect(texte(confirmation!)).toContain('Voir la demande')
    expect(formulaireVisible(racine)).toBe(false)

    // L'arbre relu ne l'a pas dessinée : la phrase le dit.
    place.value = 'absente'
    await attendre()
    expect(texte(racine)).toContain('pas encore dessinée dans l’arbre')
  })

  it('repart d’une saisie vierge sur « Ajouter une autre personne »', async () => {
    const { racine } = await monter()
    await cliquer(un(racine, boutonNomme('Déposer')))
    await cliquer(un(racine, boutonNomme('Ajouter une autre personne')))

    expect(formulaire.reinitialiser).toHaveBeenCalledTimes(1)
    expect(un(racine, parAttribut('data-confirmation-depot'))).toBeNull()
    expect(formulaireVisible(racine)).toBe(true)
  })

  it('« Fermer » après le dépôt : le volet se ferme, et rouvrira vierge', async () => {
    const { racine, fermetures } = await monter()
    await cliquer(un(racine, boutonNomme('Déposer')))
    await cliquer(un(racine, boutonNomme('Fermer')))

    expect(fermetures).toHaveLength(1)
    expect(formulaire.reinitialiser).toHaveBeenCalledTimes(1)
    expect(un(racine, parAttribut('data-confirmation-depot'))).toBeNull()
  })

  it('fermé par le bouton de la barre après un dépôt, il rouvre vierge lui aussi', async () => {
    const { racine, ouvert } = await monter()
    await cliquer(un(racine, boutonNomme('Déposer')))

    ouvert.value = false
    await attendre()
    ouvert.value = true
    await attendre()

    expect(formulaire.reinitialiser).toHaveBeenCalledTimes(1)
    expect(un(racine, parAttribut('data-confirmation-depot'))).toBeNull()
    expect(formulaireVisible(racine)).toBe(true)
  })

  it('recharge le formulaire sur la demande à refaire, sans quitter l’arbre', async () => {
    const { racine } = await monter()
    expect(formulaire.montages).toBe(1)

    await cliquer(un(racine, boutonNomme('Refaire')))

    expect(formulaire.montages).toBe(2)
    expect(un(racine, parAttribut('data-formulaire'))?.attributs['data-refaire']).toBe('91')
  })
})
