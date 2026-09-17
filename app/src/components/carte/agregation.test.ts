/**
 * Le regroupement par lieu.
 *
 * Aucun Leaflet ici, et c'est le but : tout ce qui se décide sur une bulle se
 * décide dans `agregation.ts`, que l'environnement `node` de vitest sait
 * charger. `CarteLeaflet.vue`, lui, ne sera jamais testé unitairement.
 */
import { describe, expect, it } from 'vitest'
import type { EvenementCarte, GenreEvenement, LieuCarte, VueCarte } from '@/lib/api'
import {
  bornesDesMedianes,
  cadrageInitial,
  maximum,
  mediane,
  ordreDeTrace,
  regrouperParLieu,
} from './agregation'

function lieu(placeId: number, libelle: string, brut: string[] = [libelle]): LieuCarte {
  return {
    placeId,
    libelle,
    lat: 45 + placeId / 10,
    lon: 1 + placeId / 10,
    precision: 'commune',
    confiance: 100,
    source: 'communes-fr',
    brut,
  }
}

function evenement(
  cle: string,
  placeId: number | null,
  annee: number,
  genre: GenreEvenement = 'naissance',
  place = 'Saule, Ombrage, Sylvanie',
): EvenementCarte {
  return {
    cle,
    genre,
    type: genre === 'union' ? 'mariage' : 'naissance',
    placeId,
    place,
    annee,
    texte: `14 mars ${annee}`,
    qualifier: 'exacte',
    certain: true,
    sujets: [{ personId: 1, displayName: 'Marne Sureau', sex: 'feminin', vivant: false }],
    familyId: null,
    libelle: null,
  }
}

function vueDe(lieux: LieuCarte[], evenements: EvenementCarte[]): VueCarte {
  return {
    lieux,
    evenements,
    resume: {
      parGenre: { naissance: 0, deces: 0, union: 0, bapteme: 0, autre: 0 },
      sansLieu: 0,
      lieuNonResolu: 0,
      sansDate: 0,
      bornes: { min: 1657, max: 1994 },
    },
  }
}

const TOUS = new Set<GenreEvenement>(['naissance', 'deces', 'union', 'bapteme', 'autre'])

describe('une bulle par lieu situé, et pas une par graphie', () => {
  it('regroupe sur l’identifiant du serveur, jamais sur la chaîne écrite', () => {
    /*
     * Deux graphies d'une même commune doivent s'effondrer sur UNE bulle. C'est
     * le serveur qui a fait ce rapprochement, en base, avec le gazetteer.
     * Rapprocher des chaînes ici en ferait un second endroit qui décide, et il
     * dessinerait deux cercles distants d'un mètre.
     */
    const { bulles } = regrouperParLieu(
      vueDe(
        [lieu(17, 'Saule', ['Saule, Ombrage, Sylvanie', 'SAULE (Ombrage)'])],
        [
          evenement('a', 17, 1887, 'naissance', 'Saule, Ombrage, Sylvanie'),
          evenement('b', 17, 1901, 'naissance', 'SAULE (Ombrage)'),
        ],
      ),
      TOUS,
    )

    expect(bulles).toHaveLength(1)
    expect(bulles[0]?.total).toBe(2)
  })

  it('garde les graphies brutes, pour qu’un mauvais géocodage se voie', () => {
    const { bulles } = regrouperParLieu(
      vueDe([lieu(17, 'Saule', ['Saule, Ombrage', 'Sole, Ombrage'])], [evenement('a', 17, 1887)]),
      TOUS,
    )

    expect(bulles[0]?.brut).toEqual(['Saule, Ombrage', 'Sole, Ombrage'])
  })

  it('compte par genre à l’intérieur de chaque bulle', () => {
    const { bulles } = regrouperParLieu(
      vueDe(
        [lieu(17, 'Saule')],
        [
          evenement('a', 17, 1887, 'naissance'),
          evenement('b', 17, 1901, 'naissance'),
          evenement('c', 17, 1934, 'union'),
        ],
      ),
      TOUS,
    )

    expect(bulles[0]?.parGenre).toEqual({ naissance: 2, union: 1 })
  })
})

describe('les filtres du lecteur', () => {
  it('ne retiennent que les genres allumés', () => {
    const { bulles, retenus } = regrouperParLieu(
      vueDe(
        [lieu(17, 'Saule')],
        [evenement('a', 17, 1887, 'naissance'), evenement('b', 17, 1934, 'union')],
      ),
      new Set<GenreEvenement>(['naissance']),
    )

    expect(retenus).toBe(1)
    expect(bulles[0]?.total).toBe(1)
  })

  it('font disparaître une bulle dont plus rien n’est retenu', () => {
    const { bulles } = regrouperParLieu(
      vueDe([lieu(17, 'Saule')], [evenement('a', 17, 1934, 'union')]),
      new Set<GenreEvenement>(['naissance']),
    )

    expect(bulles).toHaveLength(0)
  })
})

describe('les lieux non situés se comptent, ils ne disparaissent pas', () => {
  it('met à part un événement sans identifiant de lieu, avec sa graphie', () => {
    /* Les faire disparaître serait mentir par omission — et c'est le seul
       chiffre qui dise s'il vaut la peine de rejouer la résolution des lieux. */
    const { bulles, nonSitues } = regrouperParLieu(
      vueDe([lieu(17, 'Saule')], [evenement('a', null, 1887, 'naissance', 'Kertiou, Finistère')]),
      TOUS,
    )

    expect(bulles).toHaveLength(0)
    expect(nonSitues).toHaveLength(1)
    expect(nonSitues[0]?.place).toBe('Kertiou, Finistère')
  })

  it('met aussi à part un identifiant que la table des lieux ne connaît pas', () => {
    /* Fail-closed : une bulle à des coordonnées inventées vaut moins qu'un
       compteur honnête. */
    const { bulles, nonSitues } = regrouperParLieu(
      vueDe([lieu(17, 'Saule')], [evenement('a', 99, 1887)]),
      TOUS,
    )

    expect(bulles).toHaveLength(0)
    expect(nonSitues).toHaveLength(1)
  })
})

describe('la médiane', () => {
  it('est la valeur basse quand le compte est pair, et n’interpole pas', () => {
    /*
     * Interpoler entre 1743 et 1961 donnerait 1852 — une année où il ne s'est
     * rien passé à cet endroit. Une médiane doit être une valeur observée ;
     * c'est ce qui la distingue d'une moyenne, et pourquoi on l'a choisie.
     */
    expect(mediane([1743, 1961])).toBe(1743)
    expect(mediane([1700, 1800, 1900, 2000])).toBe(1800)
  })

  it('est la valeur centrale quand le compte est impair', () => {
    expect(mediane([1700, 1850, 2000])).toBe(1850)
  })

  it('résiste à une valeur extrême, là où une moyenne céderait', () => {
    /* Une naissance de 2014 ne doit pas tirer un hameau du XVIIIᵉ vers
       l'extrémité moderne du dégradé. La moyenne vaudrait ici 1800. */
    const annees = [1740, 1750, 1760, 2014].sort((a, b) => a - b)
    expect(mediane(annees)).toBe(1750)
  })

  it('rend zéro sur une liste vide plutôt que NaN', () => {
    expect(mediane([])).toBe(0)
  })
})

describe('l’ordre de tracé rend les petites bulles cliquables', () => {
  it('pose les grandes d’abord et les petites au-dessus', () => {
    /*
     * LE DÉTAIL QUI DÉCIDE SI LA CARTE EST UTILISABLE. Leaflet dessine dans
     * l'ordre d'insertion : la dernière posée est au-dessus, et c'est elle qui
     * reçoit le clic. L'ordre inverse enterre le village sous la ville, et rien
     * ne le signale.
     */
    const bulles = ordreDeTrace([
      { ...gabarit(1), total: 2 },
      { ...gabarit(2), total: 40 },
      { ...gabarit(3), total: 9 },
    ])

    expect(bulles.map((b) => b.total)).toEqual([40, 9, 2])
  })

  it('départage à total égal, pour que deux chargements donnent le même écran', () => {
    const bulles = ordreDeTrace([
      { ...gabarit(7), total: 3 },
      { ...gabarit(2), total: 3 },
    ])

    expect(bulles.map((b) => b.placeId)).toEqual([2, 7])
  })
})

describe('les bornes et le cadrage', () => {
  it('prend les bornes sur les médianes, pas sur les extrêmes de chaque lieu', () => {
    /* Sinon l'échelle s'étale sur des valeurs qu'aucune bulle ne porte, et la
       moitié du dégradé ne sert jamais. */
    const bornes = bornesDesMedianes([
      { ...gabarit(1), anneeMediane: 1800 },
      { ...gabarit(2), anneeMediane: 1900 },
    ])

    expect(bornes).toEqual({ min: 1800, max: 1900 })
  })

  it('rend null plutôt qu’une boîte vide quand aucun lieu n’est situé', () => {
    /* Une boîte vide, Leaflet la lit comme le point (0, 0) — au large du golfe
       de Guinée, à 5 000 km de tout ce que cet arbre contient. */
    expect(cadrageInitial([])).toBeNull()
    expect(bornesDesMedianes([])).toBeNull()
  })

  it('englobe tous les lieux situés', () => {
    const boite = cadrageInitial([
      { ...gabarit(1), lat: 43, lon: -1 },
      { ...gabarit(2), lat: 50, lon: 7 },
    ])

    expect(boite).toEqual([
      [43, -1],
      [50, 7],
    ])
  })

  it('rend zéro comme maximum quand il n’y a aucune bulle, sans diviser', () => {
    expect(maximum([])).toBe(0)
  })
})

function gabarit(placeId: number) {
  return {
    placeId,
    libelle: `Lieu ${placeId}`,
    lat: 45,
    lon: 1,
    confiance: 100,
    source: 'communes-fr',
    brut: [] as readonly string[],
    total: 1,
    parGenre: {},
    anneeMin: 1800,
    anneeMax: 1900,
    anneeMediane: 1850,
    evenements: [] as readonly EvenementCarte[],
  }
}
