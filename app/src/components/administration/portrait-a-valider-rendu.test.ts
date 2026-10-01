import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { PhotoARattacher } from '@/lib/api'
import PortraitAValider from './PortraitAValider.vue'

/**
 * La photo d'inscription sur la ligne d'un compte en attente (écran
 * « Utilisateurs ») — rendue côté serveur.
 *
 * Ce que ces cas tiennent : l'administrateur qui clique « Valider le
 * rattachement » **voit** la photo que la validation va publier comme portrait
 * de la fiche, et la phrase le lui dit en toutes lettres. Les URL sont celles
 * du serveur, jamais reconstruites. Sans photo, rien — pas un cadre vide.
 */

const PHOTO: PhotoARattacher = {
  mediaId: 91,
  url: '/api/medias/91/fichier?v=1a2b3c4d',
  vignetteUrl: '/api/medias/91/vignette?v=1a2b3c4d',
}

async function rendre(photo: PhotoARattacher | null): Promise<string> {
  const app = createSSRApp({ render: () => h(PortraitAValider, { photo, nom: 'Marbre Romarin' }) })
  return renderToString(app)
}

describe('PortraitAValider', () => {
  it('montre la vignette, ouvre la photo en grand, et dit ce que la validation en fera', async () => {
    const html = await rendre(PHOTO)

    expect(html).toContain('data-portrait-en-attente="91"')
    expect(html).toContain('src="/api/medias/91/vignette?v=1a2b3c4d"')
    expect(html).toContain('href="/api/medias/91/fichier?v=1a2b3c4d"')
    expect(html).toContain('deviendra le portrait de sa fiche à la validation')
    expect(html).toContain('alt="Photo déposée par Marbre Romarin à l’inscription"')
  })

  it('ne rend rien sans photo', async () => {
    const html = await rendre(null)

    expect(html).not.toContain('data-portrait-en-attente')
    expect(html).not.toContain('deviendra le portrait')
  })
})
