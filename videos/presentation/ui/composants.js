/* ------------------------------------------------------------------------ */
/* Les composants de la composition.                                         */
/*                                                                           */
/*  - les RUSHES : la vraie application filmée (outils/rushes.mjs), lue      */
/*    image par image, plein écran ou dans une fenêtre, avec une caméra ;    */
/*  - le PROJECTEUR : ce qui met un élément de l'interface en valeur ;       */
/*  - les répliques de l'application quand on doit la décomposer (une fiche */
/*    de l'arbre, le logo) : mêmes jetons, mêmes formes que app/src.         */
/*                                                                           */
/* Coordonnées : tout rush est ramené à une base 1920 × 1080 (« base »).      */
/* ------------------------------------------------------------------------ */

/* ------------------------------------------------------------------ les rushes */
const RUSH = window.RUSHES ?? {}
const CACHE = new Map() // chemin → Image (chargée) | Promise
const EN_ATTENTE = [] // ce que la passe de dessin courante a demandé et qui n'est pas encore là

function imageRush(nom, t) {
  const m = RUSH[nom]
  if (!m) return null
  const n = m.images.length
  const i = Math.max(0, Math.min(n - 1, Math.round((t - m.debut) * m.fps)))
  const chemin = `sortie/rushes/${nom}/${String(i).padStart(5, '0')}.jpg`
  const c = CACHE.get(chemin)
  if (c instanceof Image) return { im: c, i, m }
  if (!c) {
    const p = new Promise((ok) => { const im = new Image(); im.onload = () => { CACHE.set(chemin, im); ok() }; im.onerror = () => { CACHE.set(chemin, null); ok() }; im.src = chemin })
    CACHE.set(chemin, p)
    EN_ATTENTE.push(p)
  } else if (c instanceof Promise) EN_ATTENTE.push(c)
  return null
}
/** Libère les images loin de l'instant courant (le rendu avance dans l'ordre). */
function nettoyerCache(garder = 260) {
  if (CACHE.size <= garder) return
  const cles = [...CACHE.keys()]
  for (const k of cles.slice(0, cles.length - garder)) if (CACHE.get(k) instanceof Image) CACHE.delete(k)
}
/** Un repère enregistré avec le rush (rectangle d'un élément), ramené à la base 1920. */
function repere(nom, t, cle) {
  const m = RUSH[nom]
  if (!m) return null
  const i = Math.max(0, Math.min(m.images.length - 1, Math.round((t - m.debut) * m.fps)))
  const r = m.images[i]?.[cle]
  if (!r) return null
  const k = 1920 / m.largeur
  return { x: r[0] * k, y: r[1] * k, w: r[2] * k, h: r[3] * k }
}
/**
 * Dessine un rush. `cam` = {cx, cy, z} : le point de la base au centre de l'écran, et le
 * zoom (1 : le rush remplit l'écran). Rend la fonction qui projette un point de la base à l'écran.
 */
function dessinerRush(nom, t, { cam = { cx: 960, cy: 540, z: 1 }, a = 1, flou = 0, lum = 1 } = {}) {
  const r = imageRush(nom, t)
  const versEcran = (px, py) => [(px - cam.cx) * cam.z + W / 2, (py - cam.cy) * cam.z + H / 2]
  if (!r || a <= 0) return versEcran
  x.save(); x.globalAlpha *= a
  if (flou > 0.3 || lum !== 1) x.filter = `${flou > 0.3 ? `blur(${flou}px) ` : ''}brightness(${lum})`
  const [x0, y0] = versEcran(0, 0)
  x.drawImage(r.im, x0, y0, 1920 * cam.z, 1080 * cam.z)
  x.restore()
  return versEcran
}
/** Un rectangle de la base, à l'écran. */
function rectEcran(versEcran, r, marge = 0) {
  if (!r) return null
  const [a, b] = versEcran(r.x - marge, r.y - marge), [c, d] = versEcran(r.x + r.w + marge, r.y + r.h + marge)
  return { x: a, y: b, w: c - a, h: d - b }
}
/** Un rush dans une fenêtre flottante : coins arrondis, liseré, ombre portée. */
function fenetreRush(nom, t, { x: fx, y: fy, l, a = 1, rayon = 14, ombre = 1, lum = 1 } = {}) {
  const h = l * 1080 / 1920
  x.save(); x.globalAlpha *= a
  x.shadowColor = `rgba(0,0,0,${0.55 * ombre})`; x.shadowBlur = 60 * ombre; x.shadowOffsetY = 24 * ombre
  rr(fx, fy, l, h, rayon); x.fillStyle = C.fond; x.fill()
  x.shadowColor = 'transparent'
  x.save(); rr(fx, fy, l, h, rayon); x.clip()
  const r = imageRush(nom, t)
  if (r) { if (lum !== 1) x.filter = `brightness(${lum})`; x.drawImage(r.im, fx, fy, l, h) }
  x.restore()
  rr(fx + 0.5, fy + 0.5, l - 1, h - 1, rayon); x.strokeStyle = alpha('#ffffff', 0.08); x.lineWidth = 1.5; x.stroke()
  x.restore()
  return { x: fx, y: fy, l, h }
}

/* ------------------------------------------------------------------ le projecteur */
/**
 * Met un rectangle de l'écran en valeur : le reste s'assombrit doucement, un liseré
 * d'accent l'entoure. `k` de 0 à 1 (on l'anime par fenêtre).
 */
function projecteur(r, k, { ombre = 0.55, marge = 10, rayon = 10, liseré = true } = {}) {
  if (!r || k <= 0) return
  const X = r.x - marge, Y = r.y - marge, L = r.w + 2 * marge, Ht = r.h + 2 * marge
  x.save()
  x.beginPath(); x.rect(0, 0, W, H)
  x.moveTo(X + rayon, Y); x.arcTo(X + L, Y, X + L, Y + Ht, rayon); x.arcTo(X + L, Y + Ht, X, Y + Ht, rayon); x.arcTo(X, Y + Ht, X, Y, rayon); x.arcTo(X, Y, X + L, Y, rayon); x.closePath()
  x.fillStyle = `rgba(8,8,10,${ombre * k})`; x.fill('evenodd')
  if (liseré) {
    rr(X, Y, L, Ht, rayon)
    x.shadowColor = alpha(C.accent, 0.6 * k); x.shadowBlur = 24
    x.strokeStyle = alpha(C.accent, 0.85 * k); x.lineWidth = 2; x.stroke()
  }
  x.restore()
}

/* ------------------------------------------------------------------ les répliques de l'application */
/** Une silhouette de fiche (app/src/histoire/FichesMonde.vue) : une tête, des épaules. */
function silhouette(cx, cy, r, coul) {
  x.save(); x.fillStyle = coul
  x.beginPath(); x.arc(cx, cy - r * 0.32, r * 0.36, 0, Math.PI * 2); x.fill()
  x.beginPath(); x.ellipse(cx, cy + r * 0.62, r * 0.7, r * 0.52, 0, Math.PI, 0); x.fill()
  x.restore()
}
/**
 * Une fiche de l'arbre au format Compact, comme l'application la dessine : fond selon le
 * sexe, liseré de la branche à gauche, silhouette, nom, dates en chasse fixe.
 * (x0, y0) coin haut-gauche, l × h en pixels écran.
 */
function ficheArbre(p, x0, y0, l, h, { a = 1, surligne = 0, dates = true } = {}) {
  if (a <= 0) return
  const s = h / 56 // l'échelle : une fiche fait 56 de haut dans le monde de l'arbre
  x.save(); x.globalAlpha *= a
  if (surligne > 0) { x.shadowColor = alpha(C.accent, 0.7 * surligne); x.shadowBlur = 30 * s }
  rr(x0, y0, l, h, 6 * s); x.fillStyle = p.sexe === 'feminin' ? C.femme : C.homme; x.fill()
  x.shadowColor = 'transparent'
  x.save(); rr(x0, y0, l, h, 6 * s); x.clip(); x.fillStyle = C[p.branch] ?? C.tronc; x.fillRect(x0, y0, 4 * s, h); x.restore()
  rr(x0 + 0.5, y0 + 0.5, l - 1, h - 1, 6 * s); x.strokeStyle = p.sexe === 'feminin' ? alpha(C.femmeBord, 0.5) : alpha(C.hommeBord, 0.5); x.lineWidth = 1; x.stroke()
  silhouette(x0 + 26 * s, y0 + h / 2 + 2 * s, 18 * s, p.sexe === 'feminin' ? '#6a4d55' : '#4b5a74')
  const nom = `${p.prenom.split(/[ -]/)[0]} ${p.nom}`
  const dispo = l - 54 * s
  const tn = Math.min(12.5 * s, 12.5 * s * dispo / Math.max(1, mesure(nom, 12.5 * s, 600)))
  texte(nom, x0 + 50 * s, y0 + h / 2 - (dates ? 7 * s : 0), { taille: tn, poids: 600, coul: C.texte })
  if (dates) texte(p.vivant ? 'vivant·e' : `${p.naissance} – ${p.deces ?? ''}`, x0 + 50 * s, y0 + h / 2 + 9 * s, { taille: 10.5 * s, famille: MONO, coul: C.attenue })
  if (surligne > 0) { rr(x0, y0, l, h, 6 * s); x.strokeStyle = alpha(C.accent, surligne); x.lineWidth = 2; x.stroke() }
  x.restore()
}
/**
 * Le logo de Heritage (app/public/favicon.svg) : un tronc, une barre, deux branches,
 * trois nœuds. `k` de 0 à 1 : le dessin se trace, puis les nœuds apparaissent.
 */
function logo(cx, cy, taille, k = 1, { coul = C.accent, a = 1 } = {}) {
  if (a <= 0 || k <= 0) return
  const u = taille / 32
  const P = (px, py) => [cx + (px - 16) * u, cy + (py - 16) * u]
  x.save(); x.globalAlpha *= a; x.strokeStyle = coul; x.lineWidth = 2 * u; x.lineCap = 'round'
  const trait = (a1, b1, a2, b2, kk) => { if (kk <= 0) return; const [p1, q1] = P(a1, b1), [p2, q2] = P(a2, b2); x.beginPath(); x.moveTo(p1, q1); x.lineTo(lerp(p1, p2, kk), lerp(q1, q2, kk)); x.stroke() }
  trait(16, 28, 16, 13, eout(prog(k, 0, 0.35)))
  trait(16, 13, 7, 13, eout(prog(k, 0.3, 0.55))); trait(16, 13, 25, 13, eout(prog(k, 0.3, 0.55)))
  trait(7, 13, 7, 9, eout(prog(k, 0.5, 0.65))); trait(25, 13, 25, 9, eout(prog(k, 0.5, 0.65)))
  x.fillStyle = coul
  for (const [px, py, r, d] of [[16, 6, 3, 0.55], [7, 7, 2.5, 0.65], [25, 7, 2.5, 0.72]]) {
    const kk = eout(prog(k, d, d + 0.25))
    if (kk <= 0) continue
    const [qx, qy] = P(px, py); x.beginPath(); x.arc(qx, qy, r * u * kk, 0, Math.PI * 2); x.fill()
  }
  x.restore()
}
/** Le mot « Heritage », comme dans l'en-tête de l'application (Inter, gras, approche serrée). */
function motHeritage(cx, cy, taille, { a = 1, align = 'center' } = {}) {
  texte('Heritage', cx, cy, { taille, poids: 700, align, a, espacement: -0.01 * taille })
}

/* ------------------------------------------------------------------ petits éléments */
/** Une pastille, comme les étiquettes de l'application (rayon 99 px). */
function pastille(s, cx, cy, { taille = 22, coul = C.accent, fond = null, a = 1, align = 'center' } = {}) {
  const l = mesure(s, taille, 600) + taille * 1.3, h = taille * 1.75
  const x0 = align === 'center' ? cx - l / 2 : cx
  x.save(); x.globalAlpha *= a
  rr(x0, cy - h / 2, l, h, h / 2); x.fillStyle = fond ?? alpha(coul, 0.14); x.fill(); x.strokeStyle = alpha(coul, 0.45); x.lineWidth = 1.5; x.stroke()
  texte(s, x0 + l / 2, cy + 1, { taille, poids: 600, coul, align: 'center' })
  x.restore()
  return l
}
/** Une forme d'onde vivante (barres), pilotée par le temps et une graine. */
function onde(x0, cy, l, h, t, { barres = 48, coul = C.accent, a = 1, energie = 1, graine = 1 } = {}) {
  x.save(); x.globalAlpha *= a; x.fillStyle = coul
  const pas = l / barres
  for (let i = 0; i < barres; i++) {
    const n = 0.5 + 0.5 * Math.sin(t * 7.3 + i * 0.9 + graine) * Math.sin(t * 3.1 + i * 0.37) + 0.25 * Math.sin(t * 13 + i * 2.1)
    const v = clamp(0.12 + 0.88 * Math.abs(n) * energie * (0.55 + 0.45 * hash(i * 31 + graine)))
    const bh = Math.max(3, v * h)
    rr(x0 + i * pas + pas * 0.2, cy - bh / 2, pas * 0.6, bh, pas * 0.3); x.fill()
  }
  x.restore()
}
/** Un avatar rond à initiales (les membres de la famille). */
function avatar(cx, cy, r, initiales, coul, { a = 1 } = {}) {
  x.save(); x.globalAlpha *= a
  x.beginPath(); x.arc(cx, cy, r, 0, Math.PI * 2); x.fillStyle = melange(C.panneau, coul, 0.28); x.fill()
  x.strokeStyle = alpha(coul, 0.7); x.lineWidth = 2; x.stroke()
  texte(initiales, cx, cy + 1, { taille: r * 0.72, poids: 600, coul, align: 'center' })
  x.restore()
}
/** Une image (photo, acte) posée comme un tirage : ombre douce, légère rotation. */
function tirage(im, cx, cy, l, { rot = 0, a = 1, ombre = 1, flou = 0, rognage = null } = {}) {
  if (!im || a <= 0) return
  const [sx, sy, sw, sh] = rognage ?? [0, 0, im.width, im.height]
  const h = l * sh / sw
  x.save(); x.globalAlpha *= a; x.translate(cx, cy); x.rotate(rot)
  x.shadowColor = `rgba(0,0,0,${0.5 * ombre})`; x.shadowBlur = 40 * ombre; x.shadowOffsetY = 14 * ombre
  if (flou > 0.3) x.filter = `blur(${flou}px)`
  x.drawImage(im, sx, sy, sw, sh, -l / 2, -h / 2, l, h)
  x.restore()
}
