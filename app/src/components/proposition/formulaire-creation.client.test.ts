/**
 * `FormulaireCreationPersonne` — **monté et soumis**, hors de toute page.
 *
 * Le formulaire est sorti de `PropositionCreationView` pour vivre aussi dans le
 * volet de `/arbre`, où quitter la page perdrait l'arbre et la saisie. Ce qui se
 * prouve ici ne se voit pas au rendu serveur, il faut soumettre :
 *
 * 1. **le dépôt annonce `deposee`**, avec le nœud **lu dans la réponse** de
 *    `creerDemande` (`brouillons[0].noeudId`) — jamais recollé en `#<id>/p1` ;
 * 2. **il ne touche pas au routeur** : ni `useRoute`, ni `useRouter`, ni
 *    navigation — c'est l'hôte (page ou volet) qui décide où l'on va ;
 * 3. **la reprise arrive par la prop `refaire`**, et « Refaire cette demande »
 *    remonte en événement au lieu de naviguer ;
 * 4. **`reinitialiser()` repart d'une saisie vierge**, et le volet le sait (`sale`).
 */
import { defineComponent, h, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Demande } from '@/lib/api'
import { attendre, boutonNomme, monterEnMemoire, texte, un, type Noeud } from '@/test-utils/rendu-memoire'

/* ------------------------------------------------------------------ */
/* Les dépendances                                                     */
/* ------------------------------------------------------------------ */

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

const routeur = vi.hoisted(() => ({
  useRoute: vi.fn(() => ({ query: {}, params: {} })),
  push: vi.fn(() => Promise.resolve()),
  useRouter: vi.fn(),
}))
routeur.useRouter.mockImplementation(() => ({ push: routeur.push, replace: routeur.push }))

vi.mock('vue-router', () => ({ useRoute: routeur.useRoute, useRouter: routeur.useRouter }))

const api = vi.hoisted(() => ({
  creerDemande: vi.fn(),
  televerserMedia: vi.fn(),
  invaliderGraphe: vi.fn(),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  ...api,
}))

const reprise = vi.hoisted(() => ({ chargerReprise: vi.fn() }))

vi.mock('@/components/proposition/reprise', async (original) => ({
  ...(await original<typeof import('@/components/proposition/reprise')>()),
  ...reprise,
}))

// Le `<select v-model>` du sexe lit `el.options`, qu'un rendu en mémoire n'a pas :
// les champs d'identité ont leurs propres tests de rendu, on les remplace ici.
vi.mock('@/components/proposition/ChampsIdentite.vue', async () => {
  const { defineComponent: definir, h: rendre } = await import('vue')
  return { default: definir({ name: 'ChampsIdentite', setup: () => () => rendre('div', { 'data-champs-identite': '' }) }) }
})

import { ErreurApi } from '@/lib/api'
import { creationVide } from './creation'
import type { PersonneDeposee } from './depot'
import FormulaireCreationPersonne from './FormulaireCreationPersonne.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

/* ------------------------------------------------------------------ */
/* Les charges                                                         */
/* ------------------------------------------------------------------ */

/** Marie Dupont, fille de Jean Dupont (n° 12) : de quoi soumettre. */
function saisieComplete() {
  const saisie = creationVide()
  saisie.brouillon.givenNames = 'Marie'
  saisie.brouillon.surname = 'Dupont'
  saisie.filiation.parents = [{ personId: 12, libelle: 'Jean Dupont' }]
  return saisie
}

function reponse(): Demande {
  return {
    id: 8000,
    kind: 'creation_personne',
    status: 'en_attente',
    authorLabel: 'Opale Bourrache',
    targetKind: null,
    targetId: null,
    targetLabel: null,
    message: null,
    decisionMessage: null,
    autoValidated: false,
    duplicateOfId: null,
    submittedAt: '2026-10-09T10:00:00+00:00',
    createdAt: '2026-10-09T10:00:00+00:00',
    ops: [],
    // Un nœud que `#8000/p1` ne donnerait pas : s'il ressort, c'est qu'il a été lu.
    brouillons: [
      {
        localRef: '#p1',
        noeudId: '#8000/p3',
        givenNames: 'Marie',
        surname: 'Dupont',
        sex: 'inconnu',
        deceased: false,
        resolvedPersonId: null,
      },
    ],
    liens: [],
    revisions: [],
  }
}

interface Monte {
  racine: Noeud
  deposees: PersonneDeposee[]
  refaites: number[]
  sale: boolean[]
  formulaire: { value: { reinitialiser: () => void } | null }
}

async function monter(refaire: number | null = 77): Promise<Monte> {
  const deposees: PersonneDeposee[] = []
  const refaites: number[] = []
  const sale: boolean[] = []
  const formulaire = ref<{ reinitialiser: () => void } | null>(null)
  const Hote = defineComponent({
    setup: () => () =>
      h(FormulaireCreationPersonne, {
        ref: formulaire,
        contexte: 'volet',
        refaire,
        onDeposee: (d: PersonneDeposee) => deposees.push(d),
        onRefaire: (id: number) => refaites.push(id),
        onSale: (s: boolean) => sale.push(s),
      }),
  })
  const racine = await monterEnMemoire(Hote, { RouterLink: LienFactice })
  return { racine, deposees, refaites, sale, formulaire }
}

async function soumettre(racine: Noeud): Promise<void> {
  const form = un(racine, (n) => n.tag === 'form')
  const surSoumission = form?.ecouteurs['submit']
  if (typeof surSoumission !== 'function') throw new Error('Pas de formulaire à soumettre.')
  surSoumission({ preventDefault: () => undefined })
  await attendre()
}

beforeEach(() => {
  vi.stubGlobal('window', { addEventListener: () => undefined, removeEventListener: () => undefined })
  // Quand la valeur change sous un champ, `v-model` regarde si sa racine est un
  // document qui lui donne le focus : ici, ni document ni ombre.
  vi.stubGlobal('Document', class {})
  vi.stubGlobal('ShadowRoot', class {})
  Object.values(api).forEach((f) => f.mockReset())
  reprise.chargerReprise.mockReset()
  routeur.useRoute.mockClear()
  routeur.useRouter.mockClear()
  routeur.push.mockClear()
  reprise.chargerReprise.mockResolvedValue({
    kind: 'creation_personne',
    message: 'J’ai le livret de famille.',
    origine: { demandeId: 77, statut: 'refusee' },
    saisie: saisieComplete(),
    aResoudre: [],
    brouillonsAResoudre: [],
    avertissements: [],
  })
})

/* ------------------------------------------------------------------ */
/* Les cas                                                             */
/* ------------------------------------------------------------------ */

describe('le formulaire extrait, hors de toute page', () => {
  it('reprend la demande passée en prop, puis annonce le dépôt avec le nœud de la réponse', async () => {
    api.creerDemande.mockResolvedValue(reponse())
    const { racine, deposees } = await monter(77)

    // La reprise est lue d'après la prop, et le dit.
    expect(reprise.chargerReprise).toHaveBeenCalledWith(77)
    expect(texte(racine)).toContain('Cette demande reprend la demande n° 77')

    await soumettre(racine)

    expect(api.creerDemande).toHaveBeenCalledTimes(1)
    const corps = api.creerDemande.mock.calls[0]?.[0] as Record<string, unknown>
    expect(corps).toMatchObject({ kind: 'creation_personne', soumettre: true, origine: { demandeId: 77 } })
    expect(api.invaliderGraphe).toHaveBeenCalledTimes(1)

    expect(deposees).toEqual([
      {
        nature: 'creation',
        demandeIds: [8000],
        noeudId: '#8000/p3',
        personId: null,
        libelle: 'Marie Dupont',
        soumise: true,
      },
    ])
  })

  it('ne touche jamais au routeur', async () => {
    api.creerDemande.mockResolvedValue(reponse())
    const { racine } = await monter(77)
    await soumettre(racine)

    expect(routeur.useRoute).not.toHaveBeenCalled()
    expect(routeur.useRouter).not.toHaveBeenCalled()
    expect(routeur.push).not.toHaveBeenCalled()
  })

  it('n’annonce rien tant qu’une photo reste à envoyer, et ne redépose pas la demande', async () => {
    api.creerDemande.mockResolvedValue(reponse())
    api.televerserMedia.mockRejectedValueOnce(new Error('réseau')).mockResolvedValue({ id: 5 })
    const { racine, deposees } = await monter(77)

    // Un acte en PDF, choisi par « Importer une photo ou un fichier ».
    const champ = un(racine, (n) => n.tag === 'input' && n.attributs['type'] === 'file')
    const surChoix = champ?.ecouteurs['change']
    if (typeof surChoix !== 'function') throw new Error('Pas de champ de fichier.')
    surChoix({ target: { files: [new File(['%PDF'], 'acte.pdf', { type: 'application/pdf' })], value: 'acte.pdf' } })
    await attendre()

    await soumettre(racine)

    // La demande est là, la pièce non : on reste, et on le dit.
    expect(api.creerDemande).toHaveBeenCalledTimes(1)
    expect(api.televerserMedia.mock.calls[0]?.[0]).toMatchObject({ brouillon: { demandeId: 8000, brouillonRef: 'p1' } })
    expect(deposees).toEqual([])
    expect(texte(racine)).toContain('Votre demande n° 8000 est enregistrée')

    // « Réessayer l'envoi des fichiers » : la pièce seule repart, puis le dépôt s'annonce.
    await soumettre(racine)

    expect(api.creerDemande).toHaveBeenCalledTimes(1)
    expect(api.televerserMedia).toHaveBeenCalledTimes(2)
    expect(deposees.map((d) => d.noeudId)).toEqual(['#8000/p3'])
  })

  it('une saisie invalide ne dépose ni n’annonce rien', async () => {
    const { racine, deposees } = await monter(null)
    await soumettre(racine)

    expect(api.creerDemande).not.toHaveBeenCalled()
    expect(deposees).toEqual([])
    expect(texte(racine)).toContain('sans lien, elle flotterait hors de l’arbre')
  })

  it('remonte « Refaire cette demande » au lieu de naviguer', async () => {
    api.creerDemande.mockRejectedValue(
      new ErreurApi(409, 'demande_caduque', 'La fiche a changé.', null, { rejouable: false, demandeId: 91, champs: [] }),
    )
    const { racine, refaites, deposees } = await monter(77)
    await soumettre(racine)

    expect(texte(racine)).toContain('Cette demande est devenue caduque')
    const bouton = un(racine, boutonNomme('Refaire cette demande'))
    const surClic = bouton?.ecouteurs['click']
    if (typeof surClic !== 'function') throw new Error('Pas de bouton « Refaire ».')
    surClic({})
    await attendre()

    expect(refaites).toEqual([91])
    expect(deposees).toEqual([])
    expect(routeur.push).not.toHaveBeenCalled()
  })

  it('repart d’une saisie vierge sur `reinitialiser()`, et le dit', async () => {
    const { racine, sale, formulaire } = await monter(77)

    // La reprise a rempli la saisie : elle est « sale ».
    expect(sale.at(-1)).toBe(true)
    expect(texte(racine)).toContain('Cette demande reprend la demande n° 77')

    formulaire.value?.reinitialiser()
    await attendre()

    expect(sale.at(-1)).toBe(false)
    expect(texte(racine)).not.toContain('Cette demande reprend la demande n° 77')
  })
})
