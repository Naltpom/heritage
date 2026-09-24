/**
 * Découper le carré d'un portrait dans une image — **le seul endroit** où ce
 * carré devient un fichier.
 *
 * « Ma photo » (`ChoixPortrait.vue`, par `RecadrageSousCercle.vue`) et les
 * portraits repérés par l'assistant envoient exactement la même chose : un JPEG
 * carré, à la résolution réelle du carré découpé, 1024 px au plus
 * (`coteDeSortie`). Le calcul du carré est pur (`cadrage.ts`) ; ceci n'est que
 * la toile.
 */
import { coteDeSortie } from './cadrage'

/** Le carré `zone` (pixels de l'image) de `image`, en JPEG. */
export async function decouperCarre(
  image: CanvasImageSource,
  zone: { x: number; y: number; cote: number },
  nom = 'portrait.jpg',
): Promise<File> {
  const n = coteDeSortie(zone.cote)
  const toile = document.createElement('canvas')
  toile.width = n
  toile.height = n
  const ctx = toile.getContext('2d')!
  ctx.imageSmoothingQuality = 'high'
  ctx.drawImage(image, zone.x, zone.y, zone.cote, zone.cote, 0, 0, n, n)
  const blob = await new Promise<Blob | null>((ok) => toile.toBlob(ok, 'image/jpeg', 0.9))
  if (!blob) throw new Error("La photo n'a pas pu être préparée.")
  return new File([blob], nom, { type: 'image/jpeg' })
}

/**
 * Décode un fichier image dans une `<img>` prête à dessiner. L'URL d'objet
 * reste attachée à l'image (`image.src`) : c'est à l'appelant de la révoquer
 * quand l'image ne sert plus. `null` si le navigateur ne sait pas la lire
 * (HEIC sous Chrome, fichier abîmé) — l'URL est alors déjà révoquée.
 */
export async function decoderImage(source: Blob): Promise<HTMLImageElement | null> {
  const url = URL.createObjectURL(source)
  const img = new Image()
  img.src = url
  try {
    await img.decode()
    return img
  } catch {
    URL.revokeObjectURL(url)
    return null
  }
}
