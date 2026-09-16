import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import type { Media } from '@/lib/api'
import VignetteMedia from './VignetteMedia.vue'

/**
 * Ce fichier existe pour **une** raison, et elle vient d'un défaut trouvé dans ce
 * dépôt le même soir : `types.ts` déclarait `ligneDates` sur un nœud du graphe
 * alors que l'API envoie `etiquette`. Aucune ligne de dates n'aurait été dessinée
 * sur aucune des 377 fiches — et **aucun test ne serait tombé**, parce que les
 * fixtures posaient la clé à la main.
 *
 * > **Une fixture qui pose une clé à la main ne prouve pas que l'API l'envoie.**
 *
 * La charge utile employée ici est donc celle que `Media\Service\NormaliseurMedia`
 * produit **en lecture**, relue dans son code : `avertissements` y est **absente**
 * — la clé n'est posée qu'à la création (`if ([] !== $avertissements)`). Une
 * vignette doit donc se dessiner sans elle. Le contrat la montre dans son exemple
 * du § 7.2, qui est celui d'un `201` : les deux disent vrai, sur deux moments
 * différents, et c'est exactement le genre d'écart qu'on ne voit qu'en lisant le
 * producteur.
 */

/** Telle qu'elle arrive d'un `GET /api/medias` — sans `avertissements`. */
function mediaEnLecture(surcharge: Partial<Media> = {}): Media {
  return {
    id: 120,
    kind: 'preuve',
    subjectKind: 'personne',
    subjectId: 17,
    subjectLabel: 'Marne Dolomite Sureau',
    mimeType: 'image/jpeg',
    byteSize: 842113,
    width: 2400,
    height: 1600,
    pageCount: null,
    exifStripped: true,
    status: 'en_attente',
    isActivePortrait: false,
    caption: 'Acte n° 3, Épinaille 1910',
    taken: null,
    uploadedByLabel: 'Cobalt Romarin',
    createdAt: '2026-09-15T21:04:00+02:00',
    url: '/api/medias/120/fichier',
    vignetteUrl: '/api/medias/120/vignette',
    changeRequestId: 15,
    ...surcharge,
  }
}

/** Les props sont typées plutôt que passées en `Record<string, unknown>` : le
 *  test vérifie ainsi aussi la **forme** attendue par le composant, et un
 *  renommage de prop le fait échouer à la compilation plutôt qu'au rendu. */
function rendre(props: { media: Media; supprimable?: boolean }): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(VignetteMedia, props) }))
}

describe('la vignette se dessine sur la charge réelle, pas sur la fixture', () => {
  it('survit à l’absence d’`avertissements`', async () => {
    const html = await rendre({ media: mediaEnLecture() })

    expect(html).toContain('/api/medias/120/vignette')
    expect(html).toContain('Acte n° 3, Épinaille 1910')
  })

  it('affiche l’avertissement de doublon quand la création l’a posé', async () => {
    // Le doublon est un **avertissement, pas un refus** : le même acte de mariage
    // étaye légitimement les fiches des deux époux et de leurs quatre parents.
    const html = await rendre({
      media: mediaEnLecture({
        avertissements: [
          {
            code: 'doublon_probable',
            message: 'Ce fichier est déjà dans l’arbre, sur la fiche de Jargon Sarriette.',
            mediaId: 88,
          },
        ],
      }),
    })

    expect(html).toContain('déjà dans l’arbre')
  })
})

describe('déposé n’est pas publié — la pastille le dit', () => {
  it('porte « En attente de validation » sur un média non validé', async () => {
    const html = await rendre({ media: mediaEnLecture({ status: 'en_attente' }) })
    expect(html).toContain('En attente de validation')
  })

  it('accorde le libellé au masculin — on parle d’un document', async () => {
    // `PastilleEtat` dit « Validée » par défaut, parce que ses libellés d'origine
    // parlent d'une *demande*. Ici il s'agit d'une preuve, d'un portrait.
    const html = await rendre({ media: mediaEnLecture({ status: 'actif' }) })
    expect(html).toContain('Validé')
    expect(html).not.toContain('Validée')
  })

  it('signale un EXIF non purgé — « une photo non nettoyée publie une adresse »', async () => {
    const html = await rendre({ media: mediaEnLecture({ exifStripped: false }) })
    expect(html).toContain('Métadonnées d’origine non purgées')
  })
})

describe('supprimer est une action réservée', () => {
  it('ne propose rien par défaut', async () => {
    const html = await rendre({ media: mediaEnLecture() })
    expect(html).not.toContain('Supprimer')
  })

  it('propose la suppression à qui la porte, sans `confirm()`', async () => {
    const html = await rendre({ media: mediaEnLecture(), supprimable: true })
    expect(html).toContain('Supprimer')
  })
})
