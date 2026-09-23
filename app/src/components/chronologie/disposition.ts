/**
 * La disposition : quelle barre va sur quelle piste, et où commence chaque
 * groupe de branche.
 *
 * C'est le calcul qui, faux, produit une chronologie **plausible mais fausse** —
 * deux vies superposées sur la même piste se lisent comme une seule, et rien ne
 * le signale. D'où un module pur, testable sans navigateur, séparé du dessin.
 *
 * **Les colonnes sont les branches** (`pp`, `pm`, `mp`, `mm`, `tr`), avec les
 * couleurs déjà posées dans la charte : la chronologie n'invente pas sa palette,
 * elle reprend celle du liseré des fiches de l'arbre (§ 7.4 du dossier).
 *
 * **Le rangement en pistes est un « premier trou qui accueille »**, sur les
 * barres triées par début : c'est l'algorithme le plus simple qui garantisse
 * qu'aucune barre n'en recouvre une autre, et il est déterministe — deux
 * chargements de la même donnée donnent le même dessin, ce qui compte pour
 * comparer deux captures d'écran.
 */

import type {
  BarreChronologie,
  Branche,
  JalonChronologie,
  OrientationChronologie,
  VueChronologie,
} from '@/lib/api'
import {
  ECART_LONGUEUR,
  EPAISSEUR_BARRE,
  GOUTTIERE_BRANCHE,
  PAS_PISTE,
  UNITE_ANNEE,
  axeDepuisBandes,
  longueurDeLAxe,
  positionAnnee,
  tracerBarre,
  type AxeTemps,
  type BornesDesFaits,
  type TraceDeBarre,
} from './geometrie'

/** L'ordre des quatre quarts, du tronc, puis des quatre quarts du conjoint. Celui de la
 *  charte et de la légende de l'arbre : on ne le réordonne pas au gré des filtres, sinon
 *  les colonnes changent de place d'un réglage à l'autre.
 *
 *  **`bf` recule en dernier, et ce n'est pas indifférent.** Les colonnes de la chronologie
 *  se lisent maintenant comme le dessin : les quatre quarts du lecteur, son tronc, les
 *  quatre de son conjoint. La belle-famille — celle de sa fratrie et de ses enfants — n'a
 *  pas de place dans cette lecture de gauche à droite ; elle se range donc au bout, et non
 *  entre le tronc et la moitié droite, où elle couperait la symétrie en deux. */
export const ORDRE_BRANCHES: readonly Branche[] = [
  'pp',
  'pm',
  'mp',
  'mm',
  'tr',
  'cpp',
  'cpm',
  'cmp',
  'cmm',
  'bf',
]

/** Les noms de branche du produit — les mêmes que la légende de l'arbre. */
export const LIBELLES_BRANCHE: Record<Branche, string> = {
  pp: 'Romarin',
  pm: 'Bourrache',
  mp: 'Chardon',
  mm: 'Tilleul',
  tr: 'Tronc',
  bf: 'Belle-famille',
  cpp: 'Romarin du conjoint',
  cpm: 'Bourrache du conjoint',
  cmp: 'Chardon du conjoint',
  cmm: 'Tilleul du conjoint',
}

/** Le libellé long, pour la légende et les infobulles. */
export const DESCRIPTIONS_BRANCHE: Record<Branche, string> = {
  pp: 'Romarin — paternelle du père',
  pm: 'Bourrache — maternelle du père',
  mp: 'Chardon — paternelle de la mère',
  mm: 'Tilleul — maternelle de la mère',
  tr: 'Tronc — l’ego, sa fratrie, son conjoint, ses parents',
  bf: 'Belle-famille — celle de la fratrie et des enfants',
  cpp: 'Romarin du conjoint — paternelle de son père',
  cpm: 'Bourrache du conjoint — maternelle de son père',
  cmp: 'Chardon du conjoint — paternelle de sa mère',
  cmm: 'Tilleul du conjoint — maternelle de sa mère',
}

/* ------------------------------------------------------------------ */
/* Le rangement en pistes                                              */
/* ------------------------------------------------------------------ */

export interface SegmentAPlacer {
  debut: number
  fin: number
}

/**
 * L'indice de piste de chaque segment, **dans l'ordre d'entrée**.
 *
 * Les segments sont examinés du plus ancien au plus récent et posés sur la
 * première piste libre. À début égal, l'ordre d'entrée décide : le tri de
 * JavaScript est stable, la disposition est donc reproductible.
 *
 * `ecart` est le blanc exigé entre deux segments d'une même piste.
 */
export function repartirEnPistes(
  segments: readonly SegmentAPlacer[],
  ecart: number,
): number[] {
  const ordre = segments.map((_, index) => index)
  ordre.sort((a, b) => segments[a].debut - segments[b].debut)

  /** Fin du dernier segment posé sur chaque piste. */
  const fins: number[] = []
  const pistes: number[] = new Array<number>(segments.length).fill(0)

  for (const index of ordre) {
    const segment = segments[index]
    let piste = fins.findIndex((fin) => segment.debut >= fin + ecart)
    if (piste === -1) {
      piste = fins.length
      fins.push(segment.fin)
    } else {
      fins[piste] = Math.max(fins[piste], segment.fin)
    }
    pistes[index] = piste
  }
  return pistes
}

/* ------------------------------------------------------------------ */
/* La disposition complète                                             */
/* ------------------------------------------------------------------ */

export interface BarrePlacee {
  barre: BarreChronologie
  /** Bord de la barre sur l'axe transverse. */
  travers: number
  /** Indice de piste **dans son groupe** — sert aux tests et au diagnostic. */
  piste: number
  trace: TraceDeBarre
}

export interface GroupeDeBranche {
  branche: Branche
  libelle: string
  /** Bord du groupe sur l'axe transverse, et son épaisseur totale. */
  travers: number
  epaisseur: number
  /** Nombre de pistes. Un groupe vide en garde une, pour que son nom ait une
   *  place — et **pas** pour dire combien de barres il contient. */
  pistes: number
}

export interface Disposition {
  axe: AxeTemps
  /** Longueur de l'axe du temps, en unités : le maximum de l'axe des bandes et
   *  du bout de la barre la plus tardive (une barre ouverte peut dépasser la
   *  dernière bande d'une fraction d'année). */
  longueur: number
  epaisseur: number
  groupes: GroupeDeBranche[]
  barres: BarrePlacee[]
  /**
   * Barres reçues mais impossibles à poser — aucune borne de date, donc aucune
   * position. Ce compte existe pour le diagnostic et les tests ; il n'est
   * **jamais affiché** : un décompte de barres manquantes trahirait par
   * soustraction le nombre de personnes que le cercle protège (§ 9.1).
   */
  ignorees: number
}

/** Rien à dessiner : pas de bande, ou aucune barre visible. L'écran affiche
 *  alors un état vide explicite, jamais un canevas blanc. */
export function estVide(vue: VueChronologie): boolean {
  return vue.bandes.length === 0 || vue.barres.length === 0
}

/**
 * Place toutes les barres.
 *
 * `orientation` et `montrerIncertitude` viennent des réglages **du lecteur** ;
 * `bandes`, `barres` et `branches` viennent du serveur. La frontière est nette :
 * ce module ne redérive aucune donnée, il ne fait que placer.
 *
 * Rend `null` quand il n'y a pas de bande — l'axe n'existe pas, et fabriquer
 * une géométrie sur des bornes vides ferait disparaître le dessin sans le dire.
 */
export function disposer(
  vue: VueChronologie,
  orientation: OrientationChronologie,
  anneeCourante: number,
  montrerIncertitude: boolean,
): Disposition | null {
  const axe = axeDepuisBandes(vue.bandes)
  if (axe === null) return null

  // Les groupes suivent l'ordre de la charte, et non celui du filtre. On garde
  // aussi toute branche qui porte une barre sans figurer dans le filtre : une
  // barre sans groupe disparaîtrait du dessin sans erreur ni trace.
  const presentes = new Set<Branche>(vue.barres.map((b) => b.branch))
  const demandees = new Set<Branche>(vue.branches)
  const branches = ORDRE_BRANCHES.filter((b) => demandees.has(b) || presentes.has(b))

  /**
   * Les années extrêmes des faits datés de chaque personne. Un fait est une
   * preuve d'existence à sa date : il entre dans l'étendue de la barre, sans
   * quoi il se pose à côté d'elle — voir `tracerBarre`.
   */
  const faitsParPersonne = new Map<number, BornesDesFaits>()
  for (const jalon of vue.jalons) {
    const connu = faitsParPersonne.get(jalon.personId)
    if (connu === undefined) faitsParPersonne.set(jalon.personId, { min: jalon.annee, max: jalon.annee })
    else {
      connu.min = Math.min(connu.min, jalon.annee)
      connu.max = Math.max(connu.max, jalon.annee)
    }
  }

  const groupes: GroupeDeBranche[] = []
  const barres: BarrePlacee[] = []
  let curseur = 0
  let ignorees = 0

  for (const branche of branches) {
    // On trace une première fois pour connaître les bornes réelles de chaque
    // barre — incertitude comprise —, puis on range. Tracer avant de ranger est
    // ce qui garantit qu'une hachure ne mord jamais sur la piste voisine.
    //
    // Une barre sans aucune borne de date ne se trace pas : elle n'a nulle part
    // où se poser. Elle est **comptée** dans `ignorees` plutôt que de disparaître
    // en silence — un écart qui ne se compte pas ne se corrige jamais.
    const placables: { barre: BarreChronologie; long0: number; long1: number }[] = []
    for (const barre of vue.barres) {
      if (barre.branch !== branche) continue
      const faits = faitsParPersonne.get(barre.personId) ?? null
      const essai = tracerBarre(barre, axe, orientation, 0, anneeCourante, montrerIncertitude, faits)
      if (essai === null) {
        ignorees += 1
        continue
      }
      placables.push({ barre, long0: essai.long0, long1: essai.long1 })
    }

    const pistes = repartirEnPistes(
      placables.map((p) => ({ debut: p.long0, fin: p.long1 })),
      ECART_LONGUEUR,
    )
    const nombreDePistes = pistes.length > 0 ? Math.max(...pistes) + 1 : 1

    placables.forEach(({ barre }, index) => {
      const travers = curseur + pistes[index] * PAS_PISTE
      const trace = tracerBarre(
        barre,
        axe,
        orientation,
        travers,
        anneeCourante,
        montrerIncertitude,
        faitsParPersonne.get(barre.personId) ?? null,
      )
      if (trace === null) return
      barres.push({ barre, travers, piste: pistes[index], trace })
    })

    const epaisseur = nombreDePistes * PAS_PISTE - (PAS_PISTE - EPAISSEUR_BARRE)
    groupes.push({
      branche,
      libelle: LIBELLES_BRANCHE[branche],
      travers: curseur,
      epaisseur,
      pistes: nombreDePistes,
    })
    curseur += epaisseur + GOUTTIERE_BRANCHE
  }

  // Pas de gouttière après le dernier groupe.
  const epaisseur = Math.max(curseur - GOUTTIERE_BRANCHE, EPAISSEUR_BARRE)

  // Une barre ouverte s'arrête à l'année courante, qui peut tomber au-delà de la
  // dernière bande : on étend le dessin plutôt que de couper la barre. On
  // n'ajoute **pas** de bande pour autant — les bandes viennent du serveur.
  const longueur = barres.reduce((max, b) => Math.max(max, b.trace.long1), longueurDeLAxe(axe))

  return { axe, longueur, epaisseur, groupes, barres, ignorees }
}

/* ------------------------------------------------------------------ */
/* Les noms de branche                                                 */
/* ------------------------------------------------------------------ */

export interface EnteteDeGroupe {
  x: number
  y: number
  texte: string
  branche: Branche
  ancrage: 'start' | 'middle' | 'end'
}

/** Les noms de branche, placés en tête de leur groupe : au-dessus des colonnes
 *  en mode `lignes`, à gauche des rangées en mode `colonnes`. */
export function entetesDeGroupe(
  groupes: readonly GroupeDeBranche[],
  orientation: OrientationChronologie,
): EnteteDeGroupe[] {
  return groupes.map((groupe) => {
    const centre = groupe.travers + groupe.epaisseur / 2
    if (orientation === 'lignes') {
      return {
        x: centre,
        y: -14,
        texte: groupe.libelle,
        branche: groupe.branche,
        ancrage: 'middle' as const,
      }
    }
    return {
      x: -12,
      y: centre + 3.2,
      texte: groupe.libelle,
      branche: groupe.branche,
      ancrage: 'end' as const,
    }
  })
}

/** La position d'un jalon sur la barre d'une personne : au milieu de son année
 *  et au milieu de l'épaisseur de la barre. */
export function pointDeJalon(
  axe: AxeTemps,
  orientation: OrientationChronologie,
  annee: number,
  travers: number,
): { x: number; y: number } {
  const long = positionAnnee(axe, annee) + UNITE_ANNEE / 2
  const centre = travers + EPAISSEUR_BARRE / 2
  return orientation === 'lignes' ? { x: centre, y: long } : { x: long, y: centre }
}

export interface JalonPlace {
  jalon: JalonChronologie
  personId: number
  point: { x: number; y: number }
  /**
   * Trait de rattachement à sa barre, `null` quand le jalon tombe dessus.
   *
   * **Un jalon appartient visuellement à sa piste, toujours.** Posé librement
   * hors de sa barre, il devient attribuable à la barre voisine de la même
   * piste — « recensement 1917 » lu sur la ligne de quelqu'un d'autre. Une
   * information fausse donnée pour un fait est pire qu'une information absente.
   *
   * Le prolongement de la barre par ses propres faits (voir `tracerBarre`)
   * supprime le cas courant : sur la charge réelle, plus aucun jalon ne tombe
   * dehors. Reste le cas contradictoire — un fait **antérieur à une naissance
   * connue** —, que l'on ne prolonge pas, parce qu'on ne dessine pas quelqu'un
   * vivant avant de naître. Celui-là garde son trait, et reste donc lisible sans
   * ambiguïté au lieu d'être masqué : la date est vraie, c'est la barre qui est
   * courte.
   */
  attache: { x1: number; y1: number; x2: number; y2: number } | null
}

/**
 * Place les jalons sur les barres déjà posées.
 *
 * Pur, donc vérifiable sans navigateur — c'est le calcul qui décide de
 * l'appartenance visuelle d'un fait à une personne, et une erreur y attribue
 * silencieusement un acte au voisin.
 */
export function placerJalons(
  vue: VueChronologie,
  disposition: Disposition,
  orientation: OrientationChronologie,
): JalonPlace[] {
  const parPersonne = new Map(disposition.barres.map((b) => [b.barre.personId, b]))
  const places: JalonPlace[] = []

  for (const jalon of vue.jalons) {
    const placee = parPersonne.get(jalon.personId)
    // Un jalon dont la personne n'a pas de barre n'est pas dessiné : il
    // flotterait à côté, sans dire de qui il parle.
    if (placee === undefined) continue

    const point = pointDeJalon(disposition.axe, orientation, jalon.annee, placee.travers)
    const long = positionAnnee(disposition.axe, jalon.annee) + UNITE_ANNEE / 2
    const { long0, long1 } = placee.trace

    let attache: JalonPlace['attache'] = null
    if (long < long0 || long > long1) {
      const bord = long < long0 ? long0 : long1
      const centre = placee.travers + EPAISSEUR_BARRE / 2
      const a = orientation === 'lignes' ? { x: centre, y: long } : { x: long, y: centre }
      const b = orientation === 'lignes' ? { x: centre, y: bord } : { x: bord, y: centre }
      attache = { x1: a.x, y1: a.y, x2: b.x, y2: b.y }
    }

    places.push({ jalon, personId: jalon.personId, point, attache })
  }
  return places
}
