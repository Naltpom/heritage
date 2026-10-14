import { describe, expect, it } from 'vitest'
import type { ApercuDeSuppression, InventaireDeSuppression } from '@/lib/api'
import {
  corpsDeSuppression,
  envoiPossible,
  erreurDeMotif,
  lignesEmportees,
  MOTIF_MINIMUM,
} from './suppression'

/**
 * Ce que ces tests protègent : **l'écran ne peut pas proposer d'effacer ce qu'il
 * n'a pas montré**.
 *
 * C'est la seule capacité du produit qui détruit, et les deux garde-fous vivent
 * côté serveur. Ce qui se joue ici est plus étroit et tout aussi nécessaire : que
 * le bouton reste éteint tant que l'aperçu n'est pas arrivé, qu'il s'éteigne dès
 * que le serveur dit « bloquée », et que l'énumération montrée ne mente ni par
 * excès — « 0 note » qui fait chercher un risque inexistant — ni par défaut.
 */

function inventaire(surcharge: Partial<InventaireDeSuppression> = {}): InventaireDeSuppression {
  return {
    personneId: 17,
    displayName: 'Marne Dolomite Sureau',
    notes: 0,
    citations: 0,
    faits: 0,
    filiations: 0,
    unions: [],
    descendants: [],
    descendanceTotale: 0,
    medias: 0,
    membreId: null,
    invitationsActives: 0,
    dependances: [],
    ...surcharge,
  }
}

function apercu(surcharge: Partial<ApercuDeSuppression> = {}): ApercuDeSuppression {
  return {
    inventaire: inventaire(),
    resume: 'Suppression de la fiche « Marne Dolomite Sureau » : elle ne porte rien d’autre.',
    bloquee: false,
    raisons: [],
    ...surcharge,
  }
}

describe('le motif est obligatoire, et c’est la seule nature où il l’est', () => {
  it('refuse un motif vide en disant à quoi il sert', () => {
    expect(erreurDeMotif('   ')).toContain('trancher')
  })

  it('refuse « doublon » seul, et dit pourquoi', () => {
    const message = erreurDeMotif('doublon')

    expect(message).toContain('de quelle autre fiche')
    expect(message).toContain(String(MOTIF_MINIMUM))
  })

  it('accepte « doublon de 42 », qui est la limite qu’on cherchait', () => {
    expect(erreurDeMotif('doublon de 42')).toBeNull()
  })

  it('compte le motif après rognage : des espaces ne sont pas une justification', () => {
    expect(erreurDeMotif('  ok      ')).not.toBeNull()
  })
})

describe('l’énumération de ce qui part', () => {
  it('ne montre rien quand la fiche ne porte rien', () => {
    expect(lignesEmportees(inventaire())).toEqual([])
  })

  it('n’affiche pas les catégories vides : « 0 note » ferait chercher un risque inexistant', () => {
    const lignes = lignesEmportees(inventaire({ notes: 2 }))

    expect(lignes).toHaveLength(1)
    expect(lignes[0]?.libelle).toBe('2 notes de recherche')
  })

  it('accorde le singulier', () => {
    expect(lignesEmportees(inventaire({ citations: 1 }))[0]?.libelle).toBe('1 source citée')
  })

  it('distingue l’union qui disparaît de celle qui reste', () => {
    const lignes = lignesEmportees(
      inventaire({
        unions: [
          { familleId: 4, libelle: 'Cobalt Sureau × Marne Dolomite Sureau', enfants: 0, supprimee: false },
          { familleId: 5, libelle: 'Marne Dolomite Sureau', enfants: 0, supprimee: true },
        ],
      }),
    )

    expect(lignes[0]?.libelle).toContain('qui reste')
    expect(lignes[1]?.libelle).toContain('disparaît faute d’autre époux')
  })

  it('marque les blocages, et eux seuls', () => {
    const lignes = lignesEmportees(
      inventaire({
        notes: 1,
        medias: 2,
        descendants: [{ personneId: 55, displayName: 'Cobalt Sureau' }],
        descendanceTotale: 23,
      }),
    )

    expect(lignes.filter((ligne) => ligne.bloquante).map((ligne) => ligne.libelle)).toEqual([
      '2 preuves rattachées à cette fiche',
      '23 fiches qui descendent de celle-ci, toutes générations confondues',
      'Cobalt Sureau, dont cette fiche est le parent',
    ])
  })

  it('met le total de la branche **avant** les noms : c’est lui qui arrête la main', () => {
    // Un seul enfant direct, trois générations dessous. « Parent de Marne » se lit
    // comme une formalité ; « 3 fiches descendent de celle-ci » ne se lit pas ainsi.
    const bloquantes = lignesEmportees(
      inventaire({
        descendants: [{ personneId: 55, displayName: 'Marne Romarin' }],
        descendanceTotale: 3,
      }),
    ).filter((ligne) => ligne.bloquante)

    expect(bloquantes[0]?.libelle).toBe('3 fiches qui descendent de celle-ci, toutes générations confondues')
    expect(bloquantes[1]?.libelle).toContain('Marne Romarin')
  })

  it('accorde le singulier sur une descendance d’une seule fiche', () => {
    const bloquantes = lignesEmportees(
      inventaire({
        descendants: [{ personneId: 55, displayName: 'Marne Romarin' }],
        descendanceTotale: 1,
      }),
    ).filter((ligne) => ligne.bloquante)

    expect(bloquantes[0]?.libelle).toBe('1 fiche qui descend de celle-ci, toutes générations confondues')
  })
})

describe('les deux blocages qui ne parlent pas de l’arbre', () => {
  it('signale le compte dont la fiche est la racine, par son numéro et pas par son adresse', () => {
    const bloquantes = lignesEmportees(inventaire({ membreId: 4 })).filter((l) => l.bloquante)

    expect(bloquantes).toHaveLength(1)
    expect(bloquantes[0]?.libelle).toContain('compte membre n° 4')
    expect(bloquantes[0]?.libelle).toContain('relié à cette fiche')
    expect(bloquantes[0]?.libelle).not.toContain('@')
  })

  it('signale l’invitation en attente, et dit que le défaut est différé', () => {
    const bloquantes = lignesEmportees(inventaire({ invitationsActives: 1 })).filter((l) => l.bloquante)

    expect(bloquantes[0]?.libelle).toContain('à son acceptation')
  })
})

describe('ce que les autres modules accrochent à la fiche', () => {
  it('range parmi les blocages ce que le serveur dit bloquant, et nomme chaque nature', () => {
    const lignes = lignesEmportees(
      inventaire({
        dependances: [
          { nature: 'demarche', nombre: 2, bloquante: true },
          { nature: 'evenement_a_venir', nombre: 1, bloquante: true },
          { nature: 'tirage', nombre: 1, bloquante: true },
          { nature: 'autorisation_naissance', nombre: 1, bloquante: true },
        ],
      }),
    )

    expect(lignes.filter((l) => l.bloquante).map((l) => l.libelle)).toEqual([
      '2 démarches d’acte qui la concernent',
      '1 invitation à un événement à venir',
      '1 participation à un tirage au sort',
      'une autorisation signée de partager sa date de naissance',
    ])
  })

  it('montre les questions qui partent avec la fiche, sans bloquer', () => {
    const lignes = lignesEmportees(inventaire({ dependances: [{ nature: 'question', nombre: 3, bloquante: false }] }))

    expect(lignes).toEqual([{ libelle: '3 questions posées à son sujet', bloquante: false }])
  })

  it('affiche une nature inconnue plutôt que de la taire, avec le sort que dit le serveur', () => {
    const lignes = lignesEmportees(inventaire({ dependances: [{ nature: 'nouveaute', nombre: 2, bloquante: true }] }))

    expect(lignes).toEqual([{ libelle: '2 éléments liés à cette fiche (nouveaute)', bloquante: true }])
  })

  it('lit un inventaire d’avant les dépendances sans rien ajouter', () => {
    const ancien = inventaire()
    delete (ancien as Partial<InventaireDeSuppression>).dependances

    expect(lignesEmportees(ancien)).toEqual([])
  })
})

describe('le bouton d’envoi', () => {
  it('reste éteint tant que l’aperçu n’est pas arrivé', () => {
    expect(envoiPossible(null, 'doublon de la fiche 42')).toBe(false)
  })

  it('reste éteint quand le serveur dit « bloquée », quel que soit le motif', () => {
    expect(envoiPossible(apercu({ bloquee: true }), 'doublon de la fiche 42')).toBe(false)
  })

  it('reste éteint tant que le motif ne tient pas', () => {
    expect(envoiPossible(apercu(), 'doublon')).toBe(false)
  })

  it('s’allume quand les deux conditions sont réunies', () => {
    expect(envoiPossible(apercu(), 'doublon de la fiche 42')).toBe(true)
  })
})

describe('le corps déposé', () => {
  it('ne porte aucun op : c’est le serveur qui mesure ce que la suppression emporte', () => {
    const corps = corpsDeSuppression(17, '  doublon de la fiche 42  ')

    expect(corps).toEqual({
      kind: 'suppression_personne',
      targetKind: 'personne',
      targetId: 17,
      message: 'doublon de la fiche 42',
      soumettre: true,
    })
    expect(corps).not.toHaveProperty('ops')
  })
})
