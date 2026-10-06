import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { GENRES_DE_SOURCE, type SourceAdministration } from '@/lib/api'
import {
  fautesDeSaisie,
  libelleCitations,
  noticeDepuisSaisie,
  saisieDepuisSource,
  saisieVide,
} from './sources'

/**
 * L'écran des sources (ADR 0012) : ce qui part au serveur, et la liste des
 * types, qui ne doit pas dériver de l'énumération PHP.
 */

const SOURCE: SourceAdministration = {
  id: 29,
  gedcomXref: null,
  title: 'État civil de Paris',
  author: 'Archives de Paris',
  publication: null,
  text: null,
  note: null,
  mediaType: null,
  sourceKind: 'acte_etat_civil',
  sourceKindLabel: 'Acte d’état civil',
  citationCount: 3,
  createdAt: '2026-09-30T10:12:00+02:00',
  updatedAt: '2026-09-30T10:12:00+02:00',
}

describe('ce que le formulaire envoie', () => {
  it('rogne, et envoie null pour ce qui est blanc', () => {
    const notice = noticeDepuisSaisie({
      title: '  État civil du Nord ',
      sourceKind: 'registre_officiel',
      author: '   ',
      publication: ' archivesdepartementales.lenord.fr ',
      note: '',
    })

    expect(notice).toEqual({
      title: 'État civil du Nord',
      sourceKind: 'registre_officiel',
      author: null,
      publication: 'archivesdepartementales.lenord.fr',
      note: null,
    })
  })

  it('ne porte jamais gedcomXref : la référence GEDCOM ne se saisit pas', () => {
    expect(Object.keys(noticeDepuisSaisie(saisieDepuisSource({ ...SOURCE, gedcomXref: '@S500011@' })))).not.toContain(
      'gedcomXref',
    )
  })

  it('reprend une source existante sans perdre ses champs', () => {
    expect(noticeDepuisSaisie(saisieDepuisSource(SOURCE))).toEqual({
      title: 'État civil de Paris',
      sourceKind: 'acte_etat_civil',
      author: 'Archives de Paris',
      publication: null,
      note: null,
    })
  })
})

describe('les fautes vues avant l’envoi', () => {
  it('exige un titre', () => {
    expect(fautesDeSaisie(saisieVide())).toHaveProperty('title')
    expect(fautesDeSaisie({ ...saisieVide(), title: '   ' })).toHaveProperty('title')
  })

  it('compte le titre en caractères, comme le serveur', () => {
    expect(fautesDeSaisie({ ...saisieVide(), title: 'é'.repeat(255) })).toEqual({})
    expect(fautesDeSaisie({ ...saisieVide(), title: 'é'.repeat(256) })).toHaveProperty('title')
  })
})

describe('le nombre de citations', () => {
  it('dit « jamais citée », au singulier et au pluriel', () => {
    expect(libelleCitations(0)).toBe('Jamais citée')
    expect(libelleCitations(1)).toBe('1 citation')
    expect(libelleCitations(161)).toBe('161 citations')
  })
})

describe('les types de source', () => {
  it('sont exactement ceux de l’énumération PHP, dans le même ordre', () => {
    const php = readFileSync(
      fileURLToPath(new URL('../../../../api/src/Module/Tree/Enum/SourceKind.php', import.meta.url)),
      'utf8',
    )
    const valeurs = [...php.matchAll(/case \w+ = '([a-z_]+)';/g)].map((m) => m[1])

    expect(valeurs.length).toBeGreaterThan(0)
    expect(GENRES_DE_SOURCE.map((g) => g.valeur)).toEqual(valeurs)
  })
})
