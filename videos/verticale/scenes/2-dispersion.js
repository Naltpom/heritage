/* ------------------------------------------------------------------------ */
/* SCÈNE 2 — DISPERSION                                                      */
/* « Et plus l'arbre grandit, plus l'histoire se disperse. Des photos sur   */
/* les téléphones. Des actes dans des dossiers. Des noms et des dates un peu */
/* partout. Et surtout… plein de choses que personne n'a vraiment envie de  */
/* voir disparaître. »                                                       */
/*                                                                           */
/* Des objets flottent tout autour de l'écran vertical, désordonnés ; sur   */
/* « disparaître », certains s'éteignent sur les bords ; puis tout se       */
/* regroupe au centre.                                                       */
/* ------------------------------------------------------------------------ */
ASSETS_A_CHARGER.push('photos/lieu-2.jpg', 'photos/lieu-3.jpg', 'photos/lieu-4.jpg', 'photos/lieu-6.jpg', 'photos/dos-1.jpg', 'photos/dos-2.jpg', 'photos/dos-3.jpg',
  'documents/bapteme-1783.jpg', 'documents/deces-1901.jpg', 'documents/acte-mauron-1903.jpg')

const OBJETS = [
  // des photos sur les téléphones
  { genre: 'telephone', t: 'telephones', x: 300, y: 560, l: 330, rot: -0.12 },
  { genre: 'photo', t: 'telephones', src: 'photos/dos-2.jpg', x: 820, y: 360, l: 330, rot: 0.1, d: 0.25 },
  // des actes dans des dossiers
  { genre: 'dossier', t: 'dossiers', x: 760, y: 980, l: 470, rot: 0.08 },
  { genre: 'photo', t: 'dossiers', src: 'documents/bapteme-1783.jpg', x: 250, y: 1150, l: 420, rot: -0.07, d: 0.3, rognage: [0, 0, 1600, 900] },
  // des noms et des dates un peu partout
  { genre: 'note', t: 'notes', s: 'Mathurin Perrin', x: 330, y: 300, rot: -0.08 },
  { genre: 'note', t: 'notes', s: '° 1834', x: 840, y: 700, rot: 0.1, mono: true, d: 0.15 },
  { genre: 'note', t: 'notes', s: 'Perrine Le Gall', x: 660, y: 1330, rot: 0.06, d: 0.3 },
  { genre: 'note', t: 'notes', s: '† 4 février 1901', x: 300, y: 1560, rot: -0.05, mono: true, d: 0.45 },
  { genre: 'note', t: 'notes', s: 'mariés en 1890', x: 820, y: 1700, rot: 0.09, mono: true, d: 0.6 },
  { genre: 'photo', t: 'notes', src: 'photos/lieu-3.jpg', x: 190, y: 860, l: 300, rot: 0.12, d: 0.5 },
  // plein de choses (celles qui s'éteignent sur « disparaître »)
  { genre: 'photo', t: 'disperse', src: 'photos/lieu-6.jpg', x: 900, y: 1500, l: 280, rot: -0.1, d: 0.3, perdu: true },
  { genre: 'photo', t: 'disperse', src: 'photos/dos-3.jpg', x: 160, y: 1800, l: 300, rot: 0.08, d: 0.6, perdu: true },
  { genre: 'note', t: 'disperse', s: 'Julienne Guého', x: 870, y: 180, rot: 0.07, d: 0.9, perdu: true },
  { genre: 'photo', t: 'disperse', src: 'documents/deces-1901.jpg', x: 130, y: 120, l: 360, rot: -0.09, d: 1.1, perdu: true, rognage: [0, 0, 1600, 900] },
]
const PHOTOS_TELEPHONE = ['photos/lieu-1.jpg', 'photos/lieu-2.jpg', 'photos/dos-1.jpg', 'photos/lieu-4.jpg', 'photos/lieu-3.jpg', 'photos/dos-2.jpg', 'photos/lieu-6.jpg', 'photos/dos-3.jpg']

/** La place d'un objet à l'instant t (il flotte, puis se regroupe au centre). Utilisée aussi par la scène 3. */
function etatObjet(o, i, t) {
  const t0 = R[o.t] + (o.d ?? 0)
  const k = eout(prog(t, t0, t0 + 0.9))
  const flotte = Math.sin(t * 0.7 + i * 1.7) * 14
  const regroupe = eio(prog(t, R.regroupe - 0.2 + (i % 5) * 0.06, R.cree + 0.1))
  const cx = lerp(o.x + (1 - k) * (o.x < W / 2 ? -60 : 60), W / 2 + ((i % 3) - 1) * 40, regroupe)
  const cy = lerp(o.y + flotte, H * 0.47 + ((i % 4) - 1.5) * 34, regroupe)
  const perte = o.perdu ? eio(prog(t, R.disparaitre - 0.1 + (i % 3) * 0.2, R.disparaitre + 0.9 + (i % 3) * 0.2)) : 0
  return { k, cx, cy, echelle: lerp(1, 0.42, regroupe), rot: o.rot * (1 - regroupe), a: k * (1 - perte * 0.92), flou: perte * 12, regroupe }
}
function dessinerObjet(o, e, t) {
  if (e.a <= 0.01) return
  x.save(); if (e.flou > 0.5) x.filter = `blur(${e.flou.toFixed(1)}px)`
  if (o.genre === 'telephone') telephone(e.cx, e.cy, o.l * e.echelle, { rot: e.rot, a: e.a, photos: PHOTOS_TELEPHONE.map((n) => IMAGES[n]), defile: (t - R.telephones) * 40 })
  else if (o.genre === 'dossier') dossier(e.cx, e.cy, o.l * e.echelle, { rot: e.rot, a: e.a, actes: ['documents/acte-mauron-1903.jpg', 'documents/acte-josselin-1868.jpg', 'documents/deces-1901.jpg'].map((n) => IMAGES[n]) })
  else if (o.genre === 'note') note(o.s, e.cx, e.cy, { rot: e.rot, a: e.a, taille: (o.mono ? 40 : 56) * e.echelle, famille: o.mono ? MONO : PLUME, fond: o.mono ? '#ddd2b8' : '#e8dcc2' })
  else tirage(IMAGES[o.src], e.cx, e.cy, o.l * e.echelle, { rot: e.rot, a: e.a, rognage: o.rognage })
  x.restore()
}

function sceneDispersion(t) {
  if (!dansScene(t, 'dispersion')) return
  // au moment de la transition (scène 3), c'est elle qui dessine les objets regroupés
  if (t >= R.cree) return
  OBJETS.forEach((o, i) => dessinerObjet(o, etatObjet(o, i, t), t))
}

function sonsDispersion() {
  son(R.disperse - 0.2, 'souffle', 0.5)
  for (const cle of ['telephones', 'dossiers', 'notes']) son(R[cle], 'papier', 0.45)
  son(R.notes + 0.3, 'papier', 0.3); son(R.notes + 0.6, 'papier', 0.25)
  son(R.regroupe, 'souffle', 0.45)
}
