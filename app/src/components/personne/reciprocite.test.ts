import { describe, expect, it } from 'vitest'
import type { BlocDate, FichePersonneComplete } from '@/lib/api'
import { saisieDepuisBloc, sansTexte } from '@/components/proposition/dates'
import {
  construireOps,
  instantaneDeFiche,
  naissanceVerrouillee,
  saisieDepuisFiche,
  valeursDepuisSaisie,
} from '@/components/proposition/operations'
import { NAISSANCE_NON_PARTAGEE, rendreDate } from './champs'
import { aDesModifications, entrerEnEdition } from './edition'

/**
 * La réciprocité des vivants (ADR 0016), côté fiche : une naissance **masquée**
 * — en partie ou en entier — se lit, et se propose à l'aveugle.
 *
 * Ce que ces cas tiennent : un bloc `masquee` s'affiche par son `texte` partiel,
 * « non partagée » quand rien ne l'est (jamais « inconnue » : la date existe) ;
 * il n'entre dans aucun formulaire comme une vraie valeur ; ouvrir puis
 * soumettre la fiche ne produit **aucun** op sur `birth` ; une date saisie en
 * produit un.
 *
 * Noms inventés.
 */

function masquee(texte: string, place: string | null = null): BlocDate {
  return { raw: null, qualifier: 'masquee', min: null, max: null, place, texte }
}

function fiche(surcharge: Partial<FichePersonneComplete> = {}): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id: 71,
    gedcomXref: null,
    displayName: 'Ambre Serpolet',
    givenNames: 'Ambre',
    surname: 'Serpolet',
    marriedName: null,
    nickname: null,
    sex: 'feminin',
    deceased: false,
    vivant: true,
    birth: masquee('mars 1985', 'Saule'),
    death: null,
    naissanceModifiable: false,
    ligneDates: '1985',
    branch: 'pm',
    generation: 0,
    bestQuay: null,
    citationCount: 0,
    forcePrivacy: false,
    privacyNote: null,
    version: 3,
    parente: null,
    portrait: null,
    parents: [],
    fratrie: [],
    unions: [],
    faits: [],
    citations: [],
    notes: [],
    medias: [],
    enAttente: {},
    demandesEnAttente: [],
    ...surcharge,
  }
}

describe('rendreDate — une naissance masquée', () => {
  it('rend le texte partiel du serveur, lieu en second plan', () => {
    expect(rendreDate(masquee('mars 1985', 'Saule'), 'inconnue')).toEqual({
      principal: 'mars 1985',
      secondaire: 'Saule',
      vide: false,
    })
    expect(rendreDate(masquee('12 mars'), 'inconnue').principal).toBe('12 mars')
  })

  it('rien de partagé : « non partagée », jamais le mot des dates inconnues', () => {
    const rendu = rendreDate(masquee(''), 'inconnue')
    expect(rendu.principal).toBe(NAISSANCE_NON_PARTAGEE)
    expect(rendu.principal).not.toBe('inconnue')
    expect(rendu.vide).toBe(true)
  })
})

describe('une naissance masquée n’entre dans aucun formulaire', () => {
  it('le verrou se lit sur le drapeau du serveur, ou sur le bloc lui-même', () => {
    expect(naissanceVerrouillee(fiche())).toBe(true)
    // Sans drapeau (charge antérieure) : le bloc suffit.
    const { naissanceModifiable: _sans, ...sansDrapeau } = fiche()
    expect(naissanceVerrouillee(sansDrapeau)).toBe(true)
    // Drapeau à faux même sur un bloc ordinaire : on suit le serveur.
    expect(
      naissanceVerrouillee(
        fiche({ birth: { raw: '1985', qualifier: 'annee', min: '1985-01-01', max: '1985-12-31', place: null, texte: '1985' } }),
      ),
    ).toBe(true)
    expect(naissanceVerrouillee(fiche({ birth: null, naissanceModifiable: true }))).toBe(false)
  })

  it('la valeur d’op d’un bloc masqué n’affirme rien et ne garde que le lieu', () => {
    expect(sansTexte(masquee('mars 1985', 'Saule'))).toEqual({
      raw: null,
      qualifier: 'inconnue',
      min: null,
      max: null,
      place: 'Saule',
    })
    const saisie = saisieDepuisBloc(masquee('mars 1985'))
    expect(saisie.debut).toBe('')
    expect(saisie.forme).toBe('inconnue')
  })

  it('ouvrir puis soumettre la fiche ne propose rien sur la naissance', () => {
    for (const f of [fiche(), fiche({ birth: masquee('', null) }), fiche({ birth: masquee('12 mars', 'Saule') })]) {
      const conversion = valeursDepuisSaisie(saisieDepuisFiche(f))
      expect(conversion.valeurs).not.toBeNull()
      expect(construireOps(instantaneDeFiche(f), conversion.valeurs!)).toEqual([])
      expect(aDesModifications(entrerEnEdition(f))).toBe(false)
    }
  })
})

describe('une naissance masquée se propose à l’aveugle', () => {
  it('une date saisie produit un op sur `birth`, dont le serveur ignorera l’ancienne valeur', () => {
    const f = fiche()
    const saisie = { ...saisieDepuisFiche(f), naissance: { ...saisieDepuisBloc(f.birth), forme: 'connue' as const, debut: '12/03/1985' } }
    const conversion = valeursDepuisSaisie(saisie)
    expect(conversion.valeurs).not.toBeNull()

    const ops = construireOps(instantaneDeFiche(f), conversion.valeurs!)
    expect(ops).toHaveLength(1)
    expect(ops[0]!.field).toBe('birth')
    expect(ops[0]!.newValue).toMatchObject({ qualifier: 'exacte', min: '1985-03-12', place: 'Saule' })
  })
})
