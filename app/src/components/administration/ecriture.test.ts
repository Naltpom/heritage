import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import type { DemandeAdministration } from '@/lib/api'
import { ecrituresSuspendues, infobulleSuspension, motifSuspension } from './ecriture'
// Importés **statiquement**, et pas à la demande dans chaque test : c'est ce
// qui fait tourner la fabrique de `vi.mock` pendant le chargement du module,
// donc avant le premier test — les `shallowRef` existent alors déjà. Cela sort
// aussi le coût de transformation des deux composants du budget de temps d'un
// test, où il provoquait des expirations qui n'avaient rien à voir avec ce qui
// est vérifié ici.
import DecisionDemande from './DecisionDemande.vue'
import BandeauRegime from '@/components/mise-en-page/BandeauRegime.vue'

/**
 * Ce que ces tests protègent : **la bascule « voir comme un membre » se voit, et
 * elle bloque les écritures.**
 *
 * Les deux moitiés sont indissociables. Le serveur rend
 * `409 vue_comme_membre_active` sur **toute** route d'écriture tant que la
 * bascule est active (§ 10.4) : si le front n'éteint pas ses boutons, chaque
 * clic revient en erreur et ressemble à une panne. Et si le bandeau n'est pas
 * permanent, l'administrateur « conclura à un bug en ne voyant plus la moitié
 * des fiches » — c'est écrit en toutes lettres dans le contrat.
 *
 * Le motif est plus sérieux qu'un confort d'interface : on ne valide pas une
 * demande sous une identité empruntée. La `prp_revision` porterait
 * l'administrateur alors que l'écran montrait le cercle de quelqu'un d'autre, et
 * le journal deviendrait ininterprétable.
 */

/**
 * La session, pilotable depuis les tests.
 *
 * Les deux entrées sont de **vrais `shallowRef`**, et non des objets `{value}`.
 * Vue ne déballe dans un template que ce que `isRef()` reconnaît : un faux ref
 * reste un objet, donc toujours truthy, et `vueEmpruntee !== null` serait
 * éternellement vrai. Le bandeau s'afficherait alors dans tous les cas — et le
 * test passerait pour de mauvaises raisons, ce qui est pire que de le voir
 * échouer.
 */
const etat = vi.hoisted(() => ({
  vueEmpruntee: null as unknown as { value: { memberId: number; memberLabel: string; depuis: string } | null },
  regime: null as unknown as { value: string },
  incarnation: null as unknown as { value: { par: { id: number; displayName: string } } | null },
  nomAffiche: null as unknown as { value: string },
}))

vi.mock('@/composables/useSession', async () => {
  const { shallowRef } = await import('vue')
  etat.vueEmpruntee = shallowRef(null)
  etat.regime = shallowRef('administrateur')
  etat.incarnation = shallowRef(null)
  etat.nomAffiche = shallowRef('Cobalt Romarin')
  return {
    useSession: () => ({
      vueEmpruntee: etat.vueEmpruntee,
      regime: etat.regime,
      incarnation: etat.incarnation,
      nomAffiche: etat.nomAffiche,
    }),
  }
})

const VUE_EMPRUNTEE = {
  memberId: 7,
  memberLabel: 'Cobalt Romarin',
  depuis: '2026-09-15T21:12:00+02:00',
}

/** Les balises ouvrantes des boutons rendus. */
function balisesDeBouton(html: string): string[] {
  return html.match(/<button[^>]*>/g) ?? []
}

/**
 * Le bouton porte-t-il l'**attribut** `disabled` ?
 *
 * Un simple `includes('disabled')` serait toujours vrai : la classe de
 * `BoutonBase` contient `disabled:cursor-not-allowed` et `disabled:opacity-55`,
 * des variantes Tailwind. Le test « les boutons sont éteints » passerait alors
 * quoi qu'il arrive — y compris sur des boutons parfaitement actifs. On exige
 * donc l'attribut, reconnaissable au caractère qui le suit : `disabled:` n'est
 * jamais l'attribut, `disabled>` ou `disabled ` le sont.
 */
function estDesactive(balise: string): boolean {
  return /\sdisabled(?:[\s=>]|$)/.test(balise)
}

const DEMANDE: DemandeAdministration = {
  id: 14,
  kind: 'modification_personne',
  status: 'en_attente',
  authorLabel: 'Opale Romarin',
  targetKind: 'personne',
  targetId: 17,
  targetLabel: 'Marne Dolomite Sureau',
  message: "l'acte de Saule donne la fourchette",
  decisionMessage: null,
  autoValidated: false,
  duplicateOfId: null,
  submittedAt: '2026-09-14T11:02:00+02:00',
  createdAt: '2026-09-14T10:58:00+02:00',
  ops: [],
  brouillons: [],
  liens: [],
  revisions: [],
  resolutionPrevue: 'application',
}

/* ------------------------------------------------------------------ */
/* Les fonctions pures                                                 */
/* ------------------------------------------------------------------ */

describe('quand les écritures sont suspendues', () => {
  it('la bascule inactive ne suspend rien', () => {
    expect(ecrituresSuspendues(null)).toBe(false)
    expect(motifSuspension(null)).toBeNull()
    expect(infobulleSuspension(null)).toBeUndefined()
  })

  it('la bascule active suspend tout', () => {
    expect(ecrituresSuspendues(VUE_EMPRUNTEE)).toBe(true)
  })

  it('le motif nomme le membre emprunté — c’est lui qui fait le déclic', () => {
    const motif = motifSuspension(VUE_EMPRUNTEE) ?? ''

    expect(motif).toContain('Cobalt Romarin')
    // Et il dit quoi faire, pas seulement ce qui est interdit.
    expect(motif).toMatch(/quittez/i)
  })
})

/* ------------------------------------------------------------------ */
/* L'écran de décision                                                 */
/* ------------------------------------------------------------------ */

describe('l’écran de décision sous une vue empruntée', () => {
  function rendreDecision(vue: typeof VUE_EMPRUNTEE | null): Promise<string> {
    etat.vueEmpruntee.value = vue
    return renderToString(
      createSSRApp({ render: () => h(DecisionDemande, { demande: DEMANDE }) }),
    )
  }

  it('éteint les boutons d’écriture', async () => {
    const html = await rendreDecision(VUE_EMPRUNTEE)

    // Les deux boutons de décision sont désactivés. Le serveur refuserait de
    // toute façon ; le front ne compte pas dessus, et réciproquement.
    const boutons = balisesDeBouton(html)
    expect(boutons.length).toBeGreaterThan(0)
    for (const bouton of boutons) expect(estDesactive(bouton)).toBe(true)
  })

  it('écrit pourquoi, à côté des boutons éteints', async () => {
    const html = await rendreDecision(VUE_EMPRUNTEE)

    // Un bouton éteint sans explication se lit comme une panne, et
    // l'administrateur cherchera ce qui est cassé avant de se rappeler qu'il a
    // activé la bascule une heure plus tôt.
    expect(html).toContain('Cobalt Romarin')
    expect(html).toMatch(/aucune modification possible/i)
  })

  it('les rallume dès que la vue est quittée', async () => {
    const html = await rendreDecision(null)

    const boutons = balisesDeBouton(html)
    expect(boutons.length).toBeGreaterThan(0)
    expect(boutons.every((bouton) => !estDesactive(bouton))).toBe(true)
    expect(html).not.toMatch(/aucune modification ne peut être enregistrée/i)
  })
})

/* ------------------------------------------------------------------ */
/* Le bandeau permanent du socle                                       */
/* ------------------------------------------------------------------ */

describe('le bandeau permanent', () => {
  /**
   * `BandeauRegime` porte un `<RouterLink>` vers l'écran d'attente, et aucun
   * routeur n'est installé ici. Sans ce bouchon, chaque rendu émet
   * « Failed to resolve component: RouterLink » : les assertions passent, mais
   * la sortie se remplit d'un avertissement qu'on finit par ne plus lire — et un
   * instrument qu'on n'écoute plus ne sert à rien le jour où il a quelque chose
   * à dire.
   *
   * Un `<a>` plutôt qu'un composant vide : le bandeau doit rester lisible dans
   * le HTML rendu, et une assertion sur son texte continuerait de le trouver.
   */
  const RouterLinkBouchon = {
    props: { to: { type: [String, Object], required: false, default: '' } },
    template: '<a href="#"><slot /></a>',
  }

  function rendreBandeau(
    vue: typeof VUE_EMPRUNTEE | null,
    incarnation: { par: { id: number; displayName: string } } | null = null,
  ): Promise<string> {
    etat.vueEmpruntee.value = vue
    etat.incarnation.value = incarnation
    etat.regime.value = incarnation === null ? 'administrateur' : 'membre'
    const app = createSSRApp({ render: () => h(BandeauRegime) })
    // Enregistrement **global**, et non `components:` sur le composant racine :
    // `BandeauRegime` résout `RouterLink` dans sa propre portée, puis retombe
    // sur les composants globaux de l'application — jamais sur les composants
    // locaux de son parent. Le détail vaut la minute qu'il coûte à trouver.
    app.component('RouterLink', RouterLinkBouchon)
    return renderToString(app)
  }

  /**
   * ADR 0003, révision du 01/10/2026 — un compte en attente ne voit plus rien
   * de l'arbre : le bandeau ne doit plus lui promettre « les fiches des
   * personnes décédées ».
   */
  it('dit au compte en attente que l’arbre lui sera ouvert à la validation', async () => {
    etat.vueEmpruntee.value = null
    etat.incarnation.value = null
    etat.regime.value = 'en_attente'
    const app = createSSRApp({ render: () => h(BandeauRegime) })
    app.component('RouterLink', RouterLinkBouchon)
    const html = await renderToString(app)

    expect(html).toContain('Inscription en cours de vérification')
    expect(html).toMatch(/sera ouvert dès qu’elle sera validée/)
    expect(html).not.toMatch(/décédées/)
  })

  it('annonce la vue empruntée et qui est emprunté', async () => {
    const html = await rendreBandeau(VUE_EMPRUNTEE)

    expect(html).toMatch(/vue empruntée/i)
    expect(html).toContain('Cobalt Romarin')
  })

  it('dit que les écritures sont refusées', async () => {
    const html = await rendreBandeau(VUE_EMPRUNTEE)

    expect(html).toMatch(/aucune modification possible/i)
  })

  it('ne s’affiche pas quand la bascule est inactive', async () => {
    const html = await rendreBandeau(null)

    expect(html).not.toMatch(/vue empruntée/i)
    expect(html).not.toMatch(/connecté en tant que/i)
  })

  /**
   * ADR 0010 — sous une incarnation, l'administrateur **agit** au nom du
   * membre : le bandeau dit en tant que qui, et que chaque geste lui reste
   * attribué à lui.
   */
  it('annonce l’incarnation, qui est incarné et qui tient le clavier', async () => {
    const html = await rendreBandeau(null, { par: { id: 1, displayName: 'Natron' } })

    expect(html).toMatch(/connecté en tant que/i)
    expect(html).toContain('Cobalt Romarin')
    expect(html).toContain('Natron')
    expect(html).toMatch(/enregistrée à votre nom/i)
  })
})
