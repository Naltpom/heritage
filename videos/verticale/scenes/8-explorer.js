/* ------------------------------------------------------------------------ */
/* SCÈNE 8 — EXPLORER                                                        */
/* « Et puis il y a tout ce qu'on découvre en explorant. Les lieux où la    */
/* famille a vécu. Les métiers qui revenaient le plus. Les prénoms transmis */
/* de génération en génération. Les histoires qui se cachent derrière les   */
/* chiffres. »                                                               */
/*                                                                           */
/* Quatre écrans réels, montés vite et proprement : la carte (qui plonge    */
/* sur le pays d'origine), les métiers, les prénoms transmis, la chronologie */
/* des vies. Chaque nouvel écran monte du bas, l'ancien recule.             */
/* ------------------------------------------------------------------------ */
function rushAvecCoupes(nom, t, opts = {}) {
  const m = RUSH[nom]
  const coupe = (m?.coupes ?? []).find((c) => t >= c && t < c + 0.3)
  const ve = dessinerRush(nom, t, opts)
  if (coupe !== undefined) dessinerRush(nom, coupe - 1 / 30, { ...opts, a: 1 - eio(prog(t, coupe, coupe + 0.3)) })
  return ve
}
function sceneExplorer(t) {
  if (!dansScene(t, 'explorer')) return
  // le premier écran monte au-dessus de l'arbre
  const plans = [
    { nom: 'carte', t0: R.explorer - 0.35 },
    { nom: 'stats', t0: R.metiers - 0.3 },
    { nom: 'chronologie', t0: R.histoires - 0.3 },
  ]
  for (let i = 0; i < plans.length; i++) {
    const p = plans[i], suivant = plans[i + 1]
    if (t < p.t0 || (suivant && t > suivant.t0 + 0.45)) continue
    const dessin = (k) => rushAvecCoupes(p.nom, t, { cam: { cx: W / 2, cy: H / 2, z: 1 + 0.03 * (suivant ? prog(t, suivant.t0, suivant.t0 + 0.45) : 0) } })
    if (t < p.t0 + 0.45) glissement(t, p.t0, 0.45, () => {}, dessin)
    else dessin()
  }
}
function sonsExplorer() {
  son(R.explorer - 0.35, 'souffle', 0.4)
  son(R.metiers - 0.3, 'souffle', 0.35)
  son(R.prenoms - 0.2, 'tic', 0.3)
  son(R.histoires - 0.3, 'souffle', 0.35)
}
