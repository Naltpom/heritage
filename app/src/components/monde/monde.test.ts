/**
 * Le Monde — la traduction des données en état de la maquette, sans WebGL.
 *
 * Ce que cette suite tient : rien ne s'anime sans donnée (pas de fumée sans
 * réponse de l'API, pas de pluie sans appel récent, pas de lanterne sans
 * invitation du jour), les cartes du tapis avancent avec leur ancienneté, la
 * tour et l'arbre restent bornés quels que soient les chiffres, et les
 * paramètres d'adresse ne laissent passer que des valeurs connues.
 *
 * Les courriels et libellés de ce jeu d'essai sont inventés (ADR 0006).
 */
import { describe, expect, it } from 'vitest'
import type { Chantier, LigneDuJournal } from '@/lib/api'
import { CARTES_MAX, ZONES, etatDuMonde, lireParametres, valeurDeZone, type DonneesDuMonde } from './monde'

const MAINTENANT = new Date('2026-10-16T13:00:00Z')

function ligne(partiel: Partial<LigneDuJournal>): LigneDuJournal {
  return {
    id: 1,
    occurredAt: '2026-10-16T12:00:00Z',
    actorUserId: 3,
    actorEmail: 'camille@exemple.fr',
    action: 'connexion',
    subjectType: 'Core\\User',
    subjectId: '3',
    subjectLabel: null,
    changes: null,
    ip: null,
    userAgent: null,
    impersonatorUserId: null,
    impersonatorEmail: null,
    ...partiel,
  }
}

function chantier(validees: number, sansSource: number): Chantier {
  const liste = (n: number) => ({ n, personnes: [] })
  return {
    fiches: { sansDateDeNaissance: liste(5), sansParents: liste(5), sansSource: liste(sansSource), nonVerifiees: liste(1) },
    activite: {
      demandesParMois: [{ mois: '2026-10', proposees: validees + 2, validees }],
      demandesEnAttente: 0,
      membres: { actifs: 0, connectesRecemment: 0, jours: 30 },
      actesEnAttente: 0,
    },
  }
}

const VIDE: DonneesDuMonde = {
  sante: null,
  attente: [],
  aTraiter: null,
  comptes: null,
  journal: [],
  appels: [],
  echecs24h: 0,
  invitationsActives: null,
  chantier: null,
}

describe('le monde sans donnée', () => {
  it('ne montre rien qui bouge et dit « – » plutôt qu’un zéro inventé', () => {
    const e = etatDuMonde(VIDE, MAINTENANT)
    expect(e.maison).toEqual({ connue: false, ok: false, lenteur: 0 })
    expect(e.file.cartes).toEqual([])
    expect(e.nuage.pluie).toBe(false)
    expect(e.portail.lanternes).toBe(0)
    expect(e.arbre).toEqual({ connu: false, fruits: 0, feuilles: 0 })
    expect(e.phare.alerte).toBe(false)
    for (const id of ['maison', 'file', 'comptes', 'portail', 'arbre'] as const) expect(valeurDeZone(id, e, VIDE), id).toBe('–')
  })
})

describe('la maison des archives', () => {
  it('mesure sa lenteur au seuil de la pastille, et la plafonne', () => {
    expect(etatDuMonde({ ...VIDE, sante: { ok: true, latenceMs: 400, le: 0 } }, MAINTENANT).maison.lenteur).toBe(0.5)
    expect(etatDuMonde({ ...VIDE, sante: { ok: true, latenceMs: 5000, le: 0 } }, MAINTENANT).maison.lenteur).toBe(1)
  })

  it('en panne, dit qu’elle ne répond pas', () => {
    const d = { ...VIDE, sante: { ok: false, latenceMs: 12, le: 0 } }
    const e = etatDuMonde(d, MAINTENANT)
    expect(e.maison).toEqual({ connue: true, ok: false, lenteur: 0 })
    expect(valeurDeZone('maison', e, d)).toBe('ne répond pas')
  })
})

describe('le tapis « À traiter »', () => {
  it('fait avancer une carte avec son ancienneté, jusqu’au bout en trois jours', () => {
    const d = {
      ...VIDE,
      aTraiter: 3,
      attente: [
        { cle: 'd1', nature: 'demande' as const, depuis: '2026-10-16T13:00:00Z' },
        { cle: 'r1', nature: 'inscription' as const, depuis: '2026-10-15T01:00:00Z' },
        { cle: 'g1', nature: 'rgpd' as const, depuis: '2026-10-01T00:00:00Z' },
      ],
    }
    expect(etatDuMonde(d, MAINTENANT).file.cartes.map((c) => [c.cle, c.avancee])).toEqual([
      ['d1', 0],
      ['r1', 0.5],
      ['g1', 1],
    ])
  })

  it('ne pose pas plus de cartes qu’il n’en tient, et le chiffre dit le reste', () => {
    const attente = Array.from({ length: 30 }, (_, i) => ({ cle: `d${i}`, nature: 'demande' as const, depuis: null }))
    const d = { ...VIDE, attente, aTraiter: 30 }
    const e = etatDuMonde(d, MAINTENANT)
    expect(e.file.cartes).toHaveLength(CARTES_MAX)
    expect(valeurDeZone('file', e, d)).toBe('30 en attente')
  })
})

describe('la tour et le portail', () => {
  it('allument une fenêtre par connexion du jour, et pas celles de la veille', () => {
    const d = {
      ...VIDE,
      comptes: { tous: 12, en_attente: 0, actif: 12, suspendu: 0, admins: 1 },
      journal: [ligne({ id: 1 }), ligne({ id: 2 }), ligne({ id: 3, occurredAt: '2026-10-14T12:00:00Z' })],
    }
    expect(etatDuMonde(d, MAINTENANT).comptes).toEqual({ etages: 3, fenetres: 18, allumees: 2 })
  })

  it('bornent la tour, même pour beaucoup de comptes', () => {
    const d = { ...VIDE, comptes: { tous: 900, en_attente: 0, actif: 900, suspendu: 0, admins: 1 } }
    expect(etatDuMonde(d, MAINTENANT).comptes.etages).toBe(6)
  })

  it('posent une lanterne par invitation utilisée aujourd’hui', () => {
    const d = { ...VIDE, invitationsActives: 2, journal: [ligne({ action: 'invitation_utilisee' })] }
    expect(etatDuMonde(d, MAINTENANT).portail).toEqual({ actives: 2, lanternes: 1 })
  })
})

describe('le nuage du modèle', () => {
  it('pleut dix minutes après un appel, pas plus', () => {
    const recent = { ...VIDE, appels: [ligne({ id: 9, action: 'appel_modele_langage', occurredAt: '2026-10-16T12:55:00Z' })] }
    const ancien = { ...VIDE, appels: [ligne({ id: 9, action: 'appel_modele_langage', occurredAt: '2026-10-16T12:30:00Z' })] }
    expect(etatDuMonde(recent, MAINTENANT).nuage).toEqual({ pluie: true, dernierAppel: 9, parJour: 1 })
    expect(etatDuMonde(ancien, MAINTENANT).nuage.pluie).toBe(false)
  })
})

describe('l’arbre et le phare', () => {
  it('compte un fruit pour dix fiches à compléter, une feuille par demande validée ce mois-ci', () => {
    expect(etatDuMonde({ ...VIDE, chantier: chantier(4, 21) }, MAINTENANT).arbre).toEqual({ connu: true, fruits: 4, feuilles: 4 })
    expect(etatDuMonde({ ...VIDE, chantier: chantier(500, 9000) }, MAINTENANT).arbre).toEqual({ connu: true, fruits: 24, feuilles: 30 })
  })

  it('passe le phare en alerte au seuil de la pastille', () => {
    expect(etatDuMonde({ ...VIDE, echecs24h: 4 }, MAINTENANT).phare.alerte).toBe(false)
    expect(etatDuMonde({ ...VIDE, echecs24h: 5 }, MAINTENANT).phare.alerte).toBe(true)
  })
})

describe('les paramètres d’adresse', () => {
  it('lisent le thème, la zone et la visite', () => {
    expect(lireParametres('#theme=dark&zone=phare&tour=0')).toEqual({ theme: 'nuit', zone: 'phare', visite: false })
    expect(lireParametres('#theme=light')).toEqual({ theme: 'clair', zone: null, visite: true })
  })

  it('ignorent une zone ou un thème inconnus', () => {
    expect(lireParametres('#theme=rose&zone=cave')).toEqual({ theme: null, zone: null, visite: true })
  })
})

describe('les zones', () => {
  it('sont sept, chacune avec sa phrase pour le panneau', () => {
    expect(ZONES.map((z) => z.id)).toEqual(['maison', 'file', 'comptes', 'portail', 'nuage', 'arbre', 'phare'])
    for (const z of ZONES) expect(z.phrase.length, z.id).toBeGreaterThan(40)
  })
})
