import { describe, expect, it } from 'vitest'
import {
  brouillonVide,
  composantesARetirer,
  controlerDemande,
  corpsDeDemande,
  etatDeDemande,
  libelleNature,
  pastilleDeDemande,
} from './demandesRgpd'

/**
 * Les demandes RGPD (ADR 0016, révision du 3 octobre 2026), sans composant :
 * les libellés, l'état d'une demande, le contrôle du formulaire et le corps
 * envoyé.
 */

const RIEN = { annee: false, mois: false, jour: false }

describe('les libellés', () => {
  it('nomment chaque nature, et retombent sur « Demande »', () => {
    expect(libelleNature('retrait_partage')).toBe('Retirer une date de naissance partagée')
    expect(libelleNature('rectification')).toBe('Rectifier une donnée')
    expect(libelleNature('autre')).toBe('Autre demande')
    expect(libelleNature('inconnue')).toBe('Demande')
  })

  it('disent ce qu’un retrait retire, et rien pour une autre nature', () => {
    expect(composantesARetirer({ nature: 'retrait_partage', aRetirer: { ...RIEN, jour: true, mois: true } })).toBe(
      'jour et mois',
    )
    expect(composantesARetirer({ nature: 'autre', aRetirer: RIEN })).toBeNull()
  })

  it('disent l’état : en attente, appliquée ou refusée, avec la date', () => {
    expect(etatDeDemande({ decision: null, traiteeLe: null })).toBe('En attente')
    expect(etatDeDemande({ decision: 'appliquee', traiteeLe: '2026-10-05T10:00:00+02:00' })).toBe(
      'Appliquée le 5 octobre 2026',
    )
    expect(etatDeDemande({ decision: 'refusee', traiteeLe: '2026-10-05T10:00:00+02:00' })).toBe(
      'Refusée le 5 octobre 2026',
    )
    expect(pastilleDeDemande({ decision: null })).toBe('en_attente')
    expect(pastilleDeDemande({ decision: 'appliquee' })).toBe('validee')
    expect(pastilleDeDemande({ decision: 'refusee' })).toBe('refusee')
  })
})

describe('le formulaire', () => {
  it('part sur un retrait, prérempli de la composante cliquée', () => {
    expect(brouillonVide()).toEqual({ nature: 'retrait_partage', aRetirer: RIEN, message: '' })
    expect(brouillonVide({ mois: true }).aRetirer).toEqual({ ...RIEN, mois: true })
  })

  it('exige une composante pour un retrait, un message pour le reste', () => {
    expect(controlerDemande(brouillonVide())).toEqual({ composantes: 'Cochez au moins ce qu’il faut retirer.' })
    expect(controlerDemande(brouillonVide({ jour: true }))).toEqual({})
    expect(controlerDemande({ ...brouillonVide(), nature: 'rectification', message: '   ' })).toEqual({
      message: 'Décrivez votre demande.',
    })
    expect(controlerDemande({ ...brouillonVide(), nature: 'autre', message: 'x'.repeat(4001) })).toEqual({
      message: '4000 caractères au maximum.',
    })
  })

  it('n’envoie les composantes que pour un retrait, et le message s’il y en a un', () => {
    expect(corpsDeDemande(brouillonVide({ annee: true }))).toEqual({
      nature: 'retrait_partage',
      annee: true,
      mois: false,
      jour: false,
    })
    expect(corpsDeDemande({ nature: 'rectification', aRetirer: { ...RIEN, jour: true }, message: ' Mon prénom. ' })).toEqual({
      nature: 'rectification',
      message: 'Mon prénom.',
    })
  })
})
