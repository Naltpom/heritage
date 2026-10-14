/**
 * Le filet des erreurs que **personne** n'a attrapées.
 *
 * Deux portes :
 *
 * - `app.config.errorHandler` — ce qui s'échappe d'un composant : rendu,
 *   `setup`, crochet de cycle de vie, observateur, et la promesse rendue par un
 *   gestionnaire d'événement `async` (Vue l'attend et y branche son filet) ;
 * - `unhandledrejection` — une promesse abandonnée (`void quelqueChose()`) qui
 *   rejette sans que rien ne l'attende.
 *
 * Une erreur qu'un composant traite n'arrive **jamais** ici : un `try/catch`
 * ou le triplet de `useAppelApi` l'arrête avant, un `onErrorCaptured` qui rend
 * `false` aussi. Le filet n'avale donc rien : il **journalise** et dit au
 * lecteur, par l'avis global (`avisGlobal.ts`), que quelque chose n'a pas
 * marché — au lieu d'un bouton qui ne fait rien.
 *
 * Ce qu'il laisse passer sans rien dire :
 *
 * - un **morceau manquant** après une mise en production : c'est l'affaire du
 *   rattrapage (`router/rechargement.ts`), qui recharge une fois ;
 * - la **session tombée** (`authentification_requise`) : le rattrapage de
 *   `useSession` renvoie déjà vers la connexion, un second message brouillerait
 *   le premier ; une requête **annulée** non plus ;
 * - `AbortError` (partage annulé, lecture interrompue) et `NotAllowedError`
 *   (lecture automatique refusée, permission) : des refus du navigateur ou du
 *   lecteur, pas des pannes.
 *
 * Le message vient d'`analyserErreur()` quand c'est une erreur d'API — un
 * écran ne réinterprète jamais un code, ce filet non plus (README, § 5).
 */
import type { App } from 'vue'
// `CODE_ANNULE` n'est pas réexporté par `@/lib/api` : c'est un code du client.
import { analyserErreur, CODE_ANNULE, estErreurApi } from '@/lib/api/erreurs'
import { afficherAvis } from './avisGlobal'

export const MESSAGE_ERREUR_INATTENDUE =
  'Une erreur inattendue s’est produite. Si l’écran ne répond plus, rechargez la page.'

/** Les refus du navigateur ou du lecteur, qui ne sont pas des pannes. */
const NOMS_IGNORES = new Set(['AbortError', 'NotAllowedError'])

export function estErreurAIgnorer(erreur: unknown): boolean {
  if (estErreurApi(erreur)) return analyserErreur(erreur).sessionPerdue || erreur.code === CODE_ANNULE
  if (erreur === null || typeof erreur !== 'object') return false
  const nom = (erreur as { name?: unknown }).name
  return typeof nom === 'string' && NOMS_IGNORES.has(nom)
}

export function messageDErreurGlobale(erreur: unknown): string {
  return estErreurApi(erreur) ? analyserErreur(erreur).message : MESSAGE_ERREUR_INATTENDUE
}

export type IssueDuFilet = 'chargement' | 'ignoree' | 'signalee'

export interface DependancesDuFilet {
  /** `RattrapageChargement.signalerEchec` : rend `true` s'il s'en est chargé. */
  signalerEchecDeChargement: (erreur: unknown) => boolean
  afficher?: typeof afficherAvis
  journal?: (...elements: unknown[]) => void
  recharger?: () => void
}

/**
 * Ce que le filet fait d'une erreur. `journaliser` est faux pour une promesse
 * rejetée : le navigateur l'a déjà écrite dans la console, puisqu'on ne la
 * marque pas traitée.
 */
export function traiterErreurGlobale(
  erreur: unknown,
  deps: DependancesDuFilet,
  journaliser = true,
): IssueDuFilet {
  if (deps.signalerEchecDeChargement(erreur)) return 'chargement'
  if (estErreurAIgnorer(erreur)) return 'ignoree'

  if (journaliser) (deps.journal ?? console.error)(erreur)
  const recharger = deps.recharger ?? (() => window.location.reload())
  ;(deps.afficher ?? afficherAvis)({
    ton: 'erreur',
    message: messageDErreurGlobale(erreur),
    action: { libelle: 'Recharger', executer: recharger },
  })
  return 'signalee'
}

/**
 * Pose les deux portes. `fenetre` vaut `null` hors navigateur (rendu serveur,
 * tests) : seul le gestionnaire de Vue est alors posé.
 */
export function installerFiletDErreurs(
  app: App,
  deps: DependancesDuFilet,
  fenetre: Pick<Window, 'addEventListener'> | null = typeof window === 'undefined' ? null : window,
): void {
  // Poser un `errorHandler` fait taire le `console.error` de Vue en production :
  // `traiterErreurGlobale` journalise à sa place.
  app.config.errorHandler = (erreur) => {
    traiterErreurGlobale(erreur, deps)
  }

  fenetre?.addEventListener('unhandledrejection', (evenement) => {
    traiterErreurGlobale(evenement.reason, deps, false)
  })
}
