/**
 * **Des étiquettes qui ne se chevauchent jamais** — les noms de foyer au-dessus des
 * couples (niveau Familles) et les noms du bandeau des familles.
 *
 * Les captures du 01/10 montraient « Provos Provost », « ProvoBORNERIE », « Delobe
 * Delobel » : chaque étiquette était posée seule, filtrée sur la largeur de son couple
 * mais jamais sur la sienne, ni sur ses voisines. Ici, on les pose dans l'ordre de leur
 * PRIORITÉ, et chacune ne prend que la place libre :
 *
 *  1. entière, à l'endroit voulu, si elle tient ;
 *  2. sinon décalée dans l'intervalle permis (le couple, la part visible d'une bande)
 *     jusqu'au plus proche creux où elle tient ;
 *  3. sinon sans sa seconde ligne ;
 *  4. sinon TRONQUÉE avec « … » au plus grand creux de l'intervalle ;
 *  5. sinon pas d'étiquette — mieux vaut un nom de moins que deux noms illisibles.
 *
 * Par construction, deux étiquettes posées sur la même ligne ne se recouvrent pas,
 * marge comprise. La largeur d'un texte est ESTIMÉE (pas de canevas par image), et
 * l'estimation est volontairement généreuse : gras, capitales.
 *
 * Pur, sans DOM : tout se vérifie dans `etiquettes.test.ts`.
 */

/** Signes étroits et larges, pour une estimation qui ne sous-estime pas « BORNERIE ». */
const ETROITS = new Set([...'iljtfrI.,;:\'’ !|'])
const LARGES = new Set([...'mwMW@%'])

/**
 * Largeur estimée d'un texte en gras, en pixels. Généreuse : une capitale compte plus
 * qu'une minuscule, « m » et « w » plus encore. Mieux vaut un nom tronqué de trop
 * qu'un nom qui déborde sur son voisin.
 */
export function largeurDuTexte(texte: string, taille: number): number {
  let em = 0
  for (const c of texte) {
    if (LARGES.has(c)) em += 0.92
    else if (ETROITS.has(c)) em += 0.36
    else if (c !== c.toLowerCase()) em += 0.76
    else em += 0.62
  }
  return em * taille
}

/** Le signe de troncature. */
export const ELLIPSE = '…'
/** En deçà de trois signes avant l'ellipse, un nom tronqué ne se reconnaît plus. */
const SIGNES_MIN = 3

/**
 * Le nom ramené à `largeurMax`, terminé par « … » s'il a fallu couper ; `null` s'il
 * n'en resterait pas trois signes.
 */
export function tronquer(nom: string, largeurMax: number, taille: number): string | null {
  if (largeurDuTexte(nom, taille) <= largeurMax) return nom
  const signes = [...nom]
  for (let n = signes.length - 1; n >= SIGNES_MIN; n--) {
    const essai = signes.slice(0, n).join('').trimEnd() + ELLIPSE
    if (largeurDuTexte(essai, taille) <= largeurMax) return essai
  }
  return null
}

export interface DemandeDEtiquette {
  cle: string
  /** Centre souhaité, à l'écran. */
  x: number
  /** Ligne de l'étiquette : deux étiquettes dont les `y` diffèrent de moins de `hauteur` se gênent. */
  y: number
  nom: string
  taille: number
  /** Seconde ligne facultative, abandonnée la première quand la place manque. */
  sous?: string
  tailleSous?: number
  /** L'intervalle où l'étiquette ENTIÈRE doit tenir (bords compris). */
  min: number
  max: number
  /** La plus grande est posée la première. */
  priorite: number
}

export interface EtiquettePosee {
  cle: string
  /** Centre, à l'écran. */
  x: number
  y: number
  /** Le nom tel qu'il s'écrit — tronqué s'il a fallu. */
  nom: string
  sous: string
  /** Largeur occupée (la plus large des deux lignes). */
  largeur: number
}

/** Les creux de `[min, max]` que laissent des intervalles occupés. */
function creux(min: number, max: number, occupes: [number, number][]): [number, number][] {
  const libres: [number, number][] = []
  let debut = min
  for (const [a, b] of [...occupes].sort((p, q) => p[0] - q[0])) {
    if (b <= debut) continue
    if (a >= max) break
    if (a > debut) libres.push([debut, a])
    debut = Math.max(debut, b)
  }
  if (debut < max) libres.push([debut, max])
  return libres
}

/**
 * Pose les étiquettes sans chevauchement, la plus prioritaire d'abord. Rend celles qui
 * ont trouvé leur place, dans l'ordre de pose.
 */
export function poserEtiquettes(
  demandes: readonly DemandeDEtiquette[],
  { marge = 6, hauteur = 14 }: { marge?: number; hauteur?: number } = {},
): EtiquettePosee[] {
  const posees: EtiquettePosee[] = []
  const ordre = [...demandes].sort((a, b) => b.priorite - a.priorite)

  for (const d of ordre) {
    if (d.max <= d.min) continue
    const occupes: [number, number][] = posees
      .filter((p) => Math.abs(p.y - d.y) < hauteur)
      .map((p) => [p.x - p.largeur / 2 - marge, p.x + p.largeur / 2 + marge])
    const libres = creux(d.min, d.max, occupes)
    if (!libres.length) continue

    const largeurNom = largeurDuTexte(d.nom, d.taille)
    const largeurSous = d.sous ? largeurDuTexte(d.sous, d.tailleSous ?? d.taille) : 0
    let posee: EtiquettePosee | null = null

    // 1-3 : entière (avec, puis sans la seconde ligne), au creux le plus proche.
    for (const sous of d.sous ? [d.sous, ''] : ['']) {
      const w = Math.max(largeurNom, sous ? largeurSous : 0)
      let meilleur: number | null = null
      for (const [a, b] of libres) {
        if (b - a < w) continue
        const x = Math.min(b - w / 2, Math.max(a + w / 2, d.x))
        if (meilleur === null || Math.abs(x - d.x) < Math.abs(meilleur - d.x)) meilleur = x
      }
      if (meilleur !== null) {
        posee = { cle: d.cle, x: meilleur, y: d.y, nom: d.nom, sous, largeur: w }
        break
      }
    }

    // 4 : tronquée, dans le plus grand creux (à égalité, le plus proche).
    if (!posee) {
      const [a, b] = libres.reduce((m, c) => {
        const dc = c[1] - c[0]
        const dm = m[1] - m[0]
        if (dc > dm + 0.5) return c
        if (Math.abs(dc - dm) <= 0.5 && Math.abs((c[0] + c[1]) / 2 - d.x) < Math.abs((m[0] + m[1]) / 2 - d.x)) return c
        return m
      })
      const nom = tronquer(d.nom, b - a, d.taille)
      if (nom !== null) {
        const w = largeurDuTexte(nom, d.taille)
        posee = { cle: d.cle, x: Math.min(b - w / 2, Math.max(a + w / 2, d.x)), y: d.y, nom, sous: '', largeur: w }
      }
    }

    if (posee) posees.push(posee)
  }
  return posees
}
