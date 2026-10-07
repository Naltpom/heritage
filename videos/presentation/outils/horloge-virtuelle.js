/* ------------------------------------------------------------------------ */
/* L'horloge virtuelle, injectée dans la vraie application avant son        */
/* chargement (rushes.mjs). Tant qu'elle n'est pas figée, tout passe au     */
/* temps réel. Une fois figée (`__figer()`), le temps n'avance plus que par */
/* `__avancer(ms)` : performance.now, Date.now, requestAnimationFrame,      */
/* setTimeout/setInterval, et les animations et transitions CSS (pilotées   */
/* par l'API Web Animations). Chaque image du rush avance donc exactement   */
/* d'1/30 s, quelle que soit la lenteur de la capture : les vraies          */
/* animations de l'application sont filmées à leur vraie vitesse.           */
/* ------------------------------------------------------------------------ */
;(function () {
  const reel = { now: performance.now.bind(performance), date: Date.now, raf: window.requestAnimationFrame.bind(window), caf: window.cancelAnimationFrame.bind(window), st: window.setTimeout.bind(window), ct: window.clearTimeout.bind(window), si: window.setInterval.bind(window), ci: window.clearInterval.bind(window) }
  let fige = false, t = 0, decalageDate = 0
  let prochainId = 1e6
  const minuteurs = new Map() // id → {quand, f, args, periode}
  let rafs = new Map()
  const vus = new WeakMap() // animation → instant virtuel de sa création

  performance.now = () => (fige ? t : reel.now())
  Date.now = () => (fige ? t + decalageDate : reel.date())
  const DateReelle = Date
  window.Date = class extends DateReelle {
    constructor(...a) { if (a.length === 0 && fige) super(t + decalageDate); else super(...a) }
    static now() { return fige ? t + decalageDate : reel.date() }
  }
  window.requestAnimationFrame = (f) => { if (!fige) return reel.raf(f); const id = prochainId++; rafs.set(id, f); return id }
  window.cancelAnimationFrame = (id) => { if (rafs.has(id)) rafs.delete(id); else reel.caf(id) }
  window.setTimeout = (f, ms = 0, ...args) => { if (!fige || typeof f !== 'function') return reel.st(f, ms, ...args); const id = prochainId++; minuteurs.set(id, { quand: t + Math.max(0, ms), f, args }); return id }
  window.setInterval = (f, ms = 0, ...args) => { if (!fige) return reel.si(f, ms, ...args); const id = prochainId++; minuteurs.set(id, { quand: t + Math.max(1, ms), f, args, periode: Math.max(1, ms) }); return id }
  window.clearTimeout = (id) => { if (minuteurs.has(id)) minuteurs.delete(id); else reel.ct(id) }
  window.clearInterval = (id) => { if (minuteurs.has(id)) minuteurs.delete(id); else reel.ci(id) }

  function animations() {
    for (const a of document.getAnimations()) {
      if (!vus.has(a)) { vus.set(a, t); try { a.pause() } catch {} }
      try { a.currentTime = t - vus.get(a) } catch {}
    }
  }
  window.__figer = () => { if (fige) return; t = reel.now(); decalageDate = reel.date() - t; fige = true; animations() }
  window.__temps = () => t
  /** Avance le temps virtuel : les minuteurs échus partent dans l'ordre, puis une image d'animation. */
  window.__avancer = async (ms) => {
    const fin = t + ms
    for (;;) {
      let proche = null
      for (const [id, m] of minuteurs) if (m.quand <= fin && (!proche || m.quand < proche[1].quand)) proche = [id, m]
      if (!proche) break
      const [id, m] = proche
      t = Math.max(t, m.quand)
      if (m.periode) m.quand += m.periode; else minuteurs.delete(id)
      try { m.f(...m.args) } catch (e) { console.error(e) }
      await Promise.resolve()
    }
    t = fin
    const lot = rafs; rafs = new Map()
    for (const f of lot.values()) { try { f(t) } catch (e) { console.error(e) } }
    await Promise.resolve()
    animations()
    // laisse Vue appliquer ses mises à jour, puis le navigateur peindre
    await new Promise((r) => reel.st(r, 0))
    animations()
    await new Promise((r) => reel.raf(() => reel.raf(r)))
  }
})()
