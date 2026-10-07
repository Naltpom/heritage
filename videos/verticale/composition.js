/* ------------------------------------------------------------------------ */
/* La composition : les scènes, dans l'ordre où elles se superposent, puis  */
/* les sous-titres. Chaque scène ne dessine que pendant sa plage (SCENE,     */
/* timeline.js) : changer le timing d'une scène ne touche pas aux autres.    */
/* ------------------------------------------------------------------------ */
const SCENES = [sceneMemoire, sceneDispersion, sceneCreation, sceneArbre, sceneFamille, sceneQuestions, sceneExplorer, sceneFin]

function dessiner(t) {
  x.setTransform(RESOLUTION, 0, 0, RESOLUTION, 0, 0)
  x.globalAlpha = 1; x.globalCompositeOperation = 'source-over'; x.filter = 'none'
  fond()
  for (const s of SCENES) { x.save(); s(t); x.restore() }
  finition(t)
  sousTitres(t)
  if (t < 0.8) { x.fillStyle = `rgba(0,0,0,${1 - eout(prog(t, 0.05, 0.8))})`; x.fillRect(0, 0, W, H) }
}
/** Une image : si des images de rush manquaient, on les attend et on redessine. Le résultat ne dépend que de t. */
async function render(t) {
  for (let essai = 0; essai < 4; essai++) {
    EN_ATTENTE.length = 0
    dessiner(t)
    if (!EN_ATTENTE.length) break
    await Promise.all(EN_ATTENTE)
  }
  nettoyerCache()
}
for (const f of [sonsMemoire, sonsDispersion, sonsCreation, sonsArbre, sonsFamille, sonsQuestions, sonsExplorer, sonsFin]) f()

window.render = render
window.PISTE = { duree: DUREE, t0: T0, reperes: R, sons: SONS.sort((a, b) => a.t - b.t) }

/* le lecteur d'aperçu (page ouverte à la main), avec la voix */
Promise.all([charger([...new Set(ASSETS_A_CHARGER)]), ...[police(20, 500), police(20, 250), police(20, 700), police(20, 400, MONO), '40px "Mrs Saint Delafield"', '40px "Pinyon Script"', '40px "Cormorant Garamond"'].map((f) => document.fonts.load(f))]).then(() => {
  window.PRET = true
  if (window.__capture) return
  const voix = new Audio('narration/prise/prise.mp3')
  let t = 0, joue = false, d0 = 0
  const pos = document.getElementById('pos'), tc = document.getElementById('tc'), bt = document.getElementById('lecture')
  const caler = () => { voix.pause(); if (joue && t >= T0) { voix.currentTime = t - T0; voix.play() } }
  bt.onclick = () => { joue = !joue; bt.textContent = joue ? '❚❚' : '▶'; d0 = performance.now() / 1000 - t; caler() }
  pos.oninput = () => { t = pos.value * DUREE; d0 = performance.now() / 1000 - t; caler() }
  ;(async function boucle() {
    if (joue) { t = performance.now() / 1000 - d0; if (t >= DUREE) { t = 0; d0 = performance.now() / 1000 }; if (voix.paused && t >= T0 && t < T0 + 0.1) caler(); pos.value = t / DUREE }
    tc.textContent = t.toFixed(2) + ' s'
    await render(t); requestAnimationFrame(boucle)
  })()
})
