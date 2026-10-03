/* « Un mot de Nathan » : Nathan se présente à la famille (par le sang ou par
   alliance), puis fait le tour complet de l'application — l'arbre, la fiche,
   l'assistant et la vérification, les questions, la carte et la chronologie,
   le calendrier, la cloche, les événements, le tirage au sort, les actes
   demandés, la confidentialité, l'installation. Une phrase, un tableau. */
minuter(
  [1.0, 0.45, 0.6, 0.55, 0.5, 0.6, 0.6, 0.45, 0.6, 0.5, 0.6, 0.6, 0.6, 0.6, 0.5, 0.6, 0.6, 0.6, 0.6, 0.6, 0.7],
  [0.4, 0.45, 0.45, 0.5, 0.45, 0.45, 0.4, 0.5, 0.45, 0.5, 0.45, 0.45, 0.45, 0.4, 0.5, 0.45, 0.45, 0.45, 0.45, 0.5, 0.6],
  2.6,
)

const VIOLET_CADEAU = '#b78cf0'
const BLEU = '#6ea8e8'
/** L'instant où le tableau de la phrase `n` commence à entrer. */
const S = (n) => V[n].debut - 0.45

const P = {
  // 0–1 : bonjour
  avatar: 0.15, nom: M(0, 3), mariage: M(1, 6), repas: M(1, 9), jamais: M(1, 13),
  // 2–4 : l'arbre
  arbre: S(2), sang: M(2, 8), alliance: M(2, 11), enchante: M(2, 13),
  ensemble: S(3) + 0.1, compteur: M(3, 10) - 0.3,
  fiches: S(4) + 0.1, centre: M(4, 4), branches: M(4, 10),
  // 5 : la fiche
  fiche: S(5), touche: M(5, 0) - 0.05, dates: M(5, 4), proches: M(5, 6), photos: M(5, 8), origine: M(5, 10),
  // 6–7 : l'assistant
  assistant: S(6), frappe: M(6, 4), photo: M(6, 11) - 0.1,
  correction: M(7, 3), verification: M(7, 8), acceptee: V[7].fin - 0.1,
  // 8–9 : les questions
  question: S(8), reponse: M(8, 5), siennes: S(9), repondre: M(9, 9),
  // 10 : carte et chronologie
  carte: S(10), chrono: M(10, 9) - 0.35,
  // 11 : le calendrier
  calendrier: S(11), memoire: M(11, 8),
  // 12 : la cloche
  cloche: S(12), push1: M(12, 3), push2: M(12, 9), push3: M(12, 11),
  // 13–14 : les événements
  retrouver: S(13), cousinade: M(13, 7), surprise: M(13, 9), hommage: M(13, 12),
  suivi: S(14), relance: M(14, 11) - 0.05,
  // 15 : le tirage
  tirage: S(15), revele: M(15, 9) - 0.05,
  // 16 : les actes demandés
  demarches: S(16),
  // 17 : la confidentialité
  prive: S(17), cadenas: M(17, 4), vivants: M(17, 10),
  // 18 : l'installation
  installer: S(18), icone: M(18, 4),
  // 19–20 : la fin
  venez: S(19), fin: S(20) + 0.25,
}

/* -------- les bruitages -------- */
son(P.avatar, 'eclosion'); son(P.nom, 'pop'); son(P.mariage, 'note1'); son(P.repas, 'note2'); son(P.jamais, 'note3')
son(P.arbre, 'souffle', 0.6); son(P.sang, 'note2'); son(P.alliance, 'note4'); son(P.enchante, 'succes')
son(P.ensemble, 'recul'); son(P.compteur, 'impact', 0.6); son(P.fiches, 'souffle', 0.5); son(P.centre, 'pop')
for (let q = 0; q < 4; q++) son(P.branches + q * 0.12, `note${q + 1}`)
son(P.touche, 'tape'); son(P.touche + 0.1, 'pop'); son(P.photos, 'note2'); son(P.origine, 'tampon', 0.5)
son(P.assistant, 'souffle', 0.5); sonsDeFrappe('Jean est né à Brest, pas à Lyon.', P.frappe, 22); son(P.photo, 'tape'); son(P.photo + 0.15, 'clic')
son(P.correction, 'pop'); son(P.verification, 'note3'); son(P.acceptee, 'succes')
son(P.question, 'souffle', 0.5); son(P.reponse, 'pop'); son(P.siennes, 'notif'); son(P.repondre, 'tape')
son(P.carte, 'souffle', 0.5); son(P.carte + 0.6, 'note1'); son(P.chrono, 'recul', 0.6)
son(P.calendrier, 'souffle', 0.5); son(P.memoire, 'note4')
son(P.cloche, 'souffle', 0.5); son(P.push1, 'notif'); son(P.push2, 'notif', 0.7); son(P.push3, 'notif', 0.6)
son(P.retrouver, 'souffle', 0.5); son(P.cousinade, 'note1'); son(P.surprise, 'note2'); son(P.hommage, 'note3')
son(P.suivi, 'souffle', 0.5); son(P.relance, 'tape'); son(P.relance + 0.15, 'notif')
son(P.tirage, 'souffle', 0.5); son(P.revele, 'tampon', 0.5); son(P.revele + 0.1, 'succes')
son(P.demarches, 'souffle', 0.5)
son(P.prive, 'souffle', 0.5); son(P.cadenas, 'tampon', 0.7); son(P.vivants, 'note2')
son(P.installer, 'souffle', 0.5); son(P.icone, 'pop'); son(P.icone + 0.6, 'tape')
son(P.venez, 'eclosion'); son(P.fin, 'final')

/* -------- outils de mise en scène -------- */
/** Un tableau visible de `debut` à `fin` : il monte en entrant, glisse vers le haut en sortant. */
function phase(t, debut, fin) {
  const k = eout(prog(t, debut, debut + 0.4)), s = eio(prog(t, fin - 0.15, fin + 0.25))
  return { a: k * (1 - s), dy: (1 - k) * 90 - s * 120 }
}
function titreEcran(s, sous) {
  texte(s, 60, 580, { taille: 56, poids: 700, align: 'left' })
  if (sous) texte(sous, 60, 638, { taille: 30, poids: 400, coul: COUL.attenue, align: 'left' })
}
/** Une carte de panneau, lisere facultatif à gauche. */
function panneau(px, py, w, h, { lisere = null, bord = COUL.ligne, epais = 2, r = 22 } = {}) {
  rr(px, py, w, h, r); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = bord; x.lineWidth = epais; x.stroke()
  if (lisere) { x.save(); rr(px, py, w, h, r); x.clip(); x.fillStyle = lisere; x.fillRect(px, py, 10, h); x.restore() }
}
/** Un texte qui revient à la ligne dans `largeur` ; rend la hauteur occupée. */
function paragraphe(s, px, py, largeur, { taille = 32, poids = 400, coul = COUL.texte, interligne = 1.35 } = {}) {
  const mots = s.split(' '), lignesTexte = ['']
  for (const m of mots) {
    const essai = lignesTexte[lignesTexte.length - 1] ? `${lignesTexte[lignesTexte.length - 1]} ${m}` : m
    if (largeurTexte(essai, taille, poids) > largeur && lignesTexte[lignesTexte.length - 1]) lignesTexte.push(m)
    else lignesTexte[lignesTexte.length - 1] = essai
  }
  lignesTexte.forEach((l, i) => texte(l, px, py + i * taille * interligne, { taille, poids, coul, align: 'left' }))
  return lignesTexte.length * taille * interligne
}
/** Une photo ancienne : un cadre sépia et une silhouette. */
function photoAncienne(cx, cy, w, h, { rot = 0, alpha = 1, sexe = 'h' } = {}) {
  x.save(); x.globalAlpha *= alpha; x.translate(cx, cy); x.rotate(rot)
  x.shadowColor = 'rgba(0,0,0,.45)'; x.shadowBlur = 18
  rr(-w / 2, -h / 2, w, h, 6); x.fillStyle = COUL.papier; x.fill(); x.shadowBlur = 0
  rr(-w / 2 + 10, -h / 2 + 10, w - 20, h - 34, 3); x.fillStyle = '#b9a27a'; x.fill()
  silhouette(0, -6, Math.min(w, h) * 0.42, sexe === 'f' ? '#7d6448' : '#6b5638')
  x.restore()
}
/** Un acte manuscrit, vu de face. */
function acte(cx, cy, w, h, { rot = 0, alpha = 1 } = {}) {
  x.save(); x.globalAlpha *= alpha; x.translate(cx, cy); x.rotate(rot)
  x.shadowColor = 'rgba(0,0,0,.5)'; x.shadowBlur = 24
  rr(-w / 2, -h / 2, w, h, 8); x.fillStyle = COUL.papier; x.fill(); x.shadowBlur = 0
  // le titre et les lignes se resserrent avec la feuille : un petit acte reste lisible
  const e = Math.min(1, w / 400)
  if (w >= 300) texte('Acte de naissance', 0, -h / 2 + 48, { taille: 30, poids: 700, police: SERIF, coul: COUL.encre })
  else { x.fillStyle = avecAlpha(COUL.encre, 0.8); x.fillRect(-w * 0.3, -h / 2 + 22 * e + 8, w * 0.6, 7) }
  const r = rnd(5), haut = w >= 300 ? 100 : 40 * e + 20
  for (let i = 0; i < 9; i++) {
    const ly = -h / 2 + haut + i * 34 * e, lw = (w - 80 * e) * (0.6 + r() * 0.4)
    if (ly > h / 2 - 14) break
    x.strokeStyle = avecAlpha(COUL.encre, 0.55); x.lineWidth = Math.max(2, 3 * e); x.beginPath()
    for (let k = 0; k <= 20; k++) { const px = -w / 2 + 40 * e + lw * k / 20; const py = ly + Math.sin(k * 1.7 + i) * 3; k ? x.lineTo(px, py) : x.moveTo(px, py) }
    x.stroke()
  }
  x.restore()
}
/** Une bulle de conversation. */
function bulle(s, px, py, largeur, { moi = false, alpha = 1, taille = 32 } = {}) {
  x.save(); x.globalAlpha *= alpha
  x.save(); x.font = `500 ${taille}px ${SANS}`; const w = Math.min(largeur, x.measureText(s).width + 60); x.restore()
  const mots = s.split(' '), ls = ['']
  for (const m of mots) { const e = ls[ls.length - 1] ? `${ls[ls.length - 1]} ${m}` : m; if (largeurTexte(e, taille, 500) > largeur - 60 && ls[ls.length - 1]) ls.push(m); else ls[ls.length - 1] = e }
  const h = ls.length * taille * 1.35 + 44
  const bx = moi ? 1020 - w : 60
  rr(bx, py, w, h, 28); x.fillStyle = moi ? COUL.fonceAccent : COUL.panneau; x.fill(); x.strokeStyle = moi ? avecAlpha(COUL.accent, 0.7) : COUL.ligne; x.lineWidth = 2; x.stroke()
  ls.forEach((l, i) => texte(l, bx + 30, py + 22 + taille * 0.68 + i * taille * 1.35, { taille, poids: 500, align: 'left' }))
  x.restore()
  return h
}

/* -------- 0–1. Bonjour, moi c'est Nathan -------- */
function bonjour(t) {
  const { a, dy } = phase(t, P.avatar, P.arbre)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  const ka = eback(prog(t, P.avatar, P.avatar + 0.6), 1.6)
  // l'avatar, au centre, avec un halo qui respire
  x.save(); x.translate(540, 980); x.scale(ka, ka)
  const halo = 1 + 0.04 * Math.sin(t * 2.4)
  x.beginPath(); x.arc(0, 0, 230 * halo, 0, Math.PI * 2); x.fillStyle = avecAlpha(COUL.accent, 0.12); x.fill()
  x.beginPath(); x.arc(0, 0, 190, 0, Math.PI * 2); x.fillStyle = '#2c3038'; x.fill(); x.strokeStyle = COUL.accent; x.lineWidth = 8; x.stroke()
  x.save(); x.beginPath(); x.arc(0, 0, 186, 0, Math.PI * 2); x.clip(); silhouette(0, 40, 300, '#596069'); x.restore()
  x.restore()
  // la main qui salue
  const salut = prog(t, P.avatar + 0.5, P.avatar + 2.2)
  if (salut > 0 && salut < 1) {
    x.save(); x.translate(760, 790); x.rotate(Math.sin(salut * Math.PI * 6) * 0.35 * (1 - salut)); x.globalAlpha *= Math.sin(salut * Math.PI)
    x.fillStyle = COUL.accent; rr(-34, -40, 68, 80, 26); x.fill()
    for (let d = 0; d < 4; d++) { rr(-34 + d * 18, -92, 14, 60, 7); x.fill() }
    x.restore()
  }
  // le nom
  const kn = eout(prog(t, P.nom - 0.1, P.nom + 0.35))
  if (kn > 0) {
    x.save(); x.translate(540, 1330); const sc = lerp(1.25, 1, kn); x.scale(sc, sc)
    texte('Nathan', 0, 0, { taille: 120, police: SERIF, poids: 600, alpha: kn })
    x.restore()
    x.fillStyle = COUL.accent; x.fillRect(540 - 140 * kn, 1410, 280 * kn, 6)
  }
  // où l'on s'est peut-être croisés
  const souvenirs = [
    [P.mariage, 230, 1600, 'Un mariage', (cx, cy) => { x.strokeStyle = COUL.accent; x.lineWidth = 7; x.beginPath(); x.arc(cx - 16, cy, 26, 0, Math.PI * 2); x.stroke(); x.beginPath(); x.arc(cx + 16, cy, 26, 0, Math.PI * 2); x.stroke() }],
    [P.repas, 540, 1640, 'Un repas de famille', (cx, cy) => { x.strokeStyle = COUL.accent; x.lineWidth = 6; x.beginPath(); x.arc(cx, cy, 30, 0, Math.PI * 2); x.stroke(); x.beginPath(); x.arc(cx, cy, 16, 0, Math.PI * 2); x.stroke(); x.beginPath(); x.moveTo(cx - 50, cy - 28); x.lineTo(cx - 50, cy + 30); x.moveTo(cx + 50, cy - 28); x.lineTo(cx + 50, cy + 30); x.stroke() }],
    [P.jamais, 850, 1600, 'Jamais ?', (cx, cy) => texte('?', cx, cy + 4, { taille: 72, poids: 800, coul: COUL.accent })],
  ]
  for (const [quand, cx, cy, libelle, icone] of souvenirs) {
    const k = eback(prog(t, quand - 0.15, quand + 0.3), 2.2)
    if (k <= 0) continue
    x.save(); x.translate(cx, cy + Math.sin(t * 2 + cx) * 6); x.scale(k, k)
    rr(-150, -80, 300, 160, 30); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
    icone(0, -22)
    texte(libelle, 0, 50, { taille: 28, poids: 600 })
    x.restore()
  }
  x.restore()
}

/* -------- 2–4. L'arbre : par le sang, par alliance ; 1 800 personnes ; vous au centre -------- */
const A = arbreFictif()
const LIGNE = ligneDirecteDe(A, A.vous)
const dist = (n) => Math.hypot(n.cx - A.vous.cx, (n.cy - A.vous.cy) * 1.3)
const LOIN = Math.max(...A.N.map(dist))
/** Les familles par alliance : les conjoints de la fratrie et leurs parents, en violet. */
const ALLIES = [
  { nom: 'Claire Petit', dates: '° 1990', sexe: 'f', cx: 188, cy: 1530, lien: 'lucas' },
  { nom: 'Karim Haddad', dates: '° 1987', sexe: 'h', cx: 892, cy: 1530, lien: 'emma' },
]
function camera(t) {
  return trajet(t, { s: 1, fx: 540, fy: 1100 }, [
    { a: P.ensemble, b: P.ensemble + 0.8, s: 0.56, fy: 720 },
    { a: P.fiches, b: P.fiches + 0.7, s: 1, fy: 1100 },
    { a: P.fiche, b: P.fiche + 0.6, s: 1.3, fx: 380, fy: 1000 },
  ])
}
function arbre(t) {
  const { a } = phase(t, P.arbre, P.assistant)
  if (a <= 0) return
  const cam = camera(t)
  const ens = eio(prog(t, P.ensemble, P.ensemble + 0.5)) * (1 - eio(prog(t, P.fiches, P.fiches + 0.5)))
  const sang = eout(prog(t, P.sang - 0.2, P.sang + 0.2)) * (1 - eout(prog(t, P.alliance, P.alliance + 0.4)))
  const estompe = (n) => (LIGNE.has(n) ? 0 : sang * 0.8)
  const voile = eio(prog(t, P.fiche, P.fiche + 0.4)) // le volet de la fiche passe devant
  x.save(); x.globalAlpha = a * (1 - 0.55 * voile)
  x.beginPath(); x.rect(0, 500, W, H - 500); x.clip(); filmer(cam)
  blocsLointains(A, ens)
  traitsDe(A, { k: eout(prog(t, P.arbre + 0.45, P.arbre + 1.0)), alpha: 1 - ens * 0.6, estompe })
  // les alliances : un trait pointillé violet vers le conjoint
  const kal = eout(prog(t, P.alliance - 0.15, P.alliance + 0.3)) * (1 - ens)
  if (kal > 0) {
    for (const al of ALLIES) {
      const n = A.N.find((m) => m.id === al.lien)
      x.save(); x.globalAlpha *= kal; x.setLineDash([12, 10]); x.strokeStyle = COUL.violet; x.lineWidth = 4
      x.beginPath(); x.moveTo(n.cx, n.cy + n.h / 2); x.lineTo(al.cx, al.cy - 50); x.stroke(); x.restore()
      carteFiche(al.cx, al.cy, 280, 100, { nom: al.nom, dates: al.dates, sexe: al.sexe, branche: COUL.violet, alpha: kal, echelle: eback(clamp(kal), 2), surligne: 1 - prog(t, P.alliance + 0.4, P.enchante + 0.4) })
    }
  }
  for (const n of A.N) {
    if (n.enfant && kal > 0) continue // les alliés prennent la place des enfants
    const d = P.arbre + 0.2 + 0.8 * Math.pow(dist(n) / LOIN, 0.8)
    const k = prog(t, d, d + 0.3)
    if (k <= 0) continue
    let s = 0
    if (n.vous) s = eout(prog(t, P.centre - 0.1, P.centre + 0.2))
    if (LIGNE.has(n) && sang > 0 && !n.vous) s = Math.max(s, 0.7 * sang)
    if (n === A.parSosa(5)) s = Math.max(s, eout(prog(t, P.touche, P.touche + 0.2)))
    const couleurs = n.q ? Math.max(1 - prog(t, P.fiches, P.fiches + 0.2), eout(prog(t, P.branches + 'ABCD'.indexOf(n.q) * 0.12, P.branches + 'ABCD'.indexOf(n.q) * 0.12 + 0.25))) : 1
    ficheDe(n, { ens, alpha: clamp(k * 3), echelle: eback(k, 2.2), couleurs, surligne: s, estompe: estompe(n) })
  }
  x.restore()
  // le compteur de la vue « Ensemble »
  const kc = eout(prog(t, P.compteur - 0.5, P.compteur + 0.2)) * (1 - eio(prog(t, P.fiches - 0.2, P.fiches + 0.2)))
  if (kc > 0) {
    const n = Math.round(1800 * eout(prog(t, P.compteur - 0.5, P.compteur + 0.4)))
    x.save(); x.globalAlpha = kc * a
    rr(140, 1380, 800, 260, 36); x.fillStyle = COUL.fond; x.fill(); x.strokeStyle = COUL.accent; x.lineWidth = 3; x.stroke()
    texte(`${n.toLocaleString('fr-FR')}${n >= 1800 ? ' +' : ''}`, 540, 1480, { taille: 120, police: SERIF, poids: 600, coul: COUL.accent })
    texte('personnes dans l’arbre', 540, 1578, { taille: 38, poids: 600 })
    x.restore()
  }
  // la bande des branches
  for (let q = 0; q < 4; q++) {
    const k = eout(prog(t, P.branches + q * 0.12, P.branches + q * 0.12 + 0.25)) * (1 - eio(prog(t, P.fiche, P.fiche + 0.3)))
    if (k <= 0) continue
    const x0 = 40 + q * 250
    x.save(); x.globalAlpha = a * k
    x.fillStyle = COUL['ABCD'[q]]; x.fillRect(x0 + 6, 520, 238 * k, 6)
    texte(BRANCHES[q].nom, x0 + 125, 560, { taille: 32, poids: 800, coul: COUL['ABCD'[q]] })
    texte(BRANCHES[q].role, x0 + 125, 596, { taille: 26, poids: 500, coul: COUL.attenue })
    x.restore()
  }
  // « vous êtes ici »
  const kv = eout(prog(t, P.centre - 0.05, P.centre + 0.3)) * (1 - prog(t, P.fiche - 0.2, P.fiche + 0.1))
  if (kv > 0) {
    const [vx, vy] = versEcran(cam, A.vous.cx, A.vous.cy)
    x.save(); x.globalAlpha = kv * a; x.translate(vx, vy - 110 - 8 * Math.sin(t * 5))
    pastille('Vous', 0, 0, { taille: 30 })
    x.restore()
  }
  voileBas()
}

/* -------- 5. La fiche : dates, proches, photos, d'où vient l'information -------- */
function fiche(t) {
  const k = eback(prog(t, P.touche + 0.1, P.touche + 0.5), 1.2), s = eio(prog(t, P.assistant - 0.15, P.assistant + 0.25))
  if (k <= 0 || s >= 1) return
  const [sx, sy] = versEcran(camera(P.touche), A.parSosa(5).cx, A.parSosa(5).cy)
  doigt(sx, sy, P.touche)
  const y0 = H - 1180 * k + s * 300
  x.save(); x.globalAlpha = 1 - s
  x.shadowColor = 'rgba(0,0,0,.6)'; x.shadowBlur = 50
  rr(20, y0, 1040, 1300, 34); x.fillStyle = COUL.panneau; x.fill(); x.shadowBlur = 0
  x.fillStyle = COUL.B; x.fillRect(20, y0, 1040, 7)
  x.fillStyle = COUL.ligne; rr(490, y0 + 22, 100, 10, 5); x.fill()
  texte('Simone Bernard', 70, y0 + 100, { taille: 54, poids: 700, align: 'left' })
  const kd = eout(prog(t, P.dates - 0.15, P.dates + 0.25))
  texte('° 3 mai 1935, Lille   † 18 janv. 2019, Paris', 70, y0 + 160, { taille: 30, poids: 500, align: 'left', coul: COUL.attenue, police: MONO, alpha: kd })
  // les proches
  const kp = eout(prog(t, P.proches - 0.15, P.proches + 0.25))
  x.save(); x.globalAlpha *= kp
  texte('PROCHES', 70, y0 + 240, { taille: 26, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3 })
  ;[['Auguste Bernard', 'père'], ['Rose Faure', 'mère'], ['Jean Martin', 'époux'], ['Paul Martin', 'fils']].forEach(([nom, role], i) => {
    texte(nom, 70, y0 + 295 + i * 52, { taille: 32, poids: 600, coul: COUL.accent, align: 'left' })
    texte(role, 1000, y0 + 295 + i * 52, { taille: 26, poids: 500, coul: COUL.attenue, align: 'right' })
  })
  x.restore()
  // les photos
  const kph = prog(t, P.photos - 0.15, P.photos + 0.5)
  if (kph > 0) {
    texte('PHOTOS', 70, y0 + 540, { taille: 26, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3, alpha: clamp(kph * 3) })
    ;[[220, -0.05, 'f'], [520, 0.04, 'h'], [820, -0.03, 'f']].forEach(([cx, rot, sexe], i) => {
      const kk = eback(prog(t, P.photos - 0.1 + i * 0.12, P.photos + 0.25 + i * 0.12), 2)
      if (kk > 0) { x.save(); x.translate(cx, y0 + 690); x.scale(kk, kk); photoAncienne(0, 0, 250, 200, { rot, sexe }); x.restore() }
    })
  }
  // d'où vient l'information
  const ko = eout(prog(t, P.origine - 0.15, P.origine + 0.25))
  if (ko > 0) {
    x.save(); x.globalAlpha *= ko
    texte('SOURCES', 70, y0 + 850, { taille: 26, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3 })
    rr(60, y0 + 885, 960, 120, 18); x.fillStyle = COUL.panneau2; x.fill()
    texte('Acte de naissance', 95, y0 + 930, { taille: 32, poids: 700, align: 'left' })
    texte('Archives du Nord · registre 1935', 95, y0 + 974, { taille: 26, poids: 400, coul: COUL.attenue, align: 'left' })
    const kt = eback(prog(t, P.origine, P.origine + 0.3), 2.5)
    x.save(); x.translate(860, y0 + 945); x.scale(kt, kt); pastille('Vérifié', 0, 0, { ton: 'vert', taille: 28 }); x.restore()
    x.restore()
  }
  x.restore()
}

/* -------- 6–7. L'assistant : dire avec ses mots, l'acte en photo ; la vérification -------- */
const RECIT = 'Jean est né à Brest, pas à Lyon.'
function assistant(t) {
  const { a, dy } = phase(t, P.assistant, P.question)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  titreEcran('Modifier l’arbre', 'L’assistant écoute, et prépare la demande')
  // le récit
  champ('Ce que vous savez', '', 60, 740, 960, { h: 190, focus: prog(t, P.frappe - 0.3, P.frappe) })
  const tape = frappe(RECIT, P.frappe, 22)
  texte(tape, 90, 810, { taille: 36, poids: 500, align: 'left' })
  if (t < P.photo && Math.floor(t * 2.4) % 2 === 0) { x.fillStyle = COUL.accent; x.fillRect(92 + largeurTexte(tape, 36, 500), 785, 4, 50) }
  // les boutons de documents
  bouton('Importer', 290, 1010, 440, 90, { variante: 'contour', taille: 30 })
  bouton('Prendre en photo', 790, 1010, 440, 90, { variante: 'contour', taille: 30, presse: pression(P.photo) })
  // l'acte photographié
  const kph = eback(prog(t, P.photo + 0.1, P.photo + 0.5), 1.6)
  if (kph > 0 && t < P.correction + 0.2) { x.save(); x.translate(540, 1380); x.scale(kph, kph); acte(0, 0, 560, 420, { rot: -0.03 }); x.restore() }
  // la demande préparée
  const kc = eout(prog(t, P.correction - 0.1, P.correction + 0.35))
  if (kc > 0) {
    x.save(); x.globalAlpha *= kc; x.translate(0, (1 - kc) * 80)
    panneau(60, 1120, 960, 520, { lisere: COUL.violet, bord: avecAlpha(COUL.violet, 0.6) })
    texte('Demande de modification', 100, 1185, { taille: 36, poids: 700, align: 'left' })
    texte('Jean Martin · lieu de naissance', 100, 1235, { taille: 28, poids: 400, coul: COUL.attenue, align: 'left' })
    texte('Lyon', 100, 1325, { taille: 44, poids: 600, coul: COUL.rouge, align: 'left' })
    x.fillStyle = COUL.rouge; x.fillRect(96, 1325, largeurTexte('Lyon', 44, 600) + 8, 4)
    texte('→', 300, 1325, { taille: 44, poids: 600, coul: COUL.attenue })
    texte('Brest', 380, 1325, { taille: 44, poids: 700, coul: COUL.vert, align: 'left' })
    acte(860, 1340, 160, 120, { rot: 0.05 })
    texte('Pièce jointe : acte de naissance', 100, 1415, { taille: 26, poids: 400, coul: COUL.attenue, align: 'left' })
    const kv = prog(t, P.verification - 0.1, P.verification + 0.2), ka = prog(t, P.acceptee - 0.1, P.acceptee + 0.2)
    if (kv > 0 && ka <= 0) { x.save(); x.translate(540, 1540); x.scale(eback(kv, 2), eback(kv, 2)); pastille('En vérification par la famille', 0, 0, { taille: 30 }); x.restore() }
    if (ka > 0) { x.save(); x.translate(540, 1540); x.scale(eback(ka, 2), eback(ka, 2)); pastille('Acceptée · entrée dans l’arbre', 0, 0, { ton: 'vert', taille: 30 }); x.restore() }
    x.restore()
  }
  x.restore()
  doigt(790, 1010 + dy, P.photo)
}

/* -------- 8–9. Les questions : on demande, et l'arbre demande aussi -------- */
const QUESTION = 'Que sait-on de Jean Martin ?'
const REPONSE = 'Né en 1904 à Brest, marié à Simone Bernard en 1957. Deux actes le confirment.'
function questions(t) {
  const { a, dy } = phase(t, P.question, P.carte)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  const sienne = eio(prog(t, P.siennes, P.siennes + 0.5))
  // la conversation
  x.save(); x.globalAlpha *= 1 - sienne; x.translate(0, -sienne * 120)
  titreEcran('Poser une question', 'L’application cherche dans l’arbre')
  const kq = eback(prog(t, P.question + 0.2, P.question + 0.55), 1.6)
  if (kq > 0) { x.save(); x.globalAlpha *= clamp(kq); bulle(QUESTION, 0, 760, 760, { moi: true }); x.restore() }
  if (t > P.question + 0.6 && t < P.reponse) {
    for (let d = 0; d < 3; d++) { x.beginPath(); x.arc(110 + d * 34, 960 + Math.sin(t * 10 + d) * 6, 10, 0, Math.PI * 2); x.fillStyle = COUL.attenue; x.fill() }
  }
  const kr = eout(prog(t, P.reponse, P.reponse + 0.35))
  if (kr > 0) { x.save(); x.globalAlpha *= kr; bulle(REPONSE.slice(0, Math.ceil(REPONSE.length * clamp((t - P.reponse) / 1.4))), 0, 920, 860); x.restore() }
  x.restore()
  // la question que l'arbre vous pose
  if (sienne > 0) {
    x.save(); x.globalAlpha *= sienne; x.translate(0, (1 - sienne) * 100)
    titreEcran('Questions', 'Ce que vous seul pouvez savoir')
    panneau(60, 740, 960, 640, { lisere: COUL.accent })
    texte('UNE QUESTION POUR VOUS', 100, 800, { taille: 26, poids: 700, coul: COUL.accent, align: 'left', espacement: 3 })
    paragraphe('Où Simone Bernard et Jean Martin se sont-ils mariés ?', 100, 870, 880, { taille: 42, poids: 700 })
    champ('', frappe('À Lille, je crois !', P.siennes + 1.0, 16), 100, 1060, 880, { h: 100, focus: 1, curseur: t < P.repondre })
    bouton('Répondre', 540, 1270, 880, 96, { taille: 34, presse: pression(P.repondre) })
    x.restore()
    doigt(700, 1270 + dy, P.repondre)
  }
  x.restore()
}

/* -------- 10. La carte, puis la chronologie -------- */
const FRANCE = [[-4.8, 48.4], [-3.4, 48.8], [-1.6, 49.6], [-1.3, 49.7], [0.2, 49.5], [1.6, 50.9], [2.5, 51.1], [4.2, 49.95], [4.9, 49.8], [6.1, 49.5], [8.2, 48.97], [7.6, 47.6],
  [6.9, 47.4], [6.1, 46.15], [7.0, 45.9], [6.6, 45.1], [7.0, 44.2], [7.5, 43.8], [6.2, 43.1], [4.8, 43.4], [3.1, 43.1], [3.1, 42.45], [1.7, 42.5], [-0.5, 42.8], [-1.8, 43.4],
  [-1.3, 44.5], [-1.2, 46.0], [-2.2, 47.1], [-4.4, 47.8]]
const proj = ([lon, lat]) => [540 + (lon - 1.7) * 78, 1210 - (lat - 46.6) * 112]
const LIEUX = [
  [5.72, 45.19, 'A'], [4.83, 45.76, 'A'], [3.08, 45.78, 'A'], [5.04, 47.32, 'A'], [2.35, 48.86, 'B'], [3.06, 50.63, 'B'], [1.1, 49.44, 'B'], [2.3, 49.9, 'B'],
  [-0.58, 44.84, 'C'], [1.44, 43.6, 'C'], [1.26, 45.83, 'C'], [-1.55, 47.22, 'C'], [7.75, 48.58, 'D'], [5.37, 43.3, 'D'], [6.18, 48.69, 'D'], [-4.49, 48.39, 'D'],
]
const VIES = []
{
  const r = rnd(9)
  for (let q = 0; q < 4; q++) for (let gen = 0; gen < 9; gen++) {
    const nb = gen < 3 ? 2 + Math.floor(r() * 2) : 3 + Math.floor(r() * 3)
    for (let j = 0; j < nb; j++) { const ne = 1705 + gen * 34 + Math.floor(r() * 24); VIES.push({ q: 'ABCD'[q], ne, mort: Math.min(ne + 28 + Math.floor(r() * 55), 2030) }) }
  }
}
function carteEtChronologie(t) {
  const { a, dy } = phase(t, P.carte, P.calendrier)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  const ch = eio(prog(t, P.chrono, P.chrono + 0.5))
  // la carte
  if (ch < 1) {
    x.save(); x.globalAlpha *= 1 - ch
    titreEcran('Carte', 'Où la famille est née, s’est mariée, a vécu')
    const pts = FRANCE.map(proj)
    x.beginPath(); pts.forEach(([px, py], i) => (i ? x.lineTo(px, py) : x.moveTo(px, py))); x.closePath()
    x.fillStyle = '#1e2127'; x.fill(); x.strokeStyle = COUL.neutre; x.lineWidth = 3; x.stroke()
    LIEUX.forEach(([lon, lat, q], i) => {
      const kk = eback(prog(t, P.carte + 0.5 + i * 0.07, P.carte + 0.8 + i * 0.07), 2.5)
      if (kk <= 0) return
      const [px, py] = proj([lon, lat])
      x.beginPath(); x.arc(px, py, 30 * kk, 0, Math.PI * 2); x.fillStyle = avecAlpha(COUL[q], 0.22); x.fill()
      x.beginPath(); x.arc(px, py, 18 * kk, 0, Math.PI * 2); x.fillStyle = COUL[q]; x.fill()
    })
    x.restore()
  }
  // la chronologie : une barre par vie, les siècles défilent
  if (ch > 0) {
    x.save(); x.globalAlpha *= ch
    titreEcran('Chronologie', 'Chaque vie est une barre, siècle après siècle')
    const AN0 = 1700, AN1 = 2030, Y0 = 720, Y1 = 1740
    const anY = (an) => Y0 + (an - AN0) / (AN1 - AN0) * (Y1 - Y0)
    for (let s = 1700; s <= 2000; s += 100) { const y = anY(s); x.fillStyle = COUL.ligne; x.fillRect(60, y, 960, 2); texte(String(s), 60, y - 18, { taille: 26, poids: 700, coul: COUL.attenue, align: 'left' }) }
    const deroule = eout(prog(t, P.chrono, P.chrono + 1.6))
    const rang = { A: 0, B: 0, C: 0, D: 0 }
    VIES.forEach((v) => {
      const col = rang[v.q]++ % 10, cx = 140 + 'ABCD'.indexOf(v.q) * 225 + col * 21
      const y0b = anY(v.ne), y1b = lerp(y0b, anY(v.mort), clamp(deroule * 1.3 - (v.ne - 1700) / 330 * 0.3))
      if (anY(v.ne) > lerp(Y0, Y1, deroule) + 40) return
      rr(cx, y0b, 14, Math.max(4, y1b - y0b), 7); x.fillStyle = COUL[v.q]; x.fill()
    })
    x.restore()
  }
  x.restore()
}

/* -------- 11. Le calendrier, et ceux qu'on garde en mémoire -------- */
const JOURS = [], COUL_EV = { n: BLEU, u: COUL.accent, d: '#b7a6d9' }
for (let i = 0; i < 35; i++) JOURS.push(i - 2)
const EVENEMENTS = { 2: [['Léo', 'n']], 5: [['Emma', 'n']], 8: [['Paul & Hélène', 'u']], 12: [['Simone', 'd']], 14: [['Lucas', 'n']], 19: [['Odette', 'n']], 21: [['René & Odette', 'u']], 24: [['Auguste', 'd']], 27: [['Jade', 'n']] }
function calendrier(t) {
  const { a, dy } = phase(t, P.calendrier, P.cloche)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  titreEcran('Calendrier', 'Octobre')
  ;['L', 'M', 'M', 'J', 'V', 'S', 'D'].forEach((j, i) => texte(j, 85 + i * 152, 700, { taille: 26, poids: 700, coul: COUL.attenue }))
  JOURS.forEach((j, i) => {
    const cx = 85 + (i % 7) * 152, cy = 730 + Math.floor(i / 7) * 160
    const dansLeMois = j >= 1 && j <= 31
    rr(cx - 72, cy, 144, 150, 12); x.fillStyle = dansLeMois ? COUL.panneau : '#16171a'; x.fill()
    if (!dansLeMois) return
    texte(String(j), cx - 58, cy + 26, { taille: 26, poids: 700, align: 'left' })
    ;(EVENEMENTS[j] || []).forEach(([nom, c]) => {
      const quand = c === 'd' ? P.memoire : P.calendrier + 0.4 + (j % 9) * 0.08
      const on = eout(prog(t, quand - 0.1, quand + 0.25))
      if (on <= 0) return
      x.save(); x.globalAlpha *= on
      rr(cx - 66, cy + 56, 132, 70, 8); x.fillStyle = avecAlpha(COUL_EV[c], 0.25); x.fill()
      x.fillStyle = COUL_EV[c]; x.fillRect(cx - 66, cy + 56, 5, 70)
      texte(nom.split(' ')[0], cx - 54, cy + 91, { taille: 26, poids: 700, align: 'left' })
      x.restore()
    })
  })
  // « En mémoire »
  const km = eback(prog(t, P.memoire, P.memoire + 0.4), 1.5)
  if (km > 0) {
    x.save(); x.translate(540, 1560); x.scale(km, km)
    panneau(-460, -110, 920, 220, { lisere: COUL_EV.d, bord: avecAlpha(COUL_EV.d, 0.6) })
    texte('EN MÉMOIRE · 12 OCTOBRE', -410, -50, { taille: 26, poids: 700, coul: COUL_EV.d, align: 'left', espacement: 3 })
    texte('Simone Bernard', -410, 10, { taille: 44, poids: 700, align: 'left' })
    texte('1935 – 2019 · elle aurait eu 91 ans', -410, 62, { taille: 28, poids: 400, coul: COUL.attenue, align: 'left' })
    x.restore()
  }
  x.restore()
}

/* -------- 12. La cloche, jusque sur l'écran verrouillé -------- */
function cloche(t) {
  const { a, dy } = phase(t, P.cloche, P.retrouver)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  const g = x.createLinearGradient(0, 500, 0, H); g.addColorStop(0, '#1b2433'); g.addColorStop(1, '#0d1118'); x.fillStyle = g; rr(40, 520, 1000, 1260, 50); x.fill()
  texte('09:41', 540, 720, { taille: 170, poids: 300, coul: '#e9eef5' })
  texte('samedi 3 octobre', 540, 830, { taille: 40, poids: 500, coul: '#c3cad4' })
  const notifs = [
    [P.push1, 'Demande acceptée', 'Le lieu de naissance de Jean Martin est corrigé.'],
    [P.push2, 'Anniversaire', 'Emma Martin a 37 ans aujourd’hui.'],
    [P.push3, 'Nouvel événement', 'Cousinade été 2027 : vous êtes invité.'],
  ]
  notifs.forEach(([quand, titre, corps], i) => {
    const kp = eback(prog(t, quand - 0.1, quand + 0.3), 1.4)
    if (kp <= 0) return
    const y = 920 + i * 220
    x.save(); x.translate(0, (1 - clamp(kp)) * -120); x.globalAlpha *= clamp(kp * 2)
    rr(70, y, 940, 190, 36); x.fillStyle = 'rgba(232,236,242,.16)'; x.fill()
    rr(105, y + 35, 64, 64, 14); x.fillStyle = COUL.fond; x.fill()
    x.save(); x.translate(137, y + 67); dessinerCloche(0, 0, 22, COUL.accent); x.restore()
    texte('HERITAGE', 195, y + 55, { taille: 26, poids: 700, coul: '#c3cad4', align: 'left', espacement: 2 })
    texte('maintenant', 970, y + 55, { taille: 24, poids: 500, coul: '#9aa3ad', align: 'right' })
    texte(titre, 195, y + 103, { taille: 34, poids: 700, coul: '#ffffff', align: 'left' })
    texte(corps, 195, y + 148, { taille: 27, poids: 400, coul: '#dfe4ea', align: 'left' })
    x.restore()
  })
  x.restore()
}

/* -------- 13–14. Les événements : se retrouver, inviter, relancer -------- */
function evenements(t) {
  const { a, dy } = phase(t, P.retrouver, P.tirage)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  const suivi = eio(prog(t, P.suivi, P.suivi + 0.5))
  // trois occasions
  if (suivi < 1) {
    x.save(); x.globalAlpha *= 1 - suivi; x.translate(0, -suivi * 120)
    titreEcran('Événements', 'Se retrouver, en vrai')
    const cartes = [
      [P.cousinade, 'Cousinade été 2027', '17 – 18 juillet · Annecy', 'Rassemblement', 'accent'],
      [P.surprise, 'Les 70 ans de Paul', 'Anniversaire surprise · chut !', 'Rassemblement', 'accent'],
      [P.hommage, 'Hommage à Simone', 'Une messe, puis un repas ensemble', 'Rassemblement', 'violet'],
    ]
    cartes.forEach(([quand, titre, sous, type, ton], i) => {
      const k = eback(prog(t, quand - 0.15, quand + 0.3), 1.6)
      if (k <= 0) return
      const y = 720 + i * 300
      x.save(); x.translate(540, y + 120); x.scale(k, k); x.rotate((1 - clamp(k)) * (i % 2 ? 0.08 : -0.08))
      panneau(-480, -120, 960, 250, { lisere: ton === 'violet' ? COUL.violet : COUL.accent })
      texte(titre, -430, -40, { taille: 44, poids: 700, align: 'left' })
      texte(sous, -430, 20, { taille: 30, poids: 400, coul: COUL.attenue, align: 'left' })
      x.translate(-430 + largeurTexte(type, 26, 700) / 2 + 18, 82); pastille(type, 0, 0, { ton, taille: 26 })
      x.restore()
    })
    x.restore()
  }
  // le suivi des invités
  if (suivi > 0) {
    x.save(); x.globalAlpha *= suivi; x.translate(0, (1 - suivi) * 100)
    titreEcran('Suivi des invités', 'Cousinade été 2027 · 24 invités')
    const groupes = [
      ['Présents', '12', COUL.vert, 'Lucas, Emma, Paul…', M(14, 6)],
      ['Absents', '3', COUL.attenue, 'Jean, Odette, René', M(14, 6) + 0.25],
      ['Vu, sans réponse', '5', COUL.accent, 'vu il y a 2 jours…', M(14, 8)],
      ['Pas encore vu', '4', COUL.rouge, '2 par lien, 2 avec un compte', M(14, 9)],
    ]
    groupes.forEach(([nom, nb, c, sous, quand], i) => {
      const y = 700 + i * 180, k = eout(prog(t, quand - 0.15, quand + 0.2))
      x.save(); x.globalAlpha *= 0.35 + 0.65 * k
      panneau(60, y, 960, 155, { lisere: c })
      texte(nom, 100, y + 58, { taille: 38, poids: 700, align: 'left' })
      texte(sous, 100, y + 108, { taille: 26, poids: 400, coul: COUL.attenue, align: 'left' })
      texte(nb, 980, y + 78, { taille: 64, poids: 800, coul: c, align: 'right' })
      x.restore()
    })
    bouton('Relancer 9 invités', 540, 1480, 960, 100, { variante: 'contourAccent', taille: 34, presse: pression(P.relance) })
    if (t > P.relance + 0.1) { const kk = eback(prog(t, P.relance + 0.1, P.relance + 0.4), 2); x.save(); x.translate(540, 1610); x.scale(kk, kk); pastille('9 invités relancés', 0, 0, { ton: 'vert', taille: 28 }); x.restore() }
    x.restore()
    doigt(700, 1480 + dy, P.relance)
  }
  x.restore()
}

/* -------- 15. Le tirage au sort de Noël -------- */
function tirage(t) {
  const { a, dy } = phase(t, P.tirage, P.demarches)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  titreEcran('Noël des cousins', 'Tirage au sort · 24 décembre')
  panneau(60, 720, 960, 760, { bord: avecAlpha(VIOLET_CADEAU, 0.6), epais: 3, r: 30 })
  const ouvre = prog(t, P.revele - 0.2, P.revele + 0.1)
  x.save(); x.translate(540, 960 + Math.sin(t * 3) * 6 * (1 - ouvre))
  x.fillStyle = VIOLET_CADEAU; rr(-130, -60, 260, 180, 16); x.fill()
  x.save(); x.translate(0, -60 - 70 * eio(ouvre)); x.rotate(-0.35 * eio(ouvre)); rr(-145, -40, 290, 52, 12); x.fillStyle = '#9a6fe0'; x.fill(); x.restore()
  x.fillStyle = '#f2d16b'; x.fillRect(-14, -60, 28, 180)
  x.restore()
  const r = eback(prog(t, P.revele, P.revele + 0.4), 2)
  if (r > 0) {
    x.save(); x.translate(540, 1270); x.scale(r, r)
    texte('Vous offrez à', 0, -45, { taille: 36, poids: 500, coul: COUL.attenue })
    texte('Léo Martin', 0, 30, { taille: 76, poids: 800 })
    x.restore()
  } else bouton('Voir à qui je fais le cadeau', 540, 1290, 780, 100, { variante: 'violet', taille: 32, presse: pression(P.revele) })
  x.restore()
  doigt(540, 1290 + dy, P.revele)
}

/* -------- 16. Les actes demandés aux mairies et aux archives -------- */
function demarches(t) {
  const { a, dy } = phase(t, P.demarches, P.prive)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  titreEcran('Actes demandés', 'Qui a écrit à qui, et où on en est')
  const lignesActes = [
    ['Acte de naissance · Jean Martin', 'Mairie de Brest', 'Reçu', 'vert', M(16, 7)],
    ['Acte de mariage · Simone et Jean', 'Mairie de Lille', 'Envoyé', 'accent', M(16, 8)],
    ['Registre paroissial · 1788', 'Archives du Rhône', 'Relancé', 'violet', M(16, 10)],
  ]
  lignesActes.forEach(([titre, ou, statut, ton, quand], i) => {
    const k = eout(prog(t, quand - 0.3, quand + 0.1))
    if (k <= 0) return
    const y = 730 + i * 250
    x.save(); x.globalAlpha *= k; x.translate((1 - k) * 80, 0)
    panneau(60, y, 960, 210)
    acte(150, y + 105, 110, 140, { rot: -0.04 })
    texte(titre, 240, y + 70, { taille: 32, poids: 700, align: 'left' })
    texte(ou, 240, y + 118, { taille: 28, poids: 400, coul: COUL.attenue, align: 'left' })
    x.translate(240 + largeurTexte(statut, 26, 700) / 2 + 18, y + 168); pastille(statut, 0, 0, { ton, taille: 26 })
    x.restore()
  })
  x.restore()
}

/* -------- 17. Privé : l'arbre reste entre nous -------- */
function prive(t) {
  const { a, dy } = phase(t, P.prive, P.installer)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  // l'arbre en fond, estompé
  x.save(); x.globalAlpha *= 0.25; x.beginPath(); x.rect(0, 500, W, H - 600); x.clip(); filmer({ s: 0.8, fx: 540, fy: 1000 })
  traitsDe(A, {}); for (const n of A.N) ficheDe(n, {}); x.restore()
  // le cadenas
  const kc = eback(prog(t, P.cadenas - 0.2, P.cadenas + 0.25), 1.8)
  if (kc > 0) {
    x.save(); x.translate(540, 1030); x.scale(kc, kc)
    const ferme = eio(prog(t, P.cadenas, P.cadenas + 0.25))
    x.strokeStyle = COUL.accent; x.lineWidth = 34; x.lineCap = 'round'
    x.beginPath(); x.arc(0, -90 - 40 * (1 - ferme), 110, Math.PI, 0); x.lineTo(110, -90 + 40 * (1 - ferme) * 0); x.stroke()
    rr(-170, -90, 340, 260, 40); x.fillStyle = COUL.accent; x.fill()
    x.fillStyle = COUL.fond; x.beginPath(); x.arc(0, 15, 30, 0, Math.PI * 2); x.fill(); x.fillRect(-12, 15, 24, 70)
    x.restore()
  }
  const kt = eout(prog(t, P.cadenas + 0.2, P.cadenas + 0.5))
  // un voile sous les mots, pour qu'ils se lisent par-dessus l'arbre
  x.save(); x.globalAlpha *= kt; rr(140, 1250, 800, 340, 34); x.fillStyle = avecAlpha(COUL.fond, 0.92); x.fill(); x.restore()
  texte('Privé, entre nous', 540, 1330, { taille: 64, police: SERIF, poids: 600, alpha: kt })
  ;[['Sur invitation seulement', 'accent', P.cadenas + 0.5], ['Les vivants protégés', 'vert', P.vivants]].forEach(([s, ton, quand], i) => {
    const k = eback(prog(t, quand - 0.1, quand + 0.3), 2)
    if (k <= 0) return
    x.save(); x.translate(540, 1450 + i * 90); x.scale(k, k); pastille(s, 0, 0, { ton, taille: 32 }); x.restore()
  })
  x.restore()
}

/* -------- 18. Heritage sur l'écran d'accueil du téléphone -------- */
function installer(t) {
  const { a, dy } = phase(t, P.installer, P.venez)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  const g = x.createLinearGradient(0, 500, 0, H); g.addColorStop(0, '#1b2433'); g.addColorStop(1, '#0d1118'); x.fillStyle = g; rr(40, 520, 1000, 1260, 50); x.fill()
  const r = rnd(3)
  for (let i = 0; i < 16; i++) {
    const cx = 175 + (i % 4) * 243, cy = 700 + Math.floor(i / 4) * 250
    if (i === 6) continue
    rr(cx - 75, cy - 75, 150, 150, 36); x.fillStyle = `rgba(232,236,242,${0.08 + r() * 0.08})`; x.fill()
    x.fillStyle = 'rgba(232,236,242,.25)'; x.fillRect(cx - 45, cy + 100, 90, 12)
  }
  // l'icône Heritage arrive à sa place
  const ki = eback(prog(t, P.icone - 0.1, P.icone + 0.35), 2.2)
  if (ki > 0) {
    const cx = 175 + 2 * 243, cy = 950
    x.save(); x.translate(cx, cy); x.scale(ki, ki)
    x.shadowColor = avecAlpha(COUL.accent, 0.8); x.shadowBlur = 40 * (1 - prog(t, P.icone + 0.4, P.icone + 1.4))
    rr(-75, -75, 150, 150, 36); x.fillStyle = COUL.fond; x.fill(); x.shadowBlur = 0
    x.strokeStyle = COUL.accent; x.lineWidth = 9; x.lineCap = 'round'
    x.beginPath(); x.moveTo(0, 45); x.lineTo(0, -5); x.moveTo(0, 15); x.lineTo(-30, -20); x.moveTo(0, 15); x.lineTo(30, -20); x.moveTo(0, -5); x.lineTo(0, -40); x.stroke()
    ;[[-30, -20, COUL.A], [0, -40, COUL.B], [30, -20, COUL.C]].forEach(([px, py, c]) => { x.beginPath(); x.arc(px, py, 11, 0, Math.PI * 2); x.fillStyle = c; x.fill() })
    x.restore()
    texte('Heritage', cx, cy + 112, { taille: 28, poids: 600, coul: '#e9eef5', alpha: clamp(ki) })
  }
  const kb = eout(prog(t, P.icone + 0.3, P.icone + 0.6))
  if (kb > 0) { x.save(); x.globalAlpha *= kb; bouton('Installer', 540, 1640, 600, 100, { taille: 36, presse: pression(P.icone + 0.6) }); x.restore() }
  x.restore()
  doigt(540, 1640 + dy, P.icone + 0.6)
}

/* -------- 19. Venez : tout l'arbre s'allume -------- */
function venez(t) {
  const k = eout(prog(t, P.venez, P.venez + 0.5))
  if (k <= 0) return
  x.save(); x.globalAlpha = k
  x.beginPath(); x.rect(0, 500, W, H - 500); x.clip()
  filmer({ s: lerp(0.9, 0.62, edoux(prog(t, P.venez, P.fin))), fx: 540, fy: lerp(1050, 760, edoux(prog(t, P.venez, P.fin))) })
  const r = rnd(11)
  blocsLointains(A, 1)
  traitsDe(A, { alpha: 0.8 })
  for (const n of A.N) {
    const d = P.venez + 0.2 + r() * 2.2
    ficheDe(n, { surligne: eout(prog(t, d, d + 0.3)) * (1 - prog(t, d + 0.5, d + 1.2)) })
  }
  x.restore()
}

/* -------- la fin : l'emblème, la dernière phrase, la signature -------- */
function signature(t) {
  const d = V[20].fin + 0.2
  const k = eout(prog(t, d, d + 0.5))
  if (k <= 0) return
  texte('— Nathan', 540, 1200, { taille: 60, police: SERIF, poids: 600, coul: COUL.accent, alpha: k })
}

SCENE = (t) => {
  fondGrille()
  // un fondu d'entrée depuis le noir
  bonjour(t)
  arbre(t)
  fiche(t)
  assistant(t)
  questions(t)
  carteEtChronologie(t)
  calendrier(t)
  cloche(t)
  evenements(t)
  tirage(t)
  demarches(t)
  prive(t)
  installer(t)
  venez(t)
  for (let n = 0; n < 20; n++) legende(n)
  emblemeFinal(P.fin)
  legende(20)
  signature(t)
  const noir = 1 - prog(t, 0, 0.35)
  if (noir > 0) { x.save(); x.globalAlpha = noir; x.fillStyle = COUL.fond; x.fillRect(0, 0, W, H); x.restore() }
}
lancer(SCENE, { affiche: V[3].fin + 0.3 })
