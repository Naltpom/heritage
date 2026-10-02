/**
 * **Le champ reste au-dessus du clavier** du téléphone.
 *
 * Sur la page de connexion, le courriel et le mot de passe disparaissaient sous le
 * clavier dès qu'on les touchait (retour du 02/10) : le formulaire était centré sur
 * toute la hauteur, et dans l'application installée le navigateur ne remonte pas
 * toujours le champ de lui-même. Deux gestes ici :
 *
 *  - la hauteur du clavier est mesurée (`visualViewport`) et RÉSERVÉE en bas de la
 *    zone (`--clavier`), pour qu'il y ait de quoi défiler ;
 *  - le champ qui prend le focus est amené au milieu de la partie visible, une fois
 *    le clavier sorti.
 */
import { onBeforeUnmount, onMounted, type Ref } from 'vue'

/** La hauteur cachée par le clavier, en pixels : ce que l'écran a, moins ce qu'on voit. */
export function hauteurDuClavier(hauteurFenetre: number, hauteurVisible: number, decalageHaut: number): number {
  return Math.max(0, Math.round(hauteurFenetre - hauteurVisible - decalageHaut))
}

export function useChampAuDessusDuClavier(zone: Ref<HTMLElement | null>): void {
  const vv = typeof window !== 'undefined' ? window.visualViewport : null
  let minuterie = 0

  function mesurer(): void {
    if (!vv || !zone.value) return
    const h = hauteurDuClavier(window.innerHeight, vv.height, vv.offsetTop)
    zone.value.style.setProperty('--clavier', `${h}px`)
  }
  function surFocus(e: FocusEvent): void {
    const champ = e.target as HTMLElement | null
    if (!champ || !/^(INPUT|TEXTAREA|SELECT)$/.test(champ.tagName)) return
    // Le clavier met un instant à sortir : on attend qu'il ait pris sa place.
    window.clearTimeout(minuterie)
    minuterie = window.setTimeout(() => {
      mesurer()
      champ.scrollIntoView({ block: 'center', behavior: 'smooth' })
    }, 320)
  }

  onMounted(() => {
    zone.value?.addEventListener('focusin', surFocus)
    vv?.addEventListener('resize', mesurer)
  })
  onBeforeUnmount(() => {
    window.clearTimeout(minuterie)
    zone.value?.removeEventListener('focusin', surFocus)
    vv?.removeEventListener('resize', mesurer)
  })
}
