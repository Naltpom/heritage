/**
 * Pivoter une photo **avant** de l'envoyer.
 *
 * Le navigateur applique déjà l'orientation EXIF quand il décode une image
 * (`image-orientation: from-image`, le défaut) : ce qu'on dessine sur la toile
 * est donc ce que le lecteur voit à l'écran, et c'est ce qu'on fait tourner. Le
 * fichier produit n'a plus d'EXIF — le serveur l'aurait retiré de toute façon.
 *
 * Un PDF, ou une image que le navigateur ne sait pas décoder, ressort tel quel :
 * on ne bloque pas un envoi pour une rotation.
 */

export type Angle = 90 | 180 | 270

const TYPES_PIVOTABLES = new Set(['image/jpeg', 'image/png', 'image/webp'])

export function estPivotable(fichier: File): boolean {
  return TYPES_PIVOTABLES.has(fichier.type)
}

/** Dessine l'image tournée d'un angle horaire et l'encode. `null` si la toile
 *  refuse (mémoire, contexte indisponible). */
export async function pivoterImage(image: HTMLImageElement, angle: Angle, type: string): Promise<Blob | null> {
  const [largeur, hauteur] = [image.naturalWidth, image.naturalHeight]
  const couchee = angle === 180
  const toile = document.createElement('canvas')
  toile.width = couchee ? largeur : hauteur
  toile.height = couchee ? hauteur : largeur

  const contexte = toile.getContext('2d')
  if (contexte === null) return null
  contexte.translate(toile.width / 2, toile.height / 2)
  contexte.rotate((angle * Math.PI) / 180)
  contexte.drawImage(image, -largeur / 2, -hauteur / 2)

  return new Promise<Blob | null>((resoudre) => toile.toBlob(resoudre, type, 0.92))
}

export async function pivoterFichier(fichier: File, angle: Angle): Promise<File> {
  if (!estPivotable(fichier)) return fichier

  const url = URL.createObjectURL(fichier)
  try {
    const image = new Image()
    image.src = url
    await image.decode()
    const blob = await pivoterImage(image, angle, fichier.type)
    if (blob === null) return fichier
    return new File([blob], fichier.name, { type: fichier.type, lastModified: Date.now() })
  } catch {
    return fichier
  } finally {
    URL.revokeObjectURL(url)
  }
}
