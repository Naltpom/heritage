/**
 * La page « Actes demandés », rendue côté serveur : **le filtre se lit dans
 * l'adresse** (`?statuts=`). Un lien partagé — « les refusées, à refaire » —
 * doit montrer les mêmes pastilles cochées à qui l'ouvre ; une adresse nue,
 * ce qui attend encore la pièce.
 *
 * La lecture et l'écriture de la valeur elle-même sont tenues par
 * `demarches.test.ts` (`lireStatuts`, `ecrireStatuts`) ; ici, on tient que la
 * vue s'en sert.
 */
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'

const adresse = vi.hoisted(() => ({ query: {} as Record<string, string> }))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: adresse.query, hash: '' }),
  useRouter: () => ({ replace: () => Promise.resolve(), push: () => Promise.resolve() }),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  // La liste ne se charge pas ici : les pastilles s'affichent sans compteur.
  listerDemarches: () => new Promise(() => {}),
}))

import DemarchesView from '@/views/DemarchesView.vue'

/** Les pastilles de statut cochées, par leur valeur. */
async function cochees(query: Record<string, string>): Promise<string[]> {
  adresse.query = query
  const app = createSSRApp({ render: () => h(DemarchesView) })
  app.component('RouterLink', { template: '<a href="#"><slot /></a>' })
  const html = await renderToString(app)
  return [...html.matchAll(/<button([^>]*)>/g)]
    .map((m) => m[1] ?? '')
    .filter((attributs) => attributs.includes('aria-pressed="true"'))
    .map((attributs) => /data-statut="([a-z_]+)"/.exec(attributs)?.[1])
    .filter((valeur): valeur is string => valeur !== undefined)
}

describe('le filtre de la page « Actes demandés » vit dans l’adresse', () => {
  it('adresse nue : ce qui attend encore la pièce', async () => {
    expect(await cochees({})).toEqual(['envoyee', 'relancee', 'expediee'])
  })

  it('?statuts=refusee,sans_suite : ce qui est à refaire autrement', async () => {
    expect(await cochees({ statuts: 'refusee,sans_suite' })).toEqual(['refusee', 'sans_suite'])
  })

  it('?statuts=toutes : tout est coché', async () => {
    expect(await cochees({ statuts: 'toutes' })).toEqual(['envoyee', 'relancee', 'expediee', 'recue', 'refusee', 'sans_suite'])
  })

  it('?statuts=expediee : seulement ce qui est parti par la poste', async () => {
    expect(await cochees({ statuts: 'expediee' })).toEqual(['expediee'])
  })
})
