import { describe, expect, it } from 'vitest'
import { ErreurApi, type DemandeProposee, type PortraitPropose } from '@/lib/api'
import {
  bilanPortraits,
  clesPortraitsParDefaut,
  destinationDuPortrait,
  documentDuPortrait,
  empechementDuPortrait,
  executerPortraits,
  legendeDePortrait,
  nomDuBrouillon,
  personneDuPortrait,
  planDesPortraits,
  portraitsPartants,
  type EtapeDepot,
  NOMBRE_MAX_DOCUMENTS,
  TAILLE_MAX_DOCUMENT,
  VALEUR_MASQUEE,
  cibleExistante,
  clesCocheesParDefaut,
  datesAffichables,
  estAnnulation,
  estPdf,
  executerDepot,
  libelleAvant,
  libelleDepot,
  mentionQuota,
  messageEchecDepot,
  peutAnalyser,
  planDeDepot,
  titreErreurAnalyse,
  trierDocuments,
} from './assistant'

/**
 * La logique de l'assistant, sur des valeurs.
 *
 * Ce qui compte ici, c'est ce qu'un gabarit ne montrerait pas d'un coup d'œil :
 * qu'un septième document est refusé **avec son nom**, qu'une demande décochée
 * ne part pas, qu'un échec n'arrête pas la suite, et que les pièces vont à la
 * bonne fiche — une seule fois.
 */

function fichier(nom: string, taille = 1000, type = 'image/jpeg'): File {
  return new File([new Uint8Array(taille)], nom, { type })
}

function demande(surcharge: Partial<DemandeProposee> & Pick<DemandeProposee, 'cle' | 'corps'>): DemandeProposee {
  return {
    titre: `Demande ${surcharge.cle}`,
    justification: 'Acte de naissance (document 1).',
    confiance: 'haute',
    personnes: [],
    changements: [],
    ...surcharge,
  }
}

const CREATION = demande({
  cle: 'd1',
  corps: { kind: 'creation_personne', brouillons: [], liens: [] },
})
const MODIFICATION_12 = demande({
  cle: 'd2',
  corps: { kind: 'modification_personne', targetKind: 'personne', targetId: 12, ops: [] },
})
const LIEN_12 = demande({
  cle: 'd3',
  corps: { kind: 'ajout_lien', targetKind: 'personne', targetId: 12, liens: [] },
})
const LIEN_30 = demande({
  cle: 'd4',
  corps: { kind: 'ajout_lien', targetKind: 'personne', targetId: 30, liens: [] },
})

describe('les documents : la taille et le nombre, rien d’autre', () => {
  it('refuse ce qui ferait dépasser 18 Mo en tout, en comptant ce qui est déjà joint', () => {
    const lourd = fichier('photo.jpg', 7 * 1024 * 1024)
    const verdict = trierDocuments(1, [lourd, fichier('acte.pdf', 5 * 1024 * 1024)], 8 * 1024 * 1024)

    expect(verdict.acceptes.map((f) => f.name)).toEqual(['photo.jpg'])
    expect(verdict.refus[0]).toContain('pour tous les documents')
  })

  it('accepte jusqu’à six documents et refuse le septième en le nommant', () => {
    const selection = Array.from({ length: 7 }, (_, i) => fichier(`acte-${i + 1}.jpg`))
    const verdict = trierDocuments(0, selection)

    expect(verdict.acceptes).toHaveLength(NOMBRE_MAX_DOCUMENTS)
    expect(verdict.refus).toHaveLength(1)
    expect(verdict.refus[0]).toContain('acte-7.jpg')
    expect(verdict.refus[0]).toContain('6 documents au plus')
  })

  it('compte les documents déjà joints', () => {
    const verdict = trierDocuments(5, [fichier('a.jpg'), fichier('b.jpg')])
    expect(verdict.acceptes.map((f) => f.name)).toEqual(['a.jpg'])
    expect(verdict.refus).toHaveLength(1)
  })

  it('refuse un fichier de plus de 10 Mio avec un message lisible, sans compter sa place', () => {
    const gros = fichier('registre.pdf', TAILLE_MAX_DOCUMENT + 1, 'application/pdf')
    const verdict = trierDocuments(5, [gros, fichier('petit.jpg')])

    expect(verdict.acceptes.map((f) => f.name)).toEqual(['petit.jpg'])
    expect(verdict.refus[0]).toContain('registre.pdf')
    expect(verdict.refus[0]).toContain('10 Mio')
  })

  it('refuse un fichier vide', () => {
    expect(trierDocuments(0, [fichier('vide.jpg', 0)]).refus[0]).toContain('vide')
  })

  it('reconnaît un PDF même sans type annoncé', () => {
    expect(estPdf({ type: '', name: 'ACTE.PDF' })).toBe(true)
    expect(estPdf({ type: 'image/png', name: 'photo.png' })).toBe(false)
  })
})

describe('analyser : il faut quelque chose à lire', () => {
  it('exige un texte ou un document', () => {
    expect(peutAnalyser('', 0)).toBe(false)
    expect(peutAnalyser('   ', 0)).toBe(false)
    expect(peutAnalyser('Marie Dupont, née en 1921', 0)).toBe(true)
    expect(peutAnalyser('', 1)).toBe(true)
  })

  it('refuse un texte au-delà de 8 000 caractères', () => {
    expect(peutAnalyser('a'.repeat(8001), 0)).toBe(false)
  })

  it('annonce la règle avant le premier appel, puis le restant', () => {
    expect(mentionQuota(null)).toBe('20 analyses par jour')
    expect(mentionQuota(19)).toContain('19 analyses')
    expect(mentionQuota(1)).toContain('1 analyse possible')
    expect(mentionQuota(0)).toContain('Plus aucune')
  })

  it('titre clairement le quota, l’indisponibilité et l’échec du modèle', () => {
    expect(titreErreurAnalyse(new ErreurApi(429, 'trop_de_tentatives', 'Quota.'))).toBe('Quota du jour atteint')
    expect(titreErreurAnalyse(new ErreurApi(503, 'assistant_indisponible', 'Indisponible.'))).toBe(
      'Assistant indisponible',
    )
    expect(titreErreurAnalyse(new ErreurApi(502, 'assistant_erreur', 'Échec.'))).toContain('pas pu conclure')
    expect(titreErreurAnalyse(new ErreurApi(422, 'donnees_invalides', 'Texte vide.'))).toBeNull()
  })

  it('ne prend pas une annulation pour une erreur', () => {
    expect(estAnnulation(new ErreurApi(0, 'client_requete_annulee', 'Requête interrompue.'))).toBe(true)
    expect(estAnnulation(new ErreurApi(0, 'client_reseau_indisponible', '…'))).toBe(false)
  })
})

describe('la relecture', () => {
  it('coche toutes les demandes au départ', () => {
    expect([...clesCocheesParDefaut([CREATION, MODIFICATION_12])]).toEqual(['d1', 'd2'])
  })

  it('ne donne jamais de dates pour une personne restreinte', () => {
    expect(
      datesAffichables({ personId: 4, displayName: 'Opale', ligneDates: '1990 –', visibilite: 'restreinte' }),
    ).toBeNull()
    expect(
      datesAffichables({ personId: 5, displayName: 'Jean', ligneDates: '1890 – 1960', visibilite: 'complete' }),
    ).toBe('1890 – 1960')
  })

  it('dit « valeur masquée » sur une modification, rien sur une création', () => {
    const changement = { libelle: 'Date de naissance', avant: null, apres: '3 mars 1921' }
    expect(libelleAvant(changement, MODIFICATION_12)).toBe(VALEUR_MASQUEE)
    expect(libelleAvant(changement, CREATION)).toBeNull()
    expect(libelleAvant({ ...changement, avant: '1920' }, MODIFICATION_12)).toBe('1920')
  })

  it('désigne la fiche existante visée, et aucune pour une création', () => {
    expect(cibleExistante(CREATION)).toBeNull()
    expect(cibleExistante(MODIFICATION_12)).toBe(12)
    expect(cibleExistante(LIEN_30)).toBe(30)
  })

  it('accorde le bouton au nombre', () => {
    expect(libelleDepot(0)).toBe('Aucune demande cochée')
    expect(libelleDepot(1)).toBe('Déposer la demande')
    expect(libelleDepot(3)).toBe('Déposer les 3 demandes')
  })
})

describe('le plan de dépôt', () => {
  const docs = [fichier('acte.jpg'), fichier('livret.pdf', 10, 'application/pdf')]

  it('ne contient que les demandes cochées, dans l’ordre de l’analyse', () => {
    const plan = planDeDepot([CREATION, MODIFICATION_12, LIEN_30], new Set(['d4', 'd1']), docs, true)
    expect(plan.map((e) => e.cle)).toEqual(['d1', 'd4'])
    expect(plan.every((e) => e.etat === 'attente' && e.demandeId === null)).toBe(true)
  })

  it('joint les documents aux fiches existantes, une fois par fiche, jamais à une création', () => {
    const plan = planDeDepot(
      [CREATION, MODIFICATION_12, LIEN_12, LIEN_30],
      new Set(['d1', 'd2', 'd3', 'd4']),
      docs,
      true,
    )
    expect(plan.map((e) => e.cibleDesPreuves)).toEqual([null, 12, null, 30])
    expect(plan.map((e) => e.preuves.length)).toEqual([0, 2, 0, 2])
    expect(plan[1]?.preuves.map((p) => p.nom)).toEqual(['acte.jpg', 'livret.pdf'])
  })

  it('ne joint rien quand l’option est décochée ou sans document', () => {
    expect(planDeDepot([MODIFICATION_12], new Set(['d2']), docs, false)[0]?.preuves).toEqual([])
    expect(planDeDepot([MODIFICATION_12], new Set(['d2']), [], true)[0]?.cibleDesPreuves).toBeNull()
  })
})

describe('l’exécution : séquentielle, un échec n’arrête pas la suite', () => {
  it('dépose une par une, dans l’ordre, et garde un état par demande', async () => {
    const journal: string[] = []
    const plan = planDeDepot([CREATION, MODIFICATION_12, LIEN_30], new Set(['d1', 'd2', 'd4']), [], true)
    let enVol = 0

    await executerDepot(plan, {
      deposer: async (cle) => {
        enVol++
        expect(enVol).toBe(1)
        journal.push(cle)
        await Promise.resolve()
        enVol--
        if (cle === 'd2') throw new ErreurApi(422, 'donnees_invalides', 'Refusée.')
        return { id: cle === 'd1' ? 101 : 104 }
      },
      televerser: () => Promise.resolve(),
    })

    expect(journal).toEqual(['d1', 'd2', 'd4'])
    expect(plan.map((e) => e.etat)).toEqual(['reussi', 'echec', 'reussi'])
    expect(plan.map((e) => e.demandeId)).toEqual([101, null, 104])
  })

  it('envoie les pièces après le dépôt réussi, à la fiche visée, et rapporte chaque échec', async () => {
    const envois: string[] = []
    const plan = planDeDepot(
      [MODIFICATION_12, LIEN_30],
      new Set(['d2', 'd4']),
      [fichier('a.jpg'), fichier('b.jpg')],
      true,
    )

    await executerDepot(plan, {
      deposer: (cle) => {
        if (cle === 'd4') return Promise.reject(new ErreurApi(404, 'introuvable', 'Inconnue.'))
        return Promise.resolve({ id: 7 })
      },
      televerser: ({ index, personId, legende }) => {
        envois.push(`${index}→${personId}`)
        expect(legende).toContain('demande n° 7')
        return index === 0 ? Promise.reject(new ErreurApi(429, 'trop_de_tentatives', 'Trop.')) : Promise.resolve()
      },
    })

    // La demande d4 a échoué : ses pièces ne partent pas.
    expect(envois).toEqual(['0→12', '1→12'])
    expect(plan[0]?.preuves.map((p) => p.etat)).toEqual(['echec', 'reussi'])
    expect(plan[1]?.preuves.map((p) => p.etat)).toEqual(['attente', 'attente'])
  })

  it('au nouvel essai, ne redépose pas ce qui est passé mais reprend ses pièces', async () => {
    const plan = planDeDepot([MODIFICATION_12], new Set(['d2']), [fichier('a.jpg')], true)
    let depots = 0
    let essais = 0
    const operations = {
      deposer: () => {
        depots++
        return Promise.resolve({ id: 9 })
      },
      televerser: () => (++essais === 1 ? Promise.reject(new Error('réseau')) : Promise.resolve()),
    }

    await executerDepot(plan, operations)
    await executerDepot(plan, operations)

    expect(depots).toBe(1)
    expect(plan[0]?.preuves[0]?.etat).toBe('reussi')
  })

  it('dit l’expiration et le double dépôt en clair', () => {
    expect(messageEchecDepot(new ErreurApi(404, 'analyse_introuvable', 'Introuvable.'))).toBe(
      'L’analyse a expiré, relancez-la.',
    )
    expect(messageEchecDepot(new ErreurApi(409, 'deja_deposee', 'Déjà.'))).toContain('déjà été déposée')
    expect(messageEchecDepot(new ErreurApi(422, 'donnees_invalides', 'Champ refusé.'))).toBe('Champ refusé.')
  })
})

describe('les portraits repérés', () => {
  const CREATION_MARIE = demande({
    cle: 'd1',
    corps: {
      kind: 'creation_personne',
      brouillons: [
        {
          localRef: '#p1',
          givenNames: 'Marie',
          surname: 'Dupont',
          marriedName: null,
          nickname: null,
          sex: 'feminin',
          deceased: true,
          birth: null,
          death: null,
          note: null,
        },
      ],
      liens: [],
    },
  })

  function portrait(surcharge: Partial<PortraitPropose> & Pick<PortraitPropose, 'cle'>): PortraitPropose {
    return {
      document: 0,
      cadre: { haut: 100, gauche: 300, bas: 500, droite: 700 },
      justification: 'Photo de mariage (document 1).',
      personId: null,
      personne: null,
      demandeCle: null,
      brouillonRef: null,
      ...surcharge,
    }
  }

  const EXISTANT = portrait({
    cle: 'r1',
    personId: 12,
    personne: { personId: 12, displayName: 'Jean Dupont', ligneDates: '1890 – 1960', visibilite: 'complete' },
  })
  const RESTREINT = portrait({
    cle: 'r2',
    personId: 202,
    personne: { personId: 202, displayName: 'Opale Tilleul', ligneDates: '1994 –', visibilite: 'restreinte' },
  })
  const PROPOSE = portrait({ cle: 'r3', document: 1, demandeCle: 'd1', brouillonRef: 'p1' })

  function etape(cle: string, etat: EtapeDepot['etat'], demandeId: number | null = null) {
    return { cle, etat, demandeId }
  }

  it('nomme la personne proposée par son brouillon, retrouvé par `#<brouillonRef>`', () => {
    expect(nomDuBrouillon([CREATION_MARIE], 'd1', 'p1')).toBe('Marie Dupont')
    expect(nomDuBrouillon([CREATION_MARIE], 'd1', 'p2')).toBeNull()
    expect(nomDuBrouillon([MODIFICATION_12], 'd2', 'p1')).toBeNull()

    const personne = personneDuPortrait(PROPOSE, [CREATION_MARIE])
    expect(personne).toEqual({ nom: 'Marie Dupont', personId: null, dates: null, nouvelle: true })
  })

  it('ne donne jamais les dates d’une personne restreinte', () => {
    expect(personneDuPortrait(EXISTANT, []).dates).toBe('1890 – 1960')
    expect(personneDuPortrait(RESTREINT, []).dates).toBeNull()
    expect(personneDuPortrait(RESTREINT, []).nom).toBe('Opale Tilleul')
  })

  it('prend le document au rang 0-based de l’ordre d’envoi, jamais un PDF', () => {
    const envoyes = [fichier('mariage.jpg'), fichier('acte.pdf', 10, 'application/pdf'), fichier('groupe.png', 10, 'image/png')]
    expect(documentDuPortrait({ document: 0 }, envoyes)?.name).toBe('mariage.jpg')
    expect(documentDuPortrait({ document: 2 }, envoyes)?.name).toBe('groupe.png')
    expect(documentDuPortrait({ document: 1 }, envoyes)).toBeNull()
    expect(documentDuPortrait({ document: 3 }, envoyes)).toBeNull()
  })

  it('coche tout par défaut, mais ne compte pas un portrait dont la création est décochée', () => {
    const tous = [EXISTANT, RESTREINT, PROPOSE]
    const cochees = clesPortraitsParDefaut(tous)
    expect([...cochees]).toEqual(['r1', 'r2', 'r3'])

    expect(portraitsPartants(tous, cochees, new Set(['d1']))).toBe(3)
    expect(portraitsPartants(tous, cochees, new Set())).toBe(2)
    expect(empechementDuPortrait(PROPOSE, new Set())).toContain('décochée')
    expect(portraitsPartants(tous, new Set(['r3']), new Set(['d1']))).toBe(1)
  })

  it('vise la fiche d’une personne existante', () => {
    expect(destinationDuPortrait(EXISTANT, [])).toEqual({ type: 'personne', personId: 12 })
  })

  it('vise le brouillon de la demande créée quand la création est déposée', () => {
    expect(destinationDuPortrait(PROPOSE, [etape('d1', 'reussi', 88)])).toEqual({
      type: 'brouillon',
      demandeId: 88,
      brouillonRef: 'p1',
    })
  })

  it('saute le portrait si la création a échoué ou était décochée', () => {
    const echec = destinationDuPortrait(PROPOSE, [etape('d1', 'echec')])
    expect(echec.type).toBe('saute')
    expect(echec.type === 'saute' && echec.motif).toContain('pas pu être déposée')

    const decochee = destinationDuPortrait(PROPOSE, [etape('d2', 'reussi', 5)])
    expect(decochee.type).toBe('saute')
    expect(decochee.type === 'saute' && decochee.motif).toContain('pas été cochée')
  })

  it('n’envoie que les portraits cochés, un par un, et un échec n’arrête pas les autres', async () => {
    const tous = [EXISTANT, RESTREINT, PROPOSE]
    const etapes = planDesPortraits(tous, new Set(['r1', 'r2', 'r3']), [CREATION_MARIE])
    expect(etapes.map((e) => e.titre)).toEqual([
      'Portrait de Jean Dupont',
      'Portrait de Opale Tilleul',
      'Portrait de Marie Dupont (nouvelle personne)',
    ])
    expect(planDesPortraits(tous, new Set(['r2']), [])).toHaveLength(1)

    const envois: string[] = []
    let enVol = 0
    await executerPortraits(etapes, [etape('d1', 'echec')], async ({ portrait, destination }) => {
      enVol++
      expect(enVol).toBe(1)
      await Promise.resolve()
      enVol--
      envois.push(`${portrait.cle}→${destination.type === 'personne' ? destination.personId : destination.demandeId}`)
      if (portrait.cle === 'r1') throw new Error('réseau')
    })

    expect(envois).toEqual(['r1→12', 'r2→202'])
    expect(etapes.map((e) => e.etat)).toEqual(['echec', 'reussi', 'saute'])
    expect(bilanPortraits(etapes)).toEqual({ envoyes: 1, echecs: 1, sautes: 1 })

    // « Réessayer » : la création passe cette fois ; ce qui est parti ne repart pas.
    envois.length = 0
    await executerPortraits(etapes, [etape('d1', 'reussi', 41)], async ({ portrait, destination }) => {
      envois.push(`${portrait.cle}→${destination.type === 'brouillon' ? `${destination.demandeId}/${destination.brouillonRef}` : destination.personId}`)
    })
    expect(envois).toEqual(['r1→12', 'r3→41/p1'])
    expect(bilanPortraits(etapes)).toEqual({ envoyes: 3, echecs: 0, sautes: 0 })
  })

  it('légende le portrait par sa justification, bornée à 255 caractères', () => {
    expect(legendeDePortrait({ justification: 'Photo de mariage.' })).toBe('Repéré par l’assistant : Photo de mariage.')
    expect(legendeDePortrait({ justification: 'x'.repeat(400) })).toHaveLength(255)
  })

  it('dit les demandes et les portraits sur le bouton', () => {
    expect(libelleDepot(2, 1)).toBe('Déposer les 2 demandes et le portrait')
    expect(libelleDepot(0, 2)).toBe('Envoyer les 2 portraits')
    expect(libelleDepot(0, 0)).toBe('Aucune demande cochée')
  })
})

describe('la dictée', () => {
  it('colle la phrase avec une espace, et une majuscule en début de phrase', async () => {
    const { ajouterDictee } = await import('./assistant')
    expect(ajouterDictee('', 'ma grand-tante est née en 1921')).toBe('Ma grand-tante est née en 1921')
    expect(ajouterDictee('Elle vivait à Brest', 'avec son frère')).toBe('Elle vivait à Brest avec son frère')
    expect(ajouterDictee('Fin.', 'nouvelle phrase')).toBe('Fin. Nouvelle phrase')
    expect(ajouterDictee('Texte', '   ')).toBe('Texte')
  })
})
