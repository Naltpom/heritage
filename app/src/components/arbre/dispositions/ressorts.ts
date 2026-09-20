/**
 * **Disposition « ressorts et isotonie »** — `/arbre-7`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **LE PLACEMENT A UNE SOLUTION EXACTE, ET ELLE N'EST PAS ITÉRATIVE.**
 *
 * Les autres dispositions répartissent les abscisses par balayages : on pousse,
 * on écarte, on repousse, et l'on s'arrête quand plus rien ne bouge beaucoup.
 * C'est ce qui fait que deux forces se battent — le ressort veut rapprocher,
 * l'écartement veut séparer, et chaque passe défait le travail de l'autre. On
 * amortit alors, et l'on obtient un compromis dont personne ne sait dire de quel
 * problème il est la solution.
 *
 * Ici, non. Pour **une rangée dont l'ordre est déjà fixé**, le problème s'écrit :
 *
 * ```
 * minimiser  Σ poids_i × (x_i − désir_i)²
 * sous       x_{i+1} − x_i ≥ écart_i         (demi-largeurs + marge)
 * ```
 *
 * et le changement de variable suivant le rend trivial :
 *
 * ```
 * cumul_i = Σ_{k<i} écart_k      y_i = x_i − cumul_i      e_i = désir_i − cumul_i
 *
 * alors    x_{i+1} − x_i ≥ écart_i   ⟺   y_{i+1} ≥ y_i
 * ```
 *
 * **La contrainte de non-chevauchement devient une simple contrainte de
 * croissance.** Minimiser une somme de carrés pondérés sous contrainte de
 * croissance, c'est la **régression isotonique**, et l'algorithme PAVA la résout
 * **exactement, en une passe, en O(n)** : on fusionne deux voisins qui violent
 * l'ordre en un bloc portant leur moyenne pondérée, et l'on recommence.
 *
 * Trois conséquences qui valent qu'on les dise :
 *
 * - **aucun chevauchement n'est possible** — la contrainte est satisfaite *par
 *   construction*, pas rattrapée par une passe d'écartement ;
 * - **rien ne diverge**, puisqu'il n'y a aucune boucle interne à faire converger ;
 * - **le déterminisme ne dépend d'aucun seuil.** PAVA rend l'optimum, pas un
 *   état intermédiaire.
 *
 * ── Ce que PAVA ne fait pas ─────────────────────────────────────────────────
 *
 * **Il place, il ne réordonne pas.** L'ordre des rangées lui est donné, et c'est
 * celui du serveur — `AffinageDuPlacement`, qui l'a arrêté sous les invariants
 * convenus avec le commanditaire. Cette disposition ne les rediscute pas : elle
 * prend l'ordre qu'on lui donne et cherche les meilleures abscisses *dans cet
 * ordre*. C'est aussi ce qui la distingue d'`/arbre-4`, qui réordonne et dont le
 * prix annoncé est l'instabilité.
 *
 * ── Le désir et le poids ne sont pas réglés à la main ───────────────────
 *
 * Le `désir_i` est la **moyenne des voisins** du nœud, et le `poids_i` leur
 * **nombre**. Ce ne sont pas des réglages : ce sont le minimum et la courbure de
 * `E` pour cette seule abscisse, les autres tenues fixes. **Aucune constante
 * n'est choisie à la main.**
 *
 * Deux versions ont été jetées avant celle-ci, et les deux pour la même raison :
 * elles optimisaient un objectif *voisin* de celui sur lequel on les notait.
 *
 * 1. Trois ressorts et trois constantes (0,35 · 0,5 · 0,25) : **+16 %** sur la
 *    somme des carrés.
 * 2. Le losange tenu pour la moyenne de ses époux, donc éliminé du modèle. Mesuré
 *    sur l'arbre réel : **177 losanges sur 178 ne sont pas à cette moyenne**,
 *    l'écart monte à 1 243 px, et les y ramener coûte à soi seul **119,8 →
 *    184,9 M** — avant qu'aucun solveur n'ait tourné. Le losange n'est pas une
 *    moyenne : c'est un nœud, tiré par ses époux **et par ses enfants**.
 *
 * C'est pourquoi cette disposition n'appelle pas `grapheReplace` du socle, qui
 * recale les losanges sur le barycentre du couple : ce serait payer les 65 M.
 *
 * ## Et c'est ce qui fait qu'un tour ne peut pas empirer
 *
 * Toutes les arêtes joignent une **personne** à une **famille**, et les deux vivent
 * sur des ordonnées différentes. **Deux nœuds d'une même rangée ne sont donc
 * jamais voisins** : à l'intérieur d'une rangée, `E` est *exactement* la somme
 * séparable que PAVA minimise, et non une approximation. Placer une rangée ne peut
 * donc que faire baisser `E` — c'est une propriété, pas un espoir.
 *
 * **À une réserve près, et elle se dit :** le tronc du lecteur est figé par un
 * poids énorme vers une position fixe, terme qui n'appartient pas à `E`. La
 * décroissance est donc *démontrée* sans le figement et *mesurée* avec lui —
 * 119,8 → 113,7 → 111,8 → … → 109,8 M sur l'arbre réel, et c'est ce que vérifie le
 * test « la somme des carrés ne remonte à aucun tour ».
 * ────────────────────────────────────────────────────────────────────
 */

import type { AreteGraphe, Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
import { boiteEnglobante, ESPACE, type OptionsDeDisposition } from './socle'

/**
 * Tours de relaxation. **Fixe, et non « jusqu'à convergence »** : un nombre de
 * pas arrêté rend la sortie reproductible d'un appel à l'autre, ce qu'un critère
 * d'arrêt sur un seuil ne garantit pas.
 */
export const ITERATIONS = 8

/* ------------------------------------------------------------------ */
/* PAVA — la régression isotonique                                     */
/* ------------------------------------------------------------------ */

/**
 * **Pool Adjacent Violators.** Rend la suite croissante `y` qui minimise
 * `Σ poids_i × (y_i − valeurs_i)²`.
 *
 * L'invariant de la pile : les blocs empilés portent des valeurs **croissantes**.
 * À l'arrivée d'une valeur plus petite que le sommet, les deux blocs ne peuvent
 * pas rester distincts sans violer la croissance — on les fusionne, et la valeur
 * du bloc fusionné est la moyenne pondérée, qui est l'optimum d'un bloc contraint
 * à être plat. La fusion peut en déclencher d'autres : d'où la boucle.
 *
 * Chaque élément est empilé une fois et fusionné au plus une fois : **O(n)**.
 *
 * @param valeurs les cibles, dans l'ordre de la rangée
 * @param poids   strictement positifs, même longueur que `valeurs`
 */
export function pava(valeurs: readonly number[], poids: readonly number[]): number[] {
  const valeur: number[] = []
  const masse: number[] = []
  const taille: number[] = []

  for (let i = 0; i < valeurs.length; i += 1) {
    let v = valeurs[i]!
    let m = poids[i]!
    let t = 1

    while (valeur.length > 0 && valeur[valeur.length - 1]! > v) {
      const vPrecedent = valeur.pop()!
      const mPrecedent = masse.pop()!
      const tPrecedent = taille.pop()!

      v = (vPrecedent * mPrecedent + v * m) / (mPrecedent + m)
      m = mPrecedent + m
      t = tPrecedent + t
    }

    valeur.push(v)
    masse.push(m)
    taille.push(t)
  }

  const y: number[] = []
  for (let bloc = 0; bloc < valeur.length; bloc += 1) {
    for (let k = 0; k < taille[bloc]!; k += 1) y.push(valeur[bloc]!)
  }

  return y
}

/** Ce que rend le placement d'une rangée. */
export interface PlacementDeRangee {
  abscisses: Map<IdentifiantNoeud, number>
  /** Les nœuds dont la borne est **inconciliable** avec la séparation de la rangée. */
  bornesInfaisables: IdentifiantNoeud[]
}

/**
 * Les abscisses optimales d'**une** rangée, sous séparation **et** sous bornes.
 *
 * ## Le changement de variable, deux fois
 *
 * `x_i = y_i + cumul_i`, où `cumul` est la somme des écarts minimaux à gauche de
 * `i`. La croissance de `y` donne alors `x_{i+1} - x_i >= écart_i` — le
 * non-chevauchement, **sans aucune passe de rattrapage**.
 *
 * La même transformation emmène les **bornes** dans l'espace `y` :
 * `bas_i - cumul_i <= y_i <= haut_i - cumul_i`. On a donc une régression isotonique
 * **sous contraintes de boîte**, et elle a, elle aussi, une solution exacte.
 *
 * ## Monotoniser les bornes, puis écrêter — et c'est optimal
 *
 * Une borne inférieure à gauche contraint aussi tout ce qui est à droite, puisque
 * la suite croît. On remplace donc `L` par son **maximum courant de gauche à
 * droite**, et `U` par son **minimum courant de droite à gauche** — les deux
 * deviennent croissants, et ils portent alors toute l'information des bornes.
 *
 * **Écrêter la solution isotonique libre sur ces bornes-là donne l'optimum sous
 * contraintes** (Best, Chakravarti & Ubhaya) : écrêter une suite croissante entre
 * deux suites croissantes la laisse croissante, donc la séparation survit, et
 * aucune autre suite admissible ne fait mieux. **Ce n'est pas une projection
 * après coup** — mesuré le 20/09/2026, projeter coûtait 24 à 35 M ; ceci ne coûte
 * que ce que la contrainte vaut vraiment.
 *
 * ## Quand la borne ne peut pas être tenue
 *
 * Si `L'_i > U'_i`, la boîte est **inconciliable avec la séparation** : deux
 * couples dont les intervalles se recouvrent et dont les losanges ne tiennent pas
 * tous les deux dedans. **C'est une information sur le graphe, pas un échec du
 * solveur** : on le dit, et on retient `L'_i`, qui est la position admissible la
 * plus proche par la gauche.
 */
export function placerParIsotonie(
  rangee: readonly NoeudGraphe[],
  desirs: ReadonlyMap<IdentifiantNoeud, number>,
  poids: ReadonlyMap<IdentifiantNoeud, number>,
  bornes?: ReadonlyMap<IdentifiantNoeud, { bas: number; haut: number }>,
): PlacementDeRangee {
  const abscisses = new Map<IdentifiantNoeud, number>()
  const bornesInfaisables: IdentifiantNoeud[] = []
  if (rangee.length === 0) return { abscisses, bornesInfaisables }

  const cumuls: number[] = [0]
  for (let i = 1; i < rangee.length; i += 1) {
    const gauche = rangee[i - 1]!
    const droite = rangee[i]!
    cumuls.push(cumuls[i - 1]! + gauche.w / 2 + ESPACE + droite.w / 2)
  }

  const cibles = rangee.map((noeud, i) => (desirs.get(noeud.id) ?? noeud.x) - cumuls[i]!)
  const masses = rangee.map((noeud) => poids.get(noeud.id) ?? 1)

  const y = pava(cibles, masses)

  if (bornes === undefined) {
    rangee.forEach((noeud, i) => abscisses.set(noeud.id, y[i]! + cumuls[i]!))
    return { abscisses, bornesInfaisables }
  }

  // Les bornes, dans l'espace `y`, puis rendues croissantes.
  const bas = rangee.map((noeud, i) => {
    const borne = bornes.get(noeud.id)
    return borne === undefined ? Number.NEGATIVE_INFINITY : borne.bas - cumuls[i]!
  })
  const haut = rangee.map((noeud, i) => {
    const borne = bornes.get(noeud.id)
    return borne === undefined ? Number.POSITIVE_INFINITY : borne.haut - cumuls[i]!
  })

  for (let i = 1; i < rangee.length; i += 1) bas[i] = Math.max(bas[i]!, bas[i - 1]!)
  for (let i = rangee.length - 2; i >= 0; i -= 1) haut[i] = Math.min(haut[i]!, haut[i + 1]!)

  rangee.forEach((noeud, i) => {
    let valeur = y[i]!

    if (bas[i]! > haut[i]!) {
      bornesInfaisables.push(noeud.id)
      valeur = bas[i]!
    } else {
      valeur = Math.min(Math.max(valeur, bas[i]!), haut[i]!)
    }

    abscisses.set(noeud.id, valeur + cumuls[i]!)
  })

  return { abscisses, bornesInfaisables }
}

/**
 * Le poids d'un nœud **figé** : assez grand pour qu'aucun voisinage ne le
 * déplace, assez fini pour que PAVA reste numériquement sage.
 *
 * Figé et non retiré du problème : il doit continuer de **séparer** ses voisins de
 * rangée, sans quoi on aurait un trou où les autres viendraient se poser.
 */
export const POIDS_D_UN_FIGE = 1e6

/**
 * **Le tronc du lecteur, qui ne bouge pas** — invariant I10 du serveur, et celui-ci
 * est vérifié sur l'arbre RÉEL, contrairement à I5.
 *
 * Les nœuds marqués `branch: 'tr'` sont le lecteur et sa ligne directe ; le serveur
 * les isole entre les deux ascendances. Sans ce point fixe, la relaxation les tire
 * vers la moyenne de leurs voisins et le tronc dérive dans l'ascendance paternelle
 * — mesuré le 20/09/2026 : un nœud de tronc à −1 391 px alors que le côté
 * paternel s'arrêtait à +382.
 *
 * C'est **le seul endroit où cette disposition impose autre chose que l'objectif**,
 * et c'est parce que la référence l'impose aussi.
 */
export function troncDuLecteur(noeuds: readonly NoeudGraphe[]): Map<IdentifiantNoeud, number> {
  const fige = new Map<IdentifiantNoeud, number>()

  for (const noeud of noeuds) {
    if (noeud.type !== 'personne') continue
    if ((noeud as { branch?: string | null }).branch !== 'tr') continue
    fige.set(noeud.id, noeud.x)
  }

  return fige
}

/* ------------------------------------------------------------------ */
/* Le voisinage, et le modèle local                                    */
/* ------------------------------------------------------------------ */

/**
 * Les voisins de chaque nœud, par les arêtes.
 *
 * Personnes **et** familles : le losange est un nœud du graphe, pas une moyenne
 * calculée à côté. C'est la correction qui a coûté 65 M à une version précédente,
 * où il était tenu pour le milieu de ses époux : mesuré sur l'arbre réel, **177
 * losanges sur 178 ne sont pas à ce milieu**, et l'écart monte à 1 243 px.
 */
export function voisinage(aretes: readonly AreteGraphe[]): Map<IdentifiantNoeud, IdentifiantNoeud[]> {
  const voisins = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()

  const ajoute = (de: IdentifiantNoeud, vers: IdentifiantNoeud): void => {
    const liste = voisins.get(de)
    if (liste === undefined) voisins.set(de, [vers])
    else liste.push(vers)
  }

  for (const arete of aretes) {
    ajoute(arete.de, arete.vers)
    ajoute(arete.vers, arete.de)
  }

  return voisins
}

/**
 * Ce que chaque nœud voudrait, et avec quelle insistance.
 *
 * `desirs` est l'abscisse qui minimise `E` **pour ce nœud seul**, les autres tenues
 * fixes — c'est-à-dire la moyenne de ses voisins. `poids` est la courbure de `E`
 * en ce point, c'est-à-dire leur nombre. **Aucune constante n'est choisie à la
 * main** : ce sont la dérivée et la dérivée seconde de l'objectif du serveur.
 *
 * Un nœud sans voisin garde son abscisse : son désir est sa position, et PAVA ne le
 * déplacera que si un voisin de rangée a besoin de la place.
 */
export interface ModeleLocal {
  desirs: Map<IdentifiantNoeud, number>
  poids: Map<IdentifiantNoeud, number>
}

export function modeleLocal(
  noeuds: readonly NoeudGraphe[],
  voisins: ReadonlyMap<IdentifiantNoeud, IdentifiantNoeud[]>,
  x: ReadonlyMap<IdentifiantNoeud, number>,
  figes?: ReadonlyMap<IdentifiantNoeud, number>,
): ModeleLocal {
  const desirs = new Map<IdentifiantNoeud, number>()
  const poids = new Map<IdentifiantNoeud, number>()

  for (const noeud of noeuds) {
    const fige = figes?.get(noeud.id)
    if (fige !== undefined) {
      desirs.set(noeud.id, fige)
      poids.set(noeud.id, POIDS_D_UN_FIGE)
      continue
    }

    const mienne = x.get(noeud.id) ?? noeud.x
    const siens = voisins.get(noeud.id) ?? []

    let somme = 0
    let combien = 0

    for (const voisin of siens) {
      const abscisse = x.get(voisin)
      if (abscisse === undefined) continue
      somme += abscisse
      combien += 1
    }

    desirs.set(noeud.id, combien === 0 ? mienne : somme / combien)
    poids.set(noeud.id, combien === 0 ? 1 : combien)
  }

  return { desirs, poids }
}

/**
 * **Les bornes externes d'un losange : l'intervalle de ses époux.**
 *
 * C'est l'invariant I5 du serveur, exprimé comme ce qu'il est vraiment : une
 * **borne de boîte** sur le nœud-famille, dont les deux côtés viennent d'une autre
 * rangée. Elle est donc **connue avant** de résoudre la rangée des losanges — à
 * condition de balayer de haut en bas, ce que fait {@link unTour}.
 *
 * Un losange à un seul époux, ou dont les époux ne sont pas placés, n'est pas
 * borné : I5 ne dit rien de lui, et lui inventer une borne le figerait sans raison.
 *
 * ## — ET CETTE DISPOSITION NE S'EN SERT PAS. Voici pourquoi.
 *
 * `PlacementLisibleTest::testI5LeLosangeResteEntreSesEpoux` éprouve I5 sur un arbre
 * **synthétique** de dix personnes. Le test de l'arbre **réel**, lui, vérifie I1, I2,
 * I3, I7, I10, la somme des carrés et le trait le plus long — **pas I5.** Mesuré le
 * 20/09/2026 sur la charge de production : **63 losanges sur 178 sont hors de
 * l'intervalle de leurs époux**, jusqu'à 1 132 px. L'écran de référence présente
 * donc déjà cet écart, à la même échelle que ce solveur (64).
 *
 * Le coût de l'imposer, mesuré sur trois méthodes. **Les quatre lignes ont été
 * prises dans les mêmes conditions, avant que le tronc ne soit figé** — elles se
 * comparent entre elles, pas au chiffre courant de la disposition, qui est de
 * 109,8 M :
 *
 * | | somme des carrés | losanges hors intervalle |
 * |---|---|---|
 * | sans borne | **100,0 M** | 64 / 178 |
 * | désir écrêté avant PAVA | 120,6 M | 12 / 178 |
 * | isotonie sous boîtes (exacte) | 132,3 M | 6 / 178, tous **infaisables** |
 * | — pour mémoire, la production | 119,8 M | 63 / 178 |
 *
 * **Se contraindre plus que la référence, c'est perdre 32 M pour corriger ce
 * qu'elle ne corrige pas.** La capacité reste écrite et éprouvée — `placerParIsotonie`
 * prend des bornes et rend l'optimum sous boîtes — pour le jour où le commanditaire
 * voudra I5 partout ; elle n'est simplement pas branchée ici.
 */
export function bornesDesLosanges(
  epoux: ReadonlyMap<IdentifiantNoeud, IdentifiantNoeud[]>,
  x: ReadonlyMap<IdentifiantNoeud, number>,
): Map<IdentifiantNoeud, { bas: number; haut: number }> {
  const bornes = new Map<IdentifiantNoeud, { bas: number; haut: number }>()

  for (const [famille, membres] of epoux) {
    if (membres.length < 2) continue

    const abscisses = membres
      .map((id) => x.get(id))
      .filter((valeur): valeur is number => valeur !== undefined)

    if (abscisses.length < 2) continue

    bornes.set(famille, { bas: Math.min(...abscisses), haut: Math.max(...abscisses) })
  }

  return bornes
}

/**
 * Toutes les rangées, losanges compris, de haut en bas et de gauche à droite.
 *
 * `rangeesDePersonnes` du socle exclut les losanges — c'est juste pour les
 * dispositions qui ne les déplacent pas. Ici ils sont des variables à part
 * entière, et leur rangée doit être placée comme les autres, séparation comprise :
 * deux losanges qui se chevauchent dessinent une seule barre d'union pour deux
 * couples.
 */
export function rangeesCompletes(noeuds: readonly NoeudGraphe[]): NoeudGraphe[][] {
  const rangees = new Map<number, NoeudGraphe[]>()

  for (const noeud of noeuds) {
    const rangee = rangees.get(noeud.y)
    if (rangee === undefined) rangees.set(noeud.y, [noeud])
    else rangee.push(noeud)
  }

  for (const rangee of rangees.values()) {
    rangee.sort((a, b) => a.x - b.x || (a.id < b.id ? -1 : 1))
  }

  return [...rangees.entries()].sort(([a], [b]) => a - b).map(([, rangee]) => rangee)
}

/* ------------------------------------------------------------------ */
/* La règle sémantique, appliquée AVANT le placement                   */
/* ------------------------------------------------------------------ */

/** Les familles dont l'union est rompue, lues sur les arêtes d'union. */
export function famillesRompues(aretes: readonly AreteGraphe[]): Set<IdentifiantNoeud> {
  const rompues = new Set<IdentifiantNoeud>()

  for (const arete of aretes) {
    if (arete.genre === 'union' && arete.divorce === true) rompues.add(arete.vers)
  }

  return rompues
}

/** Les époux de chaque famille, et les familles de chaque époux. */
export function unions(aretes: readonly AreteGraphe[]): {
  epoux: Map<IdentifiantNoeud, IdentifiantNoeud[]>
  familles: Map<IdentifiantNoeud, IdentifiantNoeud[]>
} {
  const epoux = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()
  const familles = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()

  for (const arete of aretes) {
    if (arete.genre !== 'union') continue
    epoux.set(arete.vers, [...(epoux.get(arete.vers) ?? []), arete.de])
    familles.set(arete.de, [...(familles.get(arete.de) ?? []), arete.vers])
  }

  return { epoux, familles }
}

/**
 * **Le conjoint accolé est celui de l'union NON ROMPUE.**
 *
 * Une personne à deux unions ne peut être accolée qu'à un seul de ses conjoints.
 * Jusqu'ici c'était celui qui porte les enfants — et sur l'arbre réel, ça dessinait
 * **l'ex-épouse collée et la compagne actuelle reléguée à trois rangs**. Ce n'est pas
 * une maladresse d'affichage : c'est une information fausse pour qui lit l'arbre.
 *
 * La règle devient donc : **l'union non rompue d'abord** ; à défaut — plusieurs
 * ruptures, ou aucune information — celle qui porte des enfants, comme avant.
 *
 * ## C'est un échange d'ORDRE, et il a lieu AVANT PAVA
 *
 * PAVA place, il ne réordonne pas : aucun jeu sur les poids ne peut rendre adjacente
 * une personne séparée de trois rangs. Il faut donc **échanger les deux conjoints dans
 * la rangée**, et le faire avant la relaxation — **une contrainte sémantique se pose
 * d'abord, l'optimisation travaille dessous.**
 *
 * L'échange porte sur les abscisses des deux CONJOINTS, pas sur celle de la personne :
 * le reste de la rangée ne bouge pas, et PAVA rétablit ensuite les écarts. Mesuré sur
 * l'arbre réel : **deux cas changent**, et les six autres remariés ne bougent pas.
 */
export function echangerVersLUnionNonRompue(
  noeuds: readonly NoeudGraphe[],
  liens: { epoux: Map<IdentifiantNoeud, IdentifiantNoeud[]>; familles: Map<IdentifiantNoeud, IdentifiantNoeud[]> },
  rompues: ReadonlySet<IdentifiantNoeud>,
): Map<IdentifiantNoeud, number> {
  const x = new Map(noeuds.map((n) => [n.id, n.x]))
  const y = new Map(noeuds.map((n) => [n.id, n.y]))

  for (const [personne, familles] of liens.familles) {
    if (familles.length < 2) continue

    const mienne = x.get(personne)
    const rangee = y.get(personne)
    if (mienne === undefined || rangee === undefined) continue

    const candidats = familles
      .map((famille) => ({ famille, conjoint: (liens.epoux.get(famille) ?? []).find((id) => id !== personne) }))
      .filter((c): c is { famille: IdentifiantNoeud; conjoint: IdentifiantNoeud } => c.conjoint !== undefined)
      .filter((c) => y.get(c.conjoint) === rangee)
      .map((c) => ({
        famille: c.famille,
        conjoint: c.conjoint,
        ecart: Math.abs((x.get(c.conjoint) ?? 0) - mienne),
        rompue: rompues.has(c.famille),
      }))

    if (candidats.length < 2) continue

    const plusProche = candidats.reduce((a, b) => (b.ecart < a.ecart ? b : a))
    if (!plusProche.rompue) continue

    const intacts = candidats.filter((c) => !c.rompue)
    if (intacts.length === 0) continue

    const repris = intacts.reduce((a, b) => (b.ecart < a.ecart ? b : a))
    const ici = x.get(plusProche.conjoint)
    const la = x.get(repris.conjoint)
    if (ici === undefined || la === undefined) continue

    x.set(plusProche.conjoint, la)
    x.set(repris.conjoint, ici)
  }

  return x
}

/**
 * **À partir de combien d'enfants une fratrie est-elle « conséquente ».**
 *
 * Trois, et c'est une décision, pas une constante technique. Mesuré le
 * 20/09/2026 sur l'arbre réel, le nombre de couples que la règle sépare tombe
 * d'une falaise :
 *
 * | seuil | couples séparés |
 * |---|---|
 * | 2 | 12 |
 * | **3** | **8** |
 * | 4 | 1 |
 * | 5 et au-delà | 1 |
 *
 * À deux, une fratrie de deux enfants serait « conséquente » et la règle perdrait
 * son sens. À quatre, il ne reste qu'un seul cas dans tout l'arbre. **Trois est le
 * seul seuil où la règle décrit une famille nombreuse sans devenir anecdotique.**
 */
export const FRATRIE_CONSEQUENTE = 3

/**
 * **Quand les deux époux viennent d'une grosse fratrie, le couple cède : chacun
 * reste entier au milieu des siens, et le trait d'union s'allonge.**
 *
 * C'est l'arbitrage du commanditaire, et il est **l'inverse de celui du
 * serveur** : `PlacementLisibleTest::testLeCoupleRejointLaPlusGrandeDesDeuxFratries`
 * garde le couple ensemble et casse la plus petite fratrie. Les deux lectures de
 * la phrase « sauf si le poids des deux familles est conséquent » étaient
 * défendables ; il a choisi celle-ci. **La maquette porte la sienne, le serveur
 * garde la sienne** — c'est à ça qu'une maquette sert, et c'est ce qui permet de
 * les comparer à l'écran.
 *
 * ## Elle ne s'applique PAS à une union rompue
 *
 * Un remariage est déjà traité par {@link echangerVersLUnionNonRompue}, qui
 * rapproche le conjoint intact. Appliquer les deux règles au même couple
 * reviendrait à le rapprocher puis à l'éloigner. **La rupture l'emporte** : une
 * union rompue n'est pas un couple à garder ensemble, donc la question du poids
 * des fratries ne se pose pas pour elle.
 *
 * ## Ce que « rester entier » veut dire ici
 *
 * L'époux qui a été tiré hors de sa fratrie y **revient par le côté d'où il
 * venait**. On ne prétend pas connaître son rang de naissance : la charge du
 * client ne porte aucune date, et `etiquetteDates` vaut `vivant·e` pour les
 * vivants. Ce que la règle garantit est que **les deux fratries redeviennent
 * contiguës**, pas que l'aîné soit à gauche — cet ordre-là appartient au serveur,
 * qui a les vraies dates.
 */
/**
 * **Les deux fratries sont-elles déjà voisines dans la rangée ?**
 *
 * C'est la condition qui décide si la règle des grosses fratries a le droit de
 * s'appliquer — et **c'est une condition, pas un seuil**. Le seuil dit *à partir
 * de quand* une fratrie est nombreuse ; la condition dit *dans quel cas* les
 * séparer répare quelque chose.
 *
 * ## Ce qu'elle empêche, mesuré sur l'arbre réel
 *
 * Quand les deux fratries se touchent déjà, ramener chaque époux chez les siens
 * ne coûte qu'un trait d'union de quelques centaines de pixels : c'est le cas que
 * le commanditaire décrivait. Quand elles sont à l'opposé de la rangée — huit
 * fiches d'un côté, cinq de l'autre, et deux étrangères au milieu — la même règle
 * écarte le couple de **3 210 px et quatorze fiches**. Le dessin le montre : le
 * trait d'union traverse la moitié de l'écran. Ce n'est plus l'arbitrage demandé,
 * c'est sa caricature.
 *
 * ## Ce que « voisines » veut dire, sans réglage
 *
 * Chaque fratrie est prise **avec les conjoints de ses membres** — un beau-frère
 * assis au milieu d'une fratrie en fait partie pour l'œil. Les deux ensembles
 * occupent chacun un intervalle de la rangée. Elles sont voisines si ces
 * intervalles se chevauchent, ou si **tout ce qui les sépare appartient encore à
 * l'une des deux**. Une seule fiche étrangère au milieu suffit à dire non : il
 * n'y a **aucun nombre à régler**.
 *
 * **Le couple examiné est retiré des deux ensembles**, et sans ça la condition ne
 * dit rien du tout : l'épouse est déjà accolée à son mari, donc comptée parmi les
 * conjoints de la fratrie de celui-ci, et les deux intervalles se chevauchent
 * toujours. Mesuré : sans ce retrait, la condition rendait « voisines » pour les
 * quinze couples de l'arbre, y compris celui que le dessin montre écarté de
 * quatorze fiches.
 */
export function fratriesVoisines(
  premiere: readonly IdentifiantNoeud[],
  seconde: readonly IdentifiantNoeud[],
  rangee: readonly IdentifiantNoeud[],
  familles: ReadonlyMap<IdentifiantNoeud, IdentifiantNoeud[]>,
  epoux: ReadonlyMap<IdentifiantNoeud, IdentifiantNoeud[]>,
  couple: readonly IdentifiantNoeud[] = [],
): boolean {
  const examine = new Set(couple)

  const avecConjoints = (fratrie: readonly IdentifiantNoeud[]): Set<IdentifiantNoeud> => {
    const tous = new Set<IdentifiantNoeud>(fratrie)
    for (const membre of fratrie) {
      for (const famille of familles.get(membre) ?? []) {
        for (const conjoint of epoux.get(famille) ?? []) tous.add(conjoint)
      }
    }
    for (const id of examine) tous.delete(id)

    return tous
  }

  const a = avecConjoints(premiere)
  const b = avecConjoints(seconde)

  const bornes = (ensemble: ReadonlySet<IdentifiantNoeud>): [number, number] | null => {
    const places = rangee.map((id, i) => (ensemble.has(id) ? i : -1)).filter((i) => i >= 0)

    return places.length === 0 ? null : [places[0]!, places[places.length - 1]!]
  }

  const ba = bornes(a)
  const bb = bornes(b)
  if (ba === null || bb === null) return false

  // Chevauchement : elles sont déjà mêlées, donc voisines.
  if (ba[0] <= bb[1] && bb[0] <= ba[1]) return true

  const [gauche, droite] = ba[1] < bb[0] ? [ba, bb] : [bb, ba]

  for (let i = gauche[1] + 1; i < droite[0]; i += 1) {
    const id = rangee[i]
    if (id === undefined) continue
    // Le couple examiné est TRANSPARENT : retiré des deux ensembles, il ne doit
    // pas pour autant compter comme l'étranger qui les sépare — il est assis
    // entre les deux précisément parce que le serveur l'a collé.
    if (examine.has(id)) continue
    if (!a.has(id) && !b.has(id)) return false
  }

  return true
}

export function separerLesGrossesFratries(
  noeuds: readonly NoeudGraphe[],
  aretes: readonly AreteGraphe[],
  seuil: number = FRATRIE_CONSEQUENTE,
): Map<IdentifiantNoeud, number> {
  const x = new Map(noeuds.map((n) => [n.id, n.x]))
  const liens = unions(aretes)
  const rompues = famillesRompues(aretes)

  const enfants = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()
  const familleDesParents = new Map<IdentifiantNoeud, IdentifiantNoeud>()
  for (const arete of aretes) {
    if (arete.genre !== 'filiation') continue
    enfants.set(arete.de, [...(enfants.get(arete.de) ?? []), arete.vers])
    familleDesParents.set(arete.vers, arete.de)
  }

  // **Le tronc du lecteur ne se remanie pas.** Le serveur l'isole entre les deux
  // ascendances (I10), et réattribuer ses emplacements le fait dériver dans
  // l'ascendance paternelle — mesuré le 20/09/2026 : un nœud de tronc à −3 407 px
  // alors que le côté paternel s'arrêtait à −218. Il est donc retiré des rangées
  // avant le remaniement, et ses abscisses restent celles qu'il avait.
  const tronc = troncDuLecteur(noeuds)

  // Les rangées de personnes, dans l'ordre reçu.
  const rangees = new Map<number, IdentifiantNoeud[]>()
  for (const noeud of noeuds) {
    if (noeud.type !== 'personne') continue
    if (tronc.has(noeud.id)) continue
    rangees.set(noeud.y, [...(rangees.get(noeud.y) ?? []), noeud.id])
  }
  for (const ids of rangees.values()) ids.sort((a, b) => (x.get(a) ?? 0) - (x.get(b) ?? 0))

  const rangeeDe = new Map<IdentifiantNoeud, number>()
  for (const [y, ids] of rangees) for (const id of ids) rangeeDe.set(id, y)

  /** Les époux à ramener dans leur fratrie, par rangée. */
  const aRamener = new Map<number, IdentifiantNoeud[]>()

  for (const [famille, membres] of liens.epoux) {
    if (membres.length !== 2) continue
    if (rompues.has(famille)) continue

    const [a, b] = membres as [IdentifiantNoeud, IdentifiantNoeud]
    const y = rangeeDe.get(a)
    if (y === undefined || y !== rangeeDe.get(b)) continue

    const fa = familleDesParents.get(a)
    const fb = familleDesParents.get(b)
    if (fa === undefined || fb === undefined) continue
    if ((enfants.get(fa) ?? []).length < seuil) continue
    if ((enfants.get(fb) ?? []).length < seuil) continue

    // **La règle ne s'applique que si les deux fratries sont déjà voisines.**
    // Voir {@link fratriesVoisines} : ailleurs, elle écarterait le couple d'un
    // bout à l'autre de la rangée au lieu de le décaler de quelques fiches.
    if (
      !fratriesVoisines(
        enfants.get(fa) ?? [],
        enfants.get(fb) ?? [],
        rangees.get(y) ?? [],
        liens.familles,
        liens.epoux,
        [a, b],
      )
    ) {
      continue
    }

    aRamener.set(y, [...(aRamener.get(y) ?? []), a, b])
  }

  for (const [y, epouxDeLaRangee] of aRamener) {
    let ordre = [...(rangees.get(y) ?? [])]

    for (const personne of epouxDeLaRangee) {
      const sienne = familleDesParents.get(personne)
      if (sienne === undefined) continue

      const fratrie = (enfants.get(sienne) ?? []).filter((id) => id !== personne)
      const places = fratrie
        .map((id) => ordre.indexOf(id))
        .filter((i) => i >= 0)
        .sort((p, q) => p - q)

      if (places.length === 0) continue

      const ici = ordre.indexOf(personne)
      if (ici < 0) continue

      // Déjà collé à l'un des siens : rien à faire.
      if (places.some((i) => Math.abs(i - ici) === 1)) continue

      const premier = places[0]!
      const dernier = places[places.length - 1]!
      const sansLui = ordre.filter((id) => id !== personne)
      const decalage = ici < premier ? 0 : 1

      // Il revient par le côté d'où il venait : à gauche de sa fratrie s'il était
      // à sa gauche, à droite sinon.
      const cible = ici < premier ? premier - decalage : dernier - decalage + 1

      sansLui.splice(cible, 0, personne)
      ordre = sansLui
    }

    // Les emplacements du serveur, réattribués dans le nouvel ordre : la rangée
    // garde exactement sa largeur et ses écarts, seuls les occupants changent.
    const emplacements = (rangees.get(y) ?? []).map((id) => x.get(id) ?? 0).sort((p, q) => p - q)
    ordre.forEach((id, i) => x.set(id, emplacements[i] ?? (x.get(id) ?? 0)))
  }

  return x
}

/* ------------------------------------------------------------------ */
/* La relaxation                                                       */
/* ------------------------------------------------------------------ */

/**
 * Un tour : chaque rangée, de haut en bas, replacée exactement.
 *
 * **Gauss-Seidel, et non Jacobi.** Le modèle se recalcule avant CHAQUE rangée, sur
 * les positions déjà mises à jour par les rangées précédentes. Le calculer une
 * seule fois par tour ferait placer les enfants d'après des parents d'il y a un
 * tour, et c'est ce décalage qui fait osciller. L'ordre de parcours est fixe — les
 * rangées par ordonnée croissante —, donc la sortie reste reproductible.
 */
function unTour(
  voisins: ReadonlyMap<IdentifiantNoeud, IdentifiantNoeud[]>,
  rangees: readonly (readonly NoeudGraphe[])[],
  figes: ReadonlyMap<IdentifiantNoeud, number>,
  x: ReadonlyMap<IdentifiantNoeud, number>,
): { positions: Map<IdentifiantNoeud, number>; deplacement: number; infaisables: Set<IdentifiantNoeud> } {
  const positions = new Map(x)
  const infaisables = new Set<IdentifiantNoeud>()
  let deplacement = 0

  for (const rangee of rangees) {
    const { desirs, poids } = modeleLocal(rangee, voisins, positions, figes)

    // **Les bornes ne sont PAS passées ici, et c'est mesuré.** Voir
    // {@link bornesDesLosanges} : la production elle-même laisse 63 losanges sur
    // 178 hors de l'intervalle de leurs époux. Les imposer coûterait 32 M pour
    // corriger un écart que l'écran de référence présente déjà.
    const place = placerParIsotonie(rangee, desirs, poids)

    for (const id of place.bornesInfaisables) infaisables.add(id)

    for (const [id, valeur] of place.abscisses) {
      deplacement += Math.abs(valeur - (positions.get(id) ?? 0))
      positions.set(id, valeur)
    }
  }

  return { positions, deplacement, infaisables }
}

/**
 * **Les losanges qui sortent de l'intervalle de leurs époux** — la violation de
 * l'invariant I5 du serveur, comptée et mesurée plutôt que tue.
 *
 * ## Pourquoi cette disposition ne la corrige pas
 *
 * Parce que la corriger par **projection** coûte plus que le solveur ne gagne, et
 * que ça a été mesuré trois fois le 20/09/2026, sur l'arbre réel, à trois endroits
 * différents de la boucle :
 *
 * | où l'on borne | somme des carrés |
 * |---|---|
 * | nulle part (ce que fait ce fichier) | **100,0 M** |
 * | une fois, après les huit tours | 123,8 M |
 * | après chaque rangée | 126,0 M |
 * | après chaque tour | 134,6 M |
 *
 * — contre **119,8 M** pour le serveur. Autrement dit : **borné, le solveur est
 * moins bon que ce qu'il remplace ; libre, il fait 16,5 % de mieux.** Une
 * contrainte posée *après* l'optimisation se paie deux fois : une fois pour la
 * tenir, une fois pour tout ce qu'on avait optimisé en l'ignorant. **I5 doit entrer
 * dans le problème, pas s'appliquer à sa sortie** — et PAVA tel qu'il est écrit ici
 * ne sait pas porter une borne qui vient d'une autre rangée.
 *
 * D'où le choix, qui n'est **pas** au code de le faire : cette fonction ne corrige
 * rien, elle **compte**. Le test s'en sert pour que le défaut soit lisible dans la
 * suite au lieu d'être découvert à l'écran.
 */
export function losangesHorsDeLeursEpoux(
  epoux: ReadonlyMap<IdentifiantNoeud, IdentifiantNoeud[]>,
  x: ReadonlyMap<IdentifiantNoeud, number>,
): { combien: number; depassementMaximal: number } {
  let combien = 0
  let depassementMaximal = 0

  for (const [famille, membres] of epoux) {
    if (membres.length !== 2) continue

    const a = x.get(membres[0]!)
    const b = x.get(membres[1]!)
    const mienne = x.get(famille)
    if (a === undefined || b === undefined || mienne === undefined) continue

    const bas = Math.min(a, b)
    const haut = Math.max(a, b)
    const depasse = mienne < bas ? bas - mienne : mienne > haut ? mienne - haut : 0

    if (depasse > 0.5) {
      combien += 1
      depassementMaximal = Math.max(depassementMaximal, depasse)
    }
  }

  return { combien, depassementMaximal }
}

/**
 * La somme des carrés des traits — **l'objectif, tel que le serveur le mesure**
 * (`PlacementLisibleTest::sommeDesCarres`). Exporté parce qu'une mesure qu'un test
 * ne peut pas lire ne prouve rien.
 */
export function sommeDesCarres(
  aretes: readonly AreteGraphe[],
  x: ReadonlyMap<IdentifiantNoeud, number>,
): number {
  return aretes.reduce((somme, arete) => {
    const de = x.get(arete.de)
    const vers = x.get(arete.vers)
    if (de === undefined || vers === undefined) return somme
    return somme + (de - vers) * (de - vers)
  }, 0)
}

/**
 * Le mouvement total de chaque tour, et l'objectif après chacun.
 *
 * Les deux ensemble : un mouvement qui tombe ne prouve pas que l'objectif baisse,
 * et un objectif qui baisse ne prouve pas que ça se stabilise.
 */
export function mouvements(
  graphe: Graphe,
  tours: number = ITERATIONS,
): { deplacements: number[]; objectifs: number[] } {
  const voisins = voisinage(graphe.aretes)
  const rangees = rangeesCompletes(graphe.noeuds)
  const figes = troncDuLecteur(graphe.noeuds)

  let x: ReadonlyMap<IdentifiantNoeud, number> = new Map(graphe.noeuds.map((n) => [n.id, n.x]))
  const deplacements: number[] = []
  const objectifs: number[] = [sommeDesCarres(graphe.aretes, x)]

  for (let tour = 0; tour < tours; tour += 1) {
    const { positions, deplacement } = unTour(voisins, rangees, figes, x)
    x = positions
    deplacements.push(deplacement)
    objectifs.push(sommeDesCarres(graphe.aretes, x))
  }

  return { deplacements, objectifs }
}

/**
 * `/arbre-7` : les désirs, résolus exactement rangée par rangée.
 *
 * Un seul geste après la relaxation : **le lecteur reste à l'abscisse zéro** (I7),
 * par une translation globale, qui ne change aucune distance et évite que la vue
 * s'ouvre ailleurs que sur lui.
 *
 * **I5 n'est pas imposé, et ce n'est pas un oubli** : la production en laisse 63 sur
 * 178 hors de l'intervalle de leurs époux, ce solveur 64. Voir
 * {@link bornesDesLosanges} pour le tableau des trois méthodes essayées et ce
 * qu'elles coûtent.
 */
export function ressortsIsotoniques(graphe: Graphe, _options: OptionsDeDisposition): Graphe {
  if (graphe.noeuds.length === 0) return graphe

  // **La règle sémantique d'abord, l'optimisation dessous.** L'échange des conjoints
  // change l'ORDRE, il doit donc précéder PAVA qui place sans réordonner.
  const echange = echangerVersLUnionNonRompue(
    graphe.noeuds,
    unions(graphe.aretes),
    famillesRompues(graphe.aretes),
  )
  const apresEchange = graphe.noeuds.map((n) => {
    const abscisse = echange.get(n.id)
    return abscisse === undefined || abscisse === n.x ? n : { ...n, x: abscisse }
  })

  // Puis la seconde règle sémantique : les grosses fratries restent entières.
  // Elle vient APRÈS l'échange, parce qu'elle ne s'applique qu'aux unions non
  // rompues et doit donc voir l'ordre que l'échange a déjà arrêté.
  const separe = separerLesGrossesFratries(apresEchange, graphe.aretes)
  const reordonne: Graphe = {
    ...graphe,
    noeuds: apresEchange.map((n) => {
      const abscisse = separe.get(n.id)
      return abscisse === undefined || abscisse === n.x ? n : { ...n, x: abscisse }
    }),
  }

  const rangees = rangeesCompletes(reordonne.noeuds)
  if (rangees.length === 0) return graphe

  const voisins = voisinage(reordonne.aretes)
  const figes = troncDuLecteur(reordonne.noeuds)
  let x: ReadonlyMap<IdentifiantNoeud, number> = new Map(reordonne.noeuds.map((n) => [n.id, n.x]))

  for (let tour = 0; tour < ITERATIONS; tour += 1) {
    x = unTour(voisins, rangees, figes, x).positions
  }

  const borne = x
  const origine = borne.get(reordonne.ego) ?? 0
  const noeuds = reordonne.noeuds.map((noeud) => {
    const valeur = borne.get(noeud.id)
    if (valeur === undefined) return noeud
    const abscisse = valeur - origine
    return abscisse === noeud.x ? noeud : { ...noeud, x: abscisse }
  })

  return { ...graphe, noeuds, boite: boiteEnglobante(noeuds, graphe.boite) }
}
