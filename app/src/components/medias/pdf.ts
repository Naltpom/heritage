/**
 * Lire un PDF dans la page, comme une image — avec pdf.js, **chargé à la demande**.
 *
 * Un acte scanné arrive très souvent en PDF : c'est ce que délivre une mairie qui
 * envoie une copie intégrale. Jusqu'ici la loupe le renvoyait vers un nouvel
 * onglet, et la galerie affichait une vignette cassée — le serveur n'en produit
 * pas pour les PDF (voir `GenerateurDeVignette` : rastériser côté serveur
 * demanderait Ghostscript ou `imagick`). On lisait donc les images sur la fiche,
 * et les PDF ailleurs.
 *
 * **Pourquoi dans le navigateur et pas dans un `<iframe>`.** La réponse de
 * `/api/medias/{id}/fichier` porte `X-Frame-Options: DENY` et
 * `frame-ancestors 'none'`, et c'est voulu : aucun fichier de l'API ne s'encadre.
 * Et la visionneuse intégrée d'un téléphone Android ne s'affiche pas dans un
 * cadre de toute façon. pdf.js dessine chaque page dans un `<canvas>`, sur tous
 * les navigateurs, sans toucher aux en-têtes.
 *
 * **Pourquoi à la demande.** pdf.js et son worker pèsent plus lourd que tout le
 * reste de l'application. Une fiche sans PDF ne doit pas les télécharger : les
 * deux `import()` ne partent qu'au premier PDF affiché, et une seule fois.
 *
 * **Ce que la CSP du site impose** (`app/nginx.conf`), et qui se règle ici :
 *
 * - `script-src 'self'` sans `'wasm-unsafe-eval'` : le WebAssembly de pdf.js est
 *   refusé. On passe `useWasm: false`, et le worker importe les versions
 *   JavaScript de ses décodeurs d'images — CCITT, JBIG2, JPEG 2000 — depuis
 *   `/pdfjs-decodeurs/` (voir `vite.config.ts`). **Sans elles, un scan de mairie
 *   s'affiche sans son texte**, qui y est un masque CCITT posé sur un fond JPEG.
 *   Seul le profil couleur ICC reste perdu, sans effet visible sur un acte.
 * - `font-src 'self'` : les polices embarquées ne passent pas en `FontFace` depuis
 *   des données en mémoire. `disableFontFace` fait dessiner les glyphes en tracés,
 *   ce qui revient au même à l'écran.
 * - le worker est servi par Vite depuis la même origine : `script-src 'self'` le
 *   couvre, faute de `worker-src`.
 */
import type { PDFDocumentProxy, PDFPageProxy } from 'pdfjs-dist'

/** Un PDF ouvert, et le seul geste qui libère son worker : `fermer()`. */
export interface PdfOuvert {
  document: PDFDocumentProxy
  fermer: () => Promise<void>
}

type PdfJs = typeof import('pdfjs-dist')

let chargement: Promise<PdfJs> | null = null

function chargerPdfJs(): Promise<PdfJs> {
  chargement ??= Promise.all([
    import('pdfjs-dist'),
    import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
  ])
    .then(([module, worker]) => {
      module.GlobalWorkerOptions.workerSrc = worker.default
      return module
    })
    .catch((cause: unknown) => {
      // Un échec réseau ne doit pas condamner les PDF suivants de la session.
      chargement = null
      throw cause
    })
  return chargement
}

/**
 * Où le worker va chercher ses décodeurs d'images (CCITT, JBIG2, JPEG 2000). Le
 * chemin est fixé par `vite.config.ts`, qui les sert en développement et les
 * copie dans le build ; il est absolu parce que le worker résout une URL relative
 * contre sa propre adresse, pas contre celle de la page.
 */
function adresseDesDecodeurs(): string {
  return new URL(`${import.meta.env.BASE_URL}pdfjs-decodeurs/`, window.location.origin).href
}

/**
 * Le document, lu depuis l'URL que l'API a rendue — même origine, cookie compris.
 *
 * Depuis pdf.js 6, c'est la **tâche de chargement** qui se détruit, plus le
 * document : on rend donc les deux ensemble, pour qu'aucun appelant ne garde un
 * worker ouvert faute d'avoir su quoi fermer.
 */
export async function ouvrirPdf(url: string): Promise<PdfOuvert> {
  const { getDocument } = await chargerPdfJs()
  const tache = getDocument({
    url,
    disableFontFace: true,
    useWasm: false,
    wasmUrl: adresseDesDecodeurs(),
  })
  try {
    return { document: await tache.promise, fermer: () => tache.destroy() }
  } catch (cause) {
    await tache.destroy()
    throw cause
  }
}

/**
 * L'échelle à laquelle dessiner une page pour qu'elle remplisse `largeurCible`
 * pixels CSS **nets** sur l'écran du lecteur.
 *
 * La densité est bornée à 3 : au-delà, un acte de quatre pages sur un écran très
 * dense décoderait des dizaines de mégapixels pour un gain invisible.
 */
export function echelleDeRendu(largeurPage: number, largeurCible: number, densite: number): number {
  if (!(largeurPage > 0) || !(largeurCible > 0)) return 1
  const densiteBornee = Math.min(Math.max(Number.isFinite(densite) ? densite : 1, 1), 3)
  return (largeurCible / largeurPage) * densiteBornee
}

/** Dessine une page dans une toile, à la largeur CSS demandée. */
export async function dessinerPage(
  page: PDFPageProxy,
  toile: HTMLCanvasElement,
  largeurCible: number,
): Promise<void> {
  const naturelle = page.getViewport({ scale: 1 })
  const vue = page.getViewport({
    scale: echelleDeRendu(naturelle.width, largeurCible, window.devicePixelRatio),
  })
  toile.width = Math.floor(vue.width)
  toile.height = Math.floor(vue.height)
  await page.render({ canvas: toile, viewport: vue }).promise
}
