import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { Media } from '@/lib/api'
import CarrouselPortraits from './CarrouselPortraits.vue'

/**
 * Le carrousel des portraits : le portrait en place d'abord, les anciens
 * (`remplace`) ensuite — qui n'apparaissaient nulle part avant le 05/10/2026 —,
 * et « Remettre comme portrait » pour l'administrateur seulement.
 */

function portrait(surcharge: Partial<Media> = {}): Media {
  return {
    id: 120,
    kind: 'portrait',
    subjectKind: 'personne',
    subjectId: 17,
    subjectLabel: 'Marne Dolomite Sureau',
    mimeType: 'image/jpeg',
    byteSize: 84211,
    width: 800,
    height: 800,
    pageCount: null,
    exifStripped: true,
    status: 'actif',
    isActivePortrait: true,
    caption: null,
    taken: null,
    uploadedByLabel: 'Cobalt Romarin',
    createdAt: '2026-10-04T21:04:00+02:00',
    url: '/api/medias/120/fichier',
    vignetteUrl: '/api/medias/120/vignette',
    changeRequestId: 15,
    ...surcharge,
  }
}

function rendre(props: { portraits: Media[]; peutRemettre?: boolean }): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(CarrouselPortraits, props) }))
}

describe('CarrouselPortraits', () => {
  it('un seul portrait : ni flèches ni points', async () => {
    const html = await rendre({ portraits: [portrait()] })

    expect(html).toContain('Portrait actuel')
    expect(html).not.toContain('Portrait suivant')
    expect(html).not.toContain('aria-label="Portrait 1"')
  })

  it('plusieurs portraits : flèches, points, et le portrait en place d’abord', async () => {
    const html = await rendre({
      portraits: [portrait(), portrait({ id: 88, status: 'remplace', isActivePortrait: false })],
    })

    expect(html).toContain('Portrait suivant')
    expect(html).toContain('aria-label="Portrait 2"')
    expect(html).toContain('/api/medias/120/vignette')
    expect(html).toContain('Portraits — 1 sur 2')
  })

  it('un ancien portrait se remet en place — par l’administrateur seulement', async () => {
    const ancien = portrait({ id: 88, status: 'remplace', isActivePortrait: false })

    const admin = await rendre({ portraits: [ancien], peutRemettre: true })
    expect(admin).toContain('Ancien portrait')
    expect(admin).toContain('Remettre comme portrait')

    const membre = await rendre({ portraits: [ancien] })
    expect(membre).toContain('Ancien portrait')
    expect(membre).not.toContain('Remettre comme portrait')

    // Le portrait en place n'a rien à remettre.
    expect(await rendre({ portraits: [portrait()], peutRemettre: true })).not.toContain('Remettre comme portrait')
  })
})
