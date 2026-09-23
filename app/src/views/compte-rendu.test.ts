import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import CompteView from './CompteView.vue'
import CompteCourrielView from './CompteCourrielView.vue'

/**
 * La page « Mon compte », rendue côté serveur.
 *
 * Trois choses qu'un rendu vert ne dit pas tout seul, et que ces cas tiennent :
 *
 * 1. **Le nom et le prénom ne sont pas modifiables ici.** La page n'a aucun champ
 *    pour eux — pas même désactivé. Ils viennent de la fiche rattachée, et un
 *    compte en attente ne les voit même pas (§ 2.2 : `personLabel` est absent
 *    avant activation, pas vide).
 * 2. **Aucun mot de passe ne traverse le rendu.** Les trois champs de mot de
 *    passe sont vides au montage, et rien n'est jamais écrit dans un attribut
 *    `value` non vide.
 * 3. **La confirmation ne rend rien de définitif au chargement.** `onMounted` ne
 *    tourne pas en SSR : on voit « en cours », jamais un succès ou un refus
 *    inventé avant l'appel.
 */

type Membre =
  | { status: 'en_attente' }
  | { status: 'actif'; personId: number; personLabel: string }

const etat: { membre: Membre; regime: 'membre' | 'en_attente' } = {
  membre: { status: 'actif', personId: 42, personLabel: 'Cobalt Romarin' },
  regime: 'membre',
}

vi.mock('@/composables/useSession', async () => {
  const { computed: c, shallowRef: sr } = await import('vue')
  return {
    connecter: () => Promise.resolve(),
    useSession: () => {
      const session = sr({
        utilisateur: { email: 'cobalt@exemple.test' },
        membre: etat.membre,
        cercle: { regime: etat.regime === 'membre' ? 'cercle' : 'aucun' },
      })
      return {
        session,
        regime: c(() => etat.regime),
        connecte: c(() => true),
        rattache: c(() => etat.regime === 'membre'),
        administrateur: c(() => false),
        vueEmpruntee: c(() => null),
        nomAffiche: c(() => 'Cobalt Romarin'),
        chargement: c(() => false),
      }
    },
  }
})

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: () => Promise.resolve() }),
}))

const RouterLinkBouchon = {
  props: { to: { type: [String, Object], required: false, default: '' } },
  template: '<a href="#"><slot /></a>',
}

async function rendreLeCompte(): Promise<string> {
  const app = createSSRApp({ render: () => h(CompteView) })
  app.component('RouterLink', RouterLinkBouchon)
  return renderToString(app)
}

describe('CompteView', () => {
  it('montre les trois blocs, et le nom de la fiche pour un compte rattaché', async () => {
    etat.membre = { status: 'actif', personId: 42, personLabel: 'Cobalt Romarin' }
    etat.regime = 'membre'
    const html = await rendreLeCompte()

    expect(html).toContain('Mon compte')
    expect(html).toContain('Identité')
    expect(html).toContain('Changer de courriel')
    expect(html).toContain('Changer de mot de passe')
    expect(html).toContain('Cobalt Romarin')
    expect(html).toContain('cobalt@exemple.test')
    expect(html).toContain('Proposer une modification de ma fiche')
  })

  it("n'offre aucun champ pour le nom ou le prénom", async () => {
    etat.membre = { status: 'actif', personId: 42, personLabel: 'Cobalt Romarin' }
    etat.regime = 'membre'
    const html = await rendreLeCompte()

    // Les seuls champs sont ceux des deux formulaires : une adresse et quatre
    // mots de passe. La photo s'importe ou se prend dans la fenêtre « Ma photo »,
    // dont le contenu n'est rendu qu'une fois ouverte. Un champ « nom » ou
    // « prénom », même désactivé, laisserait croire qu'un droit manque plutôt
    // qu'un circuit existe.
    const champs = html.match(/<input[^>]*>/g) ?? []
    expect(champs).toHaveLength(5)
    expect(champs.filter((c) => c.includes('type="file"'))).toHaveLength(0)
    expect(html).toContain('Ajouter ma photo')
    expect(champs.filter((c) => c.includes('type="password"'))).toHaveLength(4)
    expect(champs.filter((c) => c.includes('type="email"'))).toHaveLength(1)
  })

  it("ne rend aucun mot de passe dans un attribut value", async () => {
    const html = await rendreLeCompte()
    const champs = html.match(/<input[^>]*type="password"[^>]*>/g) ?? []
    expect(champs.length).toBeGreaterThan(0)
    for (const champ of champs) {
      expect(champ).not.toMatch(/value="[^"]+"/)
    }
  })

  it("dit l'attente et cache le lien de proposition pour un compte non validé", async () => {
    etat.membre = { status: 'en_attente' }
    etat.regime = 'en_attente'
    const html = await rendreLeCompte()

    expect(html).toContain('Rattachement en attente de validation')
    expect(html).not.toContain('Proposer une modification de ma fiche')
    expect(html).not.toContain('Cobalt Romarin')
  })
})

describe('CompteCourrielView', () => {
  it("ne conclut rien avant l'appel : le rendu serveur dit « en cours »", async () => {
    const app = createSSRApp({ render: () => h(CompteCourrielView, { jeton: 'a'.repeat(43) }) })
    app.component('RouterLink', RouterLinkBouchon)
    const html = await renderToString(app)

    expect(html).toContain('Confirmation en cours')
    expect(html).not.toContain('confirmée')
    expect(html).not.toContain("n'est plus valable")
    // Le jeton ne réapparaît nulle part dans la page.
    expect(html).not.toContain('a'.repeat(43))
  })
})
