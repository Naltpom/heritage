import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import type { DemandeProposee, PortraitPropose } from '@/lib/api'

/**
 * « Proposer » et son assistant, rendus côté serveur.
 *
 * Trois choses que la logique seule ne prouve pas :
 *
 * 1. **L'assistant est la porte d'entrée, sans bascule visible** — le
 *    formulaire n'est plus servi que par `?mode=formulaire`, une reprise ou la
 *    vue empruntée, et il y est intact.
 * 2. **Le mode assistant rend la saisie** : le champ de texte, les deux boutons
 *    de documents (dont l'appareil photo arrière) et « Analyser » — et rien de
 *    la relecture tant qu'aucune analyse n'est revenue.
 * 3. **Une personne restreinte n'a que son nom** sur une carte de relecture,
 *    même quand la réponse porterait ses dates.
 */

const etat: {
  requete: Record<string, string>
  vueEmpruntee: { memberLabel: string } | null
} = { requete: {}, vueEmpruntee: null }

vi.mock('@/composables/useSession', async () => {
  const { computed: c } = await import('vue')
  return {
    useSession: () => ({
      rattache: c(() => true),
      connecte: c(() => true),
      administrateur: c(() => false),
      vueEmpruntee: c(() => etat.vueEmpruntee),
    }),
  }
})

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: etat.requete, params: {} }),
  useRouter: () => ({ push: () => Promise.resolve(), replace: () => Promise.resolve() }),
}))

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

async function rendre(composant: unknown, props: Record<string, unknown> = {}): Promise<string> {
  const app = createSSRApp({ render: () => h(composant as never, props) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

async function rendreLaVue(requete: Record<string, string>): Promise<string> {
  etat.requete = requete
  const { default: Vue } = await import('@/views/PropositionCreationView.vue')
  return rendre(Vue)
}

describe('« Proposer » : l’assistant d’abord, le formulaire caché', () => {
  // Le premier import de la vue charge tout le formulaire et l'assistant : à froid,
  // sous la charge de la suite complète, il dépasse seul les 5 s d'un test.
  beforeAll(async () => {
    await import('@/views/PropositionCreationView.vue')
  }, 30_000)

  it('ouvre sur l’assistant, sans bascule vers le formulaire', async () => {
    etat.vueEmpruntee = null
    const html = await rendreLaVue({ personne: '17' })

    expect(html).toContain('<textarea')
    expect(html).toContain('Importer')
    expect(html).toContain('Prendre en photo')
    expect(html).toContain('capture="environment"')
    expect(html).toContain('accept="image/*,application/pdf,audio/*"')
    expect(html).toContain('Analyser')
    expect(html).toContain('20 analyses par jour')
    expect(html).not.toContain('Envoyer la proposition')
    expect(html).not.toContain('aria-pressed="true"')
  })

  it('sert encore le formulaire, intact, avec ?mode=formulaire', async () => {
    etat.vueEmpruntee = null
    const html = await rendreLaVue({ mode: 'formulaire' })

    expect(html).toContain('Proposer l’ajout d’une personne')
    expect(html).toContain('Qui est cette personne')
    expect(html).toContain('Naissance et décès')
    expect(html).toContain('Envoyer la proposition')
    expect(html).toContain('Enregistrer sans envoyer')
    expect(html).not.toContain('Analyser')
  })

  it('disparaît sous la bascule « voir comme un membre » : formulaire seul', async () => {
    etat.vueEmpruntee = { memberLabel: 'Opale Romarin' }
    const html = await rendreLaVue({ mode: 'assistant' })

    expect(html).not.toContain('Assistant IA')
    expect(html).not.toContain('Prendre en photo')
    expect(html).toContain('Opale Romarin')
    etat.vueEmpruntee = null
  })

  it('une reprise force le formulaire', async () => {
    const html = await rendreLaVue({ mode: 'assistant', refaire: '8' })
    expect(html).not.toContain('Prendre en photo')
  })
})

describe('la carte de relecture', () => {
  const DEMANDE: DemandeProposee = {
    cle: 'd1',
    titre: 'Corriger la naissance d’Opale Tilleul',
    justification: 'Livret de famille (document 1).',
    confiance: 'moyenne',
    corps: { kind: 'modification_personne', targetKind: 'personne', targetId: 202, ops: [] },
    personnes: [
      { personId: 202, displayName: 'Opale Tilleul', ligneDates: '1994 –', visibilite: 'restreinte' },
      { personId: 12, displayName: 'Jean Dupont', ligneDates: '1890 – 1960', visibilite: 'complete' },
    ],
    changements: [{ libelle: 'Date de naissance', avant: null, apres: '3 mars 1994' }],
  }

  it('ne montre que le nom d’une personne restreinte, et les dates des autres', async () => {
    const { default: Carte } = await import('./CarteDemandeProposee.vue')
    const html = await rendre(Carte, { demande: DEMANDE, cochee: true })

    expect(html).toContain('Opale Tilleul')
    expect(html).not.toContain('1994 –')
    expect(html).toContain('Jean Dupont')
    expect(html).toContain('1890 – 1960')
  })

  it('est cochée par défaut, dit la confiance, et masque la valeur illisible', async () => {
    const { default: Carte } = await import('./CarteDemandeProposee.vue')
    const html = await rendre(Carte, { demande: DEMANDE, cochee: true })

    expect(html).toMatch(/<input[^>]*type="checkbox"[^>]*checked/)
    expect(html).toContain('Confiance moyenne')
    expect(html).toContain('(valeur masquée)')
    expect(html).toContain('3 mars 1994')
  })
})

describe('la section « Portraits repérés »', () => {
  const CREATION: DemandeProposee = {
    cle: 'd1',
    titre: 'Ajouter Marie Dupont',
    justification: 'Acte de naissance (document 1).',
    confiance: 'haute',
    corps: {
      kind: 'creation_personne',
      brouillons: [
        {
          localRef: '#p1',
          givenNames: 'Marie',
          surname: 'Dupont',
          marriedName: null,
          nickname: null,
          sex: 'feminin',
          deceased: true,
          birth: null,
          death: null,
          note: null,
        },
      ],
      liens: [],
    },
    personnes: [],
    changements: [],
  }

  const PORTRAITS: PortraitPropose[] = [
    {
      cle: 'r1',
      document: 0,
      cadre: { haut: 120, gauche: 340, bas: 520, droite: 660 },
      justification: 'Photo de groupe (document 1) : visage d’Opale.',
      personId: 202,
      personne: { personId: 202, displayName: 'Opale Tilleul', ligneDates: '1994 –', visibilite: 'restreinte' },
      demandeCle: null,
      brouillonRef: null,
    },
    {
      cle: 'r2',
      document: 1,
      cadre: { haut: 100, gauche: 100, bas: 400, droite: 400 },
      justification: 'Photo de mariage (document 2) : la mariée.',
      personId: null,
      personne: null,
      demandeCle: 'd1',
      brouillonRef: 'p1',
    },
  ]

  async function rendreSection(demandesCochees: string[]): Promise<string> {
    const { default: Section } = await import('./PortraitsReperes.vue')
    return rendre(Section, {
      portraits: PORTRAITS,
      demandes: [CREATION],
      cochees: new Set(['r1', 'r2']),
      demandesCochees: new Set(demandesCochees),
      apercus: {
        r1: { etat: 'pret', url: 'blob:groupe', largeur: 2000, hauteur: 1500, cadre: PORTRAITS[0]!.cadre, ajuste: false },
      },
    })
  }

  it('nomme une personne restreinte sans ses dates, et une personne proposée par son brouillon', async () => {
    const html = await rendreSection(['d1'])

    expect(html).toContain('Portraits repérés')
    expect(html).toContain('Opale Tilleul')
    expect(html).not.toContain('1994 –')
    expect(html).toContain('Marie Dupont')
    expect(html).toContain('nouvelle personne proposée')
    expect(html).toContain('Photo de mariage (document 2) : la mariée.')
    expect(html).toContain('Ajuster le cadrage')
    // Cochés par défaut, tous les deux.
    expect(html.match(/<input[^>]*type="checkbox"[^>]*checked/g)).toHaveLength(2)
    // L'aperçu prêt montre l'image d'origine sous le rond ; l'autre attend.
    expect(html).toContain('src="blob:groupe"')
    expect(html).toContain('Aperçu…')
  })

  it('prévient qu’un portrait ne partira pas si la création est décochée', async () => {
    const html = await rendreSection([])
    expect(html).toContain('La demande qui crée cette personne est décochée')
  })
})
