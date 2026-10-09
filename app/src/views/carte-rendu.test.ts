/**
 * Le rendu de la carte, sans navigateur.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE FICHIER EST D'ABORD UNE ALARME.** `leaflet/src/core/Browser.js` lit
 * `document.documentElement.style` **à l'évaluation du module**, et
 * l'environnement de vitest est `node` — il n'y a pas de `document`. Un
 * `import 'leaflet'` statique, posé n'importe où sur le chemin de `CarteView`,
 * ferait tomber cette suite. Elle est donc la garde qui empêche ce chemin
 * d'exister : si elle devient rouge avec « document is not defined », c'est
 * qu'un import a été promu de `import type` à import de valeur, ou que le
 * `defineAsyncComponent` de la vue a été remplacé par un import direct.
 *
 * Le reste tient ce que le rendu SSR peut tenir : l'état de départ, l'état vide,
 * et la bande d'erreur. Tout ce qui se calcule est ailleurs, dans
 * `components/carte/*.test.ts`.
 * ────────────────────────────────────────────────────────────────────
 */
import { describe, expect, it, vi } from 'vitest'
import { createSSRApp, defineComponent, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'

// `useRouter` lève une injection manquante hors d'une application routée. On le
// bouchonne plutôt que de laisser un avertissement s'installer dans la sortie.
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: () => Promise.resolve() }),
}))

import CarteView from './CarteView.vue'
import BarreOutilsCarte from '@/components/carte/BarreOutilsCarte.vue'

/** Les deux `RouterLink` du panneau ne se résolvent pas hors d'une application
 *  routée. Même remplacement que dans `arbre-revalidation.test.ts`. */
const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

async function rendreLaVue(): Promise<string> {
  const app = createSSRApp(CarteView)
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

describe('la carte se monte sans navigateur', () => {
  it('rend son cadre sans charger Leaflet — sinon toute la suite tombe sur « document »', async () => {
    await expect(rendreLaVue()).resolves.toBeTypeOf('string')
  })

  it('pose une hauteur mesurée sur son cadre, comme les autres écrans à canevas', async () => {
    /* La hauteur réelle est mesurée au montage ; ce qu'on tient ici, c'est que
       le cadre porte bien un style de hauteur et non une chaîne de `100%`. */
    const html = await rendreLaVue()

    expect(html).toMatch(/style="[^"]*height:/)
  })
})

describe('avant que la charge n’arrive, on montre la place à venir', () => {
  it('rend un squelette plutôt qu’une carte vide', async () => {
    /*
     * LE DÉFAUT QU'ON ÉVITE. Au premier rendu, `vue` est `null` et le
     * chargement n'a pas encore commencé — `onMounted` ne s'exécute pas sous
     * SSR. Une condition écrite sur `chargement` seul laissait alors tomber
     * l'écran dans la branche finale : on montait Leaflet sur une carte sans
     * une seule bulle, le temps d'un aller-retour, et cela ressemblait à une
     * carte vide plutôt qu'à une carte qui arrive.
     */
    const html = await rendreLaVue()

    expect(html).not.toContain('aria-label="Carte des lieux"')
  })
})

describe('la barre d’outils est là dès le premier rendu', () => {
  it('propose les cinq genres, naissances comprises', async () => {
    const html = await rendreLaVue()

    for (const libelle of ['Naissances', 'Décès', 'Unions', 'Baptêmes', 'Autres faits']) {
      expect(html).toContain(libelle)
    }
  })

  it('n’allume que les naissances', async () => {
    /* La demande, et aussi le genre le mieux pourvu de cet arbre. */
    const html = await rendreLaVue()
    const pastilles = [...html.matchAll(/aria-pressed="(true|false)"[^>]*>\s*([^<]+?)\s*</g)]
    const allumees = pastilles.filter(([, etat]) => etat === 'true').map(([, , libelle]) => libelle)

    expect(allumees).toContain('Naissances')
    expect(allumees).not.toContain('Décès')
  })

  it('part sur le fond sobre, celui qui ne parle à personne', async () => {
    /*
     * La règle qui compte : élargir la CSP et s'en servir sont deux choses
     * distinctes. Tant que « Fond sobre » est le bouton enfoncé, aucune
     * requête ne sort vers un tiers — et c'est l'état de départ.
     */
    const html = await rendreLaVue()
    const pastilles = [...html.matchAll(/aria-pressed="(true|false)"[^>]*>\s*([^<]+?)\s*</g)]
    const allumees = pastilles.filter(([, etat]) => etat === 'true').map(([, , libelle]) => libelle)

    expect(allumees).toContain('Fond sobre')
    expect(allumees).not.toContain('Fond détaillé')
  })

  it('part sur le dégradé par date', async () => {
    const html = await rendreLaVue()
    const pastilles = [...html.matchAll(/aria-pressed="(true|false)"[^>]*>\s*([^<]+?)\s*</g)]
    const allumees = pastilles.filter(([, etat]) => etat === 'true').map(([, , libelle]) => libelle)

    expect(allumees).toContain('Par date')
  })

  it('annonce ce que coûte le fond détaillé AVANT qu’on y touche', async () => {
    /* Le dire après l'appui ne servirait plus à rien. L'hôte est donc nommé
       dans l’infobulle du bouton « Fond détaillé », dès le premier rendu. */
    const html = await rendreLaVue()

    expect(html).toContain('openstreetmap.org')
  })

  it('n’émet aucune URL de tuile tant que personne n’a appuyé', async () => {
    /*
     * LA PROPRIÉTÉ QUI COMPTE, et elle n'est pas « le mot n'apparaît pas » :
     * élargir la CSP et s'en servir sont deux choses distinctes. Ce qu'on tient
     * ici, c'est qu'aucune ADRESSE chargeable n'est dans la page — ni gabarit
     * `{z}/{x}/{y}`, ni `src` vers l'hôte. Le nom dans une infobulle est du
     * texte ; une URL, c'est une requête.
     */
    const html = await rendreLaVue()

    expect(html).not.toContain('{z}')
    expect(html).not.toMatch(/(src|href)="https:\/\/tile\.openstreetmap\.org/)
  })
})

describe('le tiroir des filtres', () => {
  it('n’est pas un `aside` — ce nom est réservé aux volets', async () => {
    /* Un `<aside>`, dans ce produit, est un VOLET nommé qui partage l'écran avec
       le contenu : sur l'arbre, « Ajouter une personne » à gauche et la fiche
       choisie à droite, chacun avec son `aria-label` (et la colonne « Place dans
       l'arbre » d'une fiche). Les lecteurs d'écran les listent comme régions
       complémentaires. Un tiroir de filtres, qui s'ouvre par-dessus et se referme,
       n'en est pas un : rendu en `<aside>`, il ajouterait à cette liste une région
       anonyme. La règle vaut pour tous les écrans qui montent un tiroir
       (`BarreEcran`, « jamais un `<aside>` »), celui-ci compris. */
    const html = await rendreLaVue()

    expect(html).not.toContain('<aside')
  })

  it('porte l’identifiant que le bouton de l’en-tête désigne', async () => {
    const html = await rendreLaVue()

    expect(html).toContain('id="tiroir-outils"')
  })
})

describe('la barre d’écran de la carte', () => {
  function rendreLaBarre(supplement: Record<string, unknown> = {}): Promise<string> {
    const proprietes: Record<string, unknown> = {
      actifs: new Set(['naissance']),
      resume: null,
      mode: 'date',
      fond: 'vectoriel',
      nonSitues: 12,
      chargement: false,
      ...supplement,
    }
    return renderToString(createSSRApp({ render: () => h(BarreOutilsCarte as Component, proprietes) }))
  }

  it('n’a pas de bouton Filtres sur bureau : tout tient dans la barre', async () => {
    const html = await rendreLaBarre()
    expect(html).not.toMatch(/>\s*Filtres\s*</)
  })

  it('compte les lieux non situés, et ne dit rien quand il n’y en a pas', async () => {
    expect(await rendreLaBarre()).toContain('12 sans lieu situé')
    expect(await rendreLaBarre({ nonSitues: 0 })).not.toContain('sans lieu situé')
  })

  it('présente Fond sobre · Fond détaillé en segmenté, avec l’hôte dans l’infobulle', async () => {
    const html = await rendreLaBarre()
    expect(html).toMatch(/role="group"[^>]*aria-label="Fond de carte"/)
    expect(html).toMatch(/aria-pressed="false"[^>]*title="Images d’openstreetmap\.org/)
  })

  it('écrit l’avertissement sous la barre une fois le fond détaillé choisi', async () => {
    /* Avant l'appui, le survol et le focus le font paraître (état local, hors
       d'atteinte du rendu SSR) ; choisi, il reste. */
    const avant = await rendreLaBarre()
    expect(avant).not.toContain('role="note"')
    const apres = await rendreLaBarre({ fond: 'tuiles' })
    expect(apres).toMatch(/role="note"[^>]*>[^<]*openstreetmap\.org : ce site voit les zones/)
  })
})
