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
 * 4. **`reinitialiser()` repart d'une saisie vierge**, et le volet le sait (`sale`) ;
 * 5. **« Est-ce déjà… ? »** : un seul appel par route après le délai, les cartes,
 *    le rappel avant l'envoi — une fois, et jamais sur un brouillon — et « C'est
 *    elle » qui dépose `modification_personne` puis `ajout_lien` sur la fiche
 *    existante, sans toucher au routeur.
 */
import { defineComponent, h, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Demande } from '@/lib/api'
import { attendre, boutonNomme, cliquer, demonterTout, monterEnMemoire, texte, un, type Noeud } from '@/test-utils/rendu-memoire'

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
  chercherRessemblancesArbre: vi.fn(),
  chercherRessemblancesBrouillons: vi.fn(),
  lireFichePersonne: vi.fn(),
  proposerLeMedia: vi.fn(),
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

import { ErreurApi, type FichePersonneComplete, type RessemblanceDansLArbre } from '@/lib/api'
import { DELAI_DES_RESSEMBLANCES_MS } from '@/composables/useRessemblances'
import { creationVide } from './creation'
import type { PersonneDeposee } from './depot'
import type { EnEvidence } from './ressemblances'
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
  evidences: EnEvidence[]
  formulaire: { value: { reinitialiser: () => void } | null }
}

async function monter(refaire: number | null = 77): Promise<Monte> {
  const deposees: PersonneDeposee[] = []
  const refaites: number[] = []
  const sale: boolean[] = []
  const evidences: EnEvidence[] = []
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
        onEnEvidence: (e: EnEvidence) => evidences.push(e),
      }),
  })
  const racine = await monterEnMemoire(Hote, { RouterLink: LienFactice })
  return { racine, deposees, refaites, sale, evidences, formulaire }
}

/** Le calme que le formulaire attend avant de chercher, puis ce qui s'ensuit. */
async function attendreLesRessemblances(): Promise<void> {
  await new Promise((fin) => setTimeout(fin, DELAI_DES_RESSEMBLANCES_MS + 40))
  await attendre()
}

/** Marie Dupont, déjà dans l'arbre (n° 17) : sexe inconnu, ni parents ni union. */
const MARIE_DANS_L_ARBRE: RessemblanceDansLArbre = {
  personId: 17,
  displayName: 'Marie Dupont',
  sex: 'inconnu',
  vivant: false,
  generation: -2,
  branch: 'pp',
  ligneDates: '1894 – 1968',
  lieuNaissance: null,
  criteres: ['nom', 'prenom'],
  raisons: ['même nom', 'prénom Marie en commun'],
  score: 2,
}

function ficheDeMarie(): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id: 17,
    gedcomXref: null,
    displayName: 'Marie Dupont',
    givenNames: 'Marie',
    surname: 'Dupont',
    marriedName: null,
    nickname: null,
    sex: 'inconnu',
    deceased: true,
    vivant: false,
    birth: null,
    death: null,
    ligneDates: '1894 – 1968',
    branch: 'pp',
    generation: -2,
    bestQuay: null,
    citationCount: 0,
    forcePrivacy: false,
    privacyNote: null,
    version: 5,
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
    demandesEnAttente: [],
  }
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
  // Par défaut, rien ne ressemble : les cas d'avant le panneau restent ce qu'ils étaient.
  api.chercherRessemblancesArbre.mockResolvedValue({ elements: [], tronque: false })
  api.chercherRessemblancesBrouillons.mockResolvedValue({ elements: [], tronque: false })
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

// Le formulaire attend le calme de la frappe avant de chercher : resté monté, son
// minuteur partirait dans le test suivant.
afterEach(demonterTout)

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

describe('« Est-ce déjà… ? » dans le formulaire', () => {
  it('cherche une fois après le délai, sur les deux routes, et montre ce qui ressemble', async () => {
    api.chercherRessemblancesArbre.mockResolvedValue({ elements: [MARIE_DANS_L_ARBRE], tronque: false })
    const { racine, evidences } = await monter(77)

    // La reprise vient de remplir la saisie : rien n'est encore parti.
    expect(api.chercherRessemblancesArbre).not.toHaveBeenCalled()
    expect(un(racine, (n) => 'data-ressemblances' in n.attributs)).toBeNull()

    await attendreLesRessemblances()

    expect(api.chercherRessemblancesArbre).toHaveBeenCalledTimes(1)
    expect(api.chercherRessemblancesBrouillons).toHaveBeenCalledTimes(1)
    // La fiche comparée, sans la note ni les liens de la saisie.
    expect(api.chercherRessemblancesArbre.mock.calls[0]?.[0]).toMatchObject({ givenNames: 'Marie', surname: 'Dupont' })
    expect(api.chercherRessemblancesArbre.mock.calls[0]?.[0]).not.toHaveProperty('note')

    expect(texte(racine)).toContain('Est-ce déjà quelqu’un de connu ?')
    expect(texte(racine)).toContain('Marie Dupont')
    const resume = un(racine, (n) => 'data-resume-ressemblances' in n.attributs)
    expect(resume?.attributs['aria-live']).toBe('polite')
    expect(texte(resume!)).toBe('Une personne de l’arbre ressemble à votre saisie.')
    // L'arbre est prié de la cercler.
    expect(evidences.at(-1)).toEqual({ personIds: [17], noeudIds: [] })

    // « Ce n'est pas elle » : la carte s'en va, le cercle aussi.
    await cliquer(un(racine, boutonNomme('Ce n’est pas elle')))
    expect(un(racine, (n) => 'data-ressemblances' in n.attributs)).toBeNull()
    expect(evidences.at(-1)).toEqual({ personIds: [], noeudIds: [] })
  })

  it('« C’est elle » dépose modification_personne puis ajout_lien sur la fiche, sans changer de route', async () => {
    api.chercherRessemblancesArbre.mockResolvedValue({ elements: [MARIE_DANS_L_ARBRE], tronque: false })
    api.lireFichePersonne.mockResolvedValue(ficheDeMarie())
    api.creerDemande.mockResolvedValueOnce({ ...reponse(), id: 8101 }).mockResolvedValueOnce({ ...reponse(), id: 8102 })
    const saisie = saisieComplete()
    saisie.brouillon.sex = 'feminin'
    saisie.brouillon.note = 'Livret de famille.'
    reprise.chargerReprise.mockResolvedValue({
      kind: 'creation_personne',
      message: 'J’ai le livret de famille.',
      origine: { demandeId: 77, statut: 'refusee' },
      saisie,
      aResoudre: [],
      brouillonsAResoudre: [],
      avertissements: [],
    })
    const { racine, deposees, evidences } = await monter(77)
    await attendreLesRessemblances()

    await cliquer(un(racine, boutonNomme('C’est elle : compléter sa fiche')))
    expect(api.lireFichePersonne).toHaveBeenCalledWith(17, expect.anything())
    const completion = un(racine, (n) => 'data-completion' in n.attributs)
    expect(texte(completion!)).toContain('Compléter la fiche de Marie Dupont')
    // Le sexe manquait à la fiche : un complément, coché d'office ; le parent aussi.
    expect(un(completion!, (n) => n.attributs['data-ecart'] === 'sex')?.attributs['data-nature']).toBe('complement')
    expect(un(completion!, (n) => n.attributs['data-lien'] === 'parent:personne:12')?.attributs['data-statut']).toBe('proposable')

    await cliquer(un(racine, boutonNomme('Proposer ces compléments')))

    expect(api.creerDemande).toHaveBeenCalledTimes(2)
    const [modification, liens] = api.creerDemande.mock.calls.map((appel) => appel[0] as Record<string, unknown>)
    expect(modification).toMatchObject({
      kind: 'modification_personne',
      soumettre: true,
      targetId: 17,
      message: 'Livret de famille.\n\nJ’ai le livret de famille.',
      ops: [{ field: 'sex', oldValue: 'inconnu', newValue: 'feminin', baseVersion: 5 }],
    })
    expect(liens).toMatchObject({
      kind: 'ajout_lien',
      targetId: 17,
      liens: [{ kind: 'enfant_de', subjectPersonId: 17, otherPersonId: 12 }],
    })
    expect(api.invaliderGraphe).toHaveBeenCalled()
    expect(deposees).toEqual([
      { nature: 'fiche', demandeIds: [8101, 8102], noeudId: null, personId: 17, libelle: 'Marie Dupont', soumise: true },
    ])
    expect(routeur.push).not.toHaveBeenCalled()
    expect(routeur.useRouter).not.toHaveBeenCalled()
    // La saisie a servi : le panneau s'efface, et l'arbre ne cercle plus rien.
    expect(un(racine, (n) => 'data-ressemblances' in n.attributs)).toBeNull()
    expect(evidences.at(-1)).toEqual({ personIds: [], noeudIds: [] })
  })

  it('« C’est elle » : une 409 fait relire la fiche — explicitement, et on repart de la version relue', async () => {
    api.chercherRessemblancesArbre.mockResolvedValue({ elements: [MARIE_DANS_L_ARBRE], tronque: false })
    api.lireFichePersonne.mockResolvedValueOnce(ficheDeMarie()).mockResolvedValueOnce({ ...ficheDeMarie(), version: 6 })
    api.creerDemande
      .mockRejectedValueOnce(new ErreurApi(409, 'conflit', 'La fiche a changé.', null, {}))
      .mockResolvedValueOnce({ ...reponse(), id: 8103 })
    const saisie = saisieComplete()
    saisie.brouillon.sex = 'feminin'
    saisie.filiation.parents = []
    reprise.chargerReprise.mockResolvedValue({
      kind: 'creation_personne',
      message: '',
      origine: { demandeId: 77, statut: 'refusee' },
      saisie,
      aResoudre: [],
      brouillonsAResoudre: [],
      avertissements: [],
    })
    const { racine } = await monter(77)
    await attendreLesRessemblances()
    await cliquer(un(racine, boutonNomme('C’est elle : compléter sa fiche')))
    await cliquer(un(racine, boutonNomme('Proposer ces compléments')))

    expect(texte(racine)).toContain('La fiche a changé depuis que vous l’avez ouverte')
    // Rien n'a été relu en douce.
    expect(api.lireFichePersonne).toHaveBeenCalledTimes(1)

    await cliquer(un(racine, boutonNomme('Relire la fiche')))
    expect(api.lireFichePersonne).toHaveBeenCalledTimes(2)
    expect(texte(racine)).not.toContain('La fiche a changé depuis que vous l’avez ouverte')

    await cliquer(un(racine, boutonNomme('Proposer ces compléments')))
    expect(api.creerDemande).toHaveBeenCalledTimes(2)
    expect(api.creerDemande.mock.calls[1]?.[0]).toMatchObject({ ops: [{ field: 'sex', baseVersion: 6 }] })
  })

  it('« C’est elle » : un second clic ne redépose pas ce qui est parti', async () => {
    api.chercherRessemblancesArbre.mockResolvedValue({ elements: [MARIE_DANS_L_ARBRE], tronque: false })
    api.lireFichePersonne.mockResolvedValue(ficheDeMarie())
    api.creerDemande
      .mockResolvedValueOnce({ ...reponse(), id: 8101 })
      .mockRejectedValueOnce(new ErreurApi(500, 'erreur_interne', 'Le serveur a trébuché.', null, null))
      .mockResolvedValueOnce({ ...reponse(), id: 8102 })
    const saisie = saisieComplete()
    saisie.brouillon.sex = 'feminin'
    reprise.chargerReprise.mockResolvedValue({
      kind: 'creation_personne',
      message: '',
      origine: { demandeId: 77, statut: 'refusee' },
      saisie,
      aResoudre: [],
      brouillonsAResoudre: [],
      avertissements: [],
    })
    const { racine, deposees } = await monter(77)
    await attendreLesRessemblances()
    await cliquer(un(racine, boutonNomme('C’est elle : compléter sa fiche')))
    await cliquer(un(racine, boutonNomme('Proposer ces compléments')))

    expect(texte(racine)).toContain('Le serveur a trébuché.')
    expect(deposees).toEqual([])

    await cliquer(un(racine, boutonNomme('Réessayer l’envoi')))

    expect(api.creerDemande.mock.calls.map((appel) => (appel[0] as { kind: string }).kind)).toEqual([
      'modification_personne',
      'ajout_lien',
      'ajout_lien',
    ])
    expect(deposees.map((d) => d.demandeIds)).toEqual([[8101, 8102]])
  })

  it('une ressemblance forte retient le premier envoi, une fois ; un brouillon passe', async () => {
    api.chercherRessemblancesArbre.mockResolvedValue({
      elements: [{ ...MARIE_DANS_L_ARBRE, criteres: ['nom', 'prenom', 'naissance'], score: 3 }],
      tronque: false,
    })
    api.creerDemande.mockResolvedValue(reponse())
    const { racine, deposees } = await monter(77)
    await attendreLesRessemblances()

    await soumettre(racine)
    expect(api.creerDemande).not.toHaveBeenCalled()
    expect(texte(racine)).toContain('Vérifiez d’abord ces personnes.')

    await cliquer(un(racine, boutonNomme('Envoyer quand même')))
    expect(api.creerDemande).toHaveBeenCalledTimes(1)
    expect(deposees.map((d) => d.nature)).toEqual(['creation'])
  })

  it('« Enregistrer sans envoyer » n’est pas freiné', async () => {
    api.chercherRessemblancesArbre.mockResolvedValue({
      elements: [{ ...MARIE_DANS_L_ARBRE, criteres: ['nom', 'prenom', 'naissance'], score: 3 }],
      tronque: false,
    })
    api.creerDemande.mockResolvedValue({ ...reponse(), status: 'brouillon', submittedAt: null })
    const { racine } = await monter(77)
    await attendreLesRessemblances()

    await cliquer(un(racine, boutonNomme('Enregistrer sans envoyer')))

    expect(api.creerDemande).toHaveBeenCalledTimes(1)
    expect(api.creerDemande.mock.calls[0]?.[0]).toMatchObject({ soumettre: false })
    expect(texte(racine)).not.toContain('Vérifiez d’abord ces personnes.')
  })

  it('une ressemblance faible ne retient rien', async () => {
    api.chercherRessemblancesArbre.mockResolvedValue({ elements: [MARIE_DANS_L_ARBRE], tronque: false })
    api.creerDemande.mockResolvedValue(reponse())
    const { racine } = await monter(77)
    await attendreLesRessemblances()

    await soumettre(racine)
    expect(api.creerDemande).toHaveBeenCalledTimes(1)
  })
})
