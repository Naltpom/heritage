import { describe, expect, it } from 'vitest'
import type { BlocDate, DoublonsProbables, MaDemandeDoublon, PersonneDoublon } from '@/lib/api'
import { creationVide, unionVide, type SaisieCreation } from './creation'
import { saisieVide } from './dates'
import {
  CLE_PRE_REMPLISSAGE,
  aDesDoublons,
  apporteQuelqueChose,
  comparer,
  correspondances,
  lirePreRemplissage,
  preRemplissageModification,
  recapitulatifCreation,
  titreAvertissement,
  type Correspondance,
} from './doublons'

/**
 * La comparaison d'avant dépôt (§ 6.3 ter), sans écran : ce qui coïncide, ce qui
 * diverge, ce que le membre ajoute — et ce qu'on emporte vers la modification
 * d'une fiche existante.
 */

function bloc(fragment: Partial<BlocDate> = {}): BlocDate {
  return { raw: null, qualifier: 'inconnue', min: null, max: null, place: null, texte: '', ...fragment }
}

const ENTRE_1901_1909 = bloc({
  raw: 'BET 1901 AND 1909',
  qualifier: 'entre',
  min: '1901-01-01',
  max: '1909-12-31',
  place: 'Saule, Ombrage, Sylvanie',
  texte: 'entre 1901 et 1909',
})

function personne(fragment: Partial<PersonneDoublon> = {}): PersonneDoublon {
  return {
    personId: 17,
    displayName: 'Marne Dolomite Sureau',
    givenNames: 'Marne Dolomite',
    surname: 'Sureau',
    marriedName: null,
    nickname: null,
    sex: 'feminin',
    deceased: true,
    vivant: false,
    birth: ENTRE_1901_1909,
    death: bloc({ raw: '1987', qualifier: 'annee', min: '1987-01-01', max: '1987-12-31', texte: '1987' }),
    ligneDates: '1901-1909 – 1987',
    parents: [],
    conjoints: [{ personId: 21, displayName: 'Émeri Sarriette' }],
    enfants: [{ personId: 30, displayName: 'Natron Romarin' }],
    criteres: ['nom', 'prenom'],
    ...fragment,
  }
}

function maDemande(fragment: Partial<MaDemandeDoublon> = {}): MaDemandeDoublon {
  return {
    demandeId: 12,
    draftPersonId: 7,
    localRef: '#p1',
    noeudId: '#12/p1',
    status: 'en_attente',
    submittedAt: '2026-10-05T10:00:00+02:00',
    displayName: 'Barnabé Muguet',
    givenNames: 'Barnabé',
    surname: 'Muguet',
    marriedName: null,
    nickname: null,
    sex: 'masculin',
    deceased: true,
    birth: bloc({ raw: 'ABT 1901', qualifier: 'environ', min: '1901-01-01', max: '1901-12-31', texte: 'vers 1901' }),
    death: bloc(),
    ligneDates: '~1901',
    note: 'Acte de naissance.',
    liens: [{ seq: 1, kind: 'enfant_de', otherPersonId: 55, otherLabel: 'Jargon Sarriette' }],
    criteres: ['nom', 'prenom', 'naissance'],
    ...fragment,
  }
}

function saisieMarne(): SaisieCreation {
  const saisie = creationVide()
  saisie.brouillon.givenNames = 'Marne'
  saisie.brouillon.surname = 'SUREAU'
  saisie.brouillon.sex = 'feminin'
  saisie.brouillon.deceased = true
  saisie.brouillon.naissance = { ...saisieVide(), forme: 'connue', debut: '1905' }
  saisie.enfants = [{ personne: { personId: 30, libelle: 'Natron Romarin' }, rangFratrie: null }]
  saisie.unions = [unionVide({ personId: 44, libelle: 'Jargon Sarriette' })]
  return saisie
}

function arbre(p: PersonneDoublon): Correspondance {
  return { source: 'arbre', cle: `arbre:${p.personId}`, personne: p }
}

function etats(saisie: SaisieCreation, correspondance: Correspondance): Record<string, string> {
  return Object.fromEntries(comparer(saisie, correspondance).map((ligne) => [ligne.cle, ligne.etat]))
}

describe('la comparaison champ par champ', () => {
  it('distingue l’identique, le divergent, l’ajouté et le déjà connu', () => {
    expect(etats(saisieMarne(), arbre(personne()))).toEqual({
      givenNames: 'divergent', // « Marne » contre « Marne Dolomite »
      surname: 'identique', // la casse ne compte pas
      sex: 'identique',
      deceased: 'identique',
      birth: 'divergent', // « 1905 » contre « entre 1901 et 1909 »
      death: 'existant', // rien de saisi : la fiche le garde
      conjoints: 'ajoute', // un second conjoint s'ajoute
      enfants: 'identique',
    })
  })

  it('montre l’existant et la saisie sur une ligne qui diverge', () => {
    const naissance = comparer(saisieMarne(), arbre(personne())).find((ligne) => ligne.cle === 'birth')
    expect(naissance?.existant).toBe('entre 1901 et 1909, à Saule, Ombrage, Sylvanie')
    expect(naissance?.saisi).toBe('En 1905')
  })

  it('ne compare pas une naissance que la réciprocité ne laisse pas voir', () => {
    const masquee = personne({ vivant: true, deceased: false, birth: bloc({ qualifier: 'masquee', texte: '' }) })
    const ligne = comparer(saisieMarne(), arbre(masquee)).find((l) => l.cle === 'birth')
    expect(ligne?.etat).toBe('non_comparable')
    expect(ligne?.existant).toBe('Non partagée')
  })

  it('dit « diverge » de parents différents, mais « ajouté » d’un enfant de plus', () => {
    const saisie = saisieMarne()
    saisie.filiation.parents = [{ personId: 99, libelle: 'Lapis Fenouil' }]
    saisie.enfants.push({ personne: { personId: 31, libelle: 'Cobalt Romarin' }, rangFratrie: null })
    const avecParents = personne({ parents: [{ personId: 98, displayName: 'Ambre Bourrache' }] })

    const resultat = etats(saisie, arbre(avecParents))
    expect(resultat['parents']).toBe('divergent')
    expect(resultat['enfants']).toBe('ajoute')
  })

  it('lit les proches d’une de mes demandes dans ses liens, et y ajoute la note', () => {
    const saisie = creationVide()
    saisie.brouillon.givenNames = 'Barnabé'
    saisie.brouillon.surname = 'Muguet'
    saisie.brouillon.note = 'Livret de famille.'
    saisie.filiation.parents = [{ personId: 55, libelle: 'Jargon Sarriette' }]

    const resultat = etats(saisie, { source: 'demande', cle: 'demande:12:#p1', demande: maDemande() })
    expect(resultat['parents']).toBe('identique')
    expect(resultat['note']).toBe('ajoute')
    expect(resultat['birth']).toBe('existant')
  })

  it('sait dire qu’une saisie n’apporte rien', () => {
    const saisie = creationVide()
    saisie.brouillon.givenNames = 'Barnabé'
    saisie.brouillon.surname = 'Muguet'
    const lignes = comparer(saisie, { source: 'demande', cle: 'd', demande: maDemande() })
    expect(apporteQuelqueChose(lignes)).toBe(false)
  })
})

describe('les correspondances et le titre', () => {
  const vide: DoublonsProbables = { personnes: [], mesDemandes: [], enCoursAilleurs: false }

  it('n’avertit pas quand rien ne ressemble', () => {
    expect(aDesDoublons(vide)).toBe(false)
  })

  it('avertit sur le seul drapeau des autres', () => {
    expect(aDesDoublons({ ...vide, enCoursAilleurs: true })).toBe(true)
    expect(titreAvertissement(null)).toBe('Attention : cette personne est déjà en cours de validation')
  })

  it('range la meilleure correspondance d’abord, l’arbre devant à égalité', () => {
    const reponse: DoublonsProbables = {
      personnes: [personne({ criteres: ['nom', 'prenom', 'naissance'] })],
      mesDemandes: [maDemande({ criteres: ['nom', 'prenom', 'naissance', 'parents'] })],
      enCoursAilleurs: false,
    }
    const liste = correspondances(reponse)
    expect(liste.map((c) => c.source)).toEqual(['demande', 'arbre'])
    expect(titreAvertissement(liste[1]!)).toBe('Attention : cette personne existe déjà dans l’arbre')

    const egalite = correspondances({ ...reponse, mesDemandes: [maDemande({ criteres: ['nom', 'prenom', 'deces'] })] })
    expect(egalite[0]!.source).toBe('arbre')
  })
})

describe('le récapitulatif', () => {
  it('liste ce qui part, sans les champs vides', () => {
    expect(recapitulatifCreation(saisieMarne())).toEqual([
      { etiquette: 'Prénoms', valeur: 'Marne' },
      { etiquette: 'Nom', valeur: 'SUREAU' },
      { etiquette: 'Sexe', valeur: 'Féminin' },
      { etiquette: 'Décès attesté', valeur: 'Oui' },
      { etiquette: 'Naissance', valeur: 'En 1905' },
      { etiquette: 'Conjoints', valeur: 'Jargon Sarriette' },
      { etiquette: 'Enfants', valeur: 'Natron Romarin' },
    ])
  })
})

describe('vers la modification de la fiche existante', () => {
  it('n’emporte que les champs divergents ou ajoutés, et les proches absents', () => {
    const pre = preRemplissageModification(saisieMarne(), personne())
    expect(pre.champs).toEqual({ givenNames: 'Marne' })
    expect(pre.naissance?.debut).toBe('1905')
    expect(pre.deces).toBeNull()
    expect(pre.enfants).toEqual([]) // Natron est déjà son enfant
    expect(pre.unions.map((u) => u.personne)).toEqual([{ personId: 44, libelle: 'Jargon Sarriette' }])
  })

  it('ne propose pas de seconds parents à une fiche qui en a déjà', () => {
    const saisie = saisieMarne()
    saisie.filiation.parents = [{ personId: 99, libelle: 'Lapis Fenouil' }]
    expect(preRemplissageModification(saisie, personne({ parents: [{ personId: 98, displayName: 'X' }] })).parents).toEqual([])
    expect(preRemplissageModification(saisie, personne()).parents).toHaveLength(1)
  })

  it('se relit depuis `history.state`, pour cette fiche seulement', () => {
    const pre = JSON.parse(JSON.stringify(preRemplissageModification(saisieMarne(), personne()))) as unknown
    const etat = { back: '/personnes/proposer', [CLE_PRE_REMPLISSAGE]: pre }

    const relu = lirePreRemplissage(etat, 17)
    expect(relu?.champs).toEqual({ givenNames: 'Marne' })
    expect(relu?.unions[0]?.personne).toEqual({ personId: 44, libelle: 'Jargon Sarriette' })
    expect(relu?.naissance?.origine).toBeNull()

    expect(lirePreRemplissage(etat, 18)).toBeNull()
    expect(lirePreRemplissage(null, 17)).toBeNull()
    expect(lirePreRemplissage({ [CLE_PRE_REMPLISSAGE]: 'nimporte quoi' }, 17)).toBeNull()
  })
})
