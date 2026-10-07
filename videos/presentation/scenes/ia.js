/* ------------------------------------------------------------------------ */
/* IA — « Même chose pour les questions. Tu peux simplement demander :      */
/* “Qui étaient les parents de cette personne ?” Et Heritage retrouve        */
/* directement la réponse dans l'arbre. »                                    */
/*                                                                           */
/* La conversation réelle (rush `question`) : on parle déjà de Joséphine ;  */
/* la question de suivi est tapée mot pour mot, la réponse arrive avec ses  */
/* liens ; on suit le lien, et l'arbre (rush `arbre-fin`) vole jusqu'aux     */
/* parents.                                                                  */
/* ------------------------------------------------------------------------ */
function sceneIA(t) {
  if (t < R.questions - 0.7 || t > R.but + 1.5) return
  const n = 'question'
  const entree = eout(prog(t, R.questions - 0.6, R.questions + 0.1))
  const q = repere(n, R.frappe + 1.2, 'question'), rep = repere(n, R.reponse + 0.8, 'reponse'), lien = repere(n, R.navigation - 0.3, 'lienPere')
  const c = (r, z, dy = 0) => (r ? { cx: clamp(r.x + r.w / 2, 960 / z, 1920 - 960 / z), cy: clamp(r.y + r.h / 2 + dy, 540 / z, 1080 - 540 / z), z } : { cx: 960, cy: 540, z })
  // la caméra : la conversation, la question, la réponse, puis on plonge dans le lien
  const plonge = ein(prog(t, R.navigation - 0.25, R.navigation + 0.45))
  let cam = camRush(t, [
    { t: R.questions - 0.6, cx: 960, cy: 520, z: 1.0 },
    { t: R.frappe - 0.4, ...c(q, 1.3, -120) },
    { t: R.reponse - 0.4, ...c(q, 1.3, -120) },
    { t: R.reponse + 0.5, ...c(rep, 1.35, -30) },
    { t: R.navigation - 0.25, ...c(rep, 1.38, -30) },
  ])
  if (plonge > 0 && lien) cam = { cx: lerp(cam.cx, lien.x + lien.w / 2, plonge), cy: lerp(cam.cy, lien.y + lien.h / 2, plonge), z: cam.z * Math.exp(Math.log(3.2) * plonge) }
  const sortie = eio(prog(t, R.navigation + 0.05, R.navigation + 0.6))
  if (sortie < 1) {
    const ve = dessinerRush(n, t, { cam, a: entree * (1 - sortie), flou: 8 * plonge })
    projecteur(rectEcran(ve, repere(n, t, 'question'), 4), fenetre(t, R.frappe - 0.3, R.reponse - 0.5, 0.3, 0.3), { ombre: 0.3 })
    projecteur(rectEcran(ve, repere(n, t, 'reponse'), 10), fenetre(t, R.reponse + 0.1, R.navigation - 0.3, 0.35, 0.25), { ombre: 0.4 })
    // les deux liens de la réponse : un soulignement d'accent qui passe
    for (const [cle, d] of [['lienPere', 0], ['lienMere', 0.25]]) {
      const r = rectEcran(ve, repere(n, t, cle))
      const k = eio(prog(t, R.reponse + 0.6 + d, R.reponse + 1.1 + d)) * (1 - plonge)
      if (r && k > 0) { x.save(); x.fillStyle = alpha(C.accent, 0.9); x.fillRect(r.x, r.y + r.h + 2, r.w * k, 2.5); x.restore() }
    }
  }
  /* l'arbre : les parents de Joséphine, désignés par la réponse */
  if (t >= R.navigation) {
    const a = eout(prog(t, R.navigation + 0.05, R.navigation + 0.6))
    const z = lerp(1.25, 1, eout(prog(t, R.navigation, R.navigation + 1.0)))
    const ve = dessinerRush('arbre-fin', t, { a, cam: { cx: 960, cy: 540, z } })
    const pere = repere('arbre-fin', t, 'p' + MAT.id), mere = repere('arbre-fin', t, 'p' + PER.id)
    const k = fenetre(t, R.navigation + 0.7, R.but + 1.3, 0.4, 0.5)
    for (const r of [pere, mere]) projecteur(rectEcran(ve, r, 6), k, { ombre: 0, rayon: 9 })
  }
}

function sonsIA() {
  son(R.questions - 0.4, 'souffle', 0.3)
  son(R.frappe - 0.3, 'tic', 0.5)
  for (let i = 0; i < 43; i += 3) son(R.frappe + i * 0.045, 'touche', 0.45)
  son(R.reponse - 0.9, 'tic', 0.6)
  son(R.reponse + 0.1, 'note', 0.5)
  son(R.navigation - 0.2, 'souffle', 0.6)
}
