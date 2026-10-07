/**
 * Le toast — une phrase de confirmation, en bas au centre, qui s'efface seule
 * (handoff « mode Administration », § 5) : « Demande n° 42 validée. »
 *
 * Un seul à la fois : le suivant remplace le précédent. Il dure 2,6 s. Le
 * composant qui l'affiche (`ToastAdministration`) est un `role="status"` : un
 * lecteur d'écran le lit sans que le focus bouge.
 *
 * État de portée module : l'écran qui décide et le cadre qui affiche ne sont
 * pas dans la même branche.
 */
import { readonly, ref } from 'vue'

export const DUREE_DU_TOAST = 2600

const message = ref<string | null>(null)
let minuterie: ReturnType<typeof setTimeout> | undefined

export function annoncer(texte: string): void {
  message.value = texte
  clearTimeout(minuterie)
  minuterie = setTimeout(() => {
    message.value = null
  }, DUREE_DU_TOAST)
}

export function useToast() {
  return { message: readonly(message) }
}

/** Remise à zéro entre deux tests. */
export function oublierToast(): void {
  clearTimeout(minuterie)
  message.value = null
}
