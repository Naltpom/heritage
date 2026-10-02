/* Chapitre « Les sources et les actes » : ce que veulent dire « Vérifié » et
   « Non vérifié », où voir les sources, comment joindre une pièce. */
minuter([1.7, 0.6, 0.6, 0.6, 0.6, 0.6], [0.5, 0.6, 0.55, 0.55, 0.6, 0.6], 2.0)

const P = {
  carton: 0.1, fiche: 1.3, badge: M(0, 2),
  verifie: V[1].debut - 0.5, acte: M(1, 5) - 0.6, tampon: M(1, 8),
  nonVerifie: V[2].debut - 0.5, copies: M(2, 3), sansActe: M(2, 7),
  onglet: M(3, 1) - 0.1, actes: M(3, 7), notes: M(3, 10),
  joindre: V[4].debut - 0.45, pActe: M(4, 3), pPhoto: M(4, 5), pLivret: M(4, 7), joignez: M(4, 10) - 0.05,
  arbre: V[5].debut - 0.5,
  fin: V[5].fin + 0.7,
}
son(P.fiche, 'souffle', 0.6); son(P.badge, 'pop'); son(P.acte, 'souffle', 0.5); son(P.acte + 0.3, 'impact', 0.7); son(P.tampon, 'tampon')
son(P.nonVerifie, 'souffle', 0.5); son(P.copies, 'pop'); son(P.copies + 0.15, 'pop'); son(P.sansActe, 'pop')
son(P.onglet, 'tape'); son(P.actes, 'pop'); son(P.notes, 'pop')
son(P.joindre, 'souffle', 0.5); son(P.pActe, 'note1'); son(P.pPhoto, 'note2'); son(P.pLivret, 'note3'); son(P.joignez, 'tape'); son(P.joignez + 0.2, 'succes')
son(P.arbre, 'souffle', 0.5); for (let i = 0; i < 6; i++) son(P.arbre + 0.6 + i * 0.25, 'touche', 2)
son(P.fin, 'final')

/* -------- un acte d'état civil, écrit à la main -------- */
function acteManuscrit(px, py, rot, s, { ecrit = 1, titre = 'Acte de naissance', sous = 'N° 37 — l’an mil neuf cent trente-cinq' } = {}) {
  x.save(); x.translate(px, py); x.rotate(rot); x.scale(s, s)
  x.shadowColor = 'rgba(0,0,0,.55)'; x.shadowBlur = 40
  rr(-330, -400, 660, 800, 10); x.fillStyle = COUL.papier; x.fill(); x.shadowBlur = 0
  texte(titre, 0, -335, { taille: 40, poids: 700, police: SERIF, coul: COUL.encre })
  texte(sous, 0, -288, { taille: 22, poids: 500, police: SERIF, coul: '#6b5a44' })
  const r = rnd(11)
  x.strokeStyle = COUL.encre; x.lineWidth = 3; x.lineCap = 'round'
  for (let l = 0; l < 10; l++) {
    const ly = -220 + l * 58, fin = l === 9 ? 0.5 : 0.92 - r() * 0.12, visible = clamp(ecrit * 10 - l)
    x.beginPath(); let p = -280; x.moveTo(p, ly)
    while (p < -280 + 560 * fin) {
      const pas = 9 + r() * 13, haut = 7 + r() * 13, bas = (r() - 0.5) * 4, coupe = r() < 0.12
      if (p < -280 + 560 * fin * visible) x.quadraticCurveTo(p + pas / 2, ly - haut, p + pas, ly + bas)
      p += pas
      if (coupe) { p += 12; x.moveTo(p, ly) }
    }
    x.stroke()
  }
  x.restore()
}

function enTeteFiche(nom, dates, regime, y, a = 1, sexe = 'f') {
  x.save(); x.globalAlpha *= a
  x.save(); x.beginPath(); x.arc(120, y, 66, 0, Math.PI * 2); x.fillStyle = '#2c3038'; x.fill(); x.clip(); silhouette(120, y + 10, 100, sexe === 'f' ? '#6a4d55' : '#4b5a74'); x.restore()
  texte(nom, 215, y - 30, { taille: 50, poids: 700, align: 'left' })
  texte(dates, 215, y + 22, { taille: 30, poids: 500, align: 'left', coul: COUL.attenue, police: MONO })
  x.restore()
}

/* -------- les deux régimes d'une fiche -------- */
function regime(t, { quand, sortie, nom, dates, ton, court, long, sexe, y }) {
  const k = eout(prog(t, quand, quand + 0.4)), s = eio(prog(t, sortie, sortie + 0.35))
  if (k <= 0 || s >= 1) return
  x.save(); x.globalAlpha = k * (1 - s); x.translate((1 - k) * 120 - s * 200, 0)
  rr(40, y - 110, 1000, 330, 26); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = avecAlpha(ton === 'vert' ? COUL.vert : COUL.rouge, 0.5); x.lineWidth = 3; x.stroke()
  enTeteFiche(nom, dates, null, y, 1, sexe)
  const kb = eback(prog(t, quand + 0.25, quand + 0.5), 2.5)
  if (kb > 0) { x.save(); x.translate(300, y + 80); x.scale(kb, kb); pastille(court, 0, 0, { ton, taille: 28 }); x.restore() }
  texte(long, 80, y + 160, { taille: 30, poids: 500, coul: ton === 'vert' ? COUL.vert : COUL.rouge, align: 'left', alpha: eout(prog(t, quand + 0.45, quand + 0.75)) })
  x.restore()
}

function scenesRegimes(t) {
  // l'en-tête qui annonce : chaque fiche dit d'où viennent ses informations
  const k0 = eout(prog(t, P.fiche, P.fiche + 0.4)), s0 = eio(prog(t, P.verifie - 0.2, P.verifie + 0.2))
  if (k0 > 0 && s0 < 1) {
    x.save(); x.globalAlpha = k0 * (1 - s0)
    rr(40, 640, 1000, 300, 26); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
    enTeteFiche('Jean Martin', '1932 – 2006', null, 740, 1, 'h')
    const kb = eback(prog(t, P.badge, P.badge + 0.3), 2.5)
    if (kb > 0) { x.save(); x.translate(300, 820); x.scale(kb, kb); pastille('Vérifié', 0, 0, { ton: 'vert', taille: 28 }); x.restore()
      const halo = prog(t, P.badge, P.badge + 0.9); if (halo < 1) { x.beginPath(); x.ellipse(300, 820, 90 + 80 * halo, 34 + 30 * halo, 0, 0, Math.PI * 2); x.strokeStyle = avecAlpha(COUL.vert, 0.7 * (1 - halo)); x.lineWidth = 4; x.stroke() } }
    x.restore()
  }
  regime(t, { quand: P.verifie, sortie: P.onglet - 0.4, nom: 'Jean Martin', dates: '1932 – 2006', ton: 'vert', court: 'Vérifié', long: 'Vérifié sur un acte ou un registre.', sexe: 'h', y: 740 })
  // l'acte tombe sous la fiche vérifiée, le tampon claque sur « acte »
  const chute = prog(t, P.acte, P.acte + 0.3), sa = eio(prog(t, P.nonVerifie - 0.3, P.nonVerifie + 0.1))
  if (chute > 0 && sa < 1) {
    const rebond = Math.exp(-Math.max(0, t - P.acte - 0.3) * 12) * Math.sin(Math.max(0, t - P.acte - 0.3) * 30) * 14
    x.save(); x.globalAlpha = 1 - sa
    acteManuscrit(540, lerp(-500, 1330, chute * chute) - rebond + sa * 600, -0.05, 0.78, { ecrit: prog(t, P.acte + 0.3, P.tampon - 0.1) })
    const tp = prog(t, P.tampon, P.tampon + 0.12)
    if (tp > 0) {
      x.save(); x.translate(700, 1500); x.rotate(-0.2); const sc = lerp(3, 1, tp * tp) * 0.85; x.scale(sc, sc); x.globalAlpha *= clamp(tp * 3)
      rr(-210, -62, 420, 124, 16); x.strokeStyle = '#2f9d64'; x.lineWidth = 9; x.stroke()
      texte('VÉRIFIÉ PAR ACTE', 0, 4, { taille: 44, poids: 800, coul: '#2f9d64', espacement: 2 })
      x.restore()
    }
    x.restore()
  }
  regime(t, { quand: P.nonVerifie, sortie: P.onglet - 0.4, nom: 'Simone Bernard', dates: '1935 – 2019', ton: 'rouge', court: 'Non vérifié', long: 'Non vérifié : repris d’autres arbres, sans acte.', sexe: 'f', y: 1150 })
  // les arbres en ligne qui ont été recopiés
  const kc = prog(t, P.copies, P.copies + 0.5), sc = eio(prog(t, P.onglet - 0.4, P.onglet))
  if (kc > 0 && sc < 1) {
    x.save(); x.globalAlpha = (1 - sc)
    ;[['Arbre en ligne', 300], ['Autre arbre', 780]].forEach(([s, cx], i) => {
      const ki = eout(prog(t, P.copies + i * 0.15, P.copies + 0.4 + i * 0.15))
      x.save(); x.globalAlpha *= ki; x.translate(cx, 1560 + (1 - ki) * 40)
      rr(-180, -70, 360, 140, 20); x.fillStyle = COUL.panneau2; x.fill(); x.setLineDash([10, 8]); x.strokeStyle = '#5d6068'; x.lineWidth = 3; x.stroke(); x.setLineDash([])
      x.strokeStyle = '#8d8880'; x.lineWidth = 5; x.lineCap = 'round'
      x.beginPath(); x.moveTo(-120, 20); x.lineTo(-120, -10); x.moveTo(-120, 0); x.lineTo(-140, -25); x.moveTo(-120, 0); x.lineTo(-100, -25); x.stroke()
      texte(s, 10, 0, { taille: 30, poids: 600, coul: COUL.attenue })
      x.restore()
      // la flèche de recopie, vers la fiche
      x.save(); x.globalAlpha *= ki * 0.8; x.setLineDash([8, 8]); x.lineDashOffset = -t * 40; x.strokeStyle = '#8d8880'; x.lineWidth = 3
      x.beginPath(); x.moveTo(cx, 1490); x.lineTo(lerp(cx, 540, 0.3), 1390); x.stroke(); x.setLineDash([]); x.restore()
    })
    const ks = eback(prog(t, P.sansActe, P.sansActe + 0.3), 2)
    if (ks > 0) { x.save(); x.translate(540, 1700); x.scale(ks, ks); pastille('aucun acte pour l’instant', 0, 0, { ton: 'rouge', taille: 28 }); x.restore() }
    x.restore()
  }
}

/* -------- l'onglet « Sources et documents » -------- */
function ongletSources(t) {
  const k = eout(prog(t, P.onglet, P.onglet + 0.4)), s = eio(prog(t, P.arbre - 0.2, P.arbre + 0.2))
  if (k <= 0 || s >= 1) return
  x.save(); x.globalAlpha = k * (1 - s)
  enTeteFiche('Jean Martin', '1932 – 2006', null, 640, 1, 'h')
  pastille('Vérifié', 300, 720, { ton: 'vert', taille: 24 })
  // les gestes de la fiche ; « Joindre un document » s'allume au bon moment
  ;[0, 1, 2, 3].forEach((i) => {
    const cx = 690 + i * 92, cy = 610, doc = i === 2, crayon = i === 3
    const halo = doc ? eout(prog(t, P.joignez - 0.1, P.joignez + 0.15)) : 0
    rr(cx - 38, cy - 38, 76, 76, 16); x.fillStyle = crayon ? COUL.violet : doc && halo ? melange(COUL.panneau2, '#3a2e22', halo) : COUL.panneau2; x.fill()
    if (!crayon) { x.strokeStyle = doc && halo ? COUL.accent : COUL.ligne; x.lineWidth = doc && halo ? 4 : 2; x.stroke() }
    if (doc) { x.fillStyle = COUL.texte; rr(cx - 14, cy - 20, 28, 38, 4); x.fill(); x.fillStyle = COUL.panneau2; x.fillRect(cx - 8, cy - 8, 16, 3); x.fillRect(cx - 8, cy, 16, 3) }
    else if (!crayon) { x.fillStyle = i === 0 ? COUL.rouge : COUL.attenue; rr(cx - 13, cy - 16, 26, 32, 4); x.fill() }
  })
  // les onglets
  const sur = eout(prog(t, P.onglet, P.onglet + 0.3))
  ;['Identité et famille', 'Sources et documents · 2', 'Historique'].forEach((o, i) => texte(o, [60, 330, 760][i], 800, { taille: 28, poids: i === 1 ? 700 : 500, coul: i === 1 ? melange(COUL.attenue, COUL.accent, sur) : i === 0 ? melange(COUL.accent, COUL.attenue, sur) : COUL.attenue, align: 'left' }))
  x.fillStyle = COUL.accent; x.fillRect(lerp(60, 330, sur), 826, lerp(250, 340, sur), 4); x.fillStyle = COUL.ligne; x.fillRect(0, 830, W, 2)
  doigt(480, 800, P.onglet)
  // la source, puis la note
  const ka = eout(prog(t, P.actes - 0.2, P.actes + 0.2))
  if (ka > 0) {
    x.save(); x.globalAlpha *= ka; x.translate(0, (1 - ka) * 40)
    rr(40, 880, 1000, 300, 22); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
    texte('SOURCE', 80, 925, { taille: 22, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3 })
    x.fillStyle = COUL.vert; x.fillRect(80, 950, 5, 190)
    texte('Acte de naissance n° 37', 110, 985, { taille: 36, poids: 700, align: 'left' })
    texte('Mairie de Grenoble · registre 1932', 110, 1035, { taille: 28, poids: 400, coul: COUL.attenue, align: 'left' })
    texte('Archives départementales · vue 112', 110, 1078, { taille: 28, poids: 400, coul: COUL.attenue, align: 'left' })
    pastille('acte', 160, 1125, { ton: 'vert', taille: 22 })
    // la vignette de l'acte
    x.save(); x.translate(920, 1030); x.rotate(0.06); rr(-70, -90, 140, 180, 6); x.fillStyle = COUL.papier; x.fill()
    x.strokeStyle = COUL.encre; x.lineWidth = 2; for (let l = 0; l < 7; l++) { x.beginPath(); x.moveTo(-50, -60 + l * 20); x.lineTo(40 - (l % 3) * 12, -60 + l * 20); x.stroke() } x.restore()
    x.restore()
  }
  const kn = eout(prog(t, P.notes - 0.2, P.notes + 0.2))
  if (kn > 0) {
    x.save(); x.globalAlpha *= kn; x.translate(0, (1 - kn) * 40)
    rr(40, 1210, 1000, 210, 22); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
    texte('NOTE', 80, 1255, { taille: 22, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3 })
    texte('« Né rue Très-Cloîtres, fils de Louis Martin,', 80, 1305, { taille: 28, poids: 400, align: 'left', police: SERIF })
    texte('cordonnier, et de Jeanne Roux. »', 80, 1350, { taille: 28, poids: 400, align: 'left', police: SERIF })
    x.restore()
  }
  // joindre une pièce : l'acte, la photo, le livret arrivent à leur mot, puis filent dans la fiche
  const pieces = [['Acte', P.pActe], ['Photo', P.pPhoto], ['Livret de famille', P.pLivret]]
  pieces.forEach(([nom, quand], i) => {
    const ki = eback(prog(t, quand - 0.05, quand + 0.3), 2)
    if (ki <= 0) return
    const vole = eio(prog(t, P.joignez + 0.1 + i * 0.08, P.joignez + 0.55 + i * 0.08))
    if (vole >= 1) return
    const cx = lerp(200 + i * 340, 874, vole), cy = lerp(1600, 610, vole), sc = ki * lerp(1, 0.2, vole)
    x.save(); x.translate(cx, cy); x.scale(sc, sc)
    if (i === 0) { x.rotate(-0.08); rr(-60, -80, 120, 160, 6); x.fillStyle = COUL.papier; x.fill(); x.strokeStyle = COUL.encre; x.lineWidth = 2; for (let l = 0; l < 6; l++) { x.beginPath(); x.moveTo(-42, -50 + l * 20); x.lineTo(36, -50 + l * 20); x.stroke() } }
    if (i === 1) { x.rotate(0.06); rr(-70, -80, 140, 160, 6); x.fillStyle = '#f2efe8'; x.fill(); rr(-58, -68, 116, 100, 3); x.fillStyle = '#7c8a99'; x.fill(); silhouette(0, -12, 70, '#e8dcc8') }
    if (i === 2) { x.rotate(-0.04); rr(-62, -80, 124, 160, 8); x.fillStyle = '#3b5d8a'; x.fill(); texte('LIVRET', 0, -18, { taille: 20, poids: 800, coul: '#e8dcc8' }); texte('DE FAMILLE', 0, 10, { taille: 16, poids: 700, coul: '#e8dcc8' }) }
    x.restore()
    texte(nom, 200 + i * 340, 1720, { taille: 28, poids: 600, coul: COUL.attenue, alpha: clamp(ki) * (1 - vole * 3) })
  })
  doigt(874, 610, P.joignez)
  x.restore()
}

/* -------- pièce après pièce : l'arbre devient sûr -------- */
function arbreSur(t) {
  const k = eout(prog(t, P.arbre, P.arbre + 0.4))
  if (k <= 0) return
  const fiches = [[540, 1380, 'Camille Martin', 'f'], [290, 1130, 'Paul Martin', 'h'], [790, 1130, 'Hélène Dubois', 'f'],
    [165, 880, 'Jean Martin', 'h'], [415, 880, 'Simone Bernard', 'f'], [665, 880, 'René Dubois', 'h'], [915, 880, 'Odette Lefèvre', 'f']]
  x.save(); x.globalAlpha = k
  x.strokeStyle = '#4a5059'; x.lineWidth = 3
  const lien = (a, b, c) => { const ya = fiches[a][1] + 50, yb = fiches[c][1] - 55; x.beginPath(); x.moveTo(fiches[a][0], ya); x.lineTo(fiches[b][0], ya); x.moveTo((fiches[a][0] + fiches[b][0]) / 2, ya); x.lineTo((fiches[a][0] + fiches[b][0]) / 2, yb); x.lineTo(fiches[c][0], yb); x.lineTo(fiches[c][0], fiches[c][1] - 50); x.stroke() }
  lien(1, 2, 0); lien(3, 4, 1); lien(5, 6, 2)
  fiches.forEach(([cx, cy, nom, sexe], i) => {
    const ordre = [3, 4, 1, 5, 6, 2, 0].indexOf(i)
    const ok = prog(t, P.arbre + 0.6 + ordre * 0.25, P.arbre + 0.85 + ordre * 0.25)
    carteFiche(cx, cy, 236, 100, { nom, dates: '', sexe, branche: ok > 0.5 ? COUL.vert : COUL.rouge, vous: i === 0 })
    const kb = eback(ok, 2.5)
    if (kb > 0) { x.save(); x.translate(cx + 104, cy - 44); x.scale(kb, kb); x.beginPath(); x.arc(0, 0, 22, 0, Math.PI * 2); x.fillStyle = COUL.vert; x.fill(); x.strokeStyle = '#0f2a1c'; x.lineWidth = 5; x.lineCap = 'round'; x.beginPath(); x.moveTo(-9, 1); x.lineTo(-3, 8); x.lineTo(10, -7); x.stroke(); x.restore() }
  })
  x.restore()
}

SCENE = (t) => {
  fondGrille()
  scenesRegimes(t)
  ongletSources(t)
  arbreSur(t)
  for (let n = 0; n < 6; n++) legende(n)
  carton('Les sources et les actes', P.carton, P.fiche + 0.1, { numero: 'AIDE' })
  emblemeFinal(P.fin, { sousTitre: 'Les sources et les actes' })
}
lancer(SCENE, { affiche: P.tampon + 0.6 })
