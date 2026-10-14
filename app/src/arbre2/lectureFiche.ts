/**
 * **La fiche de la personne choisie**, lue pour le volet de `/arbre` (PC) et sa
 * parenté pour le panneau du téléphone.
 *
 * Jusqu'au 2026-10-14, la lecture finissait en `.catch(() => {})` : une panne réseau,
 * une `403`, une `404` — ou une fiche que le serveur rendait restreinte alors que le
 * nœud du graphe ne l'était pas (un cercle qui a changé depuis) — laissaient « Lecture
 * de la fiche… » affiché sans fin. Chaque issue a maintenant son état :
 *
 *  - `aucune` : rien à lire (personne de choisie, ou un nœud en attente, qui n'existe
 *    que dans sa demande) ;
 *  - `lecture` ;
 *  - `complete` : la fiche, et la parenté que le serveur calcule (on ne la recalcule pas) ;
 *  - `restreinte` : le serveur ne la montre qu'à ses proches ;
 *  - `erreur` : le message d'`analyserErreur()`, et « Réessayer » (`reessayer`).
 *
 * Choisir une autre personne abandonne la lecture en cours ; sa réponse tardive
 * n'écrit rien.
 */
import { getCurrentScope, onScopeDispose, shallowRef, watch, type ShallowRef } from 'vue'
import { analyserErreur, lireFichePersonne, type FichePersonne, type FichePersonneComplete } from '@/lib/api'

export type EtatDeLaFiche =
  | { etat: 'aucune' }
  | { etat: 'lecture' }
  | { etat: 'complete'; fiche: FichePersonneComplete }
  | { etat: 'restreinte' }
  | { etat: 'erreur'; message: string }

export type LireLaFiche = (id: number, signal: AbortSignal) => Promise<FichePersonne>

export interface LectureDeLaFiche {
  etat: ShallowRef<EtatDeLaFiche>
  /** Relance la lecture de la personne choisie (après une erreur). */
  reessayer: () => void
}

export function useLectureDeLaFiche(personId: () => number | null, lire: LireLaFiche = lireFichePersonne): LectureDeLaFiche {
  const etat = shallowRef<EtatDeLaFiche>({ etat: 'aucune' })
  let lecture: AbortController | null = null

  function lancer(id: number | null): void {
    lecture?.abort()
    lecture = null
    if (id === null) {
      etat.value = { etat: 'aucune' }
      return
    }
    const controleur = new AbortController()
    lecture = controleur
    etat.value = { etat: 'lecture' }
    lire(id, controleur.signal).then(
      (fiche) => {
        if (lecture !== controleur) return
        etat.value = fiche.visibilite === 'complete' ? { etat: 'complete', fiche } : { etat: 'restreinte' }
      },
      (e: unknown) => {
        if (lecture !== controleur || controleur.signal.aborted) return
        etat.value = { etat: 'erreur', message: analyserErreur(e).message }
      },
    )
  }

  watch(personId, lancer, { immediate: true })
  if (getCurrentScope()) onScopeDispose(() => lecture?.abort())

  return { etat, reessayer: () => lancer(personId()) }
}
