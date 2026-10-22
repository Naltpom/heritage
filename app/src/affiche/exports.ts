/**
 * Les trois fichiers de l'affiche : SVG, PNG et PDF — tous fabriqués **dans le navigateur**,
 * à partir de la même scène. Rien ne part au serveur.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Le PDF passe par l'impression du navigateur, et c'est un choix
 *
 * Deux voies étaient possibles :
 *
 * - **générer le PDF ici** (jsPDF et svg2pdf, ou un écrivain maison) : 400 Ko de
 *   bibliothèques, des polices à réenregistrer sous un autre format, le texte sur arc à
 *   recomposer glyphe par glyphe, et un second moteur de rendu dont chaque écart avec
 *   l'aperçu serait un défaut de plus à chercher ;
 * - **imprimer le SVG** dans un cadre dont la page a exactement la taille de l'affiche
 *   (`@page { size: … }`, marges nulles) : c'est **le chemin de l'affiche d'origine**, qui
 *   faisait imprimer son SVG par Chrome (`--print-to-pdf`). Le PDF est vectoriel, les
 *   polices y sont embarquées et réduites par le navigateur, et il est tiré du même rendu
 *   que l'aperçu.
 *
 * On a pris la seconde. Son prix : un geste de plus — choisir « Enregistrer au format PDF »
 * dans la fenêtre d'impression —, ce que la modale dit en toutes lettres. Chrome, Edge,
 * Firefox et Safari récents respectent la taille de page demandée, jusqu'aux 220 × 127 cm.
 *
 * ## Le PNG passe par un canevas
 *
 * Le SVG, polices embarquées et dimensions **en pixels**, est décodé comme une image puis
 * dessiné dans un canevas à la taille voulue : le navigateur le rastérise directement à la
 * résolution finale, sans agrandir une petite image. La taille est bornée (`formats.ts`).
 * ────────────────────────────────────────────────────────────────────
 */
import { declarerLesPolices, type PolicesChargees } from './chargement'
import { pixels } from './formats'
import type { Scene } from './scene'
import { versSvg, type Format } from './svg'

/** Un nom de fichier sûr : « affiche-romarin-sureau-eventail ». */
export function nomDeFichier(...morceaux: string[]): string {
  return morceaux
    .join('-')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

/**
 * Le SVG produit, en nœud du document `doc`. Analysé **en XML** (`image/svg+xml`) plutôt
 * qu'écrit par `innerHTML` : le texte des noms y est déjà échappé (`echapper()`), et un
 * analyseur XML n'exécute rien de ce qu'il lit — deux protections plutôt qu'une.
 */
export function svgEnNoeud(doc: Document, svg: string): Element {
  const analyse = new DOMParser().parseFromString(svg, 'image/svg+xml')
  const racine = analyse.documentElement
  if (racine.nodeName !== 'svg') throw new Error('Le dessin de l’affiche est illisible.')
  return doc.importNode(racine, true)
}

function telecharger(blob: Blob, nom: string): void {
  const url = URL.createObjectURL(blob)
  const lien = document.createElement('a')
  lien.href = url
  lien.download = nom
  document.body.appendChild(lien)
  lien.click()
  lien.remove()
  // Laisser au navigateur le temps de commencer le téléchargement avant de libérer l'URL.
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

export function telechargerSvg(scene: Scene, format: Format | null, polices: PolicesChargees, nom: string): void {
  const svg = versSvg(scene, { format, polices: polices.base64() })
  telecharger(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }), `${nom}.svg`)
}

export async function telechargerPng(scene: Scene, page: Format, format: Format | null, dpi: number, polices: PolicesChargees, nom: string): Promise<void> {
  const [l, h] = pixels(page, dpi)
  const svg = versSvg(scene, { format, polices: polices.base64(), pixels: [l, h] })
  const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }))
  try {
    const image = new Image(l, h)
    image.src = url
    await image.decode()
    // Les polices embarquées d'une image SVG se chargent avec elle, mais pas toujours avant
    // le premier dessin : deux images de plus laissent le temps au navigateur de les poser.
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))
    const canevas = document.createElement('canvas')
    canevas.width = l
    canevas.height = h
    const contexte = canevas.getContext('2d')
    if (!contexte) throw new Error('Le navigateur n’a pas pu préparer une image de cette taille.')
    contexte.drawImage(image, 0, 0, l, h)
    const blob = await new Promise<Blob | null>((r) => canevas.toBlob(r, 'image/png'))
    if (!blob) throw new Error('Le navigateur n’a pas pu produire une image de cette taille ; essayez une résolution plus basse, ou le PDF.')
    telecharger(blob, `${nom}.png`)
    // Libérer tout de suite les centaines de mégaoctets d'un grand canevas.
    canevas.width = 0
    canevas.height = 0
  } finally {
    URL.revokeObjectURL(url)
  }
}

/**
 * Ouvre l'impression du navigateur sur une page de la taille de l'affiche. Le titre du
 * document devient le nom proposé pour le PDF.
 */
export async function imprimerPdf(scene: Scene, page: Format, format: Format | null, polices: PolicesChargees, titre: string): Promise<void> {
  const cadre = document.createElement('iframe')
  cadre.setAttribute('aria-hidden', 'true')
  cadre.tabIndex = -1
  // Hors de l'écran, mais avec une taille : un cadre `display: none` ne s'imprime pas.
  cadre.style.cssText = 'position:fixed;left:-10000px;top:0;width:10px;height:10px;border:0;opacity:0'
  document.body.appendChild(cadre)
  try {
    const fenetre = cadre.contentWindow as (Window & typeof globalThis) | null
    const doc = cadre.contentDocument
    if (!fenetre || !doc) throw new Error('Le cadre d’impression n’a pas pu être préparé.')
    const l = page.largeur.toFixed(2)
    const h = page.hauteur.toFixed(2)
    doc.open()
    doc.write(
      `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title></title>` +
        `<style>@page{size:${l}mm ${h}mm;margin:0}html,body{margin:0;padding:0;background:#fff}` +
        `svg{display:block;width:${l}mm;height:${h}mm}</style></head><body></body></html>`,
    )
    doc.close()
    doc.title = titre
    // Les polices passent par `FontFace`, comme pour l'aperçu : le cadre hérite de la CSP de
    // la page, qui refuserait une `@font-face` en `data:`.
    await declarerLesPolices(fenetre, polices.octets)
    doc.body.appendChild(svgEnNoeud(doc, versSvg(scene, { format })))
    await doc.fonts.ready
    fenetre.focus()
    fenetre.print()
  } finally {
    // `print()` rend la main à la fermeture de la fenêtre d'impression ; Safari, avant :
    // on laisse au cadre le temps d'être imprimé.
    setTimeout(() => cadre.remove(), 60_000)
  }
}
