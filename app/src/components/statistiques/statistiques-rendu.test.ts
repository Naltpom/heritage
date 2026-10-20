/**
 * Le rendu des briques d'Explorer › Stats, sans navigateur (SSR).
 *
 * Ce que cette suite tient : un graphique est **lisible sans la couleur et sans
 * la vue** (résumé `aria-label`, tableau pour lecteur d'écran, `—` pour une
 * case vide plutôt qu'un zéro), un record est un **lien vers la fiche**, un
 * palmarès est une liste ordonnée — et l'état de l'écran ne remplace jamais
 * des chiffres justes par un message d'erreur.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import Barres from './Barres.vue'
import CarteRecord from './CarteRecord.vue'
import CourbeDesJours from './CourbeDesJours.vue'
import Palmares from './Palmares.vue'
import { etatDeLEcran, LISTES_DU_CHANTIER } from '@/views/StatsView.vue'
import { PORTEES, porteeEffective } from '@/composables/usePorteeExplorer'
import type { Statistiques } from '@/lib/api'

async function rendre(composant: unknown, props: Record<string, unknown>): Promise<string> {
  const routeur = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { render: () => null } },
      { path: '/personnes/:id(\\d+)', name: 'personne.detail', component: { render: () => null } },
    ],
  })
  await routeur.push('/')
  await routeur.isReady()
  const app = createSSRApp({ render: () => h(composant as never, props) })
  app.use(routeur)
  return renderToString(app)
}

describe('un histogramme', () => {
  const props = {
    titre: 'Âge moyen au décès, par siècle',
    categories: ['XVIIIᵉ s.', 'XIXᵉ s.'],
    series: [
      { nom: 'Hommes', valeurs: [52.3, 61], couleur: 'var(--h-homme-bord)' },
      { nom: 'Femmes', valeurs: [null, 64.5], couleur: 'var(--h-femme-bord)' },
    ],
  }

  it('porte un résumé pour le lecteur d’écran, et ses nombres dans un tableau', async () => {
    const html = await rendre(Barres, props)

    expect(html).toContain('role="img"')
    expect(html).toContain('aria-label="Âge moyen au décès, par siècle"')
    expect(html).toMatch(/<div class="sr-only"><table>/)
    expect(html).toContain('52.3')
  })

  it('une case sans donnée s’écrit « — » et ne dessine aucune colonne', async () => {
    const html = await rendre(Barres, props)

    expect(html).toContain('—')
    expect(html).not.toContain('XVIIIᵉ s. — Femmes')
    expect(html).toContain('XIXᵉ s. — Femmes : 64.5')
  })

  it('deux séries ont une légende écrite : la couleur ne dit jamais seule qui est qui', async () => {
    const html = await rendre(Barres, props)

    expect(html).toMatch(/<figcaption[^>]*>[\s\S]*Hommes[\s\S]*Femmes[\s\S]*<\/figcaption>/)
  })
})

describe('la courbe des jours', () => {
  const props = {
    titre: 'Personnes ajoutées et vérifiées par jour',
    jours: [
      { jour: '2026-10-18', ajoutees: 12, verifiees: 0 },
      { jour: '2026-10-19', ajoutees: 0, verifiees: 0 },
      { jour: '2026-10-20', ajoutees: 1, verifiees: 3 },
    ],
  }

  it('se parcourt au clavier comme un curseur, qui dit le jour et les deux nombres', async () => {
    const html = await rendre(CourbeDesJours, props)

    expect(html).toContain('role="slider"')
    expect(html).toContain('tabindex="0"')
    expect(html).toContain('aria-valuetext="mar. 20 oct. 2026 : 1 personne ajoutée, 3 vérifiées"')
  })

  it('son tableau ne liste que les jours où quelque chose a bougé, et le dit', async () => {
    const html = await rendre(CourbeDesJours, props)

    expect(html).toMatch(/<div class="sr-only"><table>/)
    expect(html).toContain('dim. 18 oct. 2026')
    expect(html).not.toContain('lun. 19 oct. 2026')
    expect(html).toContain('les jours sans ajout ni vérification ne sont pas listés')
  })

  it('a une légende écrite, et son échelle monte à une valeur ronde', async () => {
    const html = await rendre(CourbeDesJours, props)

    expect(html).toMatch(/<figcaption[^>]*>[\s\S]*Personnes ajoutées[\s\S]*Personnes vérifiées[\s\S]*<\/figcaption>/)
    expect(html).toContain('>20<')
    expect(html).toContain('>10<')
  })
})

describe('une carte de record', () => {
  it('chaque titulaire est un lien vers sa fiche', async () => {
    const html = await rendre(CarteRecord, {
      titre: 'Plus longue union',
      valeur: '68 ans',
      personnes: [
        { id: 12, nom: 'Jean Romarin' },
        { id: 13, nom: 'Marie Bourrache' },
      ],
    })

    expect(html).toContain('href="/personnes/12"')
    expect(html).toContain('href="/personnes/13"')
    expect(html).toContain('68 ans')
    expect(html).toContain('> &amp; <')
  })
})

describe('un palmarès', () => {
  it('est une liste ordonnée, le nombre écrit à côté de la barre', async () => {
    const html = await rendre(Palmares, {
      lignes: [
        { libelle: 'Saule (1)', n: 31 },
        { libelle: 'Charmille (2)', n: 4 },
      ],
    })

    expect(html).toMatch(/^<ol/)
    expect(html.indexOf('Saule')).toBeLessThan(html.indexOf('Charmille'))
    expect(html).toContain('>31<')
  })
})

describe('l’écran', () => {
  it('des chiffres déjà affichés ne sont jamais remplacés par une erreur', () => {
    const vue = {} as Statistiques

    expect(etatDeLEcran(vue, new Error('réseau'))).toEqual({ corps: 'contenu', bandeau: true })
    expect(etatDeLEcran(null, new Error('réseau'))).toEqual({ corps: 'panne', bandeau: false })
    expect(etatDeLEcran(null, null)).toEqual({ corps: 'squelette', bandeau: false })
  })

  it('le chantier a ses quatre listes, dans l’ordre', () => {
    expect(LISTES_DU_CHANTIER.map((l) => l.cle)).toEqual(['sansDateDeNaissance', 'sansParents', 'sansSource', 'nonVerifiees'])
  })

  it('la bascule d’administration part de « Mon arbre », et un membre reste sur son arbre', () => {
    expect(PORTEES.map((p) => p.valeur)).toEqual(['arbre', 'tout'])
    expect(porteeEffective(true, 'tout')).toBe('tout')
    expect(porteeEffective(false, 'tout')).toBe('arbre')
  })
})
