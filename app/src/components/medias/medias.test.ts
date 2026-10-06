import { describe, expect, it } from 'vitest'
import type { Media } from '@/lib/api'
import {
  estImage,
  fusionnerMedias,
  ordonnerMedias,
  ordonnerPortraits,
  pastilleDeMedia,
  tailleLisible,
  TAILLE_MAX_PAR_DEFAUT,
  verifierAvantEnvoi,
} from './medias'

/**
 * Ce que ces tests protègent :
 *
 *  1. **Un média téléversé n'est pas un média validé** (§ 7.1). Si la pastille
 *     se met à dire la même chose des deux, un contributeur croit son acte
 *     publié alors qu'il attend une décision.
 *  2. **Le type de fichier ne se contrôle pas côté client** (§ 7.1, point 1).
 *     C'est une règle qu'on enfreint par zèle — « autant vérifier tout de
 *     suite » —, et qui refuse alors des fichiers corrects en laissant passer
 *     les autres.
 *  3. **Le plafond de taille du client n'est pas le plafond réel.** Le serveur
 *     décide ; la vérification locale évite un envoi certainement perdu.
 */

function media(surcharge: Partial<Media> = {}): Media {
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
    // **Pas d'`avertissements` ici, et c'est délibéré.** `NormaliseurMedia` ne
    // pose la clé qu'à la création : un `GET /api/medias` n'en porte aucune, pas
    // même vide. Une fixture qui la poserait à la main prouverait seulement que
    // la fixture la pose — c'est ainsi qu'une clé que l'API n'envoie jamais
    // traverse une suite de tests verte.
    ...surcharge,
  }
}

/** Un `File` sans dépendre du disque : `Blob` suffit, et `size` est exact. */
function fichierDe(octets: number, type = 'image/jpeg'): File {
  return new File([new Uint8Array(octets)], 'acte.jpg', { type })
}

/* ------------------------------------------------------------------ */

describe('§ 7.1 — déposé n’est pas validé', () => {
  it('distingue les trois statuts du modèle, sans les traduire en base', () => {
    expect(pastilleDeMedia('en_attente')).toEqual({
      etat: 'en_attente',
      libelle: 'En attente de validation',
    })
    expect(pastilleDeMedia('actif').etat).toBe('validee')
    // Un refus n'est pas une destruction : le média reste, et le dire est le
    // seul moyen pour le déposant de savoir ce qui est arrivé à son envoi.
    expect(pastilleDeMedia('refuse').etat).toBe('refusee')
  })

  it('un ancien portrait se dit « Ancien portrait », pas « Refusé »', () => {
    expect(pastilleDeMedia('remplace')).toEqual({ etat: 'neutre', libelle: 'Ancien portrait' })
  })

  it('montre un statut inconnu plutôt que de le taire', () => {
    const inconnu = pastilleDeMedia('purge' as Media['status'])
    expect(inconnu.etat).toBe('neutre')
    expect(inconnu.libelle).toBe('purge')
  })
})

describe('le contrôle d’entrée du client — la taille, et rien d’autre', () => {
  it('accepte un fichier sous le plafond', () => {
    expect(verifierAvantEnvoi(fichierDe(1024)).accepte).toBe(true)
  })

  it('refuse un fichier au-dessus du plafond, en disant les deux tailles', () => {
    const verdict = verifierAvantEnvoi(fichierDe(TAILLE_MAX_PAR_DEFAUT + 1))
    expect(verdict.accepte).toBe(false)
    if (verdict.accepte) throw new Error('le verdict devait être un refus')
    expect(verdict.message).toContain('20 Mio')
  })

  it('refuse un fichier vide', () => {
    expect(verifierAvantEnvoi(fichierDe(0)).accepte).toBe(false)
  })

  it('n’examine JAMAIS le type annoncé — c’est le serveur qui renifle les octets', () => {
    // § 7.1 : « type réel déterminé par les octets d'en-tête, jamais par
    // l'extension ni par le `Content-Type` annoncé ». `File.type` **est** le
    // `Content-Type` annoncé. Un contrôle local ici refuserait un fichier
    // correct mal étiqueté par le système, et laisserait passer une archive
    // renommée. Ces deux cas doivent donc être traités **de la même façon** :
    // laissés au serveur.
    expect(verifierAvantEnvoi(fichierDe(2048, 'application/x-msdownload')).accepte).toBe(true)
    expect(verifierAvantEnvoi(fichierDe(2048, '')).accepte).toBe(true)
  })

  it('accepte un plafond plus bas quand on le lui donne', () => {
    // Le jour où le plafond réel descend jusqu'au client, il se passe par
    // paramètre — la constante n'est qu'un défaut documenté.
    expect(verifierAvantEnvoi(fichierDe(5000), 4096).accepte).toBe(false)
  })
})

describe('tailleLisible — en multiples binaires, comme le contrat', () => {
  it('rend des octets, des Kio et des Mio', () => {
    expect(tailleLisible(512)).toBe('512 octets')
    expect(tailleLisible(2048)).toBe('2 Kio')
    expect(tailleLisible(20 * 1024 * 1024)).toBe('20 Mio')
  })
})

describe('estImage — un PDF ne se met pas dans une balise <img>', () => {
  it('sépare les images des documents', () => {
    expect(estImage('image/jpeg')).toBe(true)
    expect(estImage('image/png')).toBe(true)
    expect(estImage('application/pdf')).toBe(false)
  })
})

describe('l’ordre de la galerie', () => {
  it('fait remonter ce qui attend une décision, puis le plus récent', () => {
    const liste = [
      media({ id: 1, status: 'actif', createdAt: '2026-01-01T00:00:00+01:00' }),
      media({ id: 2, status: 'refuse', createdAt: '2026-06-01T00:00:00+02:00' }),
      media({ id: 3, status: 'en_attente', createdAt: '2025-01-01T00:00:00+01:00' }),
      media({ id: 4, status: 'actif', createdAt: '2026-09-01T00:00:00+02:00' }),
    ]

    expect(ordonnerMedias(liste).map((m) => m.id)).toEqual([3, 4, 1, 2])
  })

  it('ne modifie pas la liste qu’on lui passe', () => {
    const liste = [media({ id: 1, status: 'actif' }), media({ id: 2, status: 'en_attente' })]
    ordonnerMedias(liste)
    expect(liste.map((m) => m.id)).toEqual([1, 2])
  })
})

describe('fusionnerMedias — deux requêtes, une galerie', () => {
  it('dédoublonne par identifiant si un statut a changé entre les deux appels', () => {
    // La galerie demande `status=actif` puis `status=en_attente` : le contrat ne
    // prévoit pas d'en demander deux d'un coup (§ 7.3). Un média validé entre
    // les deux requêtes figurerait sinon deux fois.
    const valides = [media({ id: 7, status: 'actif' })]
    const attente = [media({ id: 7, status: 'en_attente' }), media({ id: 8, status: 'en_attente' })]

    const fusion = fusionnerMedias(valides, attente)
    expect(fusion).toHaveLength(2)
    expect(fusion.map((m) => m.id).sort()).toEqual([7, 8])
  })
})

describe('ordonnerPortraits — le carrousel', () => {
  it('le portrait en place d’abord, puis l’attente, puis les anciens du plus récent au plus ancien', () => {
    const liste = [
      media({ id: 1, kind: 'portrait', status: 'remplace', createdAt: '2026-01-01T00:00:00+01:00' }),
      media({ id: 2, kind: 'portrait', status: 'actif', isActivePortrait: true, createdAt: '2026-10-04T00:00:00+02:00' }),
      media({ id: 3, kind: 'portrait', status: 'remplace', createdAt: '2026-10-01T00:00:00+02:00' }),
      media({ id: 4, kind: 'portrait', status: 'en_attente' }),
      media({ id: 5, kind: 'preuve', status: 'actif' }),
    ]

    expect(ordonnerPortraits(liste).map((m) => m.id)).toEqual([2, 4, 3, 1])
  })
})
