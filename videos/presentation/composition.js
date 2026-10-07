/* ------------------------------------------------------------------------ */
/* La composition : quelle scène dessine quoi, à quel instant.               */
/* Chaque scène est une fonction `(t) => void` qui ne dessine rien hors de   */
/* sa plage ; plusieurs se chevauchent pendant les fondus.                   */
/* ------------------------------------------------------------------------ */
const SCENES = [sceneIntro, sceneHeritage, sceneArbre, sceneExploration, sceneParticipation, sceneIA, sceneFin]

function dessiner(t) {
  x.setTransform(RESOLUTION, 0, 0, RESOLUTION, 0, 0)
  x.globalAlpha = 1; x.globalCompositeOperation = 'source-over'; x.filter = 'none'
  fond(t)
  for (const s of SCENES) { x.save(); s(t); x.restore() }
  finition(t)
}
/**
 * Une image : on dessine, et si des images de rush manquaient, on attend qu'elles
 * arrivent et on redessine (au plus trois fois). Le résultat ne dépend que de t.
 */
async function render(t) {
  for (let essai = 0; essai < 4; essai++) {
    EN_ATTENTE.length = 0
    dessiner(t)
    if (!EN_ATTENTE.length) break
    await Promise.all(EN_ATTENTE)
  }
  nettoyerCache()
}

/* Les bruitages sont posés une fois pour toutes par les scènes (fonctions `sons*`). */
for (const f of [sonsIntro, sonsHeritage, sonsArbre, sonsExploration, sonsParticipation, sonsIA, sonsFin]) f()

window.render = render
window.PISTE = {
  duree: DUREE,
  segments: Object.values(SEG).map((s) => ({ id: s.id, texte: s.texte, debut: s.debut, fin: s.fin })),
  reperes: R,
  sons: SONS.sort((a, b) => a.t - b.t),
}

/* Le lecteur d'aperçu (page ouverte à la main) : image + voix, pour juger le rythme sans rendre. */
const ASSETS = [...new Set(ASSETS_A_CHARGER)]
Promise.all([charger(ASSETS), ...[police(20, 500), police(20, 250), police(20, 700), police(20, 400, MONO), '40px "Mrs Saint Delafield"', '40px "Pinyon Script"'].map((f) => document.fonts.load(f))]).then(() => {
  window.PRET = true
  if (window.__capture) return
  const sons = Object.values(SEG).map((s, k) => ({ debut: s.debut, a: new Audio(`narration/voix/${k}.wav`) }))
  let t = 0, joue = false, d0 = 0
  const pos = document.getElementById('pos'), tc = document.getElementById('tc'), bt = document.getElementById('lecture')
  const caler = () => { for (const s of sons) { s.a.pause(); const r = t - s.debut; if (joue && r >= 0 && r < s.a.duration) { s.a.currentTime = r; s.a.play() } } }
  bt.onclick = () => { joue = !joue; bt.textContent = joue ? '❚❚' : '▶'; d0 = performance.now() / 1000 - t; caler() }
  pos.oninput = () => { t = pos.value * DUREE; d0 = performance.now() / 1000 - t; caler() }
  ;(function boucle() {
    if (joue) {
      t = performance.now() / 1000 - d0
      if (t >= DUREE) { t = 0; d0 = performance.now() / 1000 }
      for (const s of sons) if (s.a.paused && t >= s.debut && t < s.debut + 0.1) { s.a.currentTime = t - s.debut; s.a.play() }
      pos.value = t / DUREE
    }
    tc.textContent = t.toFixed(2) + ' s'
    render(t); requestAnimationFrame(boucle)
  })()
})
