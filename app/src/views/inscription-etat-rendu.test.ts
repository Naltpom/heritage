import { createSSRApp, h, shallowRef } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import { ErreurApi, type EtatInscription } from '@/lib/api'
import InscriptionEtatView from './InscriptionEtatView.vue'

/**
 * **La page d'attente** — l'écran d'un compte inscrit mais pas encore validé,
 * rendu côté serveur.
 *
 * Ce que ces cas tiennent, depuis la révision du 01/10/2026 de l'ADR 0003 (le
 * lien d'invitation circule dans un groupe de messagerie ; un faux inscrit
 * lisait les noms des vivants) :
 *
 * 1. **Le message** : « Votre inscription est en cours de vérification. Vous
 *    serez prévenu dès qu'elle est validée. »
 * 2. **Rien de l'arbre** : ni lien vers l'arbre, l'accueil, une fiche ou
 *    Explorer, ni les deux nombres (« 256 fiches de personnes décédées sur
 *    274 ») que l'écran annonçait — l'API ne les sert plus, et l'on vérifie
 *    quand même qu'ils ne s'affichent pas.
 * 3. **Ce qui reste à lui** : son compte.
 * 4. **Validé entre-temps** : la session du navigateur est relue sans
 *    attendre de clic, et le bouton mène à l'accueil.
 */

const espions = vi.hoisted(() => ({ rafraichir: vi.fn(() => Promise.resolve()) }))

vi.mock('@/composables/useSession', async () => {
  const { computed } = await import('vue')
  return {
    rafraichirSession: espions.rafraichir,
    useSession: () => ({ rattache: computed(() => false) }),
  }
})

const etatServi: { valeur: EtatInscription | null; erreur: unknown } = { valeur: null, erreur: null }

vi.mock('@/composables/useAppelApi', () => ({
  useAppelApi: () => ({
    donnees: shallowRef(etatServi.valeur),
    erreur: shallowRef(etatServi.erreur),
    chargement: shallowRef(false),
    executer: () => Promise.resolve(etatServi.valeur),
  }),
}))

/** Le lien rend sa destination : c'est elle qu'on vérifie, pas le libellé. */
const RouterLinkBouchon = {
  props: { to: { type: [String, Object], required: false, default: '' }, custom: Boolean },
  template:
    '<a href="#" :data-vers="typeof to === \'object\' ? to.name : to"><slot :navigate="() => {}" /></a>',
}

const EN_ATTENTE: EtatInscription = {
  status: 'en_attente',
  depuis: '2026-10-01T09:12:00+02:00',
  matchKind: 'recherche_confirmee',
  regime: 'aucun',
  portraitEnAttente: true,
}

async function rendre(etat: EtatInscription | null, erreur: unknown = null): Promise<string> {
  etatServi.valeur = etat
  etatServi.erreur = erreur
  const app = createSSRApp({ render: () => h(InscriptionEtatView) })
  app.component('RouterLink', RouterLinkBouchon)
  return renderToString(app)
}

const destinations = (html: string): string[] =>
  [...html.matchAll(/data-vers="([^"]+)"/g)].map((m) => m[1] ?? '')

describe('la page d’attente', () => {
  it('dit le message, en tête : vérification en cours, prévenu à la validation', async () => {
    const html = await rendre(EN_ATTENTE)

    expect(html).toContain('Votre inscription est en cours de vérification.')
    expect(html).toContain('Vous serez prévenu dès')
    expect(html).toContain('qu’elle est validée.')
    expect(html).toMatch(/class="message-attente[^"]*"[^>]*role="status"/)
  })

  it('ne montre rien de l’arbre : aucun lien vers lui, aucun nombre', async () => {
    for (const matchKind of ['recherche_confirmee', 'a_rattacher'] as const) {
      const html = await rendre({ ...EN_ATTENTE, matchKind })

      for (const nom of ['arbre', 'accueil', 'personne.detail', 'explorer', 'chronologie', 'carte', 'anniversaires']) {
        expect(destinations(html), `${matchKind} → ${nom}`).not.toContain(nom)
      }
      expect(html).not.toContain('256')
      expect(html).not.toContain('274')
      expect(html).not.toContain('Parcourir l’arbre')
      expect(html).not.toContain('décédées')
    }
  })

  it('laisse à portée ce qui est à lui : son compte', async () => {
    expect(destinations(await rendre(EN_ATTENTE))).toEqual(['compte'])
  })

  it('validé entre-temps : relit la session et mène à l’accueil', async () => {
    espions.rafraichir.mockClear()
    const html = await rendre({ status: 'actif', depuis: '2026-10-01T09:12:00+02:00', matchKind: 'recherche_confirmee', regime: 'cercle' } as EtatInscription)

    expect(html).toContain('Votre compte est actif')
    expect(destinations(html)).toEqual(['accueil'])
    // Le `watch` ne tourne pas au rendu serveur — c'est le bouton qui porte la
    // relecture ici. On vérifie au moins qu'aucun appel n'est parti d'un
    // compte encore en attente.
    espions.rafraichir.mockClear()
    await rendre(EN_ATTENTE)
    expect(espions.rafraichir).not.toHaveBeenCalled()
  })

  /**
   * Un compte actif **sans membre** (ancien administrateur rétrogradé, compte
   * créé en ligne de commande) : la session le range en attente, la garde
   * l'envoie ici, et `GET /api/inscription/etat` répond `404`. Pas une erreur
   * générique avec « Réessayer » — une impasse —, mais ce qu'il faut faire.
   */
  it('compte sans fiche (404) : dit de contacter un administrateur, sans rien de l’arbre', async () => {
    const html = await rendre(null, new ErreurApi(404, 'introuvable', 'Ressource introuvable.'))

    expect(html).toContain('Compte sans fiche : contactez une personne qui administre l’arbre.')
    expect(html).not.toContain('Réessayer')
    expect(destinations(html)).toEqual(['compte'])
  })

  it('une autre erreur reste l’erreur générique', async () => {
    const html = await rendre(null, new ErreurApi(500, 'erreur_interne', 'Erreur.'))

    expect(html).not.toContain('Compte sans fiche')
  })
})
