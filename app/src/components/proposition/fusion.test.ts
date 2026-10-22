import { describe, expect, it } from 'vitest'
import type { BlocDate, BrouillonDemande, Demande, LienDemande } from '@/lib/api'
import { creationVide, type SaisieBrouillon, type SaisieCreation } from './creation'
import { saisieDepuisBloc, saisieVide, valeurAEnvoyer } from './dates'
import {
  completionDepuisRequete,
  complementDepuisRessemblance,
  corpsMiseAJour,
  designesParLaProposition,
  fusionnerBrouillons,
  joindreNotes,
  liensDejaProposes,
  memeCible,
  phraseDuLienPropose,
  placesDeParent,
  prendreLaValeur,
  propositionDepuisDemande,
  raisonDeNePasCompleter,
  referenceSansDiese,
  retirerLiensDejaProposes,
  saisieDepuisBrouillonDemande,
  type PropositionACompleter,
} from './fusion'

/**
 * Compléter sur place une proposition en attente (ADR 0017) — la partie sans
 * écran. Ce que ces tests tiennent, dans l'ordre où ça coûte cher :
 *
 * 1. **la proposition l'emporte**, la saisie ne fait que remplir ses vides — un
 *    désaccord devient une divergence, jamais un écrasement silencieux ;
 * 2. **un lien déjà proposé ne repart pas** : le `PUT` ajoute, il ajouterait un
 *    doublon ;
 * 3. **la `localRef` de la fiche est gardée** — `#p3` reste `#p3`, ce n'est pas
 *    toujours `#p1` ;
 * 4. **aucun `texte` dans le corps** : la fiche reçue porte des blocs à six clés,
 *    celle qui repart en a cinq (§ 14.1).
 */

/* ------------------------------------------------------------------ */
/* Les charges                                                         */
/* ------------------------------------------------------------------ */

const VERS_1889: BlocDate = {
  raw: 'ABT 1889',
  qualifier: 'environ',
  min: '1889-01-01',
  max: '1889-12-31',
  place: null,
  texte: 'vers 1889',
}

/** Barnabé Muguet, la **troisième** fiche de la demande n° 12 — pas `#p1`. */
function barnabe(surcharge: Partial<BrouillonDemande> = {}): BrouillonDemande {
  return {
    localRef: '#p3',
    noeudId: '#12/p3',
    givenNames: 'Barnabé',
    surname: 'Muguet',
    marriedName: null,
    nickname: null,
    sex: 'masculin',
    deceased: false,
    birth: VERS_1889,
    death: null,
    note: 'Livret de famille.',
    resolvedPersonId: null,
    ...surcharge,
  }
}

const LIENS: LienDemande[] = [
  // Barnabé, enfant de Jargon (n° 61).
  { seq: 1, kind: 'enfant_de', subjectDraftRef: '#p3', otherPersonId: 61, otherLabel: 'Jargon Muguet' },
  // Tourmaline (#p1) conjointe de Barnabé : la fiche est l'**autre** extrémité.
  {
    seq: 2,
    kind: 'conjoint_de',
    subjectDraftRef: '#p1',
    subjectLabel: 'Tourmaline Muguet (proposée)',
    otherDraftRef: '#p3',
    otherLabel: 'Barnabé Muguet (proposée)',
    marriage: { ...VERS_1889, raw: '1912', qualifier: 'annee', min: '1912-01-01', max: '1912-12-31', texte: '1912' },
    divorced: false,
  },
  // Un lien qui ne touche pas Barnabé.
  { seq: 3, kind: 'parent_de', subjectDraftRef: '#p1', otherPersonId: 70, otherLabel: 'Ambre Muguet' },
  // Barnabé, parent de Céleste (n° 80).
  { seq: 4, kind: 'parent_de', subjectDraftRef: '#p3', otherPersonId: 80, otherLabel: 'Céleste Muguet' },
]

function demande(surcharge: Partial<Demande> = {}): Demande {
  return {
    id: 12,
    kind: 'creation_personne',
    status: 'en_attente',
    authorUserId: 3,
    authorLabel: 'Cobalt Romarin',
    targetKind: null,
    targetId: null,
    targetLabel: null,
    message: null,
    decisionMessage: null,
    autoValidated: false,
    duplicateOfId: null,
    complementDe: null,
    submittedAt: '2026-10-09T10:00:00+02:00',
    createdAt: '2026-10-09T10:00:00+02:00',
    ops: [],
    brouillons: [
      barnabe({ localRef: '#p1', noeudId: '#12/p1', givenNames: 'Tourmaline', sex: 'feminin', birth: null, note: null }),
      barnabe(),
    ],
    liens: LIENS,
    revisions: [],
    empreinte: '9f2c',
    ...surcharge,
  }
}

function proposition(): PropositionACompleter {
  const lue = propositionDepuisDemande(demande(), 'p3')
  if (lue === null) throw new Error('La fiche #p3 devait se trouver.')
  return lue
}

/** Une saisie « Barnabé Muguet » sans rien d'autre. */
function saisieDeBarnabe(surcharge: Partial<SaisieBrouillon> = {}): SaisieBrouillon {
  return { ...creationVide().brouillon, givenNames: 'Barnabé', surname: 'Muguet', ...surcharge }
}

/** Une date tapée dans le formulaire. */
function tapee(forme: 'connue' | 'environ', debut: string, lieu = '') {
  return { ...saisieVide(), forme, debut, lieu }
}

/* ------------------------------------------------------------------ */
/* La proposition                                                      */
/* ------------------------------------------------------------------ */

describe('la proposition, lue dans la demande', () => {
  it('trouve la fiche demandée — pas forcément la première —, avec ou sans dièse', () => {
    const lue = proposition()
    expect(lue.localRef).toBe('#p3')
    expect(lue.brouillonRef).toBe('p3')
    expect(lue.libelle).toBe('Barnabé Muguet')
    expect(lue.empreinte).toBe('9f2c')
    expect(propositionDepuisDemande(demande(), '#p3')?.fiche.givenNames).toBe('Barnabé')
    expect(propositionDepuisDemande(demande(), 'p9')).toBeNull()
  })

  it('ne garde que les liens qui la touchent, à l’une ou l’autre extrémité', () => {
    expect(proposition().liens.map((l) => l.seq)).toEqual([1, 2, 4])
  })

  it('sans empreinte dans la charge, n’en invente pas', () => {
    const sansEmpreinte = demande()
    delete sansEmpreinte.empreinte
    expect(propositionDepuisDemande(sansEmpreinte, 'p3')?.empreinte).toBeNull()
  })

  it('dit pourquoi elle ne se complète pas : tranchée, ou brouillon d’un autre', () => {
    const lue = proposition()
    expect(raisonDeNePasCompleter(lue, 3)).toBeNull()
    // L'administrateur complète la demande en attente d'un autre.
    expect(raisonDeNePasCompleter(lue, 1)).toBeNull()
    expect(raisonDeNePasCompleter({ ...lue, statut: 'validee' }, 3)).toMatch(/tranchée/)
    expect(raisonDeNePasCompleter({ ...lue, statut: 'brouillon' }, 1)).toMatch(/seul son auteur/)
    expect(raisonDeNePasCompleter({ ...lue, statut: 'brouillon' }, 3)).toBeNull()
  })

  it('relit les dates à cinq clés, sans `texte`, et les vides en chaînes vides', () => {
    const saisie = saisieDepuisBrouillonDemande(barnabe())
    expect(saisie.marriedName).toBe('')
    expect(saisie.naissance.forme).toBe('environ')
    expect(saisie.naissance.debut).toBe('1889')
    expect(saisie.naissance.origine).toEqual({ raw: 'ABT 1889', qualifier: 'environ', min: '1889-01-01', max: '1889-12-31', place: null })
    expect(saisie.naissance.origine).not.toHaveProperty('texte')
  })
})

/* ------------------------------------------------------------------ */
/* La fusion                                                           */
/* ------------------------------------------------------------------ */

describe('la fusion : la proposition l’emporte, la saisie remplit les vides', () => {
  it('garde la valeur proposée quand la saisie dit autre chose, et le signale', () => {
    const { fusion, divergences } = fusionnerBrouillons(barnabe(), saisieDeBarnabe({ surname: 'Muguette' }))

    expect(fusion.surname).toBe('Muguet')
    expect(divergences).toEqual([{ champ: 'surname', etiquette: 'Nom', proposition: 'Muguet', saisie: 'Muguette' }])
  })

  it('remplit les champs vides, et ne propose jamais d’effacer', () => {
    const { fusion, divergences, remplis } = fusionnerBrouillons(
      barnabe(),
      saisieDeBarnabe({ nickname: 'Bébert', givenNames: '', sex: 'inconnu' }),
    )

    expect(fusion.nickname).toBe('Bébert')
    // Un prénom vide et un sexe inconnu dans la saisie laissent la proposition.
    expect(fusion.givenNames).toBe('Barnabé')
    expect(fusion.sex).toBe('masculin')
    expect(remplis).toEqual(['nickname'])
    expect(divergences).toEqual([])
  })

  it('un sexe inconnu de la proposition prend celui de la saisie ; deux sexes différents divergent', () => {
    expect(fusionnerBrouillons(barnabe({ sex: 'inconnu' }), saisieDeBarnabe({ sex: 'masculin' })).fusion.sex).toBe('masculin')

    const { fusion, divergences } = fusionnerBrouillons(barnabe(), saisieDeBarnabe({ sex: 'feminin' }))
    expect(fusion.sex).toBe('masculin')
    expect(divergences[0]).toMatchObject({ champ: 'sex', proposition: 'Masculin', saisie: 'Féminin' })
  })

  it('« décès attesté » ne se contredit pas : coché d’un côté, il l’est', () => {
    expect(fusionnerBrouillons(barnabe({ deceased: false }), saisieDeBarnabe({ deceased: true })).fusion.deceased).toBe(true)
    expect(fusionnerBrouillons(barnabe({ deceased: true }), saisieDeBarnabe({ deceased: false })).fusion.deceased).toBe(true)
  })

  it('ajoute les notes l’une à l’autre, sans répéter un paragraphe', () => {
    const { fusion } = fusionnerBrouillons(barnabe(), saisieDeBarnabe({ note: 'Livret de famille.\n\nActe de décès de 1968.' }))
    expect(fusion.note).toBe('Livret de famille.\n\nActe de décès de 1968.')
  })

  it('une date connue sans lieu reçoit le lieu seul, et garde sa provenance', () => {
    const { fusion, divergences, remplis } = fusionnerBrouillons(
      barnabe(),
      saisieDeBarnabe({ naissance: tapee('environ', '1889', 'Saule') }),
    )

    expect(divergences).toEqual([])
    expect(remplis).toEqual(['naissance'])
    const valeur = valeurAEnvoyer(fusion.naissance)
    expect(valeur).toEqual({
      valide: true,
      valeur: { raw: 'ABT 1889', qualifier: 'environ', min: '1889-01-01', max: '1889-12-31', place: 'Saule' },
    })
  })

  it('« 1901 » et « en 1901 » ne divergent pas : on compare qualificatif et bornes, jamais `raw`', () => {
    const en1901: BlocDate = { raw: 'en 1901', qualifier: 'annee', min: '1901-01-01', max: '1901-12-31', place: null, texte: '1901' }
    const { divergences } = fusionnerBrouillons(barnabe({ birth: en1901 }), saisieDeBarnabe({ naissance: tapee('connue', '1901') }))
    expect(divergences).toEqual([])
  })

  it('deux dates différentes : la proposition reste, et les deux se lisent', () => {
    const { fusion, divergences } = fusionnerBrouillons(barnabe(), saisieDeBarnabe({ naissance: tapee('connue', '1890') }))

    expect(valeurAEnvoyer(fusion.naissance)).toMatchObject({ valide: true, valeur: { raw: 'ABT 1889' } })
    // Le `texte` du serveur d'un côté, l'aperçu de la saisie de l'autre.
    expect(divergences).toEqual([{ champ: 'naissance', etiquette: 'Naissance', proposition: 'vers 1889', saisie: 'En 1890' }])
  })

  it('une date vide de la proposition prend celle de la saisie', () => {
    const { fusion, remplis } = fusionnerBrouillons(barnabe({ death: null }), saisieDeBarnabe({ deces: tapee('connue', '1968', 'Saule') }))
    expect(remplis).toContain('deces')
    expect(valeurAEnvoyer(fusion.deces)).toMatchObject({ valide: true, valeur: { qualifier: 'annee', place: 'Saule' } })
  })

  it('une saisie illisible diverge, tapée telle quelle — elle ne se compare pas', () => {
    const { fusion, divergences } = fusionnerBrouillons(barnabe(), saisieDeBarnabe({ naissance: tapee('connue', '31/02/1890') }))
    expect(valeurAEnvoyer(fusion.naissance)).toMatchObject({ valide: true, valeur: { raw: 'ABT 1889' } })
    expect(divergences[0]?.saisie).toBe('« 31/02/1890 » (illisible)')
  })

  it('« Prendre ma valeur » reprend le champ de la saisie, sur une copie', () => {
    const saisie = saisieDeBarnabe({ surname: 'Muguette', naissance: tapee('connue', '1890') })
    const { fusion, proposee } = fusionnerBrouillons(barnabe(), saisie)

    const prise = prendreLaValeur(fusion, saisie, 'naissance')
    expect(valeurAEnvoyer(prise.naissance)).toMatchObject({ valide: true, valeur: { raw: '1890' } })
    expect(prise.surname).toBe('Muguet')
    // La date prise est à soi : la modifier ne touche pas la saisie d'origine.
    prise.naissance.debut = '1891'
    expect(saisie.naissance.debut).toBe('1890')

    // … et « Garder celle de la proposition » revient en arrière.
    expect(valeurAEnvoyer(prendreLaValeur(prise, proposee, 'naissance').naissance)).toMatchObject({
      valeur: { raw: 'ABT 1889' },
    })
  })

  it('la fusion n’écrit jamais dans la proposition d’origine', () => {
    const { fusion, proposee } = fusionnerBrouillons(barnabe(), saisieDeBarnabe())
    fusion.naissance.lieu = 'Ailleurs'
    expect(proposee.naissance.lieu).toBe('')
  })
})

describe('joindreNotes', () => {
  it('saute les vides et les paragraphes déjà là, dans l’ordre', () => {
    expect(joindreNotes('A\n\nB', '', 'A\n\nC', '  ')).toBe('A\n\nB\n\nC')
    expect(joindreNotes('', '')).toBe('')
  })
})

/* ------------------------------------------------------------------ */
/* Les liens                                                           */
/* ------------------------------------------------------------------ */

describe('les liens : ceux qui y sont, ceux qu’on ajoute', () => {
  function saisieAvecLiens(): SaisieCreation {
    const saisie = creationVide()
    saisie.brouillon = saisieDeBarnabe()
    saisie.filiation.parents = [{ personId: 61, libelle: 'Jargon Muguet' }]
    saisie.unions = [
      { personne: { personId: 90, libelle: 'Opale Sureau' }, mariage: saisieVide(), marie: null, rompue: false, rupture: saisieVide(), note: '' },
    ]
    saisie.enfants = [
      { personne: { personId: 80, libelle: 'Céleste Muguet' }, rangFratrie: null },
      { personne: { personId: 81, libelle: 'Ambroise Muguet' }, rangFratrie: 2 },
    ]
    return saisie
  }

  it('reconnaît ce que la proposition porte déjà, dans les deux sens de lecture', () => {
    expect(liensDejaProposes(proposition(), saisieAvecLiens())).toEqual({
      filiation: true,
      unions: [],
      enfants: ['personne:80'],
    })
    expect(designesParLaProposition(proposition())).toEqual(['personne:61', 'ref:#p1', 'personne:80'])
  })

  it('retire les doublons, garde les nouveaux, sans toucher la saisie d’origine', () => {
    const saisie = saisieAvecLiens()
    const reste = retirerLiensDejaProposes(saisie, proposition())

    expect(reste.filiation.parents).toEqual([])
    expect(reste.unions.map((u) => u.personne.libelle)).toEqual(['Opale Sureau'])
    expect(reste.enfants.map((e) => e.personne.libelle)).toEqual(['Ambroise Muguet'])
    expect(saisie.enfants).toHaveLength(2)
  })

  it('une filiation dont un parent est nouveau n’est pas un doublon', () => {
    const saisie = saisieAvecLiens()
    saisie.filiation.parents.push({ personId: 62, libelle: 'Marne Sureau' })
    expect(liensDejaProposes(proposition(), saisie).filiation).toBe(false)
  })

  it('compte les places de parent qui restent : un couple les prend toutes', () => {
    expect(placesDeParent(proposition())).toBe(1)
    expect(placesDeParent({ localRef: '#p3', liens: [{ seq: 1, kind: 'enfant_de', subjectDraftRef: '#p3', familyId: 28 }] })).toBe(0)
    expect(placesDeParent({ localRef: '#p3', liens: [] })).toBe(2)
  })

  it('met chaque lien en mots, et dit de qui il parle quand la fiche n’en est pas le sujet', () => {
    const [enfantDe, conjoint, , parentDe] = LIENS
    expect(phraseDuLienPropose(enfantDe!, '#p3')).toBe('Enfant de Jargon Muguet')
    expect(phraseDuLienPropose(parentDe!, '#p3')).toBe('Parent de Céleste Muguet')
    expect(phraseDuLienPropose(conjoint!, '#p3')).toBe(
      'Tourmaline Muguet (proposée) : conjoint·e de Barnabé Muguet (proposée) (mariés en 1912)',
    )
  })
})

/* ------------------------------------------------------------------ */
/* Le corps du PUT                                                      */
/* ------------------------------------------------------------------ */

describe('le corps de la complétion', () => {
  function saisieComplete(): SaisieCreation {
    const lue = proposition()
    const saisie = creationVide()
    saisie.localRef = lue.localRef
    saisie.brouillon = saisieDepuisBrouillonDemande(lue.fiche)
    saisie.brouillon.deces = saisieDepuisBloc({ raw: '1968', qualifier: 'annee', min: '1968-01-01', max: '1968-12-31', place: 'Saule', texte: '1968' })
    saisie.brouillon.deceased = true
    saisie.enfants = [{ personne: { personId: 81, libelle: 'Ambroise Muguet' }, rangFratrie: 2 }]
    return saisie
  }

  it('garde la `localRef` de la fiche, envoie les liens sans rang, et l’empreinte', () => {
    const resultat = corpsMiseAJour(saisieComplete(), { empreinte: '9f2c', liensExistants: 3, soumettre: false })
    if (!resultat.valide) throw new Error('Le corps devait être valide.')

    expect(resultat.corps.fiche.localRef).toBe('#p3')
    expect(resultat.corps.liens).toEqual([{ kind: 'parent_de', subjectDraftRef: '#p3', otherPersonId: 81, siblingOrder: 2 }])
    expect(resultat.corps.liens?.[0]).not.toHaveProperty('seq')
    expect(resultat.corps.empreinte).toBe('9f2c')
  })

  it('remplace la fiche entière : chaque champ est là, `null` quand il est vide', () => {
    const resultat = corpsMiseAJour(saisieComplete(), { empreinte: null, liensExistants: 3, soumettre: false })
    if (!resultat.valide) throw new Error('Le corps devait être valide.')

    expect(Object.keys(resultat.corps.fiche).sort()).toEqual(
      ['birth', 'death', 'deceased', 'givenNames', 'localRef', 'marriedName', 'nickname', 'note', 'sex', 'surname'].sort(),
    )
    expect(resultat.corps.fiche.marriedName).toBeNull()
    expect(resultat.corps).not.toHaveProperty('empreinte')
  })

  it('ne porte aucun `texte`, alors que la fiche reçue en avait partout', () => {
    const resultat = corpsMiseAJour(saisieComplete(), { empreinte: '9f2c', liensExistants: 3, soumettre: false })
    expect(JSON.stringify(resultat)).not.toContain('texte')
    if (!resultat.valide) throw new Error('Le corps devait être valide.')
    expect(resultat.corps.fiche.birth).toEqual({ raw: 'ABT 1889', qualifier: 'environ', min: '1889-01-01', max: '1889-12-31', place: null })
    expect(resultat.corps.fiche.death).toEqual({ raw: '1968', qualifier: 'annee', min: '1968-01-01', max: '1968-12-31', place: 'Saule' })
  })

  it('n’envoie pas de tableau de liens vide', () => {
    const saisie = saisieComplete()
    saisie.enfants = []
    const resultat = corpsMiseAJour(saisie, { empreinte: '9f2c', liensExistants: 3, soumettre: false })
    if (!resultat.valide) throw new Error('Le corps devait être valide.')
    expect(resultat.corps).not.toHaveProperty('liens')
  })

  it('« au moins un lien » se compte sur la proposition entière, et seulement à l’envoi', () => {
    const sansLien = saisieComplete()
    sansLien.enfants = []

    expect(corpsMiseAJour(sansLien, { empreinte: null, liensExistants: 2, soumettre: true }).valide).toBe(true)
    expect(corpsMiseAJour(sansLien, { empreinte: null, liensExistants: 0, soumettre: false }).valide).toBe(true)
    const refuse = corpsMiseAJour(sansLien, { empreinte: null, liensExistants: 0, soumettre: true })
    expect(refuse.valide).toBe(false)
    if (!refuse.valide) expect(refuse.erreurs.map((e) => e.champ)).toEqual(['liens'])
  })

  it('tient les règles du dépôt : un décès daté sans « décès attesté » est refusé', () => {
    const saisie = saisieComplete()
    saisie.brouillon.deceased = false
    const resultat = corpsMiseAJour(saisie, { empreinte: null, liensExistants: 3, soumettre: false })
    expect(resultat.valide).toBe(false)
    if (!resultat.valide) expect(resultat.erreurs.map((e) => e.champ)).toEqual(['deceased'])
  })
})

/* ------------------------------------------------------------------ */
/* L'adresse, et les ressemblances                                     */
/* ------------------------------------------------------------------ */

describe('ce qu’on demande à compléter', () => {
  it('relit `?completer=&ref=`, et n’ouvre rien sur une adresse bricolée', () => {
    expect(completionDepuisRequete('12', 'p3')).toEqual({ demandeId: 12, ref: 'p3' })
    expect(completionDepuisRequete('12', '#p3')).toEqual({ demandeId: 12, ref: 'p3' })
    expect(completionDepuisRequete('12', 'p')).toBeNull()
    expect(completionDepuisRequete('douze', 'p3')).toBeNull()
    expect(completionDepuisRequete('-4', 'p3')).toBeNull()
    expect(completionDepuisRequete(undefined, 'p3')).toBeNull()
    expect(completionDepuisRequete('12', undefined)).toBeNull()
  })

  it('compare deux cibles sans le dièse ni le jeton', () => {
    expect(memeCible({ demandeId: 12, ref: '#p3', jeton: 1 }, { demandeId: 12, ref: 'p3', jeton: 2 })).toBe(true)
    expect(memeCible({ demandeId: 12, ref: 'p3' }, { demandeId: 13, ref: 'p3' })).toBe(false)
    expect(memeCible(null, null)).toBe(true)
    expect(referenceSansDiese('#p1')).toBe('p1')
  })

  it('un complément ne retient d’une forme discrète que l’identifiant de la fiche', () => {
    expect(
      complementDepuisRessemblance({ forme: 'discrete', draftPersonId: 9, action: 'complement', criteres: [], raisons: [] }),
    ).toEqual({ draftPersonId: 9, libelle: null, auteur: null })
  })
})
