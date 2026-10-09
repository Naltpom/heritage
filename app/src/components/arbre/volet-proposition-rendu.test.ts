import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeAll, describe, expect, it, vi } from 'vitest'

/**
 * Le volet « Ajouter une personne » de `/arbre`, rendu côté serveur.
 *
 * Ce qu'on tient ici, et que la logique seule ne voit pas :
 *
 * 1. **C'est un volet nommé**, l'`aside` que le bouton de la barre désigne
 *    (`aria-controls="volet-proposition"`), marqué pour que les raccourcis de
 *    l'arbre s'y taisent (`data-volet-proposition`), de 440 px, bordé à droite ;
 * 2. **son en-tête** dit ce qu'il est, et que la croix garde la saisie ;
 * 3. **il porte le formulaire de la page, en contexte volet** : les mêmes blocs,
 *    l'identité sur une colonne, et rien de propre à la page (« Revenir à
 *    l'arbre » : on y est).
 */

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

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, params: {} }),
  useRouter: () => ({ push: () => Promise.resolve(), replace: () => Promise.resolve() }),
}))

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

async function rendreLeVolet(): Promise<string> {
  const { default: Volet } = await import('./VoletProposition.vue')
  const app = createSSRApp({ render: () => h(Volet) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

/** La balise ouvrante de l'`aside`, attributs compris. */
function ouvertureDuVolet(html: string): string {
  return /<aside[^>]*>/.exec(html)?.[0] ?? ''
}

describe('le volet « Ajouter une personne »', () => {
  // Le premier import charge tout le formulaire : à froid, sous la charge de la
  // suite complète, il dépasse seul les 5 s d'un test.
  beforeAll(async () => {
    await import('./VoletProposition.vue')
  }, 30_000)

  it('est un `aside` nommé, désigné par le bouton, et tu pour les raccourcis de l’arbre', async () => {
    const aside = ouvertureDuVolet(await rendreLeVolet())

    expect(aside).toContain('id="volet-proposition"')
    expect(aside).toContain('data-volet-proposition')
    expect(aside).toContain('aria-label="Ajouter une personne"')
    const classes = /class="([^"]*)"/.exec(aside)?.[1]?.split(/\s+/) ?? []
    expect(classes).toEqual(expect.arrayContaining(['w-[440px]', 'flex-none', 'border-r', 'border-ligne', 'bg-panneau']))
  })

  it('a son en-tête, et une croix qui garde la saisie', async () => {
    const html = await rendreLeVolet()

    expect(html).toMatch(/<h2[^>]*>Ajouter une personne<\/h2>/)
    expect(html).toContain('title="Fermer — votre saisie est gardée"')
    expect(html).toContain('aria-label="Fermer"')
    // Rien à effacer dans un formulaire vierge, et pas de confirmation sans dépôt.
    expect(html).not.toContain('data-effacer')
    expect(html).not.toContain('data-confirmation-depot')
  })

  it('porte le formulaire de la page, en contexte volet', async () => {
    const html = await rendreLeVolet()

    expect(html).toContain('Qui est cette personne')
    expect(html).toContain('Naissance et décès')
    expect(html).toContain('Photos ou fichiers')
    expect(html).toContain('Envoyer la proposition')
    expect(html).toContain('Enregistrer sans envoyer')
    // Propre à la page : dans le volet, l'arbre est à côté.
    expect(html).not.toContain('Revenir à l’arbre')
    // 440 px : l'identité sur une colonne, sans la largeur minimale qui mettait deux champs côte à côte.
    expect(html).not.toContain('min-w-[13rem]')
  })
})
