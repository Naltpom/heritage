/* ------------------------------------------------------------------------ */
/* FIN — « Le but, au fond… que toute cette histoire ne se perde pas… dans  */
/* dix, vingt ou cinquante ans… Ça, c'est Heritage. »                       */
/*                                                                           */
/* L'arbre réel recule lentement jusqu'à l'arbre entier ; l'interface        */
/* s'efface région par région (prises `arbre-fin` et `arbre-fin-nu`), il ne  */
/* reste que l'arbre ; il s'assombrit, et le logo se trace :                 */
/* « Préserver l'histoire de la famille, pour que personne ne l'oublie. »    */
/* ------------------------------------------------------------------------ */
const PHRASE_FIN = 'Préserver l’histoire de la famille, pour que personne ne l’oublie.'

function sceneFin(t) {
  if (t < R.but + 1.2) return
  // l'interface s'efface dans l'ordre inverse de sa construction : l'aide et la mini-carte d'abord, l'en-tête en dernier
  const part = (nom) => {
    const ordre = { aide: 0, minicarte: 0.25, generations: 0.6, familles: 0.95, outils: 1.3, entete: 1.75 }[nom]
    return 1 - eio(prog(t, R.epure + ordre, R.epure + ordre + 0.9))
  }
  const signe = eio(prog(t, R.signature - 0.3, R.logoFin + 0.4)) // l'arbre se retire derrière la signature
  const cam = { cx: 960, cy: 540 + 10 * signe, z: 1 - 0.05 * signe }
  const nu = RUSH['arbre-fin-nu']
  if (nu && t >= nu.debut) {
    x.save(); x.filter = signe > 0 ? `brightness(${1 - 0.72 * signe}) blur(${(2.5 * signe).toFixed(2)}px)` : 'none'
    arbreEnConstruction('arbre-fin', 'arbre-fin-nu', t, part, { cam })
    x.restore()
  } else dessinerRush('arbre-fin', t, { cam })
  // la signature
  logo(W / 2, 430, 120, prog(t, R.signature + 0.1, R.signature + 1.4))
  const kMot = eout(prog(t, R.logoFin - 0.25, R.logoFin + 0.45))
  motHeritage(W / 2, 560 + (1 - kMot) * 10, 96, { a: kMot })
  const kPhrase = eout(prog(t, R.logoFin + 1.0, R.logoFin + 2.0))
  texte(PHRASE_FIN, W / 2, 650 + (1 - kPhrase) * 8, { taille: 30, poids: 400, coul: C.attenue, align: 'center', a: kPhrase })
  // le noir final
  const noir = prog(t, DUREE - 1.3, DUREE - 0.1)
  if (noir > 0) { x.save(); x.globalAlpha = eio(noir); x.fillStyle = '#000'; x.fillRect(0, 0, W, H); x.restore() }
}

function sonsFin() {
  son(R.reculFinal - 0.2, 'recul', 0.6)
  son(R.signature, 'note', 0.4)
  son(R.logoFin - 0.2, 'final', 1)
}
