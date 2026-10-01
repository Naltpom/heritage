import { createSSRApp, defineComponent, h, type PropType } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import type { EchangeAssistant } from '@/lib/api'

/**
 * « Poser une question », rendu côté serveur.
 *
 * 1. **L'écran de l'assistant a deux modes**, et `?mode=question` ouvre le
 *    second : la question, les exemples, le quota, la mention de Gemini — et
 *    rien de la proposition.
 * 2. **Une réponse cite ses fiches par des liens** vers `/personnes/{id}`, et
 *    son texte est du texte : une balise du modèle ne devient pas du HTML.
 * 3. **Une personne restreinte n'a que son nom**, jamais ses dates.
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

/** Un `RouterLink` qui écrit l'adresse d'une fiche, pour que le test la lise. */
const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object] as PropType<string | Record<string, unknown>>, default: '' } },
  setup: (props, { slots }) => () => {
    const cible = props.to
    const params = typeof cible === 'object' ? (cible['params'] as { id?: number } | undefined) : undefined
    const href = params?.id !== undefined ? `/personnes/${params.id}` : '#'
    return h('a', { href }, slots['default']?.())
  },
})

async function rendre(composant: unknown, props: Record<string, unknown> = {}): Promise<string> {
  const app = createSSRApp({ render: () => h(composant as never, props) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

describe('l’écran de l’assistant : « Poser une question »', () => {
  beforeAll(async () => {
    await import('@/views/PropositionCreationView.vue')
  }, 30_000)

  async function rendreLaVue(requete: Record<string, string>): Promise<string> {
    etat.requete = requete
    const { default: Vue } = await import('@/views/PropositionCreationView.vue')
    return rendre(Vue)
  }

  it('propose les deux modes, et ?mode=question ouvre la conversation', async () => {
    etat.vueEmpruntee = null
    const html = await rendreLaVue({ mode: 'question' })

    expect(html).toContain('Proposer')
    expect(html).toContain('Poser une question')
    expect(html).toMatch(/aria-current="page"[^>]*>\s*Poser une question/)
    expect(html).toContain('Que voulez-vous savoir ?')
    expect(html).toContain('Qui est né à Étaples ?')
    expect(html).toContain('Mes conversations')
    expect(html).toContain('Nouvelle conversation')
    expect(html).toContain('<textarea')
    expect(html).toContain('maxlength="1000"')
    expect(html).toContain('Envoyer')
    expect(html).toContain('Google Gemini')
    expect(html).toContain('ne modifie rien')
    // Rien de la proposition : ni documents, ni « Analyser ».
    expect(html).not.toContain('Prendre en photo')
    expect(html).not.toContain('Analyser')
  })

  it('reste sur la proposition par défaut, l’onglet « Poser une question » à portée', async () => {
    etat.vueEmpruntee = null
    const html = await rendreLaVue({})

    expect(html).toContain('Proposer avec l’assistant')
    expect(html).toContain('Analyser')
    expect(html).toContain('Poser une question')
    expect(html).not.toContain('Que voulez-vous savoir ?')
  })

  it('disparaît sous la bascule « voir comme un membre »', async () => {
    etat.vueEmpruntee = { memberLabel: 'Opale Romarin' }
    const html = await rendreLaVue({ mode: 'question' })

    expect(html).not.toContain('Que voulez-vous savoir ?')
    expect(html).not.toContain('Poser une question')
    etat.vueEmpruntee = null
  })
})

describe('un échange', () => {
  const ECHANGE: EchangeAssistant = {
    id: 1,
    question: 'Qui est né à Brest ?',
    reponse:
      'D’après l’arbre :\n\n- [Marne Sureau](personne:17), en 1887, d’après son acte de naissance\n- [Personne vivante](personne:18)\n\n<script>alert(1)</script>',
    personnes: [
      { personId: 17, displayName: 'Marne Sureau', ligneDates: '1887 – 1950', visibilite: 'complete' },
      { personId: 18, displayName: 'Personne vivante', ligneDates: '1990 –', visibilite: 'restreinte' },
    ],
    createdAt: '2026-10-01T14:05:00+02:00',
  }

  async function rendreLEchange(): Promise<string> {
    const { default: Echange } = await import('./EchangeQuestion.vue')
    return rendre(Echange, { echange: ECHANGE })
  }

  it('fait des fiches citées des liens vers /personnes/{id}', async () => {
    const html = await rendreLEchange()

    expect(html).toContain('Qui est né à Brest ?')
    expect(html).toMatch(/<a href="\/personnes\/17"[^>]*>Marne Sureau<\/a>/)
    expect(html).toContain('d’après son acte de naissance')
    expect(html).toContain('Fiches citées')
    expect(html).toContain('Marne Sureau (1887 – 1950)')
  })

  it('ne montre que le nom d’une personne restreinte', async () => {
    const html = await rendreLEchange()

    expect(html).toMatch(/<a href="\/personnes\/18"[^>]*>\s*Personne vivante\s*<\/a>/)
    expect(html).not.toContain('1990')
  })

  it('rend le texte du modèle comme du texte, jamais comme du HTML', async () => {
    const html = await rendreLEchange()

    expect(html).not.toContain('<script>')
    expect(html).toContain('&lt;script&gt;')
  })
})
