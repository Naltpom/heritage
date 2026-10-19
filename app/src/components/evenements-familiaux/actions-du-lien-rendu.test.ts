/**
 * Les gestes du lien personnel d'un invité, rendus côté serveur.
 *
 * 1. **Pas de lien** : « Copier son lien », pas de « Révoquer ».
 * 2. **Lien actif** : on ne peut plus le relire — le geste s'appelle
 *    « Régénérer le lien », et « Révoquer » apparaît.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import ActionsDuLien from './ActionsDuLien.vue'

describe('ActionsDuLien', () => {
  it('pas de lien : « Copier son lien », rien à révoquer', async () => {
    const html = await renderToString(createSSRApp({ render: () => h(ActionsDuLien, { lienActif: false }) }))

    expect(html).toContain('Copier son lien')
    expect(html).toContain('Pas de lien')
    expect(html).not.toContain('Régénérer')
    expect(html).not.toContain('Révoquer')
  })

  it('lien actif : régénérer ou révoquer — jamais « copier » un lien qu’on ne connaît plus', async () => {
    const html = await renderToString(createSSRApp({ render: () => h(ActionsDuLien, { lienActif: true }) }))

    expect(html).toContain('Lien actif')
    expect(html).toContain('Régénérer le lien')
    expect(html).toContain('Révoquer')
    expect(html).not.toContain('Copier son lien')
  })
})
