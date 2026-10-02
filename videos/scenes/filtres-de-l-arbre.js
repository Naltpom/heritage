/* Chapitre « Les filtres de l'arbre » : le feuillet des filtres reste en bas,
   l'arbre au-dessus montre l'effet de chaque réglage au moment où la voix le dit. */
minuter([1.7, 0.55, 0.5, 0.45, 0.6, 0.6, 0.5], [0.45, 0.55, 0.45, 0.6, 0.55, 0.55, 0.6], 2.0)

const A = arbreFictif()
const MA_LIGNE = new Set(A.N.filter((n) => n.sosa || n.vous || n.enfant))
const lucas = A.N.find((n) => n.id === 'lucas'), emma = A.N.find((n) => n.id === 'emma')

const P = {
  carton: 0.1, arbre: 1.35,
  filtres: M(0, 2) - 0.05,
  masque: M(1, 6) - 0.1, remet: M(1, 11) - 0.1,
  alliances: M(2, 1) - 0.1,
  dans: M(3, 8) - 0.1,
  fratrie: M(4, 0) - 0.05, echange: M(4, 4),
  ligne: M(5, 0) - 0.05, estompe: M(5, 10) - 0.4,
  reinit: M(6, 1) - 0.05,
  ferme: V[6].fin + 0.25, fin: V[6].fin + 0.6,
}
son(P.arbre, 'eclosion'); son(P.filtres, 'tape'); son(P.filtres + 0.1, 'souffle', 0.6)
son(P.masque, 'tape'); son(P.masque + 0.1, 'pop'); son(P.remet, 'tape'); son(P.remet + 0.1, 'pop')
son(P.alliances, 'tape'); son(P.alliances + 0.15, 'note2'); son(P.dans, 'tape'); son(P.dans + 0.1, 'souffle', 0.7)
son(P.fratrie, 'tape'); son(P.echange, 'souffle', 0.5); son(P.ligne, 'tape'); son(P.reinit, 'tape'); son(P.reinit + 0.1, 'recul')
son(P.fin, 'final')

/* -------- les familles par alliance : deux blocs, rattachés à une branche -------- */
const ALLIANCES = [
  { nom: 'Petit', q: 'A', pont: A.parSosa(4), apart: { cx: 1290, cy: 760 }, dans: { cx: 415, cy: 760 }, gens: [['Henri Petit', 'h'], ['Lise Morin', 'f'], ['Marc Petit', 'h']] },
  { nom: 'Lambert', q: 'D', pont: A.parSosa(7), apart: { cx: 1290, cy: 1080 }, dans: { cx: 1420, cy: 760 }, gens: [['Alain Lambert', 'h'], ['Nadia Simon', 'f'], ['Hugo Lambert', 'h']] },
]
const BLOC_W = 220
const etat = (t) => {
  const mont = prog(t, P.alliances, P.alliances + 0.4) * (1 - prog(t, P.reinit, P.reinit + 0.4))
  const dans = eio(prog(t, P.dans, P.dans + 0.7)) * (1 - eio(prog(t, P.reinit, P.reinit + 0.6)))
  const masque = eio(prog(t, P.masque, P.masque + 0.35)) * (1 - eio(prog(t, P.remet, P.remet + 0.35)))
  const fratrie = eio(prog(t, P.echange, P.echange + 0.7)) * (1 - eio(prog(t, P.reinit, P.reinit + 0.6)))
  const ligne = eout(prog(t, P.estompe, P.estompe + 0.4)) * (1 - eout(prog(t, P.reinit, P.reinit + 0.4)))
  return { mont, dans, masque, fratrie, ligne }
}
/** « Dans mon arbre » : chaque bloc entre dans la colonne de sa branche, et pousse les colonnes à sa droite. */
function decalage(q, e) { return { A: 0, B: BLOC_W + 30, C: BLOC_W + 30, D: BLOC_W + 30 }[q] * e.dans }
function position(n, e) {
  // les parents se recentrent sous leurs propres parents ; la fratrie et les enfants, sous le couple
  let dx
  if (n.id === 'pere') dx = (decalage('A', e) + decalage('B', e)) / 2
  else if (n.id === 'mere') dx = (decalage('C', e) + decalage('D', e)) / 2
  else if (n.q) dx = decalage(n.q, e)
  else dx = (decalage('A', e) + decalage('B', e) + decalage('C', e) + decalage('D', e)) / 4
  let cx = n.cx + dx
  // la fratrie par ordre de naissance : Emma (1989) à gauche, Lucas (1995) à droite
  if (n === lucas) cx = lerp(n.cx, emma.cx, e.fratrie) + dx
  if (n === emma) cx = lerp(n.cx, lucas.cx, e.fratrie) + dx
  const sauteFratrie = n === lucas || n === emma ? -Math.sin(Math.PI * e.fratrie) * 70 : 0
  return { ...n, cx, cy: n.cy + sauteFratrie }
}

function blocAlliance(al, e, estompe) {
  if (e.mont <= 0) return
  const c = { cx: lerp(al.apart.cx, al.dans.cx, e.dans), cy: lerp(al.apart.cy, al.dans.cy, e.dans) }
  const pont = position(al.pont, e)
  x.save(); x.globalAlpha = eout(e.mont) * (1 - 0.72 * estompe)
  // le trait vers la famille qui les relie : long « à part », court « dans mon arbre »
  x.setLineDash([12, 10]); x.strokeStyle = avecAlpha(COUL[al.q], 0.8); x.lineWidth = 4
  x.beginPath(); x.moveTo(c.cx - BLOC_W / 2 * (c.cx > pont.cx ? 1 : -1), c.cy); x.lineTo(lerp(c.cx, pont.cx, 0.5), c.cy); x.lineTo(lerp(c.cx, pont.cx, 0.5), pont.cy - pont.h / 2 - 8); x.lineTo(pont.cx, pont.cy - pont.h / 2 - 8); x.stroke()
  x.setLineDash([])
  rr(c.cx - BLOC_W / 2, c.cy - 135, BLOC_W, 270, 18); x.fillStyle = 'rgba(28,30,34,.95)'; x.fill(); x.strokeStyle = COUL[al.q]; x.lineWidth = 3; x.stroke()
  texte(`Famille ${al.nom}`, c.cx, c.cy - 105, { taille: 26, poids: 800, coul: COUL[al.q] })
  carteFiche(c.cx - 52, c.cy - 30, 96, 74, { nom: '', sexe: al.gens[0][1], branche: al.q })
  carteFiche(c.cx + 52, c.cy - 30, 96, 74, { nom: '', sexe: al.gens[1][1], branche: al.q })
  carteFiche(c.cx, c.cy + 80, 190, 74, { nom: al.gens[2][0], sexe: al.gens[2][1], branche: al.q })
  x.restore()
}

/* -------- le feuillet des filtres -------- */
const Y_FEUILLET = 1330
const SECTIONS = { affichage: 0, branches: 150, alliances: 440, fratries: 735, seulement: 885 }
function defilement(t) {
  return trajet(t, { s: 0, fx: SECTIONS.affichage, fy: 0 }, [
    { a: V[1].debut - 0.5, b: V[1].debut - 0.1, fx: SECTIONS.branches },
    { a: V[2].debut - 0.45, b: V[2].debut - 0.05, fx: SECTIONS.alliances },
    { a: V[4].debut - 0.55, b: V[4].debut - 0.15, fx: SECTIONS.fratries },
    { a: V[5].debut - 0.55, b: V[5].debut - 0.15, fx: SECTIONS.seulement },
  ]).fx
}
function feuillet(t, e) {
  const k = eback(prog(t, P.filtres + 0.1, P.filtres + 0.45), 1.2) * (1 - eio(prog(t, P.ferme, P.ferme + 0.35)))
  if (k <= 0) return
  const y0 = H - (H - Y_FEUILLET) * k
  x.save()
  x.shadowColor = 'rgba(0,0,0,.6)'; x.shadowBlur = 50
  rr(16, y0, 1048, H - Y_FEUILLET + 60, 34); x.fillStyle = COUL.panneau; x.fill(); x.shadowBlur = 0
  x.fillStyle = COUL.ligne; rr(480, y0 + 18, 120, 12, 6); x.fill()
  texte('Filtres · Arbre', 60, y0 + 78, { taille: 36, poids: 700, align: 'left' })
  const pr = pression(P.reinit)
  texte('Réinitialiser', 1020, y0 + 78, { taille: 32, poids: 700, align: 'right', coul: pr ? '#ffd2a0' : COUL.accent })
  x.fillStyle = COUL.ligne; x.fillRect(16, y0 + 122, 1048, 2)
  // le contenu défile
  x.beginPath(); x.rect(16, y0 + 124, 1048, H - y0 - 124); x.clip()
  x.translate(0, y0 + 160 - defilement(t))
  const titre = (s, y) => texte(s, 60, y, { taille: 24, poids: 700, coul: COUL.attenue, align: 'left', espacement: 3 })
  titre('AFFICHAGE DES FICHES', SECTIONS.affichage)
  rr(60, SECTIONS.affichage + 30, 500, 70, 14); x.fillStyle = '#111214'; x.fill()
  rr(66, SECTIONS.affichage + 36, 240, 58, 11); x.fillStyle = '#3a2e22'; x.fill()
  texte('Compact', 186, SECTIONS.affichage + 66, { taille: 28, poids: 700 }); texte('Portrait', 430, SECTIONS.affichage + 66, { taille: 28, poids: 500, coul: COUL.attenue })
  // branches : quatre boutons de couleur
  titre('BRANCHES', SECTIONS.branches)
  BRANCHES.forEach((b, i) => {
    const bx = 60 + (i % 2) * 485, by = SECTIONS.branches + 40 + Math.floor(i / 2) * 120
    const off = b.cle === 'C' ? e.masque : 0
    rr(bx, by, 465, 100, 16); x.fillStyle = avecAlpha(COUL[b.cle], 0.12 * (1 - off)); x.fill(); x.strokeStyle = off > 0.5 ? COUL.ligne : avecAlpha(COUL[b.cle], 0.7); x.lineWidth = 3; x.stroke()
    x.fillStyle = off > 0.5 ? '#5d6068' : COUL[b.cle]; rr(bx + 24, by + 30, 20, 20, 5); x.fill()
    texte(b.nom, bx + 60, by + 38, { taille: 32, poids: 700, align: 'left', alpha: 1 - 0.5 * off })
    texte(b.role, bx + 60, by + 72, { taille: 24, poids: 400, align: 'left', coul: COUL.attenue, alpha: 1 - 0.5 * off })
    if (b.cle === 'C') { const pm = Math.max(pression(P.masque), pression(P.remet)); if (pm) { rr(bx, by, 465, 100, 16); x.fillStyle = `rgba(255,255,255,${0.1 * pm})`; x.fill() } }
  })
  // familles par alliance
  titre('FAMILLES PAR ALLIANCE', SECTIONS.alliances)
  caseACocher('Les afficher · 2', 'les familles des conjoints de vos proches', 60, SECTIONS.alliances + 75, e.mont)
  ;[['À part', 'chacune son bloc, à droite'], ['Dans mon arbre', 'dans la branche qui les relie']].forEach(([a, b], i) => {
    const bx = 60 + i * 485, by = SECTIONS.alliances + 140, on = i === 0 ? 1 - e.dans : e.dans
    rr(bx, by, 465, 110, 16); x.fillStyle = on > 0.5 ? '#2b2118' : '#111214'; x.fill(); x.strokeStyle = on > 0.5 ? COUL.accent : COUL.ligne; x.lineWidth = 3; x.stroke()
    texte(a, bx + 26, by + 40, { taille: 30, poids: 700, align: 'left', coul: on > 0.5 ? COUL.texte : COUL.attenue })
    texte(b, bx + 26, by + 78, { taille: 23, poids: 400, align: 'left', coul: COUL.attenue })
  })
  titre('FRATRIES', SECTIONS.fratries)
  caseACocher('Par ordre de naissance', 'l’aîné à gauche, le plus jeune à droite', 60, SECTIONS.fratries + 75, Math.min(1, prog(t, P.fratrie, P.fratrie + 0.25)) * (1 - prog(t, P.reinit, P.reinit + 0.2)))
  titre('AFFICHER SEULEMENT', SECTIONS.seulement)
  caseACocher('Ma ligne directe', null, 60, SECTIONS.seulement + 70, prog(t, P.ligne, P.ligne + 0.25) * (1 - prog(t, P.reinit, P.reinit + 0.2)), { droite: 'ancêtres et descendants', largeur: 960 })
  caseACocher('Personnes vivantes', null, 60, SECTIONS.seulement + 160, 0)
  texte('Les autres fiches restent visibles, estompées.', 60, SECTIONS.seulement + 235, { taille: 26, poids: 400, coul: COUL.attenue, align: 'left' })
  x.restore()
  return y0
}
/** Où est, à l'écran, un point du contenu du feuillet (pour poser le doigt). */
const dansFeuillet = (t, px, py) => [px, Y_FEUILLET + 160 - defilement(t) + py]

/* -------- la caméra sur l'arbre (au-dessus du feuillet) -------- */
const CY = 960
function camera(t) {
  return trajet(t, { s: 1, fx: 540, fy: 1100 }, [
    { a: P.filtres + 0.1, b: P.filtres + 0.6, s: 0.62, fx: 600, fy: 1100 },
    { a: P.alliances - 0.1, b: P.alliances + 0.4, s: 0.5, fx: 740, fy: 1060 },
    { a: P.dans + 0.1, b: P.dans + 0.8, s: 0.52, fx: 750, fy: 1060 },
    { a: P.fratrie - 0.2, b: P.fratrie + 0.4, s: 0.82, fx: 690, fy: 1270 },
    { a: P.ligne - 0.2, b: P.ligne + 0.4, s: 0.55, fx: 740, fy: 1080 },
    { a: P.reinit + 0.05, b: P.reinit + 0.6, s: 0.62, fx: 600, fy: 1100 },
    { a: P.ferme, b: P.ferme + 0.5, s: 1, fx: 540, fy: 1100 },
  ])
}

SCENE = (t) => {
  fondGrille()
  const e = etat(t), cam = camera(t)
  const estompe = (n) => (MA_LIGNE.has(n) ? 0 : e.ligne)
  const cache = (n) => (n.q === 'C' ? e.masque : 0)
  x.save(); x.beginPath(); x.rect(0, 600, W, H - 600); x.clip(); filmer(cam, CY)
  traitsDe(A, { k: eout(prog(t, P.arbre + 0.3, P.arbre + 0.8)), estompe: (n) => Math.max(estompe(n), cache(n)), position: (n) => (cache(n) > 0.95 ? null : position(n, e)) })
  for (const al of ALLIANCES) blocAlliance(al, e, e.ligne)
  for (const n of A.N) {
    const k = prog(t, P.arbre + 0.6 * Math.abs(n.g) / 4, P.arbre + 0.3 + 0.6 * Math.abs(n.g) / 4)
    if (k <= 0) continue
    const p = position(n, e)
    const h = cache(n)
    ficheDe(n, { cx: p.cx, cy: p.cy, alpha: clamp(k * 3) * (1 - h), echelle: eback(k, 2) * (1 - 0.3 * h), estompe: estompe(n), surligne: (n === lucas || n === emma) && e.fratrie > 0 && e.fratrie < 1 ? 0.8 : 0 })
  }
  x.restore()
  barreArbre({ alpha: eout(prog(t, P.arbre, P.arbre + 0.4)), presseFiltres: pression(P.filtres), filtresActifs: (e.mont > 0.5) + (e.fratrie > 0.5) + (e.ligne > 0.5) + (e.masque > 0.5) })
  doigt(680, 550, P.filtres)
  feuillet(t, e)
  doigt(...dansFeuillet(P.masque, 60 + 465 / 2, SECTIONS.branches + 40 + 120 + 50), P.masque)
  doigt(...dansFeuillet(P.remet, 60 + 465 / 2, SECTIONS.branches + 40 + 120 + 50), P.remet)
  doigt(...dansFeuillet(P.alliances, 82, SECTIONS.alliances + 75), P.alliances)
  doigt(...dansFeuillet(P.dans, 60 + 485 + 232, SECTIONS.alliances + 195), P.dans)
  doigt(...dansFeuillet(P.fratrie, 82, SECTIONS.fratries + 75), P.fratrie)
  doigt(...dansFeuillet(P.ligne, 82, SECTIONS.seulement + 70), P.ligne)
  doigt(950, Y_FEUILLET + 78, P.reinit)
  for (let n = 0; n < 7; n++) legende(n)
  carton('Les filtres de l’arbre', P.carton, P.arbre + 0.1, { numero: 'AIDE' })
  emblemeFinal(P.fin, { sousTitre: 'Les filtres de l’arbre' })
}
lancer(SCENE, { affiche: P.dans + 1.2 })
