/* ------------------------------------------------------------------------ */
/* SCÈNE 1 — L'HISTOIRE AVANT L'APPLICATION                                   */
/* « Dans ma famille, on a un arbre généalogique qui commence à devenir      */
/* franchement énorme. [pause] 2 307 personnes. 14 générations. »            */
/*                                                                           */
/* Des fragments de mémoire, pas une interface : un prénom, une date, une    */
/* vieille photo, un lieu, un document. Sur la pause, le prénom et la date  */
/* rejoignent la fiche de Joséphine dans l'arbre réel (rush `intro-arbre`), */
/* et la caméra recule en montant dans les générations.                     */
/* ------------------------------------------------------------------------ */
const FAM = construireFamille()
const JOS = FAM.vedette, [MAT, PER] = FAM.parentsVedette, JEAN = FAM.jean
ASSETS_A_CHARGER.push('photos/lieu-1.jpg', 'documents/acte-josselin-1868.jpg')

// les fragments, dans l'ordre où la voix les laisse venir (instants relatifs à la première phrase)
const FRAGMENTS = [
  { genre: 'prenom', s: 'Joséphine', x: 380, y: 520, z: 1.0, d: 0.15 },
  { genre: 'date', s: '12 mars 1868', x: 690, y: 700, z: 1.15, d: 0.75 },
  { genre: 'photo', src: 'photos/lieu-1.jpg', x: 560, y: 1000, z: 1.3, d: 1.45, rot: -0.06, l: 520 },
  { genre: 'lieu', s: 'Josselin', x: 300, y: 1310, z: 1.1, d: 2.25 },
  { genre: 'document', src: 'documents/acte-josselin-1868.jpg', x: 700, y: 1520, z: 1.45, d: 2.95, rot: 0.05, l: 560, rognage: [300, 380, 1250, 760] },
]

function sceneMemoire(t) {
  if (!dansScene(t, 'memoire', 0.5)) return
  const t0 = R.memoire
  const tR = RUSH['intro-arbre']?.debut ?? R.recul - 0.6
  /* l'arbre réel, sous les fragments : il arrive pendant la pause, très près de Joséphine */
  const aArbre = eout(prog(t, tR, tR + 1.0))
  const sortie = eio(prog(t, R.disperse - 0.4, R.disperse + 1.2)) // il se disperse (scène 2)
  if (aArbre > 0 && sortie < 1) dessinerRush('intro-arbre', t, { a: aArbre * (1 - sortie), flou: 14 * sortie, lum: 1 - 0.5 * sortie })
  /* les fragments : ils dérivent lentement vers le haut, les plus proches plus vite (parallaxe) */
  const jos = repere('intro-arbre', Math.max(t, tR), 'p' + JOS.id)
  const rejoint = eio(prog(t, tR - 0.2, tR + 0.9)) // le prénom et la date rejoignent la fiche
  for (const f of FRAGMENTS) {
    const k = eout(prog(t, t0 + f.d, t0 + f.d + 1.1))
    if (k <= 0) continue
    const derive = (t - t0) * 9 / f.z
    let px = f.x, py = f.y - derive + (1 - k) * 30
    const flou = Math.abs(f.z - 1.05) * 4
    let a = k
    if (f.genre === 'prenom' || f.genre === 'date') {
      if (jos) { // la place du nom et des dates sur la fiche réelle
        const cible = f.genre === 'prenom' ? [jos.x + jos.w * 0.62, jos.y + jos.h * 0.38] : [jos.x + jos.w * 0.6, jos.y + jos.h * 0.66]
        px = lerp(px, cible[0], rejoint); py = lerp(py, cible[1], rejoint)
      }
      a *= 1 - prog(t, tR + 0.6, tR + 1.1)
      const taille = lerp(f.genre === 'prenom' ? 120 : 64, f.genre === 'prenom' ? 44 : 30, rejoint)
      x.save(); if (flou * (1 - rejoint) > 0.5) x.filter = `blur(${(flou * (1 - rejoint)).toFixed(1)}px)`
      if (f.genre === 'prenom') texte(f.s, px, py, { taille, famille: PLUME, coul: '#e9dcc0', align: 'center', a, poids: 400 })
      else texte(f.s, px, py, { taille, famille: SERIF, poids: 500, coul: alpha('#e9dcc0', 0.85), align: 'center', a })
      x.restore()
    } else {
      // les autres fragments s'éloignent dans le flou quand l'arbre arrive
      const part = eio(prog(t, tR - 0.2, tR + 1.0))
      a *= 1 - part
      if (a <= 0.01) continue
      const s = 1 - 0.25 * part
      if (f.genre === 'lieu') { x.save(); if (flou > 0.5) x.filter = `blur(${flou.toFixed(1)}px)`; texte(f.s, px, py, { taille: 66 * s, famille: SERIF, poids: 500, coul: alpha('#e9dcc0', 0.75), align: 'center', a, espacement: 2 }); x.restore() }
      else tirage(IMAGES[f.src], px, py, f.l * s, { rot: f.rot, a, flou: flou + 6 * part, rognage: f.rognage })
    }
  }
  /* les chiffres : 2 307 personnes, 14 générations (empilés, dans le haut de l'écran) */
  const aN = fenetre(t, R.personnes - 0.35, R.disperse + 0.2, 0.5, 0.6)
  if (aN > 0) {
    const g = x.createLinearGradient(0, 180, 0, 900); g.addColorStop(0, 'rgba(20,21,23,0.8)'); g.addColorStop(1, 'rgba(20,21,23,0)')
    x.save(); x.globalAlpha = aN; x.fillStyle = g; x.fillRect(0, 180, W, 720); x.restore()
    const n = Math.round(2307 * eout(prog(t, R.personnes - 0.5, R.personnes + 0.6)))
    texte(n.toLocaleString('fr-FR').replace(/ | /g, ' '), W / 2, 400, { taille: 168, poids: 250, align: 'center', a: aN, espacement: 2 })
    texte('PERSONNES', W / 2, 505, { taille: 30, poids: 600, coul: C.attenue, align: 'center', a: aN, espacement: 8 })
    const aG = aN * eout(prog(t, R.generations - 0.15, R.generations + 0.45))
    texte('14', W / 2, 650 + (1 - aG) * 16, { taille: 120, poids: 250, align: 'center', a: aG })
    texte('GÉNÉRATIONS', W / 2, 735, { taille: 30, poids: 600, coul: C.attenue, align: 'center', a: aG, espacement: 8 })
  }
}

function sonsMemoire() {
  FRAGMENTS.forEach((f) => son(R.memoire + f.d, f.genre === 'photo' || f.genre === 'document' ? 'papier' : 'pose', 0.35))
  son((RUSH['intro-arbre']?.debut ?? R.recul - 0.6) + 0.2, 'lien', 0.7)
  son(R.recul + 0.2, 'recul', 0.6)
  son(R.personnes, 'note', 0.45)
}
