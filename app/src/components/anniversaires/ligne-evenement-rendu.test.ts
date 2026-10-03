import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { EvenementAnniversaire } from '@/lib/api'
import LigneEvenement from './LigneEvenement.vue'

/**
 * Une ligne d'anniversaire — rendue côté serveur.
 *
 * ADR 0016 : la naissance d'une personne vivante dont l'année n'est pas
 * partagée arrive **sans `annee`**, et `texte` partiel (« 12 mars »). La ligne
 * recopie le texte et ne calcule aucun âge. Avec l'année, rien ne change.
 * Noms inventés.
 */

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

function evenement(sur: Partial<EvenementAnniversaire> = {}): EvenementAnniversaire {
  return {
    cle: 'naissance:71',
    genre: 'naissance',
    type: 'naissance',
    annee: 1985,
    texte: '12 mars 1985',
    lieu: null,
    sujets: [{ personId: 71, displayName: 'Ambre Serpolet', sex: 'feminin', vivant: true }],
    familyId: null,
    libelle: null,
    ...sur,
  }
}

async function rendre(e: EvenementAnniversaire): Promise<string> {
  const app = createSSRApp({ render: () => h(LigneEvenement, { evenement: e, anneeAffichee: 2026 }) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

describe('LigneEvenement', () => {
  it('avec l’année : le texte du serveur et l’écart', async () => {
    const html = await rendre(evenement())
    expect(html).toContain('12 mars 1985')
    expect(html).toContain('il y a 41 ans')
  })

  it('sans année partagée : le texte partiel, et aucun âge', async () => {
    const { annee: _sans, ...sansAnnee } = evenement({ texte: '12 mars' })
    const html = await rendre(sansAnnee)
    expect(html).toContain('12 mars')
    expect(html).not.toContain('il y a')
    expect(html).not.toContain('NaN')
  })
})
