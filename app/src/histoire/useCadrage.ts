/**
 * Le cadrage de l'arbre — pan, zoom, pincement, molette, vol animé — **hors de
 * la réactivité de Vue** (ADR 0007, règle 3).
 *
 * Le cadrage courant est une variable ordinaire. Chaque changement est écrit
 * dans le DOM par `appliquer` (le `viewBox` des traits, la transformation des
 * boîtes, celle de la couche des textes) : une écriture par image, calée sur
 * `requestAnimationFrame`, sans un seul rendu de composant.
 *
 * Vue n'en reçoit qu'un **instantané**, `rendu` — c'est de lui que se
 * recalculent les textes visibles, les anneaux et les noms de foyers :
 *  - aussitôt hors d'un geste (bouton +/−, clavier, « Me trouver ») ;
 *  - aussitôt quand le niveau de détail change (`niveau`) ;
 *  - pendant un geste, au plus toutes les `INTERVALLE_RENDU` ms ;
 *  - toujours à la fin du geste (doigts levés, molette au repos, vol arrivé).
 *
 * Avant ce découpage, l'écran entier se rendait à nouveau à chaque image d'un
 * glisser : les textes dépendaient d'un `ref` de cadrage.
 */
import { onBeforeUnmount, onMounted, ref, shallowRef, watch, type Ref } from 'vue'
import { creerCadence } from './cadence'
import {
  DUREE_VOL,
  niveau as niveauDe,
  trajectoire,
  versMonde,
  zoomAutour,
  type Cadrage,
  type Canevas,
  type Niveau,
} from './vue'

/** Au plus ~6 recalculs des textes par seconde pendant un geste. */
export const INTERVALLE_RENDU = 160
/** La molette est « au repos » après ce silence. */
const REPOS_MOLETTE = 140
/** Un glisser qui vient de finir n'est pas un clic (`vientDeGlisser`). */
const GARDE_CLIC = 80

export interface OptionsCadrage {
  /** Le bloc qui reçoit les gestes. */
  fenetre: Ref<HTMLElement | null>
  /** Les dimensions du canevas et du monde, relues à chaque calcul. */
  canevas: () => Canevas
  /** Écrit le cadrage dans le DOM, sans Vue. Appelé à chaque image. */
  appliquer: (c: Cadrage) => void
  /** Le seuil des noms réglé par le lecteur (niveau Fiches), relu à chaque calcul. */
  seuilDesNoms: () => number
}

/** Un doigt : sa position dans le canevas (`x`, `y`) et à l'écran (`cx`, `cy`). */
interface Pointeur {
  x: number
  y: number
  cx: number
  cy: number
}

export function useCadrage(o: OptionsCadrage) {
  let courant: Cadrage = { s: 0.05, tx: 0, ty: 0 }
  /** L'instantané publié à Vue. */
  const rendu = shallowRef<Cadrage>(courant)
  const niveau = ref<Niveau>(niveauDe(courant.s, o.seuilDesNoms()))

  const cadence = creerCadence(INTERVALLE_RENDU, () => {
    rendu.value = courant
  })

  /* Ce qui bouge en ce moment : un doigt, la molette, un vol. */
  let doigts = false
  let molette = false
  let minuterieMolette = 0
  let animation = 0
  const bouge = (): boolean => doigts || molette || animation !== 0

  function ecrire(c: Cadrage): void {
    courant = c
    o.appliquer(c)
    const n = niveauDe(c.s, o.seuilDesNoms())
    if (n !== niveau.value) {
      niveau.value = n
      cadence.maintenant()
    } else if (bouge()) cadence.demander()
    else cadence.maintenant()
  }

  /* Un seul calcul par image pendant un geste. */
  let enAttente: Cadrage | null = null
  let image = 0
  function planifier(c: Cadrage): void {
    enAttente = c
    if (image) return
    image = requestAnimationFrame(() => {
      image = 0
      const c2 = enAttente
      enAttente = null
      if (c2) ecrire(c2)
    })
  }

  /**
   * Le cadrage le plus récent, y compris celui qui attend la prochaine image.
   * C'est de lui qu'un geste doit repartir, jamais du dernier dessiné.
   */
  const lire = (): Cadrage => enAttente ?? courant

  /** Pose un cadrage tout de suite (et l'emporte sur celui qui attendait l'image). */
  function definir(c: Cadrage): void {
    if (image) cancelAnimationFrame(image)
    image = 0
    enAttente = null
    ecrire(c)
  }

  /** Réécrit le cadrage courant (le canevas a changé de taille, les couches ont été reconstruites). */
  function reappliquer(): void {
    o.appliquer(courant)
  }

  /** Publie l'instantané tout de suite, geste en cours ou non. */
  function publier(): void {
    cadence.maintenant()
  }

  function finDeMouvement(): void {
    if (!bouge()) cadence.maintenant()
  }

  /* ---------------------------------------------------------------- */
  /* Le vol animé. Tout geste l'interrompt.                            */
  /* ---------------------------------------------------------------- */

  function arreterVol(): void {
    if (!animation) return
    cancelAnimationFrame(animation)
    animation = 0
    finDeMouvement()
  }

  function voler(sCible: number, x: number, y: number): void {
    arreterVol()
    const vol = trajectoire(lire(), o.canevas(), sCible, x, y)
    const debut = performance.now()
    const pas = (maintenant: number): void => {
      const t = Math.min(1, (maintenant - debut) / DUREE_VOL)
      animation = t < 1 ? requestAnimationFrame(pas) : 0
      ecrire(vol(t))
    }
    animation = requestAnimationFrame(pas)
  }

  function centreMonde(): { x: number; y: number } {
    const c = o.canevas()
    return versMonde(lire(), c, c.largeur / 2, c.hauteur / 2)
  }

  /** Zoom d'un cran autour du centre (boutons, clavier) : immédiat, sans animation. */
  function zoomer(facteur: number): void {
    arreterVol()
    const c = o.canevas()
    definir(zoomAutour(lire(), c, c.largeur / 2, c.hauteur / 2, facteur))
  }

  /* ---------------------------------------------------------------- */
  /* Gestes : glisser, pincer, molette                                 */
  /* ---------------------------------------------------------------- */

  const pointeurs = new Map<number, Pointeur>()
  let glisser: { x: number; y: number; tx: number; ty: number; bouge: boolean } | null = null
  let pincement: number | null = null
  let finDeGlisser = 0

  function local(e: PointerEvent | WheelEvent): { x: number; y: number } {
    const r = o.fenetre.value!.getBoundingClientRect()
    return { x: e.clientX - r.left, y: e.clientY - r.top }
  }

  function surPointeurBas(e: PointerEvent): void {
    if (e.button !== 0 && e.pointerType === 'mouse') return
    arreterVol()
    pointeurs.set(e.pointerId, { ...local(e), cx: e.clientX, cy: e.clientY })
    doigts = true
    const c = lire()
    if (pointeurs.size === 1) glisser = { x: e.clientX, y: e.clientY, tx: c.tx, ty: c.ty, bouge: false }
    if (pointeurs.size === 2) {
      const [a, b] = [...pointeurs.values()] as [Pointeur, Pointeur]
      pincement = Math.hypot(a.x - b.x, a.y - b.y)
      // Le glissement à un doigt s'arrête : son point de départ date d'avant le zoom.
      glisser = null
      finDeGlisser = Date.now()
    }
  }

  function surPointeurDeplace(e: PointerEvent): void {
    if (!pointeurs.has(e.pointerId)) return
    pointeurs.set(e.pointerId, { ...local(e), cx: e.clientX, cy: e.clientY })
    if (pointeurs.size >= 2) {
      if (!pincement) return
      const [a, b] = [...pointeurs.values()] as [Pointeur, Pointeur]
      const d = Math.hypot(a.x - b.x, a.y - b.y)
      planifier(zoomAutour(lire(), o.canevas(), (a.x + b.x) / 2, (a.y + b.y) / 2, d / pincement))
      pincement = d
      return
    }
    if (!glisser) return
    const dx = e.clientX - glisser.x
    const dy = e.clientY - glisser.y
    if (!glisser.bouge && Math.abs(dx) + Math.abs(dy) > 5) {
      glisser.bouge = true
      o.fenetre.value?.setPointerCapture(e.pointerId)
    }
    if (glisser.bouge) planifier({ s: lire().s, tx: glisser.tx + dx, ty: glisser.ty + dy })
  }

  function surPointeurHaut(e: PointerEvent): void {
    if (!pointeurs.delete(e.pointerId)) return
    if (pointeurs.size < 2) pincement = null
    if (pointeurs.size === 1) {
      // Fin d'un pincement, un doigt reste posé : le glissement REPART d'ici, de ce
      // doigt et du cadrage d'après le zoom. Reprendre l'ancien point de départ
      // faisait sauter la vue loin de l'arbre.
      const [reste] = [...pointeurs.values()] as [Pointeur]
      const c = lire()
      glisser = { x: reste.cx, y: reste.cy, tx: c.tx, ty: c.ty, bouge: true }
      finDeGlisser = Date.now()
    }
    if (pointeurs.size === 0) {
      if (glisser?.bouge) finDeGlisser = Date.now()
      glisser = null
      doigts = false
      // La dernière image du geste est peut-être encore en attente : elle publiera.
      if (!image) finDeMouvement()
      else {
        const c = enAttente
        definir(c ?? courant)
      }
    }
  }

  function surMolette(e: WheelEvent): void {
    e.preventDefault()
    arreterVol()
    molette = true
    window.clearTimeout(minuterieMolette)
    minuterieMolette = window.setTimeout(() => {
      molette = false
      finDeMouvement()
    }, REPOS_MOLETTE)
    const { x, y } = local(e)
    planifier(zoomAutour(lire(), o.canevas(), x, y, Math.exp(-e.deltaY * 0.0016)))
  }

  /** Un clic qui termine un glisser n'est pas un clic. */
  const vientDeGlisser = (): boolean => Date.now() - finDeGlisser < GARDE_CLIC

  // Non passif, pour pouvoir empêcher le défilement de la page.
  let fenetreEcoutee: HTMLElement | null = null
  onMounted(() => {
    fenetreEcoutee = o.fenetre.value
    fenetreEcoutee?.addEventListener('wheel', surMolette, { passive: false })
  })
  onBeforeUnmount(() => {
    fenetreEcoutee?.removeEventListener('wheel', surMolette)
    if (animation) cancelAnimationFrame(animation)
    animation = 0
    if (image) cancelAnimationFrame(image)
    image = 0
    window.clearTimeout(minuterieMolette)
    cadence.arreter()
  })

  // Le canevas change de taille (volet, fenêtre, disposition) : les couches se réécrivent.
  watch(o.canevas, reappliquer, { flush: 'post' })
  // Le seuil des noms bouge sous la jauge des filtres : le niveau se relit, sans geste.
  watch(o.seuilDesNoms, (seuil) => {
    niveau.value = niveauDe(courant.s, seuil)
  })

  return {
    /** L'instantané publié à Vue : à lire dans les `computed` des couches de texte. */
    rendu,
    niveau,
    lire,
    definir,
    publier,
    reappliquer,
    voler,
    arreterVol,
    zoomer,
    centreMonde,
    vientDeGlisser,
    gestes: { surPointeurBas, surPointeurDeplace, surPointeurHaut },
  }
}

export type CadrageArbre = ReturnType<typeof useCadrage>
