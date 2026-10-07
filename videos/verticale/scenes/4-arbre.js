/* ------------------------------------------------------------------------ */
/* SCÈNES 4 ET 5 — L'ARBRE, PUIS LES SOURCES                                 */
/* « Tu peux parcourir l'arbre, zoomer dedans, retrouver quelqu'un en       */
/* quelques secondes… Voir ses parents, ses enfants, les lieux où elle a    */
/* vécu. Et quand une information vient d'un vrai document, on peut même   */
/* retrouver la source. [serious tone] Une date vérifiée par acte, par      */
/* exemple. »                                                                */
/*                                                                           */
/* Un seul plan continu de l'application mobile (rush `arbre`) : l'arbre    */
/* entier, un glissement en diagonale, un zoom centré, la recherche, le vol */
/* jusqu'à Joséphine, sa fiche (parents, enfants, lieux), la date de        */
/* naissance, l'onglet Sources, l'acte. La composition ne fait que montrer  */
/* le doigt, éclairer ce que dit la voix, et relier la date à l'acte.       */
/* ------------------------------------------------------------------------ */
function sceneArbre(t) {
  if (!dansScene(t, 'arbre') && !dansScene(t, 'sources')) return
  if (t > R.fige + 0.4) return
  const n = 'arbre'
  // de légères avancées vers ce que dit la voix (le plan mobile est déjà à l'échelle de l'écran)
  const par = repere(n, R.parents + 0.2, 'parents'), nais = repere(n, R.document + 0.4, 'naissance')
  const cam = camRush(t, [
    { t: R.parcourir - 0.1, cx: W / 2, cy: H / 2, z: 1 },
    { t: R.parents - 0.2, cx: W / 2, cy: H / 2, z: 1 },
    { t: R.parents + 0.3, ...cadrerSur(par, 1.08, 0, 160) },
    { t: R.document - 0.1, ...cadrerSur(par, 1.08, 0, 160) },
    { t: R.document + 0.5, ...cadrerSur(nais, 1.12, 0, 220) },
    { t: R.source - 0.2, ...cadrerSur(nais, 1.12, 0, 220) },
    { t: R.source + 0.3, cx: W / 2, cy: H / 2, z: 1 },
    { t: R.verifiee + 0.4, cx: W / 2, cy: H / 2, z: 1.0 },
    { t: R.fige + 0.4, cx: W / 2, cy: H / 2 + 40, z: 1.07 },
  ])
  const ve = dessinerRush(n, t, { cam })
  const P = (cle, tt = t, m = 6) => rectEcran(ve, repere(n, tt, cle), m)
  /* la voix guide le regard */
  projecteur(P('recherche'), fenetre(t, R.retrouver - 0.2, R.secondes + 0.25, 0.25, 0.3), { ombre: 0, rayon: 40 })
  projecteur(P('feuillet', t, 14), fenetre(t, R.secondes + 0.55, R.parents - 0.55, 0.25, 0.2), { ombre: 0.35, rayon: 24 })
  projecteur(P('parents', t, 10), fenetre(t, R.parents - 0.05, R.enfants - 0.12, 0.25, 0.2), { ombre: 0.45 })
  const enf = repere(n, t, 'enfants')
  projecteur(enf ? rectEcran(ve, { x: enf.x, y: enf.y, w: 520, h: enf.h + 5 * 54 }, 10) : null, fenetre(t, R.enfants - 0.05, R.lieux - 0.12, 0.25, 0.2), { ombre: 0.45 })
  const lieu = repere(n, t, 'lieux')
  projecteur(lieu ? rectEcran(ve, { x: 40, y: lieu.y - 70, w: 1000, h: lieu.h + 140 }, 6) : null, fenetre(t, R.lieux - 0.05, R.document - 0.55, 0.25, 0.25), { ombre: 0.45 })
  projecteur(P('naissance', t, 12), fenetre(t, R.document + 0.25, R.source - 0.15, 0.3, 0.25), { ombre: 0.5 })
  const cit = repere(n, t, 'citation')
  projecteur(cit ? rectEcran(ve, { x: 40, y: cit.y - 200, w: 1000, h: cit.h + 420 }, 0) : null, fenetre(t, R.source + 1.0, R.verifiee - 0.35, 0.25, 0.2), { ombre: 0.45 })
  /* le doigt */
  toucher(...centreDe(P('recherche', R.retrouver - 0.1)), t, R.retrouver - 0.1)
  toucher(...centreDe(P('fiche', R.parents - 0.65)), t, R.parents - 0.6)
  toucher(...centreDe(P('onglet', R.source - 0.1)), t, R.source - 0.05)
  toucher(...centreDe(P('vignette', R.verifiee - 0.4)), t, R.verifiee - 0.35)
  /* « vérifiée par acte » : la date de la fiche, reliée à la ligne de l'acte où elle est écrite */
  verifieeParActe(t, ve)
}

function verifieeParActe(t, ve) {
  const k = fenetre(t, R.verifiee + 0.05, R.fige + 0.35, 0.5, 0.4)
  if (k <= 0) return
  const loupe = rectEcran(ve, repere('arbre', Math.min(t, R.fige), 'loupe'))
  // la ligne de l'acte : « le douze mars » (première ligne du texte, vers le quart de la page)
  if (loupe) {
    const lx = loupe.x + loupe.w * 0.22, ly = loupe.y + loupe.h * 0.205, lw = loupe.w * 0.62, lh = loupe.h * 0.032
    const kl = eout(prog(t, R.verifiee + 0.3, R.verifiee + 0.9)) * k
    x.save(); x.globalAlpha = kl
    rr(lx, ly, lw, lh, 6); x.fillStyle = alpha(C.ok, 0.22); x.fill(); x.strokeStyle = alpha(C.ok, 0.9); x.lineWidth = 3; x.stroke()
    x.restore()
    // un trait qui relie l'étiquette à la ligne
    const ka = eout(prog(t, R.verifiee + 0.6, R.verifiee + 1.1)) * k
    if (ka > 0) { x.save(); x.strokeStyle = alpha(C.ok, 0.8 * ka); x.lineWidth = 3; x.setLineDash([8, 8]); x.beginPath(); x.moveTo(W / 2, 395); x.lineTo(lerp(W / 2, lx + lw / 2, ka), lerp(395, ly, ka)); x.stroke(); x.restore() }
  }
  // l'étiquette, dans le haut de l'écran (hors des commandes des applications)
  const y = 300 + (1 - eout(k)) * 20
  x.save(); x.globalAlpha = k
  x.shadowColor = 'rgba(0,0,0,0.5)'; x.shadowBlur = 40
  rr(110, y - 92, 860, 184, 30); x.fillStyle = 'rgba(22,26,23,0.96)'; x.fill(); x.shadowColor = 'transparent'
  x.strokeStyle = alpha(C.ok, 0.55); x.lineWidth = 2; x.stroke()
  x.beginPath(); x.arc(190, y - 18, 30, 0, Math.PI * 2); x.fillStyle = C.ok; x.fill()
  x.strokeStyle = '#13201a'; x.lineWidth = 7; x.lineCap = 'round'; x.lineJoin = 'round'
  x.beginPath(); x.moveTo(176, y - 18); x.lineTo(186, y - 7); x.lineTo(205, y - 30); x.stroke()
  texte('Date vérifiée par acte', 245, y - 30, { taille: 48, poids: 650, coul: C.ok })
  texte('Naissance · 12 mars 1868 · Josselin', 245, y + 28, { taille: 32, poids: 500, coul: C.texte })
  texte('Acte n° 41 — Archives du Morbihan', 245, y + 66, { taille: 26, poids: 400, coul: C.attenue, famille: MONO })
  x.restore()
}

function sonsArbre() {
  son(R.parcourir, 'souffle', 0.4); son(R.zoomer, 'souffle', 0.45)
  son(R.retrouver - 0.1, 'tic', 0.6)
  for (let i = 0; i < 12; i += 2) son(R.retrouver + 0.1 + i * 0.06, 'touche', 0.5)
  son(R.secondes, 'souffle', 0.5)
  son(R.parents - 0.6, 'tic', 0.6)
  son(R.source - 0.05, 'tic', 0.6)
  son(R.verifiee - 0.35, 'tic', 0.6); son(R.verifiee - 0.25, 'papier', 0.5)
  son(R.verifiee + 0.1, 'verifie', 1)
}
