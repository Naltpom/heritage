/**
 * La barre d'outils de la chronologie dans sa mise en page de refonte
 * (`BarreEcran`, handoff « En-tête, barres d'écran et fiche », § 2).
 *
 * Rendu SSR, donc la forme **large** : `useEstMobile` vaut `false` hors
 * navigateur. Ce qu'on tient ici, c'est que le changement de décor n'a rien
 * perdu : la bande n'est rendue qu'une fois (son `id` reste unique), les
 * filtres sont dans le menu, et « Enregistrer » garde ses deux libellés.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'
import BarreOutilsChronologie from './BarreOutilsChronologie.vue'

function rendre(supplement: Record<string, unknown> = {}): Promise<string> {
  const proprietes: Record<string, unknown> = {
    bande: 25,
    orientation: 'lignes',
    branches: ['pp', 'pm', 'mp', 'mm', 'tr', 'cpp', 'cpm', 'cmp', 'cmm'],
    montrerIncertitude: true,
    vivantsSeuls: false,
    chargement: false,
    preferenceAJour: false,
    enregistrement: false,
    ...supplement,
  }
  return renderToString(createSSRApp({ render: () => h(BarreOutilsChronologie as Component, proprietes) }))
}

describe('BarreOutilsChronologie dans la barre d’écran', () => {
  it('n’est jamais un `aside`, et porte le bloc que l’en-tête désigne', async () => {
    const html = await rendre()
    expect(html).not.toContain('<aside')
    expect(html).toContain('id="tiroir-outils"')
  })

  it('rend la saisie de la bande une seule fois', async () => {
    const html = await rendre()
    expect((html.match(/id="chrono-bande"/g) ?? []).length).toBe(1)
  })

  it('présente Lignes · Colonnes en segmenté, le sens courant enfoncé', async () => {
    const html = await rendre({ orientation: 'colonnes' })
    expect(html).toMatch(/aria-pressed="true"[^>]*>\s*Colonnes/)
    expect(html).toMatch(/aria-pressed="false"[^>]*>\s*Lignes/)
  })

  it('dit « non enregistré » à côté de « Vivants uniquement », sans survol', async () => {
    expect(await rendre()).toMatch(/Vivants uniquement[\s\S]*non enregistré/)
  })

  it('compte les filtres actifs : branches retirées, vivants seuls', async () => {
    const aucun = await rendre()
    expect(aucun).not.toMatch(/Filtres\s*<span[^>]*>\d/)
    const deux = await rendre({ branches: ['pp'], vivantsSeuls: true })
    expect(deux).toMatch(/Filtres\s*<span[^>]*>2<\/span>/)
  })

  it('« Enregistrer ces réglages » devient « Réglages enregistrés », désactivé, quand la préférence est à jour', async () => {
    expect(await rendre({ preferenceAJour: false })).toContain('Enregistrer ces réglages')
    const html = await rendre({ preferenceAJour: true })
    expect(html).toContain('Réglages enregistrés')
    expect(html).toMatch(/<button[^>]*disabled[^>]*>(?:(?!<\/button>)[\s\S])*Réglages enregistrés/)
  })
})
