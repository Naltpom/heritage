/**
 * Le regroupement par jour, le hors-grille, l'âge, et les trous de `sansJour`.
 *
 * Ce sont les quatre calculs de l'écran qui se trompent **sans rien casser** :
 * une case qui perd ses événements se peint vide, un 29 février sans case
 * disparaît sans un mot, un âge négatif se peint « −12 ans », et un compteur
 * `sansJour` oublié fait passer une grille lacunaire pour un inventaire.
 *
 * Les noms de ce jeu d'essai sont inventés. La base porte l'arbre réel du
 * commanditaire, et une suite de tests n'est pas un endroit où en recopier des
 * lignes.
 */
import { describe, expect, it } from 'vitest'
import type {
  EvenementAnniversaire,
  GenreEvenement,
  JourAnniversaire,
  SujetEvenement,
  TypeEvenement,
} from '@/lib/api'
import { grilleDuMois } from './calendrier'
import {
  ageALAnniversaire,
  compterSansJour,
  compterSansJourMasques,
  compterSurLaGrille,
  detailSansJour,
  horsGrille,
  parJour,
} from './regroupement'

const JAMAIS = new Date(1970, 0, 1)

function sujet(personId: number, displayName: string): SujetEvenement {
  return { personId, displayName, sex: 'inconnu', vivant: false }
}

function evenement(
  cle: string,
  genre: GenreEvenement,
  annee: number,
  sujets: readonly SujetEvenement[] = [sujet(1, 'Une Personne')],
): EvenementAnniversaire {
  const type = (genre === 'union' ? 'mariage' : genre) as TypeEvenement
  return {
    cle,
    genre,
    type,
    annee,
    texte: `${annee}`,
    lieu: null,
    sujets,
    familyId: genre === 'union' ? 40 : null,
    libelle: null,
  }
}

function jour(mois: number, numero: number, evenements: EvenementAnniversaire[]): JourAnniversaire {
  return { mois, jour: numero, evenements }
}

const TOUS = new Set<GenreEvenement>(['naissance', 'deces', 'union', 'bapteme', 'autre'])
const NAISSANCES = new Set<GenreEvenement>(['naissance'])

const JOURS: JourAnniversaire[] = [
  jour(3, 14, [
    evenement('n-1', 'naissance', 1903),
    evenement('d-1', 'deces', 1871),
    evenement('u-1', 'union', 1930, [sujet(7, 'Premier Époux'), sujet(8, 'Seconde Épouse')]),
  ]),
  jour(3, 1, [evenement('n-2', 'naissance', 1950)]),
  jour(2, 29, [evenement('n-3', 'naissance', 1896), evenement('d-3', 'deces', 1944)]),
  jour(7, 4, [evenement('a-1', 'autre', 1912)]),
]

describe('parJour', () => {
  it('range chaque jour sous sa clé « MM-JJ », complétée à deux chiffres', () => {
    const table = parJour(JOURS, TOUS)
    expect([...table.keys()].sort()).toEqual(['02-29', '03-01', '03-14', '07-04'])
  })

  it('ne retient que les genres allumés', () => {
    const table = parJour(JOURS, NAISSANCES)
    expect(table.get('03-14')).toHaveLength(1)
    expect(table.get('03-14')?.[0]?.genre).toBe('naissance')
  })

  it('n’inscrit aucune clé pour un jour que le filtre a vidé', () => {
    /*
     * LE DÉFAUT TENU. Une clé présente avec un tableau vide et une clé absente
     * sont la même chose, et se traitaient inévitablement de deux façons : la
     * case interrogeait la table, trouvait un tableau, se croyait pourvue, et se
     * peignait cliquable sur rien. Il n'y a donc qu'une forme.
     */
    const table = parJour(JOURS, new Set<GenreEvenement>(['bapteme']))
    expect(table.size).toBe(0)
    expect(table.has('03-14')).toBe(false)
  })

  it('trie les événements d’un jour du plus ancien au plus récent', () => {
    /* Un jour se lit comme une chronique. L'ordre de la charge est celui du
       serveur, qui n'a aucune raison d'être celui-là. */
    expect(parJour(JOURS, TOUS).get('03-14')?.map((e) => e.annee)).toEqual([1871, 1903, 1930])
  })

  it('garde un ordre stable entre deux événements de la même année', () => {
    /* Sans départage, deux rendus successifs peuvent intervertir deux
       événements : la liste bouge sous les yeux à chaque bascule de pastille. */
    const memeAnnee = [
      jour(5, 2, [evenement('z', 'naissance', 1900), evenement('a', 'deces', 1900)]),
    ]
    expect(parJour(memeAnnee, TOUS).get('05-02')?.map((e) => e.cle)).toEqual(['a', 'z'])
  })

  it('fusionne deux entrées du même jour au lieu d’en perdre une', () => {
    /* Le serveur n'en émet qu'une. Une entrée écrasée perdrait des événements
       **en silence**, et l'éviter coûte une ligne. */
    const doublon = [
      jour(6, 9, [evenement('x', 'naissance', 1910)]),
      jour(6, 9, [evenement('y', 'naissance', 1920)]),
    ]
    expect(parJour(doublon, TOUS).get('06-09')).toHaveLength(2)
  })

  it('rend une table vide pour une charge vide, sans rien inventer', () => {
    expect(parJour([], TOUS).size).toBe(0)
  })
})

describe('horsGrille', () => {
  it('liste les 29 février d’une année ordinaire, qui n’ont aucune case', () => {
    /*
     * LE DÉFAUT TENU. Sans cette liste, deux événements disparaissent de l'écran
     * trois années sur quatre, sans un mot — et la seule alternative, les
     * déplacer au 28 février ou au 1er mars, inventerait une date.
     */
    const restants = horsGrille(JOURS, 2027, TOUS)
    expect(restants).toHaveLength(2)
    expect(restants.map((e) => e.cle).sort()).toEqual(['d-3', 'n-3'])
  })

  it('ne liste rien en année bissextile : le 29 février a sa case', () => {
    expect(horsGrille(JOURS, 2028, TOUS)).toEqual([])
  })

  it('applique les mêmes filtres que la grille', () => {
    expect(horsGrille(JOURS, 2027, NAISSANCES).map((e) => e.cle)).toEqual(['n-3'])
  })

  it('rend une liste vide, et non `undefined`, quand aucun 29 février n’existe', () => {
    expect(horsGrille([jour(3, 14, [evenement('n', 'naissance', 1900)])], 2027, TOUS)).toEqual([])
  })
})

describe('ageALAnniversaire', () => {
  it('donne l’écart d’années à l’anniversaire de l’année affichée', () => {
    expect(ageALAnniversaire(1903, 2026)).toBe(123)
  })

  it('rend zéro l’année même de l’événement', () => {
    expect(ageALAnniversaire(2026, 2026)).toBe(0)
  })

  it('rend un nombre négatif en remontant avant l’événement — sans le masquer', () => {
    /*
     * LE DÉFAUT TENU. Un `null` ici obligerait chaque appelant à retrouver
     * pourquoi, et la première branche oubliée peindrait « −12 ans ». Une clé,
     * une formule, aucune branche : c'est le gabarit qui choisit de ne rien
     * peindre sous 1.
     */
    expect(ageALAnniversaire(1902, 1890)).toBe(-12)
  })
})

describe('compterSurLaGrille', () => {
  const grille = grilleDuMois(2026, 3, JAMAIS)

  it('compte ce que le mois affiché porte réellement', () => {
    expect(compterSurLaGrille(grille, parJour(JOURS, TOUS))).toBe(4)
  })

  it('ignore les jours empruntés aux mois voisins', () => {
    /*
     * LE DÉFAUT TENU. Les six cases de tête de mars 2026 sont des jours de
     * février. Les compter ferait passer pour pourvu un mois entièrement vide
     * dont le voisin porte quelque chose, et l'état vide explicite ne
     * paraîtrait jamais — l'écran montrerait 42 cases muettes sans un mot.
     */
    const fevrierSeul = [jour(2, 24, [evenement('v', 'naissance', 1900)])]
    expect(compterSurLaGrille(grille, parJour(fevrierSeul, TOUS))).toBe(0)
  })

  it('rend zéro sur une table vide', () => {
    expect(compterSurLaGrille(grille, new Map())).toBe(0)
  })
})

describe('les événements sans jour connu', () => {
  const SANS_JOUR = {
    naissance: 166,
    deces: 42,
    union: 3,
    bapteme: 0,
    autre: 7,
  } as const

  it('compte ce que les pastilles allumées cachent encore', () => {
    expect(compterSansJour(SANS_JOUR, NAISSANCES)).toBe(166)
    expect(compterSansJour(SANS_JOUR, TOUS)).toBe(218)
  })

  it('rend zéro quand la charge n’est pas arrivée, sans supposer un total', () => {
    expect(compterSansJour(null, TOUS)).toBe(0)
    expect(detailSansJour(null, TOUS)).toEqual([])
    expect(compterSansJourMasques(null, TOUS)).toBe(0)
  })

  it('détaille dans l’ordre des pastilles, et non dans celui des clics', () => {
    /*
     * LE DÉFAUT TENU. Un `Set` conserve l'ordre d'insertion : le détail
     * changerait de rangée selon l'ordre où le lecteur a allumé ses pastilles.
     * L'ordre vient donc de `GENRES`.
     */
    const dansLeDesordre = new Set<GenreEvenement>(['autre', 'naissance', 'deces'])
    expect(detailSansJour(SANS_JOUR, dansLeDesordre).map((ligne) => ligne.genre)).toEqual([
      'naissance',
      'deces',
      'autre',
    ])
  })

  it('n’écrit pas une ligne « 0 » pour un genre qui n’a aucun trou', () => {
    expect(detailSansJour(SANS_JOUR, TOUS).map((ligne) => ligne.genre)).not.toContain('bapteme')
  })

  it('annonce à part ce que les pastilles éteintes retiennent, pour que l’addition tombe juste', () => {
    /*
     * LE DÉFAUT TENU. La ligne de tête compte les genres allumés — c'est le
     * compagnon de ce qui est à l'écran. Fondre les genres éteints dedans
     * ferait mentir l'addition du détail ; les taire laisserait croire qu'il n'y
     * a rien d'autre.
     */
    const detail = detailSansJour(SANS_JOUR, NAISSANCES)
    expect(detail.reduce((somme, ligne) => somme + ligne.compte, 0)).toBe(
      compterSansJour(SANS_JOUR, NAISSANCES),
    )
    expect(compterSansJourMasques(SANS_JOUR, NAISSANCES)).toBe(52)
  })

  it('ne masque plus rien quand tout est allumé', () => {
    expect(compterSansJourMasques(SANS_JOUR, TOUS)).toBe(0)
  })
})

describe('une naissance sans année partagée (ADR 0016)', () => {
  it('reste sur son jour, après les événements datés, dans un ordre stable', () => {
    const { annee: _sans, ...sansAnnee } = evenement('n:b', 'naissance', 0)
    const sansAnnee2 = { ...sansAnnee, cle: 'n:a' }
    const table = parJour(
      [jour(3, 12, [sansAnnee, evenement('n:c', 'naissance', 1950), sansAnnee2, evenement('n:d', 'naissance', 1890)])],
      new Set<GenreEvenement>(['naissance']),
    )
    expect(table.get('03-12')?.map((e) => e.cle)).toEqual(['n:d', 'n:c', 'n:a', 'n:b'])
  })
})
