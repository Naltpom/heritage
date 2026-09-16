/**
 * La géométrie de la chronologie : de l'année à l'unité de contenu.
 *
 * ────────────────────────────────────────────────────────────────────
 * **LIGNES ↔ COLONNES EST UNE BASCULE D'ORIENTATION, PAS DEUX RENDUS.**
 *
 * Toute la géométrie est écrite **une fois**, dans un repère (`long`, `travers`)
 * où `long` court le long de l'axe du temps et `travers` d'un côté à l'autre des
 * pistes. `projeter()` est le seul endroit qui connaît l'orientation :
 *
 *     lignes   → une ligne par tranche de N années → le temps descend (y)
 *     colonnes → une colonne par tranche           → le temps va vers la droite (x)
 *
 * Deux jeux de fonctions jumelles, un par orientation, se désynchroniseraient au
 * premier correctif — et l'écart ne se verrait que dans celle des deux qu'on ne
 * regarde pas ce jour-là.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Le front ne recalcule pas les bandes.** `bandes[]` arrive calculé par le
 * serveur à partir de `bande` et de `bornes` (§ 9.1). L'axe se **lit** dessus —
 * `axeDepuisBandes()` ne fait qu'en prendre les deux extrémités — et aucune
 * fonction de ce fichier ne divise par `bande` ni n'arrondit une borne.
 *
 * Tout est **pur** : entrées scalaires, sortie scalaire, aucun accès au DOM.
 * C'est ce qui permet de prouver sans navigateur que la barre de Marne Dolomite
 * Sureau (`BET 1901 AND 1909`) montre bien ses huit années d'incertitude — le
 * genre d'exigence qui, non vérifiée, se perd en « finition » et ne revient
 * jamais.
 *
 * Le pan/zoom, lui, n'est **pas** réécrit ici : `zoomer()`, `deplacer()` et
 * `viewBoxEnTexte()` de `@/components/arbre/geometrie` sont déjà les fonctions
 * du `viewBox`, et elles ne savent rien d'un arbre. Les recopier serait ouvrir
 * la porte à deux sensibilités de molette différentes dans la même application.
 */

import type {
  BandeChronologie,
  BarreChronologie,
  IntervalleAnnees,
  OrientationChronologie,
} from '@/lib/api'
import type { Boite } from '@/components/arbre/geometrie'

/* ------------------------------------------------------------------ */
/* Constantes de dessin                                                */
/* ------------------------------------------------------------------ */

/** Unités de contenu pour **une** année. 380 ans d'arbre ⇒ ~3 000 unités, du
 *  même ordre que les 15 824 de l'arbre : le pan/zoom s'y sent pareil. */
export const UNITE_ANNEE = 8

/** Épaisseur d'une barre, en travers. */
export const EPAISSEUR_BARRE = 13
/** Blanc entre deux pistes voisines. */
export const ECART_PISTE = 5
/** D'une piste à la suivante. */
export const PAS_PISTE = EPAISSEUR_BARRE + ECART_PISTE
/** Blanc entre deux groupes de branche. */
export const GOUTTIERE_BRANCHE = 30
/** Blanc, sur l'axe du temps, entre deux barres d'une même piste : sans lui,
 *  deux vies qui se suivent se touchent et paraissent n'en faire qu'une. */
export const ECART_LONGUEUR = 12

/** Marge réservée aux libellés de bande (à gauche en lignes, en haut en
 *  colonnes) : côté `travers` négatif. */
export const GOUTTIERE_BANDES = 122
/** Marge réservée aux noms de branche : côté `long` négatif. Assez pour
 *  « Bourrache » à 10,5 unités de corps, pas plus : cette gouttière est prise
 *  sur le temps visible, et chaque unité de trop est une année de moins à
 *  l'écran. */
export const GOUTTIERE_ENTETE = 66
/** Marge autour du contenu, comme la marge 60 du moteur de l'arbre. */
export const MARGE = 40

/** Longueur de la pointe qui termine une barre ouverte. */
export const LONGUEUR_POINTE = 11

/** En deçà, deux libellés de bande se chevaucheraient : on n'en écrit qu'un
 *  sur *n*. Deux seuils, parce qu'un texte est large et bas. */
export const MIN_ETIQUETTE_LIGNES = 18
export const MIN_ETIQUETTE_COLONNES = 66

/** Largeur moyenne d'un signe à 9 px — sert à savoir si un nom tient dans sa
 *  barre, jamais à mesurer une boîte. Même usage que `LARGEUR_PAR_SIGNE` dans
 *  `arbre/graphe.ts`. */
export const LARGEUR_PAR_SIGNE = 5.2
/** Retrait du nom par rapport au bout de sa barre. */
export const MARGE_ETIQUETTE = 9
/** Décalage de la ligne de base pour centrer un texte de 9 px sur une barre. */
export const BASE_TEXTE = 3.2

/* ------------------------------------------------------------------ */
/* L'axe du temps                                                      */
/* ------------------------------------------------------------------ */

/**
 * L'axe, **déduit des bandes du serveur** et de rien d'autre.
 *
 * `finExclusive` est la première année **hors** de la dernière bande : une
 * bande `1682–1706` couvre 25 années, la dernière d'entre elles étant 1706, et
 * l'axe s'arrête donc au début de 1707. Compter en années exclusives évite
 * l'erreur d'un an qui ferait déborder chaque barre d'une bande sur l'autre.
 */
export interface AxeTemps {
  origine: number
  finExclusive: number
}

export function axeDepuisBandes(bandes: readonly BandeChronologie[]): AxeTemps | null {
  if (bandes.length === 0) return null
  return {
    origine: bandes[0].debut,
    finExclusive: bandes[bandes.length - 1].fin + 1,
  }
}

/**
 * Le **début** de l'année donnée, en unités de contenu.
 *
 * Une année occupe une case pleine : la fin de 1987 est `positionAnnee(1988)`.
 * C'est ce qui donne une largeur non nulle à une incertitude d'un seul niveau
 * d'année (`1987` sans jour connu) et huit cases à `BET 1901 AND 1909`.
 */
export function positionAnnee(axe: AxeTemps, annee: number): number {
  return (annee - axe.origine) * UNITE_ANNEE
}

/** La longueur de l'axe des bandes, en unités. */
export function longueurDeLAxe(axe: AxeTemps): number {
  return positionAnnee(axe, axe.finExclusive)
}

/* ------------------------------------------------------------------ */
/* Projection                                                          */
/* ------------------------------------------------------------------ */

export interface PointContenu {
  x: number
  y: number
}

export interface Rectangle {
  x: number
  y: number
  w: number
  h: number
}

/**
 * **Le seul endroit du module qui connaît l'orientation.** Tout le reste
 * raisonne en `long` (l'axe du temps) et `travers` (les pistes).
 */
export function projeter(
  orientation: OrientationChronologie,
  long: number,
  travers: number,
): PointContenu {
  return orientation === 'lignes' ? { x: travers, y: long } : { x: long, y: travers }
}

/** Un rectangle du repère (`long`, `travers`), projeté et normalisé : `w` et
 *  `h` sont toujours positifs, quel que soit l'ordre des bornes reçues. */
export function rectangle(
  orientation: OrientationChronologie,
  long0: number,
  long1: number,
  travers0: number,
  travers1: number,
): Rectangle {
  const a = projeter(orientation, Math.min(long0, long1), Math.min(travers0, travers1))
  const b = projeter(orientation, Math.max(long0, long1), Math.max(travers0, travers1))
  return { x: a.x, y: a.y, w: b.x - a.x, h: b.y - a.y }
}

/** Une suite de points (`long`, `travers`) sous la forme attendue par
 *  l'attribut `points` d'un `<polygon>`. */
export function polygone(
  orientation: OrientationChronologie,
  points: readonly (readonly [number, number])[],
): string {
  return points
    .map(([long, travers]) => {
      const p = projeter(orientation, long, travers)
      return `${arrondi(p.x)},${arrondi(p.y)}`
    })
    .join(' ')
}

/** Une décimale, comme le SVG d'origine (`chemins.ts`) : à 3 000 unités de
 *  large, elle suffit et les chaînes restent comparables. */
function arrondi(valeur: number): number {
  return Math.round(valeur * 10) / 10
}

/* ------------------------------------------------------------------ */
/* Les barres                                                          */
/* ------------------------------------------------------------------ */

/**
 * L'année à laquelle le **rendu** arrête une barre, ou `null` quand on l'ignore.
 *
 * Trois cas, et le troisième n'est écrit nulle part dans le § 9.1 :
 *
 * - **vivante** ⇒ l'année courante. « La barre s'arrête à l'année courante côté
 *   rendu ; l'API n'invente pas une date de fin » (§ 9.1) ;
 * - **décédée, décès daté** ⇒ `fin.max` ;
 * - **décédée, décès non daté** ⇒ `null`. Cinquante-trois barres de la charge
 *   réelle sont dans ce cas. On ne les prolonge **pas** jusqu'à l'année
 *   courante : ce serait affirmer que la personne est vivante, ce que la donnée
 *   dit précisément ne pas savoir.
 */
export function anneeDeFin(barre: BarreChronologie, anneeCourante: number): number | null {
  if (barre.ouverte) return anneeCourante
  return barre.fin === null ? null : barre.fin.max
}

/**
 * L'étendue **dessinable** d'un intervalle, et les côtés par lesquels il fuit.
 *
 * Un `BEF 1899` vaut `{ min: null, max: 1898 }` : l'intervalle n'a pas de borne
 * basse. On ne le dessine surtout pas depuis l'origine de l'axe — ce serait
 * affirmer « née en 1655 », ce que la donnée ne dit pas. On dessine ce qui est
 * connu, et on marque le côté ouvert par une pointe, exactement comme une vie
 * en cours l'est du côté du futur.
 *
 * Rend `null` quand les deux bornes manquent : il n'y a alors rien à placer.
 */
export interface EtendueDIntervalle {
  min: number
  max: number
  ouvertAvant: boolean
  ouvertApres: boolean
}

export function etendueDIntervalle(intervalle: IntervalleAnnees): EtendueDIntervalle | null {
  const { min, max } = intervalle
  if (min === null && max === null) return null
  return {
    min: min ?? (max as number),
    max: max ?? (min as number),
    ouvertAvant: min === null,
    ouvertApres: max === null,
  }
}

export interface TraceDeBarre {
  corps: Rectangle
  /**
   * Hachure de l'extrémité de naissance, sur **toute** la largeur
   * `debut.min → debut.max`. `null` quand la date est certaine — ou quand le
   * lecteur a explicitement éteint l'incertitude.
   */
  hachureDebut: Rectangle | null
  /** Idem pour le décès. Toujours `null` sur une barre ouverte : une personne
   *  vivante n'a pas de décès incertain, elle n'a pas de décès. */
  hachureFin: Rectangle | null
  /** Le `points` de la pointe **vers l'avenir** — vie en cours, ou borne haute
   *  non bornée (`AFT`). `null` sinon. */
  pointe: string | null
  /** La pointe **vers le passé** : borne basse non bornée (`BEF`). La barre
   *  commence à ce qu'on sait et s'échappe en arrière — elle ne prétend pas à
   *  une date d'origine. */
  pointeAvant: string | null
  /** La naissance n'est pas connue : la barre ne couvre que le décès. */
  sansDebut: boolean
  /** Le décès n'est pas daté : la barre s'arrête à ce qu'on sait, et son bord
   *  de fin ne prétend rien. */
  sansFin: boolean
  /** Bornes sur l'axe du temps, en unités — utiles au calcul des pistes. */
  long0: number
  long1: number
}

/**
 * Le tracé complet d'une barre.
 *
 * `montrerIncertitude` **n'a pas de valeur par défaut**, et c'est voulu :
 * l'appelant est obligé de transmettre le choix venu du serveur
 * (`showUncertainty`, `true` par défaut), au lieu de l'éteindre par omission —
 * « masquer l'incertitude est précisément ce que le § 4 du dossier interdit »
 * (§ 9.2).
 */
/**
 * Les années extrêmes des **faits datés** d'une personne (recensement,
 * résidence, baptême, inhumation). `null` quand elle n'en a pas.
 *
 * Un fait daté est une **preuve d'existence à cette date** : c'est à ce titre
 * qu'il entre dans l'étendue dessinée d'une barre, et pas comme décoration.
 */
export interface BornesDesFaits {
  min: number
  max: number
}

export function tracerBarre(
  barre: BarreChronologie,
  axe: AxeTemps,
  orientation: OrientationChronologie,
  travers: number,
  anneeCourante: number,
  montrerIncertitude: boolean,
  faits: BornesDesFaits | null = null,
): TraceDeBarre | null {
  // **L'intervalle de tête** : la naissance, ou le décès quand la naissance est
  // inconnue — la barre couvre alors le seul décès. Le § 9.1 ne décrit pas ce
  // cas ; la charge réelle le porte (onze barres sur 235).
  //
  // Rien de dessinable : la barre n'a nulle part où se poser. L'API ne devrait
  // pas en envoyer — les personnes sans aucune date sont absentes de `barres`
  // (§ 9.1) — mais un rendu qui plante sur une ligne inattendue est pire qu'un
  // rendu qui la laisse de côté.
  const tete = barre.debut !== null ? etendueDIntervalle(barre.debut) : null
  const queue = barre.fin !== null ? etendueDIntervalle(barre.fin) : null
  const premiere = tete ?? queue
  if (premiere === null) return null

  /**
   * **Un fait daté prolonge la barre**, et ce n'est pas une coquetterie : sans
   * cela, le recensement de 1917 d'une personne née en 1879 dont le décès n'est
   * pas daté se pose **hors** de sa barre — qui s'arrête à sa naissance —, flotte
   * dans le vide, et peut tomber sur la barre du voisin de piste, où il se lit
   * comme le sien. Douze jalons sur 29 étaient dans ce cas sur la charge réelle.
   *
   * Vers l'avenir, toujours : le fait prouve que la personne était là. Vers le
   * passé, **seulement quand la naissance est inconnue** — un fait antérieur à
   * une naissance connue est une donnée contradictoire, et on ne dessine pas une
   * barre qui commence avant que la personne ne naisse.
   */
  const debutDessine =
    faits !== null && barre.debut === null ? Math.min(premiere.min, faits.min) : premiere.min

  const long0 = positionAnnee(axe, debutDessine)

  // `debut.max` peut dépasser l'année de fin sur une donnée incohérente (une
  // fourchette de naissance large et un décès tôt) : la barre couvre alors au
  // moins toute sa propre incertitude, plutôt que d'avoir une longueur négative.
  const finAnnee = barre.ouverte
    ? Math.max(anneeCourante, premiere.max)
    : queue !== null
      ? Math.max(queue.max, premiere.max)
      : // Décès non daté : la barre s'arrête à ce que l'on sait — la fourchette
        // de naissance, et rien de plus. La prolonger jusqu'à l'année courante
        // dirait « vivante », ce que la donnée dit justement ne pas savoir.
        premiere.max
  const long1 = positionAnnee(axe, Math.max(finAnnee, faits?.max ?? finAnnee) + 1)

  const t1 = travers + EPAISSEUR_BARRE
  const corps = rectangle(orientation, long0, long1, travers, t1)

  /**
   * La hachure d'un intervalle, **bornée au corps**.
   *
   * Une borne nulle ne se convertit pas en année : elle se rabat sur le bord du
   * corps. « Décédée avant 1931 », née en 1878 : l'incertitude court sur toute
   * la barre, et c'est exact — pas sur les siècles qui précèdent sa naissance.
   */
  const hachure = (intervalle: IntervalleAnnees): Rectangle => {
    const borne = (annee: number | null, defaut: number): number =>
      annee === null ? defaut : Math.min(Math.max(positionAnnee(axe, annee), long0), long1)
    const a = borne(intervalle.min, long0)
    const b =
      intervalle.max === null
        ? long1
        : Math.min(Math.max(positionAnnee(axe, intervalle.max + 1), long0), long1)
    return rectangle(orientation, a, b, travers, t1)
  }

  // La hachure de l'extrémité de tête — la naissance, ou, sur une barre de
  // décès seul, le corps entier, qui *est* le décès. Il n'y a alors qu'une
  // hachure, pas deux.
  const intervalleDeTete = barre.debut ?? barre.fin
  const hachureDebut =
    montrerIncertitude && intervalleDeTete !== null && !intervalleDeTete.certain
      ? hachure(intervalleDeTete)
      : null
  const hachureFin =
    montrerIncertitude && barre.debut !== null && barre.fin !== null && !barre.fin.certain
      ? hachure(barre.fin)
      : null

  const pointeVers = (long: number, sens: 1 | -1): string =>
    polygone(orientation, [
      [long, travers],
      [long + sens * LONGUEUR_POINTE, travers + EPAISSEUR_BARRE / 2],
      [long, t1],
    ])

  // Vers l'avenir : une vie en cours, ou une borne haute non bornée (`AFT`).
  const pointe =
    barre.ouverte || (queue ?? premiere).ouvertApres ? pointeVers(long1, 1) : null
  // Vers le passé : une borne basse non bornée (`BEF`). Symétrique exacte — la
  // barre commence à ce qu'on sait et s'échappe en arrière, sans prétendre à
  // une date d'origine.
  const pointeAvant = premiere.ouvertAvant ? pointeVers(long0, -1) : null

  return {
    corps,
    hachureDebut,
    hachureFin,
    pointe,
    pointeAvant,
    sansDebut: barre.debut === null,
    sansFin: !barre.ouverte && barre.fin === null,
    long0,
    long1,
  }
}

export interface EtiquetteDeBarre {
  x: number
  y: number
  texte: string
  /** Angle en degrés, ou `null` quand le texte se lit à l'horizontale.
   *
   *  Ce `transform` de rotation n'a rien à voir avec le `transform: scale()`
   *  proscrit par la règle n° 1 : celle-ci vise le **zoom**, qui passe par le
   *  `viewBox` et rien d'autre. Faire tourner un texte de 9 px ne rastérise
   *  aucune couche. */
  rotation: number | null
}

/**
 * Le nom dans la barre, ou `null` si la barre est trop courte pour lui.
 *
 * On ne coupe **jamais** un nom et on ne le condense pas : une vie de deux ans
 * ne porte pas d'étiquette, elle porte un `<title>` que le survol et les
 * lecteurs d'écran donnent. Un nom tronqué sur une chronologie généalogique est
 * pire que pas de nom — il se lit comme un autre nom.
 */
export function etiquetteDeBarre(
  barre: BarreChronologie,
  trace: TraceDeBarre,
  orientation: OrientationChronologie,
): EtiquetteDeBarre | null {
  const longueur = trace.long1 - trace.long0
  const requise = barre.displayName.length * LARGEUR_PAR_SIGNE + 2 * MARGE_ETIQUETTE
  if (longueur < requise) return null

  const { corps } = trace
  if (orientation === 'colonnes') {
    // Barre horizontale : texte horizontal, aligné sur son début.
    return {
      x: corps.x + MARGE_ETIQUETTE,
      y: corps.y + corps.h / 2 + BASE_TEXTE,
      texte: barre.displayName,
      rotation: null,
    }
  }
  // Barre verticale : le texte monte le long de la barre, du plus récent vers
  // le plus ancien — c'est le sens de lecture d'un diagramme vertical.
  return {
    x: corps.x + corps.w / 2 + BASE_TEXTE,
    y: corps.y + corps.h - MARGE_ETIQUETTE,
    texte: barre.displayName,
    rotation: -90,
  }
}

/* ------------------------------------------------------------------ */
/* Les bandes                                                          */
/* ------------------------------------------------------------------ */

/** Le rectangle d'une bande : toute l'épaisseur, gouttière des libellés
 *  comprise, pour que l'alternance se lise aussi derrière les libellés. */
export function rectangleDeBande(
  bande: BandeChronologie,
  axe: AxeTemps,
  orientation: OrientationChronologie,
  epaisseur: number,
): Rectangle {
  return rectangle(
    orientation,
    positionAnnee(axe, bande.debut),
    positionAnnee(axe, bande.fin + 1),
    -GOUTTIERE_BANDES,
    epaisseur,
  )
}

/**
 * Une bande sur combien porte un libellé.
 *
 * À `bande = 1`, une bande fait 8 unités : cinquante libellés se
 * chevaucheraient en une bouillie. On en écrit alors un sur *n*, `n` étant le
 * plus petit entier qui laisse la place. Le découpage, lui, ne bouge pas — on
 * n'invente pas des bandes de 5 ans parce que celles d'un an sont étroites.
 */
export function pasDEtiquettes(longueurDeBande: number, minimum: number): number {
  if (longueurDeBande >= minimum) return 1
  if (longueurDeBande <= 0) return 1
  return Math.ceil(minimum / longueurDeBande)
}

export interface EtiquetteDeBande {
  x: number
  y: number
  texte: string
  ancrage: 'start' | 'middle' | 'end'
}

/**
 * Les libellés de bande, déjà placés — `libelle` vient du serveur et n'est pas
 * recomposé (§ 9.1).
 */
export function etiquettesDeBande(
  bandes: readonly BandeChronologie[],
  axe: AxeTemps,
  orientation: OrientationChronologie,
): EtiquetteDeBande[] {
  if (bandes.length === 0) return []

  const longueurDeBande =
    positionAnnee(axe, bandes[0].fin + 1) - positionAnnee(axe, bandes[0].debut)
  const minimum = orientation === 'lignes' ? MIN_ETIQUETTE_LIGNES : MIN_ETIQUETTE_COLONNES
  const pas = pasDEtiquettes(longueurDeBande, minimum)

  const etiquettes: EtiquetteDeBande[] = []
  bandes.forEach((bande, index) => {
    if (index % pas !== 0) return
    const debut = positionAnnee(axe, bande.debut)
    if (orientation === 'lignes') {
      // Gouttière de gauche, texte calé à droite contre la première piste.
      etiquettes.push({
        x: -MARGE_ETIQUETTE,
        y: debut + 11,
        texte: bande.libelle,
        ancrage: 'end',
      })
    } else {
      // Gouttière du haut, texte calé au début de la bande.
      etiquettes.push({
        x: debut + 3,
        y: -MARGE_ETIQUETTE,
        texte: bande.libelle,
        ancrage: 'start',
      })
    }
  })
  return etiquettes
}

/** Le filet qui sépare deux bandes : une ligne en travers, à l'entrée de la
 *  bande. */
export function filetDeBande(
  bande: BandeChronologie,
  axe: AxeTemps,
  orientation: OrientationChronologie,
  epaisseur: number,
): { x1: number; y1: number; x2: number; y2: number } {
  const long = positionAnnee(axe, bande.debut)
  const a = projeter(orientation, long, -GOUTTIERE_BANDES)
  const b = projeter(orientation, long, epaisseur)
  return { x1: a.x, y1: a.y, x2: b.x, y2: b.y }
}

/* ------------------------------------------------------------------ */
/* Cadre                                                               */
/* ------------------------------------------------------------------ */

/**
 * La boîte du contenu — elle alimente le `viewBox` initial, comme `boite` le
 * fait pour l'arbre. Elle couvre les deux gouttières (libellés de bande, noms
 * de branche) et la marge.
 */
export function boiteDuContenu(
  orientation: OrientationChronologie,
  longueur: number,
  epaisseur: number,
): Boite {
  const r = rectangle(orientation, -GOUTTIERE_ENTETE, longueur, -GOUTTIERE_BANDES, epaisseur)
  return {
    x: r.x - MARGE,
    y: r.y - MARGE,
    w: r.w + 2 * MARGE,
    h: r.h + 2 * MARGE,
  }
}

/**
 * Le cadrage de départ : **l'axe des branches remplit le cadre, l'axe du temps
 * défile.**
 *
 * Une chronologie de quatre siècles est un objet très allongé — 3 200 unités de
 * long pour un millier de large. Cadrer la boîte entière dans un écran large la
 * réduit à une colonne étroite au milieu de deux grandes marges vides :
 * `preserveAspectRatio="xMidYMid meet"` prend la plus petite des deux échelles,
 * et c'est la longueur qui commande. Les noms deviennent illisibles et la
 * hachure disparaît, alors même que tout est là.
 *
 * On cale donc la vue sur la **largeur utile** — les colonnes de branche en
 * mode `lignes`, les rangées en mode `colonnes` — et on laisse le temps défiler
 * au déplacement. C'est la lecture d'un diagramme de Gantt, et c'est ce que
 * « histogramme vertical » veut dire.
 *
 * Les butées de zoom, elles, restent calées sur la **boîte du contenu** (voir
 * `CanevasChronologie.vue`) : sans cela, on ne pourrait jamais dézoomer assez
 * pour revoir les quatre siècles d'un coup.
 */
export function cadrageInitial(
  contenu: Boite,
  cadre: { largeur: number; hauteur: number },
  orientation: OrientationChronologie,
): Boite {
  const rapport = Math.max(cadre.largeur, 1) / Math.max(cadre.hauteur, 1)

  if (orientation === 'lignes') {
    // L'axe des branches est horizontal : on le prend en entier.
    const h = contenu.w / rapport
    // Si le temps tient déjà tout entier, on centre au lieu de coller en haut.
    const y = h >= contenu.h ? contenu.y + (contenu.h - h) / 2 : contenu.y
    return { x: contenu.x, y, w: contenu.w, h }
  }

  const w = contenu.h * rapport
  const x = w >= contenu.w ? contenu.x + (contenu.w - w) / 2 : contenu.x
  return { x, y: contenu.y, w, h: contenu.h }
}

/** Deux boîtes sont-elles la même ? Sert à décider si un nouveau rendu doit
 *  reprendre le cadrage du lecteur ou le remettre à zéro : changer de réglage
 *  d'affichage ne doit pas lui faire perdre l'endroit qu'il regardait. */
export function memeBoite(a: Boite | null, b: Boite | null): boolean {
  if (a === null || b === null) return false
  return a.x === b.x && a.y === b.y && a.w === b.w && a.h === b.h
}
