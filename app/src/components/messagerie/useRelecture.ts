/**
 * Relire une conversation ouverte **à intervalle**, tant que la page est sous
 * les yeux — et tout de suite quand on y revient.
 *
 * Pas de WebSocket ni de file d'attente (pas de Redis, § 3 du dossier) : une
 * relecture toutes les quinze secondes suffit à une messagerie où l'on répond
 * en minutes, et elle s'arrête d'elle-même dans un onglet caché. La réponse
 * attendue arrive de toute façon aussi par la cloche et par le téléphone.
 */
import { onBeforeUnmount, onMounted } from 'vue'

export const INTERVALLE_DE_RELECTURE_MS = 15_000

export function useRelecture(relire: () => void, intervalle = INTERVALLE_DE_RELECTURE_MS): void {
  let minuterie: ReturnType<typeof setInterval> | null = null

  const visible = (): boolean => typeof document === 'undefined' || document.visibilityState === 'visible'

  function surVisibilite(): void {
    if (visible()) relire()
  }

  onMounted(() => {
    minuterie = setInterval(() => {
      if (visible()) relire()
    }, intervalle)
    document.addEventListener('visibilitychange', surVisibilite)
  })

  onBeforeUnmount(() => {
    if (minuterie !== null) clearInterval(minuterie)
    document.removeEventListener('visibilitychange', surVisibilite)
  })
}
