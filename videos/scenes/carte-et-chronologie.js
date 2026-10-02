/* Chapitre « Carte et chronologie » : les deux vues d'Explorer. */
minuter([1.9, 0.7, 0.75, 0.5, 0.6], [0.45, 0.6, 0.55, 0.6, 0.7], 2.0)

const P = {
  carton: 0.1, explorer: 1.3, ongletCarte: V[1].debut - 0.75,
  nes: M(1, 5), maries: M(1, 8), vecu: M(1, 10),
  ongletChrono: V[2].debut - 0.75, barres: M(2, 5), anciennes: M(2, 8), recentes: M(2, 11),
  couleurs: M(3, 1), cote: M(3, 10),
  recul: V[4].debut - 0.1,
  fin: V[4].fin + 0.7,
}
son(P.explorer, 'souffle', 0.5); son(P.ongletCarte, 'tape'); son(P.nes, 'note1'); son(P.maries, 'note2'); son(P.vecu, 'note3')
son(P.ongletChrono, 'tape'); son(P.barres, 'souffle', 0.5); son(P.couleurs, 'note2'); son(P.cote, 'note4'); son(P.recul, 'recul'); son(P.fin, 'final')

/* -------- les onglets d'Explorer -------- */
function onglets(t, actif) {
  x.fillStyle = 'rgba(23,24,28,.96)'; x.fillRect(0, 500, W, 100); x.fillStyle = COUL.ligne; x.fillRect(0, 598, W, 2)
  ;['Chronologie', 'Carte', 'Calendrier'].forEach((o, i) => {
    const cx = 180 + i * 360, on = o === actif
    texte(o, cx, 550, { taille: 34, poids: on ? 700 : 500, coul: on ? COUL.accent : COUL.attenue })
    if (on) { x.fillStyle = COUL.accent; x.fillRect(cx - 100, 592, 200, 6) }
  })
}

/* -------- la carte -------- */
const FRANCE = [[-4.8, 48.4], [-3.4, 48.8], [-1.6, 49.6], [-1.3, 49.7], [0.2, 49.5], [1.6, 50.9], [2.5, 51.1], [4.2, 49.95], [4.9, 49.8], [6.1, 49.5], [8.2, 48.97], [7.6, 47.6],
  [6.9, 47.4], [6.1, 46.15], [7.0, 45.9], [6.6, 45.1], [7.0, 44.2], [7.5, 43.8], [6.2, 43.1], [4.8, 43.4], [3.1, 43.1], [3.1, 42.45], [1.7, 42.5], [-0.5, 42.8], [-1.8, 43.4],
  [-1.3, 44.5], [-1.2, 46.0], [-2.2, 47.1], [-4.4, 47.8]]
const proj = ([lon, lat]) => [540 + (lon - 1.7) * 78, 1250 - (lat - 46.6) * 112]
const LIEUX = [
  // [lon, lat, branche, nature] — n : naissance, u : union, r : résidence
  [5.72, 45.19, 'A', 'n'], [4.83, 45.76, 'A', 'u'], [3.08, 45.78, 'A', 'n'], [5.04, 47.32, 'A', 'r'],
  [2.35, 48.86, 'B', 'n'], [3.06, 50.63, 'B', 'n'], [1.1, 49.44, 'B', 'u'], [2.3, 49.9, 'B', 'r'],
  [-0.58, 44.84, 'C', 'n'], [1.44, 43.6, 'C', 'u'], [1.26, 45.83, 'C', 'n'], [-1.55, 47.22, 'C', 'r'],
  [7.75, 48.58, 'D', 'n'], [5.37, 43.3, 'D', 'u'], [6.18, 48.69, 'D', 'n'], [-4.49, 48.39, 'D', 'r'],
  [4.83, 45.76, 'C', 'r'], [2.35, 48.86, 'A', 'u'],
]
function carte(t) {
  const k = eout(prog(t, P.ongletCarte, P.ongletCarte + 0.4)), s = eio(prog(t, P.ongletChrono, P.ongletChrono + 0.35))
  if (k <= 0 || s >= 1) return
  x.save(); x.globalAlpha = k * (1 - s)
  x.fillStyle = '#16181c'; x.fillRect(0, 600, W, H - 600)
  // la France, un trait discret
  const pts = FRANCE.map(proj)
  x.beginPath(); pts.forEach(([a, b], i) => (i ? x.lineTo(a, b) : x.moveTo(a, b))); x.closePath()
  x.fillStyle = '#1e2127'; x.fill(); x.strokeStyle = '#3a3f47'; x.lineWidth = 3; x.stroke()
  // les filtres de la carte
  const allume = { n: prog(t, P.nes - 0.1, P.nes + 0.2), u: prog(t, P.maries - 0.1, P.maries + 0.2), r: prog(t, P.vecu - 0.1, P.vecu + 0.2) }
  ;[['Naissances', 'n'], ['Unions', 'u'], ['Résidences', 'r']].forEach(([nom, c], i) => {
    const cx = 180 + i * 290, on = allume[c]
    rr(cx - 125, 630, 250, 62, 31); x.fillStyle = on > 0.5 ? '#3a2e22' : '#111214'; x.fill(); x.strokeStyle = on > 0.5 ? COUL.accent : COUL.ligne; x.lineWidth = 3; x.stroke()
    texte(nom, cx, 662, { taille: 28, poids: 700, coul: on > 0.5 ? COUL.texte : COUL.attenue })
  })
  // les lieux, colorés par branche, apparaissent avec leur nature
  LIEUX.forEach(([lon, lat, q, c], i) => {
    const kk = eback(prog(t, { n: P.nes, u: P.maries, r: P.vecu }[c] + (i % 4) * 0.06, { n: P.nes, u: P.maries, r: P.vecu }[c] + 0.3 + (i % 4) * 0.06), 2.5)
    if (kk <= 0) return
    const [px, py] = proj([lon, lat])
    const r = (c === 'n' ? 22 : c === 'u' ? 18 : 15) * kk
    x.beginPath(); x.arc(px, py, r + 10, 0, Math.PI * 2); x.fillStyle = avecAlpha(COUL[q], 0.22); x.fill()
    x.beginPath(); x.arc(px, py, r, 0, Math.PI * 2); x.fillStyle = COUL[q]; x.fill()
    if (c === 'u') { x.strokeStyle = COUL.fond; x.lineWidth = 4; x.beginPath(); x.arc(px - 5, py, 7, 0, Math.PI * 2); x.arc(px + 5, py, 7, 0, Math.PI * 2); x.stroke() }
  })
  x.restore()
}

/* -------- la chronologie : une barre par vie -------- */
const AN0 = 1700, AN1 = 2030, Y0 = 760, Y1 = 1760
const anY = (a) => Y0 + (a - AN0) / (AN1 - AN0) * (Y1 - Y0)
const VIES = []
{
  const r = rnd(9)
  for (let q = 0; q < 4; q++) {
    let n = 0
    for (let gen = 0; gen < 9; gen++) {
      const nb = gen < 3 ? 2 + Math.floor(r() * 2) : 3 + Math.floor(r() * 3)
      for (let j = 0; j < nb; j++) {
        const ne = 1705 + gen * 34 + Math.floor(r() * 24), vie = 28 + Math.floor(r() * 55)
        VIES.push({ q: 'ABCD'[q], ne, mort: Math.min(ne + vie, 2030), vivant: ne + vie > 2026, col: n++ })
      }
    }
  }
}
function chronologie(t) {
  const k = eout(prog(t, P.ongletChrono, P.ongletChrono + 0.4))
  if (k <= 0) return
  const recul = eio(prog(t, P.recul, P.recul + 0.9))
  x.save(); x.globalAlpha = k
  x.fillStyle = '#16181c'; x.fillRect(0, 600, W, H - 600)
  // les bandes de décennies, à gauche
  for (let a = AN0; a < AN1; a += 50) {
    const y = anY(a)
    x.fillStyle = (a / 50) % 2 ? '#1a1c20' : '#16181c'; x.fillRect(110, y, W - 110, anY(a + 50) - y)
    texte(String(a), 95, y + 14, { taille: 22, poids: 600, coul: COUL.attenue, align: 'right', police: MONO })
  }
  // les colonnes de branches, avec leur nom
  const coul = prog(t, P.couleurs - 0.1, P.couleurs + 0.4)
  for (let q = 0; q < 4; q++) {
    const x0 = 130 + q * 232
    const kq = eout(prog(t, P.couleurs + q * 0.12, P.couleurs + 0.3 + q * 0.12))
    if (kq > 0) {
      x.fillStyle = COUL['ABCD'[q]]; x.fillRect(x0 + 4, 690, 216 * kq, 6)
      texte(BRANCHES[q].nom, x0 + 112, 728, { taille: 28, poids: 800, coul: COUL['ABCD'[q]], alpha: kq })
    }
  }
  // les côtés : père, mère
  const kc = eout(prog(t, P.cote - 0.15, P.cote + 0.3))
  if (kc > 0) {
    ;[['Côté père', 130, 594], ['Côté mère', 594, 1058]].forEach(([s, a, b]) => {
      x.save(); x.globalAlpha *= kc
      x.strokeStyle = COUL.texte; x.lineWidth = 3; x.beginPath(); x.moveTo(a + 6, 660); x.lineTo(a + 6, 645); x.lineTo(b - 10, 645); x.lineTo(b - 10, 660); x.stroke()
      texte(s, (a + b) / 2, 625, { taille: 28, poids: 700, coul: COUL.texte })
      x.restore()
    })
  }
  // les vies : chaque barre pousse de la naissance à la mort ; on balaie des plus anciennes aux plus récentes
  const front = lerp(AN0, AN1 + 20, eio(prog(t, P.barres - 0.2, P.recentes + 0.4)))
  for (const v of VIES) {
    if (v.ne > front) continue
    const qi = 'ABCD'.indexOf(v.q)
    const larg = 216 / 18, px = 130 + qi * 232 + 6 + (v.col % 17) * (larg + 0.6)
    const fin = Math.min(v.mort, front)
    const c = coul > 0 ? melange('#5d6068', COUL[v.q], coul) : '#5d6068'
    x.fillStyle = c; rr(px, anY(v.ne), larg - 2, Math.max(4, anY(fin) - anY(v.ne)), 3); x.fill()
    if (v.vivant && fin >= 2026) { x.fillStyle = '#ffffff'; x.globalAlpha *= 0.8; x.fillRect(px, anY(2026) - 2, larg - 2, 4); x.globalAlpha = k }
  }
  // l'année courante du balayage
  if (front < AN1 + 10 && front > AN0 + 5) { x.fillStyle = avecAlpha(COUL.accent, 0.7); x.fillRect(110, anY(Math.min(front, AN1)), W - 110, 3) }
  x.restore()
  // siècle après siècle : un recul, la lumière passe sur tout l'arbre du temps
  if (recul > 0) {
    const y = lerp(Y0, Y1, recul)
    const g = x.createLinearGradient(0, y - 160, 0, y + 20); g.addColorStop(0, 'rgba(227,163,95,0)'); g.addColorStop(1, 'rgba(227,163,95,.16)')
    x.fillStyle = g; x.fillRect(110, y - 160, W - 110, 180)
  }
}

SCENE = (t) => {
  fondGrille()
  const ke = eout(prog(t, P.explorer, P.explorer + 0.4))
  if (ke > 0) {
    x.save(); x.globalAlpha = ke
    // l'accroche : les trois onglets, avant qu'on en choisisse un
    if (t < P.ongletCarte + 0.4) {
      ;[['Chronologie', 'une barre par vie'], ['Carte', 'les lieux de la famille'], ['Calendrier', 'les dates à fêter']].forEach(([a, b], i) => {
        const ki = eout(prog(t, P.explorer + 0.15 + i * 0.12, P.explorer + 0.5 + i * 0.12)) * (1 - prog(t, P.ongletCarte, P.ongletCarte + 0.3))
        x.save(); x.globalAlpha *= ki
        rr(60, 760 + i * 250, 960, 210, 26); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
        texte(a, 110, 840 + i * 250, { taille: 50, poids: 700, align: 'left' })
        texte(b, 110, 905 + i * 250, { taille: 32, poids: 400, coul: COUL.attenue, align: 'left' })
        x.restore()
      })
    }
    carte(t)
    chronologie(t)
    onglets(t, t < P.ongletCarte ? null : t < P.ongletChrono ? 'Carte' : 'Chronologie')
    x.restore()
    doigt(540, 550, P.ongletCarte); doigt(180, 550, P.ongletChrono)
  }
  for (let n = 0; n < 5; n++) legende(n)
  carton('Carte et chronologie', P.carton, P.explorer + 0.1, { numero: 'AIDE' })
  emblemeFinal(P.fin, { sousTitre: 'Carte et chronologie' })
}
lancer(SCENE, { affiche: P.vecu + 0.8 })
