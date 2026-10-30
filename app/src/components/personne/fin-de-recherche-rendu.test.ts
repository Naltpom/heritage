import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FichePersonneComplete, FinDeRecherche } from '@/lib/api'
import FinDeRechercheFiche from './FinDeRechercheFiche.vue'

/**
 * L'encart « Fin de recherche » **tel qu'il s'imprime** (ADR 0023). La logique est
 * prouvée dans `fin-de-recherche.test.ts` ; ici, que le gabarit la dit : les cinq états
 * de lecture, le calque, et l'absence de tout bouton sans le droit de proposer.
 * Rendu SSR, comme `fiche-rendu.test.ts`.
 */

const session = vi.hoisted(() => ({ regime: null as unknown as { value: string } }))

vi.mock('@/composables/useSession', async () => {
  const { computed: c, shallowRef: sr } = await import('vue')
  session.regime = sr('membre')
  return {
    useSession: () => ({
      regime: session.regime,
      vueEmpruntee: sr(null),
      connecte: c(() => session.regime.value !== 'anonyme'),
      rattache: c(() => session.regime.value === 'membre' || session.regime.value === 'administrateur'),
      administrateur: c(() => session.regime.value === 'administrateur'),
    }),
  }
})

beforeEach(() => {
  session.regime.value = 'membre'
})

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (props, { slots }) => () => h('a', { href: '#', 'data-vers': JSON.stringify(props.to) }, slots['default']?.()),
})

function rendre(fiche: FichePersonneComplete): Promise<string> {
  const app = createSSRApp({ render: () => h(FinDeRechercheFiche, { fiche }) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

const FIN: FinDeRecherche = {
  reason: 'registres_inexistants',
  beforeYear: 1668,
  body: 'Les registres de Saint-Martin\ncommencent en 1668',
  authorLabel: 'Opale Romarin',
  closedAt: '2026-10-12T09:30:00+02:00',
}

function fiche(surcharge: Partial<FichePersonneComplete> = {}): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id: 17,
    version: 8,
    parents: [],
    finDeRecherchePere: null,
    finDeRechercheMere: null,
    enAttente: {},
    demandesEnAttente: [],
    ...surcharge,
  } as unknown as FichePersonneComplete
}

/** Le morceau de HTML d'un côté. */
function cote(html: string, nom: 'pere' | 'mere'): string {
  const debut = html.indexOf(`data-cote="${nom}"`)
  // Le père s'arrête où la mère commence ; la mère, où le geste commun « Clore » ou le
  // formulaire commence (ils sont hors des deux côtés).
  const apres = nom === 'pere' ? ['data-cote="mere"'] : ['data-geste="clore"', 'data-formulaire']
  const fins = apres.map((m) => html.indexOf(m, debut + 1)).filter((i) => i !== -1)
  return html.slice(debut, fins.length === 0 ? html.length : Math.min(...fins)).replace(/<!--.*?-->/g, '').replace(/\s+/g, ' ')
}

describe('la lecture', () => {
  it('un côté clos : le motif avec son année, le texte, l’auteur et la date', async () => {
    const html = await rendre(fiche({ finDeRecherchePere: FIN }))
    const pere = cote(html, 'pere')

    expect(pere).toContain('Registres inexistants avant 1668')
    expect(pere).toContain('Les registres de Saint-Martin')
    expect(pere).toContain('whitespace-pre-line')
    expect(pere).toContain('Opale Romarin')
    expect(pere).toContain('Close le 12 octobre 2026')
    expect(pere).toContain('data-fin')
    // L'icône mur, de couleur brique.
    expect(pere).toContain('<svg')
    expect(pere).toContain('var(--h-fin-de-recherche, #9b3426)')
    // L'autre côté reste à chercher.
    expect(cote(html, 'mere')).toContain('Pas encore cherché')
    expect(cote(html, 'mere')).not.toContain('data-fin')
  })

  it('un côté à chercher', async () => {
    const html = await rendre(fiche())
    expect(cote(html, 'pere')).toContain('Pas encore cherché')
    expect(html).toContain('Côté père')
    expect(html).toContain('Côté mère')
  })

  it('un parent connu, sans fin de recherche : une ligne discrète', async () => {
    const html = await rendre(fiche({ parents: [{ id: 3, cote: 'mere' }] as never }))
    expect(cote(html, 'mere')).toContain('Parent connu')
    expect(cote(html, 'mere')).not.toContain('Pas encore cherché')
  })

  it('un parent connu avec une fin de recherche restée : la mention, et la levée mise en avant', async () => {
    const html = await rendre(fiche({ finDeRecherchePere: FIN, parents: [{ id: 3, cote: 'pere' }] as never }))
    const pere = cote(html, 'pere')

    expect(pere).toContain('Un parent est désormais lié de ce côté : l’arbre ne montre plus cette fin de recherche.')
    expect(pere).toContain('Registres inexistants avant 1668')
    expect(pere).toContain('data-geste="rouvrir"')
    // Mise en avant : avant « Modifier ».
    expect(pere.indexOf('data-geste="rouvrir"')).toBeLessThan(pere.indexOf('data-geste="modifier"'))
  })
})

describe('une demande en attente', () => {
  const entree = (propose: unknown, actuel: unknown) => ({
    propose,
    actuel,
    demandeId: 31,
    auteur: 'Opale Romarin',
    depuis: '2026-10-20T10:00:00+02:00',
    autresDemandes: [],
  })

  it('une pose : le calque, la pastille, l’auteur, le lien vers la demande, aucun geste sur ce côté', async () => {
    const html = await rendre(
      fiche({ enAttente: { finDeRecherchePere: entree({ reason: 'lacune', beforeYear: null, body: 'Registre perdu' }, null) } }),
    )
    const pere = cote(html, 'pere')

    expect(pere).toContain('data-calque')
    expect(pere).toContain('En attente de validation')
    expect(pere).toContain('Lacune dans les registres — Registre perdu')
    expect(pere).toContain('Proposé par Opale Romarin le 20 octobre 2026')
    expect(pere).toContain('Demande n° 31')
    expect(pere).toContain('&quot;name&quot;:&quot;demande.detail&quot;')
    expect(pere).toContain('Une demande attend déjà la validation pour ce côté.')
    expect(pere).not.toContain('data-geste')
    // La valeur validée reste dite : ici, rien n'est encore enregistré.
    expect(pere).toContain('Pas encore cherché')
  })

  it('une levée : « Rouvrir la recherche », et la valeur validée reste visible', async () => {
    const html = await rendre(
      fiche({
        finDeRechercheMere: FIN,
        enAttente: { finDeRechercheMere: entree(null, { reason: FIN.reason, beforeYear: 1668, body: FIN.body }) },
      }),
    )
    const mere = cote(html, 'mere')

    expect(mere).toContain('data-levee')
    expect(mere).toContain('Rouvrir la recherche')
    expect(mere).toContain('Registres inexistants avant 1668')
    expect(mere).not.toContain('data-geste')
  })

  it('le calque d’un côté ne touche pas l’autre', async () => {
    const html = await rendre(fiche({ enAttente: { finDeRecherchePere: entree({ reason: 'lacune', beforeYear: null, body: null }, null) } }))
    expect(cote(html, 'mere')).not.toContain('data-calque')
    expect(cote(html, 'mere')).not.toContain('Une demande attend')
  })
})

describe('les gestes', () => {
  it('sans le droit de proposer : aucun bouton, aucun formulaire', async () => {
    session.regime.value = 'anonyme'
    const html = await rendre(fiche({ finDeRecherchePere: FIN }))

    expect(html).not.toContain('data-geste')
    expect(html).not.toContain('<button')
    expect(html).not.toContain('data-formulaire')
    expect(html).toContain('Registres inexistants avant 1668')
  })

  it('pour un membre rattaché : clore, et modifier / rouvrir sur un côté clos', async () => {
    const html = await rendre(fiche({ finDeRecherchePere: FIN }))
    expect(html).toContain('data-geste="clore"')
    expect(html).toContain('Clore la recherche')
    expect(cote(html, 'pere')).toContain('data-geste="modifier"')
    expect(cote(html, 'pere')).toContain('data-geste="rouvrir"')
    // Le formulaire n'est pas ouvert d'office.
    expect(html).not.toContain('data-formulaire')
  })

  it('aucun « Clore la recherche » quand les deux côtés sont déjà dits', async () => {
    const html = await rendre(fiche({ finDeRecherchePere: FIN, finDeRechercheMere: FIN }))
    expect(html).not.toContain('data-geste="clore"')
  })
})
