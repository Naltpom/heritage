/* ------------------------------------------------------------------------ */
/* Les composants de la vidéo verticale.                                     */
/*  - RUSHES : la vraie application mobile filmée (outils/rushes.mjs), en     */
/*    1080 × 1920 natif : un rush est à l'échelle de l'écran ;                */
/*  - le PROJECTEUR, le TOUCHER : mettre en valeur un élément réel ;         */
/*  - les SOUS-TITRES, dans la zone de sécurité verticale ;                  */
/*  - le LOGO de l'application (app/public/favicon.svg) ;                    */
/*  - les objets de la mémoire dispersée : photo, téléphone, dossier, note,  */
/*    boîte, document.                                                       */
/* ------------------------------------------------------------------------ */

/* ------------------------------------------------------------------ les rushes */
const RUSH = window.RUSHES ?? {}
const CACHE = new Map()
const EN_ATTENTE = []
function imageRush(nom, t) {
  const m = RUSH[nom]
  if (!m) return null
  const i = Math.max(0, Math.min(m.images.length - 1, Math.round((t - m.debut) * m.fps)))
  const chemin = `sortie/rushes/${nom}/${String(i).padStart(5, '0')}.jpg`
  const c = CACHE.get(chemin)
  if (c instanceof Image) return { im: c, i, m }
  if (!c) {
    const p = new Promise((ok) => { const im = new Image(); im.onload = () => { CACHE.set(chemin, im); ok() }; im.onerror = () => { CACHE.set(chemin, null); ok() }; im.src = chemin })
    CACHE.set(chemin, p); EN_ATTENTE.push(p)
  } else if (c instanceof Promise) EN_ATTENTE.push(c)
  return null
}
function nettoyerCache(garder = 240) {
  if (CACHE.size <= garder) return
  const cles = [...CACHE.keys()]
  for (const k of cles.slice(0, cles.length - garder)) if (CACHE.get(k) instanceof Image) CACHE.delete(k)
}
/** Le rectangle enregistré d'un élément de l'interface (pixels du rush = pixels de l'écran). */
function repere(nom, t, cle) {
  const m = RUSH[nom]
  if (!m) return null
  const i = Math.max(0, Math.min(m.images.length - 1, Math.round((t - m.debut) * m.fps)))
  const r = m.images[i]?.[cle]
  return r ? { x: r[0], y: r[1], w: r[2], h: r[3] } : null
}
/**
 * Dessine un rush. `cam` = {cx, cy, z} : le point du rush au centre de l'écran, et le zoom
 * (1 : plein écran). Rend la projection rush → écran.
 */
function dessinerRush(nom, t, { cam = { cx: W / 2, cy: H / 2, z: 1 }, a = 1, flou = 0, lum = 1, clip = null } = {}) {
  const versEcran = (px, py) => [(px - cam.cx) * cam.z + W / 2, (py - cam.cy) * cam.z + H / 2]
  const r = imageRush(nom, t)
  if (!r || a <= 0) return versEcran
  x.save(); x.globalAlpha *= a
  if (clip) { x.beginPath(); x.rect(clip.x, clip.y, clip.w, clip.h); x.clip() }
  if (flou > 0.3 || lum !== 1) x.filter = `${flou > 0.3 ? `blur(${flou.toFixed(1)}px) ` : ''}brightness(${lum})`
  const [x0, y0] = versEcran(0, 0)
  x.drawImage(r.im, x0, y0, W * cam.z, H * cam.z)
  x.restore()
  return versEcran
}
function rectEcran(ve, r, marge = 0) {
  if (!r) return null
  const [a, b] = ve(r.x - marge, r.y - marge), [c, d] = ve(r.x + r.w + marge, r.y + r.h + marge)
  return { x: a, y: b, w: c - a, h: d - b }
}
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
/** Un cadrage centré sur un rectangle, borné aux bords du rush (rien ne sort de l'image). */
function cadrerSur(r, z, dx = 0, dy = 0) {
  if (!r) return { cx: W / 2, cy: H / 2, z: 1 }
  return { cx: clamp(r.x + r.w / 2 + dx, W / 2 / z, W - W / 2 / z), cy: clamp(r.y + r.h / 2 + dy, H / 2 / z, H - H / 2 / z), z }
}
/** Un fondu enchaîné entre deux plans : `avant(a)`, puis `apres(a)`. */
function enchaine(t, t0, duree, avant, apres) {
  const k = eio(prog(t, t0, t0 + duree))
  if (k < 1) avant(1 - k)
  if (k > 0) apres(k)
}
/** La transition rapide de l'exploration : le plan entrant monte du bas, coins arrondis, l'ancien recule. */
function glissement(t, t0, duree, avant, apres) {
  const k = eio(prog(t, t0, t0 + duree))
  if (k < 1) { x.save(); x.globalAlpha = 1 - 0.6 * k; avant(k); x.restore() }
  if (k > 0) {
    const y = (1 - k) * H * 0.92
    x.save(); x.translate(0, y)
    x.shadowColor = 'rgba(0,0,0,0.6)'; x.shadowBlur = 60
    rr(0, 0, W, H + 40, 46 * (1 - k)); x.fillStyle = C.fond; x.fill(); x.shadowColor = 'transparent'
    rr(0, 0, W, H + 40, 46 * (1 - k)); x.clip()
    apres(k)
    x.restore()
  }
}

/* ------------------------------------------------------------------ mettre en valeur */
function projecteur(r, k, { ombre = 0.5, marge = 12, rayon = 14, lisere = true } = {}) {
  if (!r || k <= 0) return
  const X = r.x - marge, Y = r.y - marge, L = r.w + 2 * marge, Ht = r.h + 2 * marge
  x.save()
  if (ombre > 0) {
    x.beginPath(); x.rect(0, 0, W, H)
    x.moveTo(X + rayon, Y); x.arcTo(X + L, Y, X + L, Y + Ht, rayon); x.arcTo(X + L, Y + Ht, X, Y + Ht, rayon); x.arcTo(X, Y + Ht, X, Y, rayon); x.arcTo(X, Y, X + L, Y, rayon); x.closePath()
    x.fillStyle = `rgba(8,8,10,${ombre * k})`; x.fill('evenodd')
  }
  if (lisere) { rr(X, Y, L, Ht, rayon); x.shadowColor = alpha(C.accent, 0.6 * k); x.shadowBlur = 28; x.strokeStyle = alpha(C.accent, 0.9 * k); x.lineWidth = 3; x.stroke() }
  x.restore()
}
/** Un toucher de doigt : un disque doux qui s'enfonce, puis une onde. */
function toucher(px, py, t, instant) {
  const avant = prog(t, instant - 0.35, instant - 0.02), apres = prog(t, instant + 0.1, instant + 0.55)
  if (avant <= 0 || apres >= 1) return
  x.save(); x.globalAlpha = eout(avant) * (1 - apres)
  const onde = prog(t, instant, instant + 0.55)
  if (onde > 0 && onde < 1) { x.beginPath(); x.arc(px, py, 46 + 70 * eout(onde), 0, Math.PI * 2); x.strokeStyle = `rgba(255,255,255,${0.45 * (1 - onde)})`; x.lineWidth = 4; x.stroke() }
  const appui = t >= instant - 0.04 && t <= instant + 0.12
  x.beginPath(); x.arc(px, py, appui ? 38 : 46, 0, Math.PI * 2); x.fillStyle = 'rgba(255,255,255,0.22)'; x.fill()
  x.strokeStyle = 'rgba(255,255,255,0.7)'; x.lineWidth = 3; x.stroke()
  x.restore()
}
const centreDe = (r) => (r ? [r.x + r.w / 2, r.y + r.h / 2] : [W / 2, H / 2])

/* ------------------------------------------------------------------ sous-titres */
/**
 * Les sous-titres : la ligne du script en cours, sans ses audio tags, dans la zone de
 * sécurité verticale (au-dessus des légendes et des boutons des applications, à l'écart
 * des icônes de droite). Le mot dit s'éclaire à peine : on lit au rythme de la voix.
 */
const SOUS_TITRES = { y: 1395, largeur: 860, taille: 54 }
function sousTitres(t) {
  for (let i = 0; i < LIGNES.length - 1; i++) { // (pas de sous-titre pour « Heritage. » : le logo le dit)
    const l = LIGNES[i], suivante = LIGNES[i + 1]
    const debut = l.debut - 0.12, fin = suivante ? Math.min(l.fin + 0.45, suivante.debut - 0.08) : l.fin + 1.0
    if (t < debut || t > fin) continue
    const a = Math.min(eout(prog(t, debut, debut + 0.18)), 1 - prog(t, fin - 0.15, fin))
    const { taille, largeur } = SOUS_TITRES
    const lignes = couper(l.texte, taille, 600, largeur)
    const hl = taille * 1.3, hTot = lignes.length * hl
    const y0 = SOUS_TITRES.y - hTot / 2
    // un voile sombre derrière, pour lire sur n'importe quelle image
    const lMax = Math.max(...lignes.map((s) => mesure(s, taille, 600)))
    x.save(); x.globalAlpha = a * 0.78
    rr(W / 2 - lMax / 2 - 30, y0 - 20, lMax + 60, hTot + 40, 26); x.fillStyle = 'rgba(12,12,14,0.86)'; x.fill()
    x.restore()
    // les mots s'éclairent au fil de la ligne (au prorata des caractères entre son début et sa fin réels)
    const total = l.texte.length
    let vus = 0
    lignes.forEach((s, li) => {
      let px = W / 2 - mesure(s, taille, 600) / 2
      const py = y0 + hl * (li + 0.5)
      for (const m of s.split(' ')) {
        const tm = l.debut + (l.fin - l.debut) * (vus / total)
        const dit = t >= tm - 0.02, eclat = 1 - prog(t, tm, tm + 0.4)
        texte(m, px, py, { taille, poids: 600, coul: dit ? melange(C.texte, C.accent, eclat * 0.55) : 'rgba(232,230,226,0.5)', a })
        px += mesure(m + ' ', taille, 600)
        vus += m.length + 1
      }
    })
  }
}

/* ------------------------------------------------------------------ le logo */
function logo(cx, cy, taille, k = 1, { coul = C.accent, a = 1 } = {}) {
  if (a <= 0 || k <= 0) return
  const u = taille / 32, P = (px, py) => [cx + (px - 16) * u, cy + (py - 16) * u]
  x.save(); x.globalAlpha *= a; x.strokeStyle = coul; x.lineWidth = 2 * u; x.lineCap = 'round'
  const trait = (a1, b1, a2, b2, kk) => { if (kk <= 0) return; const [p1, q1] = P(a1, b1), [p2, q2] = P(a2, b2); x.beginPath(); x.moveTo(p1, q1); x.lineTo(lerp(p1, p2, kk), lerp(q1, q2, kk)); x.stroke() }
  trait(16, 28, 16, 13, eout(prog(k, 0, 0.35)))
  trait(16, 13, 7, 13, eout(prog(k, 0.3, 0.55))); trait(16, 13, 25, 13, eout(prog(k, 0.3, 0.55)))
  trait(7, 13, 7, 9, eout(prog(k, 0.5, 0.65))); trait(25, 13, 25, 9, eout(prog(k, 0.5, 0.65)))
  x.fillStyle = coul
  for (const [px, py, r, d] of [[16, 6, 3, 0.55], [7, 7, 2.5, 0.65], [25, 7, 2.5, 0.72]]) {
    const kk = eout(prog(k, d, d + 0.25)); if (kk <= 0) continue
    const [qx, qy] = P(px, py); x.beginPath(); x.arc(qx, qy, r * u * kk, 0, Math.PI * 2); x.fill()
  }
  x.restore()
}
const motHeritage = (cx, cy, taille, a = 1) => texte('Heritage', cx, cy, { taille, poids: 700, align: 'center', a, espacement: -0.01 * taille })

/* ------------------------------------------------------------------ les objets de la mémoire */
/** Un tirage : une image posée, ombre douce, légère rotation, flou de profondeur. */
function tirage(im, cx, cy, l, { rot = 0, a = 1, flou = 0, rognage = null } = {}) {
  if (!im || a <= 0) return
  const [sx, sy, sw, sh] = rognage ?? [0, 0, im.width, im.height]
  const h = l * sh / sw
  x.save(); x.globalAlpha *= a; x.translate(cx, cy); x.rotate(rot)
  x.shadowColor = 'rgba(0,0,0,0.5)'; x.shadowBlur = 40; x.shadowOffsetY = 16
  if (flou > 0.4) x.filter = `blur(${flou.toFixed(1)}px)`
  x.drawImage(im, sx, sy, sw, sh, -l / 2, -h / 2, l, h)
  x.restore()
}
/** Un bout de papier (nom, date, note) écrit à la main. */
function note(s, cx, cy, { rot = 0, a = 1, taille = 52, fond = '#e8dcc2', famille = PLUME, flou = 0, coul = '#3b2f22' } = {}) {
  if (a <= 0) return
  const l = mesure(s, taille, 400, famille) + taille * 1.2, h = taille * 1.9
  x.save(); x.globalAlpha *= a; x.translate(cx, cy); x.rotate(rot)
  if (flou > 0.4) x.filter = `blur(${flou.toFixed(1)}px)`
  x.shadowColor = 'rgba(0,0,0,0.45)'; x.shadowBlur = 26; x.shadowOffsetY = 10
  x.fillStyle = fond; x.fillRect(-l / 2, -h / 2, l, h); x.shadowColor = 'transparent'
  texte(s, 0, 4, { taille, famille, coul, align: 'center', poids: 400 })
  x.restore()
}
/** Un téléphone, seulement comme objet : un bloc sombre, un écran plein de vieilles photos. */
function telephone(cx, cy, l, { rot = 0, a = 1, photos = [], defile = 0 } = {}) {
  if (a <= 0) return
  const h = l * 2.05, r = l * 0.13
  x.save(); x.globalAlpha *= a; x.translate(cx, cy); x.rotate(rot)
  x.shadowColor = 'rgba(0,0,0,0.6)'; x.shadowBlur = 50; x.shadowOffsetY = 20
  rr(-l / 2, -h / 2, l, h, r); x.fillStyle = '#0c0d0f'; x.fill(); x.shadowColor = 'transparent'
  x.strokeStyle = 'rgba(255,255,255,0.12)'; x.lineWidth = 2; x.stroke()
  const m = l * 0.045
  rr(-l / 2 + m, -h / 2 + m, l - 2 * m, h - 2 * m, r * 0.8); x.save(); x.clip()
  x.fillStyle = '#16171a'; x.fillRect(-l / 2, -h / 2, l, h)
  // une galerie : trois colonnes de vieilles photos
  const c = (l - 2 * m) / 3
  for (let i = 0; i < 18; i++) {
    const im = photos[i % photos.length]
    if (!im) continue
    const px = -l / 2 + m + (i % 3) * c, py = -h / 2 + m + Math.floor(i / 3) * c - defile
    const s = Math.max(c / im.width, c / im.height)
    x.save(); x.beginPath(); x.rect(px + 1, py + 1, c - 2, c - 2); x.clip()
    x.drawImage(im, px + c / 2 - im.width * s / 2, py + c / 2 - im.height * s / 2, im.width * s, im.height * s); x.restore()
  }
  x.restore(); x.restore()
}
/** Un dossier cartonné, d'où dépassent des actes. */
function dossier(cx, cy, l, { rot = 0, a = 1, actes = [] } = {}) {
  if (a <= 0) return
  const h = l * 0.72
  x.save(); x.globalAlpha *= a; x.translate(cx, cy); x.rotate(rot)
  x.shadowColor = 'rgba(0,0,0,0.55)'; x.shadowBlur = 40; x.shadowOffsetY = 16
  x.fillStyle = '#9c7748'; rr(-l / 2, -h / 2, l, h, 10); x.fill(); x.shadowColor = 'transparent'
  actes.forEach((im, i) => { if (im) { const al = l * 0.82; x.save(); x.rotate((i - 1) * 0.05); x.drawImage(im, 0, 0, im.width, im.width * 0.6, -al / 2, -h / 2 - 50 + i * 16, al, al * 0.6); x.restore() } })
  x.fillStyle = '#b5905c'; rr(-l / 2, -h / 2 + h * 0.28, l, h * 0.72, 10); x.fill()
  x.fillStyle = 'rgba(60,40,20,0.5)'; x.font = police(l * 0.06, 600, SERIF); x.textAlign = 'center'; x.fillText('PAPIERS DE FAMILLE', 0, h * 0.12)
  x.restore()
}
/** Une boîte en carton (« un acte retrouvé dans une boîte »). */
function boite(cx, cy, l, { a = 1, ouverture = 1 } = {}) {
  if (a <= 0) return
  const h = l * 0.5
  x.save(); x.globalAlpha *= a; x.translate(cx, cy)
  x.shadowColor = 'rgba(0,0,0,0.55)'; x.shadowBlur = 50; x.shadowOffsetY = 20
  x.fillStyle = '#7d5f3a'; x.fillRect(-l / 2, -h / 2, l, h); x.shadowColor = 'transparent'
  x.fillStyle = '#5d4529'; x.fillRect(-l / 2 + 14, -h / 2 + 10, l - 28, 26)
  x.save(); x.translate(-l / 2, -h / 2); x.rotate(-1.2 * ouverture); x.fillStyle = '#8a6a42'; x.fillRect(0, -h * 0.35, l * 0.5, h * 0.35); x.restore()
  x.restore()
}
/** Une forme d'onde vivante. */
function onde(x0, cy, l, h, t, { barres = 40, coul = C.accent, a = 1, energie = 1 } = {}) {
  x.save(); x.globalAlpha *= a; x.fillStyle = coul
  const pas = l / barres
  for (let i = 0; i < barres; i++) {
    const n = 0.5 + 0.5 * Math.sin(t * 7.3 + i * 0.9) * Math.sin(t * 3.1 + i * 0.37) + 0.25 * Math.sin(t * 13 + i * 2.1)
    const v = clamp(0.12 + 0.88 * Math.abs(n) * energie * (0.55 + 0.45 * hash(i * 31)))
    const bh = Math.max(4, v * h)
    rr(x0 + i * pas + pas * 0.2, cy - bh / 2, pas * 0.6, bh, pas * 0.3); x.fill()
  }
  x.restore()
}
function avatar(cx, cy, r, initiales, coul, { a = 1 } = {}) {
  x.save(); x.globalAlpha *= a
  x.beginPath(); x.arc(cx, cy, r, 0, Math.PI * 2); x.fillStyle = melange(C.panneau, coul, 0.28); x.fill()
  x.strokeStyle = alpha(coul, 0.7); x.lineWidth = 3; x.stroke()
  texte(initiales, cx, cy + 1, { taille: r * 0.72, poids: 600, coul, align: 'center' })
  x.restore()
}
/** Une fiche de l'arbre au format Compact (pour une fiche proposée, en attente). */
function ficheArbre(p, x0, y0, l, h, { a = 1, attente = false } = {}) {
  if (a <= 0) return
  const s = h / 56
  x.save(); x.globalAlpha *= a
  rr(x0, y0, l, h, 6 * s); x.fillStyle = p.sexe === 'feminin' ? C.femme : C.homme; x.fill()
  x.save(); rr(x0, y0, l, h, 6 * s); x.clip(); x.fillStyle = C[p.branch] ?? C.tr; x.fillRect(x0, y0, 4 * s, h); x.restore()
  x.fillStyle = p.sexe === 'feminin' ? '#6a4d55' : '#4b5a74'
  x.beginPath(); x.arc(x0 + 26 * s, y0 + h / 2 - 4 * s, 7 * s, 0, Math.PI * 2); x.fill()
  x.beginPath(); x.ellipse(x0 + 26 * s, y0 + h / 2 + 13 * s, 12 * s, 9 * s, 0, Math.PI, 0); x.fill()
  texte(`${p.prenom} ${p.nom}`, x0 + 50 * s, y0 + h / 2 - 7 * s, { taille: 12.5 * s, poids: 600 })
  texte(p.dates ?? '', x0 + 50 * s, y0 + h / 2 + 9 * s, { taille: 10.5 * s, famille: MONO, coul: C.attenue })
  if (attente) { x.setLineDash([6 * s, 4 * s]); rr(x0, y0, l, h, 6 * s); x.strokeStyle = C.accent; x.lineWidth = 2 * s; x.stroke(); x.setLineDash([]) }
  x.restore()
}
function pastille(s, cx, cy, { taille = 30, coul = C.accent, a = 1 } = {}) {
  const l = mesure(s, taille, 600) + taille * 1.3, h = taille * 1.75
  x.save(); x.globalAlpha *= a
  rr(cx - l / 2, cy - h / 2, l, h, h / 2); x.fillStyle = 'rgba(20,21,23,0.92)'; x.fill(); x.strokeStyle = alpha(coul, 0.55); x.lineWidth = 2; x.stroke()
  texte(s, cx, cy + 1, { taille, poids: 600, coul, align: 'center' })
  x.restore()
}
