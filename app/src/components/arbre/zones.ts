/**
 * Les **zones de branche** : un aplat coloré sous l'arbre, par région logique.
 *
 * L'arbre se lit en neuf régions — les quatre quarts du lecteur de part et d'autre
 * du `tr` (le tronc), puis les quatre de son conjoint —, et rien à l'écran ne les
 * montrait : la branche ne
 * se voyait que sur le liseré de 4 unités d'une fiche, invisible dès qu'on
 * s'éloigne. Ce module calcule la géométrie de ces aplats. Il ne touche pas au
 * DOM : {@link construireCanevas} s'en charge, et ces fonctions se testent donc
 * sans navigateur ni faux document.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ces nombres sont datés, et la mise en page bouge.** Ils ont été relevés le
 * 20/09/2026 ; le même jour, la boîte est passée de 26 977 à 24 693 unités de large
 * sans qu'aucune branche change de contenu. Ce qui doit tenir n'est donc pas le
 * chiffre mais **la forme de l'argument** : deux emprises globales se chevauchent,
 * une rangée sur quinze est entrelacée, et aucun seuil ne sépare les deux familles
 * d'écarts. Si un relevé futur les contredit, c'est la forme qu'il faut réexaminer.
 *
 * TOUT CE QUI SUIT EST MESURÉ SUR LE GRAPHE SERVI, empreinte `91ad68db` —
 * 648 nœuds, 468 personnes, 15 rangées, boîte 24 693 × 2 301.
 *
 * **POURQUOI PAS UN RECTANGLE PAR BRANCHE.** C'était la forme évidente, et elle
 * est fausse : les emprises globales se chevauchent. `pp` (-13 715 .. -3 148)
 * recouvre `pm` (-7 432 .. -125) sur **4 284 unités**, et `mp` (583 .. 5 468)
 * recouvre `mm` (3 629 .. 8 189) sur **1 839**. Un rectangle par branche
 * donnerait deux paires de bandes superposées. On peint donc **rangée par
 * rangée**, une bande par génération, empilées.
 *
 * **ET POURQUOI PAS UNE BANDE PAR (BRANCHE, RANGÉE) NON PLUS.** Une seule rangée
 * sur quinze fait échouer cette forme-là, et c'est `y = -300` : les branches y
 * sont **entrelacées**, pas seulement voisines. Dans l'ordre des abscisses, la
 * rangée se lit
 *
 *     pp×10 | pm×1 | pp×1 | pm×17 | mp×2 | mm×1 | mp×7 | mm×12
 *
 * — quatre branches coupées en deux morceaux chacune. Une bande unique par
 * (branche, rangée) enjamberait donc les intrus : `pp` recouvrirait `pm` sur
 * 430 unités et `mp` recouvrirait `mm` sur 1 683. Sur la disposition `croisements`,
 * qui réordonne les rangées, la même forme naïve produit **neuf** paires
 * superposées au lieu de deux.
 *
 * **Et la coupure ne peut PAS se faire sur une distance.** Ce n'était pas
 * évident, c'est mesuré, et le résultat est catégorique. Sur cette rangée, le
 * plus petit écart qu'il FAUT couper — entre `pp×10` et le `pm` qui s'y glisse —
 * vaut **20 unités**. Ailleurs dans l'arbre, le plus grand écart interne qu'il ne
 * faut SURTOUT pas couper — un trou à l'intérieur de `pp`, sur la rangée du bas —
 * vaut **3 750 unités**. Un seuil `t` devrait donc vérifier `t < 20` et
 * `t > 3 750` à la fois : **aucun seuil n'existe.** Réglé sur 20, il couperait
 * à tort 416 des 417 écarts internes et réduirait les régions en confettis.
 *
 * On coupe donc sur le fait lui-même : **une bande s'arrête là où une personne
 * d'une AUTRE branche s'intercale**. Les bandes d'une rangée sont les suites
 * maximales de personnes consécutives de même branche, dans l'ordre des `x` —
 * c'est-à-dire des intervalles disjoints par construction. Deux bandes d'une même
 * rangée ne peuvent plus se chevaucher : ce n'est plus une mesure qui tient,
 * c'est la forme qui l'interdit. Vérifié sur les six dispositions, **zéro
 * chevauchement partout** — 1 à 9 pour la forme naïve, selon la disposition.
 * {@link zones.test.ts} tient la propriété.
 *
 * **La seule condition, et elle n'est pas de notre ressort** : que deux fiches de
 * branches différentes ne se chevauchent pas *elles-mêmes*. Si le moteur en
 * posait deux l'une sur l'autre, aucune découpe en intervalles ne pourrait les
 * séparer — les aplats se recouvriraient parce que les fiches se recouvrent. Le
 * graphe servi la vérifie largement (plus petit écart entre deux suites voisines :
 * 20 unités). Dans ce cas dégénéré, la bande reste d'aire positive au lieu de se
 * retourner, et c'est tout ce qu'on peut promettre.
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
 * La clé d'une région. Les neuf branches du contrat, plus `hors`.
 *
 * ⚠ **`hors` n'est pas une dixième branche, c'est l'absence de branche — et depuis
 * l'arbitrage du 23/09/2026 elle ne désigne plus qu'UNE chose : ce qu'aucun lien ne
 * rattache à l'arbre du lecteur.** Ni les pièces rapportées, ni les belles-familles,
 * qui prennent désormais le bloc de leur partenaire. Sur le graphe servi, l'îlot
 * détaché pèse **28 personnes sur 468**, et c'est tout ce que `hors` doit porter.
 *
 * Le type `Branche` de l'API déclare `branch` obligatoire,
 * mais le sérialiseur, lui, pose `'branch' => $libelle['branch'] ?? null`
 * (`NormaliseurDeGraphe.php`) : la colonne est `NULL`able exprès — « sans racine,
 * "branche paternelle du père" ne veut rien dire » (`CalculAscendance::branches`).
 * Une personne que rien ne relie à la racine sort donc avec `branch: null`, que
 * le type ne prévoit pas. Sans cette clé, ces fiches recevaient la classe
 * `b-null`, à laquelle aucune règle ne répond : liseré non peint, et aucun aplat.
 * Elles ont une région, et c'est celle des gens sans place attribuée.
 */
export type CleDeZone =
  | 'pp'
  | 'pm'
  | 'mp'
  | 'mm'
  | 'tr'
  | 'cpp'
  | 'cpm'
  | 'cmp'
  | 'cmm'
  | 'hors'

/**
 * L'ordre de PEINTURE des régions, et il suit maintenant le dessin de gauche à droite :
 * les quatre quarts du lecteur, le tronc, les quatre quarts de son conjoint, puis ce qui
 * n'entre dans aucun des deux.
 *
 * `tr` reste au milieu et non en cinquième position, parce que c'est là que le moteur le
 * pose — `[ pp | pm ][ TRONC ][ mp | mm ]` sans conjoint. Avec un conjoint, le moteur
 * range ses quarts aux rangs 5 à 8, après le tronc : c'est l'ordre repris ici.
 */
export const CLES_DE_ZONE: readonly CleDeZone[] = [
  'pp',
  'pm',
  'tr',
  'mp',
  'mm',
  'cpp',
  'cpm',
  'cmp',
  'cmm',
  'hors',
]

/** La clé de région d'un nœud — {@link CleDeZone} pour `branch: null`. */
export function cleDeZone(branche: string | null | undefined): CleDeZone {
  switch (branche) {
    case 'pp':
    case 'pm':
    case 'mp':
    case 'mm':
    case 'tr':
    case 'cpp':
    case 'cpm':
    case 'cmp':
    case 'cmm':
      return branche
    default:
      return 'hors'
  }
}

/**
 * Le débord d'un aplat autour des fiches qu'il porte, en unités du graphe.
 *
 * 16 de chaque côté, **rabotés** à la moitié de l'espace disponible quand la
 * bande voisine est plus près que ça. Le rabot n'est pas une précaution
 * théorique : sur le graphe servi, deux suites voisines sont séparées de
 * **20 unités seulement** (`y = -300`, `pp` puis `pm`). Sans lui, les deux
 * aplats se recouvriraient de 12 unités et la propriété que toute cette forme
 * existe pour garantir tomberait à cet endroit précis. Avec lui, les deux bandes
 * se touchent exactement, sans jamais se croiser.
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
 * Les neuf régions et les sans-branche, prêtes à peindre.
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
