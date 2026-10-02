/* Chapitre « Proposer une correction » : la fiche de Simone Bernard porte une
   date inversée (1953 au lieu de 1935) et il lui manque sa mère. On corrige,
   on envoie, c'est relu, on est prévenu, on retrouve tout dans « Mes demandes ». */
minuter([1.7, 0.6, 0.55, 0.5, 0.6, 0.7], [0.5, 0.5, 0.55, 0.6, 0.55, 0.7], 2.0)

const P = {
  carton: 0.1, fiche: 1.3,
  date: M(0, 1), parent: M(0, 4),
  crayon: M(1, 5) - 0.05, edition: M(1, 8),
  saisie: M(2, 2) - 0.1, ajoutParent: M(2, 6), ajoutConjoint: M(2, 8), ajoutEnfant: M(2, 10),
  corriger: M(3, 4) - 0.05, envoyee: M(3, 10) - 0.2,
  notif: M(4, 2) - 0.2, acceptee: M(4, 10) - 0.15,
  demandes: V[5].debut - 0.65,
  fin: V[5].fin + 0.7,
}
const NOUVELLE_DATE = '03/05/1935'
son(P.fiche, 'souffle', 0.6); son(P.date, 'pop'); son(P.parent, 'pop')
son(P.crayon, 'tape'); son(P.crayon + 0.12, 'souffle', 0.4)
sonsDeFrappe(NOUVELLE_DATE, P.saisie + 0.3, 16)
son(P.ajoutParent, 'note1'); son(P.ajoutConjoint, 'note2'); son(P.ajoutEnfant, 'note3')
son(P.corriger, 'tape'); son(P.envoyee, 'succes'); son(P.notif, 'notif'); son(P.acceptee, 'tampon', 0.6)
son(P.demandes, 'souffle', 0.6); son(P.fin, 'final')

/* -------- la fiche (téléphone) -------- */
function iconeCrayon(cx, cy, coul, s = 1) {
  x.save(); x.translate(cx, cy); x.scale(s, s); x.rotate(-Math.PI / 4); x.fillStyle = coul
  x.fillRect(-5, -20, 10, 30); x.beginPath(); x.moveTo(-5, 12); x.lineTo(5, 12); x.lineTo(0, 22); x.closePath(); x.fill(); x.restore()
}
function iconeCoche(cx, cy, coul) {
  x.save(); x.strokeStyle = coul; x.lineWidth = 6; x.lineCap = 'round'; x.lineJoin = 'round'
  x.beginPath(); x.moveTo(cx - 13, cy + 1); x.lineTo(cx - 3, cy + 11); x.lineTo(cx + 14, cy - 10); x.stroke(); x.restore()
}
function iconeCroix(cx, cy, coul) {
  x.save(); x.strokeStyle = coul; x.lineWidth = 5; x.lineCap = 'round'
  x.beginPath(); x.moveTo(cx - 11, cy - 11); x.lineTo(cx + 11, cy + 11); x.moveTo(cx + 11, cy - 11); x.lineTo(cx - 11, cy + 11); x.stroke(); x.restore()
}

function fiche(t) {
  const entree = eout(prog(t, P.fiche, P.fiche + 0.45)), sortie = eio(prog(t, P.demandes, P.demandes + 0.4))
  const a = entree * (1 - sortie)
  if (a <= 0) return
  const ed = eout(prog(t, P.crayon + 0.1, P.crayon + 0.4)) * (1 - eout(prog(t, P.envoyee - 0.3, P.envoyee)))
  x.save(); x.globalAlpha = a; x.translate(0, (1 - entree) * 80 - sortie * 120)
  texte('‹ Retour à l’arbre', 50, 545, { taille: 28, poids: 600, coul: COUL.accent, align: 'left' })
  // l'en-tête
  x.save(); x.beginPath(); x.arc(120, 650, 66, 0, Math.PI * 2); x.fillStyle = '#2c3038'; x.fill(); x.clip(); silhouette(120, 660, 100, '#6a4d55'); x.restore()
  texte('Simone Bernard', 215, 620, { taille: 50, poids: 700, align: 'left' })
  const corrigee = eout(prog(t, P.acceptee, P.acceptee + 0.3))
  texte(corrigee > 0.5 ? '1935 – 2019' : '1953 – 2019', 215, 672, { taille: 30, poids: 500, align: 'left', coul: COUL.attenue, police: MONO })
  pastille('Non vérifié', 300, 718, { ton: 'rouge', taille: 22 })
  // les gestes de la fiche : suppression, assistant, document, crayon
  if (ed < 0.5) {
    ;[['#d9675a', 'S'], ['#8d8880', 'A'], ['#8d8880', 'D']].forEach(([c], i) => {
      rr(650 + i * 92, 590, 76, 76, 16); x.fillStyle = COUL.panneau2; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
      x.fillStyle = c; rr(675 + i * 92, 612, 26, 32, 4); x.fill()
    })
    const pc = pression(P.crayon)
    x.save(); x.translate(964, 628); x.scale(1 - 0.08 * pc, 1 - 0.08 * pc)
    rr(-38, -38, 76, 76, 16); x.fillStyle = COUL.violet; x.fill(); iconeCrayon(0, 0, '#1a1410'); x.restore()
  } else {
    rr(830, 590, 76, 76, 16); x.fillStyle = COUL.panneau2; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke(); iconeCroix(868, 628, COUL.texte)
    const pc = pression(P.corriger)
    x.save(); x.translate(964, 628); x.scale(1 - 0.08 * pc, 1 - 0.08 * pc)
    rr(-38, -38, 76, 76, 16); x.fillStyle = COUL.violet; x.fill(); iconeCoche(0, 0, '#1a1410'); x.restore()
  }
  // le bandeau du mode édition
  if (ed > 0) {
    x.save(); x.globalAlpha *= ed
    x.fillStyle = avecAlpha(COUL.violet, 0.14); x.fillRect(0, 760, W, 70); x.fillStyle = COUL.violet; x.fillRect(0, 826, W, 4)
    iconeCrayon(70, 795, COUL.violet, 0.8)
    texte('Mode édition : rien n’est envoyé avant « Corriger ».', 105, 795, { taille: 28, poids: 600, coul: COUL.violet, align: 'left' })
    x.restore()
  } else {
    ;['Identité et famille', 'Sources · 2', 'Historique'].forEach((o, i) => texte(o, 60 + [0, 300, 520][i], 795, { taille: 28, poids: i ? 500 : 700, coul: i ? COUL.attenue : COUL.accent, align: 'left' }))
    x.fillStyle = COUL.accent; x.fillRect(60, 822, 250, 4); x.fillStyle = COUL.ligne; x.fillRect(0, 826, W, 2)
  }
  // IDENTITÉ
  const bord = ed > 0 ? melange(COUL.ligne, COUL.violet, ed) : COUL.ligne
  rr(40, 860, 1000, 420, 22); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = bord; x.lineWidth = 2; x.stroke()
  if (ed > 0) { x.fillStyle = COUL.violet; x.globalAlpha *= 1; x.fillRect(40, 880, 6 * ed, 380) }
  texte('IDENTITÉ', 80, 905, { taille: 24, poids: 700, coul: ed > 0.5 ? COUL.violet : COUL.attenue, align: 'left', espacement: 3 })
  ;[['Prénoms', 'Simone'], ['Nom', 'Bernard'], ['Sexe', 'Femme']].forEach(([a, b], i) => {
    texte(a, 80, 960 + i * 62, { taille: 30, poids: 500, coul: COUL.attenue, align: 'left' })
    if (ed > 0.5) { rr(380, 932 + i * 62, 620, 56, 10); x.fillStyle = '#111214'; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke() }
    texte(b, 400, 960 + i * 62, { taille: 30, poids: 600, align: 'left' })
  })
  // la naissance : fausse, puis saisie, puis corrigée
  texte('Naissance', 80, 1160, { taille: 30, poids: 500, coul: COUL.attenue, align: 'left' })
  const alerte = t > P.date - 0.1 && t < P.crayon ? 0.5 + 0.5 * Math.sin((t - P.date) * 9) : 0
  if (ed > 0.5) {
    const focus = t > P.saisie && t < P.ajoutParent ? 1 : 0
    rr(380, 1126, 620, 68, 10); x.fillStyle = '#111214'; x.fill(); x.strokeStyle = focus ? COUL.violet : COUL.ligne; x.lineWidth = focus ? 4 : 2; x.stroke()
    const tape = t > P.saisie + 0.3 ? frappe(NOUVELLE_DATE, P.saisie + 0.3, 16) : t > P.saisie ? '' : '03/05/1953'
    texte(tape, 400, 1160, { taille: 32, poids: 500, align: 'left', police: MONO })
    if (focus && Math.floor(t * 2.4) % 2 === 0) { x.fillStyle = COUL.violet; x.fillRect(402 + largeurTexte(tape, 32, 500, MONO), 1140, 4, 40) }
  } else {
    if (alerte > 0) { rr(380, 1130, 300, 60, 10); x.fillStyle = avecAlpha(COUL.rouge, 0.25 * alerte); x.fill() }
    texte(corrigee > 0.5 ? '3 mai 1935' : '3 mai 1953', 400, 1160, { taille: 30, poids: 600, align: 'left', coul: alerte > 0 ? melange(COUL.texte, COUL.rouge, alerte) : corrigee > 0 && corrigee < 1 ? COUL.vert : COUL.texte })
  }
  texte('Lyon, Rhône', 400, 1205, { taille: 24, poids: 400, coul: COUL.attenue, align: 'left' })
  texte('Décès', 80, 1248, { taille: 30, poids: 500, coul: COUL.attenue, align: 'left' })
  texte('18 janvier 2019', 400, 1248, { taille: 30, poids: 600, align: 'left' })
  // FAMILLE
  rr(40, 1310, 1000, 400, 22); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = bord; x.lineWidth = 2; x.stroke()
  if (ed > 0) { x.fillStyle = COUL.violet; x.fillRect(40, 1330, 6 * ed, 360) }
  texte('FAMILLE', 80, 1355, { taille: 24, poids: 700, coul: ed > 0.5 ? COUL.violet : COUL.attenue, align: 'left', espacement: 3 })
  texte('PARENTS', 80, 1405, { taille: 22, poids: 700, coul: COUL.attenue, align: 'left', espacement: 2 })
  texte('Auguste Bernard', 80, 1450, { taille: 32, poids: 600, coul: COUL.accent, align: 'left' })
  texte('° 1904', 1000, 1450, { taille: 26, poids: 500, coul: COUL.attenue, align: 'right', police: MONO })
  const manque = t > P.parent - 0.1 && t < P.crayon ? 0.5 + 0.5 * Math.sin((t - P.parent) * 9) : 0
  x.save(); x.setLineDash([10, 8]); rr(70, 1478, 520, 56, 10); x.strokeStyle = manque > 0 ? melange(COUL.ligne, COUL.rouge, manque) : COUL.ligne; x.lineWidth = 3; x.stroke(); x.restore()
  texte('mère inconnue', 95, 1506, { taille: 28, poids: 500, coul: manque > 0 ? melange(COUL.attenue, COUL.rouge, manque) : COUL.attenue, align: 'left' })
  texte('ENFANTS', 80, 1580, { taille: 22, poids: 700, coul: COUL.attenue, align: 'left', espacement: 2 })
  texte('Anne Martin', 80, 1625, { taille: 32, poids: 600, coul: COUL.accent, align: 'left' })
  texte('Paul Martin', 80, 1670, { taille: 32, poids: 600, coul: COUL.accent, align: 'left' })
  // les ajouts possibles, en mode édition : ils s'allument au mot
  if (ed > 0.5) {
    ;[['+ Ajouter un parent', P.ajoutParent, 620, 1506], ['+ Ajouter un conjoint', P.ajoutConjoint, 620, 1580], ['+ Ajouter un enfant', P.ajoutEnfant, 620, 1648]].forEach(([s, quand, px, py]) => {
      const k = eout(prog(t, quand - 0.1, quand + 0.2)), eclat = 1 - prog(t, quand + 0.2, quand + 0.8)
      if (k > 0 && eclat > 0) { rr(px - 14, py - 28, largeurTexte(s, 28, 700) + 28, 56, 10); x.fillStyle = avecAlpha(COUL.violet, 0.25 * eclat * k); x.fill() }
      texte(s, px, py, { taille: 28, poids: 700, coul: COUL.violet, align: 'left', alpha: 0.55 + 0.45 * k })
    })
  }
  x.restore()
  doigt(964, 628, P.crayon)
  doigt(690, 1160, P.saisie)
  doigt(964, 628, P.corriger)
}

/* -------- l'envoi, puis la notification -------- */
function envoi(t) {
  const k = eback(prog(t, P.envoyee, P.envoyee + 0.35), 1.6), s = eio(prog(t, P.notif - 0.2, P.notif + 0.1))
  if (k <= 0 || s >= 1) return
  x.save(); x.globalAlpha = clamp(k) * (1 - s)
  x.fillStyle = 'rgba(10,11,13,.55)'; x.fillRect(0, 500, W, H - 500)
  x.translate(540, 1220); x.scale(lerp(0.85, 1, clamp(k)), lerp(0.85, 1, clamp(k)))
  rr(-440, -170, 880, 340, 30); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = COUL.ligne; x.lineWidth = 2; x.stroke()
  x.beginPath(); x.arc(0, -70, 50, 0, Math.PI * 2); x.fillStyle = avecAlpha(COUL.accent, 0.2); x.fill(); iconeCoche(0, -70, COUL.accent)
  texte('Proposition envoyée', 0, 25, { taille: 44, poids: 700 })
  pastille('en vérification', 0, 100, { ton: 'accent' })
  x.restore()
}
function notification(t) {
  const k = eback(prog(t, P.notif, P.notif + 0.35), 1.5), s = eio(prog(t, P.demandes - 0.15, P.demandes + 0.2))
  if (k <= 0 || s >= 1) return
  x.save(); x.globalAlpha = clamp(k * 2) * (1 - s)
  // la cloche, en haut de l'écran utile
  const y = 560 + (1 - clamp(k)) * -60
  rr(40, y - 40, 1000, 230, 26); x.shadowColor = 'rgba(0,0,0,.6)'; x.shadowBlur = 40; x.fillStyle = '#23262b'; x.fill(); x.shadowBlur = 0
  x.strokeStyle = avecAlpha(COUL.vert, 0.6); x.lineWidth = 3; x.stroke()
  x.save(); x.translate(110, y + 40); x.rotate(Math.sin((t - P.notif) * 22) * 0.4 * Math.max(0, 1 - (t - P.notif) * 1.5)); dessinerCloche(0, 0, 34, COUL.texte); x.restore()
  x.beginPath(); x.arc(90 + 45, y + 12, 9, 0, Math.PI * 2); x.fillStyle = COUL.accent; x.fill()
  texte('Demande acceptée', 180, y + 18, { taille: 28, poids: 700, coul: COUL.vert, align: 'left' })
  texte('il y a 1 min', 1000, y + 18, { taille: 24, poids: 500, coul: COUL.attenue, align: 'right' })
  texte('Votre demande sur Simone Bernard', 180, y + 70, { taille: 34, poids: 700, align: 'left' })
  texte('a été validée.', 180, y + 115, { taille: 34, poids: 700, align: 'left' })
  x.restore()
}

/* -------- « Mes demandes » -------- */
function mesDemandes(t) {
  const k = eout(prog(t, P.demandes, P.demandes + 0.45))
  if (k <= 0) return
  x.save(); x.globalAlpha = k; x.translate(0, (1 - k) * 100)
  texte('Mes demandes', 60, 600, { taille: 58, poids: 700, align: 'left' })
  texte('Le suivi de tout ce que vous avez proposé.', 60, 660, { taille: 30, poids: 400, coul: COUL.attenue, align: 'left' })
  const lignes = [
    ['Simone Bernard', 'Date de naissance · 1935', 'acceptée', 'vert'],
    ['Simone Bernard', 'Ajout d’un parent · Rose Faure', 'en vérification', 'accent'],
    ['Léo Martin', 'Nouvelle photo', 'acceptée', 'vert'],
    ['Jean Martin', 'Lieu de décès · Grenoble', 'en vérification', 'accent'],
  ]
  lignes.forEach(([n, d, e, ton], i) => {
    const ki = eout(prog(t, P.demandes + 0.15 + i * 0.12, P.demandes + 0.5 + i * 0.12))
    const y = 760 + i * 190 + (1 - ki) * 40
    x.save(); x.globalAlpha *= ki
    rr(40, y, 1000, 160, 22); x.fillStyle = COUL.panneau; x.fill(); x.strokeStyle = i === 0 && t > V[5].fin - 0.6 ? COUL.vert : COUL.ligne; x.lineWidth = 2; x.stroke()
    texte(n, 80, y + 55, { taille: 36, poids: 700, align: 'left' })
    texte(d, 80, y + 108, { taille: 28, poids: 400, coul: COUL.attenue, align: 'left' })
    const w = largeurTexte(e, 24, 700) + 36
    pastille(e, 1000 - w / 2, y + 55, { ton, taille: 24 })
    x.restore()
  })
  x.restore()
}

SCENE = (t) => {
  fondGrille()
  fiche(t)
  envoi(t)
  mesDemandes(t)
  notification(t)
  for (let n = 0; n < 6; n++) legende(n)
  carton('Proposer une correction', P.carton, P.fiche + 0.1, { numero: 'AIDE' })
  emblemeFinal(P.fin, { sousTitre: 'Proposer une correction' })
}
lancer(SCENE, { affiche: P.ajoutConjoint + 0.3 })
