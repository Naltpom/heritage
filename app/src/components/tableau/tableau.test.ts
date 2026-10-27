import { describe, expect, it } from 'vitest'
import type { LigneDuTableau } from '@/lib/api'
import { CHOIX_VIDE, COLONNES, COLONNES_PAR_DEFAUT, enCsv, filtrer, nomDuFichier, replier, trier, triSuivant, type Colonne, type Filtres } from './tableau'

function ligne(champs: Partial<LigneDuTableau>): LigneDuTableau {
  return {
    personId: 1,
    gedcomXref: null,
    displayName: 'Sans nom',
    surname: '',
    givenNames: '',
    marriedName: null,
    nickname: null,
    sex: 'inconnu',
    vivant: false,
    generation: null,
    branch: null,
    parente: null,
    naissance: null,
    naissanceTri: null,
    lieuNaissance: null,
    deces: null,
    decesTri: null,
    lieuDeces: null,
    professions: [],
    parents: [],
    conjoints: [],
    enfants: 0,
    citationCount: 0,
    bestQuay: null,
    createdAt: '2026-10-01T10:00:00+02:00',
    updatedAt: '2026-10-02T10:00:00+02:00',
    ...champs,
  }
}

const marne = ligne({
  personId: 44,
  displayName: 'Marne Dolomite Sureau',
  sex: 'feminin',
  generation: -3,
  branch: 'pm',
  naissance: '1901-1909',
  naissanceTri: '1901-01-01',
  lieuNaissance: 'Saint-Lô, Manche',
  deces: '1987',
  decesTri: '1987-01-01',
  professions: ['Ménagère'],
  conjoints: [{ personId: 45, displayName: 'Émeri Sarriette' }],
  enfants: 3,
  citationCount: 2,
  bestQuay: 2,
})
const emeri = ligne({ personId: 45, displayName: 'Émeri Sarriette', sex: 'masculin', generation: -3, naissance: '1899', naissanceTri: '1899-01-01', bestQuay: 1 })
const natron = ligne({ personId: 3, displayName: 'Natron Romarin', sex: 'masculin', vivant: true, generation: 0, naissance: '2012', naissanceTri: '2012-01-01' })
const lapis = ligne({ personId: 9, displayName: 'Lapis Fenouil', sex: 'masculin', generation: -8 })
const TOUTES = [marne, emeri, natron, lapis]
const AFFICHEES: Colonne[] = COLONNES.filter((c) => COLONNES_PAR_DEFAUT.includes(c.cle))

function par(colonnes: Record<string, string>, recherche = ''): number[] {
  const filtres: Filtres = { recherche, colonnes }
  return filtrer(TOUTES, filtres, AFFICHEES).map((l) => l.personId)
}

describe('les colonnes', () => {
  it('ont chacune une clé unique', () => {
    const cles = COLONNES.map((c) => c.cle)
    expect(new Set(cles).size).toBe(cles.length)
  })

  it('affichent les valeurs du serveur, sans les recomposer', () => {
    const texte = (cle: string): string | null => COLONNES.find((c) => c.cle === cle)!.texte(marne)
    expect(texte('naissance')).toBe('1901-1909')
    expect(texte('generation')).toBe('−3')
    expect(texte('sexe')).toBe('Femme')
    expect(texte('conjoints')).toBe('Émeri Sarriette')
    expect(texte('verifiee')).toBe('Vérifiée')
    expect(texte('creee')).toBe('01/10/2026')
  })
})

describe('filtrer', () => {
  it('cherche partout, sans accents ni casse, chaque mot dans la ligne', () => {
    expect(par({}, 'emeri')).toEqual([marne.personId, emeri.personId])
    expect(par({}, 'SUREAU saint')).toEqual([marne.personId])
    expect(par({}, 'introuvable')).toEqual([])
  })

  it('lit un filtre de texte comme phpMyAdmin', () => {
    expect(par({ lieuNaissance: 'saint' })).toEqual([marne.personId])
    expect(par({ nom: '=natron romarin' })).toEqual([natron.personId])
    expect(par({ nom: '!r' })).toEqual([lapis.personId])
    expect(par({ lieuNaissance: '=' })).toEqual([emeri.personId, natron.personId, lapis.personId])
    expect(par({ lieuNaissance: '!' })).toEqual([marne.personId])
  })

  it('filtre les dates sur l’année, en comparaison ou en intervalle', () => {
    expect(par({ naissance: '1899' })).toEqual([emeri.personId])
    expect(par({ naissance: '<1950' })).toEqual([marne.personId, emeri.personId])
    expect(par({ naissance: '1900-2020' })).toEqual([marne.personId, natron.personId])
    expect(par({ naissance: '=' })).toEqual([lapis.personId])
  })

  it('filtre les nombres, le moins typographique compris', () => {
    expect(par({ generation: '−3' })).toEqual([marne.personId, emeri.personId])
    expect(par({ generation: '<=-3' })).toEqual([marne.personId, emeri.personId, lapis.personId])
    expect(par({ enfants: '>0' })).toEqual([marne.personId])
  })

  it('filtre les listes à choix, et leurs cases vides', () => {
    expect(par({ sexe: 'masculin' })).toEqual([emeri.personId, natron.personId, lapis.personId])
    expect(par({ etat: 'vivant' })).toEqual([natron.personId])
    expect(par({ verifiee: 'oui' })).toEqual([marne.personId])

    const avecBranche = [...AFFICHEES, COLONNES.find((c) => c.cle === 'branche')!]
    expect(filtrer(TOUTES, { recherche: '', colonnes: { branche: CHOIX_VIDE } }, avecBranche).map((l) => l.personId)).toEqual([
      emeri.personId,
      natron.personId,
      lapis.personId,
    ])
  })

  it('combine les filtres', () => {
    expect(par({ sexe: 'masculin', generation: '-3' })).toEqual([emeri.personId])
  })

  it('ignore le filtre d’une colonne masquée : rien ne retire une ligne sans se voir', () => {
    expect(AFFICHEES.some((c) => c.cle === 'branche')).toBe(false)
    expect(par({ branche: 'pm' })).toEqual(TOUTES.map((l) => l.personId))
  })
})

describe('trier', () => {
  it('passe de croissant à décroissant puis à rien', () => {
    expect(triSuivant(null, 'nom')).toEqual({ cle: 'nom', sens: 'asc' })
    expect(triSuivant({ cle: 'nom', sens: 'asc' }, 'nom')).toEqual({ cle: 'nom', sens: 'desc' })
    expect(triSuivant({ cle: 'nom', sens: 'desc' }, 'nom')).toBeNull()
    expect(triSuivant({ cle: 'nom', sens: 'desc' }, 'deces')).toEqual({ cle: 'deces', sens: 'asc' })
  })

  it('trie les dates sur leur clé, les vides toujours à la fin', () => {
    expect(trier(TOUTES, { cle: 'naissance', sens: 'asc' }).map((l) => l.personId)).toEqual([45, 44, 3, 9])
    expect(trier(TOUTES, { cle: 'naissance', sens: 'desc' }).map((l) => l.personId)).toEqual([3, 44, 45, 9])
  })

  it('trie les noms à la française et les nombres comme des nombres', () => {
    expect(trier(TOUTES, { cle: 'nom', sens: 'asc' }).map((l) => l.displayName)).toEqual([
      'Émeri Sarriette',
      'Lapis Fenouil',
      'Marne Dolomite Sureau',
      'Natron Romarin',
    ])
    expect(trier(TOUTES, { cle: 'generation', sens: 'asc' }).map((l) => l.personId)).toEqual([9, 44, 45, 3])
  })

  it('ne touche pas à l’entrée', () => {
    const avant = [...TOUTES]
    trier(TOUTES, { cle: 'nom', sens: 'desc' })
    expect(TOUTES).toEqual(avant)
  })
})

describe('enCsv', () => {
  const colonnes = COLONNES.filter((c) => ['id', 'nom', 'generation', 'lieuNaissance', 'conjoints'].includes(c.cle))

  it('écrit un CSV qu’un tableur français ouvre en colonnes', () => {
    const csv = enCsv([marne], colonnes)
    expect(csv.startsWith('﻿')).toBe(true)
    expect(csv.slice(1).split('\r\n')).toEqual(['N°;Nom affiché;Lieu de naissance;Génération;Conjoints', '44;Marne Dolomite Sureau;Saint-Lô, Manche;-3;Émeri Sarriette', ''])
  })

  it('protège les guillemets, les points-virgules et les formules', () => {
    const piege = ligne({ personId: 7, displayName: '=HYPERLINK("x")', lieuNaissance: 'Lyon; Rhône' })
    const lignes = enCsv([piege], colonnes).slice(1).split('\r\n')
    expect(lignes[1]).toBe(`7;"'=HYPERLINK(""x"")";"Lyon; Rhône";;`)
  })
})

describe('le reste', () => {
  it('replie les accents', () => {
    expect(replier('  Émeri SARRIETTE ')).toBe('emeri sarriette')
  })

  it('nomme le fichier à la date du jour', () => {
    expect(nomDuFichier(new Date(2026, 9, 28))).toBe('personnes-2026-10-28.csv')
  })
})
