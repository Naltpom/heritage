import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import EnTeteApplication from './EnTeteApplication.vue'

/**
 * Ce que ces tests protègent : **« Proposer une personne » est atteignable.**
 *
 * C'est la quatrième demande du commanditaire — « permettre aux gens de proposer
 * de nouvelles personnes à ajouter ». Le formulaire, sa route et ses tests
 * existent ; il ne manquait que le point d'entrée. Une capacité entière tient
 * donc à deux lignes de l'en-tête, et rien n'avertirait si elles disparaissaient
 * : l'écran resterait juste, la route continuerait de répondre, et le geste
 * serait simplement introuvable. D'où ce test.
 *
 * Il vérifie aussi les deux conditions d'affichage, parce qu'elles sont la
 * raison pour laquelle le lien vit dans l'en-tête plutôt que dans la barre
 * d'outils de l'arbre — et parce qu'un lien qui mène à un refus est pire qu'un
 * lien absent :
 *
 * 1. un compte **en attente** ne dépose pas (`403 regime_en_attente`, § 12.2) ;
 * 2. sous la bascule **« voir comme un membre »**, toute écriture rend
 *    `409 vue_comme_membre_active` (§ 10.4).
 *
 * Masquer le lien n'est pas une mesure de sécurité — la garde de route le
 * double, l'API double les deux. Le rôle choisit ici une **action**, jamais un
 * champ de données : c'est ce qui rend le `v-if` légitime.
 */

const etat = vi.hoisted(() => ({
  regime: null as unknown as { value: string },
  vueEmpruntee: null as unknown as { value: { memberId: number; memberLabel: string } | null },
}))

vi.mock('@/composables/useSession', async () => {
  const { computed: c, shallowRef: sr } = await import('vue')
  etat.regime = sr('membre')
  etat.vueEmpruntee = sr(null)
  return {
    deconnecter: () => Promise.resolve(),
    useSession: () => ({
      regime: etat.regime,
      vueEmpruntee: etat.vueEmpruntee,
      connecte: c(() => etat.regime.value !== 'anonyme'),
      rattache: c(() => etat.regime.value === 'membre' || etat.regime.value === 'administrateur'),
      administrateur: c(() => etat.regime.value === 'administrateur'),
      nomAffiche: c(() => 'Cobalt Romarin'),
    }),
  }
})

// `useRouter` n'est appelé que par la déconnexion, mais il lève une injection
// manquante hors d'une application routée. On le bouchonne plutôt que de laisser
// un avertissement s'installer dans la sortie.
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: () => Promise.resolve() }),
}))

const RouterLinkBouchon = {
  props: { to: { type: [String, Object], required: false, default: '' } },
  template: '<a href="#"><slot /></a>',
}

function rendre(regime: string, vue: { memberId: number; memberLabel: string } | null = null) {
  etat.regime.value = regime
  etat.vueEmpruntee.value = vue
  const app = createSSRApp({ render: () => h(EnTeteApplication) })
  // Enregistrement **global** : un composant résout les composants globaux de
  // l'application, jamais les composants locaux de son parent.
  app.component('RouterLink', RouterLinkBouchon)
  return renderToString(app)
}

/** Le libellé par défaut de `PointCreation`. */
const LIBELLE = 'Proposer une personne'

describe('le point d’entrée « proposer une personne »', () => {
  it('est présent pour un membre rattaché', async () => {
    expect(await rendre('membre')).toContain(LIBELLE)
  })

  it('est présent pour un administrateur', async () => {
    expect(await rendre('administrateur')).toContain(LIBELLE)
  })

  it('est absent pour un compte en attente de validation', async () => {
    // Il recevrait `403 regime_en_attente`, et la garde de route l'enverrait
    // vers l'écran d'attente — ce qui, depuis un bouton, ressemble à une panne.
    expect(await rendre('en_attente')).not.toContain(LIBELLE)
  })

  it('est absent sous la bascule « voir comme un membre »', async () => {
    // On ne dépose pas une demande sous une identité empruntée : la révision
    // porterait l'administrateur alors que l'écran montrait quelqu'un d'autre.
    const html = await rendre('administrateur', { memberId: 7, memberLabel: 'Cobalt Romarin' })
    expect(html).not.toContain(LIBELLE)
  })

  it('est absent pour un visiteur anonyme', async () => {
    expect(await rendre('anonyme')).not.toContain(LIBELLE)
  })
})

describe('la navigation suit le régime, pas le rôle', () => {
  it('ouvre l’arbre et la chronologie à un compte en attente', async () => {
    // § 12.1 : la lecture de l'arbre ancien lui est ouverte. C'est la décision
    // du commanditaire, et l'en-tête ne doit pas la contredire.
    const html = await rendre('en_attente')
    expect(html).toContain('Arbre')
    expect(html).toContain('Chronologie')
  })

  it('ouvre « Mes demandes » à un membre rattaché, et pas avant', async () => {
    // Le pendant exact de « Proposer une personne » : une capacité entière —
    // lire le motif de son propre refus — tenait à l'absence de ce lien. Rien
    // n'avertirait s'il disparaissait : l'écran resterait juste, la route
    // continuerait de répondre, et le chemin serait simplement introuvable.
    expect(await rendre('membre')).toContain('Mes demandes')
    expect(await rendre('administrateur')).toContain('Mes demandes')

    // Un compte en attente n'a aucune demande — l'API lui refuse le dépôt —, et
    // la garde de route le renverrait vers l'écran d'attente.
    expect(await rendre('en_attente')).not.toContain('Mes demandes')
    expect(await rendre('anonyme')).not.toContain('Mes demandes')
  })

  it('ne montre « Administration » qu’à un administrateur', async () => {
    expect(await rendre('membre')).not.toContain('Administration')
    expect(await rendre('administrateur')).toContain('Administration')
  })

  it('ne montre aucune entrée de navigation à un anonyme', async () => {
    const html = await rendre('anonyme')
    expect(html).not.toContain('Chronologie')
    // L'assertion porte sur l'**élément**, pas sur le texte : un commentaire de
    // template contenant « Se déconnecter » suffisait à faire passer — ou
    // échouer — un test écrit sur la chaîne. Il n'y a aucun bouton dans l'en-tête
    // d'un visiteur anonyme.
    expect(html).not.toMatch(/<button/)
  })
})

describe('la greffe elle-même', () => {
  it('monte `PointCreation` dans l’en-tête, et non ailleurs', async () => {
    // Lecture du source, et **pas** de repli silencieux si elle échoue : un test
    // qui se tait quand il ne peut plus rien vérifier est pire qu'un test absent
    // — il continue de compter dans le total et de rassurer.
    const source = await import('./EnTeteApplication.vue?raw')
    expect(String(source.default)).toContain('PointCreation')
  })
})
