/* ------------------------------------------------------------------------ */
/* SCÈNE 7 — QUESTIONS À L'ARBRE                                             */
/* « [lighthearted] Et évidemment, on peut aussi lui poser des questions.   */
/* “Qui étaient les parents de cette personne ?” [pause] Heritage retrouve   */
/* la réponse directement dans l'arbre. »                                    */
/*                                                                           */
/* La conversation réelle : on parle déjà de Joséphine, la question de      */
/* suivi est tapée mot pour mot, l'assistant cherche, la réponse cite ses   */
/* parents ; on plonge dans le lien, l'arbre vole jusqu'à elle, et un lien  */
/* lumineux la relie à son père et à sa mère.                               */
/* ------------------------------------------------------------------------ */
function sceneQuestions(t) {
  if (!dansScene(t, 'questions')) return
  const n = 'question'
  const plonge = ein(prog(t, R.dansLArbre - 0.75, R.dansLArbre - 0.05))
  if (t < R.dansLArbre + 0.1) {
    const a = eout(prog(t, R.questions - 0.4, R.questions + 0.1))
    const lien = repere(n, R.dansLArbre - 0.8, 'lienPere')
    let cam = { cx: W / 2, cy: H / 2, z: 1 }
    if (plonge > 0 && lien) cam = { cx: lerp(W / 2, lien.x + lien.w / 2, plonge), cy: lerp(H / 2, lien.y + lien.h / 2, plonge), z: Math.exp(Math.log(2.6) * plonge) }
    const ve = dessinerRush(n, t, { a: a * (1 - prog(t, R.dansLArbre - 0.35, R.dansLArbre + 0.1)), cam, flou: 6 * plonge })
    projecteur(rectEcran(ve, repere(n, t, 'question'), 6), fenetre(t, R.question - 0.3, R.question + 1.2, 0.25, 0.25), { ombre: 0.3, rayon: 20 })
    projecteur(rectEcran(ve, repere(n, t, 'cherche'), 14), fenetre(t, R.question + 1.2, R.reponse - 0.1, 0.2, 0.2), { ombre: 0, rayon: 22 })
    projecteur(rectEcran(ve, repere(n, t, 'reponse'), 16), fenetre(t, R.reponse + 0.15, R.dansLArbre - 0.7, 0.3, 0.2), { ombre: 0.4, rayon: 26 })
    for (const [cle, d] of [['lienPere', 0], ['lienMere', 0.2]]) {
      const r = rectEcran(ve, repere(n, t, cle))
      const k = eio(prog(t, R.reponse + 0.6 + d, R.reponse + 1.0 + d)) * (1 - plonge)
      if (r && k > 0) { x.save(); x.shadowColor = alpha(C.accent, 0.8); x.shadowBlur = 16; x.fillStyle = C.accent; x.fillRect(r.x, r.y + r.h + 3, r.w * k, 4); x.restore() }
    }
    toucher(...centreDe(rectEcran(ve, repere(n, R.dansLArbre - 0.8, 'lienPere'))), t, R.dansLArbre - 0.8)
  }
  /* l'arbre : on vole jusqu'à Joséphine ; ses parents s'allument au-dessus d'elle */
  if (t >= R.dansLArbre - 0.3) {
    const a = eout(prog(t, R.dansLArbre - 0.3, R.dansLArbre + 0.25))
    dessinerRush('arbre-parents', t, { a })
    const jo = repere('arbre-parents', t, 'p' + JOS.id), pe = repere('arbre-parents', t, 'p' + MAT.id), me = repere('arbre-parents', t, 'p' + PER.id)
    const k = eout(prog(t, R.dansLArbre + 0.45, R.dansLArbre + 1.2))
    if (jo && k > 0) {
      x.save(); x.lineCap = 'round'; x.shadowColor = alpha(C.accent, 0.9); x.shadowBlur = 24
      x.strokeStyle = alpha(C.accent, 0.95); x.lineWidth = 6
      for (const p of [pe, me]) {
        if (!p) continue
        const ax = jo.x + jo.w / 2, ay = jo.y, bx = p.x + p.w / 2, by = p.y + p.h, my = (ay + by) / 2
        x.beginPath(); x.moveTo(ax, ay)
        const kk = clamp(k * 1.2)
        x.bezierCurveTo(ax, lerp(ay, my, kk), lerp(ax, bx, kk), lerp(ay, my, kk), lerp(ax, bx, kk), lerp(ay, by, kk)); x.stroke()
      }
      x.restore()
      for (const p of [jo, pe, me]) projecteur(p, k * fenetre(t, R.dansLArbre, R.explorer, 0.3, 0.3), { ombre: 0, rayon: 16, marge: 8 })
    }
  }
}

function sonsQuestions() {
  son(R.questions - 0.3, 'souffle', 0.35)
  son(R.question - 0.35, 'tic', 0.5)
  for (let i = 0; i < 43; i += 3) son(R.question - 0.2 + i * 0.026, 'touche', 0.4)
  son(R.question + 1.05, 'tic', 0.6)
  son(R.reponse, 'note', 0.5)
  son(R.dansLArbre - 0.8, 'tic', 0.5)
  son(R.dansLArbre - 0.6, 'souffle', 0.6)
  son(R.dansLArbre + 0.5, 'lien', 0.8)
}
