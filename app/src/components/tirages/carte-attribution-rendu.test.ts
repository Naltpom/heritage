/**
 * Le nom du receveur n'est **pas dans le HTML** tant qu'on n'a pas cliqué sur
 * « Découvrir » — ni caché par une classe, ni estompé : absent.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import type { MonAttribution } from '@/lib/api'
import CarteAttribution from './CarteAttribution.vue'

const attribution: MonAttribution = {
  donneur: { personId: 1, libelle: 'Natron Romarin' },
  receveur: { personId: 2, libelle: 'Cobalt Romarin' },
  presenceDuDonneur: 'a_distance',
}

describe('CarteAttribution', () => {
  it('ne rend pas le nom du receveur avant le geste', async () => {
    const html = await renderToString(createSSRApp(CarteAttribution, { attribution, monPersonId: 1 }))

    expect(html).not.toContain('Cobalt Romarin')
    expect(html).toContain('Vous offrez un cadeau à')
    expect(html).toContain('Découvrir')
    expect(html).toContain('faites transmettre')
  })

  it('nomme celui qu’on accompagne, sans davantage révéler son receveur', async () => {
    const html = await renderToString(createSSRApp(CarteAttribution, { attribution, monPersonId: 9 }))

    expect(html).toContain('Natron Romarin offre un cadeau à')
    expect(html).not.toContain('Cobalt Romarin')
  })
})
