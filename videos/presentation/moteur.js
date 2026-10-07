/* ------------------------------------------------------------------------ */
/* Heritage — vidéo de présentation (1920×1080, 30 i/s).                     */
/* Le moteur : le canevas, les courbes, le texte, les images, la caméra.     */
/*                                                                           */
/* Comme pour les vidéos d'aide, une image est une fonction du temps :       */
/* `render(t)` dessine l'instant t, sans état. On peut donc rendre n'importe */
/* quelle image dans n'importe quel ordre (aperçus, rendu parallèle).        */
/* ------------------------------------------------------------------------ */
const W = 1920, H = 1080
const cv = document.getElementById('c'), x = cv.getContext('2d')
// RESOLUTION < 1 : la version basse définition (le canevas est plus petit, le dessin identique)
const RESOLUTION = window.RESOLUTION || 1
cv.width = Math.round(W * RESOLUTION); cv.height = Math.round(H * RESOLUTION)

/* -------- la palette : les jetons de l'application (app/src/styles/main.css, thème sombre) -------- */
const C = {
  fond: '#141517', fond2: '#101113', panneau: '#1d1f22', panneau2: '#24272b', ligne: '#33363b',
  texte: '#e8e6e2', attenue: '#9a948c', discret: '#5f5b55',
  accent: '#d9a066', accentDoux: '#3a2c1d', ok: '#7db38c', okDoux: '#1f2a22', alerte: '#e08a5a',
  edition: '#a891f2',
  homme: '#24354a', hommeBord: '#4a6b90', femme: '#432b31', femmeBord: '#8e5b66', inconnu: '#2a2c30',
  // les quatre quarts : paternelle du père, maternelle du père, paternelle de la mère, maternelle de la mère
  pp: '#d9a066', pm: '#4f9d8c', mp: '#8a7bc8', mm: '#7fa855', tronc: '#9a948c',
  papier: '#e9dfc8', papierOmbre: '#cdbf9f', encre: '#3b2f22', sepia: '#8a6a45',
}
const BRANCHES = ['pp', 'pm', 'mp', 'mm']
const SANS = '"Inter Variable", Inter, system-ui, sans-serif'
const MONO = '"IBM Plex Mono", ui-monospace, monospace'
const SERIF = 'Georgia, "Times New Roman", serif'

/* -------- outils -------- */
const clamp = (v, a = 0, b = 1) => Math.max(a, Math.min(b, v))
const lerp = (a, b, k) => a + (b - a) * k
const prog = (t, a, b) => (b <= a ? (t >= a ? 1 : 0) : clamp((t - a) / (b - a)))
// des courbes douces : rien ne rebondit, rien ne claque — la caméra d'un documentaire
const ein = (k) => k * k * k
const eout = (k) => 1 - Math.pow(1 - k, 3)
const eio = (k) => (k < 0.5 ? 4 * k ** 3 : 1 - Math.pow(-2 * k + 2, 3) / 2)
const eio5 = (k) => (k < 0.5 ? 16 * k ** 5 : 1 - Math.pow(-2 * k + 2, 5) / 2) // pour les grands trajets de caméra
const esin = (k) => -(Math.cos(Math.PI * k) - 1) / 2
/** Une fenêtre d'apparition : monte de `a` à `a+fi`, reste, redescend de `b-fo` à `b`. */
const fenetre = (t, a, b, fi = 0.4, fo = 0.4) => Math.min(eout(prog(t, a, a + fi)), 1 - eio(prog(t, b - fo, b)))
function rnd(seed) { let s = seed >>> 0; return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296) }
function hash(n) { n = (n ^ 61) ^ (n >>> 16); n = n + (n << 3); n = n ^ (n >>> 4); n = Math.imul(n, 0x27d4eb2d); return ((n ^ (n >>> 15)) >>> 0) / 4294967296 }

function rr(px, py, w, h, r) {
  r = Math.max(0, Math.min(r, w / 2, h / 2))
  x.beginPath(); x.moveTo(px + r, py); x.arcTo(px + w, py, px + w, py + h, r); x.arcTo(px + w, py + h, px, py + h, r)
  x.arcTo(px, py + h, px, py, r); x.arcTo(px, py, px + w, py, r); x.closePath()
}
function hex(c) { return [1, 3, 5].map((i) => parseInt(c.slice(i, i + 2), 16)) }
function melange(a, b, k) {
  const pa = hex(a), pb = hex(b)
  return '#' + pa.map((v, i) => Math.round(lerp(v, pb[i], clamp(k))).toString(16).padStart(2, '0')).join('')
}
const alpha = (c, a) => { const [r, g, b] = hex(c); return `rgba(${r},${g},${b},${clamp(a)})` }

function police(taille, poids = 500, famille = SANS) { return `${poids} ${taille}px ${famille}` }
function texte(s, px, py, { taille = 28, poids = 500, famille = SANS, coul = C.texte, align = 'left', base = 'middle', a = 1, espacement = 0, maxL = 0 } = {}) {
  if (a <= 0) return
  x.save(); x.globalAlpha *= a; x.fillStyle = coul; x.textAlign = align; x.textBaseline = base
  x.font = police(taille, poids, famille)
  if (espacement) x.letterSpacing = `${espacement}px`
  if (maxL && x.measureText(s).width > maxL) { while (s.length > 1 && x.measureText(s + '…').width > maxL) s = s.slice(0, -1); s += '…' }
  x.fillText(s, px, py); x.restore()
}
function mesure(s, taille, poids = 500, famille = SANS) { x.save(); x.font = police(taille, poids, famille); const w = x.measureText(s).width; x.restore(); return w }

/* -------- les images (captures de l'application, documents) -------- */
const IMAGES = {}
function charger(noms) {
  return Promise.all(noms.map((n) => new Promise((ok) => {
    const im = new Image()
    im.onload = () => { IMAGES[n] = im; ok() }
    im.onerror = () => { console.warn('image absente : ' + n); ok() }
    im.src = 'assets/' + n
  })))
}

/* -------- la caméra -------- */
/**
 * Une caméra = {s, fx, fy} : l'échelle, et le point du « monde » au centre de l'écran.
 * `trajet` enchaîne des cadrages ; entre deux, on glisse en interpolant l'échelle en
 * LOGARITHME (un zoom de ×1 à ×100 avance alors régulièrement, sans accélération
 * apparente), et la position en fonction de l'échelle, pour que le point visé reste
 * sous les yeux pendant tout le zoom.
 */
function cadrage(a, b, k) {
  const ls = lerp(Math.log(a.s), Math.log(b.s), k)
  const s = Math.exp(ls)
  // la position suit l'inverse de l'échelle : on « vise » pendant qu'on zoome
  const u = Math.abs(1 / b.s - 1 / a.s) < 1e-9 ? k : (1 / s - 1 / a.s) / (1 / b.s - 1 / a.s)
  return { s, fx: lerp(a.fx, b.fx, u), fy: lerp(a.fy, b.fy, u) }
}
function trajet(t, etapes, courbe = eio) {
  let c = { ...etapes[0] }
  for (const e of etapes.slice(1)) {
    const k = (e.courbe ?? courbe)(prog(t, e.a, e.b))
    if (k <= 0) break
    c = cadrage(c, e, k)
  }
  return c
}
function filmer(cam, cx = W / 2, cy = H / 2) { x.translate(cx, cy); x.scale(cam.s, cam.s); x.translate(-cam.fx, -cam.fy) }

/* -------- le fond : un noir chaud, un léger grain, un vignettage -------- */
const GRAIN = (() => {
  const c = document.createElement('canvas'); c.width = c.height = 256
  const g = c.getContext('2d'), d = g.createImageData(256, 256), r = rnd(3)
  for (let i = 0; i < d.data.length; i += 4) { const v = 128 + (r() - 0.5) * 70; d.data[i] = d.data[i + 1] = d.data[i + 2] = v; d.data[i + 3] = 255 }
  g.putImageData(d, 0, 0); return c
})()
function fond(t, { teinte = C.fond } = {}) {
  x.fillStyle = teinte; x.fillRect(0, 0, W, H)
  const g = x.createRadialGradient(W * 0.5, H * 0.42, 100, W * 0.5, H * 0.5, W * 0.75)
  g.addColorStop(0, 'rgba(60,48,36,0.18)'); g.addColorStop(1, 'rgba(0,0,0,0)')
  x.fillStyle = g; x.fillRect(0, 0, W, H)
}
function finition(t) {
  // vignettage
  const g = x.createRadialGradient(W / 2, H / 2, H * 0.45, W / 2, H / 2, W * 0.72)
  g.addColorStop(0, 'rgba(0,0,0,0)'); g.addColorStop(1, 'rgba(0,0,0,0.42)')
  x.fillStyle = g; x.fillRect(0, 0, W, H)
  // grain de film, très léger, qui change à chaque image
  x.save(); x.globalAlpha = 0.035; x.globalCompositeOperation = 'overlay'
  const f = Math.floor(t * 30), ox = (hash(f) * 256) | 0, oy = (hash(f + 7) * 256) | 0
  x.translate(-ox, -oy); x.fillStyle = x.createPattern(GRAIN, 'repeat'); x.fillRect(0, 0, W + 256, H + 256)
  x.restore()
}

/* -------- les bruitages (lus par mixer.py) -------- */
const SONS = []
const son = (t, type, gain = 1) => SONS.push({ t: Math.round(t * 1000) / 1000, type, gain })
