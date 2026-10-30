/**
 * L'encart « Fin de recherche » **monté et cliqué** (`test-utils/rendu-memoire.ts`).
 *
 * Ce que le rendu serveur (`fin-de-recherche-rendu.test.ts`) ne peut pas montrer : un
 * refus de levée. « Rouvrir la recherche » se confirme dans un dialogue, sans formulaire ;
 * si le serveur répond `422`, le motif doit se lire **hors du formulaire** — fermé à ce
 * moment-là, il ne l'afficherait à personne.
 *
 * Noms inventés.
 */
import { computed, defineComponent, h, shallowRef } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ErreurApi, type FichePersonneComplete, type FinDeRecherche } from '@/lib/api'
import { boutonNomme, cliquer, demonterTout, monterEnMemoire, parAttribut, texte, un, type Noeud } from '@/test-utils/rendu-memoire'
import FinDeRechercheFiche from './FinDeRechercheFiche.vue'

const creerDemande = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  creerDemande,
}))

vi.mock('@/composables/useSession', () => {
  const regime = shallowRef('membre')
  return {
    useSession: () => ({
      regime,
      vueEmpruntee: shallowRef(null),
      connecte: computed(() => true),
      rattache: computed(() => true),
      administrateur: computed(() => false),
    }),
  }
})

const FIN: FinDeRecherche = {
  reason: 'registres_inexistants',
  beforeYear: 1668,
  body: null,
  authorLabel: 'Opale Romarin',
  closedAt: '2026-10-12T09:30:00+02:00',
}

const FICHE = {
  visibilite: 'complete',
  id: 17,
  version: 8,
  parents: [],
  finDeRecherchePere: FIN,
  finDeRechercheMere: null,
  enAttente: {},
  demandesEnAttente: [],
} as unknown as FichePersonneComplete

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_props, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

async function monter(): Promise<Noeud> {
  return monterEnMemoire(defineComponent({ render: () => h(FinDeRechercheFiche, { fiche: FICHE }) }), { RouterLink: LienFactice })
}

const rouvrir = (racine: Noeud) => un(racine, (n) => n.tag === 'button' && n.attributs['data-geste'] === 'rouvrir')

beforeEach(() => {
  creerDemande.mockReset()
})

afterEach(demonterTout)

describe('rouvrir la recherche', () => {
  it('un refus du serveur se lit hors du formulaire, qui reste fermé', async () => {
    creerDemande.mockRejectedValueOnce(new ErreurApi(422, 'validation', 'Refusé.', { 'ops[0]': 'Ce côté n’est pas clos.' }))
    const racine = await monter()

    await cliquer(rouvrir(racine))
    const dialogue = un(racine, (n) => n.tag === 'dialog')
    await cliquer(un(dialogue!, boutonNomme('Rouvrir la recherche')))

    expect(creerDemande).toHaveBeenCalledOnce()
    expect(un(racine, parAttribut('data-formulaire'))).toBeNull()
    const refus = un(racine, parAttribut('data-erreur-levee'))
    expect(refus).not.toBeNull()
    expect(texte(refus!)).toContain('Ce côté n’est pas clos.')
  })

  it('sans refus, aucun message', async () => {
    creerDemande.mockResolvedValueOnce({ id: 31 })
    const racine = await monter()

    await cliquer(rouvrir(racine))
    await cliquer(un(un(racine, (n) => n.tag === 'dialog')!, boutonNomme('Rouvrir la recherche')))

    expect(un(racine, parAttribut('data-erreur-levee'))).toBeNull()
    expect(un(racine, parAttribut('data-annonce-fin-de-recherche'))).not.toBeNull()
  })
})
