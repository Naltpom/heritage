/* Chapitre « Notifications et calendrier » : la cloche, ce qui y arrive, l'alerte
   sur le téléphone, le calendrier des anniversaires, l'accueil du jour. */
minuter([1.9, 0.5, 0.6, 0.7, 0.7], [0.45, 0.6, 0.6, 0.6, 0.7], 2.0)

const P = {
  carton: 0.1, ecran: 1.3, sonne: M(0, 1) - 0.05, ouvre: V[0].fin + 0.1,
  n1: M(1, 1), n2: M(1, 4), n3: M(1, 7),
  recevoir: M(2, 2) - 0.05, verrou: M(2, 7) - 0.2, push: M(2, 9),
  calendrier: V[3].debut - 0.6, naissances: M(3, 4), unions: M(3, 6), memoire: M(3, 9),
  accueil: V[4].debut - 0.6, jour: M(4, 7),
  fin: V[4].fin + 0.7,
}
son(P.ecran, 'souffle', 0.5); son(P.sonne, 'notif'); son(P.ouvre, 'tape')
son(P.n1, 'pop'); son(P.n2, 'pop'); son(P.n3, 'pop'); son(P.recevoir, 'tape'); son(P.verrou, 'souffle', 0.5); son(P.push, 'notif')
son(P.calendrier, 'souffle', 0.5); son(P.naissances, 'note1'); son(P.unions, 'note2'); son(P.memoire, 'note3')
son(P.accueil, 'souffle', 0.5); son(P.jour, 'pop'); son(P.fin, 'final')

const BLEU = '#6ea8e8'
/* -------- l'en-tête de l'application et la cloche -------- */
function application(t) {
  const k = eout(prog(t, P.ecran, P.ecran + 0.4)), s = eio(prog(t, P.verrou, P.verrou + 0.3))
  if (k <= 0 || s >= 1) return
  x.save(); x.globalAlpha = k * (1 - s)
  const sonne = t > P.sonne ? prog(t, P.sonne, P.sonne + 1.0) : 0
  enTete(500, { cloche: sonne > 0 && sonne < 1 ? sonne : 0, badge: t > P.sonne ? '3' : null })
  // l'accueil en fond, discret
  x.globalAlpha *= 0.5
  texte('Bonjour, Camille', 60, 700, { taille: 50, poids: 700, align: 'left' })
  bouton('Arbre', 290, 820, 460, 96, { taille: 32 }); bouton('Modifier l’arbre', 790, 820, 460, 96, { variante: 'contourAccent', taille: 32 })
  rr(60, 900, 960, 300, 22); x.fillStyle = COUL.panneau; x.fill()
  x.restore()
  doigt(900, 560, P.ouvre)
  // le panneau des notifications
  const kp = eback(prog(t, P.ouvre + 0.05, P.ouvre + 0.4), 1.2)
  if (kp <= 0) return
  x.save(); x.globalAlpha = clamp(kp * 2) * (1 - s)
  x.translate(0, (1 - clamp(kp)) * -40)
  rr(30, 640, 1020, 1060, 28); x.shadowColor = 'rgba(0,0,0,.6)'; x.shadowBlur = 50; x.fillStyle = COUL.panneau; x.fill(); x.shadowBlur = 0; x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
  texte('Notifications', 80, 710, { taille: 40, poids: 700, align: 'left' })
  texte('Tout marquer comme lu', 1000, 710, { taille: 28, poids: 700, coul: COUL.accent, align: 'right' })
  const items = [
    [P.n1, 'Demande acceptée', COUL.vert, 'Votre demande sur Simone Bernard', 'a été validée.'],
    [P.n2, 'Média accepté', COUL.vert, 'La photo de Léo Martin', 'a rejoint sa fiche.'],
    [P.n3, 'Tirage au sort', COUL.accent, 'Noël cousins Martin :', 'votre tirage est fait.'],
  ]
  items.forEach(([quand, type, c, l1, l2], i) => {
    const ki = eout(prog(t, quand - 0.1, quand + 0.25))
    if (ki <= 0) return
    const y = 770 + i * 200 + (1 - ki) * -30
    x.save(); x.globalAlpha *= ki
    rr(60, y, 960, 180, 18); x.fillStyle = '#26221d'; x.fill()
    x.beginPath(); x.arc(95, y + 40, 9, 0, Math.PI * 2); x.fillStyle = COUL.accent; x.fill()
    texte(type, 120, y + 42, { taille: 26, poids: 700, coul: c, align: 'left' })
    texte('à l’instant', 990, y + 42, { taille: 22, poids: 500, coul: COUL.attenue, align: 'right' })
    texte(l1, 120, y + 95, { taille: 32, poids: 700, align: 'left' }); texte(l2, 120, y + 140, { taille: 32, poids: 700, align: 'left' })
    x.restore()
  })
  // « Recevoir aussi sur ce téléphone »
  const kr = eout(prog(t, V[2].debut - 0.3, V[2].debut))
  if (kr > 0) { x.save(); x.globalAlpha *= kr; bouton('Recevoir aussi sur ce téléphone', 540, 1520, 900, 100, { variante: 'contourAccent', taille: 32, presse: pression(P.recevoir) }); x.restore() }
  x.restore()
  doigt(700, 1520, P.recevoir)
}

/* -------- l'écran verrouillé : l'alerte arrive quand même -------- */
function verrouille(t) {
  const k = eout(prog(t, P.verrou, P.verrou + 0.35)), s = eio(prog(t, P.calendrier - 0.1, P.calendrier + 0.25))
  if (k <= 0 || s >= 1) return
  x.save(); x.globalAlpha = k * (1 - s)
  const g = x.createLinearGradient(0, 500, 0, H); g.addColorStop(0, '#1b2433'); g.addColorStop(1, '#0d1118'); x.fillStyle = g; x.fillRect(0, 500, W, H - 500)
  texte('09:41', 540, 760, { taille: 170, poids: 300, coul: '#e9eef5' })
  texte('vendredi 2 octobre', 540, 870, { taille: 40, poids: 500, coul: '#c3cad4' })
  const kp = eback(prog(t, P.push, P.push + 0.35), 1.4)
  if (kp > 0) {
    x.save(); x.translate(0, (1 - clamp(kp)) * -120); x.globalAlpha *= clamp(kp * 2)
    rr(50, 1010, 980, 200, 36); x.fillStyle = 'rgba(232,236,242,.16)'; x.fill()
    rr(85, 1045, 64, 64, 14); x.fillStyle = COUL.fond; x.fill()
    x.save(); x.translate(117, 1077); x.strokeStyle = COUL.accent; x.lineWidth = 5; x.lineCap = 'round'
    x.beginPath(); x.moveTo(0, 20); x.lineTo(0, 0); x.moveTo(0, 6); x.lineTo(-12, -8); x.moveTo(0, 6); x.lineTo(12, -8); x.moveTo(0, 0); x.lineTo(0, -16); x.stroke(); x.restore()
    texte('HERITAGE', 175, 1065, { taille: 26, poids: 700, coul: '#c3cad4', align: 'left', espacement: 2 })
    texte('maintenant', 990, 1065, { taille: 24, poids: 500, coul: '#9aa3ad', align: 'right' })
    texte('Demande acceptée', 175, 1115, { taille: 34, poids: 700, coul: '#ffffff', align: 'left' })
    texte('Votre demande sur Simone Bernard a été validée.', 175, 1162, { taille: 28, poids: 400, coul: '#dfe4ea', align: 'left' })
    x.restore()
  }
  x.restore()
}

/* -------- le calendrier -------- */
const JOURS = [] // octobre : le 1er est un jeudi
for (let i = 0; i < 35; i++) JOURS.push(i - 3 + 1)
const EVENEMENTS = { 2: [['Léo Martin', 'n'], ['Jean Martin', 'd']], 5: [['Emma Martin', 'n']], 8: [['Paul & Hélène', 'u']], 12: [['Simone Bernard', 'd']], 14: [['Lucas Martin', 'n']], 19: [['Odette Lefèvre', 'n']], 21: [['René & Odette', 'u']], 24: [['Auguste Bernard', 'd']], 27: [['Jade Martin', 'n']], 30: [['Rose Faure', 'd']] }
const COUL_EV = { n: BLEU, u: COUL.accent, d: '#b7a6d9' }
function calendrier(t) {
  const k = eout(prog(t, P.calendrier, P.calendrier + 0.4)), s = eio(prog(t, P.accueil - 0.1, P.accueil + 0.25))
  if (k <= 0 || s >= 1) return
  x.save(); x.globalAlpha = k * (1 - s); x.translate(0, (1 - k) * 80)
  texte('Calendrier', 60, 560, { taille: 50, poids: 700, align: 'left' })
  texte('Octobre 2026', 1020, 560, { taille: 34, poids: 600, coul: COUL.attenue, align: 'right' })
  const allume = { n: prog(t, P.naissances - 0.1, P.naissances + 0.15), u: prog(t, P.unions - 0.1, P.unions + 0.15), d: prog(t, P.memoire - 0.1, P.memoire + 0.15) }
  ;[['Naissances', 'n'], ['Unions', 'u'], ['Décès', 'd']].forEach(([nom, c], i) => {
    const cx = 160 + i * 270, on = allume[c]
    rr(cx - 120, 610, 240, 64, 32); x.fillStyle = on > 0 ? avecAlpha(COUL_EV[c], 0.22 * on) : '#111214'; x.fill(); x.strokeStyle = on > 0.5 ? COUL_EV[c] : COUL.ligne; x.lineWidth = 3; x.stroke()
    texte(nom, cx, 643, { taille: 28, poids: 700, coul: on > 0.5 ? COUL_EV[c] : COUL.attenue })
  })
  ;['L', 'M', 'M', 'J', 'V', 'S', 'D'].forEach((j, i) => texte(j, 85 + i * 152, 730, { taille: 26, poids: 700, coul: COUL.attenue }))
  JOURS.forEach((j, i) => {
    const cx = 85 + (i % 7) * 152, cy = 760 + Math.floor(i / 7) * 190
    const dansLeMois = j >= 1 && j <= 31
    rr(cx - 72, cy, 144, 178, 12); x.fillStyle = dansLeMois ? COUL.panneau : '#16171a'; x.fill()
    if (j === 2) { x.strokeStyle = COUL.accent; x.lineWidth = 4; x.stroke() }
    if (!dansLeMois) return
    texte(String(j), cx - 58, cy + 26, { taille: 24, poids: 700, align: 'left', coul: j === 2 ? COUL.accent : COUL.texte })
    ;(EVENEMENTS[j] || []).forEach(([nom, c], e) => {
      const on = allume[c]
      if (on <= 0) return
      const ey = cy + 66 + e * 50
      x.save(); x.globalAlpha *= eout(on)
      rr(cx - 66, ey - 20, 132, 40, 8); x.fillStyle = avecAlpha(COUL_EV[c], 0.25); x.fill()
      x.fillStyle = COUL_EV[c]; x.fillRect(cx - 66, ey - 20, 5, 40)
      texte(nom.split(' ')[0], cx - 54, ey + 1, { taille: 25, poids: 700, align: 'left' })
      x.restore()
    })
  })
  x.restore()
}

/* -------- l'accueil du jour -------- */
function accueil(t) {
  const k = eout(prog(t, P.accueil, P.accueil + 0.4))
  if (k <= 0) return
  x.save(); x.globalAlpha = k; x.translate(0, (1 - k) * 80)
  texte('Bonjour, Camille', 60, 570, { taille: 54, poids: 700, align: 'left' })
  texte('Aujourd’hui, 2 octobre', 60, 630, { taille: 32, poids: 400, coul: COUL.attenue, align: 'left' })
  rr(40, 690, 1000, 980, 26); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
  texte('Anniversaires · Aujourd’hui', 540, 750, { taille: 34, poids: 700 })
  ;['Mer', 'Jeu', 'Ven', 'Sam', 'Dim'].forEach((j, i) => {
    const cx = 220 + i * 160
    const kj = i === 2 ? eback(prog(t, P.jour - 0.1, P.jour + 0.25), 2) : 1
    const on = i === 2 && kj > 0.5
    if (i === 2 && kj > 0) { x.save(); x.translate(cx, 860); x.scale(kj, kj); rr(-66, -62, 132, 124, 18); x.fillStyle = COUL.accent; x.fill(); x.restore() }
    texte(j, cx, 830, { taille: 26, poids: 500, coul: on ? '#1a1410' : COUL.attenue })
    texte(String([30, 1, 2, 3, 4][i]), cx, 872, { taille: 36, poids: 700, coul: on ? '#1a1410' : COUL.texte })
    x.beginPath(); x.arc(cx, 905, 5, 0, Math.PI * 2); x.fillStyle = on ? '#1a1410' : COUL.accent; x.fill()
  })
  x.fillStyle = COUL.ligne; x.fillRect(40, 950, 1000, 2)
  const kl = eout(prog(t, P.jour, P.jour + 0.4))
  x.save(); x.globalAlpha *= kl
  texte('NAISSANCE', 80, 1010, { taille: 22, poids: 700, coul: BLEU, align: 'left', espacement: 2 })
  texte('Léo Martin', 80, 1060, { taille: 38, poids: 700, coul: COUL.accent, align: 'left' })
  texte('2 octobre 2018 · 8 ans aujourd’hui', 80, 1110, { taille: 30, poids: 400, coul: COUL.attenue, align: 'left' })
  texte('EN MÉMOIRE', 80, 1210, { taille: 22, poids: 700, coul: '#b7a6d9', align: 'left', espacement: 2 })
  texte('Jean Martin', 80, 1260, { taille: 38, poids: 700, coul: COUL.accent, align: 'left' })
  texte('† 2 octobre 2006 · il y a 20 ans', 80, 1310, { taille: 30, poids: 400, coul: COUL.attenue, align: 'left' })
  x.restore()
  x.restore()
}

SCENE = (t) => {
  fondGrille()
  application(t)
  verrouille(t)
  calendrier(t)
  accueil(t)
  for (let n = 0; n < 5; n++) legende(n)
  carton('Notifications et calendrier', P.carton, P.ecran + 0.1, { numero: 'AIDE' })
  emblemeFinal(P.fin, { sousTitre: 'Notifications et calendrier' })
}
lancer(SCENE, { affiche: P.n3 + 0.6 })
