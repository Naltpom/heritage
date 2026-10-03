/* « Un mot de Nathan » : Nathan se présente à la famille (par le sang ou par
   alliance), puis fait le tour de l'application — l'arbre et la balade
   dedans, la fiche, la carte, la chronologie, le calendrier, les stats,
   l'assistant et la vérification, les questions, les événements, les actes
   demandés, la confidentialité. Dit à la première personne, en tutoyant.

   Le mouvement : chaque tableau entre par la droite et sort par la gauche,
   comme on glisse d'un écran à l'autre ; pendant qu'il est là, la caméra
   avance lentement. Des halos aux couleurs des branches dérivent en fond, et
   les temps forts lâchent une gerbe d'éclats. */
minuter(
  [1.0, 0.4, 0.55, 0.5, 0.5, 0.4, 0.45, 0.55, 0.5, 0.5, 0.55, 0.5, 0.55, 0.4, 0.55, 0.4, 0.55, 0.45, 0.55, 0.55, 0.55],
  [0.35, 0.4, 0.45, 0.4, 0.35, 0.45, 0.45, 0.4, 0.5, 0.45, 0.35, 0.5, 0.4, 0.75, 0.4, 1.05, 0.4, 0.75, 0.45, 0.45, 0.5],
  3.0,
)

const BLEU = '#6ea8e8'
/** L'instant où le tableau de la phrase `n` commence à entrer. */
const S = (n) => V[n].debut - 0.45

const P = {
  // 0–2 : salut ; où l'on s'est croisés ; t'es dans mon arbre
  avatar: 0.15, nom: M(0, 3), mariage: M(1, 7), repas: M(1, 10), jamais: M(1, 15),
  arbre: S(2), sang: M(2, 4), alliance: M(2, 7), enchante: M(2, 9),
  // 3 : 1 800 personnes, toutes reliées à moi
  ensemble: S(3) + 0.1, compteur: M(3, 18) - 0.3, reliees: M(3, 20),
  // 4 : toi au centre ; chaque couleur, une branche
  fiches: S(4), centre: M(4, 10), branches: M(4, 17),
  // 5 : la balade dans l'arbre
  balade: M(5, 2), zoom: M(5, 5), remonte: M(5, 7), descend: M(5, 13),
  // 6 : la fiche
  fiche: S(6), touche: M(6, 1) - 0.05, dates: M(6, 8), proches: M(6, 10), photos: M(6, 12), origine: M(6, 14),
  // 7 : la carte
  carte: S(7), monde: M(7, 5), lieux: M(7, 8), liens: M(7, 13),
  // 8 : la chronologie
  chrono: S(8), epoque: M(8, 16),
  // 9 : le calendrier
  calendrier: S(9), anniversaires: M(9, 6), memoire: M(9, 14),
  // 10–11 : les stats
  stats: S(10), adore: M(10, 8), doyen: M(10, 10), fratrie: M(10, 17), longMariage: M(10, 21),
  stats2: S(11), prenoms: M(11, 1), transmis: M(11, 8), metiers: M(11, 14), age: M(11, 18),
  // 12–13 : l'assistant et la vérification
  assistant: S(12), frappe: M(12, 10), photo: M(12, 16) - 0.1,
  prepare: M(13, 0), correction: M(13, 3), verification: M(13, 9), acceptee: V[13].fin + 0.3,
  // 14–15 : les questions
  question: S(14), reponse: M(14, 9), siennes: S(15) + 0.2, repondre: V[15].fin + 0.55,
  // 16–17 : les événements
  retrouver: S(16), cousinade: M(16, 12), surprise: M(16, 14), hommage: M(16, 17),
  suivi: S(17), voyez: M(17, 6), qui: M(17, 7), vient: M(17, 8), relance: M(17, 15),
  // 18 : les actes demandés
  demarches: S(18), mairies: M(18, 7), archives: M(18, 10), directement: M(18, 14),
  // 19 : la confidentialité
  prive: S(19), cadenas: M(19, 4), vivants: M(19, 11),
  // 20 : viens ! puis la fin
  venez: S(20), fin: V[20].fin + 0.6,
}

/* -------- les bruitages -------- */
son(P.avatar, 'eclosion'); son(P.nom, 'pop'); son(P.mariage, 'note1'); son(P.repas, 'note2'); son(P.jamais, 'note3')
son(P.arbre, 'souffle', 0.6); son(P.sang, 'note2'); son(P.alliance, 'note4'); son(P.enchante, 'succes')
son(P.ensemble, 'recul'); son(P.compteur + 0.4, 'impact', 0.6); son(P.reliees, 'note3', 0.6)
son(P.fiches, 'souffle', 0.5); son(P.centre, 'pop')
for (let q = 0; q < 4; q++) son(P.branches + q * 0.12, `note${q + 1}`, 0.6)
son(P.balade, 'souffle', 0.4); son(P.zoom, 'recul', 0.5); son(P.remonte, 'souffle', 0.5); son(P.descend, 'souffle', 0.5)
son(P.touche, 'tape'); son(P.touche + 0.1, 'pop'); son(P.photos, 'note2'); son(P.origine, 'tampon', 0.5)
son(P.carte, 'souffle', 0.5); son(P.monde, 'note1', 0.6); son(P.lieux, 'note2', 0.6); son(P.liens, 'note3', 0.6)
son(P.chrono, 'recul', 0.6); son(P.epoque, 'pop')
son(P.calendrier, 'souffle', 0.5); son(P.anniversaires, 'note4'); son(P.memoire, 'note2', 0.6)
son(P.stats, 'souffle', 0.5); son(P.adore, 'pop'); son(P.doyen, 'note1'); son(P.fratrie, 'note2'); son(P.longMariage, 'note3')
son(P.stats2, 'souffle', 0.5); son(P.prenoms, 'note1', 0.6); son(P.transmis, 'note2', 0.6); son(P.metiers, 'note3', 0.6); son(P.age, 'note4', 0.6)
son(P.assistant, 'souffle', 0.5); sonsDeFrappe('Jean est né à Brest, pas à Lyon.', P.frappe, 26); son(P.photo, 'tape'); son(P.photo + 0.15, 'clic')
son(P.prepare, 'note1', 0.6); son(P.correction, 'pop'); son(P.verification, 'note3'); son(P.acceptee, 'succes')
son(P.question, 'souffle', 0.5); son(P.question + 0.25, 'pop'); son(P.reponse, 'pop'); son(P.siennes, 'notif'); son(P.repondre, 'tape')
son(P.retrouver, 'souffle', 0.5); son(P.cousinade, 'note1'); son(P.surprise, 'note2'); son(P.hommage, 'note3')
son(P.suivi, 'souffle', 0.5); son(P.voyez, 'pop', 0.6); son(P.vient, 'pop', 0.6); son(P.relance, 'tape'); son(P.relance + 0.15, 'notif')
son(P.demarches, 'souffle', 0.5); son(P.mairies, 'note1', 0.6); son(P.archives, 'note2', 0.6); son(P.directement, 'tampon', 0.5)
son(P.prive, 'souffle', 0.5); son(P.cadenas, 'tampon', 0.7); son(P.cadenas + 0.5, 'note1', 0.6); son(P.vivants, 'note2')
son(P.venez, 'eclosion'); son(P.fin, 'final')

/* -------- le mouvement -------- */
/** Un tableau visible de `debut` à `fin` : il monte en entrant, glisse vers le haut en sortant. */
function phase(t, debut, fin) {
  const k = eout(prog(t, debut, debut + 0.4)), s = eio(prog(t, fin - 0.15, fin + 0.25))
  return { a: k * (1 - s), dy: (1 - k) * 90 - s * 120 }
}
/**
 * Un tableau d'application, de `debut` à `fin` : il arrive de la droite, part
 * vers la gauche (comme un écran qu'on fait glisser), et pendant qu'il est là,
 * la caméra avance lentement vers lui.
 */
function tableau(t, debut, fin, dessin) {
  const k = eout(prog(t, debut, debut + 0.5)), s = eio(prog(t, fin - 0.15, fin + 0.35))
  const a = k * (1 - s)
  if (a <= 0) return
  const z = (1 + 0.035 * edoux(prog(t, debut, fin + 0.35))) * lerp(0.93, 1, k) * lerp(1, 0.93, s)
  x.save(); x.globalAlpha = a
  x.translate(540 + (1 - k) * 220 - s * 220, 1150); x.scale(z, z); x.translate(-540, -1150)
  dessin()
  x.restore()
}

/** Des halos aux couleurs des branches, qui dérivent lentement derrière tout. */
function ambiance(t) {
  const halos = [['A', 0.0, 260, 0.11], ['B', 1.7, 340, 0.08], ['C', 3.1, 300, 0.1], ['D', 4.4, 380, 0.07]]
  for (const [q, ph, r, al] of halos) {
    const cx = 540 + Math.sin(t * 0.21 + ph) * 420, cy = 1150 + Math.cos(t * 0.17 + ph * 1.3) * 620
    const g = x.createRadialGradient(cx, cy, 0, cx, cy, r * 2.2)
    g.addColorStop(0, avecAlpha(COUL[q], al)); g.addColorStop(1, avecAlpha(COUL[q], 0))
    x.fillStyle = g; x.fillRect(0, 0, W, H)
  }
}

/** Une gerbe d'éclats aux couleurs des branches, lâchée à `debut` depuis (cx, cy). */
const ECLATS = []
function eclats(debut, cx, cy, { n = 34, force = 1, graine = 1 } = {}) { ECLATS.push({ debut, cx, cy, n, force, graine }) }
function dessinerEclats(t) {
  for (const e of ECLATS) {
    const k = (t - e.debut) / 1.5
    if (k <= 0 || k >= 1) continue
    const r = rnd(e.graine * 97 + 13), dt = t - e.debut
    for (let i = 0; i < e.n; i++) {
      const ang = r() * Math.PI * 2, v = (380 + r() * 620) * e.force, rot = r() * 6, q = 'ABCD'[i % 4], forme = r()
      const px = e.cx + Math.cos(ang) * v * dt * (1 - 0.35 * dt), py = e.cy + Math.sin(ang) * v * dt * (1 - 0.35 * dt) + 520 * dt * dt
      x.save(); x.globalAlpha = (1 - eio(k)) * 0.95; x.translate(px, py); x.rotate(rot + dt * 7)
      x.fillStyle = i % 5 === 0 ? COUL.accent : COUL[q]
      if (forme < 0.5) { rr(-9, -4, 18, 8, 3); x.fill() } else { x.beginPath(); x.arc(0, 0, 6, 0, Math.PI * 2); x.fill() }
      x.restore()
    }
  }
}

/* -------- outils de mise en scène -------- */
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
/** Une ligne « en cours de chargement » qui miroite. */
function squelette(px, py, w, h, t, decalage = 0) {
  rr(px, py, w, h, h / 2); x.fillStyle = COUL.panneau2; x.fill()
  const m = ((t * 0.9 + decalage) % 1.4) / 1.4
  x.save(); rr(px, py, w, h, h / 2); x.clip()
  const g = x.createLinearGradient(px + (m - 0.3) * w * 1.6, 0, px + (m + 0.1) * w * 1.6, 0)
  g.addColorStop(0, avecAlpha(COUL.texte, 0)); g.addColorStop(0.5, avecAlpha(COUL.texte, 0.12)); g.addColorStop(1, avecAlpha(COUL.texte, 0))
  x.fillStyle = g; x.fillRect(px, py, w, h); x.restore()
}

/* -------- 0–1. Salut, moi c'est Nathan -------- */
function bonjour(t) {
  const { a, dy } = phase(t, P.avatar, P.arbre)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  // la caméra avance doucement vers Nathan
  const z = 1 + 0.05 * edoux(prog(t, P.avatar, P.arbre))
  x.translate(540, 1150); x.scale(z, z); x.translate(-540, -1150)
  const ka = eback(prog(t, P.avatar, P.avatar + 0.6), 1.6)
  // l'avatar, au centre, avec deux ondes qui s'éloignent et un halo qui respire
  x.save(); x.translate(540, 980); x.scale(ka, ka)
  for (let o = 0; o < 2; o++) {
    const ph = ((t * 0.45 + o * 0.5) % 1)
    x.beginPath(); x.arc(0, 0, 200 + 220 * ph, 0, Math.PI * 2); x.strokeStyle = avecAlpha(COUL.accent, 0.35 * (1 - ph)); x.lineWidth = 3; x.stroke()
  }
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
  // le nom, lettre à lettre
  const kn = eout(prog(t, P.nom - 0.1, P.nom + 0.35))
  if (kn > 0) {
    const nom = 'Nathan', w = largeurTexte(nom, 120, 600, SERIF)
    let px = 540 - w / 2
    for (let i = 0; i < nom.length; i++) {
      const kl = eback(prog(t, P.nom - 0.1 + i * 0.05, P.nom + 0.25 + i * 0.05), 2.2), lw = largeurTexte(nom[i], 120, 600, SERIF)
      if (kl > 0) { x.save(); x.translate(px + lw / 2, 1330 + (1 - kl) * 50); texte(nom[i], 0, 0, { taille: 120, police: SERIF, poids: 600, alpha: clamp(kl) }); x.restore() }
      px += lw
    }
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
    x.save(); x.translate(cx, cy + Math.sin(t * 2 + cx) * 6); x.scale(k, k); x.rotate((1 - clamp(k)) * 0.2)
    rr(-150, -80, 300, 160, 30); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
    icone(0, -22)
    texte(libelle, 0, 50, { taille: 28, poids: 600 })
    x.restore()
  }
  x.restore()
}

/* -------- 2–5. L'arbre : t'es dedans ; 1 800 personnes ; toi au centre ; la balade -------- */
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
  return trajet(t, { s: 1.12, fx: 540, fy: 1220 }, [
    { a: P.arbre, b: P.ensemble, s: 1, fy: 1100 },
    { a: P.ensemble, b: P.ensemble + 0.8, s: 0.56, fy: 720 },
    { a: P.fiches, b: P.fiches + 0.7, s: 1, fy: 1100 },
    // la balade : on glisse, on zoome, on remonte vers les ancêtres, on descend vers les cousins
    { a: P.balade - 0.2, b: P.balade + 0.7, s: 1.2, fx: 330, fy: 1200 },
    { a: P.zoom - 0.2, b: P.zoom + 0.5, s: 1.85, fx: 330, fy: 1150 },
    { a: P.remonte - 0.15, b: P.remonte + 0.8, s: 1.05, fx: 540, fy: 560 },
    { a: P.descend - 0.15, b: P.descend + 0.8, s: 1.25, fx: 540, fy: 1420 },
    { a: P.fiche, b: P.fiche + 0.6, s: 1.3, fx: 380, fy: 1000 },
  ])
}
/** Le doigt qui fait glisser l'arbre : il appuie, puis traîne de `de` à `a`. */
function glisse(debut, de, vers, duree = 0.6) {
  const k = prog(t0, debut - 0.3, debut), g = edoux(prog(t0, debut, debut + duree)), f = prog(t0, debut + duree, debut + duree + 0.3)
  if (k <= 0 || f >= 1) return
  x.save(); x.globalAlpha = eout(k) * (1 - f)
  x.beginPath(); x.arc(lerp(de[0], vers[0], g), lerp(de[1], vers[1], g), 38, 0, Math.PI * 2); x.fillStyle = 'rgba(255,255,255,.28)'; x.fill()
  x.strokeStyle = 'rgba(255,255,255,.75)'; x.lineWidth = 4; x.stroke()
  x.restore()
}
function arbre(t) {
  const { a } = phase(t, P.arbre, P.carte)
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
  const kal = eout(prog(t, P.alliance - 0.15, P.alliance + 0.3)) * (1 - ens) * (1 - eio(prog(t, P.fiches, P.fiches + 0.4)))
  if (kal > 0) {
    for (const al of ALLIES) {
      const n = A.N.find((m) => m.id === al.lien)
      x.save(); x.globalAlpha *= kal; x.setLineDash([12, 10]); x.lineDashOffset = -t * 30; x.strokeStyle = COUL.violet; x.lineWidth = 4
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
    if (n.vous) s = Math.max(eout(prog(t, P.centre - 0.1, P.centre + 0.2)), 0.8 * eout(prog(t, P.enchante - 0.1, P.enchante + 0.2)) * (1 - prog(t, P.ensemble, P.ensemble + 0.3)), Math.sin(Math.PI * prog(t, P.reliees - 0.1, P.reliees + 0.9)))
    if (n.enfant || n.fratrie) s = Math.max(s, 0.8 * Math.sin(Math.PI * prog(t, P.descend + 0.3, P.descend + 1.4)))
    if (n.g >= 3) s = Math.max(s, 0.6 * Math.sin(Math.PI * prog(t, P.remonte + 0.3, P.remonte + 1.3)))
    if (LIGNE.has(n) && sang > 0 && !n.vous) s = Math.max(s, 0.7 * sang)
    if (n === A.parSosa(5)) s = Math.max(s, eout(prog(t, P.touche, P.touche + 0.2)))
    const couleurs = n.q ? Math.max(1 - prog(t, P.fiches, P.fiches + 0.2), eout(prog(t, P.branches + 'ABCD'.indexOf(n.q) * 0.12, P.branches + 'ABCD'.indexOf(n.q) * 0.12 + 0.25))) : 1
    ficheDe(n, { ens, alpha: clamp(k * 3), echelle: eback(k, 2.2), couleurs, surligne: s, estompe: estompe(n) })
  }
  x.restore()
  // le compteur de la vue « Ensemble »
  const kc = eback(prog(t, P.compteur - 0.5, P.compteur + 0.1), 1.6) * (1 - eio(prog(t, P.fiches - 0.1, P.fiches + 0.25)))
  if (kc > 0) {
    const n = Math.round(1800 * eout(prog(t, P.compteur - 0.5, P.compteur + 0.4)))
    const bat = 1 + 0.06 * Math.sin(Math.PI * prog(t, P.compteur + 0.4, P.compteur + 0.7))
    x.save(); x.globalAlpha = clamp(kc) * a; x.translate(540, 1510); x.scale(kc * bat, kc * bat)
    rr(-400, -130, 800, 260, 36); x.fillStyle = COUL.fond; x.fill(); x.strokeStyle = COUL.accent; x.lineWidth = 3; x.stroke()
    texte(`${n.toLocaleString('fr-FR')}${n >= 1800 ? ' +' : ''}`, 0, -30, { taille: 120, police: SERIF, poids: 600, coul: COUL.accent })
    texte('personnes dans l’arbre', 0, 68, { taille: 38, poids: 600 })
    x.restore()
  }
  // la bande des branches
  for (let q = 0; q < 4; q++) {
    const k = eout(prog(t, P.branches + q * 0.12, P.branches + q * 0.12 + 0.25)) * (1 - eio(prog(t, P.balade - 0.2, P.balade + 0.2)))
    if (k <= 0) continue
    const x0 = 40 + q * 250
    x.save(); x.globalAlpha = a * k
    x.fillStyle = COUL['ABCD'[q]]; x.fillRect(x0 + 6, 520, 238 * k, 6)
    texte(BRANCHES[q].nom, x0 + 125, 560, { taille: 32, poids: 800, coul: COUL['ABCD'[q]] })
    texte(BRANCHES[q].role, x0 + 125, 596, { taille: 26, poids: 500, coul: COUL.attenue })
    x.restore()
  }
  // « vous êtes ici »
  const kv = eback(prog(t, P.centre - 0.05, P.centre + 0.3), 2) * (1 - prog(t, P.balade - 0.3, P.balade))
  if (kv > 0) {
    const [vx, vy] = versEcran(cam, A.vous.cx, A.vous.cy)
    x.save(); x.globalAlpha = clamp(kv) * a; x.translate(vx, vy - 110 - 8 * Math.sin(t * 5)); x.scale(kv, kv)
    pastille('Vous', 0, 0, { taille: 30 })
    x.restore()
  }
  voileBas()
  // les gestes de la balade
  glisse(P.balade - 0.1, [700, 1300], [900, 1200])
  glisse(P.zoom - 0.1, [500, 1240], [380, 1360], 0.5); glisse(P.zoom - 0.1, [620, 1160], [740, 1040], 0.5)
  glisse(P.remonte - 0.1, [540, 900], [540, 1500], 0.8)
  glisse(P.descend - 0.1, [540, 1500], [540, 900], 0.8)
}

/* -------- 6. La fiche : dates, proches, photos, d'où vient l'information -------- */
function fiche(t) {
  const k = eback(prog(t, P.touche + 0.1, P.touche + 0.5), 1.2), s = eio(prog(t, P.carte - 0.15, P.carte + 0.3))
  if (k <= 0 || s >= 1) return
  const [sx, sy] = versEcran(camera(P.touche), A.parSosa(5).cx, A.parSosa(5).cy)
  doigt(sx, sy, P.touche)
  const y0 = H - 1180 * k
  x.save(); x.globalAlpha = 1 - s; x.translate(-s * 220, 0)
  x.shadowColor = 'rgba(0,0,0,.6)'; x.shadowBlur = 50
  rr(20, y0, 1040, 1300, 34); x.fillStyle = COUL.panneau; x.fill(); x.shadowBlur = 0
  x.fillStyle = COUL.B; x.fillRect(20, y0, 1040, 7)
  x.fillStyle = COUL.ligne; rr(490, y0 + 22, 100, 10, 5); x.fill()
  texte('Simone Bernard', 70, y0 + 100, { taille: 54, poids: 700, align: 'left' })
  const kd = eout(prog(t, P.dates - 0.15, P.dates + 0.25))
  texte('° 3 mai 1935, Lille   † 18 janv. 2019, Paris', 70 + (1 - kd) * 40, y0 + 160, { taille: 30, poids: 500, align: 'left', coul: COUL.attenue, police: MONO, alpha: kd })
  // les proches, un par un
  const kp = prog(t, P.proches - 0.15, P.proches + 0.45)
  if (kp > 0) {
    texte('PROCHES', 70, y0 + 240, { taille: 26, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3, alpha: clamp(kp * 3) })
    ;[['Auguste Bernard', 'père'], ['Rose Faure', 'mère'], ['Jean Martin', 'époux'], ['Paul Martin', 'fils']].forEach(([nom, role], i) => {
      const ki = eout(prog(t, P.proches - 0.15 + i * 0.08, P.proches + 0.2 + i * 0.08))
      if (ki <= 0) return
      x.save(); x.globalAlpha *= ki; x.translate((1 - ki) * 60, 0)
      texte(nom, 70, y0 + 295 + i * 52, { taille: 32, poids: 600, coul: COUL.accent, align: 'left' })
      texte(role, 1000, y0 + 295 + i * 52, { taille: 26, poids: 500, coul: COUL.attenue, align: 'right' })
      x.restore()
    })
  }
  // les photos
  const kph = prog(t, P.photos - 0.15, P.photos + 0.5)
  if (kph > 0) {
    texte('PHOTOS', 70, y0 + 540, { taille: 26, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3, alpha: clamp(kph * 3) })
    ;[[220, -0.05, 'f'], [520, 0.04, 'h'], [820, -0.03, 'f']].forEach(([cx, rot, sexe], i) => {
      const kk = eback(prog(t, P.photos - 0.1 + i * 0.12, P.photos + 0.25 + i * 0.12), 2)
      if (kk > 0) { x.save(); x.translate(cx, y0 + 690 + (1 - clamp(kk)) * 80); x.scale(kk, kk); photoAncienne(0, 0, 250, 200, { rot: rot * (1 + 3 * (1 - clamp(kk))), sexe }); x.restore() }
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
    x.save(); x.translate(860, y0 + 945); x.scale(kt, kt); x.rotate((1 - clamp(kt)) * -0.4); pastille('Vérifié', 0, 0, { ton: 'vert', taille: 28 }); x.restore()
    x.restore()
  }
  x.restore()
}

/* -------- 7. La carte : où vivaient nos ancêtres, d'où vient chaque branche -------- */
const FRANCE = [[-4.8, 48.4], [-3.4, 48.8], [-1.6, 49.6], [-1.3, 49.7], [0.2, 49.5], [1.6, 50.9], [2.5, 51.1], [4.2, 49.95], [4.9, 49.8], [6.1, 49.5], [8.2, 48.97], [7.6, 47.6],
  [6.9, 47.4], [6.1, 46.15], [7.0, 45.9], [6.6, 45.1], [7.0, 44.2], [7.5, 43.8], [6.2, 43.1], [4.8, 43.4], [3.1, 43.1], [3.1, 42.45], [1.7, 42.5], [-0.5, 42.8], [-1.8, 43.4],
  [-1.3, 44.5], [-1.2, 46.0], [-2.2, 47.1], [-4.4, 47.8]]
const proj = ([lon, lat]) => [540 + (lon - 1.7) * 78, 1210 - (lat - 46.6) * 112]
const LIEUX = [
  [5.72, 45.19, 'A'], [4.83, 45.76, 'A'], [3.08, 45.78, 'A'], [5.04, 47.32, 'A'], [2.35, 48.86, 'B'], [3.06, 50.63, 'B'], [1.1, 49.44, 'B'], [2.3, 49.9, 'B'],
  [-0.58, 44.84, 'C'], [1.44, 43.6, 'C'], [1.26, 45.83, 'C'], [-1.55, 47.22, 'C'], [7.75, 48.58, 'D'], [5.37, 43.3, 'D'], [6.18, 48.69, 'D'], [-4.49, 48.39, 'D'],
]
/** Les branches venues d'ailleurs : un lieu de départ hors de la carte, relié à un lieu français. */
const AILLEURS = [
  ['Italie', 'A', [950, 1560], [5.72, 45.19]],
  ['Pologne', 'D', [930, 760], [7.75, 48.58]],
  ['Algérie', 'C', [400, 1760], [1.44, 43.6]],
]
function carte(t) {
  tableau(t, P.carte, P.chrono, () => {
    titreEcran('Carte', 'Où la famille est née, s’est mariée, a vécu')
    const pts = FRANCE.map(proj), trace = eout(prog(t, P.carte + 0.1, P.carte + 0.8))
    x.beginPath(); pts.forEach(([px, py], i) => (i ? x.lineTo(px, py) : x.moveTo(px, py))); x.closePath()
    x.fillStyle = avecAlpha(COUL.panneau, trace); x.fill()
    x.save(); x.setLineDash([3000 * trace, 3000]); x.strokeStyle = COUL.neutre; x.lineWidth = 3; x.stroke(); x.restore()
    // d'où vient chaque branche : un trait d'une même couleur relie ses lieux
    for (const q of 'ABCD') {
      const ps = LIEUX.filter((l) => l[2] === q).map(proj)
      const kl = eout(prog(t, P.liens - 0.1, P.liens + 0.6))
      if (kl <= 0) continue
      x.save(); x.globalAlpha *= 0.6 * kl; x.strokeStyle = COUL[q]; x.lineWidth = 4; x.setLineDash([8, 8]); x.lineDashOffset = -t * 40
      x.beginPath(); ps.forEach(([px, py], i) => (i ? x.lineTo(px, py) : x.moveTo(px, py))); x.stroke(); x.restore()
    }
    // les lieux tombent sur la carte
    LIEUX.forEach(([lon, lat, q], i) => {
      const kk = eback(prog(t, P.lieux - 0.3 + i * 0.05, P.lieux + i * 0.05), 2.5)
      if (kk <= 0) return
      const [px, py] = proj([lon, lat])
      const pouls = 1 + 0.25 * Math.max(0, Math.sin(t * 3 + i))
      x.beginPath(); x.arc(px, py - (1 - clamp(kk)) * 60, 30 * kk * pouls, 0, Math.PI * 2); x.fillStyle = avecAlpha(COUL[q], 0.22); x.fill()
      x.beginPath(); x.arc(px, py - (1 - clamp(kk)) * 60, 18 * kk, 0, Math.PI * 2); x.fillStyle = COUL[q]; x.fill()
    })
    // et le monde : des branches venues d'ailleurs
    AILLEURS.forEach(([pays, q, [ax, ay], lieu], i) => {
      const k = eout(prog(t, P.monde - 0.1 + i * 0.12, P.monde + 0.4 + i * 0.12))
      if (k <= 0) return
      const [px, py] = proj(lieu)
      x.save(); x.globalAlpha *= k; x.strokeStyle = COUL[q]; x.lineWidth = 4; x.setLineDash([10, 10]); x.lineDashOffset = -t * 40
      x.beginPath(); x.moveTo(ax, ay); x.quadraticCurveTo((ax + px) / 2 + 60, (ay + py) / 2 - 60, lerp(ax, px, k), lerp(ay, py, k)); x.stroke(); x.restore()
      x.save(); x.translate(ax, ay); x.scale(eback(k, 2), eback(k, 2)); pastille(pays, 0, 0, { taille: 30 }); x.restore()
    })
  })
}

/* -------- 8. La chronologie : remonter les siècles -------- */
const VIES = []
{
  const r = rnd(9)
  for (let q = 0; q < 4; q++) for (let gen = 0; gen < 9; gen++) {
    const nb = gen < 3 ? 2 + Math.floor(r() * 2) : 3 + Math.floor(r() * 3)
    for (let j = 0; j < nb; j++) { const ne = 1705 + gen * 34 + Math.floor(r() * 24); VIES.push({ q: 'ABCD'[q], ne, mort: Math.min(ne + 28 + Math.floor(r() * 55), 2030) }) }
  }
}
function chronologie(t) {
  tableau(t, P.chrono, P.calendrier, () => {
    titreEcran('Chronologie', 'Chaque vie est une barre, siècle après siècle')
    const AN0 = 1700, AN1 = 2030, Y0 = 720, Y1 = 1740
    const anY = (an) => Y0 + (an - AN0) / (AN1 - AN0) * (Y1 - Y0)
    for (let s = 1700; s <= 2000; s += 100) { const y = anY(s); x.fillStyle = COUL.ligne; x.fillRect(60, y, 960, 2); texte(String(s), 60, y - 18, { taille: 26, poids: 700, coul: COUL.attenue, align: 'left' }) }
    const deroule = eout(prog(t, P.chrono + 0.3, P.chrono + 2.2))
    const rang = { A: 0, B: 0, C: 0, D: 0 }
    VIES.forEach((v) => {
      const col = rang[v.q]++ % 10, cx = 140 + 'ABCD'.indexOf(v.q) * 225 + col * 21
      const y0b = anY(v.ne), y1b = lerp(y0b, anY(v.mort), clamp(deroule * 1.3 - (v.ne - 1700) / 330 * 0.3))
      if (anY(v.ne) > lerp(Y0, Y1, deroule) + 40) return
      rr(cx, y0b, 14, Math.max(4, y1b - y0b), 7); x.fillStyle = COUL[v.q]; x.fill()
    })
    // la ligne du temps qui descend
    const yl = lerp(Y0, Y1, deroule)
    if (deroule < 1) { x.fillStyle = COUL.accent; x.fillRect(60, yl, 960, 3); x.beginPath(); x.arc(60, yl + 1, 9, 0, Math.PI * 2); x.fill() }
    // ce qui se passait dans la famille, à chaque époque
    const evts = [[1789, 'Naissance d’Auguste Roux', 0], [1872, 'Les Roux : 11 enfants', 0.15], [1957, 'Jean et Simone se marient', 0.3]]
    evts.forEach(([an, quoi, d]) => {
      const k = eback(prog(t, P.epoque - 0.1 + d, P.epoque + 0.25 + d), 2)
      if (k <= 0) return
      const y = anY(an)
      x.save(); x.globalAlpha *= clamp(k); x.translate(1000, y); x.scale(k, k)
      const w = largeurTexte(`${an} · ${quoi}`, 28, 700) + 44
      rr(-w, -30, w, 60, 30); x.fillStyle = COUL.fond; x.fill(); x.strokeStyle = COUL.accent; x.lineWidth = 3; x.stroke()
      texte(`${an} · ${quoi}`, -w / 2, 1, { taille: 28, poids: 700 })
      x.restore()
    })
  })
}

/* -------- 9. Le calendrier : les anniversaires, et ceux qui sont partis -------- */
const JOURS = [], COUL_EV = { n: BLEU, u: COUL.accent, d: '#b7a6d9' }
for (let i = 0; i < 35; i++) JOURS.push(i - 2)
const EVENEMENTS = { 2: [['Léo', 'n']], 3: [['Emma', 'n']], 8: [['Paul & Hélène', 'u']], 12: [['Simone', 'd']], 14: [['Lucas', 'n']], 19: [['Odette', 'n']], 21: [['René & Odette', 'u']], 24: [['Auguste', 'd']], 27: [['Jade', 'n']] }
function calendrier(t) {
  tableau(t, P.calendrier, P.stats, () => {
    titreEcran('Calendrier', 'Octobre')
    ;['L', 'M', 'M', 'J', 'V', 'S', 'D'].forEach((j, i) => texte(j, 85 + i * 152, 700, { taille: 26, poids: 700, coul: COUL.attenue }))
    JOURS.forEach((j, i) => {
      const cx = 85 + (i % 7) * 152, cy = 730 + Math.floor(i / 7) * 160
      const dansLeMois = j >= 1 && j <= 31
      const kj = eout(prog(t, P.calendrier + 0.1 + i * 0.012, P.calendrier + 0.4 + i * 0.012))
      x.save(); x.globalAlpha *= kj; x.translate(0, (1 - kj) * 30)
      rr(cx - 72, cy, 144, 150, 12); x.fillStyle = dansLeMois ? COUL.panneau : COUL.fond; x.fill()
      x.restore()
      if (!dansLeMois || kj <= 0) return
      texte(String(j), cx - 58, cy + 26, { taille: 26, poids: 700, align: 'left', alpha: kj })
      ;(EVENEMENTS[j] || []).forEach(([nom, c]) => {
        const quand = c === 'd' ? P.memoire : P.anniversaires - 0.2 + (j % 9) * 0.07
        const on = eback(prog(t, quand - 0.1, quand + 0.25), 2)
        if (on <= 0) return
        x.save(); x.globalAlpha *= clamp(on); x.translate(cx, cy + 91); x.scale(on, on); x.translate(-cx, -cy - 91)
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
  })
}

/* -------- 10–11. Les stats : la famille en chiffres -------- */
/** Un cœur, pour « je l'adore ». */
function coeur(cx, cy, r, coul) {
  x.save(); x.translate(cx, cy); x.beginPath(); x.moveTo(0, r * 0.9)
  x.bezierCurveTo(-r * 1.6, -r * 0.1, -r * 0.7, -r * 1.3, 0, -r * 0.45)
  x.bezierCurveTo(r * 0.7, -r * 1.3, r * 1.6, -r * 0.1, 0, r * 0.9)
  x.fillStyle = coul; x.fill(); x.restore()
}
function stats(t) {
  tableau(t, P.stats, P.stats2, () => {
    titreEcran('Statistiques', 'La famille en chiffres')
    const kh = eback(prog(t, P.adore - 0.1, P.adore + 0.3), 2.5)
    if (kh > 0) coeur(470 + 6 * Math.sin(t * 8) * (1 - prog(t, P.adore, P.adore + 1)), 568, 26 * kh, COUL.rouge)
    // trois chiffres d'ensemble
    ;[['1 842', 'personnes'], ['11', 'générations'], ['4', 'branches']].forEach(([n, l], i) => {
      const k = eout(prog(t, P.stats + 0.3 + i * 0.1, P.stats + 0.7 + i * 0.1))
      if (k <= 0) return
      const cx = 210 + i * 330
      x.save(); x.globalAlpha *= k; x.translate(0, (1 - k) * 40)
      rr(cx - 150, 700, 300, 160, 24); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
      texte(n, cx, 765, { taille: 58, poids: 800, coul: COUL['ABC'[i]] })
      texte(l, cx, 822, { taille: 26, poids: 600, coul: COUL.attenue })
      x.restore()
    })
    const records = [
      [P.doyen, 'DOYENNE', 'Odette Martin', 103, 'ans', COUL.A],
      [P.fratrie, 'PLUS GRANDE FRATRIE', 'Les Roux, en 1872', 11, 'enfants', COUL.B],
      [P.longMariage, 'PLUS LONG MARIAGE', 'René et Odette', 68, 'ans', COUL.C],
    ]
    records.forEach(([quand, titre, qui, nb, unite, c], i) => {
      const k = eback(prog(t, quand - 0.15, quand + 0.3), 1.6)
      if (k <= 0) return
      const y = 920 + i * 250
      x.save(); x.translate(540, y + 105); x.scale(k, k); x.rotate((1 - clamp(k)) * (i % 2 ? 0.06 : -0.06))
      panneau(-480, -105, 960, 210, { lisere: c })
      texte(titre, -430, -45, { taille: 26, poids: 700, coul: c, align: 'left', espacement: 3 })
      texte(qui, -430, 20, { taille: 44, poids: 700, align: 'left' })
      texte(String(Math.round(nb * eout(prog(t, quand, quand + 0.7)))), 300, -5, { taille: 92, poids: 800, coul: c, align: 'right' })
      texte(unite, 316, 8, { taille: 30, poids: 600, coul: COUL.attenue, align: 'left' })
      x.restore()
    })
  })
}
function stats2(t) {
  tableau(t, P.stats2, P.assistant, () => {
    titreEcran('Statistiques', 'Prénoms, métiers, mariages')
    // les prénoms les plus donnés
    const kp = eout(prog(t, P.prenoms - 0.2, P.prenoms + 0.2))
    x.save(); x.globalAlpha *= kp
    panneau(60, 700, 960, 330)
    texte('PRÉNOMS LES PLUS DONNÉS', 100, 750, { taille: 26, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3 })
    ;[['Jean', 42, 'A'], ['Marie', 38, 'B'], ['Louis', 27, 'C'], ['Jeanne', 24, 'D']].forEach(([nom, n, q], i) => {
      const kb = eout(prog(t, P.prenoms + i * 0.1, P.prenoms + 0.6 + i * 0.1)), y = 800 + i * 56
      texte(nom, 100, y + 20, { taille: 30, poids: 700, align: 'left' })
      rr(260, y + 4, 640 * n / 42 * kb, 32, 16); x.fillStyle = COUL[q]; x.fill()
      texte(String(Math.round(n * kb)), 270 + 640 * n / 42 * kb + 14, y + 20, { taille: 28, poids: 700, coul: COUL.attenue, align: 'left' })
    })
    x.restore()
    // un prénom transmis de génération en génération
    const kt = eout(prog(t, P.transmis - 0.2, P.transmis + 0.2))
    if (kt > 0) {
      x.save(); x.globalAlpha *= kt; x.translate((1 - kt) * 60, 0)
      panneau(60, 1060, 960, 170, { lisere: COUL.A })
      texte('Jean, transmis sur 5 générations', 100, 1110, { taille: 34, poids: 700, align: 'left' })
      for (let g = 0; g < 5; g++) {
        const kg = eback(prog(t, P.transmis + g * 0.12, P.transmis + 0.3 + g * 0.12), 2.5), cx = 130 + g * 190
        if (g > 0) { x.fillStyle = avecAlpha(COUL.A, 0.5); x.fillRect(cx - 190, 1163, 190 * clamp(kg), 4) }
        if (kg > 0) { x.beginPath(); x.arc(cx, 1165, 16 * kg, 0, Math.PI * 2); x.fillStyle = COUL.A; x.fill(); texte(String(1790 + g * 52), cx, 1205, { taille: 22, poids: 600, coul: COUL.attenue, alpha: clamp(kg) }) }
      }
      x.restore()
    }
    // les métiers, siècle par siècle
    const km = eout(prog(t, P.metiers - 0.2, P.metiers + 0.2))
    if (km > 0) {
      x.save(); x.globalAlpha *= km; x.translate((1 - km) * 60, 0)
      panneau(60, 1260, 960, 170, { lisere: COUL.B })
      ;[['XVIIIᵉ', 'Laboureur'], ['XIXᵉ', 'Tisserand'], ['XXᵉ', 'Cheminot']].forEach(([siecle, metier], i) => {
        const k = eback(prog(t, P.metiers + i * 0.15, P.metiers + 0.35 + i * 0.15), 2)
        if (k <= 0) return
        x.save(); x.translate(230 + i * 310, 1345); x.scale(k, k)
        texte(siecle, 0, -30, { taille: 26, poids: 700, coul: COUL.B })
        texte(metier, 0, 22, { taille: 36, poids: 700 })
        x.restore()
      })
      x.restore()
    }
    // l'âge auquel on se mariait
    const ka = eout(prog(t, P.age - 0.2, P.age + 0.2))
    if (ka > 0) {
      x.save(); x.globalAlpha *= ka; x.translate((1 - ka) * 60, 0)
      panneau(60, 1460, 960, 250, { lisere: COUL.C })
      texte('ÂGE AU MARIAGE', 100, 1505, { taille: 26, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3 })
      const pts = [[1800, 26], [1850, 25], [1900, 24], [1950, 23], [2000, 29], [2020, 32]]
      const px = (an) => 140 + (an - 1800) / 220 * 800, py = (a) => 1680 - (a - 22) * 13
      const kl = eio(prog(t, P.age, P.age + 1.0))
      x.save(); x.beginPath(); x.rect(0, 0, px(1800) + 800 * kl + (kl >= 1 ? 120 : 1), H); x.clip()
      x.beginPath(); pts.forEach(([an, a], i) => (i ? x.lineTo(px(an), py(a)) : x.moveTo(px(an), py(a)))); x.strokeStyle = COUL.C; x.lineWidth = 6; x.lineJoin = 'round'; x.stroke()
      pts.forEach(([an, a]) => { x.beginPath(); x.arc(px(an), py(a), 9, 0, Math.PI * 2); x.fillStyle = COUL.C; x.fill(); texte(`${a} ans`, px(an), py(a) - 30, { taille: 22, poids: 700, coul: COUL.attenue }) })
      x.restore()
      x.restore()
    }
  })
}

/* -------- 12–13. L'assistant : l'écrire comme ça vient ; la correction ; la vérification -------- */
const RECIT = 'Jean est né à Brest, pas à Lyon.'
function assistant(t) {
  tableau(t, P.assistant, P.question, () => {
    titreEcran('Modifier l’arbre', 'L’assistant écoute, et prépare la demande')
    // le récit
    champ('Ce que tu sais', '', 60, 740, 960, { h: 190, focus: prog(t, P.frappe - 0.3, P.frappe) })
    const tape = frappe(RECIT, P.frappe, 26)
    texte(tape, 90, 810, { taille: 36, poids: 500, align: 'left' })
    if (t < P.correction && Math.floor(t * 2.4) % 2 === 0) { x.fillStyle = COUL.accent; x.fillRect(92 + largeurTexte(tape, 36, 500), 785, 4, 50) }
    // les boutons de documents
    bouton('Importer', 290, 1010, 440, 90, { variante: 'contour', taille: 30 })
    bouton('Prendre en photo', 790, 1010, 440, 90, { variante: 'contour', taille: 30, presse: pression(P.photo) })
    doigt(790, 1010, P.photo)
    // l'acte photographié, qui s'envole vers la demande
    const kph = eback(prog(t, P.photo + 0.1, P.photo + 0.5), 1.6), vol = eio(prog(t, P.prepare - 0.2, P.prepare + 0.3))
    if (kph > 0 && vol < 1) { x.save(); x.translate(lerp(540, 860, vol), lerp(1380, 1340, vol)); x.scale(kph * lerp(1, 0.29, vol), kph * lerp(1, 0.29, vol)); acte(0, 0, 560, 420, { rot: -0.03 }); x.restore() }
    // l'assistant prépare : la carte se remplit de lignes qui miroitent
    const kp = eout(prog(t, P.prepare - 0.1, P.prepare + 0.3))
    const kc = eout(prog(t, P.correction - 0.1, P.correction + 0.35))
    x.save(); x.globalAlpha *= Math.max(kp, kc); x.translate(0, (1 - kp) * 80)
    panneau(60, 1120, 960, 520, { lisere: COUL.violet, bord: avecAlpha(COUL.violet, 0.3 + 0.3 * kc) })
    if (kc < 1) {
      x.save(); x.globalAlpha *= 1 - kc
      texte('L’assistant prépare la demande…', 100, 1185, { taille: 32, poids: 600, coul: COUL.attenue, align: 'left' })
      squelette(100, 1240, 520, 30, t); squelette(100, 1300, 560, 50, t, 0.2); squelette(100, 1380, 420, 30, t, 0.4)
      x.restore()
    }
    if (vol >= 1) acte(860, 1340, 160, 120, { rot: 0.05 })
    if (kc > 0) {
      x.save(); x.globalAlpha *= kc
      texte('Demande de modification', 100, 1185, { taille: 36, poids: 700, align: 'left' })
      texte('Jean Martin · lieu de naissance', 100, 1235, { taille: 28, poids: 400, coul: COUL.attenue, align: 'left' })
      texte('Lyon', 100, 1325, { taille: 44, poids: 600, coul: COUL.rouge, align: 'left' })
      const barre = eout(prog(t, P.correction + 0.1, P.correction + 0.4))
      x.fillStyle = COUL.rouge; x.fillRect(96, 1325, (largeurTexte('Lyon', 44, 600) + 8) * barre, 4)
      const kb = eout(prog(t, P.correction + 0.25, P.correction + 0.55))
      texte('→', 300 - (1 - kb) * 30, 1325, { taille: 44, poids: 600, coul: COUL.attenue, alpha: kb })
      texte('Brest', 380, 1325, { taille: 44, poids: 700, coul: COUL.vert, align: 'left', alpha: kb })
      texte('Pièce jointe : acte de naissance', 100, 1415, { taille: 26, poids: 400, coul: COUL.attenue, align: 'left' })
      const kv = prog(t, P.verification - 0.1, P.verification + 0.2), ka = prog(t, P.acceptee - 0.1, P.acceptee + 0.2)
      if (kv > 0 && ka <= 0) { x.save(); x.translate(540, 1540); x.scale(eback(kv, 2), eback(kv, 2)); pastille('En vérification par la famille', 0, 0, { taille: 30 }); x.restore() }
      if (ka > 0) { x.save(); x.translate(540, 1540); x.scale(eback(ka, 2), eback(ka, 2)); pastille('Acceptée · entrée dans l’arbre', 0, 0, { ton: 'vert', taille: 30 }); x.restore() }
      x.restore()
    }
    x.restore()
  })
}

/* -------- 14–15. Les questions : tu demandes, et l'arbre demande aussi -------- */
const QUESTION = 'Que sait-on de Jean Martin ?'
const REPONSE = 'Né en 1904 à Brest, marié à Simone Bernard en 1957. Deux actes le confirment.'
function questions(t) {
  tableau(t, P.question, P.retrouver, () => {
    const sienne = eio(prog(t, P.siennes, P.siennes + 0.5))
    // la conversation
    x.save(); x.globalAlpha *= 1 - sienne; x.translate(-sienne * 200, 0)
    titreEcran('Poser une question', 'L’appli cherche dans l’arbre')
    const kq = eback(prog(t, M(14, 2) - 0.2, M(14, 2) + 0.15), 1.6)
    if (kq > 0) { x.save(); x.globalAlpha *= clamp(kq); x.translate((1 - clamp(kq)) * 60, 0); bulle(QUESTION, 0, 760, 760, { moi: true }); x.restore() }
    if (t > M(14, 2) + 0.4 && t < P.reponse) {
      for (let d = 0; d < 3; d++) { x.beginPath(); x.arc(110 + d * 34, 960 + Math.sin(t * 10 + d) * 6, 10, 0, Math.PI * 2); x.fillStyle = COUL.attenue; x.fill() }
    }
    const kr = eout(prog(t, P.reponse, P.reponse + 0.3))
    if (kr > 0) {
      x.save(); x.globalAlpha *= kr
      const h = bulle(REPONSE.slice(0, Math.ceil(REPONSE.length * clamp((t - P.reponse) / 1.0))), 0, 920, 860)
      // les deux actes qui le confirment
      for (let i = 0; i < 2; i++) {
        const ka = eback(prog(t, P.reponse + 0.9 + i * 0.12, P.reponse + 1.2 + i * 0.12), 2)
        if (ka > 0) { x.save(); x.translate(150 + i * 170, 920 + h + 110); x.scale(ka, ka); acte(0, 0, 130, 170, { rot: i ? 0.06 : -0.05 }); x.restore() }
      }
      x.restore()
    }
    x.restore()
    // la question que l'arbre te pose
    if (sienne > 0) {
      x.save(); x.globalAlpha *= sienne; x.translate((1 - sienne) * 200, 0)
      titreEcran('Questions', 'Ce que toi seul peux savoir')
      panneau(60, 740, 960, 640, { lisere: COUL.accent })
      texte('UNE QUESTION POUR TOI', 100, 800, { taille: 26, poids: 700, coul: COUL.accent, align: 'left', espacement: 3 })
      paragraphe('Où Simone Bernard et Jean Martin se sont-ils mariés ?', 100, 870, 880, { taille: 42, poids: 700 })
      champ('', frappe('À Lille, je crois !', M(15, 13), 16), 100, 1060, 880, { h: 100, focus: 1, curseur: t < P.repondre })
      bouton('Répondre', 540, 1270, 880, 96, { taille: 34, presse: pression(P.repondre) })
      if (t > P.repondre + 0.1) { const kk = eback(prog(t, P.repondre + 0.1, P.repondre + 0.4), 2); x.save(); x.translate(540, 1460); x.scale(kk, kk); pastille('Merci ! La famille va vérifier', 0, 0, { ton: 'vert', taille: 30 }); x.restore() }
      x.restore()
      doigt(700, 1270, P.repondre)
    }
  })
}

/* -------- 16–17. Les événements : se retrouver, inviter, voir qui vient, relancer -------- */
function evenements(t) {
  tableau(t, P.retrouver, P.demarches, () => {
    const suivi = eio(prog(t, P.suivi, P.suivi + 0.5))
    // trois occasions
    if (suivi < 1) {
      x.save(); x.globalAlpha *= 1 - suivi; x.translate(-suivi * 200, 0)
      titreEcran('Événements', 'Se retrouver, en vrai')
      const cartes = [
        [P.cousinade, 'Cousinade été 2027', '17 – 18 juillet · Annecy', 'Rassemblement', 'accent'],
        [P.surprise, 'Les 70 ans de Paul', 'Anniversaire surprise · chut !', 'Surprise', 'accent'],
        [P.hommage, 'Hommage à Simone', 'Une messe, puis un repas ensemble', 'Hommage', 'violet'],
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
      x.save(); x.globalAlpha *= suivi; x.translate((1 - suivi) * 200, 0)
      titreEcran('Suivi des invités', 'Cousinade été 2027 · 24 invités')
      const groupes = [
        ['Présents', 12, COUL.vert, 'Lucas, Emma, Paul…', P.voyez],
        ['Absents', 3, COUL.attenue, 'Jean, Odette, René', P.voyez + 0.2],
        ['Vu, sans réponse', 5, COUL.accent, 'vu il y a 2 jours…', P.qui],
        ['Pas encore vu', 4, COUL.rouge, '2 par lien, 2 avec un compte', P.vient],
      ]
      groupes.forEach(([nom, nb, c, sous, quand], i) => {
        const y = 700 + i * 170, k = eout(prog(t, quand - 0.15, quand + 0.2))
        x.save(); x.globalAlpha *= 0.35 + 0.65 * k
        panneau(60, y, 960, 150, { lisere: c })
        texte(nom, 100, y + 56, { taille: 38, poids: 700, align: 'left' })
        texte(sous, 100, y + 104, { taille: 26, poids: 400, coul: COUL.attenue, align: 'left' })
        texte(String(Math.round(nb * eout(prog(t, quand - 0.1, quand + 0.5)))), 980, y + 76, { taille: 64, poids: 800, coul: c, align: 'right' })
        x.restore()
      })
      // la jauge des réponses
      const kj = eout(prog(t, P.voyez, P.vient + 0.6))
      rr(60, 1395, 960, 24, 12); x.fillStyle = COUL.panneau2; x.fill()
      let px = 60
      for (const [, nb, c] of groupes) { const w = 960 * nb / 24 * kj; x.fillStyle = c; x.fillRect(px, 1395, w, 24); px += w }
      bouton('Relancer 9 invités', 540, 1520, 960, 100, { variante: 'contourAccent', taille: 34, presse: pression(P.relance) })
      if (t > P.relance + 0.1) { const kk = eback(prog(t, P.relance + 0.1, P.relance + 0.4), 2); x.save(); x.translate(540, 1650); x.scale(kk, kk); pastille('9 invités relancés', 0, 0, { ton: 'vert', taille: 28 }); x.restore() }
      x.restore()
      doigt(700, 1520, P.relance)
    }
  })
}

/* -------- 18. Les actes demandés aux mairies et aux archives -------- */
function demarches(t) {
  tableau(t, P.demarches, P.prive, () => {
    titreEcran('Actes demandés', 'Qui a écrit à qui, et où on en est')
    const lignesActes = [
      ['Acte de naissance · Jean Martin', 'Mairie de Brest', 'Reçu', 'vert', P.mairies - 0.1],
      ['Acte de mariage · Simone et Jean', 'Mairie de Lille', 'Envoyé', 'accent', P.mairies + 0.25],
      ['Registre paroissial · 1788', 'Archives du Rhône', 'Relancé', 'violet', P.archives],
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
      // l'acte de mariage arrive pendant qu'on regarde : « Envoyé » devient « Reçu »
      const recu = i === 1 ? prog(t, P.directement, P.directement + 0.3) : 0
      const s = recu > 0 ? 'Reçu' : statut, tn = recu > 0 ? 'vert' : ton, sc = recu > 0 ? eback(recu, 2.5) : 1
      x.translate(240 + largeurTexte(s, 26, 700) / 2 + 18, y + 168); x.scale(sc, sc); pastille(s, 0, 0, { ton: tn, taille: 26 })
      x.restore()
    })
  })
}

/* -------- 19. Privé : l'arbre reste entre nous -------- */
function prive(t) {
  tableau(t, P.prive, P.venez, () => {
    // l'arbre en fond, estompé
    x.save(); x.globalAlpha *= 0.25; x.beginPath(); x.rect(0, 500, W, H - 600); x.clip(); filmer({ s: 0.8, fx: 540, fy: 1000 })
    traitsDe(A, {}); for (const n of A.N) ficheDe(n, {}); x.restore()
    // le cadenas tombe et se ferme
    const kc = eback(prog(t, P.cadenas - 0.25, P.cadenas + 0.2), 1.8)
    if (kc > 0) {
      x.save(); x.translate(540, 1030 - (1 - clamp(kc)) * 200); x.scale(kc, kc)
      const ferme = eio(prog(t, P.cadenas, P.cadenas + 0.25))
      x.strokeStyle = COUL.accent; x.lineWidth = 34; x.lineCap = 'round'
      x.beginPath(); x.arc(0, -90 - 40 * (1 - ferme), 110, Math.PI, 0); x.lineTo(110, -90); x.stroke()
      rr(-170, -90, 340, 260, 40); x.fillStyle = COUL.accent; x.fill()
      x.fillStyle = COUL.fond; x.beginPath(); x.arc(0, 15, 30, 0, Math.PI * 2); x.fill(); x.fillRect(-12, 15, 24, 70)
      x.restore()
      // une onde quand il se ferme
      const onde = prog(t, P.cadenas + 0.2, P.cadenas + 0.9)
      if (onde > 0 && onde < 1) { x.beginPath(); x.arc(540, 1050, 220 + 260 * eout(onde), 0, Math.PI * 2); x.strokeStyle = avecAlpha(COUL.accent, 0.5 * (1 - onde)); x.lineWidth = 6; x.stroke() }
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
  })
}

/* -------- 20. Viens : tout l'arbre s'allume -------- */
function venez(t) {
  const k = eout(prog(t, P.venez, P.venez + 0.5))
  if (k <= 0) return
  x.save(); x.globalAlpha = k
  x.beginPath(); x.rect(0, 500, W, H - 500); x.clip()
  filmer({ s: lerp(0.95, 0.6, edoux(prog(t, P.venez, P.fin))), fx: 540, fy: lerp(1150, 760, edoux(prog(t, P.venez, P.fin))) })
  const r = rnd(11)
  blocsLointains(A, 1)
  traitsDe(A, { alpha: 0.8 })
  for (const n of A.N) {
    const d = P.venez + 0.2 + r() * 1.8
    ficheDe(n, { surligne: eout(prog(t, d, d + 0.3)) * (1 - prog(t, d + 0.5, d + 1.2)) })
  }
  x.restore()
}

/* -------- la fin : l'emblème et la signature -------- */
function signature(t) {
  const d = P.fin + 0.6
  const k = eout(prog(t, d, d + 0.5))
  if (k <= 0) return
  x.save(); x.translate(540 + (1 - k) * 40, 1200)
  texte('— Nathan', 0, 0, { taille: 60, police: SERIF, poids: 600, coul: COUL.accent, alpha: k })
  x.restore()
}

/* -------- les gerbes d'éclats -------- */
eclats(P.enchante + 0.1, 540, 1420, { graine: 1, force: 0.9 })
eclats(P.compteur + 0.4, 540, 1490, { graine: 2 })
eclats(P.adore + 0.05, 470, 568, { graine: 3, n: 24, force: 0.6 })
eclats(P.acceptee + 0.05, 540, 1540, { graine: 4, force: 0.8 })
eclats(P.relance + 0.15, 540, 1650, { graine: 5, n: 24, force: 0.7 })
eclats(P.fin + 0.45, 540, 760, { graine: 6, n: 56, force: 1.3 })

SCENE = (t) => {
  fondGrille()
  ambiance(t)
  bonjour(t)
  arbre(t)
  fiche(t)
  carte(t)
  chronologie(t)
  calendrier(t)
  stats(t)
  stats2(t)
  assistant(t)
  questions(t)
  evenements(t)
  demarches(t)
  prive(t)
  venez(t)
  if (t < P.fin) dessinerEclats(t)
  for (let n = 0; n < 21; n++) legende(n)
  emblemeFinal(P.fin)
  if (t >= P.fin) dessinerEclats(t)
  signature(t)
  // un fondu d'entrée depuis le noir
  const noir = 1 - prog(t, 0, 0.35)
  if (noir > 0) { x.save(); x.globalAlpha = noir; x.fillStyle = COUL.fond; x.fillRect(0, 0, W, H); x.restore() }
}
lancer(SCENE, { affiche: V[4].fin + 0.3 })
