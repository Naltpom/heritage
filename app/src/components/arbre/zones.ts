/**
 * Les **zones de branche** : un aplat coloré sous l'arbre, par région logique.
 *
 * L'arbre se lit en cinq régions — `pp`, `pm` de part et d'autre du `tr` (le
 * tronc), puis `mp`, `mm` —, et rien à l'écran ne les montrait : la branche ne
 * se voyait que sur le liseré de 4 unités d'une fiche, invisible dès qu'on
 * s'éloigne. Ce module calcule la géométrie de ces aplats. Il ne touche pas au
 * DOM : {@link construireCanevas} s'en charge, et ces fonctions se testent donc
 * sans navigateur ni faux document.
 *
 * ────────────────────────────────────────────────────────────────────
 * **POURQUOI PAS UN RECTANGLE PAR BRANCHE.** C'était la forme évidente, et elle
 * est fausse : les emprises globales se chevauchent. Mesuré sur le graphe servi
 * (274 personnes, 12 générations), `tr` recouvre `pm` sur 5 590 unités, `mp` sur
 * 3 482 et `mm` sur 615 — trois paires de bandes superposées sur cinq régions.
 * On peint donc **rangée par rangée**, une bande par génération, empilées.
 *
 * **ET POURQUOI PAS UNE BANDE PAR (BRANCHE, RANGÉE) NON PLUS.** C'est le piège,
 * et il ne se voit que sur la rangée des parents. À `y = -150`, le tronc ne
 * compte que **deux** personnes — le père et la mère de la racine — et le moteur
 * les pose chacun au-dessus de sa propre moitié d'arbre : `x = -4 700` et
 * `x = +4 980`, soit **9 544 unités d'écart**, quand le plus grand écart interne
 * légitime mesuré ailleurs dans l'arbre est de 1 815. Une bande unique par
 * (branche, rangée) donnerait donc au tronc, sur cette rangée, un aplat large de
 * dix mille unités qui avale celui de `pm` — la couleur cesserait de dire où
 * sont les gens pour ne plus dire que de qui ils descendent.
 *
 * **La coupure ne se fait pas sur une distance.** Un seuil en unités aurait
 * fonctionné ici — l'intervalle [1 815, 9 544] est vide —, mais il aurait été
 * réglé sur *cet* arbre, et un arbre qui grandit l'aurait franchi sans que rien
 * ne sonne. On coupe sur le fait lui-même : **une bande s'arrête là où une
 * personne d'une AUTRE branche s'intercale**. Les bandes d'une rangée sont donc
 * les suites maximales de personnes consécutives de même branche, dans l'ordre
 * des `x` — c'est-à-dire des intervalles disjoints par construction. Deux bandes
 * d'une même rangée ne peuvent plus se chevaucher : ce n'est plus une mesure qui
 * tient, c'est la forme qui l'interdit. {@link zones.test.ts} le vérifie.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Une seule `<path>` par région, et c'est délibéré.** Toutes les bandes d'une
 * région vivent dans le même `d`, en sous-chemins. Deux raisons : cinq éléments
 * au lieu de la centaine de bandes, et surtout **la peinture est calculée une
 * fois pour tout le chemin** — deux bandes qui se touchent bord à bord ne
 * laissent donc ni couture claire ni double couche de teinte, ce qu'un rectangle
 * par bande, chacun avec sa `fill-opacity`, produirait à chaque jointure.
 */

import type { NoeudGraphe } from '@/lib/api'

/**
 * La clé d'une région. Les cinq branches du contrat, plus `hors`.
 *
 * ⚠ **`hors` n'est pas une sixième branche, c'est l'absence de branche.** Le
 * type `Branche` de l'API n'a que cinq valeurs et déclare `branch` obligatoire,
 * mais le sérialiseur, lui, pose `'branch' => $libelle['branch'] ?? null`
 * (`NormaliseurDeGraphe.php`) : la colonne est `NULL`able exprès — « sans racine,
 * "branche paternelle du père" ne veut rien dire » (`CalculAscendance::branches`).
 * Une personne que rien ne relie à la racine sort donc avec `branch: null`, que
 * le type ne prévoit pas. Sans cette clé, ces fiches recevaient la classe
 * `b-null`, à laquelle aucune règle ne répond : liseré non peint, et aucun aplat.
 * Elles ont une région, et c'est celle des gens sans place attribuée.
 */
export type CleDeZone = 'pp' | 'pm' | 'mp' | 'mm' | 'tr' | 'hors'

export const CLES_DE_ZONE: readonly CleDeZone[] = ['pp', 'pm', 'tr', 'mp', 'mm', 'hors']

/** La clé de région d'un nœud — {@link CleDeZone} pour `branch: null`. */
export function cleDeZone(branche: string | null | undefined): CleDeZone {
  switch (branche) {
    case 'pp':
    case 'pm':
    case 'mp':
    case 'mm':
    case 'tr':
      return branche
    default:
      return 'hors'
  }
}

/**
 * Le débord d'un aplat autour des fiches qu'il porte, en unités du graphe.
 *
 * 16 de chaque côté, **rabotés** à la moitié de l'espace disponible quand la
 * bande voisine est plus près que ça : le débord ne peut donc jamais créer le
 * chevauchement que la forme interdit par ailleurs. Le plus petit écart mesuré
 * entre deux bandes voisines du graphe servi est de 59 unités, ce qui laisse
 * 27 unités de fond entre les deux aplats les plus serrés.
 */
const DEBORD_X = 16

/** Le débord au-dessus de la première rangée et sous la dernière, à défaut de voisine. */
const DEBORD_Y = 28

/** Une bande : une région, sur une rangée, sur une plage d'abscisses. */
export interface BandeDeZone {
  cle: CleDeZone
  /** L'ordonnée de la rangée — celle des nœuds, pas celle du bord de la bande. */
  y: number
  x0: number
  x1: number
  haut: number
  bas: number
}

/** Une région : sa clé et le `d` d'un `<path>` qui en peint toutes les bandes. */
export interface ZoneDeBranche {
  cle: CleDeZone
  bandes: BandeDeZone[]
  d: string
  /** Le nombre de personnes portées — sert à ne pas légender une région vide. */
  personnes: number
}

interface Rangee {
  y: number
  haut: number
  bas: number
  noeuds: NoeudGraphe[]
}

function estPersonne(n: NoeudGraphe): boolean {
  return n.type === 'personne'
}

/**
 * Les rangées du graphe, de haut en bas.
 *
 * Une rangée est un `y` distinct : le moteur pose une génération par ligne, et
 * c'est lui qui décide — on ne recalcule pas la génération depuis le nœud, on lit
 * la position qu'il a produite. Les dispositions du dossier `dispositions/`
 * déplacent les `x` et jamais les `y`, donc ce découpage leur survit.
 */
function rangees(noeuds: readonly NoeudGraphe[]): Rangee[] {
  const parY = new Map<number, NoeudGraphe[]>()
  for (const n of noeuds) {
    if (!estPersonne(n)) continue
    const liste = parY.get(n.y)
    if (liste === undefined) parY.set(n.y, [n])
    else liste.push(n)
  }

  return [...parY.entries()]
    .map(([y, ns]) => ({
      y,
      haut: Math.min(...ns.map((n) => n.y - n.h / 2)),
      bas: Math.max(...ns.map((n) => n.y + n.h / 2)),
      noeuds: ns,
    }))
    .sort((a, b) => a.y - b.y)
}

/**
 * Les bandes d'une rangée : les suites maximales de même branche, dans l'ordre
 * des abscisses. Voir l'en-tête pour la raison — c'est le cœur du module.
 */
function bandesDeLaRangee(rangee: Rangee, haut: number, bas: number): BandeDeZone[] {
  const ordonnes = [...rangee.noeuds].sort((a, b) => a.x - b.x || a.w - b.w)

  // Les suites : on ferme la courante dès que la branche change.
  const suites: { cle: CleDeZone; x0: number; x1: number }[] = []
  for (const n of ordonnes) {
    const cle = cleDeZone((n as { branch?: string | null }).branch)
    const g = n.x - n.w / 2
    const d = n.x + n.w / 2
    const courante = suites[suites.length - 1]

    if (courante !== undefined && courante.cle === cle) {
      courante.x0 = Math.min(courante.x0, g)
      courante.x1 = Math.max(courante.x1, d)
    } else {
      suites.push({ cle, x0: g, x1: d })
    }
  }

  // Le débord, raboté à la moitié de l'espace libre vers la suite voisine : deux
  // bandes d'une même rangée restent disjointes quoi qu'il arrive.
  return suites.map((s, i) => {
    const avant = suites[i - 1]
    const apres = suites[i + 1]
    const aGauche = avant === undefined ? DEBORD_X : Math.min(DEBORD_X, (s.x0 - avant.x1) / 2)
    const aDroite = apres === undefined ? DEBORD_X : Math.min(DEBORD_X, (apres.x0 - s.x1) / 2)

    return {
      cle: s.cle,
      y: rangee.y,
      // `Math.max(0, …)` : deux fiches de branches différentes qui se
      // chevauchent déjà rendraient un débord négatif, donc une bande inversée.
      x0: s.x0 - Math.max(0, aGauche),
      x1: s.x1 + Math.max(0, aDroite),
      haut,
      bas,
    }
  })
}

/**
 * Les cinq régions et les sans-branche, prêtes à peindre.
 *
 * Les bandes d'une même région sont réunies dans un seul `d`. Les bandes
 * verticalement voisines se touchent **exactement** — le bord commun est posé au
 * milieu de l'espace qui sépare deux générations —, si bien que la région se lit
 * comme un seul aplat à marches, et non comme des barreaux.
 */
export function zonesDeBranche(noeuds: readonly NoeudGraphe[]): ZoneDeBranche[] {
  const lignes = rangees(noeuds)
  if (lignes.length === 0) return []

  const bandes: BandeDeZone[] = []
  for (const [i, rangee] of lignes.entries()) {
    const precedente = lignes[i - 1]
    const suivante = lignes[i + 1]
    // Le bord se pose à mi-chemin de la rangée voisine ; faute de voisine, au
    // débord par défaut. Les deux bandes se touchent donc sans se recouvrir.
    const haut = precedente === undefined ? rangee.haut - DEBORD_Y : (precedente.bas + rangee.haut) / 2
    const bas = suivante === undefined ? rangee.bas + DEBORD_Y : (rangee.bas + suivante.haut) / 2
    bandes.push(...bandesDeLaRangee(rangee, haut, bas))
  }

  const zones: ZoneDeBranche[] = []
  for (const cle of CLES_DE_ZONE) {
    const siennes = bandes.filter((b) => b.cle === cle)
    if (siennes.length === 0) continue

    zones.push({
      cle,
      bandes: siennes,
      d: siennes.map(rectangle).join(' '),
      personnes: noeuds.filter((n) => estPersonne(n) && cleDeZone((n as { branch?: string | null }).branch) === cle)
        .length,
    })
  }

  return zones
}

/** Un sous-chemin rectangulaire fermé. Les coordonnées sont arrondies au dixième. */
function rectangle(b: BandeDeZone): string {
  const n = (v: number): string => String(Math.round(v * 10) / 10)
  return `M${n(b.x0)},${n(b.haut)} H${n(b.x1)} V${n(b.bas)} H${n(b.x0)} Z`
}
