/**
 * La recherche de l'en-tête — le motif « combobox » de WAI-ARIA, tel que le
 * balisage l'écrit.
 *
 * Rendu serveur (`renderToString`), comme les autres tests de l'en-tête : il ne
 * sait ni taper ni attendre une réponse. Les états (chargement, panne, vide,
 * résultats) sont tenus par `composables/useRecherchePersonnes.test.ts` ; ici, on
 * tient ce qu'un lecteur d'écran lit au repos — et c'est là qu'une erreur ne se
 * voit pas : un `aria-controls` vers un identifiant absent est du HTML valide.
 */
import { describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: () => Promise.resolve() }),
}))
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  rechercherPersonnes: () => Promise.resolve({ elements: [], tronque: false }),
}))

import RechercheEntete from './RechercheEntete.vue'

async function rendre(): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(RechercheEntete) }))
}

/** La balise ouvrante du premier élément qui porte `attribut`. */
function balise(html: string, attribut: string): string {
  return html.match(new RegExp(`<[a-z]+[^>]*${attribut}[^>]*>`))?.[0] ?? ''
}

describe('la recherche de l’en-tête est une combobox', () => {
  it('porte le rôle, l’autocomplétion en liste et l’état replié', async () => {
    const champ = balise(await rendre(), 'role="combobox"')

    expect(champ).toContain('<input')
    expect(champ).toContain('aria-autocomplete="list"')
    expect(champ).toContain('aria-expanded="false"')
    // Aucune option active au repos : l'attribut est absent, pas vide.
    expect(champ).not.toContain('aria-activedescendant')
  })

  it('désigne une liste qui existe vraiment, même repliée', async () => {
    const html = await rendre()
    const controle = balise(html, 'role="combobox"').match(/aria-controls="([^"]+)"/)?.[1]

    expect(controle).toBe('recherche-entete-liste')
    expect(balise(html, `id="${controle}"`)).toContain('role="listbox"')
  })

  it('ne dit pas « Personne ne correspond. » avant la moindre réponse', async () => {
    expect(await rendre()).not.toContain('Personne ne correspond')
  })
})
