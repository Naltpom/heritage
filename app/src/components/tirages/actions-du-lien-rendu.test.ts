/**
 * Les gestes du lien personnel sur la page du tirage, rendus côté serveur.
 *
 * 1. **Pas de lien** : « Copier son lien », pas de « Révoquer ».
 * 2. **Lien actif** : on ne peut plus le relire — le geste s'appelle
 *    « Régénérer le lien », et « Révoquer » apparaît.
 * 3. **Le lien fraîchement créé** s'affiche en entier, avec l'avertissement :
 *    il donne accès à la présence et au cadeau.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import ActionsDuLien from './ActionsDuLien.vue'
import LienDuParticipant from './LienDuParticipant.vue'

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

describe('LienDuParticipant', () => {
  it('montre l’adresse entière, le bouton de copie et l’avertissement', async () => {
    const adresse = 'https://heritage.local/t/kJ8s-2Qb1nR7xTmA0vL5wYcE9hP3dZfG6uIoN4jXqSs'
    const html = await renderToString(
      createSSRApp({ render: () => h(LienDuParticipant, { libelle: 'Léa Romarin', adresse }) }),
    )

    expect(html).toContain(adresse)
    expect(html).toContain('Copier son lien')
    expect(html).toContain('Ce lien donne accès à sa présence et à son cadeau')
    expect(html).toContain('ne le partagez qu’avec Léa Romarin')
    expect(html).toContain('régénérez-le')
    expect(html).not.toContain('Copié')
  })

  it('dit que la copie automatique a réussi', async () => {
    const html = await renderToString(
      createSSRApp({ render: () => h(LienDuParticipant, { libelle: 'Léa', adresse: 'https://x/t/a', dejaCopie: true }) }),
    )

    expect(html).toContain('Copié dans le presse-papiers.')
  })
})
