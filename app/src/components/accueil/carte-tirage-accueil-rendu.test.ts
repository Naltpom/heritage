/**
 * Un tirage sur l'accueil, rendu côté serveur (donc replié, sans geste).
 *
 * 1. **Tirage fait** : « Voir à qui je fais le cadeau » est là sans rien
 *    déplier, et le nom du receveur n'est **pas dans le HTML**.
 * 2. **Édition ouverte** : sa présence se règle ici, en trois boutons.
 * 3. **Tirage fait** : la présence n'est plus réglable depuis l'accueil.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import type { EditionDuTirage, PersonneDuTirage, ResumeDeTirage } from '@/lib/api'
import CarteTirageAccueil from './CarteTirageAccueil.vue'

const MOI: PersonneDuTirage = { personId: 1, libelle: 'Natron Romarin' }

function edition(partiel: Partial<EditionDuTirage>): EditionDuTirage {
  return {
    annee: 2026,
    date: '2026-12-24',
    passee: false,
    statut: 'ouverte',
    statutLibelle: 'Ouverte',
    memoireAppliquee: null,
    tireeLe: null,
    nombreDAttributions: 0,
    presences: [
      { personId: 1, libelle: 'Natron Romarin', presence: 'sur_place', presenceLibelle: 'Sur place' },
      { personId: 3, libelle: 'Opale Tilleul', presence: 'absente', presenceLibelle: 'Ne participe pas' },
    ],
    arrivesApresLeTirage: [],
    mesAttributions: [],
    ...partiel,
  }
}

function tirage(e: EditionDuTirage): ResumeDeTirage {
  return {
    id: 7,
    nom: 'Noël des Romarin',
    jour: 24,
    mois: 12,
    lieu: 'Fleurance',
    ouvert: true,
    memoire: 2,
    exclureConjoints: true,
    organisateur: { libelle: 'Opale Tilleul', estMoi: false },
    peutGerer: false,
    suisParticipant: true,
    peutRejoindre: false,
    nombreDeParticipants: 5,
    prochaineEdition: e,
  }
}

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

async function rendre(t: ResumeDeTirage): Promise<string> {
  const app = createSSRApp({ render: () => h(CarteTirageAccueil, { tirage: t, moi: MOI, aujourdhui: new Date(2026, 8, 30, 12) }) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

describe('CarteTirageAccueil', () => {
  it('tirage fait : le bouton cadeau est visible, le receveur absent du HTML', async () => {
    const html = await rendre(
      tirage(
        edition({
          statut: 'tiree',
          mesAttributions: [
            { donneur: MOI, receveur: { personId: 2, libelle: 'Cobalt Romarin' }, presenceDuDonneur: 'sur_place' },
            { donneur: { personId: 4, libelle: 'Léa Romarin' }, receveur: { personId: 5, libelle: 'Ambre Chardon' }, presenceDuDonneur: 'sur_place' },
          ],
        }),
      ),
    )

    expect(html).toContain('Voir à qui je fais le cadeau')
    expect(html).toContain('Voir à qui Léa Romarin fait le cadeau')
    expect(html).toContain('bg-cadeau')
    expect(html).not.toContain('Cobalt Romarin')
    expect(html).not.toContain('Ambre Chardon')
    expect(html).toContain('Le tirage est fait')
    expect(html).not.toContain('aria-label="Ma présence"')
  })

  it('édition ouverte : sa présence se règle en trois boutons', async () => {
    const html = await rendre(tirage(edition({})))

    expect(html).toContain('aria-label="Ma présence"')
    expect(html).toContain('Sur place')
    expect(html).toContain('À distance')
    expect(html).toContain('Ne participe pas')
    expect(html).not.toContain('Voir à qui')
    expect(html).toContain('Opale Tilleul')
  })
})
