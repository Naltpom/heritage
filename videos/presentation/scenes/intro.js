/* ------------------------------------------------------------------------ */
/* INTRO — « Dans ma famille, on a un arbre généalogique assez énorme… »     */
/*                                                                           */
/*  1. des fragments flottent (fiches, dates, lieux, une photo, un acte) ;  */
/*  2. sur « arbre », les fiches se rangent et se relient ;                  */
/*  3. elles se posent EXACTEMENT sur les fiches de l'arbre réel (rush        */
/*     intro-recul, position enregistrée image par image), qui prend le     */
/*     relais : on recule jusqu'aux 2 339 personnes, 14 générations ;        */
/*  4. « au départ… un peu partout » : l'arbre se défait en papiers épars ;  */
/*  5. « du coup » : tout converge vers le centre.                           */
/* ------------------------------------------------------------------------ */
const FAM = construireFamille()
const JOS = FAM.vedette, [MAT, PER] = FAM.parentsVedette
const FRANCOIS = FAM.conjointsDe(JOS)[0]
// la famille dessinée : celle dont rushes.mjs a noté la place à l'écran
// (seules celles qui sont à l'écran au moment du relais : les autres n'auraient pas de place où se poser)
const FAMILLE_INTRO = [JOS, MAT, PER, FRANCOIS, ...FAM.enfantsDe(JOS), ...FAM.enfantsDe(MAT).filter((p) => p !== JOS)].filter((p) => {
  const r = repere('intro-recul', RUSH['intro-recul']?.debut ?? 0, 'p' + p.id)
  return r && r.x > -r.w && r.x < W && r.y > -r.h && r.y < H
})
ASSETS_A_CHARGER.push('photos/lieu-1.jpg', 'photos/lieu-2.jpg', 'photos/lieu-3.jpg', 'photos/lieu-4.jpg', 'photos/lieu-6.jpg', 'photos/dos-1.jpg', 'photos/dos-2.jpg', 'photos/dos-3.jpg', 'photos/dos-4.jpg',
  'documents/acte-josselin-1868.jpg', 'documents/bapteme-1783.jpg', 'documents/deces-1901.jpg')

const T_RUSH_INTRO = () => RUSH['intro-recul']?.debut ?? R.recul1 - 0.6

/* ---- 1. les fragments du début : dans la profondeur, ils dérivent vers nous ---- */
const FRAGMENTS_DEBUT = (() => {
  const r = rnd(12)
  const liste = []
  // les fiches de la famille de Joséphine
  FAMILLE_INTRO.forEach((p, i) => liste.push({ genre: 'fiche', p, i }))
  ;[['12 mars 1868', 'date'], ['° 1834', 'date'], ['† 1901', 'date'], ['1890', 'date'], ['Josselin', 'lieu'], ['Guilliers', 'lieu'], ['Ploërmel', 'lieu']].forEach(([s, g]) => liste.push({ genre: g, s }))
  liste.push({ genre: 'photo', src: 'photos/lieu-1.jpg' }, { genre: 'photo', src: 'photos/dos-1.jpg' }, { genre: 'acte', src: 'documents/acte-josselin-1868.jpg' })
  // un ordre d'apparition mêlé, et une place dans l'espace
  const ordre = liste.map((f, i) => [hash(i * 97 + 5), f]).sort((a, b) => a[0] - b[0]).map((e) => e[1])
  ordre.forEach((f, k) => {
    f.apparition = 0.25 + k * 0.13
    const ang = r() * Math.PI * 2, rayon = 90 + r() * 430
    f.x = W / 2 + Math.cos(ang) * rayon * 1.5
    f.y = H / 2 + Math.sin(ang) * rayon * 0.75
    f.z = f.genre === 'fiche' ? 1.15 + r() * 0.9 : 0.9 + r() * 1.6
    f.rot = f.genre === 'photo' || f.genre === 'acte' ? (r() - 0.5) * 0.3 : 0
  })
  return ordre
})()

/** La place d'une fiche de la famille dans l'arbre réel, à l'instant t du rush (base = écran). */
function placeReelle(p, t) { return repere('intro-recul', Math.max(t, T_RUSH_INTRO()), 'p' + p.id) }

function dessinerFragmentsDebut(t) {
  const tRush = T_RUSH_INTRO()
  const range = eio(prog(t, R.liens - 0.2, R.liens + 1.5)) // les fiches se rangent
  const relais = prog(t, tRush, tRush + 1.0) // le rush prend le relais
  if (relais >= 1) return
  // la caméra avance doucement dans la profondeur
  const avance = t * 0.035
  for (const f of FRAGMENTS_DEBUT) {
    const k = eout(prog(t, f.apparition, f.apparition + 0.9))
    if (k <= 0) continue
    const z = Math.max(0.35, f.z - avance)
    const echelle = 1 / z
    const px = W / 2 + (f.x - W / 2) * echelle, py = H / 2 + (f.y - H / 2) * echelle + (1 - k) * 18
    const flou = Math.abs(z - 1.1) * 3.2
    if (f.genre === 'fiche') {
      const cible = placeReelle(f.p, t)
      const l0 = 300 * echelle, h0 = 84 * echelle
      let X = px - l0 / 2, Y = py - h0 / 2, L = l0, Hh = h0
      if (cible) { X = lerp(X, cible.x, range); Y = lerp(Y, cible.y, range); L = lerp(L, cible.w, range); Hh = lerp(Hh, cible.h, range) }
      x.save(); if (flou * (1 - range) > 0.6) x.filter = `blur(${(flou * (1 - range)).toFixed(1)}px)`
      ficheArbre(f.p, X, Y, L, Hh, { a: k * (1 - relais), surligne: f.p === JOS ? eout(prog(t, R.liens + 1.4, R.liens + 2.2)) * (1 - relais) : 0 })
      x.restore()
      f.ecran = { x: X, y: Y, w: L, h: Hh }
    } else {
      // les autres fragments s'effacent quand les fiches se rangent
      const a = k * (1 - 0.9 * eio(prog(t, R.liens, R.liens + 1.2))) * (1 - relais)
      if (a <= 0.01) continue
      x.save(); if (flou > 0.6) x.filter = `blur(${flou.toFixed(1)}px)`
      if (f.genre === 'date') texte(f.s, px, py, { taille: 42 * echelle, famille: MONO, coul: C.attenue, a, align: 'center' })
      else if (f.genre === 'lieu') texte(f.s, px, py, { taille: 48 * echelle, poids: 500, coul: alpha(C.texte, 0.8), a, align: 'center' })
      else tirage(IMAGES[f.src], px, py, (f.genre === 'acte' ? 440 : 380) * echelle, { rot: f.rot, a, rognage: f.genre === 'acte' ? [300, 380, 1200, 700] : null })
      x.restore()
    }
  }
  // les liens de parenté, tracés dans le style de l'arbre (traits orthogonaux, gris de l'application)
  const traits = eout(prog(t, R.liens + 1.0, R.liens + 2.4)) * (1 - relais)
  if (traits > 0) {
    const e = (p) => FRAGMENTS_DEBUT.find((f) => f.p === p)?.ecran
    const couple = (a, b, enfants) => {
      const A = e(a), B = e(b), Es = enfants.map(e).filter(Boolean)
      if (!A || !B) return
      const [g, d] = A.x < B.x ? [A, B] : [B, A]
      const yU = g.y + g.h / 2, mid = (g.x + g.w + d.x) / 2
      x.save(); x.strokeStyle = alpha('#6a6e76', traits); x.lineWidth = 1.5
      const seg = (x1, y1, x2, y2, kk) => { x.beginPath(); x.moveTo(x1, y1); x.lineTo(lerp(x1, x2, kk), lerp(y1, y2, kk)); x.stroke() }
      seg(g.x + g.w, yU, d.x, yU, traits)
      if (Es.length) {
        const yB = Math.min(...Es.map((c) => c.y)) - 14
        seg(mid, yU, mid, yB, eout(prog(traits, 0.2, 0.7)))
        const xs = Es.map((c) => c.x + c.w / 2)
        const kb = eout(prog(traits, 0.5, 1))
        seg(mid, yB, Math.min(...xs), yB, kb); seg(mid, yB, Math.max(...xs), yB, kb)
        for (const c of Es) seg(c.x + c.w / 2, yB, c.x + c.w / 2, c.y, kb)
      }
      x.restore()
    }
    couple(MAT, PER, FAM.enfantsDe(MAT))
    couple(JOS, FRANCOIS, FAM.enfantsDe(JOS))
  }
}

/* ---- 3. le grand recul, et les chiffres ---- */
function chiffres(t) {
  const a = Math.min(eout(prog(t, R.compteur - 0.3, R.compteur + 0.4)), 1 - eio(prog(t, R.eparpille - 0.3, R.eparpille + 0.3)))
  if (a <= 0) return
  // un voile en bas, pour lire sur l'arbre
  const g = x.createLinearGradient(0, H * 0.55, 0, H)
  g.addColorStop(0, 'rgba(20,21,23,0)'); g.addColorStop(0.55, 'rgba(20,21,23,0.82)'); g.addColorStop(1, 'rgba(20,21,23,0.92)')
  x.save(); x.globalAlpha = a; x.fillStyle = g; x.fillRect(0, H * 0.55, W, H * 0.45); x.restore()
  const n = Math.round(2339 * eout(prog(t, R.compteur - 0.15, R.compteur + 1.3)))
  const nombre = n.toLocaleString('fr-FR').replace(/ | /g, ' ')
  const gen = eout(prog(t, R.generations - 0.1, R.generations + 0.6))
  const cx = W / 2 - 230 * gen
  texte(nombre, cx, 846, { taille: 120, poids: 250, align: 'center', a, espacement: 2 })
  texte('PERSONNES', cx, 930, { taille: 20, poids: 600, coul: C.attenue, align: 'center', a, espacement: 6 })
  if (gen > 0) {
    x.save(); x.globalAlpha = a * gen; x.fillStyle = C.ligne; x.fillRect(W / 2 + 10, 790, 1.5, 150); x.restore()
    texte('14', W / 2 + 250, 846 + (1 - gen) * 14, { taille: 120, poids: 250, align: 'center', a: a * gen })
    texte('GÉNÉRATIONS', W / 2 + 250, 930, { taille: 20, poids: 600, coul: C.attenue, align: 'center', a: a * gen, espacement: 6 })
    // quatorze traits, un par génération, qui s'allument de la plus ancienne à la plus récente
    for (let i = 0; i < 14; i++) {
      const ki = eout(prog(t, R.generations + 0.05 * i, R.generations + 0.05 * i + 0.3))
      x.save(); x.globalAlpha = a * ki; x.fillStyle = C[BRANCHES[i % 4]]; x.fillRect(W / 2 + 250 - 70 + i * 10.4, 962, 6, 3); x.restore()
    }
  }
}

/* ---- 4. « au départ… un peu partout » : des papiers épars ---- */
const EPARS = (() => {
  const r = rnd(77), liste = []
  const noms = ['Mathurin Perrin', 'Perrine Le Gall', 'Joséphine', 'Jan Perrin', 'François Dubois', 'Julienne Guého', 'Marcel', 'Louis Dubois', 'Françoise']
  const dates = ['12 mars 1868', '23 juin 1783', '4 février 1901', '° 1839', '† 1949', 'vers 1720', 'mariés en 1890', '1912']
  const photos = ['photos/lieu-2.jpg', 'photos/dos-2.jpg', 'photos/lieu-3.jpg', 'photos/dos-3.jpg', 'photos/lieu-6.jpg', 'photos/dos-4.jpg', 'photos/lieu-4.jpg']
  const actes = ['documents/bapteme-1783.jpg', 'documents/deces-1901.jpg', 'documents/acte-josselin-1868.jpg']
  const place = (genre, i, n) => {
    // réparties sur tout l'écran, et au-delà (révélé par le recul de « un peu partout »)
    const ang = (i / n) * Math.PI * 2 + r() * 1.2 + { nom: 0, date: 0.6, photo: 1.3, acte: 2.1 }[genre]
    const rayon = 180 + r() * 760
    return { x: W / 2 + Math.cos(ang) * rayon * 1.15, y: H / 2 + Math.sin(ang) * rayon * 0.62, rot: (r() - 0.5) * 0.42, d: r() * 0.35 }
  }
  noms.forEach((s, i) => liste.push({ genre: 'nom', s, ...place('nom', i, noms.length), t: R.frNoms }))
  dates.forEach((s, i) => liste.push({ genre: 'date', s, ...place('date', i, dates.length), t: R.frDates }))
  photos.forEach((src, i) => liste.push({ genre: 'photo', src, ...place('photo', i, photos.length), t: R.frPhotos, l: 230 + r() * 90 }))
  actes.forEach((src, i) => liste.push({ genre: 'acte', src, ...place('acte', i, actes.length), t: R.frActes, l: 330 + r() * 90 }))
  // « un peu partout » : une seconde vague, plus loin, plus petite
  for (let i = 0; i < 16; i++) {
    const g = ['nom', 'date', 'photo', 'acte'][i % 4]
    const ang = r() * Math.PI * 2, rayon = 820 + r() * 700
    liste.push({ genre: g, s: g === 'nom' ? noms[i % noms.length] : dates[i % dates.length], src: g === 'photo' ? photos[i % photos.length] : actes[i % actes.length], x: W / 2 + Math.cos(ang) * rayon * 1.2, y: H / 2 + Math.sin(ang) * rayon * 0.7, rot: (r() - 0.5) * 0.5, d: r() * 0.5, t: R.partout - 0.2, l: 200 + r() * 80 })
  }
  return liste
})()
ASSETS_A_CHARGER.push('photos/lieu-6.jpg')

/** Un bout de papier (nom ou date manuscrits), comme une fiche d'archive. */
function papier(s, cx, cy, rot, { a = 1, police = 'nom', echelle = 1 } = {}) {
  const taille = (police === 'nom' ? 46 : 34) * echelle
  const fam = police === 'nom' ? '"Mrs Saint Delafield", "Pinyon Script", cursive' : MONO
  const l = mesure(s, taille, 400, fam) + 50 * echelle, h = taille * 1.9
  x.save(); x.globalAlpha *= a; x.translate(cx, cy); x.rotate(rot)
  x.shadowColor = 'rgba(0,0,0,0.45)'; x.shadowBlur = 24; x.shadowOffsetY = 8
  x.fillStyle = police === 'nom' ? '#e6dabf' : '#ddd3bc'; x.fillRect(-l / 2, -h / 2, l, h)
  x.shadowColor = 'transparent'
  texte(s, 0, police === 'nom' ? 4 * echelle : 1, { taille, famille: fam, coul: police === 'nom' ? '#3b2f22' : '#4a3c2c', align: 'center', poids: 400 })
  x.restore()
}

function dessinerEpars(t) {
  if (t < R.frNoms - 0.5 || t > R.logo + 0.6) return
  const recul = 1 - 0.28 * eio(prog(t, R.partout - 0.3, R.converge)) // « un peu partout » : on découvre plus loin
  const conv = ein(prog(t, R.converge - 0.05, R.logo + 0.25)) // « du coup » : tout converge
  for (const f of EPARS) {
    const k = eout(prog(t, f.t + f.d * 0.6, f.t + f.d * 0.6 + 0.55))
    if (k <= 0) continue
    let px = W / 2 + (f.x - W / 2) * recul, py = H / 2 + (f.y - H / 2) * recul
    px = lerp(px, W / 2, conv); py = lerp(py, H / 2 + 20, conv)
    const e = recul * (1 - 0.92 * conv) * lerp(0.92, 1, k)
    const a = k * (1 - prog(conv, 0.6, 1))
    const rot = f.rot * (1 - conv) + (1 - k) * 0.12
    if (f.genre === 'nom') papier(f.s, px, py, rot, { a, echelle: e })
    else if (f.genre === 'date') papier(f.s, px, py, rot, { a, police: 'date', echelle: e })
    else tirage(IMAGES[f.src], px, py, (f.l ?? 260) * e, { rot, a, rognage: f.genre === 'acte' ? [0, 0, 1600, 1100] : null })
  }
}

/* ---- la scène ---- */
function sceneIntro(t) {
  if (t > R.logo + 0.8) return
  const tRush = T_RUSH_INTRO()
  // l'arbre réel, du niveau des fiches jusqu'à l'arbre entier, puis il se défait
  const entree = eout(prog(t, tRush, tRush + 1.0))
  // l'arbre se dissout lentement : les papiers épars arrivent pendant qu'il disparaît
  const sortie = eio(prog(t, R.eparpille - 0.2, R.frNoms + 0.5))
  if (entree > 0 && sortie < 1) {
    dessinerRush('intro-recul', t, { a: entree * (1 - sortie), flou: 10 * sortie, lum: 1 - 0.4 * sortie, cam: { cx: 960, cy: 540, z: 1 + 0.12 * sortie } })
  }
  dessinerFragmentsDebut(t)
  chiffres(t)
  dessinerEpars(t)
}

function sonsIntro() {
  FRAGMENTS_DEBUT.filter((f, i) => i % 3 === 0).forEach((f) => son(f.apparition, 'pose', 0.25))
  son(R.liens + 1.0, 'lien', 0.8)
  son(R.zoomArriere - 0.2, 'recul', 0.8)
  son(R.compteur, 'note', 0.6)
  son(R.eparpille - 0.1, 'souffle', 0.5)
  for (const [cle, g] of [['frNoms', 0.5], ['frDates', 0.4], ['frPhotos', 0.6], ['frActes', 0.7]]) son(R[cle], 'papier', g)
  son(R.converge, 'souffle', 0.7)
}
