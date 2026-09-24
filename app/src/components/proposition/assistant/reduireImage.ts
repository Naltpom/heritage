/**
 * Réduire une photo avant de l'envoyer à l'assistant.
 *
 * Une photo de téléphone pèse 3 à 8 Mo pour 4000 px de côté : l'acte y est lisible
 * à 2000 px. Or tout le trajet est borné — 25 Mo par requête (nginx, PHP), et le
 * modèle refuse au-delà d'environ 20 Mo de documents en ligne. Deux photos brutes
 * suffisaient à frôler la limite ; réduites, six tiennent largement.
 *
 * Seules les images que le navigateur sait décoder (JPEG, PNG, WebP) sont
 * réduites, et seulement si elles dépassent 2000 px ou 1,5 Mo. Un PDF, un HEIC
 * (que Chrome ne décode pas) ou un échec quelconque : on garde le fichier tel
 * quel — le serveur tranchera, avec un message clair.
 */

const COTE_MAX = 2000
const POIDS_SANS_REDUCTION = 1.5 * 1024 * 1024
const DECODABLES = new Set(['image/jpeg', 'image/png', 'image/webp'])

export async function reduireSiImage(fichier: File): Promise<File> {
  if (!DECODABLES.has(fichier.type) || typeof createImageBitmap !== 'function') return fichier
  try {
    const image = await createImageBitmap(fichier)
    const echelle = Math.min(1, COTE_MAX / Math.max(image.width, image.height))
    if (echelle === 1 && fichier.size <= POIDS_SANS_REDUCTION) {
      image.close()
      return fichier
    }
    const toile = document.createElement('canvas')
    toile.width = Math.round(image.width * echelle)
    toile.height = Math.round(image.height * echelle)
    const ctx = toile.getContext('2d')
    if (!ctx) return fichier
    ctx.imageSmoothingQuality = 'high'
    ctx.drawImage(image, 0, 0, toile.width, toile.height)
    image.close()
    const blob = await new Promise<Blob | null>((ok) => toile.toBlob(ok, 'image/jpeg', 0.85))
    if (!blob || blob.size >= fichier.size) return fichier
    const nom = fichier.name.replace(/\.[^.]+$/, '') + '.jpg'
    return new File([blob], nom, { type: 'image/jpeg', lastModified: fichier.lastModified })
  } catch {
    return fichier
  }
}
