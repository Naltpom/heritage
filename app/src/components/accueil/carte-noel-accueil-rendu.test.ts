/**
 * Un tirage de Noël sur l'accueil, rendu côté serveur (donc sans geste).
 *
 * 1. **Ouvert, pas encore répondu** : le compte à rebours du tirage, les deux
 *    dates, « Répondre » qui mène au site de Noël ;
 * 2. **Ouvert, répondu** : « Tu participes » ou « Tu ne participes pas », sans
 *    bouton « Répondre » ;
 * 3. **Tiré, et j'y suis** : « Découvrir à qui j'offre », et **aucun nom de
 *    receveur dans le HTML** — le secret n'entre dans le DOM qu'au geste
 *    (`carte-noel-accueil.client.test.ts`) ;
 * 4. « Vous organisez » pour le créateur, et toujours le lien vers le site.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import type { ResumeDuTirageDeNoel } from '@/lib/api'
import CarteNoelAccueil from './CarteNoelAccueil.vue'

const AUJOURDHUI = new Date(2026, 9, 19, 12) // lundi 19 octobre 2026

function tirage(partiel: Partial<ResumeDuTirageDeNoel> = {}): ResumeDuTirageDeNoel {
  return {
    code: 'ROMA26',
    titre: 'Noël des Romarin',
    dateTirage: '2026-12-01T00:00:00+01:00',
    dateEvenement: '2026-12-24T00:00:00+01:00',
    statut: 'ouvert',
    estCreateur: false,
    reponse: null,
    nbParticipants: 6,
    budget: 30,
    lieu: 'Fleurance',
    ...partiel,
  }
}

function rendre(t: ResumeDuTirageDeNoel): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(CarteNoelAccueil, { tirage: t, aujourdhui: AUJOURDHUI }) }))
}

describe('CarteNoelAccueil', () => {
  it('ouvert, pas répondu : le compte à rebours, les deux dates, et « Répondre » vers le site de Noël', async () => {
    const html = await rendre(tirage())

    expect(html).toContain('Noël des Romarin')
    expect(html).toContain('Tirage dans 43 jours')
    expect(html).toContain('1er décembre 2026')
    expect(html).toContain('Remise des cadeaux')
    expect(html).toContain('24 décembre 2026')
    expect(html).toContain('Fleurance · 6 participants · budget 30 €')
    expect(html).toContain('Tu n’as pas encore répondu')
    expect(html).toMatch(/<a[^>]*href="https:\/\/noel2026\.naltpom\.fr\/t\/ROMA26"[^>]*data-repondre/)
    expect(html).toContain('Ouvrir sur le site de Noël')
    expect(html).not.toContain('Découvrir')
    expect(html).not.toContain('Vous organisez')
  })

  it('ouvert, répondu : la pastille dit ma réponse, plus de « Répondre »', async () => {
    const oui = await rendre(tirage({ reponse: 'oui' }))
    expect(oui).toContain('Tu participes')
    expect(oui).not.toContain('data-repondre')

    const non = await rendre(tirage({ reponse: 'non' }))
    expect(non).toContain('Tu ne participes pas')
    expect(non).not.toContain('data-repondre')
  })

  it('tiré, et j’y suis : « Découvrir à qui j’offre » — aucun receveur dans le HTML', async () => {
    const html = await rendre(tirage({ statut: 'tire', reponse: 'oui' }))

    expect(html).toContain('Découvrir à qui j’offre')
    expect(html).toContain('Tiré le')
    expect(html).toContain('Tirage fait · cadeaux dans 66 jours')
    expect(html).not.toContain('Tu offres à')
    expect(html).not.toContain('data-attribution')
  })

  it('tiré sans moi : rien à découvrir', async () => {
    const html = await rendre(tirage({ statut: 'tire', reponse: null }))

    expect(html).toContain('Tu n’es pas dans ce tirage')
    expect(html).not.toContain('Découvrir')
  })

  it('le créateur voit « Vous organisez »', async () => {
    expect(await rendre(tirage({ estCreateur: true, reponse: 'oui' }))).toContain('Vous organisez')
  })
})
