/* ------------------------------------------------------------------------ */
/* HERITAGE — « Du coup, j'ai créé Heritage. L'idée est assez simple :      */
/* rassembler toute l'histoire de notre famille au même endroit. »           */
/*                                                                           */
/* Le logo se trace au centre ; puis le mot glisse à sa place dans l'en-tête */
/* de l'application, et l'interface RÉELLE se construit autour de l'arbre :  */
/* deux prises du même plan (rush `arbre` avec l'interface, `arbre-nu`       */
/* sans), et chaque région de l'interface se révèle à son tour.              */
/* ------------------------------------------------------------------------ */

/* Les régions de l'interface de l'arbre, en base 1920 (relevées dans le DOM de HistoireView). */
const REGIONS_INTERFACE = [
  { nom: 'entete', r: [0, 0, 1920, 56], dy: -20 },
  { nom: 'outils', r: [0, 56, 1920, 50], dy: -12 },
  { nom: 'familles', r: [0, 106, 1920, 42], dy: -8 },
  { nom: 'generations', r: [0, 148, 176, 932], dx: -16 },
  { nom: 'minicarte', r: [1630, 990, 290, 90], dy: 12 },
  { nom: 'aide', r: [170, 1015, 380, 60], dy: 10 },
]
/**
 * Dessine l'arbre avec seulement une part de son interface : la prise nue, puis la prise
 * complète découpée sur chaque région, selon `part(nom)` ∈ [0, 1].
 */
function arbreEnConstruction(nomComplet, nomNu, t, part, { cam = { cx: 960, cy: 540, z: 1 }, a = 1 } = {}) {
  const ve = dessinerRush(nomNu, t, { cam, a })
  const im = imageRush(nomComplet, t)
  if (!im) return ve
  for (const reg of REGIONS_INTERFACE) {
    const k = part(reg.nom)
    if (k <= 0) continue
    const [rx, ry, rw, rh] = reg.r
    const [sx, sy] = ve(rx, ry), [ex, ey] = ve(rx + rw, ry + rh)
    x.save(); x.globalAlpha = a * k
    x.beginPath(); x.rect(sx, sy, ex - sx, ey - sy); x.clip()
    const glisse = 1 - eout(k)
    x.translate((reg.dx ?? 0) * glisse * cam.z, (reg.dy ?? 0) * glisse * cam.z)
    const [x0, y0] = ve(0, 0)
    x.drawImage(im.im, x0, y0, 1920 * cam.z, 1080 * cam.z)
    x.restore()
  }
  return ve
}

function sceneHeritage(t) {
  if (t < R.logo - 0.4 || t > R.parcourir + 0.2) return
  // le logo et le mot, au centre
  const kLogo = prog(t, R.logo - 0.2, R.logo + 1.1)
  const vers = eio(prog(t, R.interface - 0.45, R.interface + 0.45)) // le mot rejoint l'en-tête
  const aMot = eout(prog(t, R.logo + 0.1, R.logo + 0.7))
  // l'arbre réel arrive sous le logo (la révélation de l'application est dans le rush)
  const tA = RUSH['arbre']?.debut ?? R.interface - 1.2
  const aArbre = eout(prog(t, tA, tA + 1.1))
  if (aArbre > 0) {
    const part = (nom) => {
      const ordre = { entete: 0, outils: 0.35, familles: 0.75, generations: 1.0, minicarte: 1.35, aide: 1.55 }[nom]
      return eout(prog(t, R.interface + 0.25 + ordre, R.interface + 0.85 + ordre))
    }
    // un très léger recul pendant que l'interface se pose : la caméra « fait de la place »
    const z = lerp(1.05, 1, eio(prog(t, tA, R.interface + 2.2)))
    arbreEnConstruction('arbre', 'arbre-nu', t, part, { cam: { cx: 960, cy: 540, z }, a: aArbre })
  }
  // le voile qui garde le logo lisible tant qu'il est au centre
  const voile = (1 - vers) * eout(prog(t, R.logo - 0.4, R.logo + 0.2))
  if (voile > 0) { x.save(); x.globalAlpha = 0.8 * voile; x.fillStyle = C.fond; x.fillRect(0, 0, W, H); x.restore() }
  // le logo s'efface pendant que le mot part vers l'en-tête
  logo(W / 2, 455, 132, kLogo, { a: 1 - eio(prog(t, R.interface - 0.6, R.interface)) })
  // le mot : du centre (110 px) à sa place dans l'en-tête de l'application (16 px, en haut à gauche)
  if (aMot > 0 && t < R.interface + 1.2) {
    const taille = lerp(104, 18, vers)
    const cx = lerp(W / 2, 20 + mesure('Heritage', 18, 700) / 2, vers)
    const cy = lerp(598, 28, vers)
    // à l'arrivée, le vrai mot de l'en-tête (dans le rush) prend le relais
    motHeritage(cx, cy, taille, { a: aMot * (1 - prog(t, R.interface + 0.6, R.interface + 1.1)) })
  }
}

function sonsHeritage() {
  son(R.logo - 0.1, 'note', 0.9)
  son(R.interface + 0.25, 'tic', 0.5)
  son(R.interface + 0.6, 'tic', 0.35)
  son(R.interface + 1.0, 'tic', 0.3)
}
