/**
 * Un tirage de Noël dans la liste « Événements », rendu côté serveur.
 *
 * La ligne mène au site de Noël, dit le jour des cadeaux, le compte à rebours,
 * ma réponse — et **ne nomme jamais de receveur** : elle n'en reçoit pas.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import type { ResumeDuTirageDeNoel } from '@/lib/api'
import LigneNoel from './LigneNoel.vue'

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
    budget: null,
    lieu: null,
    ...partiel,
  }
}

function rendre(t: ResumeDuTirageDeNoel): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(LigneNoel, { tirage: t, aujourdhui: new Date(2026, 9, 19, 12) }) }))
}

describe('LigneNoel', () => {
  it('mène au site de Noël, avec la pastille « Noël », le jour des cadeaux et le compte à rebours', async () => {
    const html = await rendre(tirage())

    expect(html).toContain('href="https://noel2026.naltpom.fr/t/ROMA26"')
    expect(html).toContain('Noël')
    expect(html).toContain('Cadeaux le 24 décembre 2026')
    expect(html).toContain('Tirage dans 43 jours · 6 participants')
    expect(html).toContain('À répondre')
    expect(html).not.toContain('budget')
  })

  it('dit ma réponse, le budget et « Vous organisez »', async () => {
    const html = await rendre(tirage({ reponse: 'oui', estCreateur: true, budget: 25, lieu: 'Auch' }))

    expect(html).toContain('Tu participes')
    expect(html).toContain('Vous organisez')
    expect(html).toContain('Auch · 6 participants · budget 25 €')
    expect(html).not.toContain('À répondre')
  })
})
