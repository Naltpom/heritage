/**
 * **Glisser vers le bas pour fermer** un feuillet du téléphone.
 *
 * Le feuillet des filtres montrait une poignée qui invite à le tirer vers le bas, sans
 * aucun geste branché dessus : le doigt arrivait à la page, et le navigateur faisait
 * ce qu'il fait d'un glissement vers le bas en haut de page — il la RECHARGEAIT
 * (retour du 02/10). Deux choses ici :
 *
 *  - le geste existe : en haut du feuillet (défilement à zéro), tirer vers le bas le
 *    fait suivre le doigt, et le lâcher assez bas — ou d'un coup sec — le ferme ;
 *    sinon il revient en place ;
 *  - tant que le feuillet est ouvert, la page ne « tire » plus pour s'actualiser
 *    (`overscroll-behavior-y: contain` sur la racine).
 *
 * La décision est pure ({@link doitFermer}) et testée ; le reste n'est que de la
 * colle DOM.
 */
import { onBeforeUnmount, watch, type Ref } from 'vue'

/** Assez bas, ou assez vite : le feuillet se ferme. */
export function doitFermer(deplacement: number, dureeMs: number): boolean {
  if (deplacement >= 90) return true
  const vitesse = deplacement / Math.max(1, dureeMs)
  return deplacement >= 35 && vitesse >= 0.6
}

export function useGlisserPourFermer(
  feuillet: Ref<HTMLElement | null>,
  actif: Ref<boolean>,
  fermer: () => void,
): void {
  let depart: { y: number; t: number } | null = null
  let tire = 0
  let racinePrecedente = ''

  const poser = (el: HTMLElement, dy: number, anime: boolean): void => {
    el.style.transition = anime ? 'transform 200ms ease-out' : 'none'
    el.style.transform = dy > 0 ? `translateY(${dy}px)` : ''
  }

  function surDebut(e: TouchEvent): void {
    const t = e.touches[0]
    depart = e.touches.length === 1 && t ? { y: t.clientY, t: performance.now() } : null
    tire = 0
  }
  function surDeplace(e: TouchEvent): void {
    const el = feuillet.value
    const t = e.touches[0]
    if (!el || !depart || !t) return
    const dy = t.clientY - depart.y
    // Seulement depuis le haut du feuillet : plus bas, le doigt fait défiler son contenu.
    if (tire === 0 && (el.scrollTop > 0 || dy <= 0)) return
    e.preventDefault()
    tire = Math.max(0, dy)
    poser(el, tire, false)
  }
  function surFin(): void {
    const el = feuillet.value
    if (!el || !depart) return
    const ferme = tire > 0 && doitFermer(tire, performance.now() - depart.t)
    depart = null
    if (ferme) {
      fermer()
      el.style.transition = ''
      el.style.transform = ''
    } else if (tire > 0) poser(el, 0, true)
    tire = 0
  }

  function brancher(el: HTMLElement): void {
    el.addEventListener('touchstart', surDebut, { passive: true })
    // Non passif : c'est lui qui empêche la page de défiler (et de se recharger).
    el.addEventListener('touchmove', surDeplace, { passive: false })
    el.addEventListener('touchend', surFin)
    el.addEventListener('touchcancel', surFin)
  }
  function debrancher(el: HTMLElement): void {
    el.removeEventListener('touchstart', surDebut)
    el.removeEventListener('touchmove', surDeplace)
    el.removeEventListener('touchend', surFin)
    el.removeEventListener('touchcancel', surFin)
    el.style.transform = ''
    el.style.transition = ''
  }

  watch(
    [feuillet, actif],
    ([el, ouvert], [avant]) => {
      if (avant && avant !== el) debrancher(avant)
      const racine = document.documentElement.style
      if (el && ouvert) {
        brancher(el)
        racinePrecedente = racine.overscrollBehaviorY
        racine.overscrollBehaviorY = 'contain'
      } else {
        if (el) debrancher(el)
        racine.overscrollBehaviorY = racinePrecedente
      }
    },
    { flush: 'post' },
  )
  onBeforeUnmount(() => {
    if (feuillet.value) debrancher(feuillet.value)
    document.documentElement.style.overscrollBehaviorY = racinePrecedente
  })
}
