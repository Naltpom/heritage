/* ------------------------------------------------------------------------ */
/* Le thème clair des vidéos.                                                */
/*                                                                           */
/* Les scènes sont écrites en sombre. En clair (`window.THEME === 'clair'`,  */
/* posé par rendre.mjs), on traduit chaque couleur au moment où elle est     */
/* donnée au canevas : `fillStyle`, `strokeStyle`, `shadowColor` et les      */
/* arrêts de dégradé. La table suit les jetons clairs de l'application       */
/* (app/src/styles/main.css) : fond #f4f2ef, panneau #fffefc, texte #1b1b1b, */
/* accent #8a5a2b, fiches bleu pâle / rose pâle… Les couleurs de branche,    */
/* le papier des actes et l'écran verrouillé du téléphone ne changent pas.   */
/*                                                                           */
/* Une couleur sombre absente de la table passerait telle quelle : rendre.mjs */
/* liste ces oubliées (`window.__couleursInconnues`) pour qu'on les ajoute.  */
/* ------------------------------------------------------------------------ */
(function () {
  if (window.THEME !== 'clair') return

  const TABLE = {
    // les jetons de la palette
    '#141518': '#f4f2ef', '#121418': '#f4f2ef', '#0e1013': '#f4f2ef', // fond
    '#1c1e22': '#fffefc', '#1b1e23': '#fffefc', '#1d1f22': '#fffefc', // panneau
    '#23262b': '#f2efea', '#20242a': '#fffefc', '#26221d': '#f8f1e7', // panneau 2, carte, notification
    '#30343b': '#dcd7ce', '#2e3239': '#dcd7ce', // ligne
    '#efe9e0': '#1b1b1b', // texte
    '#8d8880': '#6b6560', // atténué
    '#e3a35f': '#8a5a2b', // accent
    '#2b2118': '#f0e3d4', '#3a2e22': '#f0e3d4', '#2a2620': '#f6ead9', // accent doux, choisi, « vous »
    '#3a3f47': '#cfcac2', // neutre
    '#9a7cf0': '#5b3fb0', // violet de l'édition
    '#4caf7a': '#3f6b4a', '#2f9d64': '#2f7a4f', // vert
    '#d9675a': '#a8501e', // rouge
    '#1a1410': '#ffffff', '#0f2a1c': '#ffffff', // texte sur un aplat d'accent, de violet ou de vert
    // les fiches : bleu nuit / lie-de-vin → bleu pâle / rose pâle, comme l'application
    '#22304a': '#dbe6f2', '#24354a': '#dbe6f2', '#3c2830': '#f3dfe2', '#432b31': '#f3dfe2',
    '#4b5a74': '#8ba9c9', '#6a4d55': '#cf9aa4', // silhouettes
    '#596069': '#a39e96', '#5d6068': '#a39e96', '#4b515a': '#b5b0a8', '#4f5660': '#b5b0a8',
    '#2c3038': '#e8e6e2', '#2a2e35': '#e8e6e2', // fond d'avatar
    // les champs, les traits, les mots pas encore dits
    '#111214': '#ffffff', '#55585e': '#a39e96', '#5f5c57': '#c4bfb7', '#4a5059': '#b5b0a8',
    // la barre, la grille, les bandes
    '#17181c': '#fffefc', '#1e2026': '#e2ded8', '#1d2026': '#e2ded8',
    '#16181c': '#efece7', '#1a1c20': '#f6f4f1', '#16171a': '#ebe8e3', '#1e2127': '#e9e5de',
    // l'enregistrement
    '#3a1f1d': '#f8e1dc', '#7a3a34': '#e9b8ae',
    // le violet du cadeau (tirage au sort), comme --h-cadeau
    '#b78cf0': '#6b3fa0', '#9a6fe0': '#8456b8',
  }
  const RGBA = [
    // [r, g, b] sombre → [r, g, b] clair, l'opacité est gardée
    [[20, 21, 24], [244, 242, 239]], [[18, 20, 24], [244, 242, 239]], [[23, 24, 28], [255, 254, 252]],
    [[28, 30, 34], [255, 254, 252]], [[10, 11, 13], [60, 50, 40]],
  ]
  const inconnues = (window.__couleursInconnues = new Set())

  function traduire(c) {
    if (typeof c !== 'string') return c
    const s = c.trim().toLowerCase()
    if (s[0] === '#') {
      const base = s.length === 9 ? s.slice(0, 7) : s
      const alpha = s.length === 9 ? s.slice(7) : ''
      if (TABLE[base]) return TABLE[base] + alpha
      inconnues.add(base)
      return c
    }
    const m = s.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)$/)
    if (m) {
      const [r, g, b] = [+m[1], +m[2], +m[3]], a = m[4] === undefined ? 1 : +m[4]
      for (const [sombre, clair] of RGBA) if (sombre[0] === r && sombre[1] === g && sombre[2] === b) return `rgba(${clair.join(',')},${a})`
      // les ombres noires : bien plus légères sur fond clair
      if (r === 0 && g === 0 && b === 0) return `rgba(0,0,0,${(a * 0.35).toFixed(3)})`
      // le doigt et les reflets blancs : invisibles sur fond clair, ils deviennent brun foncé
      if (r === 255 && g === 255 && b === 255 && a < 1) return `rgba(60,45,30,${Math.min(1, a * 0.9).toFixed(3)})`
      if (r === 255 && g === 244 && b === 225) return `rgba(255,255,255,${a})` // le flash
      // un aplat d'accent translucide (surlignage de l'acte, halo)
      if (r === 227 && g === 163 && b === 95) return `rgba(138,90,43,${a})`
    }
    return c
  }

  const proto = CanvasRenderingContext2D.prototype
  for (const prop of ['fillStyle', 'strokeStyle', 'shadowColor']) {
    const d = Object.getOwnPropertyDescriptor(proto, prop)
    Object.defineProperty(proto, prop, { get: d.get, set(v) { d.set.call(this, traduire(v)) }, configurable: true })
  }
  const grad = CanvasGradient.prototype.addColorStop
  CanvasGradient.prototype.addColorStop = function (o, c) { return grad.call(this, o, traduire(c)) }
})()
