import { describe, expect, it } from 'vitest'
import type { FichePersonneComplete } from '@/lib/api'
import {
  aDesModifications,
  basculerRetrait,
  cleDEnfant,
  cleDeConjoint,
  cleDeParent,
  dateLisible,
  dejaLies,
  demandesDEdition,
  entrerEnEdition,
  gedcomEnFrancais,
  liensAffiches,
  parentsApresEdition,
  recapitulatif,
  retraitsDemandes,
  type EtatEdition,
} from './edition'

/**
 * Le mode édition de la fiche, **sans composant** : ce qui change, comment ça se
 * relit, et en quelles demandes ça part.
 *
 * La fiche d'essai : Marne (17), enfant du couple Galène × Opale (famille 4),
 * sœur de Jaspe ; unie à Cobalt (famille 9), dont elle a deux enfants.
 */

function fiche(surcharge: Partial<FichePersonneComplete> = {}): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id: 17,
    gedcomXref: '@I500017@',
    displayName: 'Marne Sureau',
    givenNames: 'Marne',
    surname: 'Sureau',
    marriedName: null,
    nickname: null,
    sex: 'feminin',
    deceased: true,
    vivant: false,
    birth: { raw: '1894', qualifier: 'annee', min: '1894-01-01', max: '1894-12-31', place: null, texte: '1894' },
    death: null,
    ligneDates: '1894 – ?',
    branch: 'pm',
    generation: -3,
    bestQuay: 2,
    citationCount: 1,
    forcePrivacy: false,
    privacyNote: null,
    version: 8,
    parente: null,
    portrait: null,
    parents: [
      { personId: 30, displayName: 'Galène Bourrache', relation: 'naissance', familyId: 4 },
      { personId: 31, displayName: 'Opale Sureau', relation: 'naissance', familyId: 4 },
    ],
    fratrie: [{ personId: 40, displayName: 'Jaspe Sureau', siblingOrder: 1 }],
    unions: [
      {
        familyId: 9,
        conjoint: { personId: 52, displayName: 'Cobalt Romarin' },
        marriage: null,
        divorced: false,
        divorce: null,
        enfants: [
          { personId: 60, displayName: 'Pivoine Romarin' },
          { personId: 61, displayName: 'Basalte Romarin' },
        ],
      },
    ],
    faits: [],
    citations: [],
    notes: [],
    medias: [],
    enAttente: {},
    demandesEnAttente: [],
    ...surcharge,
  }
}

function etat(f = fiche(), modifier: (e: EtatEdition) => void = () => {}): EtatEdition {
  const e = entrerEnEdition(f)
  modifier(e)
  return e
}

describe('entrer en édition — le cliché du crayon', () => {
  it('part de la fiche validée, sans rien barrer ni ajouter', () => {
    const e = etat()
    expect(e.instantane).toMatchObject({ personId: 17, version: 8 })
    expect(e.saisie.givenNames).toBe('Marne')
    expect(e.retraits).toEqual([])
    expect(e.ajouts).toEqual({ parents: [], conjoints: [], enfants: [] })
    expect(aDesModifications(e)).toBe(false)
  })

  it('liste chaque lien qu’une croix peut retirer — parents, conjoint, enfants — et jamais la fratrie', () => {
    const liens = liensAffiches(fiche())
    expect(liens.map((l) => l.cle)).toEqual([
      'parent:30:4',
      'parent:31:4',
      'conjoint:52:9',
      'enfant:60:9',
      'enfant:61:9',
    ])
    expect(liens.some((l) => l.personId === 40)).toBe(false)
  })

  it('une croix barre, la même croix rétablit', () => {
    const une = basculerRetrait([], 'conjoint:52:9')
    expect(une).toEqual(['conjoint:52:9'])
    expect(basculerRetrait(une, 'conjoint:52:9')).toEqual([])
  })
})

describe('retraitsDemandes — ce que chaque croix envoie', () => {
  it('un parent barré : l’enfant ne perd que lui (`parentId`)', () => {
    expect(retraitsDemandes(fiche(), [cleDeParent(30, 4)])).toEqual([
      { kind: 'enfant_de', personId: 17, familyId: 4, parentId: 30 },
    ])
  })

  it('les deux parents d’un couple barrés se replient en un seul retrait, sans `parentId`', () => {
    expect(retraitsDemandes(fiche(), [cleDeParent(30, 4), cleDeParent(31, 4)])).toEqual([
      { kind: 'enfant_de', personId: 17, familyId: 4 },
    ])
  })

  it('le seul parent d’une famille : le retrait n’a pas besoin de le nommer', () => {
    const seul = fiche({ parents: [{ personId: 30, displayName: 'Galène Bourrache', relation: 'naissance', familyId: 4 }] })
    expect(retraitsDemandes(seul, [cleDeParent(30, 4)])).toEqual([{ kind: 'enfant_de', personId: 17, familyId: 4 }])
  })

  it('un conjoint : il quitte l’union ; un enfant : il quitte le couple', () => {
    expect(retraitsDemandes(fiche(), [cleDeConjoint(52, 9), cleDEnfant(61, 9)])).toEqual([
      { kind: 'conjoint_de', personId: 52, familyId: 9 },
      { kind: 'enfant_de', personId: 61, familyId: 9 },
    ])
  })

  it('une clé inconnue — un lien disparu de la fiche relue — ne part pas', () => {
    expect(retraitsDemandes(fiche(), ['conjoint:999:9'])).toEqual([])
  })
})

describe('le récapitulatif — « Naissance : 1894 → 18 mai 1893 »', () => {
  it('écrit chaque changement comme l’administrateur le lira', () => {
    const e = etat(fiche(), (x) => {
      x.saisie.naissance = { forme: 'connue', debut: '18/05/1893', fin: '', lieu: '', origine: null }
      x.saisie.nickname = 'Mamie'
      x.retraits = [cleDeParent(30, 4), cleDeConjoint(52, 9), cleDEnfant(60, 9)]
      x.ajouts.conjoints = [{ personId: 70, libelle: 'Tourmaline Thym' }]
      x.ajouts.parents = [{ personId: 71, libelle: 'Jargon Thym' }]
      x.ajouts.enfants = [{ personne: { personId: 72, libelle: 'Ambre Romarin' }, familleId: 9 }]
    })

    expect(recapitulatif(fiche(), e).map((c) => c.texte)).toEqual([
      'Surnom : (vide) → Mamie',
      'Naissance : 1894 → 18 mai 1893',
      'Lien retiré : enfant de Galène Bourrache',
      'Lien retiré : conjoint de Cobalt Romarin',
      'Lien retiré : parent de Pivoine Romarin',
      'Lien ajouté : enfant de Jargon Thym',
      'Lien ajouté : conjoint de Tourmaline Thym',
      'Lien ajouté : parent de Ambre Romarin (avec Cobalt Romarin)',
    ])
  })

  it('les deux parents barrés se relisent ensemble', () => {
    const e = etat(fiche(), (x) => {
      x.retraits = [cleDeParent(30, 4), cleDeParent(31, 4)]
    })
    expect(recapitulatif(fiche(), e).map((c) => c.texte)).toEqual([
      'Lien retiré : enfant de Galène Bourrache et Opale Sureau',
    ])
  })

  it('une fourchette reste une fourchette, et le lieu suit la date', () => {
    expect(gedcomEnFrancais('BET 1901 AND 1909')).toBe('entre 1901 et 1909')
    expect(gedcomEnFrancais('ABT 1894')).toBe('vers 1894')
    expect(gedcomEnFrancais('1 MAY 1893')).toBe('1er mai 1893')
    expect(gedcomEnFrancais('BEF DEC 1920')).toBe('avant décembre 1920')
    expect(dateLisible({ raw: '12 MAR 1930', qualifier: 'exacte', min: '1930-03-12', max: '1930-03-12', place: 'Saule' })).toBe(
      '12 mars 1930, Saule',
    )
    expect(dateLisible(null)).toBe('(vide)')
  })
})

describe('demandesDEdition — une demande par nature, dans l’ordre', () => {
  it('champs, puis retraits, puis ajouts ; chacun porte la cible et le message', () => {
    const e = etat(fiche(), (x) => {
      x.saisie.nickname = 'Mamie'
      x.retraits = [cleDeConjoint(52, 9)]
      x.ajouts.parents = [{ personId: 71, libelle: 'Jargon Thym' }]
    })
    const resultat = demandesDEdition(fiche(), e, '  Livret de famille.  ')
    expect(resultat.valide).toBe(true)
    if (!resultat.valide) return

    expect(resultat.corps.map((c) => c.kind)).toEqual(['modification_personne', 'retrait_lien', 'ajout_lien'])
    const [champs, retraits, ajouts] = resultat.corps
    expect(champs).toMatchObject({
      targetId: 17,
      message: 'Livret de famille.',
      ops: [{ field: 'nickname', oldValue: null, newValue: 'Mamie', baseVersion: 8 }],
    })
    expect(retraits).toEqual({
      kind: 'retrait_lien',
      soumettre: true,
      targetKind: 'personne',
      targetId: 17,
      message: 'Livret de famille.',
      retraits: [{ kind: 'conjoint_de', personId: 52, familyId: 9 }],
    })
    expect(ajouts).toMatchObject({
      kind: 'ajout_lien',
      targetId: 17,
      liens: [{ seq: 1, kind: 'enfant_de', subjectPersonId: 17, otherPersonId: 71 }],
    })
  })

  it('rien de changé : aucune demande', () => {
    const resultat = demandesDEdition(fiche(), etat(), '')
    expect(resultat).toEqual({ valide: true, corps: [] })
  })

  it('un enfant ajouté sous l’union rejoint le couple ; sans union, la fiche seule', () => {
    const e = etat(fiche(), (x) => {
      x.ajouts.enfants = [
        { personne: { personId: 72, libelle: 'Ambre' }, familleId: 9 },
        { personne: { personId: 73, libelle: 'Jade' }, familleId: null },
      ]
    })
    const resultat = demandesDEdition(fiche(), e, '')
    if (!resultat.valide) throw new Error('attendu valide')
    expect(resultat.corps).toHaveLength(1)
    expect(resultat.corps[0]).toMatchObject({
      kind: 'ajout_lien',
      liens: [
        { kind: 'enfant_de', subjectPersonId: 72, familyId: 9 },
        { kind: 'parent_de', subjectPersonId: 17, otherPersonId: 73 },
      ],
    })
  })

  it('une saisie invalide bloque tout — on ne dépose pas les liens en laissant les champs', () => {
    const e = etat(fiche(), (x) => {
      x.saisie.deceased = false
      x.saisie.deces = { forme: 'connue', debut: '1960', fin: '', lieu: '', origine: null }
      x.retraits = [cleDeConjoint(52, 9)]
    })
    const resultat = demandesDEdition(fiche(), e, '')
    expect(resultat.valide).toBe(false)
    if (resultat.valide) return
    expect(resultat.erreurs[0]?.champ).toBe('deceased')
    expect(aDesModifications(e)).toBe(true)
  })
})

describe('les garde-fous de la recherche', () => {
  it('deux parents au plus : un barré en libère la place', () => {
    expect(parentsApresEdition(fiche(), etat())).toBe(2)
    expect(parentsApresEdition(fiche(), etat(fiche(), (x) => (x.retraits = [cleDeParent(30, 4)])))).toBe(1)
  })

  it('la fiche et ses proches ne se désignent pas une seconde fois', () => {
    const e = etat(fiche(), (x) => (x.ajouts.conjoints = [{ personId: 70, libelle: 'Tourmaline' }]))
    const exclus = dejaLies(fiche(), e)
    for (const id of [17, 30, 31, 52, 60, 61, 70]) expect(exclus).toContain(`personne:${id}`)
  })
})
