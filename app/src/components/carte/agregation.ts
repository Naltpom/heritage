/**
 * Des événements aux bulles : le regroupement par lieu.
 *
 * Tout est **pur**. Leaflet touche le DOM et ne peut pas être monté sous
 * l'environnement `node` de vitest ; c'est donc ici que vit tout ce qui se
 * décide, et `CarteLeaflet.vue` ne fait plus que dessiner ce qu'on lui rend.
 *
 * ────────────────────────────────────────────────────────────────────
 * **On regroupe par `placeId`, jamais par la chaîne brute.** Deux graphies
 * d'une même commune — « Saule, Ombrage, Sylvanie » et « SAULE (Ombrage) » —
 * doivent s'effondrer sur une seule bulle, et c'est le serveur qui a fait ce
 * rapprochement, en base, avec le gazetteer. Rapprocher des chaînes ici en
 * ferait un second endroit qui décide, et il dessinerait deux cercles distants
 * d'un mètre que personne ne saurait expliquer.
 *
 * La graphie verbatim reste sur chaque événement, et la bulle porte la liste de
 * celles qui ont abouti chez elle : c'est ce qui permet de **repérer un mauvais
 * géocodage**, quand deux orthographes qui n'avaient rien à voir se retrouvent
 * ensemble.
 *
 * **Les lieux non situés ne disparaissent pas.** Un événement dont le lieu est
 * écrit mais n'a pas de coordonnées est mis de côté, compté, et énumérable dans
 * l'interface. Le faire disparaître serait mentir par omission — et c'est le
 * seul chiffre qui dise s'il vaut la peine de rejouer `heritage:resoudre-les-
 * lieux` sur le serveur.
 * ────────────────────────────────────────────────────────────────────
 */
import type { EvenementCarte, GenreEvenement, LieuCarte, VueCarte } from '@/lib/api'

export interface Bulle {
  placeId: number
  libelle: string
  lat: number
  lon: number
  confiance: number
  source: string
  /** Les graphies brutes qui ont abouti ici. */
  brut: readonly string[]
  /** Tous les événements du lieu, filtres du lecteur appliqués. */
  total: number
  parGenre: Readonly<Partial<Record<GenreEvenement, number>>>
  anneeMin: number
  anneeMax: number
  /** La médiane, et non la moyenne : une naissance de 2014 ne doit pas tirer un
   *  hameau du XVIIᵉ vers l'extrémité moderne du dégradé. */
  anneeMediane: number
  evenements: readonly EvenementCarte[]
}

export interface Regroupement {
  bulles: readonly Bulle[]
  /** Les événements dont le lieu est écrit mais n'a pas été situé. */
  nonSitues: readonly EvenementCarte[]
  /** Combien d'événements les filtres retiennent, tous lieux confondus. */
  retenus: number
}

export function regrouperParLieu(
  vue: VueCarte | null,
  actifs: ReadonlySet<GenreEvenement>,
): Regroupement {
  if (vue === null) return { bulles: [], nonSitues: [], retenus: 0 }

  const lieux = new Map<number, LieuCarte>()
  for (const lieu of vue.lieux) lieux.set(lieu.placeId, lieu)

  const parLieu = new Map<number, EvenementCarte[]>()
  const nonSitues: EvenementCarte[] = []
  let retenus = 0

  for (const evenement of vue.evenements) {
    if (!actifs.has(evenement.genre)) continue
    retenus += 1

    if (evenement.placeId === null || !lieux.has(evenement.placeId)) {
      nonSitues.push(evenement)
      continue
    }
    const liste = parLieu.get(evenement.placeId)
    if (liste === undefined) parLieu.set(evenement.placeId, [evenement])
    else liste.push(evenement)
  }

  const bulles: Bulle[] = []
  for (const [placeId, evenements] of parLieu) {
    const lieu = lieux.get(placeId)
    if (lieu === undefined) continue

    const annees = evenements.map((e) => e.annee).sort((a, b) => a - b)
    const parGenre: Partial<Record<GenreEvenement, number>> = {}
    for (const e of evenements) parGenre[e.genre] = (parGenre[e.genre] ?? 0) + 1

    bulles.push({
      placeId,
      libelle: lieu.libelle,
      lat: lieu.lat,
      lon: lieu.lon,
      confiance: lieu.confiance,
      source: lieu.source,
      brut: lieu.brut,
      total: evenements.length,
      parGenre,
      anneeMin: annees[0] ?? 0,
      anneeMax: annees[annees.length - 1] ?? 0,
      anneeMediane: mediane(annees),
      evenements,
    })
  }

  return { bulles: ordreDeTrace(bulles), nonSitues, retenus }
}

/**
 * La médiane d'une liste **déjà triée**, valeur basse sur un compte pair.
 *
 * **Sans interpolation, et c'est le point.** Interpoler entre 1743 et 1961
 * produirait 1852 — une année où il ne s'est rien passé à cet endroit. Une
 * médiane doit être une valeur observée ; c'est ce qui la distingue d'une
 * moyenne, et c'est pour cela qu'on l'a choisie.
 */
export function mediane(anneesTriees: readonly number[]): number {
  if (anneesTriees.length === 0) return 0
  return anneesTriees[Math.floor((anneesTriees.length - 1) / 2)] ?? 0
}

/**
 * L'ordre dans lequel Leaflet doit poser les cercles.
 *
 * **Ce seul détail décide si la carte est utilisable.** Leaflet dessine dans
 * l'ordre d'insertion à l'intérieur d'un calque : la dernière bulle posée est
 * au-dessus, et c'est elle qui reçoit le clic. En posant les grandes d'abord,
 * le village à deux événements atterrit sur la ville à quarante et reste
 * atteignable. L'ordre inverse l'enterre, et rien ne le signale.
 *
 * `placeId` départage à total égal, pour que deux chargements successifs ne
 * dessinent pas le même écran dans un ordre différent.
 */
export function ordreDeTrace(bulles: readonly Bulle[]): Bulle[] {
  return [...bulles].sort((a, b) => b.total - a.total || a.placeId - b.placeId)
}

/** Le plus grand nombre d'événements sur un lieu — le dénominateur de l'échelle
 *  des tailles. `0` quand il n'y a rien : l'appelant n'a alors aucune bulle à
 *  dessiner et n'a pas à diviser. */
export function maximum(bulles: readonly Bulle[]): number {
  let max = 0
  for (const b of bulles) if (b.total > max) max = b.total
  return max
}

/** Les bornes du dégradé « par date », sur les **médianes** — pas sur les
 *  extrêmes de chaque lieu, qui étaleraient l'échelle sur des valeurs
 *  qu'aucune bulle ne porte. */
export function bornesDesMedianes(bulles: readonly Bulle[]): { min: number; max: number } | null {
  if (bulles.length === 0) return null
  let min = Number.POSITIVE_INFINITY
  let max = Number.NEGATIVE_INFINITY
  for (const b of bulles) {
    if (b.anneeMediane < min) min = b.anneeMediane
    if (b.anneeMediane > max) max = b.anneeMediane
  }
  return { min, max }
}

/** Le cadrage de départ, ou `null` quand aucun lieu n'est situé — et non une
 *  boîte vide, que Leaflet interpréterait comme le point (0, 0), au large du
 *  golfe de Guinée. */
export function cadrageInitial(
  bulles: readonly Bulle[],
): [[number, number], [number, number]] | null {
  if (bulles.length === 0) return null
  let sudLat = Number.POSITIVE_INFINITY
  let ouestLon = Number.POSITIVE_INFINITY
  let nordLat = Number.NEGATIVE_INFINITY
  let estLon = Number.NEGATIVE_INFINITY
  for (const b of bulles) {
    if (b.lat < sudLat) sudLat = b.lat
    if (b.lat > nordLat) nordLat = b.lat
    if (b.lon < ouestLon) ouestLon = b.lon
    if (b.lon > estLon) estLon = b.lon
  }
  return [
    [sudLat, ouestLon],
    [nordLat, estLon],
  ]
}
