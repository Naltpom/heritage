/* ------------------------------------------------------------------------ */
/* EXPLORATION — « Mais Heritage, ce n'est pas juste un arbre… dans le      */
/* temps, les lieux, les métiers, les prénoms, quelques statistiques assez  */
/* improbables. »                                                            */
/*                                                                           */
/* La fiche recule ; quatre fenêtres de l'application se posent (l'arbre,   */
/* la chronologie, la carte, les statistiques) ; la chronologie s'ouvre en   */
/* grand, puis la carte, puis trois cadrages des statistiques.              */
/* ------------------------------------------------------------------------ */

const FENETRES = [
  { nom: 'arbre', tRush: () => R.interface + 3.2, x: 150, y: 150, l: 780, d: 0 },
  { nom: 'chronologie', x: 990, y: 118, l: 800, d: 0.12 },
  { nom: 'carte', tRush: () => R.metiers, x: 190, y: 600, l: 740, d: 0.24 },
  { nom: 'stats', tRush: () => R.stats + 1, x: 1010, y: 588, l: 760, d: 0.36 },
]
/** Un fondu enchaîné entre deux plans plein écran, avec une légère avance de l'entrant. */
function enchaine(t, t0, duree, avant, apres) {
  const k = eio(prog(t, t0, t0 + duree))
  if (k < 1) avant(1 - k)
  if (k > 0) apres(k)
}
/** Les coupes enregistrées dans un rush (stats) : on les fond sur 0,5 s. */
function rushFondu(nom, t, cam) {
  const m = RUSH[nom]
  const coupe = (m?.coupes ?? []).find((c) => t >= c && t < c + 0.5)
  const ve = dessinerRush(nom, t, { cam })
  if (coupe !== undefined) dessinerRush(nom, coupe - 1 / 30, { cam, a: 1 - eio(prog(t, coupe, coupe + 0.5)) })
  return ve
}

function sceneExploration(t) {
  if (t < R.pasJuste - 0.2 || t > R.participer + 0.6) return
  /* --- 1. la mosaïque : « pas juste un arbre » --- */
  const ouvre = eio(prog(t, R.temps - 1.35, R.temps - 0.35)) // la chronologie s'ouvre en grand
  const kMos = eout(prog(t, R.pasJuste + 0.15, R.pasJuste + 1.1))
  if (kMos > 0 && ouvre < 1) {
    x.save(); x.globalAlpha = kMos; x.fillStyle = C.fond; x.fillRect(0, 0, W, H); x.restore()
    const derive = (t - R.pasJuste) * 4 // une dérive lente, presque imperceptible
    for (const f of FENETRES) {
      const k = eout(prog(t, R.pasJuste + 0.25 + f.d, R.pasJuste + 1.05 + f.d))
      if (k <= 0) continue
      const tr = f.tRush ? f.tRush() : t
      if (f.nom === 'chronologie' && ouvre > 0) continue // dessinée en dernier, au-dessus
      // « pas juste un arbre » : l'arbre reste un peu en retrait pendant que les autres se posent
      const lum = f.nom === 'arbre' ? 1 - 0.35 * eout(prog(t, R.pasJuste + 1.2, R.pasJuste + 2)) : 1
      fenetreRush(f.nom, tr, { x: f.x - derive * (f.x < 900 ? 1 : -1) * 0.5, y: f.y + (1 - k) * 40, l: f.l, a: k * (1 - ouvre), lum })
    }
  }
  /* --- 2. la chronologie s'ouvre en plein écran --- */
  if (t >= R.temps - 1.35 && t < R.carte + 0.7) {
    const f = FENETRES[1], derive = (R.temps - 1.35 - R.pasJuste) * 4
    const fx = f.x + derive * 0.5, kz = ouvre
    if (kz < 1) {
      // de la fenêtre au plein écran
      fenetreRush('chronologie', t, { x: lerp(fx, 0, kz), y: lerp(f.y, 0, kz), l: lerp(f.l, W, kz), rayon: 14 * (1 - kz), ombre: 1 - kz })
    } else {
      enchaine(t, R.carte - 0.35, 0.6,
        (a) => dessinerRush('chronologie', t, { a, cam: { cx: 960, cy: 540, z: 1 + 0.02 * a } }),
        (a) => dessinerRush('carte', t, { a, cam: { cx: 960, cy: 540, z: 1.03 - 0.03 * a } }))
    }
  }
  /* --- 3. la carte, puis les statistiques (métiers, prénoms, records) --- */
  if (t >= R.carte + 0.25 && t < R.participer + 0.6) {
    const camStats = (tt) => ({ cx: 960, cy: 600, z: 1.12 + 0.03 * prog(tt, R.metiers, R.participer) })
    enchaine(t, R.metiers - 0.35, 0.6,
      (a) => dessinerRush('carte', t, { a }),
      (a) => rushFondu('stats', t, camStats(t)))
  }
}

function sonsExploration() {
  son(R.pasJuste + 0.1, 'recul', 0.5)
  FENETRES.forEach((f) => son(R.pasJuste + 0.3 + f.d, 'pose', 0.3))
  son(R.temps - 1.3, 'souffle', 0.55)
  son(R.carte - 0.3, 'souffle', 0.35)
  son(R.metiers - 0.3, 'souffle', 0.35)
  son(R.prenoms - 0.25, 'souffle', 0.3)
  son(R.stats - 0.25, 'souffle', 0.3)
}
