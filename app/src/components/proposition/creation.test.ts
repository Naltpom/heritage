import { describe, expect, it } from 'vitest'
import { saisieVide, type SaisieDate } from './dates'
import {
  construireLiens,
  corpsCreationPersonne,
  creationVide,
  erreursParChamp,
  nombreDeLiens,
  unionVide,
  type SaisieCreation,
} from './creation'

/**
 * Ce que ces tests protègent : **une création est une demande, et ses liens
 * obéissent à des `CHECK`**.
 *
 * Le commanditaire a demandé l'ajout de personnes « sur ce même principe » que
 * la modification. Le risque n'est donc pas d'oublier un champ : c'est
 * d'inventer un second circuit. Et une fois la demande bien formée, tout se joue
 * sur les liens — quatre contraintes de base qu'un formulaire enfreint sans s'en
 * apercevoir, et qui rendent `422` après que le contributeur a rempli quatre
 * blocs.
 */

const JARGON = { personId: 55, libelle: 'Jargon Sarriette' }
const PERE = { personId: 61, libelle: 'Argile Muguet' }
const MERE = { personId: 62, libelle: 'Sanguine Verveine' }
const ENFANT = { personId: 63, libelle: 'Sodalite Sarriette' }

function date(modifications: Partial<SaisieDate>): SaisieDate {
  return { ...saisieVide(), ...modifications }
}

/** Une personne nommée, avec un lien : le minimum soumettable. */
function saisieMinimale(): SaisieCreation {
  const saisie = creationVide()
  saisie.brouillon.givenNames = 'Tourmaline'
  saisie.brouillon.surname = 'Muguet'
  saisie.brouillon.sex = 'feminin'
  saisie.unions = [unionVide(JARGON)]
  return saisie
}

describe('au moins un lien — mais seulement à la soumission', () => {
  it('refuse de soumettre une personne qui flotterait hors de l’arbre', () => {
    const saisie = creationVide()
    saisie.brouillon.surname = 'Muguet'

    const resultat = corpsCreationPersonne(saisie, { message: '', soumettre: true })

    expect(resultat.valide).toBe(false)
    if (resultat.valide) return
    expect(erreursParChamp(resultat.erreurs)['liens']).toMatch(/au moins un parent, un enfant ou un conjoint/i)
  })

  it('accepte de l’enregistrer en brouillon', () => {
    // « On peut enregistrer un brouillon incomplet » : on complète le lendemain.
    // Bloquer l'enregistrement exigerait que la contribution se fasse d'un trait.
    const saisie = creationVide()
    saisie.brouillon.surname = 'Muguet'

    const resultat = corpsCreationPersonne(saisie, { message: '', soumettre: false })

    expect(resultat.valide).toBe(true)
    if (!resultat.valide) return
    expect(resultat.corps.liens).toEqual([])
    expect(resultat.corps.soumettre).toBe(false)
  })

  it('exige un nom, sans quoi aucun rapprochement n’est possible', () => {
    const saisie = creationVide()
    saisie.unions = [unionVide(JARGON)]

    const resultat = corpsCreationPersonne(saisie, { message: '', soumettre: true })

    expect(resultat.valide).toBe(false)
    if (resultat.valide) return
    expect(erreursParChamp(resultat.erreurs)['identite']).toMatch(/prénom ou un nom/i)
  })
})

describe('la filiation — un couple, ou un ou deux parents, jamais les deux', () => {
  it('assemble deux parents en un seul lien enfant_de', () => {
    // Deux parents ne font pas deux liens : `other2PersonId` est « le second
    // parent », et `ck_prp_link_second_parent` ne l'admet que sur `enfant_de`.
    const saisie = saisieMinimale()
    saisie.filiation.parents = [PERE, MERE]

    const { liens, erreurs } = construireLiens(saisie)
    const filiation = liens.find((lien) => lien.kind === 'enfant_de')

    expect(erreurs).toEqual([])
    expect(liens.filter((lien) => lien.kind === 'enfant_de')).toHaveLength(1)
    expect(filiation?.otherPersonId).toBe(61)
    expect(filiation?.other2PersonId).toBe(62)
  })

  it('n’écrit pas other2 quand il n’y a qu’un parent', () => {
    // Le serveur compte les clés **non nulles** : poser `other2PersonId` à
    // `undefined` ou à `null` n'est pas la même chose que ne pas l'écrire.
    const saisie = saisieMinimale()
    saisie.filiation.parents = [PERE]

    const filiation = construireLiens(saisie).liens.find((lien) => lien.kind === 'enfant_de')

    expect(filiation?.otherPersonId).toBe(61)
    expect('other2PersonId' in filiation!).toBe(false)
  })

  it('refuse un couple et des parents désignés en même temps', () => {
    // `ck_prp_link_cible` : « le service ne saurait pas laquelle des deux
    // intentions respecter ».
    const saisie = saisieMinimale()
    saisie.filiation.famille = { familyId: 47, libelle: 'Argile Muguet × Sanguine Verveine' }
    saisie.filiation.parents = [PERE]

    const { erreurs } = construireLiens(saisie)

    expect(erreursParChamp(erreurs)['filiation']).toMatch(/soit le couple, soit les parents/i)
  })

  it('reprend un couple existant seul, sans other', () => {
    // Le formulaire ne propose pas de désigner un couple, mais une reprise en
    // rapporte un : « `family_id` est conservé » (§ 6.10, point 3).
    const saisie = saisieMinimale()
    saisie.filiation.famille = { familyId: 47, libelle: 'Argile Muguet × Sanguine Verveine' }

    const filiation = construireLiens(saisie).liens.find((lien) => lien.kind === 'enfant_de')!

    expect(filiation.familyId).toBe(47)
    expect('otherPersonId' in filiation).toBe(false)
  })

  it('refuse deux fois la même personne comme parents', () => {
    const saisie = saisieMinimale()
    saisie.filiation.parents = [PERE, PERE]

    expect(erreursParChamp(construireLiens(saisie).erreurs)['filiation']).toMatch(/même personne/i)
  })
})

describe('les unions', () => {
  it('reste une union sans aucune date', () => {
    // « Toutes facultatives : une union sans date reste une union » (§ 6.4 du
    // dossier). Exiger la date ferait disparaître la majorité des unions d'un
    // arbre ancien.
    const saisie = saisieMinimale()

    const union = construireLiens(saisie).liens.find((lien) => lien.kind === 'conjoint_de')!

    expect(union.otherPersonId).toBe(55)
    expect('marriage' in union).toBe(false)
    expect(union.divorced).toBe(false)
  })

  it('porte la date de mariage sans l’aplatir', () => {
    const saisie = saisieMinimale()
    saisie.unions[0]!.mariage = date({
      forme: 'connue',
      debut: '23/09/1927',
      lieu: 'Saule, Ombrage, Sylvanie',
    })

    const union = construireLiens(saisie).liens.find((lien) => lien.kind === 'conjoint_de')!

    expect(union.marriage).toEqual({
      raw: '23 SEP 1927',
      qualifier: 'exacte',
      min: '1927-09-23',
      max: '1927-09-23',
      place: 'Saule, Ombrage, Sylvanie',
    })
  })

  it('accepte une date de mariage incertaine', () => {
    const saisie = saisieMinimale()
    saisie.unions[0]!.mariage = date({ forme: 'entre', debut: '1914', fin: '1929' })

    const union = construireLiens(saisie).liens.find((lien) => lien.kind === 'conjoint_de')!

    expect(union.marriage?.qualifier).toBe('entre')
    expect(union.marriage?.min).toBe('1914-01-01')
    expect(union.marriage?.max).toBe('1929-12-31')
  })

  it('garde la rupture même sans date', () => {
    // Une rupture sans date reste une rupture : `divorced` et `divorce` sont
    // deux informations distinctes.
    const saisie = saisieMinimale()
    saisie.unions[0]!.rompue = true

    const union = construireLiens(saisie).liens.find((lien) => lien.kind === 'conjoint_de')!

    expect(union.divorced).toBe(true)
    expect('divorce' in union).toBe(false)
  })

  it('signale une date de rupture sans rupture cochée', () => {
    // On ne devine pas un divorce à partir d'une date : c'est un fait d'état
    // civil, pas un détail d'affichage.
    const saisie = saisieMinimale()
    saisie.unions[0]!.rupture = date({ forme: 'connue', debut: '1931' })

    const erreurs = erreursParChamp(construireLiens(saisie).erreurs)

    expect(erreurs['unions.0.rupture']).toMatch(/cochez/i)
  })

  it('remonte l’erreur de saisie de date à son champ, et pas dans un bandeau', () => {
    // La clé porte le rang de l'union : avec quatre blocs de liens à l'écran,
    // un message générique en haut de page laisserait chercher lequel est en
    // cause. `06/1927` est une date de mois valide ; `13/1927` ne l'est pas.
    const saisie = saisieMinimale()
    saisie.unions[0]!.mariage = date({ forme: 'connue', debut: '13/1927' })

    expect(erreursParChamp(construireLiens(saisie).erreurs)['unions.0.mariage']).toMatch(/entre 1 et 12/i)
  })
})

describe('les enfants', () => {
  it('produit un lien parent_de par enfant, sans date d’union', () => {
    // `ck_prp_link_dates_union` : les dates d'union ne concernent que
    // `conjoint_de`. Un lien de filiation qui en porterait rendrait 422.
    const saisie = saisieMinimale()
    saisie.enfants = [{ personne: ENFANT, rangFratrie: 0 }]

    const enfant = construireLiens(saisie).liens.find((lien) => lien.kind === 'parent_de')!

    expect(enfant.otherPersonId).toBe(63)
    expect(enfant.siblingOrder).toBe(0)
    expect('marriage' in enfant).toBe(false)
    expect('divorced' in enfant).toBe(false)
    expect('familyId' in enfant).toBe(false)
  })

  it('omet le rang quand il n’est pas connu — « à la fin »', () => {
    const saisie = saisieMinimale()
    saisie.enfants = [{ personne: ENFANT, rangFratrie: null }]

    const enfant = construireLiens(saisie).liens.find((lien) => lien.kind === 'parent_de')!

    expect('siblingOrder' in enfant).toBe(false)
  })
})

describe('le corps complet', () => {
  it('numérote les liens sans trou, dans l’ordre du formulaire', () => {
    const saisie = saisieMinimale()
    saisie.filiation.parents = [PERE, MERE]
    saisie.unions.push(unionVide({ personId: 99, libelle: 'Autre conjoint' }))
    saisie.enfants = [{ personne: ENFANT, rangFratrie: null }]

    const { liens } = construireLiens(saisie)

    expect(liens.map((lien) => lien.seq)).toEqual([1, 2, 3, 4])
    expect(liens.map((lien) => lien.kind)).toEqual(['enfant_de', 'conjoint_de', 'conjoint_de', 'parent_de'])
    expect(nombreDeLiens(saisie)).toBe(4)
  })

  it('rattache tous les liens à la référence provisoire, jamais à un identifiant', () => {
    // « La personne n'a pas d'identifiant tant qu'elle n'est pas validée. »
    const saisie = saisieMinimale()
    saisie.filiation.parents = [PERE]
    saisie.enfants = [{ personne: ENFANT, rangFratrie: null }]

    const resultat = corpsCreationPersonne(saisie, { message: '', soumettre: true })

    expect(resultat.valide).toBe(true)
    if (!resultat.valide) return
    expect(resultat.corps.brouillons[0]!.localRef).toBe('#p1')
    for (const lien of resultat.corps.liens) {
      expect(lien.subjectDraftRef).toBe('#p1')
      expect('subjectPersonId' in lien).toBe(false)
    }
  })

  it('ne décrit aucune famille — le service les déduit à la validation', () => {
    const saisie = saisieMinimale()
    const resultat = corpsCreationPersonne(saisie, { message: '', soumettre: true })

    expect(resultat.valide).toBe(true)
    if (!resultat.valide) return
    // Une clé `familleRef` serait refusée en 422 : la demande dit l'intention
    // (« conjoint de X »), pas le résultat.
    expect(JSON.stringify(resultat.corps)).not.toMatch(/familleRef/)
    expect(resultat.corps.kind).toBe('creation_personne')
  })

  it('refuse une date de décès sans décès attesté', () => {
    const saisie = saisieMinimale()
    saisie.brouillon.deces = date({ forme: 'connue', debut: '1972' })

    const resultat = corpsCreationPersonne(saisie, { message: '', soumettre: true })

    expect(resultat.valide).toBe(false)
    if (resultat.valide) return
    expect(erreursParChamp(resultat.erreurs)['deceased']).toMatch(/décès attesté/i)
  })

  it('compose la fiche du § 6.3 quand tout est cohérent', () => {
    const saisie = saisieMinimale()
    saisie.brouillon.marriedName = 'Sarriette'
    saisie.brouillon.deceased = true
    saisie.brouillon.naissance = date({ forme: 'environ', debut: '1889', lieu: 'Saule, Ombrage, Sylvanie' })
    saisie.brouillon.deces = date({ forme: 'connue', debut: '1972' })
    saisie.brouillon.note = 'Livret de famille en ma possession, photographié.'

    const resultat = corpsCreationPersonne(saisie, {
      message: 'épouse de Jargon, j’ai le livret de famille',
      soumettre: true,
    })

    expect(resultat.valide).toBe(true)
    if (!resultat.valide) return
    expect(resultat.corps.brouillons[0]).toEqual({
      localRef: '#p1',
      givenNames: 'Tourmaline',
      surname: 'Muguet',
      marriedName: 'Sarriette',
      nickname: null,
      sex: 'feminin',
      deceased: true,
      birth: {
        raw: 'ABT 1889',
        qualifier: 'environ',
        min: '1889-01-01',
        max: '1889-12-31',
        place: 'Saule, Ombrage, Sylvanie',
      },
      death: { raw: '1972', qualifier: 'annee', min: '1972-01-01', max: '1972-12-31', place: null },
      note: 'Livret de famille en ma possession, photographié.',
    })
  })
})
