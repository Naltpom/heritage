/* ------------------------------------------------------------------------ */
/* ARBRE — parcourir, remonter, retrouver Joséphine, voir ses proches ;      */
/* puis « chaque information peut être reliée à sa source » : sa fiche, la  */
/* date de naissance, l'onglet Sources, l'acte, et « Vérifié ».              */
/* Tout ce qu'on voit est l'application (rushes `arbre` et `fiche`) ; la     */
/* composition ne fait que cadrer et mettre en valeur.                       */
/* ------------------------------------------------------------------------ */

/** L'union de rectangles (null ignorés). */
function union(...rs) {
  rs = rs.filter(Boolean)
  if (!rs.length) return null
  const x0 = Math.min(...rs.map((r) => r.x)), y0 = Math.min(...rs.map((r) => r.y))
  return { x: x0, y: y0, w: Math.max(...rs.map((r) => r.x + r.w)) - x0, h: Math.max(...rs.map((r) => r.y + r.h)) - y0 }
}
const centre = (r) => (r ? { cx: r.x + r.w / 2, cy: r.y + r.h / 2 } : null)

/** Une caméra de rush qui glisse d'un cadrage à l'autre : [{t, cx, cy, z}]. */
function camRush(t, cles, courbe = eio) {
  let c = cles[0]
  for (let i = 1; i < cles.length; i++) {
    const a = cles[i - 1], b = cles[i]
    if (t <= a.t) break
    const k = courbe(prog(t, a.t, b.t))
    c = { cx: lerp(a.cx, b.cx, k), cy: lerp(a.cy, b.cy, k), z: Math.exp(lerp(Math.log(a.z), Math.log(b.z), k)) }
  }
  return c
}

/* ---- le volet de Joséphine : les cadrages suivent ce que dit la voix ---- */
function camVolet(t) {
  const n = 'arbre'
  const volet = repere(n, R.lieux, 'volet')
  const par = union(repere(n, R.parents, 'parents'), repere(n, R.parents, 'mere'))
  const enf = repere(n, R.enfants, 'enfants'), lieu = repere(n, R.lieux, 'lieux')
  const plein = { cx: 960, cy: 540, z: 1 }
  if (!volet) return plein
  // le volet à droite, la fiche choisie à gauche : 1,5 × suffit à lire le volet
  const z = 1.55, cx = Math.min(volet.x + volet.w / 2 - 330, 1920 - 960 / z)
  const y = (r, d) => clamp((r ? r.y + r.h / 2 : 540) + d, 540 / z, 1080 - 540 / z)
  return camRush(t, [
    { t: R.fiche - 0.35, ...plein },
    { t: R.fiche + 0.45, cx, cy: y(par, 60), z },
    { t: R.enfants - 0.25, cx, cy: y(par, 60), z },
    { t: R.enfants + 0.25, cx, cy: y(enf, 40), z },
    { t: R.lieux - 0.25, cx, cy: y(enf, 40), z },
    { t: R.lieux + 0.25, cx, cy: y(lieu, -20), z },
    { t: R.source - 0.6, cx, cy: y(lieu, -20), z: z * 1.03 },
  ])
}

function sceneArbre(t) {
  /* --- 1. le rush de l'arbre : parcours, recherche, volet --- */
  if (t >= R.parcourir && t < R.source + 0.2) {
    const cam = camVolet(t)
    const ve = dessinerRush('arbre', t, { cam })
    // la recherche : un liseré sur le champ, sans assombrir la liste des résultats
    const rech = rectEcran(ve, repere('arbre', t, 'recherche'), 2)
    projecteur(rech, fenetre(t, R.recherche - 0.1, R.vol + 0.1, 0.25, 0.3), { ombre: 0, rayon: 8 })
    // parents, enfants, lieux de vie
    const enf = repere('arbre', t, 'enfants')
    const zones = [
      [union(repere('arbre', t, 'parents'), repere('arbre', t, 'mere')), R.parents - 0.1, R.enfants - 0.1],
      [enf ? { x: enf.x, y: enf.y, w: 300, h: enf.h + 5 * 21 } : null, R.enfants - 0.05, R.lieux - 0.1],
      [(() => { const l = repere('arbre', t, 'lieux'); return l ? { x: l.x - 120, y: l.y - 26, w: 330, h: l.h + 56 } : null })(), R.lieux - 0.05, R.source - 0.7],
    ]
    for (const [r, a, b] of zones) projecteur(rectEcran(ve, r, 4), fenetre(t, a, b, 0.25, 0.3), { ombre: 0.45, rayon: 8 })
  }
  /* --- 2. la fiche complète : la date, sa source, l'acte --- */
  sceneFiche(t)
}

function sceneFiche(t) {
  const n = 'fiche'
  const m = RUSH[n]
  if (!m || t < m.debut + 0.05 || t > R.pasJuste + 1.4) return
  // on entre dans la fiche par un fondu qui avance légèrement
  const entree = eout(prog(t, R.source - 0.55, R.source + 0.05))
  const nais = repere(n, R.source + 0.2, 'naissance'), onglet = repere(n, R.sourceLien, 'onglet')
  const cit = repere(n, R.acte - 0.1, 'citation'), loupe = repere(n, R.verifiee, 'loupe')
  const plein = { cx: 960, cy: 540, z: 1 }
  const c = (r, z, dx = 0, dy = 0) => (r ? { cx: clamp(r.x + r.w / 2 + dx, 960 / z, 1920 - 960 / z), cy: clamp(r.y + r.h / 2 + dy, 540 / z, 1080 - 540 / z), z } : plein)
  const cam = camRush(t, [
    { t: R.source - 0.55, ...c(nais, 1.08, 220, -60) },
    { t: R.source + 0.5, ...c(nais, 1.32, 260, -40) },
    { t: R.sourceLien - 0.45, ...c(nais, 1.36, 260, -40) },
    { t: R.sourceLien + 0.2, ...c(onglet, 1.2, 300, 200) },
    { t: R.sourceLien + 1.0, ...c(cit, 1.3, 120, 40) },
    { t: R.acte + 0.3, ...c(cit, 1.34, 120, 40) },
    { t: R.acte + 0.75, ...plein },
    { t: R.verifiee - 0.4, ...c(loupe, 1.22, 0, 30) },
    { t: R.pasJuste + 1.4, ...c(loupe, 1.3, 0, 30) },
  ])
  const ve = dessinerRush(n, t, { cam, a: entree })
  projecteur(rectEcran(ve, nais, 6), fenetre(t, R.source + 0.1, R.sourceLien - 0.3, 0.3, 0.3), { ombre: 0.5 })
  projecteur(rectEcran(ve, repere(n, t, 'onglet'), 5), fenetre(t, R.sourceLien - 0.35, R.sourceLien + 0.35, 0.2, 0.25), { ombre: 0.35 })
  const citE = rectEcran(ve, repere(n, t, 'citation'), 0)
  if (citE) projecteur({ x: citE.x - 230, y: citE.y - 70, w: 560, h: citE.h + 90 }, fenetre(t, R.sourceLien + 0.9, R.acte + 0.4, 0.3, 0.25), { ombre: 0.5 })
  // « on le sait » : le badge réel de la fiche (« Vérifié sur un acte ou un registre ») se pose sur l'acte
  badgeVerifie(t, ve)
}

/** Le badge « Vérifié sur un acte ou un registre », découpé dans l'en-tête réel de la fiche. */
function badgeVerifie(t, ve) {
  const k = fenetre(t, R.verifiee - 0.15, R.pasJuste + 1.3, 0.5, 0.5)
  if (k <= 0) return
  const src = imageRush('fiche', R.source - 0.4), b = repere('fiche', R.source - 0.4, 'verifie')
  if (!src || !b) return
  const m = RUSH['fiche'], f = m.largeur / 1920
  const echelle = 2.4, l = b.w * echelle, h = b.h * echelle
  const cx = W / 2, cy = H * 0.8 + (1 - eout(k)) * 30
  x.save(); x.globalAlpha = k
  x.shadowColor = alpha(C.ok, 0.55); x.shadowBlur = 50
  rr(cx - l / 2 - 14, cy - h / 2 - 10, l + 28, h + 20, (h + 20) / 2); x.fillStyle = 'rgba(20,21,23,0.92)'; x.fill()
  x.shadowColor = 'transparent'
  x.drawImage(src.im, (b.x - 2) * f, (b.y - 2) * f, (b.w + 4) * f, (b.h + 4) * f, cx - l / 2, cy - h / 2, l, h)
  x.restore()
}

function sonsArbre() {
  son(R.parcourir, 'souffle', 0.45)
  son(R.remonter + 0.1, 'souffle', 0.35)
  son(R.recherche, 'tic', 0.6)
  for (let i = 0; i < 12; i += 2) son(R.recherche + 0.25 + i * 0.075, 'touche', 0.6)
  son(R.vol - 0.15, 'tic', 0.7)
  son(R.vol, 'souffle', 0.55)
  son(R.parents - 0.1, 'pose', 0.35); son(R.enfants - 0.05, 'pose', 0.3); son(R.lieux - 0.05, 'pose', 0.3)
  son(R.source - 0.5, 'souffle', 0.35)
  son(R.sourceLien - 0.2, 'tic', 0.6)
  son(R.acte + 0.35, 'tic', 0.6)
  son(R.acte + 0.45, 'papier', 0.6)
  son(R.verifiee - 0.1, 'verifie', 1)
}
