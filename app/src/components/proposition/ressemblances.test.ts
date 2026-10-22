import { describe, expect, it } from 'vitest'
import type { RessemblanceDansLArbre, RessemblancePropositionComplete, RessemblancePropositionDiscrete } from '@/lib/api'
import { brouillonVide, creationVide, type SaisieBrouillon } from './creation'
import { saisieVide } from './dates'
import {
  cleDeComparaison,
  cleDeRessemblance,
  designesDansLesLiens,
  enEvidence,
  estDuLecteur,
  estForte,
  ficheDeComparaison,
  garderLOuverte,
  replier,
  resumeDesRessemblances,
  visibles,
} from './ressemblances'

/**
 * « Est-ce déjà… ? » sans réseau : quand appeler, et quoi montrer.
 *
 * La propriété la plus importante est la première : **on n'appelle que quand le
 * serveur peut répondre**. Ses deux refus — un nom de deux lettres, un prénom —
 * sont recopiés au même repliement que le sien ; un appel de trop rendrait un
 * `422`, et un `200` vide ferait croire que la personne n'existe pas.
 */

function brouillon(champs: Partial<SaisieBrouillon> = {}): SaisieBrouillon {
  return { ...brouillonVide(), givenNames: 'Pyroxène', surname: 'Tilleule', ...champs }
}

function personne(champs: Partial<RessemblanceDansLArbre> = {}): RessemblanceDansLArbre {
  return {
    personId: 233,
    displayName: 'Pyroxène Tilleul',
    sex: 'masculin',
    vivant: true,
    generation: -2,
    branch: 'mm',
    ligneDates: '—',
    lieuNaissance: 'Sorbière, Fougerie, Florie',
    criteres: ['nom', 'prenom', 'sexe', 'lieu'],
    raisons: ['nom proche : Tilleule / Tilleul', 'prénom Pyroxène en commun', 'même sexe', 'même lieu de naissance : Sorbière'],
    score: 4,
    ...champs,
  }
}

function complete(champs: Partial<RessemblancePropositionComplete> = {}): RessemblancePropositionComplete {
  return {
    forme: 'complete',
    draftPersonId: 7,
    demandeId: 12,
    localRef: '#p1',
    noeudId: '#12/p1',
    displayName: 'Barnabé Muguet',
    sex: 'masculin',
    deceased: true,
    ligneDates: '1901 – 1968',
    authorLabel: 'Cobalt Romarin',
    enAttente: true,
    lieuNaissance: 'Saule, Ombrage, Sylvanie',
    statut: 'en_attente',
    action: 'completer',
    criteres: ['nom', 'prenom', 'sexe', 'naissance'],
    raisons: ['même nom', 'prénom Barnabé en commun', 'même sexe', 'né en 1901, comme dans la fiche'],
    score: 4,
    ...champs,
  }
}

function discrete(champs: Partial<RessemblancePropositionDiscrete> = {}): RessemblancePropositionDiscrete {
  return {
    forme: 'discrete',
    draftPersonId: 9,
    action: 'complement',
    criteres: ['nom', 'prenom', 'sexe'],
    raisons: ['nom semblable', 'un prénom en commun', 'même sexe'],
    ...champs,
  }
}

describe('replier : la clé du serveur', () => {
  it('replie accents, ligatures, casse et ponctuation comme `CleDeRecherche::replier()`', () => {
    expect(replier('Génévrolle')).toBe('GENEVROLLE')
    expect(replier('Jean-Marie  d’Œillet')).toBe('JEAN MARIE D OEILLET')
    expect(replier('Łucja Ørsted')).toBe('LUCJA ORSTED')
    expect(replier('  ')).toBe('')
  })
})

describe('ficheDeComparaison : n’appeler que quand le serveur peut répondre', () => {
  it('rend la fiche aux huit clés, sans note ni liens', () => {
    const fiche = ficheDeComparaison(brouillon({ note: 'Livret de famille', sex: 'masculin' }))

    expect(fiche).toEqual({
      givenNames: 'Pyroxène',
      surname: 'Tilleule',
      marriedName: null,
      nickname: null,
      sex: 'masculin',
      deceased: false,
      birth: null,
      death: null,
    })
  })

  it('attend un nom ou un nom d’usage de deux lettres', () => {
    expect(ficheDeComparaison(brouillon({ surname: 'T' }))).toBeNull()
    // Deux caractères, mais une seule lettre une fois replié : le serveur compte des lettres.
    expect(ficheDeComparaison(brouillon({ surname: 'T.' }))).toBeNull()
    expect(ficheDeComparaison(brouillon({ surname: 'Ti' }))).not.toBeNull()
    // Le nom d'usage suffit.
    expect(ficheDeComparaison(brouillon({ surname: '', marriedName: 'Muguet' }))?.marriedName).toBe('Muguet')
    // Un accent est une lettre.
    expect(ficheDeComparaison(brouillon({ surname: 'Éa' }))).not.toBeNull()
  })

  it('attend un prénom ou un surnom', () => {
    expect(ficheDeComparaison(brouillon({ givenNames: '' }))).toBeNull()
    expect(ficheDeComparaison(brouillon({ givenNames: ' - ' }))).toBeNull()
    expect(ficheDeComparaison(brouillon({ givenNames: '', nickname: 'Pyro' }))?.nickname).toBe('Pyro')
  })

  it('passe les dates par `valeurAEnvoyer`, sans `texte`, et omet celles qui ne se composent pas', () => {
    const fiche = ficheDeComparaison(
      brouillon({
        naissance: { ...saisieVide(), forme: 'environ', debut: '1934', lieu: 'Sorbière' },
        // « Date connue » sans rien de tapé : invalide, donc omise — pas un `422`.
        deces: { ...saisieVide(), forme: 'connue' },
      }),
    )

    expect(fiche?.birth).toEqual({ raw: 'ABT 1934', qualifier: 'environ', min: '1934-01-01', max: '1934-12-31', place: 'Sorbière' })
    expect(fiche?.death).toBeNull()
  })

  it('retire `texte` d’une date reprise d’une fiche', () => {
    const origine = { raw: '1901', qualifier: 'annee', min: '1901-01-01', max: '1901-12-31', place: null, texte: '1901' }
    const fiche = ficheDeComparaison(
      brouillon({ naissance: { forme: 'connue', debut: '1901', fin: '', lieu: '', origine: origine as never } }),
    )

    expect(fiche?.birth).not.toHaveProperty('texte')
    expect(fiche?.birth?.raw).toBe('1901')
  })
})

describe('cleDeComparaison : ce qui relance la recherche', () => {
  const avant = ficheDeComparaison(brouillon())!

  it('est stable : la note, les liens et la case « décès attesté » ne relancent rien', () => {
    expect(cleDeComparaison(ficheDeComparaison(brouillon({ note: 'Acte de naissance' }))!)).toBe(cleDeComparaison(avant))
    expect(cleDeComparaison(ficheDeComparaison(brouillon({ deceased: true }))!)).toBe(cleDeComparaison(avant))
  })

  it('change avec le nom, le sexe ou une date', () => {
    expect(cleDeComparaison(ficheDeComparaison(brouillon({ surname: 'Tilleul' }))!)).not.toBe(cleDeComparaison(avant))
    expect(cleDeComparaison(ficheDeComparaison(brouillon({ sex: 'feminin' }))!)).not.toBe(cleDeComparaison(avant))
    const date = brouillon({ naissance: { ...saisieVide(), forme: 'connue', debut: '1934' } })
    expect(cleDeComparaison(ficheDeComparaison(date)!)).not.toBe(cleDeComparaison(avant))
  })
})

describe('cleDeRessemblance et visibles : ce qu’on montre', () => {
  it('a le format de `cleDuChoix`', () => {
    expect(cleDeRessemblance(personne({ personId: 12 }))).toBe('personne:12')
    expect(cleDeRessemblance(complete({ draftPersonId: 34 }))).toBe('brouillon:34')
    expect(cleDeRessemblance(discrete({ draftPersonId: 9 }))).toBe('brouillon:9')
  })

  it('retire les écartées et les personnes déjà désignées dans les liens, dans l’ordre du serveur', () => {
    const saisie = creationVide()
    saisie.filiation.parents = [{ personId: 12, libelle: 'Jean Dupont' }]
    saisie.unions = [
      { personne: { draftPersonId: 34, demandeId: 5, libelle: 'Marie' }, mariage: saisieVide(), marie: null, rompue: false, rupture: saisieVide(), note: '' },
    ]
    const designes = designesDansLesLiens(saisie)
    expect(designes).toEqual(['personne:12', 'brouillon:34'])

    const arbre = [personne({ personId: 12 }), personne({ personId: 13 }), personne({ personId: 14 })]
    expect(visibles(arbre, ['personne:14'], designes).map((r) => r.personId)).toEqual([13])
    expect(visibles([complete({ draftPersonId: 34 }), discrete()], [], designes)).toEqual([discrete()])
  })

  it('garde la fiche qu’on complète, même si la réponse ne la rend plus', () => {
    const ouverte = personne({ personId: 99 })
    expect(garderLOuverte([personne()], ouverte).map((r) => r.personId)).toEqual([99, 233])
    expect(garderLOuverte([personne(), ouverte], ouverte).map((r) => r.personId)).toEqual([233, 99])
    expect(garderLOuverte([personne()], null)).toHaveLength(1)
  })
})

describe('estForte : trois concordances', () => {
  it('ne retient pas le plancher de la règle — un nom et un prénom', () => {
    expect(estForte(personne({ criteres: ['nom', 'prenom'], score: 2 }))).toBe(false)
    expect(estForte(personne({ criteres: ['nom', 'prenom', 'sexe'], score: 3 }))).toBe(true)
    expect(estForte(complete({ score: 6 }))).toBe(true)
  })

  it('compte les critères d’une forme discrète, qui n’a pas de score', () => {
    expect(estForte(discrete())).toBe(true)
    expect(estForte(discrete({ criteres: ['nom', 'prenom'], raisons: ['nom semblable', 'un prénom en commun'] }))).toBe(false)
  })
})

describe('le reste de ce que le panneau lit', () => {
  it('dit « votre proposition » d’un brouillon ou d’une demande du lecteur', () => {
    expect(estDuLecteur(complete({ statut: 'brouillon' }), [])).toBe(true)
    expect(estDuLecteur(complete({ demandeId: 12 }), [12])).toBe(true)
    expect(estDuLecteur(complete({ demandeId: 12 }), [13])).toBe(false)
  })

  it('cercle les fiches de l’arbre et les nœuds proposés qu’il porte', () => {
    expect(enEvidence([personne({ personId: 3 })], [complete({ noeudId: '#12/p1' }), complete({ noeudId: null }), discrete()])).toEqual({
      personIds: [3],
      noeudIds: ['#12/p1'],
    })
  })

  it('résume en une phrase, vide sans résultat', () => {
    expect(resumeDesRessemblances(0, 0)).toBe('')
    expect(resumeDesRessemblances(1, 0)).toBe('Une personne de l’arbre ressemble à votre saisie.')
    expect(resumeDesRessemblances(2, 1)).toBe('2 personnes de l’arbre et une proposition en attente ressemblent à votre saisie.')
    expect(resumeDesRessemblances(0, 3)).toBe('3 propositions en attente ressemblent à votre saisie.')
  })
})
