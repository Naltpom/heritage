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
import type { DemandeDeCompletion } from '@/components/proposition/fusion'
import type { EnEvidence } from '@/components/proposition/ressemblances'
import type { PlaceDansLArbre } from './voletProposition'

const DEPOT: PersonneDeposee = {
  nature: 'creation',
  demandeIds: [8000],
  noeudId: '#8000/p1',
  personId: null,
  libelle: 'Marie Dupont',
  soumise: true,
}

/** « C'est elle : compléter sa fiche » — rien de créé, deux demandes sur Marie (n° 17). */
const FICHE_COMPLETEE: PersonneDeposee = {
  nature: 'fiche',
  demandeIds: [41, 42],
  noeudId: null,
  personId: 17,
  libelle: 'Marie Dupont',
  soumise: true,
}

/** Une proposition en attente complétée sur place (ADR 0017) : la même demande. */
const PROPOSITION_COMPLETEE: PersonneDeposee = {
  nature: 'completion',
  demandeIds: [12],
  noeudId: '#12/p3',
  personId: null,
  libelle: 'Marie Dupont',
  soumise: true,
}

const formulaire = vi.hoisted(() => ({ reinitialiser: vi.fn(), montages: 0, reponses: [] as boolean[] }))

vi.mock('@/components/proposition/FormulaireCreationPersonne.vue', async () => {
  const { defineComponent: definir, h: rendre } = await import('vue')
  return {
    default: definir({
      name: 'FormulaireCreationPersonne',
      props: {
        contexte: { type: String, required: true },
        refaire: { type: Number, default: null },
        completer: { type: Object, default: null },
      },
      emits: ['deposee', 'refaire', 'sale', 'voir-personne', 'voir-proposition', 'en-evidence', 'completion'],
      setup(props, { emit, expose }) {
        formulaire.montages++
        expose({ reinitialiser: formulaire.reinitialiser })
        const repondre = (dessinee: boolean) => formulaire.reponses.push(dessinee)
        const cible = () => props.completer as { demandeId: number; ref: string; jeton?: number } | null
        return () =>
          rendre(
            'div',
            {
              'data-formulaire': '',
              'data-contexte': props.contexte,
              'data-refaire': String(props.refaire),
              'data-completer': cible() === null ? 'null' : `${cible()!.demandeId}/${cible()!.ref}/${cible()!.jeton}`,
            },
            [
              rendre('button', { type: 'button', onClick: () => emit('sale', true) }, 'Saisir'),
              rendre('button', { type: 'button', onClick: () => emit('deposee', DEPOT) }, 'Déposer'),
              rendre('button', { type: 'button', onClick: () => emit('deposee', FICHE_COMPLETEE) }, 'Compléter'),
              rendre(
                'button',
                { type: 'button', onClick: () => emit('deposee', PROPOSITION_COMPLETEE) },
                'Enregistrer les compléments',
              ),
              rendre(
                'button',
                { type: 'button', onClick: () => emit('completion', { demandeId: 12, ref: 'p3' }) },
                'Entrer en complétion',
              ),
              rendre('button', { type: 'button', onClick: () => emit('completion', null) }, 'Revenir à une nouvelle personne'),
              rendre('button', { type: 'button', onClick: () => emit('refaire', 91) }, 'Refaire'),
              rendre('button', { type: 'button', onClick: () => emit('voir-personne', 17, repondre) }, 'Voir la personne'),
              rendre(
                'button',
                { type: 'button', onClick: () => emit('voir-proposition', '#12/p1', repondre) },
                'Voir la proposition',
              ),
              rendre(
                'button',
                { type: 'button', onClick: () => emit('en-evidence', { personIds: [17], noeudIds: ['#12/p1'] }) },
                'Cercler',
              ),
            ],
          )
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
  /** « Compléter » sur un nœud de l'arbre : un jeton par clic. */
  completer: { value: DemandeDeCompletion | null }
  fermetures: number[]
  deposees: PersonneDeposee[]
  /** Ce que l'arbre a été prié de montrer, et de cercler. */
  montrees: (number | string)[]
  evidences: EnEvidence[]
}

/** Monté comme l'arbre le monte : `fermer` repasse `ouvert` à `false`. L'arbre
 *  « dessine » la personne 17, pas le nœud proposé. */
async function monter(): Promise<Monte> {
  const place = ref<PlaceDansLArbre>('recherche')
  const ouvert = ref(true)
  const completer = ref<DemandeDeCompletion | null>(null)
  const fermetures: number[] = []
  const deposees: PersonneDeposee[] = []
  const montrees: (number | string)[] = []
  const evidences: EnEvidence[] = []
  const Hote = defineComponent({
    setup: () => () =>
      h(VoletProposition, {
        ouvert: ouvert.value,
        place: place.value,
        completer: completer.value,
        onFermer: () => {
          fermetures.push(1)
          ouvert.value = false
        },
        onDeposee: (d: PersonneDeposee) => deposees.push(d),
        onVoirPersonne: (personId: number, repondre: (dessinee: boolean) => void) => {
          montrees.push(personId)
          repondre(personId === 17)
        },
        onVoirProposition: (noeudId: string, repondre: (dessinee: boolean) => void) => {
          montrees.push(noeudId)
          repondre(false)
        },
        onEnEvidence: (e: EnEvidence) => evidences.push(e),
      }),
  })
  const racine = await monterEnMemoire(Hote, { RouterLink: LienFactice })
  return { racine, place, ouvert, completer, fermetures, deposees, montrees, evidences }
}

/** Ce que le formulaire a reçu en `completer` : `12/p3/1`, ou `null`. */
function completerRecu(racine: Noeud): unknown {
  return un(racine, parAttribut('data-formulaire'))?.attributs['data-completer']
}

/** Le formulaire est-il à l'écran ? `v-show` masque son enveloppe. */
function formulaireVisible(racine: Noeud): boolean {
  const enveloppe = un(racine, parAttribut('data-formulaire'))?.parent
  return enveloppe?.style?.['display'] !== 'none'
}

beforeEach(() => {
  formulaire.reinitialiser.mockClear()
  formulaire.montages = 0
  formulaire.reponses = []
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

describe('le volet et les ressemblances (« Est-ce déjà… ? »)', () => {
  it('relaie « voir » à l’arbre, et rend sa réponse au formulaire', async () => {
    const { racine, montrees } = await monter()

    await cliquer(un(racine, boutonNomme('Voir la personne')))
    await cliquer(un(racine, boutonNomme('Voir la proposition')))

    expect(montrees).toEqual([17, '#12/p1'])
    // La personne est dessinée, le nœud proposé non : le formulaire le saura.
    expect(formulaire.reponses).toEqual([true, false])
  })

  it('relaie ce que l’arbre doit cercler', async () => {
    const { racine, evidences } = await monter()

    await cliquer(un(racine, boutonNomme('Cercler')))

    expect(evidences).toEqual([{ personIds: [17], noeudIds: ['#12/p1'] }])
  })

  it('confirme une fiche complétée : ses demandes, un lien pour chacune', async () => {
    const { racine, place, deposees } = await monter()
    await cliquer(un(racine, boutonNomme('Compléter')))
    place.value = 'placee'
    await attendre()

    expect(deposees).toEqual([FICHE_COMPLETEE])
    const confirmation = un(racine, parAttribut('data-confirmation-depot'))
    expect(texte(confirmation!)).toContain('Demandes n° 41 et 42 déposées')
    expect(texte(confirmation!)).toContain('La fiche de Marie Dupont est choisie dans l’arbre')
    expect(texte(confirmation!)).toContain('Voir la demande n° 41')
    expect(texte(confirmation!)).toContain('Voir la demande n° 42')
    expect(texte(confirmation!)).not.toContain('Proposition envoyée')

    // « Ajouter une autre personne » repart d'une saisie vierge, comme après une création.
    await cliquer(un(racine, boutonNomme('Ajouter une autre personne')))
    expect(formulaire.reinitialiser).toHaveBeenCalledTimes(1)
  })
})

describe('le volet et « Compléter » une proposition en attente (ADR 0017)', () => {
  it('passe au formulaire la proposition demandée par l’arbre, une fois', async () => {
    const { racine, completer } = await monter()
    expect(completerRecu(racine)).toBe('null')

    completer.value = { demandeId: 12, ref: 'p3', jeton: 1 }
    await attendre()
    expect(completerRecu(racine)).toBe('12/p3/1')

    // Le formulaire en sort : la demande tombe, elle ne se rouvrira pas toute seule.
    await cliquer(un(racine, boutonNomme('Revenir à une nouvelle personne')))
    expect(completerRecu(racine)).toBe('null')

    // Un nouveau clic sur le même nœud : un nouveau jeton, et elle repart.
    completer.value = { demandeId: 12, ref: 'p3', jeton: 2 }
    await attendre()
    expect(completerRecu(racine)).toBe('12/p3/2')
  })

  it('le titre suit le mode du formulaire', async () => {
    const { racine } = await monter()
    await cliquer(un(racine, boutonNomme('Entrer en complétion')))
    expect(texte(un(racine, (n) => n.tag === 'h2')!)).toBe('Compléter une proposition')

    await cliquer(un(racine, boutonNomme('Revenir à une nouvelle personne')))
    expect(texte(un(racine, (n) => n.tag === 'h2')!)).toBe('Ajouter une personne')
  })

  it('après une complétion, « Reprendre votre saisie » la remontre — sans l’effacer', async () => {
    const { racine, deposees } = await monter()
    // Le formulaire a rendu la saisie mise de côté : elle est « sale ».
    await cliquer(un(racine, boutonNomme('Saisir')))
    await cliquer(un(racine, boutonNomme('Enregistrer les compléments')))

    expect(deposees).toEqual([PROPOSITION_COMPLETEE])
    expect(texte(un(racine, parAttribut('data-confirmation-depot'))!)).toContain('Proposition complétée — demande n° 12')

    await cliquer(un(racine, boutonNomme('Reprendre votre saisie')))
    expect(formulaire.reinitialiser).not.toHaveBeenCalled()
    expect(un(racine, parAttribut('data-confirmation-depot'))).toBeNull()
    expect(formulaireVisible(racine)).toBe(true)
  })

  it('fermé après une complétion, il garde la saisie rendue', async () => {
    const { racine } = await monter()
    await cliquer(un(racine, boutonNomme('Saisir')))
    await cliquer(un(racine, boutonNomme('Enregistrer les compléments')))
    await cliquer(un(racine, boutonNomme('Fermer')))

    expect(formulaire.reinitialiser).not.toHaveBeenCalled()
    expect(un(racine, parAttribut('data-confirmation-depot'))).toBeNull()
  })

  it('« Compléter » après une création : la confirmation s’efface, le formulaire repart neuf', async () => {
    const { racine, completer } = await monter()
    await cliquer(un(racine, boutonNomme('Déposer')))
    expect(un(racine, parAttribut('data-confirmation-depot'))).not.toBeNull()

    completer.value = { demandeId: 12, ref: 'p3', jeton: 1 }
    await attendre()

    expect(formulaire.reinitialiser).toHaveBeenCalledTimes(1)
    expect(un(racine, parAttribut('data-confirmation-depot'))).toBeNull()
    expect(completerRecu(racine)).toBe('12/p3/1')
  })

  it('« Refaire cette demande » remonte le formulaire sans rouvrir la complétion', async () => {
    const { racine, completer } = await monter()
    completer.value = { demandeId: 12, ref: 'p3', jeton: 1 }
    await attendre()

    await cliquer(un(racine, boutonNomme('Refaire')))

    expect(formulaire.montages).toBe(2)
    expect(completerRecu(racine)).toBe('null')
  })
})
