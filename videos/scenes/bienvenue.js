/* Bienvenue — la vidéo du lien d'invitation : ce qui va se passer, dans l'ordre
   de l'écran (se retrouver, choisir ses identifiants, la vérification), puis
   ce qu'on fera dans l'arbre. Données fictives : Nathan Martin, né en 1992. */
minuter([0.5, 0.95, 0.55, 0.75, 0.6, 0.75, 0.6], [0.55, 0.75, 0.65, 0.8, 0.45, 0.45, 0], 1.6)

/* -------- l'arbre de Nathan -------- */
const RANG = { 0: 1420, 1: 1220, 2: 1020, 3: 860 }
const GENS = [
  [{ nom: 'Nathan Martin', dates: '° 14.3.1992', sexe: 'h', vous: true }],
  [{ nom: 'Paul Martin', dates: '° 1961', sexe: 'h', branche: 'A' }, { nom: 'Hélène Dubois', dates: '° 1964', sexe: 'f', branche: 'C' }],
  [{ nom: 'Jean Martin', dates: '1932 – 2011', sexe: 'h', branche: 'A' }, { nom: 'Simone Bernard', dates: '1935 – 2019', sexe: 'f', branche: 'B' },
    { nom: 'René Dubois', dates: '1930 – 1998', sexe: 'h', branche: 'C' }, { nom: 'Odette Lefèvre', dates: '1936 – 2020', sexe: 'f', branche: 'D' }],
  [...'AABBCCDD'].map((b, i) => ({ nom: '', dates: '', sexe: i % 2 ? 'f' : 'h', branche: b })),
]
const TAILLE = { 0: [330, 110], 1: [330, 104], 2: [236, 96], 3: [112, 64] }
const noeuds = []
GENS.forEach((rang, g) => {
  const [w, h] = TAILLE[g], n = rang.length, ecart = g === 3 ? 12 : g === 2 ? 20 : 60
  const total = n * w + (n - 1) * ecart
  rang.forEach((p, i) => noeuds.push({ ...p, g, i, cx: 540 - total / 2 + w / 2 + i * (w + ecart), cy: RANG[g], w, h }))
})
const noeud = (g, i) => noeuds.find((n) => n.g === g && n.i === i)
const moi = noeud(0, 0)
const dist = (n) => Math.hypot(n.cx - moi.cx, (n.cy - moi.cy) * 1.3)
const LOIN = Math.max(...noeuds.map(dist))

/* -------- la partition -------- */
const P = {
  arbre: 0.15,
  formulaire: V[1].debut - 0.95, recherche: V[1].fin + 0.35,
  resultats: V[2].debut - 0.45, choix: M(2, 6), continuer: M(2, 8) + 0.25,
  identifiants: V[3].debut - 0.7, creer: V[3].fin + 0.4,
  attente: V[4].debut - 0.55, verifie: M(4, 4), cadenas: M(4, 8),
  explore: V[5].debut - 0.7, toucheFiche: M(5, 3), crayon: M(5, 6),
  fin: V[6].debut - 0.55,
}
const FRAPPES = [['Martin', M(1, 3)], ['Nathan', M(1, 5)], ['14/03/1992', M(1, 7)]]
const COURRIEL = ['nathan@exemple.fr', M(3, 2)], TELEPHONE = ['06 12 34 56 78', M(3, 4)], MOTDEPASSE = ['••••••••••••', M(3, 7) + 0.1]

son(P.arbre, 'eclosion')
son(P.formulaire, 'souffle')
for (const [s, d] of [...FRAPPES, COURRIEL, TELEPHONE, MOTDEPASSE]) sonsDeFrappe(s, d)
son(P.recherche, 'tape'); son(P.resultats, 'pop'); son(P.choix, 'tape'); son(P.continuer, 'tape')
son(P.identifiants, 'souffle'); son(P.creer, 'tape'); son(P.attente, 'pop'); son(P.verifie + 0.1, 'tampon'); son(P.cadenas, 'clic')
son(P.explore, 'souffle'); son(P.toucheFiche, 'tape'); son(P.crayon, 'pop'); son(P.fin, 'final')

/* -------- l'arbre -------- */
function traitsArbre(k, alpha) {
  if (k <= 0) return
  x.save(); x.globalAlpha *= alpha; x.strokeStyle = '#4a5059'; x.lineWidth = 3
  const seg = (a, b, c, d) => { x.beginPath(); x.moveTo(a, b); x.lineTo(lerp(a, c, k), lerp(b, d, k)); x.stroke() }
  for (let g = 0; g < 3; g++) for (let i = 0; i < GENS[g].length; i++) {
    const e = noeud(g, i), p = noeud(g + 1, 2 * i), m = noeud(g + 1, 2 * i + 1)
    const yU = p.cy + p.h / 2 + 14, yB = e.cy - e.h / 2 - 16, mid = (p.cx + m.cx) / 2
    seg(e.cx, e.cy - e.h / 2, e.cx, yB); seg(e.cx, yB, mid, yB); seg(mid, yB, mid, yU)
    seg(mid, yU, p.cx, yU); seg(mid, yU, m.cx, yU); seg(p.cx, yU, p.cx, p.cy + p.h / 2); seg(m.cx, yU, m.cx, m.cy + m.h / 2)
  }
  x.restore()
}
/** `trouve` : 0 = la case de Nathan est vide (« Vous ? »), 1 = elle est remplie. */
function arbre(t, { alpha = 1, trouve = 0, surligne = null } = {}) {
  traitsArbre(eout(prog(t, P.arbre + 0.5, P.arbre + 1.0)), alpha)
  for (const n of noeuds) {
    const d = P.arbre + 0.85 * Math.pow(dist(n) / LOIN, 0.8)
    const k = prog(t, d, d + 0.32)
    if (k <= 0) continue
    const sc = eback(k, 2.2)
    if (n === moi && trouve < 1) {
      // la case vide, en pointillés, qui respire
      x.save(); x.globalAlpha = alpha * (1 - trouve) * clamp(k * 3)
      x.translate(n.cx, n.cy); x.scale(sc * (1 + 0.03 * Math.sin(t * 5)), sc * (1 + 0.03 * Math.sin(t * 5)))
      rr(-n.w / 2, -n.h / 2, n.w, n.h, 14); x.fillStyle = 'rgba(227,163,95,.07)'; x.fill()
      x.setLineDash([14, 10]); x.lineDashOffset = -t * 30; x.strokeStyle = COUL.accent; x.lineWidth = 4; x.stroke(); x.setLineDash([])
      texte('Vous ?', 0, 2, { taille: 44, poids: 700, coul: COUL.accent })
      x.restore()
      if (trouve <= 0) continue
    }
    carteFiche(n.cx, n.cy, n.w, n.h, {
      ...n, alpha: alpha * clamp(k * 3) * (n === moi ? trouve : 1), echelle: sc * (n === moi ? lerp(1.25, 1, eout(trouve)) : 1),
      surligne: surligne && surligne.n === n ? surligne.k : 0,
    })
  }
}

/* -------- les écrans d'inscription -------- */
function cadreEntree(titre, sous, etape, alpha, dy) {
  x.save(); x.globalAlpha *= alpha; x.translate(0, dy)
  texte(`ÉTAPE ${etape} SUR 2`, 90, 560, { taille: 26, poids: 700, coul: COUL.accent, align: 'left', espacement: 4 })
  texte(titre, 90, 625, { taille: 58, poids: 700, align: 'left' })
  texte(sous, 90, 685, { taille: 34, poids: 400, coul: COUL.attenue, align: 'left' })
  rr(60, 740, 960, 880, 28); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
  x.restore()
}
function ecranRecherche(t) {
  const entree = eout(prog(t, P.formulaire, P.formulaire + 0.45)), sortie = eio(prog(t, P.identifiants - 0.1, P.identifiants + 0.3))
  const a = entree * (1 - sortie)
  if (a <= 0) return
  const dy = (1 - entree) * 120 - sortie * 160
  cadreEntree('Vous situer dans l’arbre', 'Pour retrouver votre fiche.', 1, a, dy)
  x.save(); x.globalAlpha *= a; x.translate(0, dy)
  const etiquettes = [['Nom de famille', 'Votre nom de naissance.'], ['Prénom·s', 'Tous, dans l’ordre de l’état civil.'], ['Date de naissance', null]]
  // le formulaire se replie quand les résultats arrivent
  const repli = eio(prog(t, P.resultats - 0.1, P.resultats + 0.3))
  etiquettes.forEach(([e, aide], i) => {
    const [s, d] = FRAPPES[i]
    const tape = frappe(s, d)
    const focus = t >= d - 0.15 && (i === 2 ? t < P.recherche : t < FRAPPES[i + 1][1] - 0.15) ? 1 : 0
    const py = lerp(850 + i * 190, 800 + i * 0, repli)
    if (repli > 0 && i > 0) return
    if (repli > 0) {
      // résumé de la recherche, sur une ligne
      texte('Martin · Nathan · 14/03/1992', 110, 830, { taille: 34, poids: 600, align: 'left', alpha: repli })
      texte('Modifier', 970, 830, { taille: 30, poids: 600, coul: COUL.accent, align: 'right', alpha: repli })
      return
    }
    champ(e, tape, 110, py, 860, { focus, curseur: focus > 0, aide, mono: i === 2 })
  })
  if (repli <= 0) bouton('Rechercher ma fiche', 540, 1480, 860, 104, { presse: pression(P.recherche), alpha: t > FRAPPES[2][1] + 0.5 ? 1 : 0.45 })
  x.restore()
  if (repli <= 0) doigt(700, 1480 + dy, P.recherche)

  // les résultats : deux homonymes — la date de naissance tranche
  const r = eout(prog(t, P.resultats, P.resultats + 0.4))
  if (r > 0) {
    x.save(); x.globalAlpha *= a; x.translate(0, dy)
    texte('2 fiches trouvées', 110, 920, { taille: 30, poids: 700, coul: COUL.attenue, align: 'left', alpha: r, espacement: 1 })
    const choisie = eout(prog(t, P.choix, P.choix + 0.2))
    const cands = [
      { nom: 'Nathan Martin', dates: '° 14.3.1992 · Lyon', sexe: 'h', bonne: true },
      { nom: 'Nathan Martin', dates: '1921 – 2004 · Rouen', sexe: 'h', bonne: false },
    ]
    cands.forEach((c, i) => {
      const ki = eout(prog(t, P.resultats + i * 0.12, P.resultats + 0.4 + i * 0.12))
      const y = 1010 + i * 170 + (1 - ki) * 40
      const sel = c.bonne ? choisie : 0
      x.save(); x.globalAlpha *= ki * (c.bonne ? 1 : 1 - 0.5 * choisie)
      rr(110, y - 70, 860, 140, 18); x.fillStyle = sel > 0 ? melange(COUL.panneau2, '#3a2e22', sel) : COUL.panneau2; x.fill()
      x.strokeStyle = sel > 0 ? COUL.accent : COUL.ligne; x.lineWidth = sel > 0 ? 4 : 2; x.stroke()
      silhouette(185, y + 4, 70, '#4b5a74')
      texte(c.nom, 250, y - 18, { taille: 38, poids: 700, align: 'left' })
      texte(c.dates, 250, y + 28, { taille: 28, poids: 500, align: 'left', coul: COUL.attenue })
      // le bouton radio
      x.beginPath(); x.arc(920, y, 22, 0, Math.PI * 2); x.strokeStyle = sel > 0 ? COUL.accent : '#5d6068'; x.lineWidth = 3; x.stroke()
      if (sel > 0) { x.beginPath(); x.arc(920, y, 12 * eback(sel), 0, Math.PI * 2); x.fillStyle = COUL.accent; x.fill() }
      x.restore()
    })
    const kb = eout(prog(t, P.choix + 0.15, P.choix + 0.45))
    if (kb > 0) bouton('Continuer', 540, 1440, 860, 104, { alpha: kb, presse: pression(P.continuer) })
    x.restore()
    doigt(560, 1010 + dy, P.choix)
    doigt(620, 1440 + dy, P.continuer)
  }
}

function ecranIdentifiants(t) {
  const entree = eout(prog(t, P.identifiants, P.identifiants + 0.45)), sortie = eio(prog(t, P.attente - 0.1, P.attente + 0.3))
  const a = entree * (1 - sortie)
  if (a <= 0) return
  const dy = (1 - entree) * 120 - sortie * 160
  cadreEntree('Choisir vos identifiants', 'Pour revenir sur l’arbre.', 2, a, dy)
  x.save(); x.globalAlpha *= a; x.translate(0, dy)
  const [c, dc] = COURRIEL, [tl, dt] = TELEPHONE, [m, dm] = MOTDEPASSE
  const actif = (debut, finDuChamp) => (t >= debut - 0.15 && t < finDuChamp - 0.15 ? 1 : 0)
  champ('Adresse de courriel', frappe(c, dc, 22), 110, 850, 860, { focus: actif(dc, dt), curseur: actif(dc, dt) > 0, aide: 'Pour vous connecter et être prévenu.' })
  champ('Téléphone', frappe(tl, dt, 18), 110, 1050, 860, { focus: actif(dt, dm), curseur: actif(dt, dm) > 0, aide: 'Seul l’administrateur le voit.' })
  champ('Mot de passe', frappe(m, dm, 22), 110, 1250, 860, { focus: actif(dm, P.creer + 0.15), curseur: actif(dm, P.creer + 0.15) > 0, aide: 'Une petite phrase fait un bon mot de passe.' })
  bouton('Créer mon compte', 540, 1500, 860, 104, { presse: pression(P.creer) })
  x.restore()
  doigt(680, 1500 + dy, P.creer)
}

function ecranAttente(t) {
  const entree = eout(prog(t, P.attente, P.attente + 0.45)), sortie = eio(prog(t, P.explore - 0.1, P.explore + 0.35))
  const a = entree * (1 - sortie)
  if (a <= 0) return
  x.save(); x.globalAlpha *= a; x.translate(0, (1 - entree) * 100)
  texte('Votre inscription', 90, 600, { taille: 58, poids: 700, align: 'left' })
  rr(60, 670, 960, 470, 28); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
  texte('Votre compte est créé', 110, 740, { taille: 36, poids: 700, align: 'left' })
  const ok = prog(t, P.verifie, P.verifie + 0.25)
  pastille(ok > 0.5 ? 'vérifié' : 'en attente', 870, 740, { ton: ok > 0.5 ? 'vert' : 'accent' })
  texte('Un administrateur vérifie que la fiche', 110, 830, { taille: 34, poids: 500, align: 'left' })
  texte('choisie est bien la vôtre.', 110, 878, { taille: 34, poids: 500, align: 'left' })
  texte('Vous serez prévenu dès qu’elle est validée.', 110, 960, { taille: 30, poids: 400, coul: COUL.attenue, align: 'left' })
  // le tampon « vérifié »
  const tp = prog(t, P.verifie, P.verifie + 0.12)
  if (tp > 0) {
    x.save(); x.translate(720, 1060); x.rotate(-0.12); const sc = lerp(2.6, 1, tp * tp); x.scale(sc, sc); x.globalAlpha *= clamp(tp * 3) * 0.95
    rr(-180, -48, 360, 96, 14); x.strokeStyle = COUL.vert; x.lineWidth = 7; x.stroke()
    texte('C’EST BIEN VOUS', 0, 3, { taille: 36, poids: 800, coul: COUL.vert, espacement: 2 })
    x.restore()
  }
  // le cadenas : l'arbre reste entre vous
  const kc = eout(prog(t, P.cadenas - 0.3, P.cadenas)), ferme = eback(prog(t, P.cadenas, P.cadenas + 0.2), 3)
  if (kc > 0) {
    x.save(); x.globalAlpha *= kc; x.translate(540, 1390 + (1 - kc) * 40)
    rr(-110, -40, 220, 170, 26); x.fillStyle = COUL.accent; x.fill()
    x.strokeStyle = COUL.accent; x.lineWidth = 26; x.lineCap = 'round'
    x.beginPath(); x.moveTo(-62, -40); x.lineTo(-62, -95 - 30 * (1 - ferme)); x.arc(0, -95 - 30 * (1 - ferme), 62, Math.PI, 0); x.lineTo(62, -40 - 50 * (1 - clamp(ferme))); x.stroke()
    x.beginPath(); x.arc(0, 35, 18, 0, Math.PI * 2); x.fillStyle = '#1a1410'; x.fill(); x.fillRect(-7, 35, 14, 45)
    x.restore()
    texte('Privé : la famille seulement', 540, 1610, { taille: 34, poids: 600, coul: COUL.attenue, alpha: kc })
  }
  x.restore()
}

/* -------- l'arbre retrouvé : explorer, ouvrir, compléter -------- */
function volet(t) {
  const k = eback(prog(t, P.toucheFiche + 0.1, P.toucheFiche + 0.45), 1.4), s = eio(prog(t, P.fin - 0.2, P.fin + 0.1))
  if (k <= 0 || s >= 1) return
  const y0 = 1920 - 640 * k
  x.save()
  x.shadowColor = 'rgba(0,0,0,.6)'; x.shadowBlur = 50
  rr(30, y0, 1020, 760, 34); x.fillStyle = COUL.panneau; x.fill(); x.shadowBlur = 0
  x.fillStyle = COUL.B; x.fillRect(30, y0, 1020, 7)
  x.fillStyle = COUL.ligne; rr(490, y0 + 22, 100, 10, 5); x.fill()
  texte('Simone Bernard', 80, y0 + 100, { taille: 54, poids: 700, align: 'left' })
  texte('° 3.5.1935   † 18.1.2019', 80, y0 + 160, { taille: 32, poids: 500, align: 'left', coul: COUL.attenue, police: MONO })
  ;[['ENFANTS', null], ['Paul Martin', '° 1961'], ['Anne Martin', '° 1958']].forEach(([a, b], i) => {
    const ly = y0 + 240 + i * 56
    if (!b) texte(a, 80, ly, { taille: 24, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3 })
    else { texte(a, 80, ly, { taille: 34, poids: 600, coul: COUL.accent, align: 'left' }); texte(b, 960, ly, { taille: 28, poids: 500, coul: COUL.attenue, align: 'right', police: MONO }) }
  })
  // le crayon : compléter ce que vous savez
  const kc = eback(prog(t, P.crayon, P.crayon + 0.3), 2.5)
  if (kc > 0) {
    x.save(); x.translate(940, y0 + 100); x.scale(kc, kc)
    rr(-46, -46, 92, 92, 18); x.fillStyle = COUL.violet; x.fill()
    crayon(0, 0, '#1a1410')
    x.restore()
    const halo = prog(t, P.crayon, P.crayon + 0.8)
    if (halo < 1) { x.beginPath(); x.arc(940, y0 + 100, 50 + 60 * halo, 0, Math.PI * 2); x.strokeStyle = avecAlpha(COUL.violet, 0.7 * (1 - halo)); x.lineWidth = 5; x.stroke() }
  }
  x.restore()
}
function crayon(cx, cy, coul) {
  x.save(); x.translate(cx, cy); x.rotate(-Math.PI / 4); x.fillStyle = coul
  x.fillRect(-6, -24, 12, 36); x.beginPath(); x.moveTo(-6, 14); x.lineTo(6, 14); x.lineTo(0, 26); x.closePath(); x.fill(); x.restore()
}

function camera(t) {
  // on plonge dans la case vide quand le formulaire arrive, on en ressort pour explorer
  const plonge = eio(prog(t, P.formulaire - 0.2, P.formulaire + 0.35))
  const ressort = eio(prog(t, P.explore - 0.1, P.explore + 0.5))
  const k = plonge * (1 - ressort)
  const s = lerp(1, 3.2, k)
  return { s, fx: 540, fy: lerp(990, moi.cy, k) }
}

SCENE = (t) => {
  fondGrille()
  const cam = camera(t)
  const enArbre = t < P.formulaire + 0.35 || t > P.explore - 0.15
  if (enArbre) {
    x.save(); x.translate(540, 960); x.scale(cam.s, cam.s); x.translate(-cam.fx, -cam.fy)
    const fiche = noeud(2, 1)
    arbre(t, {
      alpha: 1 - prog(t, P.formulaire, P.formulaire + 0.3) * (t < P.explore ? 1 : 0),
      trouve: t > P.explore ? eout(prog(t, P.explore + 0.35, P.explore + 0.75)) : 0,
      surligne: t > P.toucheFiche - 0.05 ? { n: fiche, k: eout(prog(t, P.toucheFiche, P.toucheFiche + 0.2)) } : null,
    })
    x.restore()
    const f = noeud(2, 1)
    doigt(f.cx, f.cy, P.toucheFiche)
  }
  ecranRecherche(t)
  ecranIdentifiants(t)
  ecranAttente(t)
  volet(t)
  for (let n = 0; n < 6; n++) legende(n)
  // la fin : l'emblème, puis la phrase et « Commencer »
  emblemeFinal(P.fin)
  if (t > P.fin) {
    karaoke(6, 540, 1150, { taille: 52, largeur: 900, sortieApres: 9 })
    const kb = eback(prog(t, V[6].fin + 0.1, V[6].fin + 0.45), 2)
    if (kb > 0) { const pulse = 1 + 0.03 * Math.sin((t - V[6].fin) * 6); bouton('Commencer', 540, 1340, 560 * kb * pulse, 110 * kb, { taille: 40 }) }
  }
}
lancer(SCENE, { affiche: 2.2 })
