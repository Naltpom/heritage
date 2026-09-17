import { describe, expect, it } from 'vitest'
import { erreursParChamp, unionVide } from './creation'
import { saisieVide, type SaisieDate } from './dates'
import {
  corpsAjoutLien,
  dejaDesignes,
  liensVides,
  nombreDeLiensProposes,
  type SaisieLiens,
} from './liens'

/**
 * Ce que ces tests protègent : **la parenté d'une fiche existante se propose**,
 * et chaque geste écrit le lien qu'il faut.
 *
 * Le défaut d'origine, dans les mots du commanditaire : « quand je demande une
 * modification je peux pas ajouter les parents les frères et sœur ni les
 * enfants ». Il était exact, et la cause n'était pas dans l'API — `ajout_lien`
 * existait, avec son constructeur et son applicateur — mais dans l'absence
 * d'écran.
 *
 * ── Le piège que ces tests visent en priorité ────────────────────────────────
 *
 * **Les trois natures de lien se lisent du sujet vers l'autre**, et le sujet
 * n'est pas toujours la fiche d'où l'on vient :
 *
 * - « ajouter un parent à X » → `enfant_de`, sujet **X** ;
 * - « ajouter un frère à X » → `enfant_de`, sujet **le frère**, `familyId` la
 *   famille d'origine de X.
 *
 * Inverser l'un des deux produit un arbre à l'envers, et **aucun type ne s'en
 * plaint** : les deux liens ont exactement la même forme. C'est le défaut qui
 * avait déjà été payé une fois sur la table de libellés de l'administration —
 * « l'arbre à l'envers, sur l'écran même où l'on décide ».
 */

const PERE = { personId: 61, libelle: 'Argile Muguet' }
const MERE = { personId: 62, libelle: 'Sanguine Verveine' }
const FRERE = { personId: 64, libelle: 'Cobalt Muguet' }
const CONJOINT = { personId: 55, libelle: 'Jargon Sarriette' }
const ENFANT = { personId: 63, libelle: 'Sodalite Sarriette' }
/** Une personne **proposée** par une autre demande, non encore validée. */
const PROPOSEE = { draftPersonId: 7, demandeId: 12, libelle: 'Barnabé Muguet' }

/** La fiche d'où l'on vient. */
const FICHE = 17
/** Sa famille d'origine, et l'une de ses unions. */
const FAMILLE_DORIGINE = 31
const UNION = 42

function date(modifications: Partial<SaisieDate>): SaisieDate {
  return { ...saisieVide(), ...modifications }
}

function saisie(): SaisieLiens {
  return liensVides(FICHE)
}

function deposer(etat: SaisieLiens, soumettre = true) {
  return corpsAjoutLien(etat, { message: '', soumettre })
}

describe('la nature et la cible de la demande', () => {
  it('dépose une demande `ajout_lien` qui annonce la fiche d’où l’on vient', () => {
    // `targetId` n'est pas décoratif : sans lui, le serveur prend le sujet du
    // premier lien, et « ajouter un frère » a pour sujet le frère. La demande
    // apparaîtrait alors dans la file sur la fiche du frère.
    const etat = saisie()
    etat.fratrie = [{ personne: FRERE, familleId: FAMILLE_DORIGINE, rangFratrie: null }]

    const resultat = deposer(etat)

    expect(resultat.valide).toBe(true)
    if (!resultat.valide) return
    expect(resultat.corps.kind).toBe('ajout_lien')
    expect(resultat.corps.targetKind).toBe('personne')
    expect(resultat.corps.targetId).toBe(FICHE)
    // Elle ne crée **aucune fiche** : un `brouillons` ici serait refusé en 422.
    expect(resultat.corps).not.toHaveProperty('brouillons')
  })

  it('refuse de soumettre une demande sans aucun lien, et accepte de l’enregistrer', () => {
    // Même règle que la création : on enregistre un brouillon incomplet un soir,
    // on le complète le lendemain.
    const refus = deposer(saisie())
    expect(refus.valide).toBe(false)
    if (refus.valide) return
    expect(erreursParChamp(refus.erreurs)['liens']).toMatch(/au moins un lien/i)

    expect(deposer(saisie(), false).valide).toBe(true)
  })
})

describe('les parents — un seul lien, un ou deux parents', () => {
  it('écrit « la fiche est enfant de ces parents », les deux sur le même lien', () => {
    // Deux parents ne font **pas** deux liens : `other2PersonId` est le second
    // parent du même lien, et c'est ce qui fait qu'un seul couple est déduit.
    const etat = saisie()
    etat.parents = [PERE, MERE]

    const resultat = deposer(etat)
    expect(resultat.valide).toBe(true)
    if (!resultat.valide) return

    expect(resultat.corps.liens).toEqual([
      {
        seq: 1,
        kind: 'enfant_de',
        subjectPersonId: FICHE,
        otherPersonId: PERE.personId,
        other2PersonId: MERE.personId,
      },
    ])
  })

  it('refuse deux fois la même personne et plus de deux parents', () => {
    const memeDeuxFois = saisie()
    memeDeuxFois.parents = [PERE, { ...PERE }]
    const refusDouble = deposer(memeDeuxFois)
    expect(refusDouble.valide).toBe(false)
    if (refusDouble.valide) return
    expect(erreursParChamp(refusDouble.erreurs)['parents']).toMatch(/la même personne/i)

    const trois = saisie()
    trois.parents = [PERE, MERE, FRERE]
    expect(deposer(trois).valide).toBe(false)
  })
})

describe('la fratrie — enfant du même couple, et le sujet est le frère', () => {
  it('fait du frère le sujet et désigne la famille d’origine', () => {
    // **Le test central de ce fichier.** Si le sujet était la fiche, on
    // rattacherait la fiche une seconde fois à ses propres parents, et le frère
    // ne serait rattaché à rien — sans qu'aucun type ne le signale.
    const etat = saisie()
    etat.fratrie = [{ personne: FRERE, familleId: FAMILLE_DORIGINE, rangFratrie: 2 }]

    const resultat = deposer(etat)
    expect(resultat.valide).toBe(true)
    if (!resultat.valide) return

    expect(resultat.corps.liens).toEqual([
      {
        seq: 1,
        kind: 'enfant_de',
        subjectPersonId: FRERE.personId,
        familyId: FAMILLE_DORIGINE,
        siblingOrder: 2,
      },
    ])
  })

  it('n’envoie pas de rang quand il est inconnu', () => {
    // Un rang absent veut dire « à la fin » ; un zéro voudrait dire « l'aîné ».
    // La nuance a un sens généalogique, et `siblingOrder: 0` la détruirait.
    const etat = saisie()
    etat.fratrie = [{ personne: FRERE, familleId: FAMILLE_DORIGINE, rangFratrie: null }]

    const resultat = deposer(etat)
    if (!resultat.valide) throw new Error('corps attendu')
    expect(resultat.corps.liens[0]).not.toHaveProperty('siblingOrder')
  })
})

describe('les enfants — au couple, ou au parent seul', () => {
  it('rattache au couple désigné quand une union est choisie', () => {
    // La forme qui compte : `enfant_de` + `familyId`, sujet l'enfant. Passer par
    // `parent_de` alors qu'une union existe créerait une **seconde** famille
    // pour le même couple, et le graphe montrerait deux losanges.
    const etat = saisie()
    etat.enfants = [{ personne: ENFANT, familleId: UNION, rangFratrie: null }]

    const resultat = deposer(etat)
    if (!resultat.valide) throw new Error('corps attendu')

    expect(resultat.corps.liens).toEqual([
      { seq: 1, kind: 'enfant_de', subjectPersonId: ENFANT.personId, familyId: UNION },
    ])
  })

  it('écrit « parent de » quand l’autre parent est inconnu', () => {
    // Ce n'est pas un défaut de saisie : trois familles réelles de cet arbre
    // n'ont qu'un époux. La validation créera une famille à un parent.
    const etat = saisie()
    etat.enfants = [{ personne: ENFANT, familleId: null, rangFratrie: 0 }]

    const resultat = deposer(etat)
    if (!resultat.valide) throw new Error('corps attendu')

    expect(resultat.corps.liens).toEqual([
      {
        seq: 1,
        kind: 'parent_de',
        subjectPersonId: FICHE,
        otherPersonId: ENFANT.personId,
        siblingOrder: 0,
      },
    ])
  })
})

describe('les unions', () => {
  it('porte les dates sur le lien, et rien d’autre que sur une union', () => {
    const etat = saisie()
    const union = unionVide(CONJOINT)
    union.mariage = date({ forme: 'connue', debut: '23/09/1927' })
    union.rompue = true
    union.rupture = date({ forme: 'connue', debut: '1943' })
    etat.unions = [union]

    const resultat = deposer(etat)
    if (!resultat.valide) throw new Error('corps attendu')

    const lien = resultat.corps.liens[0]!
    expect(lien.kind).toBe('conjoint_de')
    expect(lien.subjectPersonId).toBe(FICHE)
    expect(lien.otherPersonId).toBe(CONJOINT.personId)
    expect(lien.divorced).toBe(true)
    expect(lien.marriage?.qualifier).toBe('exacte')
    expect(lien.divorce?.qualifier).toBe('annee')
  })

  it('ne devine pas une rupture à partir d’une date', () => {
    // Le lecteur a pu saisir la date avant de cocher. On le lui dit plutôt que
    // de trancher à sa place : c'est un fait d'état civil.
    const etat = saisie()
    const union = unionVide(CONJOINT)
    union.rupture = date({ forme: 'connue', debut: '1943' })
    etat.unions = [union]

    const resultat = deposer(etat)
    expect(resultat.valide).toBe(false)
    if (resultat.valide) return
    expect(erreursParChamp(resultat.erreurs)['unions.0.rupture']).toMatch(/cochez/i)
  })
})

describe('une extrémité peut être une personne proposée', () => {
  it('envoie `otherDraftPersonId` pour un parent encore en attente', () => {
    // C'est la première moitié du besoin : « le père que j'ai proposé juste
    // avant » doit pouvoir être désigné sans attendre sa validation.
    const etat = saisie()
    etat.parents = [PROPOSEE]

    const resultat = deposer(etat)
    if (!resultat.valide) throw new Error('corps attendu')

    expect(resultat.corps.liens[0]).toEqual({
      seq: 1,
      kind: 'enfant_de',
      subjectPersonId: FICHE,
      otherDraftPersonId: PROPOSEE.draftPersonId,
    })
    // Et **jamais** les deux clés à la fois : c'est un `CHECK` en base.
    expect(resultat.corps.liens[0]).not.toHaveProperty('otherPersonId')
  })

  it('en fait le sujet quand c’est un frère ou une sœur', () => {
    const etat = saisie()
    etat.fratrie = [{ personne: PROPOSEE, familleId: FAMILLE_DORIGINE, rangFratrie: null }]

    const resultat = deposer(etat)
    if (!resultat.valide) throw new Error('corps attendu')

    expect(resultat.corps.liens[0]).toEqual({
      seq: 1,
      kind: 'enfant_de',
      subjectDraftPersonId: PROPOSEE.draftPersonId,
      familyId: FAMILLE_DORIGINE,
    })
  })

  it('distingue une personne n° 7 de l’arbre d’une fiche proposée n° 7', () => {
    // Une clé commune éteindrait le bouton de l'une parce que l'autre est déjà
    // dans le formulaire — deux personnes différentes, deux clés.
    const etat = saisie()
    etat.parents = [{ personId: 7, libelle: 'Une personne de l’arbre' }]
    etat.enfants = [
      { personne: { draftPersonId: 7, demandeId: 12, libelle: 'Une proposée' }, familleId: null, rangFratrie: null },
    ]

    expect(dejaDesignes(etat)).toEqual(['personne:17', 'personne:7', 'brouillon:7'])
  })
})

describe('les rangs et les comptes', () => {
  it('numérote les liens dans l’ordre de lecture de l’écran', () => {
    // L'ordre n'a pas d'incidence métier — le service déduit les familles à la
    // validation — mais il doit être **stable** : `uniq_prp_draft_link_seq`
    // garantit un ordre total, et deux liens de même rang sont refusés.
    const etat = saisie()
    etat.parents = [PERE]
    etat.fratrie = [{ personne: FRERE, familleId: FAMILLE_DORIGINE, rangFratrie: null }]
    etat.unions = [unionVide(CONJOINT)]
    etat.enfants = [{ personne: ENFANT, familleId: null, rangFratrie: null }]

    const resultat = deposer(etat)
    if (!resultat.valide) throw new Error('corps attendu')

    expect(resultat.corps.liens.map((lien) => lien.seq)).toEqual([1, 2, 3, 4])
    expect(resultat.corps.liens.map((lien) => lien.kind)).toEqual([
      'enfant_de',
      'enfant_de',
      'conjoint_de',
      'parent_de',
    ])
  })

  it('compte les liens décrits — un seul pour deux parents', () => {
    const etat = saisie()
    etat.parents = [PERE, MERE]
    etat.unions = [unionVide(CONJOINT)]

    expect(nombreDeLiensProposes(etat)).toBe(2)
    expect(nombreDeLiensProposes(saisie())).toBe(0)
  })

  it('tient la fiche elle-même pour déjà désignée', () => {
    // On ne rattache pas une fiche à elle-même, et le sélecteur doit éteindre le
    // bouton plutôt que de laisser déposer un corps voué au refus.
    expect(dejaDesignes(saisie())).toContain('personne:17')
  })
})
