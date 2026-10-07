/* ------------------------------------------------------------------------ */
/* Heritage — vidéo verticale (1080 × 1920, 30 i/s). Le moteur.              */
/* Une image est une fonction du temps : `render(t)` dessine l'instant t,    */
/* sans état ; on peut rendre n'importe quelle image, dans n'importe quel     */
/* ordre (aperçus, rendu en parallèle).                                      */
/* ------------------------------------------------------------------------ */
const W = 1080, H = 1920
const cv = document.getElementById('c'), x = cv.getContext('2d')
// RESOLUTION < 1 : l'aperçu basse définition (même dessin, canevas plus petit)
const RESOLUTION = window.RESOLUTION || 1
cv.width = Math.round(W * RESOLUTION); cv.height = Math.round(H * RESOLUTION)

/* -------- les jetons de l'application (app/src/styles/main.css, thème sombre) -------- */
const C = {
  fond: '#141517', panneau: '#1d1f22', panneau2: '#24272b', ligne: '#33363b',
  texte: '#e8e6e2', attenue: '#9a948c', accent: '#d9a066', accentDoux: '#3a2c1d', ok: '#7db38c', okDoux: '#1f2a22', alerte: '#e08a5a',
  homme: '#24354a', hommeBord: '#4a6b90', femme: '#432b31', femmeBord: '#8e5b66',
  pp: '#d9a066', pm: '#4f9d8c', mp: '#8a7bc8', mm: '#7fa855', tr: '#9a948c',
  papier: '#e6dabf', encre: '#3b2f22', kraft: '#b08a5a',
}
const SANS = '"Inter Variable", Inter, system-ui, sans-serif'
const MONO = '"IBM Plex Mono", ui-monospace, monospace'
const PLUME = '"Mrs Saint Delafield", "Pinyon Script", cursive'
const SERIF = '"Cormorant Garamond", Georgia, serif'

/* -------- outils -------- */
const clamp = (v, a = 0, b = 1) => Math.max(a, Math.min(b, v))
const lerp = (a, b, k) => a + (b - a) * k
const prog = (t, a, b) => (b <= a ? (t >= a ? 1 : 0) : clamp((t - a) / (b - a)))
const ein = (k) => k * k * k
const eout = (k) => 1 - Math.pow(1 - k, 3)
const eio = (k) => (k < 0.5 ? 4 * k ** 3 : 1 - Math.pow(-2 * k + 2, 3) / 2)
const esin = (k) => -(Math.cos(Math.PI * k) - 1) / 2
/** Monte de a à a+fi, reste, redescend de b-fo à b. */
const fenetre = (t, a, b, fi = 0.4, fo = 0.4) => Math.min(eout(prog(t, a, a + fi)), 1 - eio(prog(t, b - fo, b)))
function rnd(seed) { let s = seed >>> 0; return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296) }
function hash(n) { n = (n ^ 61) ^ (n >>> 16); n = n + (n << 3); n = n ^ (n >>> 4); n = Math.imul(n, 0x27d4eb2d); return ((n ^ (n >>> 15)) >>> 0) / 4294967296 }
function rr(px, py, w, h, r) {
  r = Math.max(0, Math.min(r, w / 2, h / 2))
  x.beginPath(); x.moveTo(px + r, py); x.arcTo(px + w, py, px + w, py + h, r); x.arcTo(px + w, py + h, px, py + h, r)
  x.arcTo(px, py + h, px, py, r); x.arcTo(px, py, px + w, py, r); x.closePath()
}
function hex(c) { return [1, 3, 5].map((i) => parseInt(c.slice(i, i + 2), 16)) }
function melange(a, b, k) { const pa = hex(a), pb = hex(b); return '#' + pa.map((v, i) => Math.round(lerp(v, pb[i], clamp(k))).toString(16).padStart(2, '0')).join('') }
const alpha = (c, a) => { const [r, g, b] = hex(c); return `rgba(${r},${g},${b},${clamp(a)})` }
const police = (taille, poids = 500, famille = SANS) => `${poids} ${taille}px ${famille}`
function texte(s, px, py, { taille = 40, poids = 500, famille = SANS, coul = C.texte, align = 'left', base = 'middle', a = 1, espacement = 0 } = {}) {
  if (a <= 0) return
  x.save(); x.globalAlpha *= a; x.fillStyle = coul; x.textAlign = align; x.textBaseline = base
  x.font = police(taille, poids, famille)
  if (espacement) x.letterSpacing = `${espacement}px`
  x.fillText(s, px, py); x.restore()
}
function mesure(s, taille, poids = 500, famille = SANS) { x.save(); x.font = police(taille, poids, famille); const w = x.measureText(s).width; x.restore(); return w }
/** Coupe un texte en lignes qui tiennent dans `largeur`. */
function couper(s, taille, poids, largeur, famille = SANS) {
  x.save(); x.font = police(taille, poids, famille)
  const mots = s.split(' '), lignes = []
  let l = ''
  for (const m of mots) { const essai = l ? l + ' ' + m : m; if (x.measureText(essai).width > largeur && l) { lignes.push(l); l = m } else l = essai }
  if (l) lignes.push(l)
  x.restore(); return lignes
}

/* -------- les images (fabriquées, chargées avant la première image) -------- */
const IMAGES = {}
const ASSETS_A_CHARGER = []
function charger(noms) {
  return Promise.all(noms.map((n) => new Promise((ok) => {
    const im = new Image()
    im.onload = () => { IMAGES[n] = im; ok() }
    im.onerror = () => { console.warn('image absente : ' + n); ok() }
    im.src = 'assets/' + n
  })))
}

/* -------- le fond et la finition : un noir chaud, un grain de film, un vignettage -------- */
const GRAIN = (() => {
  const c = document.createElement('canvas'); c.width = c.height = 256
  const g = c.getContext('2d'), d = g.createImageData(256, 256), r = rnd(3)
  for (let i = 0; i < d.data.length; i += 4) { const v = 128 + (r() - 0.5) * 70; d.data[i] = d.data[i + 1] = d.data[i + 2] = v; d.data[i + 3] = 255 }
  g.putImageData(d, 0, 0); return c
})()
function fond() {
  x.fillStyle = C.fond; x.fillRect(0, 0, W, H)
  const g = x.createRadialGradient(W / 2, H * 0.42, 80, W / 2, H * 0.5, H * 0.7)
  g.addColorStop(0, 'rgba(70,54,38,0.22)'); g.addColorStop(1, 'rgba(0,0,0,0)')
  x.fillStyle = g; x.fillRect(0, 0, W, H)
}
function finition(t) {
  const g = x.createRadialGradient(W / 2, H / 2, H * 0.35, W / 2, H / 2, H * 0.68)
  g.addColorStop(0, 'rgba(0,0,0,0)'); g.addColorStop(1, 'rgba(0,0,0,0.38)')
  x.fillStyle = g; x.fillRect(0, 0, W, H)
  x.save(); x.globalAlpha = 0.035; x.globalCompositeOperation = 'overlay'
  const f = Math.floor(t * 30), ox = (hash(f) * 256) | 0, oy = (hash(f + 7) * 256) | 0
  x.translate(-ox, -oy); x.fillStyle = x.createPattern(GRAIN, 'repeat'); x.fillRect(0, 0, W + 256, H + 256)
  x.restore()
}

/* -------- les bruitages (lus par mixer.py) -------- */
const SONS = []
const son = (t, type, gain = 1) => SONS.push({ t: Math.round(t * 1000) / 1000, type, gain })
