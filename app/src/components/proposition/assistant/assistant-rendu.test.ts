import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import type { DemandeProposee } from '@/lib/api'

/**
 * « Proposer » et son assistant, rendus côté serveur.
 *
 * Trois choses que la logique seule ne prouve pas :
 *
 * 1. **Le formulaire reste le défaut, et il est intact** — le segmenté se pose
 *    au-dessus, rien ne se glisse dedans. Un membre qui ne clique pas sur
 *    « Assistant IA » ne doit rien voir changer.
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

describe('le segmenté « Formulaire · Assistant IA »', () => {
  it('ouvre sur le formulaire, inchangé', async () => {
    etat.vueEmpruntee = null
    const html = await rendreLaVue({})

    expect(html).toContain('Formulaire')
    expect(html).toContain('Assistant IA')
    expect(html).toMatch(/aria-pressed="true"[^>]*>\s*Formulaire/)
    expect(html).toMatch(/aria-pressed="false"[^>]*>\s*Assistant IA/)

    // Le formulaire, tel qu'il était.
    expect(html).toContain('Proposer l’ajout d’une personne')
    expect(html).toContain('Qui est cette personne')
    expect(html).toContain('Naissance et décès')
    expect(html).toContain('Envoyer la proposition')
    expect(html).toContain('Enregistrer sans envoyer')
    expect(html).not.toContain('Analyser')
  })

  it('passe à l’assistant avec ?mode=assistant', async () => {
    etat.vueEmpruntee = null
    const html = await rendreLaVue({ mode: 'assistant', personne: '17' })

    expect(html).toMatch(/aria-pressed="true"[^>]*>\s*Assistant IA/)
    expect(html).toContain('<textarea')
    expect(html).toContain('Importer')
    expect(html).toContain('Prendre en photo')
    expect(html).toContain('capture="environment"')
    expect(html).toContain('accept="image/*,application/pdf"')
    expect(html).toContain('Analyser')
    expect(html).toContain('20 analyses par jour')
    expect(html).not.toContain('Envoyer la proposition')
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
