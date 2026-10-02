/* ------------------------------------------------------------------------ */
/* Heritage — le moteur commun des vidéos d'aide (1080×1920, données        */
/* fictives). Une scène est une fonction du temps : `render(t)` dessine une  */
/* image, et la partition SUIT LA VOIX — chaque plan prépare l'image,       */
/* la phrase tombe, le plan suivant part juste après le dernier mot.        */
/*                                                                           */
/* Règles de lecture (mesurées sur la vidéo de présentation) :              */
/*  - une seule chose bouge à la fois : le geste, PUIS la phrase ;           */
/*  - la phrase entière s'affiche en gris, chaque mot s'allume quand il est */
/*    dit (karaoké) : on lit au rythme de la voix, sans temps mort ;         */
/*  - le haut (≈ 200 px) et le bas (≈ 100 px) restent libres pour les       */
/*    commandes du lecteur (croix, son, barre de progression).               */
/* ------------------------------------------------------------------------ */
const W = 1080, H = 1920
const cv = document.getElementById('c'), x = cv.getContext('2d')

const COUL = {
  fond: '#141518', panneau: '#1c1e22', panneau2: '#23262b', ligne: '#30343b', texte: '#efe9e0', attenue: '#8d8880',
  accent: '#e3a35f', fonceAccent: '#2b2118', neutre: '#3a3f47', papier: '#efe6d1', encre: '#3b2f22',
  violet: '#9a7cf0', vert: '#4caf7a', rouge: '#d9675a',
  homme: '#22304a', femme: '#3c2830',
  A: '#cf8a4c', B: '#3f9b88', C: '#8c6ddb', D: '#84b04c',
}
const BRANCHES = [
  { cle: 'A', nom: 'Martin', role: 'Père du père' }, { cle: 'B', nom: 'Bernard', role: 'Mère du père' },
  { cle: 'C', nom: 'Dubois', role: 'Père de la mère' }, { cle: 'D', nom: 'Lefèvre', role: 'Mère de la mère' },
]
const SERIF = 'Georgia, "Times New Roman", serif'
const SANS = '"Segoe UI", system-ui, sans-serif'
const MONO = 'Consolas, monospace'

/* -------- outils -------- */
const clamp = (v, a = 0, b = 1) => Math.max(a, Math.min(b, v))
const lerp = (a, b, k) => a + (b - a) * k
const prog = (t, a, b) => clamp((t - a) / (b - a))
const eio = (k) => (k < 0.5 ? 16 * k ** 5 : 1 - Math.pow(-2 * k + 2, 5) / 2)
const edoux = (k) => (k < 0.5 ? 4 * k ** 3 : 1 - Math.pow(-2 * k + 2, 3) / 2)
const eout = (k) => 1 - Math.pow(1 - k, 4)
const eback = (k, c1 = 2.4) => { const c3 = c1 + 1; return 1 + c3 * Math.pow(k - 1, 3) + c1 * Math.pow(k - 1, 2) }
function rnd(seed) { let s = seed >>> 0; return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296) }
function rr(px, py, w, h, r) {
  r = Math.max(0, Math.min(r, w / 2, h / 2))
  x.beginPath(); x.moveTo(px + r, py); x.arcTo(px + w, py, px + w, py + h, r); x.arcTo(px + w, py + h, px, py + h, r)
  x.arcTo(px, py + h, px, py, r); x.arcTo(px, py, px + w, py, r); x.closePath()
}
function texte(s, px, py, { taille = 40, poids = 600, police = SANS, coul = COUL.texte, align = 'center', alpha = 1, espacement = 0, base = 'middle' } = {}) {
  x.save(); x.globalAlpha *= alpha; x.fillStyle = coul; x.textAlign = align; x.textBaseline = base
  x.font = `${poids} ${taille}px ${police}`
  if (espacement) x.letterSpacing = `${espacement}px`
  x.fillText(s, px, py); x.restore()
}
function largeurTexte(s, taille, poids = 600, police = SANS) { x.save(); x.font = `${poids} ${taille}px ${police}`; const w = x.measureText(s).width; x.restore(); return w }
function silhouette(cx, cy, r, coul, alpha = 1) {
  x.save(); x.globalAlpha *= alpha; x.fillStyle = coul
  x.beginPath(); x.arc(cx, cy - r * 0.28, r * 0.34, 0, Math.PI * 2); x.fill()
  x.beginPath(); x.ellipse(cx, cy + r * 0.62, r * 0.66, r * 0.5, 0, Math.PI, 0); x.fill(); x.restore()
}
function melange(a, b, k) {
  const hex = (c) => [1, 3, 5].map((i) => parseInt(c.slice(i, i + 2), 16))
  const pa = hex(a), pb = hex(b)
  return '#' + pa.map((v, i) => Math.round(lerp(v, pb[i], clamp(k))).toString(16).padStart(2, '0')).join('')
}
const avecAlpha = (c, a) => c + Math.round(clamp(a) * 255).toString(16).padStart(2, '0')

/* -------- la partition, tirée de la voix -------- */
let t0 = 0 // le temps courant
let V = [] // les phrases placées : {texte, jetons, debut, fin, mots:[{debut, fin}]}
let DUREE = 10
const SONS = [] // les bruitages : {t, type, gain}

/**
 * Les jetons affichés, alignés sur les mots dits. La synthèse ne compte pas la
 * ponctuation isolée du français (« : », « ? », « « », « » ») : on la colle au
 * mot voisin par une espace insécable, pour qu'il y ait un jeton par mot dit.
 */
function jetonsDe(phrase) {
  const bruts = phrase.split(' '), res = []
  let ouvrant = ''
  for (const b of bruts) {
    if (/^[«]$/.test(b)) { ouvrant = '« '; continue }
    if (/^[:;?!»]/.test(b) && /^[:;?!».,]+$/.test(b) && res.length) { res[res.length - 1] += ' ' + b; continue }
    res.push(ouvrant + b); ouvrant = ''
  }
  return res
}

/**
 * Place les phrases : `avant[n]` = le temps que l'image prend avant que la voix
 * parle, `apres[n]` = ce qu'on laisse après le dernier mot (0,3 à 0,6 s : le
 * karaoké a déjà fait lire la phrase, inutile d'attendre davantage).
 */
function minuter(avant, apres, queue = 0) {
  let t = 0
  V = window.VOIX_BRUTE.map((p, n) => {
    const debut = t + avant[n]
    const mots = p.mots.map((m) => ({ debut: debut + m.debut, fin: debut + m.fin }))
    const jetons = jetonsDe(p.texte)
    if (jetons.length !== mots.length) console.warn('jetons ≠ mots', n, jetons, p.mots.map((m) => m.mot))
    const fin = mots[mots.length - 1].fin
    t = fin + apres[n]
    return { texte: p.texte, jetons, debut, fin, mots }
  })
  DUREE = t + queue
  return V
}
/** L'instant où le mot `k` de la phrase `n` est dit (pour caler un geste sur un mot). */
const M = (n, k) => V[n].mots[Math.min(k, V[n].mots.length - 1)].debut
const son = (t, type, gain = 1) => SONS.push({ t: Math.round(t * 1000) / 1000, type, gain })

/** Coupe une phrase en lignes équilibrées qui tiennent dans `largeur`. */
function lignes(jetons, style, largeur) {
  x.save(); x.font = `${style.poids ?? 700} ${style.taille}px ${style.police ?? SANS}`
  const esp = x.measureText(' ').width
  const ms = jetons.map((j, i) => ({ j, i, w: x.measureText(j).width }))
  x.restore()
  const total = ms.reduce((a, m) => a + m.w, 0) + esp * (ms.length - 1)
  for (let nl = 1; nl <= 5; nl++) {
    const cible = Math.max(total / nl * 1.06, ...ms.map((m) => m.w))
    const res = [[]]; let cour = 0
    for (const m of ms) {
      const ajout = (cour > 0 ? esp : 0) + m.w
      // on coupe si la ligne déborde la cible ; on préfère couper après une virgule ou un deux-points
      const ponct = res[res.length - 1].length && /[,:] ?$|:$/.test(res[res.length - 1][res[res.length - 1].length - 1].j)
      if (cour > 0 && (cour + ajout > cible || (ponct && cour > cible * 0.55 && res.length < nl))) { res.push([]); cour = 0 }
      res[res.length - 1].push(m); cour += (cour > 0 ? esp : 0) + m.w
    }
    const larg = (l) => l.reduce((a, m) => a + m.w, 0) + esp * (l.length - 1)
    if (res.every((l) => larg(l) <= largeur)) return { res, esp }
  }
  return { res: [ms], esp }
}

/**
 * Une phrase en karaoké : entière en gris, chaque mot s'allume en doré quand il
 * est dit, puis reste blanc. `sortieApres` : combien de temps elle reste après
 * le dernier mot.
 */
function karaoke(n, cx, cy, { taille = 56, largeur = 920, entree = 0.2, sortieApres = 0.4, poids = 700, police = SANS, jusqua = null } = {}) {
  const v = V[n]
  const kIn = eout(prog(t0, v.mots[0].debut - entree - 0.05, v.mots[0].debut + 0.1))
  const finVisible = jusqua ?? v.fin + sortieApres
  const kOut = eio(prog(t0, finVisible, finVisible + 0.22))
  if (kIn <= 0 || kOut >= 1) return 0
  const { res, esp } = lignes(v.jetons, { taille, poids, police }, largeur)
  const hl = taille * 1.18
  res.forEach((ligne, li) => {
    const total = ligne.reduce((a, m) => a + m.w, 0) + esp * (ligne.length - 1)
    let px = cx - total / 2
    const py = cy + (li - (res.length - 1) / 2) * hl + (1 - kIn) * 16
    for (const m of ligne) {
      const tm = v.mots[m.i]
      const dit = t0 >= tm.debut - 0.03
      const eclat = 1 - prog(t0, tm.debut, tm.debut + 0.35)
      const sc = dit && t0 >= tm.debut ? 1 + 0.07 * eclat : 1
      const coul = !dit ? '#5f5c57' : eclat > 0 ? melange(COUL.texte, COUL.accent, eclat) : COUL.texte
      x.save(); x.translate(px + m.w / 2, py); x.scale(sc, sc)
      texte(m.j, 0, 0, { taille, poids, police, coul, alpha: kIn * (1 - kOut) })
      x.restore()
      px += m.w + esp
    }
  })
  return kIn * (1 - kOut)
}

/** La légende du haut : un voile sombre, la phrase en karaoké par-dessus. */
function legende(n, { cy = 315 } = {}) {
  const v = V[n]
  if (!v) return
  const suivante = V[n + 1] ? V[n + 1].mots[0].debut - 0.3 : Infinity
  const finVisible = Math.min(v.fin + 0.5, suivante)
  const debut = v.mots[0].debut - 0.3
  if (t0 < debut || t0 > finVisible + 0.25) return
  const voile = Math.min(prog(t0, debut, debut + 0.2), 1 - prog(t0, finVisible, finVisible + 0.22))
  x.save(); x.globalAlpha = voile
  const g = x.createLinearGradient(0, 0, 0, 500); g.addColorStop(0, 'rgba(20,21,24,.97)'); g.addColorStop(0.78, 'rgba(20,21,24,.92)'); g.addColorStop(1, 'rgba(20,21,24,0)')
  x.fillStyle = g; x.fillRect(0, 0, W, 500)
  x.restore()
  const longue = v.jetons.length > 12
  karaoke(n, 540, cy, { taille: longue ? 50 : 56, largeur: 940, jusqua: finVisible })
}

/* -------- le fond et le cadre de l'application -------- */
function fondGrille() {
  x.fillStyle = COUL.fond; x.fillRect(0, 0, W, H)
  x.save(); x.globalAlpha = 0.5; x.fillStyle = '#1e2026'
  for (let gy = 0; gy < H; gy += 36) for (let gx = (gy / 36) % 2 ? 18 : 0; gx < W; gx += 36) x.fillRect(gx, gy, 2, 2)
  x.restore()
}

/** L'en-tête de l'application (téléphone) : le nom, la cloche, l'avatar. */
function enTete(y, { alpha = 1, onglet = null, cloche = 0, badge = null } = {}) {
  x.save(); x.globalAlpha *= alpha
  x.fillStyle = '#17181c'; x.fillRect(0, y, W, 120)
  x.fillStyle = COUL.ligne; x.fillRect(0, y + 119, W, 2)
  texte('Heritage', 48, y + 60, { taille: 44, poids: 700, align: 'left' })
  // la cloche
  x.save(); x.translate(900, y + 60); if (cloche) x.rotate(Math.sin(cloche * 18) * 0.35 * (1 - cloche)); dessinerCloche(0, 0, 30, COUL.texte); x.restore()
  if (badge) { x.beginPath(); x.arc(922, y + 38, 17, 0, Math.PI * 2); x.fillStyle = COUL.accent; x.fill(); texte(badge, 922, y + 39, { taille: 20, poids: 800, coul: COUL.fond }) }
  x.beginPath(); x.arc(1000, y + 60, 30, 0, Math.PI * 2); x.fillStyle = '#2c3038'; x.fill()
  silhouette(1000, y + 66, 40, '#596069')
  x.restore()
  if (onglet) ongletsBas(onglet, alpha)
}
function dessinerCloche(cx, cy, r, coul) {
  x.save(); x.strokeStyle = coul; x.lineWidth = r * 0.16; x.lineJoin = 'round'; x.lineCap = 'round'
  x.beginPath(); x.moveTo(cx - r * 0.6, cy + r * 0.45); x.quadraticCurveTo(cx - r * 0.55, cy - r * 0.75, cx, cy - r * 0.75)
  x.quadraticCurveTo(cx + r * 0.55, cy - r * 0.75, cx + r * 0.6, cy + r * 0.45); x.closePath(); x.stroke()
  x.beginPath(); x.arc(cx, cy + r * 0.62, r * 0.17, 0, Math.PI); x.stroke()
  x.restore()
}
/** La barre du bas (téléphone) : Accueil, Arbre, Explorer. */
function ongletsBas(actif, alpha = 1) {
  x.save(); x.globalAlpha *= alpha
  x.fillStyle = '#17181c'; x.fillRect(0, 1700, W, 220); x.fillStyle = COUL.ligne; x.fillRect(0, 1700, W, 2)
  ;['Accueil', 'Arbre', 'Explorer'].forEach((o, i) => {
    const cx = 180 + i * 360
    const on = o === actif
    x.fillStyle = on ? COUL.accent : '#5d6068'; rr(cx - 26, 1735, 52, 40, 10); x.fill()
    texte(o, cx, 1810, { taille: 30, poids: on ? 700 : 500, coul: on ? COUL.accent : COUL.attenue })
  })
  x.restore()
}

/* -------- les éléments d'interface -------- */
function bouton(label, cx, cy, w, h, { variante = 'plein', alpha = 1, presse = 0, taille = 36, icone = null } = {}) {
  x.save(); x.globalAlpha *= alpha
  const sc = 1 - 0.05 * presse
  x.translate(cx, cy); x.scale(sc, sc)
  rr(-w / 2, -h / 2, w, h, 16)
  if (variante === 'plein') { x.fillStyle = COUL.accent; x.fill() }
  else if (variante === 'violet') { x.fillStyle = COUL.violet; x.fill() }
  else if (variante === 'contour') { x.fillStyle = COUL.panneau2; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 3; x.stroke() }
  else if (variante === 'contourAccent') { x.fillStyle = COUL.fonceAccent; x.fill(); x.strokeStyle = COUL.accent; x.lineWidth = 3; x.stroke() }
  const coul = variante === 'plein' || variante === 'violet' ? '#1a1410' : variante === 'contourAccent' ? COUL.accent : COUL.texte
  if (presse > 0) { rr(-w / 2, -h / 2, w, h, 16); x.fillStyle = `rgba(255,255,255,${0.12 * presse})`; x.fill() }
  texte(label, icone ? 20 : 0, 2, { taille, poids: 700, coul })
  if (icone) icone(-largeurTexte(label, taille, 700) / 2 - 16, 2, coul)
  x.restore()
}
function champ(etiquette, valeur, px, py, w, { focus = 0, alpha = 1, curseur = false, aide = null, h = 96, mono = false, placeholder = '' } = {}) {
  x.save(); x.globalAlpha *= alpha
  texte(etiquette, px, py - 26, { taille: 30, poids: 600, align: 'left' })
  rr(px, py, w, h, 14); x.fillStyle = '#111214'; x.fill()
  x.strokeStyle = focus > 0 ? melange(COUL.ligne, COUL.accent, focus) : COUL.ligne; x.lineWidth = focus > 0 ? 4 : 3; x.stroke()
  if (valeur) texte(valeur, px + 26, py + h / 2 + 2, { taille: 36, poids: 500, align: 'left', police: mono ? MONO : SANS })
  else if (placeholder) texte(placeholder, px + 26, py + h / 2 + 2, { taille: 34, poids: 400, align: 'left', coul: '#55585e' })
  if (curseur && Math.floor(t0 * 2.4) % 2 === 0) { const cw = valeur ? largeurTexte(valeur, 36, 500, mono ? MONO : SANS) : 0; x.fillStyle = COUL.accent; x.fillRect(px + 28 + cw, py + 24, 4, h - 48) }
  if (aide) texte(aide, px, py + h + 32, { taille: 26, poids: 400, coul: COUL.attenue, align: 'left' })
  x.restore()
}
/** Ce qu'on voit d'un texte tapé à `cps` caractères/seconde, à partir de `debut` (avec les clics de clavier). */
function frappe(s, debut, cps = 14) { return s.slice(0, Math.max(0, Math.floor((t0 - debut) * cps))) }
function sonsDeFrappe(s, debut, cps = 14) { for (let i = 0; i < s.length; i += 2) son(debut + i / cps, 'touche', 0.5) }

function caseACocher(label, sous, px, py, coche, { alpha = 1, largeur = 900, droite = null } = {}) {
  x.save(); x.globalAlpha *= alpha
  rr(px, py - 22, 44, 44, 9)
  if (coche > 0) { x.fillStyle = melange('#111214', COUL.accent, coche); x.fill() }
  else { x.fillStyle = '#111214'; x.fill() }
  x.strokeStyle = coche > 0.5 ? COUL.accent : '#5d6068'; x.lineWidth = 3; x.stroke()
  if (coche > 0) {
    x.strokeStyle = '#1a1410'; x.lineWidth = 6; x.lineCap = 'round'; x.lineJoin = 'round'
    x.beginPath(); x.moveTo(px + 11, py + 1); x.lineTo(px + 19, py + 10)
    x.lineTo(lerp(px + 19, px + 34, clamp(coche * 2 - 0.5)), lerp(py + 10, py - 9, clamp(coche * 2 - 0.5))); x.stroke()
  }
  texte(label, px + 70, sous ? py - 14 : py, { taille: 36, poids: 600, align: 'left' })
  if (sous) texte(sous, px + 70, py + 26, { taille: 26, poids: 400, coul: COUL.attenue, align: 'left' })
  if (droite) texte(droite, px + largeur, py, { taille: 26, poids: 400, coul: COUL.attenue, align: 'right' })
  x.restore()
}
function pastille(s, cx, cy, { ton = 'accent', alpha = 1, taille = 26 } = {}) {
  const c = ton === 'vert' ? COUL.vert : ton === 'rouge' ? COUL.rouge : ton === 'violet' ? COUL.violet : COUL.accent
  const w = largeurTexte(s, taille, 700) + 36
  x.save(); x.globalAlpha *= alpha
  rr(cx - w / 2, cy - taille * 0.85, w, taille * 1.7, taille * 0.85); x.fillStyle = avecAlpha(c, 0.18); x.fill(); x.strokeStyle = avecAlpha(c, 0.6); x.lineWidth = 2; x.stroke()
  texte(s, cx, cy + 1, { taille, poids: 700, coul: c })
  x.restore()
  return w
}
/**
 * Une fiche comme dans l'arbre (mode « Fiches ») : fond bleu nuit pour un
 * homme, lie-de-vin pour une femme, liseré de la couleur de sa branche à gauche.
 */
function carteFiche(cx, cy, w, h, { nom = '', dates = '', sexe = 'h', branche = null, alpha = 1, surligne = 0, estompe = 0, vous = false, echelle = 1 } = {}) {
  x.save(); x.globalAlpha *= alpha * (1 - 0.72 * estompe)
  x.translate(cx, cy); x.scale(echelle, echelle)
  if (surligne > 0) { x.shadowColor = COUL.accent; x.shadowBlur = 50 * surligne }
  rr(-w / 2, -h / 2, w, h, 12); x.fillStyle = vous ? '#2a2620' : sexe === 'f' ? COUL.femme : COUL.homme; x.fill()
  x.shadowBlur = 0
  if (branche) { x.save(); rr(-w / 2, -h / 2, w, h, 12); x.clip(); x.fillStyle = COUL[branche] ?? branche; x.fillRect(-w / 2, -h / 2, 8, h); x.restore() }
  const r = Math.min(h * 0.3, 34)
  silhouette(-w / 2 + 18 + r, 2, r * 1.5, sexe === 'f' ? '#6a4d55' : '#4b5a74')
  const tx = -w / 2 + 34 + r * 2
  if (h > 60 && nom) {
    // le texte se resserre pour tenir dans la carte, comme dans l'application
    const place = w / 2 - tx - 14
    const tn = Math.min(30, h * 0.3, 30 * place / Math.max(1, largeurTexte(nom, 30, 700)))
    const td = dates ? Math.min(22, h * 0.22, 22 * place / Math.max(1, largeurTexte(dates, 22, 500, MONO))) : 0
    texte(nom, tx, dates ? -h * 0.13 : 0, { taille: tn, poids: 700, align: 'left' })
    if (dates) texte(dates, tx, h * 0.2, { taille: td, poids: 500, align: 'left', coul: COUL.attenue, police: MONO })
  }
  if (surligne > 0 || vous) { rr(-w / 2, -h / 2, w, h, 12); x.strokeStyle = COUL.accent; x.globalAlpha *= vous && !surligne ? 1 : surligne; x.lineWidth = 4; x.stroke() }
  x.restore()
}

/* -------- le doigt : où l'on touche -------- */
/**
 * Un doigt (disque translucide) qui arrive, appuie à `appui`, et laisse une onde.
 * On le voit 0,35 s avant l'appui, et il s'efface 0,35 s après.
 */
function doigt(px, py, appui, { depuis = null } = {}) {
  const avant = prog(t0, appui - 0.4, appui - 0.05), apres = prog(t0, appui + 0.15, appui + 0.5)
  if (avant <= 0 || apres >= 1) return
  let dx = px, dy = py
  if (depuis) { const k = edoux(avant); dx = lerp(depuis[0], px, k); dy = lerp(depuis[1], py, k) } else dy = py + (1 - eout(avant)) * 60
  const presse = t0 >= appui - 0.05 && t0 <= appui + 0.15 ? 1 : 0
  x.save(); x.globalAlpha = eout(avant) * (1 - apres)
  const onde = prog(t0, appui, appui + 0.5)
  if (onde > 0 && onde < 1) { x.beginPath(); x.arc(dx, dy, 40 + 70 * eout(onde), 0, Math.PI * 2); x.strokeStyle = `rgba(255,255,255,${0.5 * (1 - onde)})`; x.lineWidth = 5; x.stroke() }
  x.beginPath(); x.arc(dx, dy, presse ? 34 : 40, 0, Math.PI * 2); x.fillStyle = 'rgba(255,255,255,.28)'; x.fill()
  x.strokeStyle = 'rgba(255,255,255,.75)'; x.lineWidth = 4; x.stroke()
  x.restore()
}
/** Combien un appui « presse » un bouton (pour l'enfoncer à l'instant du doigt). */
const pression = (appui) => (t0 >= appui - 0.05 && t0 <= appui + 0.2 ? 1 - prog(t0, appui + 0.05, appui + 0.2) : 0)

/* -------- les fins -------- */
/** L'emblème et le nom, sur fond plein, à partir de `debut`. */
function emblemeFinal(debut, { sousTitre = null } = {}) {
  const k = eio(prog(t0, debut - 0.15, debut + 0.2))
  if (k <= 0) return
  x.save(); x.globalAlpha = k; x.fillStyle = COUL.fond; x.fillRect(0, 0, W, H); x.restore()
  const tronc = eout(prog(t0, debut, debut + 0.25)), branches = eout(prog(t0, debut + 0.15, debut + 0.4))
  x.save(); x.translate(540, 760); x.strokeStyle = COUL.accent; x.lineWidth = 11; x.lineCap = 'round'
  const ligne = (a, b, c, d, kk) => { if (kk <= 0) return; x.beginPath(); x.moveTo(a, b); x.lineTo(lerp(a, c, kk), lerp(b, d, kk)); x.stroke() }
  ligne(0, 120, 0, 10, tronc); ligne(0, 40, -70, -40, branches); ligne(0, 40, 70, -40, branches); ligne(0, 10, 0, -80, branches)
  ;[[-70, -40, COUL.A, 0], [0, -80, COUL.B, 0.08], [70, -40, COUL.C, 0.16]].forEach(([px, py, c, d]) => {
    const kk = eback(prog(t0, debut + 0.35 + d, debut + 0.6 + d), 3)
    if (kk > 0) { x.beginPath(); x.arc(px, py, 24 * kk, 0, Math.PI * 2); x.fillStyle = c; x.fill() }
  })
  x.restore()
  const titre = prog(t0, debut + 0.3, debut + 0.6)
  if (titre > 0) { const sc = lerp(1.35, 1, eout(titre)); x.save(); x.translate(540, 995); x.scale(sc, sc); texte('Heritage', 0, 0, { taille: 140, police: SERIF, poids: 600, alpha: clamp(titre * 2.5) }); x.restore() }
  if (sousTitre) texte(sousTitre, 540, 1100, { taille: 40, poids: 500, coul: COUL.attenue, alpha: eout(prog(t0, debut + 0.6, debut + 0.9)) })
}

/* -------- le titre d'ouverture d'un chapitre -------- */
function carton(titre, debut, fin, { numero = null } = {}) {
  const k = eout(prog(t0, debut, debut + 0.4)), s = eio(prog(t0, fin - 0.3, fin))
  if (k <= 0 || s >= 1) return
  x.save(); x.globalAlpha = k * (1 - s); x.fillStyle = COUL.fond; x.fillRect(0, 0, W, H)
  if (numero) texte(numero, 540, 860, { taille: 30, poids: 700, coul: COUL.accent, espacement: 6 })
  const sc = lerp(1.15, 1, k)
  x.translate(540, 950); x.scale(sc, sc)
  texte(titre, 0, 0, { taille: Math.min(92, 92 * 940 / largeurTexte(titre, 92, 600, SERIF)), police: SERIF, poids: 600 })
  x.fillStyle = COUL.accent; x.fillRect(-160 * eout(prog(t0, debut + 0.2, debut + 0.5)), 70, 320 * eout(prog(t0, debut + 0.2, debut + 0.5)), 6)
  x.restore()
}

/* -------- la caméra : des mouvements d'un cadrage à l'autre -------- */
/**
 * `depart` = {s, fx, fy} ; `etapes` = [{a, b, s, fx, fy}] : entre a et b, on glisse
 * (ease in-out) vers ce cadrage. Le monde se dessine ensuite sous `filmer(cam)`.
 */
function trajet(t, depart, etapes, courbe = edoux) {
  let c = { ...depart }
  for (const e of etapes) {
    const k = courbe(prog(t, e.a, e.b))
    if (k <= 0) break
    c = { s: lerp(c.s, e.s ?? c.s, k), fx: lerp(c.fx, e.fx ?? c.fx, k), fy: lerp(c.fy, e.fy ?? c.fy, k) }
  }
  return c
}
const CENTRE_Y = 1180 // le centre de la zone utile, sous la légende et la barre d'outils
function filmer(cam, cy = CENTRE_Y) { x.translate(540, cy); x.scale(cam.s, cam.s); x.translate(-cam.fx, -cam.fy) }
const versEcran = (cam, px, py, cy = CENTRE_Y) => [(px - cam.fx) * cam.s + 540, (py - cam.fy) * cam.s + cy]

/* -------- le rendu -------- */
let SCENE = () => {}
let AFFICHE = 1 // l'instant de l'image qui sert d'affiche
function render(t) {
  t0 = t
  x.setTransform(1, 0, 0, 1, 0, 0); x.globalAlpha = 1
  SCENE(t)
}
function lancer(scene, { affiche = 1 } = {}) {
  SCENE = scene; AFFICHE = affiche
  window.render = render
  window.DUREE = DUREE
  window.PISTE = { duree: DUREE, affiche, phrases: V.map((v) => ({ texte: v.texte, jetons: v.jetons, debut: v.debut, fin: v.fin, mots: v.mots })), sons: SONS }
  if (!window.__capture) { const d0 = performance.now(); (function boucle() { render(((performance.now() - d0) / 1000) % DUREE); requestAnimationFrame(boucle) })() }
}
