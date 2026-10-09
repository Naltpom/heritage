import { describe, expect, it } from 'vitest'
import type { EnvoiMedia, Media } from '@/lib/api'
import type { PhotoAJoindre } from './PhotosAJoindre.vue'
import { envoyerLesPhotos, photosAEnvoyer } from './photos'

/**
 * Les photos d'une personne proposée partent **après** sa demande, sur une fiche
 * proposée de cette demande (`p1` par défaut), avec leur description. Ce qui est
 * passé ne repart jamais, et un refus de taille ne se rejoue pas.
 */

function photo(cle: number, champs: Partial<PhotoAJoindre> = {}): PhotoAJoindre {
  return {
    cle,
    fichier: new File(['x'], `photo-${cle}.jpg`, { type: 'image/jpeg' }),
    apercu: null,
    description: '',
    etat: 'attente',
    message: null,
    rejouable: true,
    ...champs,
  }
}

const MEDIA = { id: 1 } as Media

describe('envoyerLesPhotos', () => {
  it('vise la fiche proposée de la demande, avec la description', async () => {
    const envois: EnvoiMedia[] = []
    const photos = [photo(1, { description: '  Mariage, 1952  ' }), photo(2)]

    const echecs = await envoyerLesPhotos(photos, 42, async (envoi) => {
      envois.push(envoi)
      return MEDIA
    })

    expect(echecs).toBe(0)
    expect(envois).toHaveLength(2)
    expect(envois[0]).toMatchObject({
      kind: 'preuve',
      subjectKind: 'personne',
      brouillon: { demandeId: 42, brouillonRef: 'p1' },
      caption: 'Mariage, 1952',
    })
    // Une description vide n'envoie pas de légende vide.
    expect(envois[1]).not.toHaveProperty('caption')
    expect(photos.map((p) => p.etat)).toEqual(['reussie', 'reussie'])
  })

  it('vise la fiche qu’on lui nomme, quand ce n’est pas la première', async () => {
    // Compléter une proposition de l'assistant : la fiche complétée peut être `p3`.
    const envois: EnvoiMedia[] = []

    await envoyerLesPhotos([photo(1)], 42, async (envoi) => {
      envois.push(envoi)
      return MEDIA
    }, 'p3')

    expect(envois.map((e) => e.brouillon)).toEqual([{ demandeId: 42, brouillonRef: 'p3' }])
  })

  it('un échec n’arrête pas les suivantes, et seul l’échec repart ensuite', async () => {
    const photos = [photo(1), photo(2), photo(3)]
    let appel = 0

    const echecs = await envoyerLesPhotos(photos, 7, async () => {
      appel++
      if (appel === 2) throw new Error('réseau')
      return MEDIA
    })

    expect(echecs).toBe(1)
    expect(photos.map((p) => p.etat)).toEqual(['reussie', 'echec', 'reussie'])
    expect(photos[1]!.message).not.toBeNull()
    expect(photosAEnvoyer(photos).map((p) => p.cle)).toEqual([2])

    const renvoyees: string[] = []
    await envoyerLesPhotos(photos, 7, async (envoi) => {
      renvoyees.push(envoi.fichier.name)
      return MEDIA
    })
    expect(renvoyees).toEqual(['photo-2.jpg'])
  })

  it('ne renvoie pas une photo refusée pour sa taille', () => {
    const trop = photo(1, { etat: 'echec', message: 'Trop lourd.', rejouable: false })
    expect(photosAEnvoyer([trop])).toEqual([])
  })
})
