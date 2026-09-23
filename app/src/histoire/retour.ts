/**
 * Revenir de la fiche **exactement** à la vue de l'arbre qu'on avait quittée.
 *
 * Au clic sur « Fiche », l'arbre mémorise son cadrage — le point du monde au
 * centre de l'écran et l'échelle, pas des pixels : l'écran peut avoir tourné —,
 * la sélection et les filtres. La fiche voit la mémoire et propose « Retour à
 * l'arbre » ; l'arbre, en se remontant, la relit et la consomme. Le bouton
 * « précédent » du navigateur y mène aussi.
 *
 * `sessionStorage` : la mémoire vit le temps de l'onglet, et c'est ce qu'on
 * veut. Elle peut être refusée (navigation privée, stockage bloqué) : l'arbre
 * s'ouvre alors ajusté, comme d'habitude — une commodité perdue, pas une panne.
 */

const CLE = 'heritage.arbre.retour'
/** Au-delà, la mémoire est trop vieille pour qu'on s'attende encore à y revenir. */
const DUREE_MAX = 2 * 60 * 60 * 1000

export interface VueMemorisee {
  /** Point du monde au centre de l'écran. */
  x: number
  y: number
  /** Échelle horizontale. */
  s: number
  selection: string | null
  filtres: { masquees: string[]; ligneDirecte: boolean; vivants: boolean }
  /** Horodatage de la mémorisation. */
  t: number
}

function stockage(): Storage | null {
  try {
    return globalThis.sessionStorage ?? null
  } catch {
    return null
  }
}

export function memoriserVue(vue: Omit<VueMemorisee, 't'>, maintenant = Date.now()): void {
  try {
    stockage()?.setItem(CLE, JSON.stringify({ ...vue, t: maintenant }))
  } catch {
    // Stockage plein ou refusé : on reviendra sur une vue ajustée.
  }
}

function lire(maintenant: number): VueMemorisee | null {
  try {
    const brut = stockage()?.getItem(CLE)
    if (!brut) return null
    const v = JSON.parse(brut) as Partial<VueMemorisee>
    const valide =
      typeof v.x === 'number' &&
      typeof v.y === 'number' &&
      typeof v.s === 'number' &&
      v.s > 0 &&
      typeof v.t === 'number' &&
      maintenant - v.t < DUREE_MAX &&
      typeof v.filtres === 'object' &&
      v.filtres !== null
    return valide ? (v as VueMemorisee) : null
  } catch {
    return null
  }
}

/** Y a-t-il une vue de l'arbre où revenir ? La fiche s'en sert pour son bouton. */
export function vueARetrouver(maintenant = Date.now()): boolean {
  return lire(maintenant) !== null
}

/** Relit la vue mémorisée et l'efface : on n'y revient qu'une fois. */
export function reprendreVue(maintenant = Date.now()): VueMemorisee | null {
  const v = lire(maintenant)
  try {
    stockage()?.removeItem(CLE)
  } catch {
    // rien
  }
  return v
}
