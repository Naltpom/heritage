/**
 * Le rendu SVG d'une scène — la classe `Svg` de `commun.py`, et `mettre_au_format()`.
 *
 * Pur : une scène entre, une chaîne sort. Le même rendu sert trois usages, qui ne diffèrent
 * que par les options :
 *
 * - **l'aperçu** dans la modale : sans polices embarquées — elles sont déjà déclarées dans
 *   le document (`chargement.ts`, `FontFace`), sous les mêmes noms de famille ;
 * - **le téléchargement SVG** : polices embarquées en `data:` (TrueType), pour que le
 *   fichier s'ouvre tel quel ailleurs ;
 * - **le PNG** : le SVG embarqué, dimensionné en pixels, dessiné dans un canevas.
 *
 * Les attributs et leur ordre sont ceux du script d'origine, au millième de millimètre :
 * deux rendus se comparent ainsi ligne à ligne.
 */
import { f3, polaire, type Primitive, type Scene } from './scene'

/** Un format imposé, en millimètres. */
export interface Format {
  largeur: number
  hauteur: number
  /**
   * La boîte du dessin, centrée sur la page (le format sur mesure) : le dessin la remplit, et ce
   * qui dépasse de la page est rogné — ce qui manque est du papier. Absente : la page elle-même.
   */
  dessin?: { largeur: number; hauteur: number } | null
}

export interface OptionsDeRendu {
  /** Le format de la page : le dessin y est agrandi (ou réduit) pour le remplir, centré. */
  format?: Format | null
  /** Les polices à embarquer, en base64, par clé (`SS-Txt-Reg`…). Absentes : aucune `@font-face`. */
  polices?: ReadonlyMap<string, string> | null
  /** Largeur et hauteur **en pixels** (rendu PNG) ; sinon en millimètres. */
  pixels?: readonly [number, number] | null
}

/** `html.escape(t, quote=True)`. */
export function echapper(t: string): string {
  return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#x27;')
}

/** L'agrandissement et le décalage qui posent un dessin `l × h` au centre d'un format (dans sa boîte, s'il en a une). */
export function mettreAuFormat(l: number, h: number, format: Format): { k: number; dx: number; dy: number } {
  const boite = format.dessin ?? format
  const k = Math.min(boite.largeur / l, boite.hauteur / h)
  return { k, dx: (format.largeur - l * k) / 2, dy: (format.hauteur - h * k) / 2 }
}

export function versSvg(scene: Scene, options: OptionsDeRendu = {}): string {
  const defs: string[] = []
  const corps: string[] = []
  let n = 0

  for (const e of scene.elements) corps.push(primitive(e, defs, () => `arc${++n}`))

  const polices: string[] = []
  if (options.polices) {
    for (const cle of [...scene.polices].sort()) {
      const donnees = options.polices.get(cle)
      if (donnees) polices.push(`@font-face{font-family:'${cle}';src:url(data:font/ttf;base64,${donnees}) format('truetype')}`)
    }
  }

  const { largeur: w, hauteur: h } = scene
  const page = options.format ?? { largeur: w, hauteur: h }
  const [lAttr, hAttr] = options.pixels ? [`${Math.round(options.pixels[0])}`, `${Math.round(options.pixels[1])}`] : [`${page.largeur.toFixed(2)}mm`, `${page.hauteur.toFixed(2)}mm`]

  const tete =
    `<svg xmlns="http://www.w3.org/2000/svg" width="${lAttr}" height="${hAttr}" viewBox="0 0 ${f3(page.largeur)} ${f3(page.hauteur)}">\n` +
    (polices.length > 0 ? `<style>${polices.join('')}</style>\n` : '') +
    `<defs>${defs.join('')}</defs>\n`

  if (!options.format) {
    return tete + `<rect width="${f3(w)}" height="${f3(h)}" fill="${scene.fond}"/>\n` + corps.join('\n') + '\n</svg>\n'
  }
  const { k, dx, dy } = mettreAuFormat(w, h, options.format)
  return (
    tete +
    `<rect width="${f3(page.largeur)}" height="${f3(page.hauteur)}" fill="${scene.fond}"/>\n` +
    `<g transform="translate(${f3(dx)},${f3(dy)}) scale(${k.toFixed(6)})">\n` +
    `<rect width="${f3(w)}" height="${f3(h)}" fill="${scene.fond}"/>\n` +
    corps.join('\n') +
    '\n</g>\n</svg>\n'
  )
}

function primitive(e: Primitive, defs: string[], nouvelId: () => string): string {
  switch (e.type) {
    case 'chemin': {
      const trait = e.trait ? ` stroke="${e.trait}" stroke-width="${e.epaisseur ?? 1}"` : ''
      const bouts = e.arrondi ? ' stroke-linejoin="round" stroke-linecap="round"' : ''
      const tirets = e.tirets ? ` stroke-dasharray="${e.tirets}"` : ''
      return `<path d="${e.d}" fill="${e.fond}"${trait}${bouts}${tirets}/>`
    }
    case 'rect': {
      const trait = e.trait ? ` stroke="${e.trait}" stroke-width="${e.epaisseur}"` : ''
      return `<rect x="${f3(e.x)}" y="${f3(e.y)}" width="${f3(e.l)}" height="${f3(e.h)}" rx="${e.rayon}" fill="${e.fond}"${trait}/>`
    }
    case 'ligne':
      return `<line x1="${f3(e.x1)}" y1="${f3(e.y1)}" x2="${f3(e.x2)}" y2="${f3(e.y2)}" stroke="${e.trait}" stroke-width="${e.epaisseur}" stroke-linecap="round"${e.transform ? ` transform="${e.transform}"` : ''}/>`
    case 'texte': {
      const st = e.style
      const attrs = [`x="${f3(e.x)}"`, `y="${f3(e.y)}"`, `font-family="${st.cle}"`, `font-size="${f3(st.mm)}"`, `fill="${st.couleur}"`]
      if (e.ancre !== 'start') attrs.push(`text-anchor="${e.ancre}"`)
      if (st.espacement) attrs.push(`letter-spacing="${f3(st.espacement * st.mm)}"`)
      if (e.transform) attrs.push(`transform="${e.transform}"`)
      if (e.compression) attrs.push(`textLength="${f3(e.compression)}" lengthAdjust="spacingAndGlyphs"`)
      return `<text ${attrs.join(' ')}>${echapper(e.texte)}</text>`
    }
    case 'texteSurArc': {
      const st = e.style
      const id = nouvelId()
      // Toujours de gauche à droite : par le haut (de a2 à a1, sens horaire à l'écran) au-dessus
      // du centre ; par le bas (de a1 à a2, sens trigonométrique) en dessous, le haut des
      // lettres tourné vers le centre.
      const dessous = e.dessous ?? Math.sin(((e.a1 + e.a2) / 2) * (Math.PI / 180)) < 0
      const [x1, y1] = polaire(e.cx, e.cy, e.r, dessous ? e.a1 : e.a2)
      const [x2, y2] = polaire(e.cx, e.cy, e.r, dessous ? e.a2 : e.a1)
      const grand = e.a2 - e.a1 > 180 ? 1 : 0
      defs.push(`<path id="${id}" d="M${f3(x1)},${f3(y1)} A${f3(e.r)},${f3(e.r)} 0 ${grand} ${dessous ? 0 : 1} ${f3(x2)},${f3(y2)}"/>`)
      const esp = st.espacement ? ` letter-spacing="${f3(st.espacement * st.mm)}"` : ''
      return (
        `<text font-family="${st.cle}" font-size="${f3(st.mm)}" fill="${st.couleur}"${esp}>` +
        `<textPath href="#${id}" startOffset="50%" text-anchor="middle">${echapper(e.texte)}</textPath></text>`
      )
    }
  }
}
