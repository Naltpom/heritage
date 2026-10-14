import { createApp } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ErreurApi } from '@/lib/api'
import { CODE_ANNULE, CODE_RESEAU } from '@/lib/api/erreurs'
import { fermerAvis, useAvisGlobal } from './avisGlobal'
import { installerFiletDErreurs, MESSAGE_ERREUR_INATTENDUE, traiterErreurGlobale } from './erreursGlobales'

/**
 * Le filet des erreurs que personne n'a attrapées.
 *
 * Ce qui se casse en silence : un filet qui **avale** (plus rien dans la
 * console), un filet qui **crie** sur ce qui n'est pas une panne (partage
 * annulé, session tombée — déjà prise en charge ailleurs), et un filet qui
 * vole au rattrapage des morceaux disparus la chance de recharger la page.
 */

const { avis } = useAvisGlobal()

function dependances(chargement = false) {
  return {
    signalerEchecDeChargement: vi.fn(() => chargement),
    journal: vi.fn(),
    recharger: vi.fn(),
  }
}

beforeEach(() => fermerAvis())

describe('traiterErreurGlobale', () => {
  it('journalise et affiche un avis non bloquant, avec « Recharger »', () => {
    const deps = dependances()
    const erreur = new Error('Cannot read properties of undefined')

    expect(traiterErreurGlobale(erreur, deps)).toBe('signalee')
    expect(deps.journal).toHaveBeenCalledWith(erreur)
    expect(avis.value?.ton).toBe('erreur')
    expect(avis.value?.message).toBe(MESSAGE_ERREUR_INATTENDUE)

    avis.value?.action?.executer()
    expect(deps.recharger).toHaveBeenCalledTimes(1)
  })

  it('une erreur d’API garde le message d’`analyserErreur`, jamais un code réinterprété', () => {
    traiterErreurGlobale(new ErreurApi(0, CODE_RESEAU, 'Le serveur est injoignable.'), dependances())
    expect(avis.value?.message).not.toBe(MESSAGE_ERREUR_INATTENDUE)
    expect(avis.value?.message.length).toBeGreaterThan(0)
  })

  it('un morceau disparu va au rattrapage, et à lui seul', () => {
    const deps = dependances(true)
    expect(traiterErreurGlobale(new TypeError('Failed to fetch dynamically imported module'), deps)).toBe('chargement')
    expect(deps.journal).not.toHaveBeenCalled()
    expect(avis.value).toBeNull()
  })

  it('ni la session tombée, ni une requête annulée, ni un partage annulé ne sont des pannes', () => {
    const deps = dependances()
    const sessionTombee = new ErreurApi(401, 'authentification_requise', 'Connectez-vous.')
    const annulee = new ErreurApi(0, CODE_ANNULE, 'Annulée.')
    const partageAnnule = Object.assign(new Error('Share canceled'), { name: 'AbortError' })
    const lectureRefusee = Object.assign(new Error('play() failed'), { name: 'NotAllowedError' })

    for (const erreur of [sessionTombee, annulee, partageAnnule, lectureRefusee]) {
      expect(traiterErreurGlobale(erreur, deps)).toBe('ignoree')
    }
    expect(avis.value).toBeNull()
  })

  it('une promesse rejetée n’est pas journalisée deux fois : le navigateur l’a déjà écrite', () => {
    const deps = dependances()
    traiterErreurGlobale(new Error('perdue'), deps, false)
    expect(deps.journal).not.toHaveBeenCalled()
    expect(avis.value?.message).toBe(MESSAGE_ERREUR_INATTENDUE)
  })
})

describe('installerFiletDErreurs', () => {
  it('pose `app.config.errorHandler`, qui passe par le filet', () => {
    const app = createApp({ render: () => null })
    const deps = dependances()
    installerFiletDErreurs(app, deps, null)

    const erreur = new Error('dans un rendu')
    app.config.errorHandler?.(erreur, null, 'render function')

    expect(deps.journal).toHaveBeenCalledWith(erreur)
    expect(avis.value?.message).toBe(MESSAGE_ERREUR_INATTENDUE)
  })

  it('écoute `unhandledrejection`, sans le marquer traité', () => {
    const fenetre = new EventTarget()
    const deps = dependances()
    installerFiletDErreurs(createApp({ render: () => null }), deps, fenetre as unknown as Window)

    const evenement = Object.assign(new Event('unhandledrejection', { cancelable: true }), {
      reason: new Error('promesse abandonnée'),
    })
    fenetre.dispatchEvent(evenement)

    expect(avis.value?.message).toBe(MESSAGE_ERREUR_INATTENDUE)
    // Pas de `preventDefault` : la console du navigateur garde sa trace.
    expect(evenement.defaultPrevented).toBe(false)
  })
})
