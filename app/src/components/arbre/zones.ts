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
 *
 * ── POURQUOI ELLE SE DESSINE, ALORS QU'ELLE N'EST PAS UN BLOC ──────────────
 *
 * **Deux affirmations se collent facilement, et une seule est vraie.** La spécification du
 * chantier a d'abord été formulée ainsi : *« `hors` n'est pas un bloc — ne se dessine
 * pas »*. Le premier membre est juste : `hors` ne fait pas partie des treize. **Le second
 * ne s'en déduit pas**, et l'arbitrage rendu le 23/09/2026 l'a retiré.
 *
 * `hors` n'est pas un bloc : **c'est la marque de ce qui n'en a aucun.** La règle du
 * commanditaire dit *« seules les personnes vraiment hors de mon arbre n'ont pas de
 * bloc »* ; cet aplat est l'expression VISIBLE de cette règle, le seul endroit où l'on
 * voit *qui* est dehors. Le retirer cacherait l'effet de la règle elle-même.
 *
 * Et le moment comptait : le liseré de branche des fiches a été retiré le même jour
 * (`canevas.ts`). Supprimer l'aplat en plus aurait ôté **deux repères d'un coup à la même
 * population** — les 28 détachés seraient devenus indistinguables de fiches ordinaires.
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
function bandesDeLaRangee(
  rangee: Rangee,
  haut: number,
  bas: number,
  cleDe: (n: NoeudGraphe) => string = (n) => cleDeZone(brancheDe(n)),
): BandeDeZone[] {
  const ordonnes = [...rangee.noeuds].sort((a, b) => a.x - b.x || a.w - b.w)

  // Les suites : on ferme la courante dès que la clé change.
  const suites: { cle: string; x0: number; x1: number }[] = []
  for (const n of ordonnes) {
    const cle = cleDe(n)
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
      cle: s.cle as CleDeZone,
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
      d: piles(siennes).map(contourDeLaPile).join(' '),
      personnes: noeuds.filter((n) => estPersonne(n) && cleDeZone((n as { branch?: string | null }).branch) === cle)
        .length,
    })
  }

  return zones
}


/* ------------------------------------------------------------------ */
/* Les enveloppes — les gros nuages du croquis                         */
/* ------------------------------------------------------------------ */

/**
 * La clé d'une enveloppe : `p` et `m` pour le lecteur, `pc` et `mc` pour son conjoint.
 *
 * ⚠ **Ce ne sont PAS des valeurs de `Branche`, et elles ne doivent jamais le devenir.**
 * L'énumération du serveur porte la règle en toutes lettres : *« une valeur désigne une
 * partition, jamais une réunion »*. Une enveloppe est la réunion de deux régions voisines ;
 * la stocker obligerait le calcul à tenir cohérentes une réunion et ses deux moitiés, et
 * poserait la question sans bonne réponse — *une personne de `pp` est-elle aussi dans `p` ?*
 *
 * Elle se dérive donc ici, à la géométrie, où elle n'est qu'un dessin. C'est ce qui fait
 * **treize blocs pour neuf régions** : quatre enveloppes, et aucune valeur de plus.
 *
 * `pc` et `mc` plutôt que `p'` et `m'` de la spécification : l'apostrophe ne passe pas dans
 * un nom de classe CSS, et le préfixe `c` est déjà celui des quarts du conjoint côté
 * serveur (`cpp`, `cpm`, `cmp`, `cmm`).
 */
export type CleDEnveloppe = 'p' | 'm' | 'pc' | 'mc'

/**
 * Quelle enveloppe réunit quelles régions.
 *
 * L'ordre suit le dessin de gauche à droite : le côté paternel du lecteur, son côté
 * maternel, puis les deux côtés de son conjoint.
 */
export const ENVELOPPES: readonly { cle: CleDEnveloppe; membres: readonly CleDeZone[] }[] = [
  { cle: 'p', membres: ['pp', 'pm'] },
  { cle: 'm', membres: ['mp', 'mm'] },
  { cle: 'pc', membres: ['cpp', 'cpm'] },
  { cle: 'mc', membres: ['cmp', 'cmm'] },
]

/**
 * Ce dont l'enveloppe dépasse ses deux moitiés, vers le haut.
 *
 * **Seulement vers le haut et vers le bas, jamais sur les côtés** — et ce n'est pas une
 * demi-mesure, c'est ce qui rend la propriété de non-chevauchement gratuite. Les bandes
 * d'une enveloppe sont calculées par {@link bandesDeLaRangee}, exactement comme celles
 * d'une région, en remplaçant simplement la clé de chaque personne par celle de son
 * enveloppe. Elles héritent donc du rabot, et **deux enveloppes d'une même rangée ne
 * peuvent pas se recouvrir, par construction et non par mesure**.
 *
 * Les élargir latéralement casserait cette garantie au premier endroit serré — celui, déjà
 * mesuré, où deux suites voisines ne sont séparées que de 20 unités.
 */
const MARGE_ENVELOPPE_Y = 26

/**
 * Ce dont l'enveloppe dépasse ses deux moitiés **sur les côtés**, et ce qu'elle en obtient
 * réellement.
 *
 * ── POURQUOI CE DÉBORD N'EXISTAIT PAS D'ABORD, ET CE QUI A CHANGÉ ─────────────
 *
 * La première version n'élargissait que vers le haut, et la raison était bonne : les bandes
 * d'une enveloppe passent par {@link bandesDeLaRangee}, donc héritent du rabot, et deux
 * enveloppes d'une rangée ne peuvent pas se recouvrir **sans qu'on ait à le redémontrer**.
 * Élargir latéralement, c'est reprendre cette garantie à sa charge.
 *
 * Le prix de ce renoncement a été mesuré : l'enveloppe peignait **presque exactement les
 * mêmes pixels que ses enfants** — 179 557 contre 189 132 sur le graphe servi —, si bien
 * qu'elle se lisait comme « la même région, en plus foncé » et non comme un contenant. Ce
 * n'était ni un défaut de teinte (les nuages sont 2,3 fois plus contrastés que les régions)
 * ni une surface nulle (12,5 % en propre) : c'était une superposition.
 *
 * ── LE RABOT EST PAR CÔTÉ, ET C'EST TOUT CE QUI REND LA CHOSE POSSIBLE ─────────
 *
 * Chaque bord est borné à la **moitié de l'espace libre de SON côté**, séparément. Borné
 * globalement, une enveloppe coincée d'un côté n'obtiendrait rien des deux ; borné par côté,
 * elle garde ce que l'autre bord lui laisse. C'est ce qui sauve `mc`, bloquée à gauche sur
 * une rangée et entièrement libre à droite, puisqu'elle est la dernière du dessin.
 *
 * La moitié, et non l'espace entier : deux voisines qui s'élargissent l'une vers l'autre se
 * **touchent** alors exactement, et ne se croisent jamais. C'est la même démonstration que
 * celle du débord des régions, refaite à cet étage.
 *
 * ── 48, ET CE QU'ON EN OBTIENT VRAIMENT ─────────────────────────────────────
 *
 * Trois fois le débord d'une région ({@link DEBORD_X}, 16), pour que la marche entre
 * l'enveloppe et sa moitié se voie ; et moins du tiers de la fiche la plus étroite (152
 * unités), pour qu'elle ne puisse jamais passer pour une colonne vide.
 *
 * **Le rabot fait que 48 est un plafond, presque jamais la valeur obtenue** — et les écarts
 * mesurés disent que le plafond s'applique presque partout : sur le graphe servi, lecteur
 * `p3`, les 29 paires d'enveloppes voisines ont un écart **médian de 2 438 unités**, et deux
 * seulement tombent sous 500. **Une seule est à zéro** — rangée `y = -300`, entre `pc` et
 * `mc`, deux enveloppes qui se touchent déjà exactement. Là, et là seulement, l'élargissement
 * ne donne rien : c'est le rabot qui fonctionne, pas un défaut.
 */
const MARGE_ENVELOPPE_X = 48

/**
 * La profondeur de la surface de convergence, sous la dernière rangée de l'enveloppe.
 *
 * C'est le ▽ du croquis : *« large en haut, les aïeux, converge vers le bas »*. Les deux
 * moitiés d'une enveloppe descendent toutes deux vers un même couple — le père pour `p`, la
 * mère pour `m` —, et cette surface est l'endroit où elles se rejoignent.
 */
const CONVERGENCE_Y = 58

/** Ce qu'il reste de la largeur à la pointe du ▽, en fraction de la dernière bande. */
const CONVERGENCE_LARGEUR = 0.34

/** Une enveloppe : la réunion de deux régions, plus sa surface de convergence. */
export interface EnveloppeDeZone {
  cle: CleDEnveloppe
  membres: readonly CleDeZone[]
  bandes: BandeDeZone[]
  d: string
  /** Le nombre de personnes portées par les deux moitiés réunies. */
  personnes: number
}

/**
 * **Les gros nuages : chacun contient deux nuages, plus sa propre surface dessous.**
 *
 * Le croquis du commanditaire dit *« chaque bloc = un nuage, et dans les gros blocs on
 * retrouve 2 blocs nuage »*. C'est cette fonction qui dessine les gros ; {@link zonesDeBranche}
 * dessine ceux qu'ils contiennent.
 *
 * ── CE QUI REND L'IMBRICATION SÛRE ──────────────────────────────────────────
 *
 * Les bandes d'une enveloppe sont calculées par la **même** fonction que celles d'une
 * région, sur les **mêmes** rangées, en substituant à la clé de chaque personne celle de son
 * enveloppe. Trois conséquences, et ce sont elles qui font tenir le dessin :
 *
 * - une enveloppe **contient** ses deux moitiés horizontalement, puisqu'elle est calculée
 *   sur la réunion de leurs personnes avec le même débord ;
 * - **deux enveloppes d'une même rangée ne se recouvrent jamais**, par le même argument que
 *   pour deux régions — le rabot, pas un seuil ;
 * - une enveloppe **coupée par un intrus** l'est au même endroit que ses moitiés.
 *
 * ── CE QU'ELLE NE FAIT PAS ──────────────────────────────────────────────────
 *
 * Elle ne dessine **ni le tronc ni les sans-branche**. Le tronc n'est pas la réunion de deux
 * régions — c'est une région, et {@link zonesDeBranche} le sert déjà ; le croquis lui donne
 * d'ailleurs la forme inverse, un △ qui s'élargit vers la descendance. Et `hors` n'est pas un
 * bloc : *« seules les personnes vraiment hors de mon arbre n'ont pas de bloc »*, et ce
 * qu'aucun lien ne rattache ne se dessine pas.
 *
 * Une enveloppe dont aucune moitié ne porte personne n'est pas rendue — c'est le masquage
 * des blocs vides demandé, appliqué ici par simple absence.
 */
/**
 * À quelle enveloppe une personne appartient — et **sa propre région quand elle n'en a
 * aucune**.
 *
 * ⚠ **Ce second cas est ce qui fait tenir tout le reste, et sa première rédaction
 * l'ignorait.** Elle FILTRAIT les nœuds pour ne garder que les membres de l'enveloppe, puis
 * calculait les bandes sur ce qui restait. Deux tests l'ont prise en défaut le même jour :
 *
 * - **l'enveloppe ne coupait plus sur l'intrus** — `pp` et `pm` gardaient leurs clés, donc
 *   la bande se fermait entre eux, et l'enveloppe ne couvrait plus ses propres moitiés ;
 * - **deux enveloppes se recouvraient** — le rabot ne voit que les suites présentes dans la
 *   rangée, et une enveloppe dont on a retiré la voisine s'étale jusqu'à elle.
 *
 * On ne filtre donc rien : on **réétiquette**. La rangée entière passe dans la même
 * machinerie que les régions, chaque personne portant la clé de son enveloppe au lieu de
 * celle de son quart. Le tronc et les sans-branche gardent la leur, ce qui les laisse
 * couper et raboter comme n'importe quelle autre suite. Les deux propriétés se déduisent
 * alors de celles des régions, au lieu d'être à redémontrer.
 */
function enveloppeDe(n: NoeudGraphe): string {
  const cle = cleDeZone(brancheDe(n))
  return ENVELOPPES.find((e) => e.membres.includes(cle))?.cle ?? cle
}

/**
 * **Les gros nuages : chacun contient deux nuages, plus sa propre surface dessous.**
 *
 * Le croquis du commanditaire dit *« chaque bloc = un nuage, et dans les gros blocs on
 * retrouve 2 blocs nuage »*. C'est cette fonction qui dessine les gros ;
 * {@link zonesDeBranche} dessine ceux qu'ils contiennent.
 *
 * ── CE QUI REND L'IMBRICATION SÛRE ──────────────────────────────────────────
 *
 * C'est **exactement** {@link zonesDeBranche}, sur les mêmes rangées, avec
 * {@link enveloppeDe} à la place de {@link cleDeZone}. Trois conséquences, et elles sont
 * héritées plutôt que reprouvées :
 *
 * - une enveloppe **contient** ses deux moitiés, puisqu'elle est la suite maximale qui les
 *   réunit, avec le même débord ;
 * - **deux enveloppes d'une même rangée ne se recouvrent jamais** — le rabot, pas un seuil ;
 * - une enveloppe **coupée par un intrus** l'est au même endroit que ses moitiés.
 *
 * ── CE QU'ELLE NE DESSINE PAS ───────────────────────────────────────────────
 *
 * **Ni le tronc, ni les sans-branche.** Le tronc n'est pas la réunion de deux régions —
 * c'en est une, que {@link zonesDeBranche} sert déjà, et le croquis lui donne la forme
 * inverse : un △ qui s'élargit vers la descendance. Et `hors` n'est pas un bloc : *« seules
 * les personnes vraiment hors de mon arbre n'ont pas de bloc »*, et ce qu'aucun lien ne
 * rattache ne se dessine pas.
 *
 * Une enveloppe dont aucune moitié ne porte personne n'est pas rendue — le masquage des
 * blocs vides, obtenu par simple absence.
 */
export function enveloppesDeBranche(noeuds: readonly NoeudGraphe[]): EnveloppeDeZone[] {
  const lignes = rangees(noeuds)
  if (lignes.length === 0) return []

  const cles = new Set<string>(ENVELOPPES.map((e) => e.cle))
  const bandes: BandeDeZone[] = []

  for (const [i, rangee] of lignes.entries()) {
    const precedente = lignes[i - 1]
    const suivante = lignes[i + 1]
    const haut = precedente === undefined ? rangee.haut - DEBORD_Y : (precedente.bas + rangee.haut) / 2
    const bas = suivante === undefined ? rangee.bas + DEBORD_Y : (rangee.bas + suivante.haut) / 2

    // **L'élargissement se calcule sur la rangée ENTIÈRE**, tronc et sans-branche compris :
    // l'espace libre d'un bord se mesure vers ce qui s'y trouve réellement, pas seulement
    // vers une autre enveloppe. Filtrer d'abord reviendrait à s'élargir dans le tronc.
    const rang = bandesDeLaRangee(rangee, haut, bas, enveloppeDe).sort((a, b) => a.x0 - b.x0)

    for (const [j, b] of rang.entries()) {
      if (!cles.has(b.cle as string)) continue

      const avant = rang[j - 1]
      const apres = rang[j + 1]
      const aGauche = avant === undefined ? MARGE_ENVELOPPE_X : Math.min(MARGE_ENVELOPPE_X, (b.x0 - avant.x1) / 2)
      const aDroite = apres === undefined ? MARGE_ENVELOPPE_X : Math.min(MARGE_ENVELOPPE_X, (apres.x0 - b.x1) / 2)

      // `Math.max(0, …)` : deux bandes qui se toucheraient déjà rendraient un rabot négatif,
      // donc une enveloppe qui rétrécirait sous ses propres enfants.
      b.x0 -= Math.max(0, aGauche)
      b.x1 += Math.max(0, aDroite)
    }

    bandes.push(...rang)
  }

  const enveloppes: EnveloppeDeZone[] = []

  for (const { cle, membres } of ENVELOPPES) {
    const siennes = bandes.filter((b) => (b.cle as string) === cle)
    if (siennes.length === 0) continue

    // Le débord propre à l'enveloppe, vers le haut seulement : elle se voit au-dessus de ses
    // moitiés sans empiéter latéralement sur sa voisine, dont le rabot la sépare.
    const premiere = Math.min(...siennes.map((b) => b.haut))
    for (const b of siennes) if (b.haut === premiere) b.haut -= MARGE_ENVELOPPE_Y

    const dedans = new Set<CleDeZone>(membres)

    enveloppes.push({
      cle,
      membres,
      bandes: siennes,
      d: piles(siennes)
        .map((pile) => contourDeLaPile(avecConvergence(pile)))
        .join(' '),
      personnes: noeuds.filter((n) => estPersonne(n) && dedans.has(cleDeZone(brancheDe(n)))).length,
    })
  }

  return enveloppes
}

/**
 * La surface propre de l'enveloppe, ajoutée sous sa dernière bande : le ▽ du croquis.
 *
 * Une bande de plus, moins large et centrée sur celle qu'elle prolonge. Le lissage de
 * {@link contourDeLaPile} s'en charge ensuite comme d'un décrochement ordinaire — d'où une
 * pointe adoucie, et non un chevron.
 *
 * **Elle est centrée sur la bande, et non sur le couple vers lequel les deux moitiés
 * convergent.** Ce module ne connaît que des positions et des clés : il ne sait pas lequel
 * des nœuds de la rangée du dessous est le père. Le centre de la bande en est une
 * approximation, exacte quand les deux moitiés sont équilibrées et décalée quand elles ne le
 * sont pas. **Le jour où ce décalage se verra, c'est le nœud-famille de la rangée du dessous
 * qu'il faudra viser, pas un réglage de cette constante.**
 */
function avecConvergence(pile: readonly BandeDeZone[]): BandeDeZone[] {
  const bandes = [...pile].sort((a, b) => a.haut - b.haut)
  const derniere = bandes[bandes.length - 1]
  if (derniere === undefined) return bandes

  const centre = (derniere.x0 + derniere.x1) / 2
  const demiLargeur = ((derniere.x1 - derniere.x0) * CONVERGENCE_LARGEUR) / 2

  return [
    ...bandes,
    {
      cle: derniere.cle,
      y: derniere.y,
      x0: centre - demiLargeur,
      x1: centre + demiLargeur,
      haut: derniere.bas,
      bas: derniere.bas + CONVERGENCE_Y,
    },
  ]
}

/** La branche d'un nœud, que le type du contrat ne déclare pas nullable. */
function brancheDe(n: NoeudGraphe): string | null | undefined {
  return (n as { branch?: string | null }).branch
}

/** Une coordonnée de chemin, arrondie au dixième. */
function n(v: number): string {
  return String(Math.round(v * 10) / 10)
}

/**
 * Le rayon des congés, en unités du graphe.
 *
 * **16, soit exactement le débord horizontal.** Ce n'est pas une coïncidence choisie pour
 * faire joli : le congé mange au plus `RAYON` de chaque côté d'un coin, et le débord est la
 * seule marge dont la bande dispose au-delà des fiches qu'elle porte. Un rayon plus grand
 * entamerait la fiche elle-même sur les rangées serrées — celles où le rabot a déjà réduit
 * le débord à 10 unités.
 *
 * Il est **raboté** à chaque coin par la moitié du plus court des deux segments qui s'y
 * rejoignent : une bande de 30 unités de haut ne reçoit pas un congé de 16, sans quoi les
 * deux congés d'un même bord se croiseraient et le chemin se retournerait.
 */
const RAYON = 16

/**
 * **Le contour lissé d'une PILE de bandes**, du haut vers le bas.
 *
 * ── POURQUOI UNE PILE, ET PAS UNE BANDE ─────────────────────────────────────
 *
 * Arrondir chaque bande séparément produirait des **encoches** entre deux rangées : les
 * bandes verticalement voisines se touchent exactement — c'est la propriété que tout ce
 * module garantit — et deux coins arrondis dos à dos y creusent un losange de fond visible.
 * Le contour se calcule donc sur la pile entière : on descend le bord droit, on remonte le
 * bord gauche, et **les seuls coins arrondis sont ceux qui existent vraiment**, c'est-à-dire
 * les décrochements entre deux rangées de largeurs différentes.
 *
 * ── CE QUE CE LISSAGE N'EST PAS ─────────────────────────────────────────────
 *
 * Ce n'est **pas un flou**, et il ne pouvait pas l'être : l'ADR 0007 interdit `filter`,
 * `mask`, `mix-blend-mode` et `will-change` au même titre qu'`opacity`, parce qu'ils créent
 * un contexte d'empilement par élément sur un canevas de 750. Treize chemins de plus ne
 * coûtent rien ; treize filtres coûteraient treize couches de composition **par image**.
 * L'estompe des bords vient d'un dégradé — un *paint server* s'applique pendant la peinture
 * et n'alloue aucune surface.
 *
 * Ce n'est pas non plus une **boîte englobante**. La pile épouse le relief rangée par
 * rangée ; une région large en haut et étroite en bas garde sa forme en entonnoir, qui est
 * le ▽ du croquis. C'était tout l'objet de la demande.
 */
export function contourDeLaPile(pile: readonly BandeDeZone[]): string {
  if (pile.length === 0) return ''

  const bandes = [...pile].sort((a, b) => a.haut - b.haut)
  const derniere = bandes.length - 1
  const chemin: string[] = []

  // Le congé ne dépasse jamais la moitié de la hauteur de sa bande, ni la moitié du
  // décrochement qu'il adoucit : deux congés d'un même bord ne peuvent pas se croiser.
  const rayon = (b: BandeDeZone, decrochement: number): number =>
    Math.max(0, Math.min(RAYON, (b.bas - b.haut) / 2, Math.abs(decrochement) / 2))

  const premiere = bandes[0]!
  chemin.push(`M${n(premiere.x0)},${n(premiere.haut)}`, `L${n(premiere.x1)},${n(premiere.haut)}`)

  // ── LE BORD DROIT, DU HAUT VERS LE BAS ────────────────────────────────────
  for (const [i, b] of bandes.entries()) {
    const suivante = bandes[i + 1]

    if (suivante === undefined) {
      chemin.push(`L${n(b.x1)},${n(b.bas)}`, `L${n(b.x0)},${n(b.bas)}`)
      break
    }

    const pas = suivante.x1 - b.x1
    const r = rayon(b, pas)
    const sens = Math.sign(pas)
    chemin.push(
      `L${n(b.x1)},${n(b.bas - r)}`,
      `Q${n(b.x1)},${n(b.bas)} ${n(b.x1 + sens * r)},${n(b.bas)}`,
      `L${n(suivante.x1 - sens * r)},${n(b.bas)}`,
      `Q${n(suivante.x1)},${n(b.bas)} ${n(suivante.x1)},${n(b.bas + r)}`,
    )
  }

  // ── LE BORD GAUCHE, DU BAS VERS LE HAUT ───────────────────────────────────
  //
  // ⚠ **Il se trace dans SON ordre de parcours, et pas en retournant ce qu'on a empilé
  // en descendant.** La première rédaction accumulait les commandes du bord gauche
  // pendant la descente, puis appelait `.reverse()`. Sur un tableau PLAT de chaînes,
  // `reverse()` inverse aussi les commandes À L'INTÉRIEUR de chaque groupe : un `Q` se
  // retrouvait avant le `L` qui l'amenait, et le chemin partait en pointes — des éclats
  // de plusieurs milliers d'unités, parfaitement visibles au rendu et invisibles dans les
  // tests de bandes, qui ne lisent pas le `d`.
  for (let i = derniere; i >= 0; i -= 1) {
    const b = bandes[i]!
    const precedente = bandes[i - 1]

    if (precedente === undefined) {
      chemin.push(`L${n(b.x0)},${n(b.haut)}`)
      break
    }

    // Les bandes se touchent exactement : le haut de celle-ci EST le bas de la précédente.
    const pas = precedente.x0 - b.x0
    const r = rayon(b, pas)
    const sens = Math.sign(pas)
    chemin.push(
      `L${n(b.x0)},${n(b.haut + r)}`,
      `Q${n(b.x0)},${n(b.haut)} ${n(b.x0 + sens * r)},${n(b.haut)}`,
      `L${n(precedente.x0 - sens * r)},${n(b.haut)}`,
      `Q${n(precedente.x0)},${n(b.haut)} ${n(precedente.x0)},${n(b.haut - r)}`,
    )
  }

  chemin.push('Z')

  return chemin.join(' ')
}

/**
 * Les bandes d'une région, groupées en **piles verticalement contiguës**.
 *
 * Deux bandes de rangées voisines appartiennent à la même pile si elles se chevauchent en
 * abscisse. Sans ce découpage, une région coupée en deux morceaux par un intrus — le cas
 * mesuré à `y = -300`, où quatre branches sont entrelacées — verrait ses deux morceaux
 * reliés par un trait qui traverse la région voisine.
 */
export function piles(bandes: readonly BandeDeZone[]): BandeDeZone[][] {
  const parRangee = new Map<number, BandeDeZone[]>()
  for (const b of bandes) parRangee.set(b.y, [...(parRangee.get(b.y) ?? []), b])

  const rangees = [...parRangee.entries()].sort((a, b) => a[0] - b[0]).map(([, bs]) => bs)
  let ouvertes: BandeDeZone[][] = []
  const finies: BandeDeZone[][] = []

  for (const rangee of rangees) {
    const suivantes: BandeDeZone[][] = []
    const reprises = new Set<BandeDeZone[]>()

    for (const bande of rangee) {
      const pile = ouvertes.find((p) => !reprises.has(p) && chevauchent(p[p.length - 1]!, bande))
      if (pile === undefined) {
        suivantes.push([bande])
      } else {
        pile.push(bande)
        reprises.add(pile)
        suivantes.push(pile)
      }
    }

    for (const p of ouvertes) if (!reprises.has(p)) finies.push(p)
    ouvertes = suivantes
  }

  return [...finies, ...ouvertes]
}

function chevauchent(a: BandeDeZone, b: BandeDeZone): boolean {
  return a.x0 < b.x1 && b.x0 < a.x1
}
