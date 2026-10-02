/* Chapitre « Les événements » : créer un rassemblement ou un tirage au sort,
   inviter, les réponses, le suivi (et ses fantômes), la relance, le cadeau. */
minuter([1.7, 0.6, 0.6, 0.55, 0.55, 0.45, 0.6], [0.45, 0.55, 0.65, 0.55, 0.6, 0.45, 0.7], 2.0)

const VIOLET_CADEAU = '#b78cf0'
const P = {
  carton: 0.1, liste: 1.3, creer: M(0, 6) - 0.05,
  choix: V[1].debut - 0.45, rass: M(1, 2), tirage: M(1, 5), choisi: V[1].fin + 0.2,
  formulaire: V[2].debut - 0.35, titre: M(2, 1) - 0.2, dates: M(2, 3) - 0.1, lieu: M(2, 5) - 0.1, coche: M(2, 7),
  notif: V[3].debut - 0.4, present: M(3, 5) - 0.05,
  suivi: V[4].debut - 0.45, gVient: M(4, 3), gFantome: M(4, 8), gNonVu: M(4, 14),
  relance: M(5, 2) - 0.05,
  cadeau: V[6].debut - 0.4, revele: M(6, 7) - 0.05,
  fin: V[6].fin + 0.7,
}
const TITRE = 'Cousinade été 2027', LIEU = 'Chez Hélène, à Annecy'
son(P.liste, 'souffle', 0.5); son(P.creer, 'tape'); son(P.choix, 'souffle', 0.5); son(P.rass, 'note1'); son(P.tirage, 'note2'); son(P.choisi, 'tape')
son(P.formulaire, 'souffle', 0.5); sonsDeFrappe(TITRE, P.titre, 20); sonsDeFrappe('17/07 – 18/07', P.dates, 18); sonsDeFrappe(LIEU, P.lieu, 24)
for (let i = 0; i < 5; i++) son(P.coche + i * 0.12, 'touche', 2)
son(P.notif, 'notif'); son(P.present, 'tape'); son(P.present + 0.1, 'succes')
son(P.suivi, 'souffle', 0.5); son(P.gVient, 'note1'); son(P.gFantome, 'note3'); son(P.gNonVu, 'note2')
son(P.relance, 'tape'); son(P.relance + 0.15, 'notif'); son(P.cadeau, 'souffle', 0.5); son(P.revele, 'tampon', 0.5); son(P.revele + 0.1, 'succes')
son(P.fin, 'final')

function phase(t, debut, fin) {
  const k = eout(prog(t, debut, debut + 0.4)), s = eio(prog(t, fin - 0.15, fin + 0.25))
  return { a: k * (1 - s), dy: (1 - k) * 90 - s * 120 }
}
function titreEcran(s, sous) {
  texte(s, 60, 580, { taille: 56, poids: 700, align: 'left' })
  if (sous) texte(sous, 60, 638, { taille: 30, poids: 400, coul: COUL.attenue, align: 'left' })
}
/* -------- 1. la liste des événements -------- */
function liste(t) {
  const { a, dy } = phase(t, P.liste, P.choix)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  titreEcran('Événements', 'Rassemblements et tirages au sort de la famille.')
  bouton('Créer un événement', 540, 740, 960, 100, { presse: pression(P.creer), taille: 34 })
  ;[['Noël cousins Martin', 'tirage', '24 décembre · tous les ans'], ['Anniversaire de Simone', 'rassemblement', 'sam. 3 mai 2026 · passé'], ['Mariage de Lucas', 'rassemblement', '12 septembre 2026 · passé']].forEach(([n, ty, d], i) => {
    const y = 860 + i * 190
    rr(60, y, 960, 165, 22); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
    pastille(ty === 'tirage' ? 'Tirage au sort' : 'Rassemblement', 100 + (ty === 'tirage' ? 100 : 104), y + 45, { ton: ty === 'tirage' ? 'violet' : 'accent', taille: 22 })
    texte(n, 100, y + 98, { taille: 38, poids: 700, align: 'left' })
    texte(d, 100, y + 138, { taille: 26, poids: 400, coul: COUL.attenue, align: 'left' })
  })
  x.restore()
  doigt(700, 740 + dy, P.creer)
}

/* -------- 2. le choix du type -------- */
function choix(t) {
  const { a, dy } = phase(t, P.choix, P.formulaire)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  titreEcran('Nouvel événement', 'Quel genre d’événement ?')
  ;[['rassemblement', 'Rassemblement', 'Une cousinade, un anniversaire, des', 'retrouvailles : chacun dit s’il vient.', P.rass],
    ['tirage', 'Tirage au sort', 'Chacun tire au sort à qui offrir', 'un cadeau — Noël, une fête.', P.tirage]].forEach(([ty, nom, l1, l2, quand], i) => {
    const y = 720 + i * 400
    const on = t > quand - 0.1 ? 1 - prog(t, quand + 0.9, quand + 1.2) * (ty === 'tirage' ? 1 : 0) : 0
    const choisie = ty === 'rassemblement' && t > P.choisi - 0.05
    const c = ty === 'tirage' ? VIOLET_CADEAU : COUL.accent
    x.save(); const sc = 1 + 0.03 * on; x.translate(540, y + 170); x.scale(sc, sc); x.translate(-540, -(y + 170))
    rr(60, y, 960, 340, 28); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = on > 0.5 || choisie ? c : COUL.ligne; x.lineWidth = on > 0.5 || choisie ? 5 : 2; x.stroke()
    pastille(nom, 100 + largeurTexte(nom, 26, 700) / 2 + 18, y + 70, { ton: ty === 'tirage' ? 'violet' : 'accent', taille: 26 })
    texte(nom, 100, y + 160, { taille: 54, poids: 700, align: 'left' })
    texte(l1, 100, y + 225, { taille: 30, poids: 400, coul: COUL.attenue, align: 'left' })
    texte(l2, 100, y + 268, { taille: 30, poids: 400, coul: COUL.attenue, align: 'left' })
    x.restore()
  })
  x.restore()
  doigt(540, 890 + dy, P.choisi)
}

/* -------- 3. le formulaire -------- */
const INVITES = ['Lucas Martin', 'Emma Martin', 'Paul Martin', 'Hélène Dubois', 'Simone Bernard']
function formulaire(t) {
  const { a, dy } = phase(t, P.formulaire, P.notif)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  titreEcran('Nouvel événement')
  pastille('Rassemblement', 210, 640, { ton: 'accent', taille: 24 })
  champ('Titre', frappe(TITRE, P.titre, 20), 60, 720, 960, { focus: t > P.titre - 0.1 && t < P.dates - 0.1 ? 1 : 0, curseur: t > P.titre - 0.1 && t < P.dates - 0.1, h: 88 })
  champ('Du', frappe('17/07/2027', P.dates, 18), 60, 880, 465, { focus: t > P.dates - 0.1 && t < P.lieu - 0.1 ? 1 : 0, h: 88, mono: true })
  champ('Au', t > P.dates + 0.4 ? frappe('18/07/2027', P.dates + 0.4, 18) : '', 555, 880, 465, { h: 88, mono: true })
  champ('Lieu', frappe(LIEU, P.lieu, 24), 60, 1040, 960, { focus: t > P.lieu - 0.1 && t < P.coche ? 1 : 0, curseur: t > P.lieu - 0.1 && t < P.coche, h: 88 })
  // les invités
  rr(60, 1170, 960, 560, 24); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
  const n = INVITES.filter((_, i) => t > P.coche + i * 0.12).length
  const tous = t > P.coche + 0.9
  texte(tous ? '24 invités' : `${n + 1} invité${n ? 's' : ''}`, 100, 1225, { taille: 34, poids: 700, align: 'left' })
  ;[['Tous les vivants', '24'], ['Martin', '9'], ['Dubois', '7']].forEach(([s, c], i) => {
    const w = largeurTexte(s, 26, 700) + 80, px = [100, 400, 610][i]
    rr(px, 1260, w, 60, 30); x.fillStyle = i === 0 && tous ? '#3a2e22' : '#111214'; x.fill(); x.strokeStyle = i === 0 && tous ? COUL.accent : COUL.ligne; x.lineWidth = 2; x.stroke()
    texte(s, px + 22, 1291, { taille: 26, poids: 700, align: 'left' }); texte(c, px + w - 22, 1291, { taille: 22, poids: 500, coul: COUL.attenue, align: 'right' })
  })
  INVITES.forEach((nom, i) => {
    const y = 1385 + i * 66
    caseACocher(nom, null, 100, y, tous ? 1 : prog(t, P.coche + i * 0.12, P.coche + 0.15 + i * 0.12))
    texte(['Tronc', 'Tronc', 'Martin', 'Dubois', 'Bernard'][i], 980, y, { taille: 24, poids: 500, coul: COUL.attenue, align: 'right' })
  })
  x.restore()
  doigt(220, 1290 + dy, P.coche + 0.85)
}

/* -------- 4. l'invitation arrive, on répond -------- */
function reponse(t) {
  const { a, dy } = phase(t, P.notif, P.suivi)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  const kn = eback(prog(t, P.notif, P.notif + 0.35), 1.4)
  x.save(); x.translate(0, (1 - clamp(kn)) * -80)
  rr(40, 560, 1000, 200, 26); x.fillStyle = COUL.panneau2; x.fill(); x.strokeStyle = avecAlpha(COUL.accent, 0.6); x.lineWidth = 3; x.stroke()
  x.save(); x.translate(110, 630); x.rotate(Math.sin((t - P.notif) * 22) * 0.4 * Math.max(0, 1 - (t - P.notif) * 1.5)); dessinerCloche(0, 0, 34, COUL.texte); x.restore()
  texte('Invitation', 180, 610, { taille: 28, poids: 700, coul: COUL.accent, align: 'left' })
  texte('Cousinade été 2027', 180, 660, { taille: 38, poids: 700, align: 'left' })
  texte('du sam. 17 au dim. 18 juillet 2027', 180, 708, { taille: 28, poids: 400, coul: COUL.attenue, align: 'left' })
  x.restore()
  // la page de l'événement
  rr(40, 800, 1000, 760, 26); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
  pastille('Rassemblement', 190, 860, { ton: 'accent', taille: 24 })
  texte('Cousinade été 2027', 80, 940, { taille: 50, poids: 700, align: 'left' })
  texte('Du sam. 17 au dim. 18 juillet 2027', 80, 1000, { taille: 32, poids: 500, coul: COUL.attenue, align: 'left' })
  texte('Chez Hélène, à Annecy', 80, 1045, { taille: 32, poids: 500, coul: COUL.attenue, align: 'left' })
  texte('Ma réponse', 80, 1130, { taille: 26, poids: 700, coul: COUL.attenue, align: 'left', espacement: 2 })
  const choisi = eout(prog(t, P.present, P.present + 0.2))
  ;[['Présent', 300], ['Absent', 780]].forEach(([s, cx], i) => {
    const on = i === 0 ? choisi : 0
    x.save(); x.translate(cx, 1220); const sc = 1 - 0.05 * (i === 0 ? pression(P.present) : 0); x.scale(sc, sc)
    rr(-220, -55, 440, 110, 20); x.fillStyle = on > 0.5 ? COUL.vert : COUL.panneau2; x.fill(); x.strokeStyle = on > 0.5 ? COUL.vert : COUL.ligne; x.lineWidth = 3; x.stroke()
    texte(s, 0, 2, { taille: 38, poids: 700, coul: on > 0.5 ? '#0f2a1c' : COUL.texte })
    x.restore()
  })
  texte('Qui vient : Lucas, Emma, Paul et 9 autres', 80, 1340, { taille: 28, poids: 500, coul: COUL.attenue, align: 'left', alpha: choisi })
  x.restore()
  doigt(300, 1220 + dy, P.present)
}

/* -------- 5. le suivi de l'organisateur, et la relance -------- */
function suivi(t) {
  const { a, dy } = phase(t, P.suivi, P.cadeau)
  if (a <= 0) return
  x.save(); x.globalAlpha = a; x.translate(0, dy)
  titreEcran('Suivi des invités', 'Cousinade été 2027 · 24 invités')
  const groupes = [
    ['Présents', '12', COUL.vert, P.gVient, 'Lucas, Emma, Paul…'],
    ['Absents', '3', COUL.attenue, P.gVient + 0.3, 'Jean, Odette, René'],
    ['Vu, sans réponse 👻', '5', COUL.accent, P.gFantome, 'vu il y a 2 j, il y a 4 j…'],
    ['Pas encore vu', '4', COUL.rouge, P.gNonVu, '2 avec un compte, 2 par lien'],
  ]
  groupes.forEach(([nom, nb, c, quand, sous], i) => {
    const y = 700 + i * 190
    const k = eout(prog(t, quand - 0.1, quand + 0.25)), eclat = 1 - prog(t, quand + 0.25, quand + 0.9)
    x.save(); x.globalAlpha *= 0.35 + 0.65 * k
    rr(60, y, 960, 165, 22); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = k > 0 && eclat > 0 ? c : COUL.ligne; x.lineWidth = k > 0 && eclat > 0 ? 4 : 2; x.stroke()
    x.fillStyle = c; rr(60, y, 10, 165, 5); x.fill()
    texte(nom, 100, y + 62, { taille: 38, poids: 700, align: 'left' })
    texte(sous, 100, y + 115, { taille: 26, poids: 400, coul: COUL.attenue, align: 'left' })
    texte(nb, 980, y + 82, { taille: 64, poids: 800, coul: c, align: 'right' })
    x.restore()
  })
  const kr = eout(prog(t, V[5].debut - 0.3, V[5].debut))
  if (kr > 0) { x.save(); x.globalAlpha *= kr; bouton('Relancer 9 invités', 540, 1520, 960, 100, { variante: 'contourAccent', taille: 34, presse: pression(P.relance) }); x.restore() }
  if (t > P.relance + 0.1) {
    const kk = eback(prog(t, P.relance + 0.1, P.relance + 0.4), 2)
    x.save(); x.translate(540, 1640); x.scale(kk, kk); pastille('9 invités relancés', 0, 0, { ton: 'vert', taille: 26 }); x.restore()
  }
  x.restore()
  doigt(700, 1520 + dy, P.relance)
}

/* -------- 6. le tirage au sort : la carte cadeau -------- */
function cadeau(t) {
  const k = eout(prog(t, P.cadeau, P.cadeau + 0.4))
  if (k <= 0) return
  x.save(); x.globalAlpha = k; x.translate(0, (1 - k) * 90)
  titreEcran('Noël cousins Martin', 'Tirage au sort · 24 décembre')
  rr(60, 740, 960, 700, 30); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = avecAlpha(VIOLET_CADEAU, 0.6); x.lineWidth = 3; x.stroke()
  // le paquet
  const r = eback(prog(t, P.revele, P.revele + 0.4), 2)
  const ouvre = prog(t, P.revele - 0.2, P.revele + 0.1)
  x.save(); x.translate(540, 980)
  x.fillStyle = VIOLET_CADEAU; rr(-120, -60, 240, 170, 16); x.fill()
  x.save(); x.translate(0, -60 - 70 * eio(ouvre)); x.rotate(-0.35 * eio(ouvre)); rr(-135, -40, 270, 50, 12); x.fillStyle = '#9a6fe0'; x.fill(); x.restore()
  x.fillStyle = '#f2d16b'; x.fillRect(-14, -60, 28, 170)
  x.restore()
  if (r > 0) {
    x.save(); x.translate(540, 1240); x.scale(r, r)
    texte('Vous offrez à', 0, -40, { taille: 34, poids: 500, coul: COUL.attenue })
    texte('Léo Martin', 0, 30, { taille: 70, poids: 800 })
    x.restore()
  } else bouton('Voir à qui je fais le cadeau', 540, 1260, 760, 100, { variante: 'violet', taille: 32, presse: pression(P.revele) })
  x.restore()
  doigt(540, 1260, P.revele)
}

SCENE = (t) => {
  fondGrille()
  liste(t)
  choix(t)
  formulaire(t)
  reponse(t)
  suivi(t)
  cadeau(t)
  for (let n = 0; n < 7; n++) legende(n)
  carton('Les événements', P.carton, P.liste + 0.1, { numero: 'AIDE' })
  emblemeFinal(P.fin, { sousTitre: 'Les événements' })
}
lancer(SCENE, { affiche: P.gFantome + 0.6 })
