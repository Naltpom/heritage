/**
 * Déplacement et zoom d'un calque SVG : glisser, molette, pincement à deux
 * doigts, et vols animés vers un point.
 */
import { onBeforeUnmount, ref, type Ref } from 'vue'

export interface View {
  x: number
  y: number
  k: number
}

const MIN_K = 0.06
const MAX_K = 3

export function usePanZoom(el: Ref<SVGSVGElement | null>) {
  const view = ref<View>({ x: 0, y: 0, k: 1 })
  /** Vrai si le dernier geste a bougé : le « clic » qui suit n'en est pas un. */
  let moved = false
  let anim = 0
  const pointers = new Map<number, { x: number; y: number }>()
  let start = { x: 0, y: 0 }

  const clampK = (k: number): number => Math.min(MAX_K, Math.max(MIN_K, k))
  const local = (e: { clientX: number; clientY: number }): { x: number; y: number } => {
    const r = el.value!.getBoundingClientRect()
    return { x: e.clientX - r.left, y: e.clientY - r.top }
  }
  const stop = (): void => cancelAnimationFrame(anim)

  function zoomAt(px: number, py: number, factor: number): void {
    const v = view.value
    const k = clampK(v.k * factor)
    const f = k / v.k
    view.value = { k, x: px - (px - v.x) * f, y: py - (py - v.y) * f }
  }

  function onPointerDown(e: PointerEvent): void {
    stop()
    pointers.set(e.pointerId, local(e))
    if (pointers.size === 1) {
      moved = false
      start = local(e)
    }
  }
  function onPointerMove(e: PointerEvent): void {
    const prev = pointers.get(e.pointerId)
    if (!prev) return
    const cur = local(e)
    if (pointers.size === 1) {
      // Capturer seulement une fois le glissé engagé : capturer dès l'appui
      // détournerait le clic de la fiche touchée vers le canevas.
      if (!moved && Math.hypot(cur.x - start.x, cur.y - start.y) > 6) {
        moved = true
        el.value?.setPointerCapture?.(e.pointerId)
      }
      if (!moved) return
      view.value = { ...view.value, x: view.value.x + cur.x - prev.x, y: view.value.y + cur.y - prev.y }
    } else if (pointers.size === 2) {
      moved = true
      const other = [...pointers.entries()].find(([id]) => id !== e.pointerId)![1]
      const d0 = Math.hypot(prev.x - other.x, prev.y - other.y)
      const d1 = Math.hypot(cur.x - other.x, cur.y - other.y)
      const m0 = { x: (prev.x + other.x) / 2, y: (prev.y + other.y) / 2 }
      const m1 = { x: (cur.x + other.x) / 2, y: (cur.y + other.y) / 2 }
      view.value = { ...view.value, x: view.value.x + m1.x - m0.x, y: view.value.y + m1.y - m0.y }
      if (d0 > 0) zoomAt(m1.x, m1.y, d1 / d0)
    }
    pointers.set(e.pointerId, cur)
  }
  function onPointerUp(e: PointerEvent): void {
    pointers.delete(e.pointerId)
  }
  function onWheel(e: WheelEvent): void {
    e.preventDefault()
    stop()
    const p = local(e)
    const delta = e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY
    zoomAt(p.x, p.y, Math.exp(-delta * (e.ctrlKey ? 0.01 : 0.0015)))
  }

  /** Vole pour que le point (cx, cy) du plan soit en (sx, sy) de l'écran, à l'échelle k. */
  function flyTo(cx: number, cy: number, k: number, sx: number, sy: number, ms = 650): Promise<void> {
    stop()
    k = clampK(k)
    const from = { ...view.value }
    const to = { k, x: sx - cx * k, y: sy - cy * k }
    if (ms <= 0 || matchMedia('(prefers-reduced-motion: reduce)').matches) {
      view.value = to
      return Promise.resolve()
    }
    return new Promise((resolve) => {
      const t0 = performance.now()
      const step = (t: number): void => {
        const u = Math.min(1, (t - t0) / ms)
        const e = u < 0.5 ? 4 * u * u * u : 1 - (-2 * u + 2) ** 3 / 2
        // Interpolation logarithmique de l'échelle : le zoom paraît régulier.
        const kk = from.k * (to.k / from.k) ** e
        // Le point visé glisse linéairement à l'écran pendant que k change.
        const pcx = (sx - from.x) / from.k
        const pcy = (sy - from.y) / from.k
        const ccx = pcx + (cx - pcx) * e
        const ccy = pcy + (cy - pcy) * e
        view.value = { k: kk, x: sx - ccx * kk, y: sy - ccy * kk }
        if (u < 1) anim = requestAnimationFrame(step)
        else resolve()
      }
      anim = requestAnimationFrame(step)
    })
  }

  onBeforeUnmount(stop)

  return {
    view,
    zoomAt,
    flyTo,
    wasDrag: () => moved,
    handlers: { onPointerDown, onPointerMove, onPointerUp, onWheel },
  }
}
