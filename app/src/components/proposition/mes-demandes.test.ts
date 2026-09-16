import { describe, expect, it } from 'vitest'
import type { Demande, ResumeDemande, StatutDemande } from '@/lib/api'
import {
  FILTRES,
  LIBELLES_NATURE,
  lienDeReprise,
  messageQuandVide,
  motifDepuisDemande,
  motifsARecuperer,
  pastilleDeStatut,
} from './mes-demandes'

/**
 * Ce que ces tests protègent : **la décision d'une demande revient à son
 * auteur.**
 *
 * Le serveur faisait déjà tout — refus motivé, motif obligatoire, motif stocké,
 * motif servi, notification écrite. Il manquait le chemin : aucun écran
 * n'affichait rien de tout cela, et le seul moyen de lire le motif de son propre
 * refus était de taper `/demandes/6` dans la barre d'adresse.
 *
 * Deux décisions de cet écran se prouvent ici, sans monter quoi que ce soit, et
 * ce sont celles qui se cassent en silence :
 *
 * 1. **quelles demandes exigent une seconde lecture** — la liste ne porte pas
 *    `decisionMessage`, et lire le détail de chaque ligne pour une clé qui
 *    n'existe que sur les refus serait une requête par demande ;
 * 2. **où mène « Refaire cette demande »** — un lien qui mène à un refus est
 *    pire qu'un lien absent, et une modification sans cible n'a pas de
 *    formulaire où retourner.
 */

function resume(fragment: Partial<ResumeDemande> & { id: number }): ResumeDemande {
  return {
    kind: 'creation_personne',
    status: 'en_attente',
    authorUserId: 17,
    authorLabel: 'Opale Bourrache',
    targetKind: null,
    targetId: null,
    targetLabel: null,
    message: null,
    autoValidated: false,
    submittedAt: '2026-09-16T10:56:18+00:00',
    createdAt: '2026-09-16T10:56:18+00:00',
    ...fragment,
  }
}

describe('le motif n’est pas dans la liste : on ne le lit que là où il existe', () => {
  it('ne redemande le détail que des demandes refusées', () => {
    // `decisionMessage` n'est écrit que par un refus. Une demande **caduque**
    // n'en a pas : personne ne l'a refusée, la fiche a bougé sous elle
    // (ADR 0004). La lire serait une requête pour une clé absente.
    const elements = [
      resume({ id: 9, status: 'validee' }),
      resume({ id: 8, status: 'refusee' }),
      resume({ id: 7, status: 'caduque' }),
      resume({ id: 6, status: 'refusee' }),
      resume({ id: 5, status: 'en_attente' }),
      resume({ id: 4, status: 'brouillon' }),
      resume({ id: 3, status: 'annulee' }),
    ]

    expect(motifsARecuperer(elements)).toEqual([8, 6])
  })

  it('conserve l’ordre de la liste, et ne demande jamais deux fois le même', () => {
    const elements = [resume({ id: 8, status: 'refusee' }), resume({ id: 8, status: 'refusee' })]

    expect(motifsARecuperer(elements)).toEqual([8])
  })

  it('ne relit pas un motif déjà connu', () => {
    // Changer de filtre relit la liste ; les motifs, eux, ne bougent pas — une
    // décision est définitive.
    const elements = [resume({ id: 8, status: 'refusee' }), resume({ id: 6, status: 'refusee' })]

    expect(motifsARecuperer(elements, new Set([8]))).toEqual([6])
  })

  it('ne demande rien sur une liste vide', () => {
    expect(motifsARecuperer([])).toEqual([])
  })
})

describe('le motif tel qu’il s’affiche', () => {
  function detail(fragment: Partial<Demande>): Demande {
    return {
      id: 8,
      kind: 'creation_personne',
      status: 'refusee',
      authorLabel: 'Opale Bourrache',
      targetKind: null,
      targetId: null,
      targetLabel: null,
      message: null,
      decisionMessage: null,
      autoValidated: false,
      duplicateOfId: null,
      submittedAt: null,
      createdAt: '2026-09-16T10:56:18+00:00',
      ops: [],
      brouillons: [],
      liens: [],
      revisions: [],
      ...fragment,
    }
  }

  it('rend le mot de l’administrateur tel quel, avec qui et quand', () => {
    const motif = motifDepuisDemande(
      detail({
        decisionMessage: 'Le surnom Sarriette n’est appuyé par aucun acte.\nMerci de joindre la pièce.',
        decidedByLabel: 'Agate Fenouil',
        decidedAt: '2026-09-16T10:58:33+00:00',
      }),
    )

    // Aucune reformulation : c'est le mot d'un administrateur à un membre, et
    // le réécrire serait le réécrire.
    expect(motif?.message).toBe('Le surnom Sarriette n’est appuyé par aucun acte.\nMerci de joindre la pièce.')
    expect(motif?.parQui).toBe('Agate Fenouil')
    expect(motif?.quand).toBe('2026-09-16T10:58:33+00:00')
  })

  it('n’invente pas un décideur que la charge ne porte pas', () => {
    const motif = motifDepuisDemande(detail({ decisionMessage: 'Fiche fictive, sans acte.' }))

    expect(motif?.parQui).toBeNull()
    expect(motif?.quand).toBeNull()
  })

  it.each([
    ['absent', null],
    ['vide', ''],
    ['blanc', '   \n  '],
  ])('« %s » n’est pas un motif : c’est pas de motif du tout', (_nom, valeur) => {
    // Sans quoi l'écran dessinerait un panneau « Motif du refus » vide, qui se
    // lit comme un défaut d'affichage plutôt que comme une absence.
    expect(motifDepuisDemande(detail({ decisionMessage: valeur }))).toBeNull()
  })
})

describe('refaire une demande — ou dire honnêtement qu’on ne peut pas', () => {
  it('renvoie la création vers son formulaire, avec le paramètre de reprise', () => {
    const lien = lienDeReprise(resume({ id: 8, status: 'refusee', kind: 'creation_personne' }))

    expect(lien).toEqual({ name: 'proposition.creation', query: { refaire: '8' } })
  })

  it('renvoie la modification vers la fiche visée', () => {
    const lien = lienDeReprise(
      resume({ id: 6, status: 'caduque', kind: 'modification_personne', targetKind: 'personne', targetId: 483 }),
    )

    expect(lien).toEqual({
      name: 'proposition.modification',
      params: { id: 483 },
      query: { refaire: '6' },
    })
  })

  it('ne propose rien sur une modification sans cible', () => {
    // `proposition.modification` est montée sur `/personnes/:id(\d+)/proposer` :
    // sans identifiant, il n'y a pas d'écran où aller. On ne fabrique pas une
    // URL par concaténation pour s'en apercevoir en « page introuvable ».
    const lien = lienDeReprise(resume({ id: 6, status: 'refusee', kind: 'modification_personne', targetId: null }))

    expect(lien).toBeNull()
  })

  it.each([
    ['ajout_lien', 'ajout_lien'],
    ['ajout_media', 'ajout_media'],
    ['modification_famille', 'modification_famille'],
    ['modification_citation', 'modification_citation'],
  ] as const)('ne propose rien sur « %s », qui n’a pas de formulaire', (_nom, kind) => {
    expect(lienDeReprise(resume({ id: 8, status: 'refusee', kind }))).toBeNull()
  })

  it.each([
    ['validee', 'validee'],
    ['en_attente', 'en_attente'],
    ['brouillon', 'brouillon'],
    ['annulee', 'annulee'],
  ] as const)('ne propose rien sur « %s » : il n’y a rien à refaire', (_nom, status) => {
    // Une demande en attente se **retire**, elle ne se reprend pas ; une demande
    // validée est écrite dans l'arbre.
    expect(lienDeReprise(resume({ id: 8, status }))).toBeNull()
  })
})

describe('les libellés, et l’exhaustivité qui les tient', () => {
  it('nomme les six natures du contrat', () => {
    // `Record<NatureDemande, string>` fait échouer la **compilation** le jour où
    // le contrat en ajoute une : ce test garde le compte, le typeur garde la
    // liste.
    expect(Object.keys(LIBELLES_NATURE)).toHaveLength(6)
    expect(LIBELLES_NATURE.creation_personne).toBe('Ajout d’une personne')
  })

  it('donne une pastille aux six statuts, et un libellé aux deux que la pastille ignore', () => {
    const STATUTS: StatutDemande[] = ['brouillon', 'en_attente', 'validee', 'refusee', 'caduque', 'annulee']

    for (const statut of STATUTS) {
      const pastille = pastilleDeStatut(statut)
      // Une pastille neutre **sans** libellé s'affiche vide : elle se lit alors
      // comme un défaut d'affichage et non comme un état.
      expect(pastille.etat === 'neutre' ? pastille.libelle.length > 0 : true, statut).toBe(true)
    }
  })

  it('ne confond pas « refusée » et « devenue caduque »', () => {
    // Personne n'a refusé une demande caduque. Le dire à son auteur serait lui
    // faire lire un reproche que personne n'a formulé.
    expect(pastilleDeStatut('refusee').etat).toBe('refusee')
    expect(pastilleDeStatut('caduque').etat).toBe('caduque')
  })
})

describe('le vide — deux vides, deux phrases', () => {
  it('invite celui qui n’a encore rien proposé', () => {
    expect(messageQuandVide('')).toMatch(/rien proposé/i)
  })

  it('dit à celui qui filtre que ses autres demandes sont toujours là', () => {
    // Sans cette nuance, filtrer sur « Refusées » quand on n'a aucun refus se
    // lit comme « mes demandes ont disparu ».
    const message = messageQuandVide('refusee')

    expect(message).toMatch(/refusées/i)
    expect(message).toMatch(/Toutes/)
  })

  it('a un libellé pour chacun des filtres qu’il propose', () => {
    // Un filtre sans libellé rendrait « dans l'état «  » ».
    for (const choix of FILTRES) {
      expect(messageQuandVide(choix.valeur), choix.libelle).not.toMatch(/«\s*»/)
    }
  })
})
