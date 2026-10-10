import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import type { ComplementDeDemande, DemandeAdministration, RapportDeFusion } from '@/lib/api'

/**
 * Les compléments d'une proposition, et le bandeau d'un complément — **rendus**
 * (ADR 0017). `complements.test.ts` vérifie ce que la logique décide ; ce fichier
 * vérifie ce que les gabarits impriment :
 *
 * 1. sur l'origine, un complément en attente montre son rapport et sa fusion —
 *    **si l'écran peut l'offrir** : sans le droit, il n'y a qu'une ligne ;
 * 2. un complément tranché reste listé, sans geste ;
 * 3. le bandeau d'un complément nomme l'origine **si la charge la porte**, et ne
 *    dit rien de plus à son auteur, qui ne la voit pas.
 */

vi.mock('@/composables/useSession', async () => {
  const { computed } = await import('vue')
  return { useSession: () => ({ vueEmpruntee: computed(() => null), administrateur: computed(() => true) }) }
})

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (props, { slots }) => () =>
    h('a', { href: typeof props.to === 'string' ? props.to : JSON.stringify(props.to) }, slots['default']?.()),
})

const RAPPORT: RapportDeFusion = {
  remplis: [{ champ: 'nickname', valeur: 'Bébert' }],
  conflits: [{ champ: 'sex', origine: 'masculin', complement: 'feminin', retenu: 'origine' }],
  liensAjoutes: [{ seqComplement: 2, seq: 3 }],
  liensIgnores: [{ seqComplement: 1, raison: 'meme_personne' }],
  mediasDeplaces: 2,
  demandesRepointees: [],
}

const EN_ATTENTE: ComplementDeDemande = {
  id: 21,
  authorLabel: 'Tuf Romarin',
  submittedAt: '2026-10-10T09:12:00+02:00',
  status: 'en_attente',
  draftPersonId: 31,
  origineDraftPersonId: 7,
  displayName: 'Barnabé Muguet',
  ligneDates: '1901 – 1968',
  apercu: RAPPORT,
  empreinte: '41ab',
}

const CLOS: ComplementDeDemande = { ...EN_ATTENTE, id: 24, authorLabel: 'Ambre Romarin', status: 'refusee', apercu: null }

function origine(complements: ComplementDeDemande[]): DemandeAdministration {
  return {
    id: 12,
    kind: 'creation_personne',
    status: 'en_attente',
    authorUserId: 3,
    authorLabel: 'Cobalt Romarin',
    targetKind: null,
    targetId: null,
    targetLabel: null,
    message: null,
    decisionMessage: null,
    autoValidated: false,
    duplicateOfId: null,
    complementDe: null,
    submittedAt: '2026-10-09T10:00:00+02:00',
    createdAt: '2026-10-09T10:00:00+02:00',
    ops: [],
    brouillons: [],
    liens: [],
    revisions: [],
    empreinte: '9f2c',
    resolutionPrevue: 'complements_en_attente',
    complements,
  }
}

async function rendre(composant: unknown, props: Record<string, unknown>): Promise<string> {
  const app = createSSRApp({ render: () => h(composant as never, props) })
  app.component('RouterLink', LienFactice)
  return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '')
}

/** Le texte visible, balises retirées. */
function texte(html: string): string {
  return html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ')
}

describe('le panneau « Compléments proposés »', () => {
  beforeAll(async () => {
    await import('./ComplementsDeDemande.vue')
  }, 30_000)

  it('un complément en attente : ce qu’il apporte, ce qu’il faut trancher, et sa fusion', async () => {
    const { default: Panneau } = await import('./ComplementsDeDemande.vue')
    const html = await rendre(Panneau, { origine: origine([EN_ATTENTE]), peutFusionner: true })
    const lu = texte(html)

    expect(lu).toContain('Un complément attend sur cette proposition.')
    expect(lu).toContain('avant de la valider')
    expect(lu).toContain('Complément n° 21')
    expect(lu).toContain('proposé par Tuf Romarin')
    expect(lu).toContain('Surnom : Bébert')
    expect(lu).toContain('Garder la proposition : Masculin')
    expect(lu).toContain('Prendre le complément : Féminin')
    // L'origine est cochée d'office : la proposition l'emporte.
    expect(html).toMatch(/<input[^>]*value="origine"[^>]*checked/)
    expect(html).not.toMatch(/<input[^>]*value="complement"[^>]*checked/)
    // Sans la demande du complément (le rendu serveur ne lit rien), le rang seul.
    expect(lu).toContain('Ajouté — Lien n° 2 du complément')
    expect(lu).toContain('Écarté — Lien n° 1 du complément : il relierait la personne à elle-même')
    expect(lu).toContain('2 pièces jointes rejoindront la proposition.')
    expect(lu).toContain('Fusionner dans la demande n° 12')
  })

  it('sans le droit de fusionner, une ligne — ni rapport, ni bouton', async () => {
    const { default: Panneau } = await import('./ComplementsDeDemande.vue')
    const lu = texte(await rendre(Panneau, { origine: origine([EN_ATTENTE]), peutFusionner: false }))

    expect(lu).toContain('Complément n° 21')
    expect(lu).toContain('en attente de fusion')
    expect(lu).not.toContain('Fusionner dans la demande')
    expect(lu).not.toContain('Surnom : Bébert')
  })

  it('un complément tranché reste listé, sans geste, et sans prétendre qu’il a été fusionné', async () => {
    const { default: Panneau } = await import('./ComplementsDeDemande.vue')
    const html = await rendre(Panneau, { origine: origine([EN_ATTENTE, CLOS]), peutFusionner: true })
    const lu = texte(html)

    expect(html.match(/data-fusion=/g)).toHaveLength(1)
    expect(lu).toContain('Complément n° 24')
    expect(lu).toContain('par Ambre Romarin — close : fusionnée ou refusée')
  })
})

describe('le bandeau d’un complément', () => {
  function complement(surcharge: Partial<DemandeAdministration>): DemandeAdministration {
    return { ...origine([]), id: 21, authorLabel: 'Tuf Romarin', resolutionPrevue: 'complement_a_fusionner', ...surcharge }
  }

  it('nomme l’origine que la charge d’administration porte, en lien', async () => {
    const { default: Bandeau } = await import('./BandeauDeComplement.vue')
    const html = await rendre(Bandeau, {
      demande: complement({
        complementDe: { draftPersonId: 7, demandeId: 12, localRef: '#p1', displayName: 'Barnabé Muguet', status: 'en_attente' },
      }),
      peutFusionner: true,
    })

    expect(texte(html)).toContain('Cette demande complète la demande n° 12 — Barnabé Muguet.')
    // Le lien mène à la demande d'origine (SSR échappe les guillemets de l'attribut).
    expect(html.replace(/&quot;/g, '"')).toContain('"name":"demande.detail","params":{"id":12}')
  })

  it('dit d’une origine validée qu’il ne reste qu’à refuser', async () => {
    const { default: Bandeau } = await import('./BandeauDeComplement.vue')
    const lu = texte(
      await rendre(Bandeau, {
        demande: complement({ complementDe: { draftPersonId: 7, demandeId: 12, displayName: 'Barnabé Muguet', status: 'validee' } }),
        peutFusionner: true,
      }),
    )

    expect(lu).toContain('Elle est déjà validée')
    expect(lu).toContain('ne peut plus que se refuser')
  })

  it('à son auteur, ni numéro ni nom : la proposition est celle d’un autre', async () => {
    const { default: Bandeau } = await import('./BandeauDeComplement.vue')
    const html = await rendre(Bandeau, { demande: complement({ complementDe: { draftPersonId: 7 } }) })
    const lu = texte(html)

    expect(lu).toContain('Cette demande complète une proposition déjà en attente, faite par un autre membre.')
    expect(lu).toContain('L’administrateur y fusionnera ses informations.')
    expect(lu).not.toMatch(/n° \d/)
    expect(html).not.toContain('demande.detail')
  })
})
