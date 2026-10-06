/**
 * L'avis global — **un** message non bloquant, affiché par-dessus l'écran,
 * quel que soit l'écran.
 *
 * Il n'existait aucun mécanisme de « toast » : chaque écran dit ses erreurs à
 * sa place (`MessageErreur`, le `role="status"` à côté du bouton), et c'est
 * très bien ainsi. Cet avis-ci ne sert qu'à ce qui n'a **pas d'écran pour le
 * dire** :
 *
 * - un écran qui n'a pas pu se charger, même après rechargement
 *   (`router/rechargement.ts`) — le clic n'a mené nulle part, il n'y a pas
 *   d'écran d'arrivée ;
 * - une erreur que personne n'a attrapée (`erreursGlobales.ts`).
 *
 * Ce n'est pas un canal de plus pour les écrans : une erreur qu'un écran sait
 * afficher s'affiche dans l'écran, à côté de ce qui l'a causée.
 *
 * Même parti que `useSession` : un singleton de module. **Une seule place** —
 * un nouvel avis remplace le précédent au lieu de s'empiler : dix erreurs en
 * rafale ne doivent pas couvrir l'écran de dix cartes. Il reste jusqu'à ce
 * qu'on le ferme : un message d'erreur qui s'efface seul n'a peut-être pas été
 * lu. `AvisGlobal.vue` le dessine, monté une fois dans `App.vue`.
 */
import { readonly, shallowRef } from 'vue'

export interface ActionDAvis {
  libelle: string
  executer: () => void
}

export interface Avis {
  /** Change à chaque avis : le même texte reposé doit se relire (lecteur d'écran). */
  id: number
  ton: 'info' | 'erreur'
  message: string
  action: ActionDAvis | null
}

const courant = shallowRef<Avis | null>(null)
let compteur = 0

export function afficherAvis(avis: { ton?: Avis['ton']; message: string; action?: ActionDAvis | null }): void {
  compteur += 1
  courant.value = { id: compteur, ton: avis.ton ?? 'info', message: avis.message, action: avis.action ?? null }
}

export function fermerAvis(): void {
  courant.value = null
}

export function useAvisGlobal() {
  return { avis: readonly(courant), fermer: fermerAvis }
}
