/* ------------------------------------------------------------------------ */
/* L'arbre fictif des chapitres : Camille Martin (1992), sa fratrie, ses     */
/* enfants, et cinq générations d'ascendants, colorés par branche comme     */
/* dans l'application (Martin, Bernard côté père ; Dubois, Lefèvre côté     */
/* mère). Coordonnées « monde » : 1080 de large à l'échelle 1.              */
/* ------------------------------------------------------------------------ */
const PRENOMS_H = ['Jean', 'Louis', 'Henri', 'Pierre', 'Joseph', 'Émile', 'Auguste', 'Marcel', 'Albert', 'Jules', 'Victor', 'André']
const PRENOMS_F = ['Marie', 'Louise', 'Jeanne', 'Rose', 'Berthe', 'Lucie', 'Alice', 'Marthe', 'Julie', 'Eugénie', 'Céline', 'Adèle']
const NOMS_Q = { A: ['Martin', 'Roux', 'Morel', 'Girard'], B: ['Bernard', 'Faure', 'Blanc', 'Garnier'], C: ['Dubois', 'Leroy', 'Perrin', 'Robin'], D: ['Lefèvre', 'Mercier', 'Fontaine', 'Chevalier'] }
const GEN_Y = { '-1': 1530, 0: 1340, 1: 1150, 2: 970, 3: 800, 4: 650 }

function arbreFictif() {
  const r = rnd(42), N = []
  const ajoute = (n) => { N.push(n); return n }
  const vous = ajoute({ id: 'vous', g: 0, nom: 'Camille Martin', dates: '° 1992', naissance: 1992, sexe: 'f', q: null, cx: 540, cy: GEN_Y[0], w: 290, h: 104, vous: true })
  ajoute({ id: 'lucas', g: 0, nom: 'Lucas Martin', dates: '° 1995', naissance: 1995, sexe: 'h', q: null, cx: 188, cy: GEN_Y[0], w: 290, h: 104, fratrie: true })
  ajoute({ id: 'emma', g: 0, nom: 'Emma Martin', dates: '° 1989', naissance: 1989, sexe: 'f', q: null, cx: 892, cy: GEN_Y[0], w: 290, h: 104, fratrie: true })
  ajoute({ id: 'leo', g: -1, nom: 'Léo Martin', dates: '° 2018', sexe: 'h', q: null, cx: 380, cy: GEN_Y[-1], w: 280, h: 100, enfant: true })
  ajoute({ id: 'jade', g: -1, nom: 'Jade Martin', dates: '° 2021', sexe: 'f', q: null, cx: 700, cy: GEN_Y[-1], w: 280, h: 100, enfant: true })
  ajoute({ id: 'pere', g: 1, nom: 'Paul Martin', dates: '° 1961', sexe: 'h', q: 'A', cx: 290, cy: GEN_Y[1], w: 300, h: 104, sosa: 2 })
  ajoute({ id: 'mere', g: 1, nom: 'Hélène Dubois', dates: '° 1964', sexe: 'f', q: 'C', cx: 790, cy: GEN_Y[1], w: 300, h: 104, sosa: 3 })
  const tailles = { 2: [232, 94], 3: [114, 78], 4: [56, 58] }
  for (let g = 2; g <= 4; g++) {
    const n = 2 ** g, cell = 1000 / n, [w, h] = tailles[g]
    for (let i = 0; i < n; i++) {
      const q = 'ABCD'[Math.floor(i / (n / 4))], sexe = i % 2 ? 'f' : 'h'
      const prenom = (sexe === 'h' ? PRENOMS_H : PRENOMS_F)[Math.floor(r() * 12)]
      const nom = NOMS_Q[q][(Math.floor(i / 2) + (sexe === 'f' ? 1 : 0)) % 4]
      const ne = 1932 - (g - 2) * 28 + Math.floor(r() * 6), mort = ne + 60 + Math.floor(r() * 25)
      ajoute({ id: `a${g}_${i}`, g, i, nom: g === 2 ? `${prenom} ${nom}` : g === 3 ? nom : '', dates: g === 2 ? `${ne} – ${mort}` : g === 3 ? `${ne}` : '', naissance: ne, sexe, q, cx: 40 + cell / 2 + i * cell, cy: GEN_Y[g], w, h, sosa: n + i })
    }
  }
  const parSosa = (s) => N.find((n) => n.sosa === s)
  // la grand-mère paternelle sert d'exemple dans les chapitres
  const gm = parSosa(5); gm.nom = 'Simone Bernard'; gm.dates = '1935 – 2019'; gm.naissance = 1935
  parSosa(10).nom = 'Bernard'; parSosa(11).nom = 'Faure'; parSosa(4).nom = 'Jean Martin'
  // les ascendants lointains, pour la vue « Ensemble » : des blocs par branche
  const loin = []
  for (let k = 5; k <= 10; k++) for (let q = 0; q < 4; q++) {
    const x0 = 40 + q * 250, nb = Math.max(2, Math.round((k < 8 ? 3 + k * 0.6 : 13 - k) + r() * 2))
    for (let j = 0; j < nb; j++) loin.push({ q: 'ABCD'[q], cx: x0 + 14 + r() * 222, cy: GEN_Y[4] - (k - 4) * 120, w: 18 + r() * 34, h: 46 })
  }
  return { N, vous, parSosa, loin }
}

/** Les ancêtres (par numéro Sosa) et les descendants d'une fiche de l'arbre fictif. */
function ligneDirecteDe(A, n) {
  const res = new Set([n])
  if (n.sosa) {
    // ascendants : les Sosa 2s, 2s+1 de proche en proche ; descendants : les Sosa s/2 jusqu'à Camille
    const monte = (s) => { for (const k of [2 * s, 2 * s + 1]) { const a = A.parSosa(k); if (a) { res.add(a); monte(k) } } }
    monte(n.sosa)
    let s = n.sosa
    while (s > 3) { s = Math.floor(s / 2); res.add(A.parSosa(s)) }
    // un parent de Camille : toute la fratrie descend de lui
    for (const m of A.N.filter((m) => m.g === 0)) res.add(m)
  }
  if (n.sosa || n === A.vous) for (const e of A.N.filter((m) => m.enfant)) res.add(e)
  return res
}

/** Les traits d'un arbre fictif : chaque couple descend vers ses enfants. */
function traitsDe(A, { k = 1, alpha = 1, estompe = () => 0, position = (n) => n } = {}) {
  if (k <= 0) return
  x.save(); x.strokeStyle = '#4a5059'; x.lineWidth = 3
  const seg = (a, b, c, d, e = 0) => { x.globalAlpha = alpha * (1 - 0.72 * e); x.beginPath(); x.moveTo(a, b); x.lineTo(lerp(a, c, k), lerp(b, d, k)); x.stroke() }
  const P = (n) => (n ? position(n) : null)
  const couple = (p, m, enfants) => {
    const pp = P(p), mm = P(m)
    const es = enfants.map(P).filter(Boolean)
    if (!pp || !mm || !es.length) return
    const yU = pp.cy + pp.h / 2 + 14, mid = (pp.cx + mm.cx) / 2
    const yB = es[0].cy - es[0].h / 2 - 18
    const e = Math.min(estompe(p), estompe(m))
    seg(pp.cx, pp.cy + pp.h / 2, pp.cx, yU, e); seg(mm.cx, mm.cy + mm.h / 2, mm.cx, yU, e); seg(pp.cx, yU, mm.cx, yU, e)
    seg(mid, yU, mid, yB, e)
    const xs = es.map((c) => c.cx)
    seg(Math.min(mid, ...xs), yB, Math.max(mid, ...xs), yB, e)
    enfants.forEach((c) => { const cc = P(c); if (cc) seg(cc.cx, yB, cc.cx, cc.cy - cc.h / 2, Math.max(e, estompe(c))) })
  }
  const S = A.parSosa
  couple(S(2), S(3), A.N.filter((n) => n.g === 0))
  for (let s = 2; s < 16; s++) couple(S(2 * s), S(2 * s + 1), [S(s)])
  // Camille et ses enfants
  const v = P(A.vous)
  const es = A.N.filter((n) => n.enfant).map(P).filter(Boolean)
  if (v && es.length) {
    const yB = es[0].cy - es[0].h / 2 - 18, e = estompe(A.vous)
    seg(v.cx, v.cy + v.h / 2, v.cx, yB, e); seg(Math.min(...es.map((c) => c.cx)), yB, Math.max(...es.map((c) => c.cx)), yB, e)
    for (const c of es) seg(c.cx, yB, c.cx, c.cy - c.h / 2, e)
  }
  x.restore()
}

/** Une fiche de l'arbre fictif : carte détaillée, ou bloc plein en vue « Ensemble » (`ens` de 0 à 1). */
function ficheDe(n, { ens = 0, alpha = 1, estompe = 0, surligne = 0, echelle = 1, couleurs = 1, cx = n.cx, cy = n.cy } = {}) {
  if (alpha <= 0) return
  const branche = n.q ? (couleurs >= 1 ? n.q : couleurs > 0 ? melange('#3a3f47', COUL[n.q], couleurs) : null) : null
  if (ens < 1) carteFiche(cx, cy, n.w, n.h, { nom: n.nom, dates: n.dates, sexe: n.sexe, branche, alpha: alpha * (1 - ens), estompe, surligne, echelle, vous: n.vous })
  if (ens > 0) {
    x.save(); x.globalAlpha *= alpha * ens * (1 - 0.72 * estompe)
    rr(cx - n.w / 2, cy - n.h / 2, n.w, n.h, 6); x.fillStyle = n.vous ? COUL.accent : n.q ? COUL[n.q] : '#5d6068'; x.fill()
    x.restore()
  }
}
function blocsLointains(A, ens, { masque = () => 0 } = {}) {
  if (ens <= 0) return
  x.save()
  for (const b of A.loin) {
    const m = masque(b.q)
    if (m >= 1) continue
    x.globalAlpha = ens * (1 - m); rr(b.cx - b.w / 2, b.cy - b.h / 2, b.w, b.h, 4); x.fillStyle = COUL[b.q]; x.fill()
  }
  x.restore()
}

/** La barre d'outils de l'arbre, sous la légende : modes à gauche, Filtres et « Me trouver » à droite. */
function barreArbre({ mode = 'Fiches', alpha = 1, presseFiltres = 0, presseMoi = 0, filtresActifs = 0 } = {}) {
  x.save(); x.globalAlpha *= alpha
  x.fillStyle = 'rgba(23,24,28,.96)'; x.fillRect(0, 500, W, 100); x.fillStyle = COUL.ligne; x.fillRect(0, 598, W, 2)
  rr(30, 515, 470, 70, 14); x.fillStyle = '#111214'; x.fill()
  ;['Ensemble', 'Familles', 'Fiches'].forEach((m, i) => {
    const cx = 30 + 78 + i * 156
    if (m === mode) { rr(cx - 74, 521, 148, 58, 11); x.fillStyle = '#3a2e22'; x.fill() }
    texte(m, cx, 551, { taille: 28, poids: m === mode ? 700 : 500, coul: m === mode ? COUL.texte : COUL.attenue })
  })
  bouton(filtresActifs ? `Filtres · ${filtresActifs}` : 'Filtres', 680, 550, 200, 70, { variante: filtresActifs ? 'contourAccent' : 'contour', taille: 28, presse: presseFiltres })
  bouton('Me trouver', 915, 550, 230, 70, { taille: 28, presse: presseMoi })
  x.restore()
}
/** Le voile du bas de l'arbre, sous lequel les fiches disparaissent proprement. */
function voileBas(y0 = 1700) {
  const g = x.createLinearGradient(0, y0, 0, H); g.addColorStop(0, 'rgba(20,21,24,0)'); g.addColorStop(0.5, 'rgba(20,21,24,.9)'); g.addColorStop(1, 'rgba(20,21,24,1)')
  x.fillStyle = g; x.fillRect(0, y0, W, H - y0)
}
/** Le volet d'une fiche (téléphone : un feuillet qui monte du bas). `k` : 0 fermé → 1 ouvert. */
function voletFiche(k, { nom, dates, branche = 'B', ligne = 0, presseLigne = 0, proches = [], hauteur = 820 } = {}) {
  if (k <= 0) return null
  const y0 = H - hauteur * k
  x.save()
  x.shadowColor = 'rgba(0,0,0,.6)'; x.shadowBlur = 50
  rr(20, y0, 1040, hauteur + 60, 34); x.fillStyle = COUL.panneau; x.fill(); x.shadowBlur = 0
  x.fillStyle = COUL[branche] ?? branche; x.fillRect(20, y0, 1040, 7)
  x.fillStyle = COUL.ligne; rr(490, y0 + 22, 100, 10, 5); x.fill()
  texte(nom, 70, y0 + 92, { taille: 50, poids: 700, align: 'left' })
  texte(dates, 70, y0 + 148, { taille: 30, poids: 500, align: 'left', coul: COUL.attenue, police: MONO })
  bouton('Centrer', 290, y0 + 235, 440, 80, { variante: 'contour', taille: 30 })
  bouton('Fiche', 790, y0 + 235, 440, 80, { taille: 30 })
  caseACocher('Afficher sa ligne directe', 'ancêtres et descendants', 70, y0 + 345, ligne, { alpha: 1 })
  if (presseLigne) { rr(60, y0 + 310, 600, 72, 12); x.fillStyle = `rgba(255,255,255,${0.08 * presseLigne})`; x.fill() }
  x.fillStyle = COUL.ligne; x.fillRect(70, y0 + 410, 940, 2)
  let ly = y0 + 455
  for (const [a, b, c] of proches) {
    if (b === null) texte(a, 70, ly, { taille: 24, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3 })
    else {
      texte(a, 70, ly, { taille: 32, poids: 600, coul: COUL.accent, align: 'left' })
      texte(b, 800, ly, { taille: 26, poids: 500, coul: COUL.attenue, align: 'right', police: MONO })
      if (c) texte(c, 1000, ly, { taille: 26, poids: 500, coul: COUL.attenue, align: 'right', police: MONO })
    }
    ly += 52
  }
  x.restore()
  return y0
}
