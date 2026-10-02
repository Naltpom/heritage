/* Chapitre « Lire l'arbre » : la place de chacun, les couleurs des branches,
   les deux vues, le volet d'une fiche, la ligne directe, « Me trouver ». */
minuter([1.7, 0.5, 0.4, 0.75, 0.4, 1.1], [0.5, 0.45, 0.6, 0.45, 0.6, 0.6], 2.0)

const A = arbreFictif()
const simone = A.parSosa(5)
const LIGNE = ligneDirecteDe(A, simone)
const dist = (n) => Math.hypot(n.cx - A.vous.cx, (n.cy - A.vous.cy) * 1.3)
const LOIN = Math.max(...A.N.map(dist))

const P = {
  carton: 0.1, arbre: 1.35,
  centre: M(0, 5), parents: M(0, 7), enfants: M(0, 10),
  pere: M(1, 8), mere: M(1, 13),
  ensemble: M(2, 0) - 0.15, fiches: M(2, 6) - 0.15,
  cadre: V[3].debut - 0.75, touche: M(3, 2) - 0.05,
  coche: M(4, 0) + 0.05, ancetres: M(4, 6), descendants: M(4, 9),
  perdu: V[5].debut - 1.0, moi: M(5, 2) + 0.05,
  fin: V[5].fin + 0.6,
}
son(P.arbre, 'eclosion'); son(P.pere - 0.05, 'note1'); son(P.pere + 0.25, 'note2'); son(P.mere - 0.05, 'note3'); son(P.mere + 0.25, 'note4')
son(P.ensemble, 'tape'); son(P.ensemble + 0.1, 'recul'); son(P.fiches, 'tape'); son(P.fiches + 0.1, 'souffle')
son(P.cadre, 'souffle', 0.5); son(P.touche, 'tape'); son(P.touche + 0.1, 'pop'); son(P.coche, 'tape'); son(P.ancetres, 'note2'); son(P.descendants, 'note3')
son(P.perdu, 'souffle', 0.6); son(P.moi, 'tape'); son(P.moi + 0.1, 'souffle'); son(P.fin, 'final')

const BASE = { s: 1, fx: 540, fy: 1100 }
function camera(t) {
  return trajet(t, BASE, [
    { a: P.ensemble, b: P.ensemble + 0.7, s: 0.56, fy: 720 },
    { a: P.fiches, b: P.fiches + 0.6, s: 1, fy: 1100 },
    { a: P.cadre, b: P.cadre + 0.6, s: 1.25, fx: 540, fy: 1250 },
    { a: P.ancetres - 0.2, b: P.ancetres + 0.5, s: 0.95, fx: 540, fy: 1080 },
    { a: P.perdu, b: P.perdu + 0.8, s: 2.1, fx: 900, fy: 760 },
    { a: P.moi + 0.05, b: P.moi + 0.75, s: 1.15, fx: 540, fy: 1280 },
  ])
}
const coulQuart = (q, t) => (q === 'A' || q === 'B' ? prog(t, P.pere - 0.05, P.pere + 0.25) : prog(t, P.mere - 0.05, P.mere + 0.25))

SCENE = (t) => {
  fondGrille()
  const cam = camera(t)
  const ens = eio(prog(t, P.ensemble, P.ensemble + 0.5)) * (1 - eio(prog(t, P.fiches, P.fiches + 0.5)))
  const ligne = eout(prog(t, P.ancetres - 0.3, P.ancetres + 0.2)) * (1 - eout(prog(t, P.perdu, P.perdu + 0.4)))
  const estompe = (n) => (LIGNE.has(n) ? 0 : ligne)
  x.save(); x.beginPath(); x.rect(0, 600, W, H - 600); x.clip(); filmer(cam)
  blocsLointains(A, ens)
  traitsDe(A, { k: eout(prog(t, P.arbre + 0.45, P.arbre + 1.0)), alpha: 1 - ens * 0.6, estompe })
  for (const n of A.N) {
    const d = P.arbre + 0.8 * Math.pow(dist(n) / LOIN, 0.8)
    const k = prog(t, d, d + 0.3)
    if (k <= 0) continue
    // les rôles s'allument quand la voix les nomme
    let s = 0
    if (n.vous) s = Math.max(s, 1 - prog(t, P.centre + 0.6, P.centre + 1.0)) * (t > P.centre - 0.1 ? 1 : 0)
    if (n.g === 1) s = Math.max(s, (t > P.parents - 0.05 ? 1 : 0) * (1 - prog(t, P.parents + 0.6, P.parents + 1.0)))
    if (n.enfant) s = Math.max(s, (t > P.enfants - 0.05 ? 1 : 0) * (1 - prog(t, P.enfants + 0.6, P.enfants + 1.0)))
    if (n === simone) s = Math.max(s, eout(prog(t, P.touche, P.touche + 0.2)) * (1 - prog(t, P.perdu, P.perdu + 0.3)))
    if (n.vous && t > P.moi) s = Math.max(s, eout(prog(t, P.moi + 0.5, P.moi + 0.8)))
    // les ancêtres puis les descendants de Simone s'allument à leur mot
    if (LIGNE.has(n) && n !== simone && ligne > 0) {
      const quand = n.g > simone.g ? P.ancetres : P.descendants
      s = Math.max(s, 0.8 * eout(prog(t, quand, quand + 0.25)) * (1 - prog(t, P.perdu, P.perdu + 0.3)))
    }
    ficheDe(n, { ens, alpha: clamp(k * 3), echelle: eback(k, 2.2), couleurs: n.q ? coulQuart(n.q, t) : 1, surligne: s, estompe: estompe(n) })
  }
  x.restore()
  // la bande des branches, comme en haut de l'arbre de l'application
  bandeBranches(t, cam, ens)
  voileBas()
  barreArbre({ mode: t > P.ensemble && t < P.fiches ? 'Ensemble' : 'Fiches', alpha: eout(prog(t, P.arbre, P.arbre + 0.4)), presseMoi: pression(P.moi) })
  doigt(108, 551, P.ensemble); doigt(420, 551, P.fiches)
  const [sx, sy] = versEcran(camera(P.touche), simone.cx, simone.cy)
  doigt(sx, sy, P.touche)
  // le volet de Simone
  const ouvre = eback(prog(t, P.touche + 0.1, P.touche + 0.45), 1.3)
  const replie = eio(prog(t, P.ancetres - 0.35, P.ancetres + 0.15)), ferme = eio(prog(t, P.perdu - 0.1, P.perdu + 0.25))
  const y0 = voletFiche(ouvre * (1 - ferme) * lerp(1, 0.2, replie), {
    nom: 'Simone Bernard', dates: '° 3.5.1935   † 18.1.2019', branche: 'B', ligne: eout(prog(t, P.coche, P.coche + 0.25)), presseLigne: pression(P.coche),
    proches: [['PARENTS', null], ['Auguste Bernard', '° 1904', '† 1977'], ['Rose Faure', '° 1907', '† 1990'], ['ENFANTS', null], ['Anne Martin', '° 1958', ''], ['Paul Martin', '° 1961', '']],
  })
  if (y0 !== null && t < P.ancetres) doigt(92, H - 820 + 345, P.coche)
  doigt(915, 550, P.moi)
  for (let n = 0; n < 6; n++) legende(n)
  carton('Lire l’arbre', P.carton, P.arbre + 0.1, { numero: 'AIDE' })
  emblemeFinal(P.fin, { sousTitre: 'Lire l’arbre' })
}

function bandeBranches(t, cam, ens) {
  for (let q = 0; q < 4; q++) {
    const k = coulQuart('ABCD'[q], t)
    if (k <= 0) continue
    const fin = (1 - prog(t, P.cadre, P.cadre + 0.3)) * (1 - ens)
    if (fin <= 0) continue
    const [x0] = versEcran(cam, 40 + q * 250, 0), [x1] = versEcran(cam, 40 + (q + 1) * 250, 0)
    x.save(); x.globalAlpha = fin
    x.fillStyle = COUL['ABCD'[q]]; x.fillRect(x0 + 6, 610, (x1 - x0 - 12) * eout(k), 6)
    const sc = lerp(1.3, 1, eout(k))
    x.translate((x0 + x1) / 2, 645); x.scale(sc, sc)
    texte(BRANCHES[q].nom, 0, 0, { taille: 30, poids: 800, coul: COUL['ABCD'[q]], alpha: clamp(k * 3) })
    texte(BRANCHES[q].role, 0, 34, { taille: 20, poids: 500, coul: COUL.attenue, alpha: clamp(k * 3) * (1 - ens) })
    x.restore()
  }
}
lancer(SCENE, { affiche: P.mere + 0.8 })
