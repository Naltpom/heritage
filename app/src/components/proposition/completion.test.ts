import { describe, expect, it, vi } from 'vitest'
import type { Demande, FichePersonneComplete, Media } from '@/lib/api'
import { brouillonVide, creationVide, type SaisieBrouillon, type SaisieCreation } from './creation'
import { saisieVide, type SaisieDate } from './dates'
import {
  basculerEcart,
  cleDEcart,
  demandesDeCompletion,
  ecartsAvecFiche,
  ecartsCochesParDefaut,
  envoyerLesPhotosSurLaFiche,
  liensCochesParDefaut,
  liensPourFiche,
  messageDeCompletion,
  saisieLiensRetenus,
  unionParDefaut,
  valeursRetenues,
  type Ecart,
} from './completion'
import { MOTIF_NAISSANCE_VERROUILLEE, instantaneDeFiche } from './operations'
import type { PhotoAJoindre } from './PhotosAJoindre.vue'

/**
 * « C'est elle : compléter sa fiche », **sans composant** : ce que la saisie
 * apporte à une fiche qui existe, et en quelles demandes ça part.
 *
 * La fiche d'essai : Marne Sureau (17), née en 1894, décédée sans date, enfant du
 * couple Galène × Opale (famille 4), unie à Cobalt (famille 9) dont elle a deux
 * enfants. Les règles tenues ici, dans l'ordre où elles coûteraient cher :
 *
 * 1. **un champ vide dans la saisie ne propose jamais d'effacer** ;
 * 2. **une divergence n'est jamais cochée d'office** ;
 * 3. **`oldValue` se lit dans le corps de la fiche**, jamais dans son calque
 *    `enAttente` — sans quoi la demande partirait caduque ;
 * 4. **un lien déjà présent ou impossible ne part pas**.
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
    fratrie: [],
    unions: [
      {
        familyId: 9,
        conjoint: { personId: 52, displayName: 'Cobalt Romarin' },
        marriage: null,
        married: null,
        divorced: false,
        divorce: null,
        version: 1,
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

function saisie(champs: Partial<SaisieBrouillon> = {}): SaisieBrouillon {
  return { ...brouillonVide(), ...champs }
}

function date(forme: SaisieDate['forme'], debut: string, lieu = '', fin = ''): SaisieDate {
  return { ...saisieVide(), forme, debut, fin, lieu }
}

/** L'écart d'un champ, ou `undefined`. */
function ecartDe(ecarts: Ecart[], champ: Ecart['champ']): Ecart | undefined {
  return ecarts.find((e) => e.champ === champ)
}

describe('ecartsAvecFiche : les quatre natures', () => {
  it('complement : la fiche est vide là où la saisie dit quelque chose — coché', () => {
    const ecarts = ecartsAvecFiche(fiche(), saisie({ marriedName: 'Romarin', nickname: 'Marnette' }))

    expect(ecarts.map((e) => [e.champ, e.nature, e.cocheParDefaut])).toEqual([
      ['marriedName', 'complement', true],
      ['nickname', 'complement', true],
    ])
    expect(ecartDe(ecarts, 'marriedName')).toMatchObject({ avant: '(vide)', apres: 'Romarin', valeur: 'Romarin', motif: null })
  })

  it('precision : une date qui tient dans celle de la fiche et la resserre — cochée', () => {
    const ecart = ecartDe(ecartsAvecFiche(fiche(), saisie({ naissance: date('connue', '11/03/1894') })), 'birth')

    expect(ecart?.nature).toBe('precision')
    expect(ecart?.cocheParDefaut).toBe(true)
    expect(ecart?.valeur).toEqual({ raw: '11 MAR 1894', qualifier: 'exacte', min: '1894-03-11', max: '1894-03-11', place: null })
    expect(ecart?.avant).toBe('1894')
  })

  it('precision : « 1904 » ôte le doute de « vers 1904 », « 1904 » resserre « entre 1901 et 1909 »', () => {
    const vers = fiche({ birth: { raw: 'ABT 1904', qualifier: 'environ', min: '1904-01-01', max: '1904-12-31', place: null, texte: 'vers 1904' } })
    expect(ecartDe(ecartsAvecFiche(vers, saisie({ naissance: date('connue', '1904') })), 'birth')?.nature).toBe('precision')

    const entre = fiche({
      birth: { raw: 'BET 1901 AND 1909', qualifier: 'entre', min: '1901-01-01', max: '1909-12-31', place: null, texte: 'entre 1901 et 1909' },
    })
    expect(ecartDe(ecartsAvecFiche(entre, saisie({ naissance: date('connue', '1904') })), 'birth')?.nature).toBe('precision')
  })

  it('precision : le lieu qui manque à la fiche s’ajoute, la date et sa provenance restent', () => {
    const ecart = ecartDe(ecartsAvecFiche(fiche(), saisie({ naissance: date('inconnue', '', 'Saule') })), 'birth')

    expect(ecart?.nature).toBe('precision')
    expect(ecart?.valeur).toEqual({ raw: '1894', qualifier: 'annee', min: '1894-01-01', max: '1894-12-31', place: 'Saule' })
  })

  it('divergence : la saisie contredit la fiche — décochée, et le dit', () => {
    const ecarts = ecartsAvecFiche(fiche(), saisie({ givenNames: 'Marie', naissance: date('connue', '1895'), sex: 'masculin' }))

    for (const champ of ['givenNames', 'sex', 'birth'] as const) {
      const ecart = ecartDe(ecarts, champ)
      expect(ecart?.nature).toBe('divergence')
      expect(ecart?.cocheParDefaut).toBe(false)
      expect(ecart?.motif).toBe('La valeur de la fiche sera remplacée.')
    }
    expect(ecartDe(ecarts, 'givenNames')).toMatchObject({ avant: 'Marne', apres: 'Marie' })
    expect(ecartDe(ecarts, 'sex')).toMatchObject({ avant: 'Féminin', apres: 'Masculin' })
  })

  it('divergence : un lieu différent, même à date égale', () => {
    const lieu = fiche({ birth: { raw: '1894', qualifier: 'annee', min: '1894-01-01', max: '1894-12-31', place: 'Saule', texte: '1894' } })
    expect(ecartDe(ecartsAvecFiche(lieu, saisie({ naissance: date('connue', '1894', 'Ombrage') })), 'birth')?.nature).toBe('divergence')
    // « Saule, Ombrage » précise « Saule » ; « Saule » ne contredit pas « Saule, Ombrage ».
    expect(ecartDe(ecartsAvecFiche(lieu, saisie({ naissance: date('connue', '1894', 'Saule, Ombrage') })), 'birth')?.nature).toBe('precision')
  })

  it('aveugle : une naissance masquée se propose sans se comparer — décochée, avec son motif', () => {
    const masquee = fiche({
      vivant: true,
      deceased: false,
      birth: { raw: null, qualifier: 'masquee', min: null, max: null, place: 'Saule', texte: 'mars 1985', composantes: { annee: 1985, mois: 3 } },
      naissanceModifiable: false,
    })
    const ecart = ecartDe(ecartsAvecFiche(masquee, saisie({ naissance: date('connue', '12/03/1985') })), 'birth')

    expect(ecart?.nature).toBe('aveugle')
    expect(ecart?.cocheParDefaut).toBe(false)
    expect(ecart?.motif).toBe(MOTIF_NAISSANCE_VERROUILLEE)
    expect(ecart?.avant).toBe('mars 1985 (en partie masquée)')
    // Le lieu, visible, n'est pas effacé par une saisie qui n'en dit rien.
    expect(ecart?.valeur).toEqual({ raw: '12 MAR 1985', qualifier: 'exacte', min: '1985-03-12', max: '1985-03-12', place: 'Saule' })
  })

  it('aveugle : retenue, elle part avec l’ancienne valeur du cliché — le serveur pose la sienne', () => {
    const masquee = fiche({
      vivant: true,
      deceased: false,
      birth: { raw: null, qualifier: 'masquee', min: null, max: null, place: null, texte: '' },
      naissanceModifiable: false,
    })
    const ecarts = ecartsAvecFiche(masquee, saisie({ naissance: date('connue', '1985') }))
    const resultat = demandesDeCompletion(masquee, instantaneDeFiche(masquee), creationVide(), {
      ecarts,
      retenus: ecarts.map(cleDEcart),
      liensRetenus: [],
      unionsDesEnfants: {},
      message: '',
    })

    expect(ecarts[0]?.avant).toBe('non visible')
    expect(resultat.valide && resultat.corps[0]).toMatchObject({
      ops: [
        {
          field: 'birth',
          oldValue: { raw: null, qualifier: 'inconnue', min: null, max: null, place: null },
          newValue: { raw: '1985', qualifier: 'annee' },
        },
      ],
    })
  })
})

describe('ecartsAvecFiche : ce qui n’est pas un écart', () => {
  it('un champ vide dans la saisie ne propose jamais d’effacer', () => {
    const pleine = fiche({ marriedName: 'Romarin', nickname: 'Marnette' })
    expect(ecartsAvecFiche(pleine, saisie())).toEqual([])
    // Le sexe inconnu et la case « décès attesté » laissée vide non plus.
    expect(ecartsAvecFiche(fiche({ deceased: false, sex: 'masculin' }), saisie({ sex: 'inconnu', deceased: false }))).toEqual([])
  })

  it('compare les textes repliés : accents, casse, espaces, tirets', () => {
    const accentuee = fiche({ givenNames: 'Jean-Marie Hélène', surname: 'Sureau' })
    expect(ecartsAvecFiche(accentuee, saisie({ givenNames: ' jean marie  helene ', surname: 'SUREAU' }))).toEqual([])
  })

  it('une date moins précise que celle de la fiche, ou plus douteuse, ne propose rien', () => {
    const exacte = fiche({ birth: { raw: '11 MAR 1894', qualifier: 'exacte', min: '1894-03-11', max: '1894-03-11', place: null, texte: '11 mars 1894' } })
    expect(ecartsAvecFiche(exacte, saisie({ naissance: date('connue', '1894') }))).toEqual([])
    expect(ecartsAvecFiche(fiche(), saisie({ naissance: date('environ', '1894') }))).toEqual([])
  })

  it('une date qui ne se compose pas encore ne propose rien', () => {
    expect(ecartsAvecFiche(fiche({ birth: null }), saisie({ naissance: date('connue', '18') }))).toEqual([])
  })

  it('aucun écart quand la saisie redit la fiche', () => {
    expect(ecartsAvecFiche(fiche(), saisie({ givenNames: 'Marne', surname: 'Sureau', sex: 'feminin', deceased: true, naissance: date('connue', '1894') }))).toEqual([])
  })
})

describe('le décès et sa date', () => {
  const vivante = fiche({ deceased: false })
  const ecarts = ecartsAvecFiche(vivante, saisie({ deceased: true, deces: date('connue', '1968') }))

  it('une date de décès propose « décès attesté », même case laissée vide dans la saisie', () => {
    expect(ecartDe(ecarts, 'deceased')).toMatchObject({ nature: 'complement', valeur: true })
    expect(ecartDe(ecarts, 'death')?.nature).toBe('complement')
    const sansCase = ecartsAvecFiche(vivante, saisie({ deceased: false, deces: date('connue', '1968') }))
    expect(ecartDe(sansCase, 'deceased')?.valeur).toBe(true)
  })

  it('cocher la date coche le décès, décocher le décès décoche la date', () => {
    const deces = cleDEcart(ecartDe(ecarts, 'death')!)
    const decede = cleDEcart(ecartDe(ecarts, 'deceased')!)

    expect(basculerEcart(ecarts, [], deces).sort()).toEqual([deces, decede].sort())
    expect(basculerEcart(ecarts, [deces, decede], decede)).toEqual([])
    // Décocher la date seule garde le décès : il peut être attesté sans date.
    expect(basculerEcart(ecarts, [deces, decede], deces)).toEqual([decede])
  })

  it('une date de décès retenue rend la personne décédée, quoi qu’on ait coché à côté', () => {
    const instantane = instantaneDeFiche(vivante)
    const valeurs = valeursRetenues(instantane, ecarts, [cleDEcart(ecartDe(ecarts, 'death')!)])

    expect(valeurs.deceased).toBe(true)
    expect(valeurs.death).toMatchObject({ raw: '1968', qualifier: 'annee' })
  })
})

describe('demandesDeCompletion : l’oldValue vient du corps de la fiche', () => {
  it('ignore le calque `enAttente` : l’ancienne valeur est celle qui est validée', () => {
    const avecCalque = fiche({
      marriedName: null,
      enAttente: {
        marriedName: { propose: 'Proposé-ailleurs', actuel: null, demandeId: 4, auteur: 'Opale', depuis: '2026-10-01', autresDemandes: [] },
      },
    })
    const ecarts = ecartsAvecFiche(avecCalque, saisie({ marriedName: 'Romarin' }))
    const resultat = demandesDeCompletion(avecCalque, instantaneDeFiche(avecCalque), creationVide(), {
      ecarts,
      retenus: ecartsCochesParDefaut(ecarts),
      liensRetenus: [],
      unionsDesEnfants: {},
      message: '',
    })

    expect(resultat.valide).toBe(true)
    if (!resultat.valide) return
    expect(resultat.corps).toHaveLength(1)
    expect(resultat.corps[0]).toMatchObject({
      kind: 'modification_personne',
      soumettre: true,
      targetId: 17,
      ops: [{ field: 'marriedName', oldValue: null, newValue: 'Romarin', baseVersion: 8 }],
    })
  })

  it('ne dépose rien quand rien n’est retenu', () => {
    const ecarts = ecartsAvecFiche(fiche(), saisie({ givenNames: 'Marie' }))
    const resultat = demandesDeCompletion(fiche(), instantaneDeFiche(fiche()), creationVide(), {
      ecarts,
      retenus: ecartsCochesParDefaut(ecarts),
      liensRetenus: [],
      unionsDesEnfants: {},
      message: '',
    })

    // La divergence n'est pas cochée d'office : rien ne part.
    expect(resultat).toEqual({ valide: true, corps: [] })
  })

  it('dépose les champs puis les liens, deux demandes, avec le mot du formulaire', () => {
    const sansParents = fiche({ parents: [] })
    const creation: SaisieCreation = { ...creationVide(), brouillon: saisie({ marriedName: 'Romarin' }) }
    creation.filiation.parents = [{ personId: 30, libelle: 'Galène Bourrache' }]
    const ecarts = ecartsAvecFiche(sansParents, creation.brouillon)
    const message = messageDeCompletion('Livret de famille', 'C’est ma grand-mère.')
    const resultat = demandesDeCompletion(sansParents, instantaneDeFiche(sansParents), creation, {
      ecarts,
      retenus: ecartsCochesParDefaut(ecarts),
      liensRetenus: liensCochesParDefaut(sansParents, creation),
      unionsDesEnfants: {},
      message,
    })

    expect(message).toBe('Livret de famille\n\nC’est ma grand-mère.')
    expect(resultat.valide && resultat.corps.map((c) => c.kind)).toEqual(['modification_personne', 'ajout_lien'])
    expect(resultat.valide && resultat.corps[1]).toMatchObject({
      kind: 'ajout_lien',
      targetId: 17,
      message,
      liens: [{ seq: 1, kind: 'enfant_de', subjectPersonId: 17, otherPersonId: 30 }],
    })
  })
})

describe('liensPourFiche : les liens saisis, vus depuis la fiche', () => {
  function avecLiens(remplir: (s: SaisieCreation) => void): SaisieCreation {
    const s = creationVide()
    remplir(s)
    return s
  }

  it('grise ce qui est déjà présent, avec sa raison', () => {
    const s = avecLiens((c) => {
      c.filiation.parents = [{ personId: 30, libelle: 'Galène Bourrache' }]
      c.unions = [{ personne: { personId: 52, libelle: 'Cobalt Romarin' }, mariage: saisieVide(), marie: null, rompue: false, rupture: saisieVide(), note: '' }]
      c.enfants = [{ personne: { personId: 60, libelle: 'Pivoine Romarin' }, rangFratrie: null }]
    })

    expect(liensPourFiche(fiche(), s, []).map((l) => [l.nature, l.statut, l.raison])).toEqual([
      ['parent', 'present', 'Déjà son parent dans l’arbre.'],
      ['conjoint', 'present', 'Déjà son conjoint dans l’arbre.'],
      ['enfant', 'present', 'Déjà son enfant dans l’arbre.'],
    ])
    expect(liensCochesParDefaut(fiche(), s)).toEqual([])
  })

  it('refuse un troisième parent, et une personne déjà liée autrement', () => {
    const s = avecLiens((c) => {
      c.filiation.parents = [{ personId: 80, libelle: 'Inconnu Tiers' }]
      c.enfants = [{ personne: { personId: 30, libelle: 'Galène Bourrache' }, rangFratrie: null }]
      c.unions = [{ personne: { personId: 17, libelle: 'Marne Sureau' }, mariage: saisieVide(), marie: null, rompue: false, rupture: saisieVide(), note: '' }]
    })
    const liens = liensPourFiche(fiche(), s, [])

    expect(liens[0]).toMatchObject({ statut: 'impossible', raison: 'Elle a déjà deux parents dans l’arbre : un troisième ne s’ajoute pas.' })
    expect(liens.find((l) => l.nature === 'enfant')).toMatchObject({ statut: 'impossible', raison: 'Déjà son parent dans l’arbre : un second lien le contredirait.' })
    expect(liens.find((l) => l.nature === 'conjoint')).toMatchObject({ statut: 'impossible', raison: 'C’est la fiche elle-même.' })
  })

  it('n’offre qu’une place quand la fiche a déjà un parent, au premier saisi', () => {
    const unParent = fiche({ parents: [{ personId: 30, displayName: 'Galène Bourrache', relation: 'naissance', familyId: 4 }] })
    const s = avecLiens((c) => {
      c.filiation.parents = [
        { personId: 80, libelle: 'Premier' },
        { draftPersonId: 7, demandeId: 12, libelle: 'Second (proposé)' },
      ]
    })

    const coches = liensCochesParDefaut(unParent, s)
    expect(coches).toEqual(['parent:personne:80'])
    expect(liensPourFiche(unParent, s, coches).map((l) => l.statut)).toEqual(['proposable', 'impossible'])
    // Décocher le premier libère la place.
    expect(liensPourFiche(unParent, s, []).map((l) => l.statut)).toEqual(['proposable', 'proposable'])
    // Les deux cochés quand même : le second ne part pas — elle aurait trois parents.
    const deux = ['parent:personne:80', 'parent:brouillon:7']
    expect(liensPourFiche(unParent, s, deux).map((l) => l.statut)).toEqual(['proposable', 'impossible'])
    expect(saisieLiensRetenus(unParent, s, deux, {}).parents).toEqual([{ personId: 80, libelle: 'Premier' }])
  })

  it('met un enfant dans la seule union de la fiche, ou d’elle seule à défaut', () => {
    const s = avecLiens((c) => {
      c.enfants = [{ personne: { personId: 70, libelle: 'Nouveau Romarin' }, rangFratrie: 2 }]
    })
    const retenus = liensCochesParDefaut(fiche(), s)
    expect(unionParDefaut(fiche())).toBe(9)
    expect(saisieLiensRetenus(fiche(), s, retenus, {}).enfants).toEqual([{ personne: { personId: 70, libelle: 'Nouveau Romarin' }, familleId: 9, rangFratrie: 2 }])
    expect(saisieLiensRetenus(fiche(), s, retenus, { 'enfant:personne:70': null }).enfants[0]?.familleId).toBeNull()
    expect(saisieLiensRetenus(fiche({ unions: [] }), s, retenus, {}).enfants[0]?.familleId).toBeNull()
  })

  it('reporte le couple de parents d’une reprise comme « enfant de ce couple »', () => {
    const orpheline = fiche({ parents: [] })
    const s = avecLiens((c) => {
      c.filiation.famille = { familyId: 4, libelle: 'Galène × Opale' }
    })
    const retenus = liensCochesParDefaut(orpheline, s)
    const resultat = demandesDeCompletion(orpheline, instantaneDeFiche(orpheline), s, {
      ecarts: [],
      retenus: [],
      liensRetenus: retenus,
      unionsDesEnfants: {},
      message: '',
    })

    expect(resultat.valide && resultat.corps[0]).toMatchObject({
      kind: 'ajout_lien',
      liens: [{ kind: 'enfant_de', subjectPersonId: 17, familyId: 4 }],
    })
    // Sur la fiche qui a déjà ses parents, le même couple est présent.
    expect(liensPourFiche(fiche(), s, [])[0]?.statut).toBe('present')
  })

  it('ne dépose pas un lien coché qui n’est pas proposable', () => {
    const s = avecLiens((c) => {
      c.filiation.parents = [{ personId: 30, libelle: 'Galène Bourrache' }]
    })
    expect(saisieLiensRetenus(fiche(), s, ['parent:personne:30'], {}).parents).toEqual([])
  })
})

describe('envoyerLesPhotosSurLaFiche : sur la fiche, puis en demande, jamais deux fois', () => {
  function photo(cle: number): PhotoAJoindre {
    return {
      cle,
      fichier: new File(['x'], `acte-${cle}.jpg`, { type: 'image/jpeg' }),
      apercu: null,
      description: cle === 1 ? ' Acte de naissance ' : '',
      etat: 'attente',
      message: null,
      rejouable: true,
    }
  }
  const media = (id: number) => ({ id, kind: 'preuve', status: 'en_attente', caption: null, sha256: 'abc', changeRequestId: null }) as unknown as Media

  it('téléverse sur la personne, porte chaque média par sa demande, et ne renvoie que ce qui manque', async () => {
    const photos = [photo(1), photo(2)]
    const televersees = new Map<number, Media>()
    const televerser = vi.fn().mockResolvedValueOnce(media(501)).mockResolvedValueOnce(media(502))
    const proposer = vi
      .fn<(m: Media, personId: number) => Promise<Demande | null>>()
      .mockResolvedValueOnce({ id: 900 } as Demande)
      .mockRejectedValueOnce(new Error('réseau'))
      .mockResolvedValueOnce({ id: 901 } as Demande)

    const premier = await envoyerLesPhotosSurLaFiche(photos, 17, televersees, televerser, proposer)

    expect(televerser.mock.calls[0]?.[0]).toMatchObject({ kind: 'preuve', subjectKind: 'personne', subjectId: 17, caption: 'Acte de naissance' })
    expect(televerser.mock.calls[1]?.[0]).not.toHaveProperty('caption')
    expect(premier).toEqual({ echecs: 1, demandeIds: [900] })
    expect(photos.map((p) => p.etat)).toEqual(['reussie', 'echec'])

    // Le second clic : la photo 2 est déjà téléversée, seule sa demande repart.
    const second = await envoyerLesPhotosSurLaFiche(photos, 17, televersees, televerser, proposer)
    expect(televerser).toHaveBeenCalledTimes(2)
    expect(proposer).toHaveBeenLastCalledWith(media(502), 17)
    expect(second).toEqual({ echecs: 0, demandeIds: [901] })
  })
})
