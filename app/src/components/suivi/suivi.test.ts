/**
 * Le tableau de suivi — sa logique, sans navigateur.
 *
 * Ce que cette suite tient : la pastille de l'en-tête ne dit qu'un motif, le
 * plus grave ; un jour sans activité est un zéro et non un trou ; la recherche
 * de l'historique ignore accents et casse ; les appels au modèle se comptent
 * par modèle, sans rien lire de leur contenu.
 *
 * Les courriels et libellés de ce jeu d'essai sont inventés (ADR 0006).
 */
import { describe, expect, it } from 'vitest'
import type { LigneDuJournal } from '@/lib/api'
import { ACTIONS_DU_JOURNAL } from '@/lib/api/suivi'
import {
  activiteParJour,
  depuis,
  detailsDAppel,
  etatGlobal,
  familleDe,
  filtrerTexte,
  fraicheur,
  libelleAction,
  modelesAppeles,
  natureDAppel,
  pointsDeCourbe,
  repartition,
} from './suivi'

function ligne(partiel: Partial<LigneDuJournal>): LigneDuJournal {
  return {
    id: 1,
    occurredAt: '2026-10-16T10:00:00+02:00',
    actorUserId: 3,
    actorEmail: 'camille@exemple.fr',
    action: 'connexion',
    subjectType: 'Core\\User',
    subjectId: '3',
    subjectLabel: 'Camille Essai',
    changes: null,
    ip: '127.0.0.1',
    userAgent: null,
    impersonatorUserId: null,
    impersonatorEmail: null,
    ...partiel,
  }
}

const SAINE = { ok: true, latenceMs: 40, le: 0 }

describe('la pastille de l’en-tête', () => {
  it('attend la première mesure avant de rien affirmer', () => {
    expect(etatGlobal({ sante: null, aTraiter: null, connexionsEchouees: 0 }).niveau).toBe('inconnu')
  })

  it('est rouge quand la base ne répond pas, quoi qu’il arrive par ailleurs', () => {
    const e = etatGlobal({ sante: { ok: false, latenceMs: 12, le: 0 }, aTraiter: 4, connexionsEchouees: 30 })
    expect(e).toEqual({ niveau: 'panne', libelle: 'La base ne répond pas' })
  })

  it('est orange sur une réponse lente, puis sur des connexions échouées en rafale', () => {
    expect(etatGlobal({ sante: { ok: true, latenceMs: 1200, le: 0 }, aTraiter: 0, connexionsEchouees: 0 }).niveau).toBe('attention')
    expect(etatGlobal({ sante: SAINE, aTraiter: 0, connexionsEchouees: 5 }).libelle).toBe('5 connexions échouées en 24 h')
  })

  it('reste verte quand des demandes attendent : c’est le travail ordinaire, pas une alerte', () => {
    expect(etatGlobal({ sante: SAINE, aTraiter: 12, connexionsEchouees: 4 }).niveau).toBe('ok')
  })
})

describe('la fraîcheur', () => {
  it('dit « jamais » avant la première réponse, puis compte en secondes et en minutes', () => {
    expect(fraicheur(null, 0)).toBe('jamais mis à jour')
    expect(fraicheur(1000, 2000)).toBe('à l’instant')
    expect(fraicheur(0, 12_000)).toBe('il y a 12 s')
    expect(fraicheur(0, 180_000)).toBe('il y a 3 min')
  })
})

describe('les actions du journal', () => {
  it('ont toutes un libellé français : aucune ne s’affiche par son code', () => {
    for (const a of ACTIONS_DU_JOURNAL) expect(libelleAction(a), a).not.toBe(a)
  })

  it('rangent les connexions échouées dans la sécurité, les appels dans le modèle', () => {
    expect(familleDe('connexion_echouee')).toBe('securite')
    expect(familleDe('appel_modele_langage')).toBe('ia')
    expect(familleDe('action_ajoutee_demain')).toBe('arbre')
    expect(libelleAction('action_ajoutee_demain')).toBe('action_ajoutee_demain')
  })

  it('se répartissent par famille, familles vides comprises', () => {
    const r = repartition([ligne({ action: 'demande_soumise' }), ligne({ action: 'demande_validee' }), ligne({ action: 'connexion' })])
    expect(r.map((f) => [f.famille, f.n])).toEqual([
      ['demande', 2],
      ['arbre', 0],
      ['compte', 1],
      ['ia', 0],
      ['securite', 0],
    ])
  })
})

describe('les fenêtres sur le journal', () => {
  // Autour de midi UTC : le même jour calendaire dans tout fuseau de −11 h à +11 h.
  const maintenant = new Date('2026-10-16T13:00:00Z')

  it('ne gardent que les dernières heures', () => {
    const lignes = [ligne({ id: 1, occurredAt: '2026-10-16T12:00:00Z' }), ligne({ id: 2, occurredAt: '2026-10-14T12:00:00Z' })]
    expect(depuis(lignes, 24, maintenant.getTime()).map((l) => l.id)).toEqual([1])
  })

  it('comptent par jour, jours vides compris, du plus ancien au plus récent', () => {
    const jours = activiteParJour(
      [ligne({ occurredAt: '2026-10-16T12:00:00Z' }), ligne({ occurredAt: '2026-10-16T11:30:00Z' }), ligne({ occurredAt: '2026-10-14T12:00:00Z' })],
      3,
      maintenant,
    )
    expect(jours.map((j) => j.n)).toEqual([1, 0, 2])
    expect(jours.at(-1)!.jour).toBe('2026-10-16')
  })
})

describe('la recherche de l’historique', () => {
  const lignes = [
    ligne({ id: 1, subjectLabel: 'Hélène Essai' }),
    ligne({ id: 2, action: 'televersement', actorEmail: 'dominique@exemple.fr', subjectLabel: 'Acte de mariage' }),
  ]

  it('ignore accents et casse', () => {
    expect(filtrerTexte(lignes, 'HELENE').map((l) => l.id)).toEqual([1])
  })

  it('cherche aussi dans le libellé de l’action et dans le courriel', () => {
    expect(filtrerTexte(lignes, 'téléversement').map((l) => l.id)).toEqual([2])
    expect(filtrerTexte(lignes, 'dominique').map((l) => l.id)).toEqual([2])
  })

  it('rend tout pour une recherche vide', () => {
    expect(filtrerTexte(lignes, '  ')).toHaveLength(2)
  })
})

describe('les appels au modèle de langage', () => {
  const appels = [
    ligne({ action: 'appel_modele_langage', subjectType: 'Assistant\\Analyse', subjectLabel: 'modele-a' }),
    ligne({ action: 'appel_modele_langage', subjectType: 'Assistant\\Question', subjectLabel: 'modele-a' }),
    ligne({ action: 'appel_modele_langage', subjectType: 'Assistant\\Transcription', subjectLabel: 'modele-b' }),
    ligne({ action: 'connexion' }),
  ]

  it('se comptent par modèle, du plus appelé au moins appelé, avec leurs natures', () => {
    expect(modelesAppeles(appels)).toEqual([
      { modele: 'modele-a', n: 2, natures: ['Analyse de documents', 'Question à l’assistant'] },
      { modele: 'modele-b', n: 1, natures: ['Transcription vocale'] },
    ])
  })

  it('se nomment par leur nature, et une nature inconnue garde son nom court', () => {
    expect(natureDAppel('Question\\Redaction')).toBe('Rédaction de questions')
    expect(natureDAppel('Assistant\\Nouveau')).toBe('Nouveau')
  })

  it('disent leurs compteurs en clair', () => {
    expect(detailsDAppel({ tours: 3, texte_transmis: false, verdict: null })).toEqual(['tours : 3', 'texte transmis : non', 'verdict : —'])
    expect(detailsDAppel(null)).toEqual([])
  })
})

describe('la mini-courbe', () => {
  it('tient dans son cadre, et ne divise pas par zéro sur une série plate à zéro', () => {
    expect(pointsDeCourbe([], 100, 20)).toBe('')
    expect(pointsDeCourbe([0, 0], 100, 20)).toBe('0.0,19.0 100.0,19.0')
    expect(pointsDeCourbe([5, 10], 100, 20)).toBe('0.0,10.0 100.0,1.0')
  })
})
