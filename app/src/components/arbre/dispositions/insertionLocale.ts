/**
 * **L'arrivée d'une fiche dans un arbre déjà à l'écran.**
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QUI CASSE LES REPÈRES N'EST PAS LE SERVEUR, C'EST NOUS.**
 *
 * Mesuré le 20/09/2026 sur deux charges réelles successives, prises à deux
 * minutes d'intervalle pendant qu'on saisissait des fiches (622 → 625 nœuds,
 * six fiches de plus) :
 *
 * | | fiches déplacées, sur 447 | de plus de 50 px |
 * |---|---|---|
 * | la charge brute du serveur | **0** | 0 |
 * | la même, passée par `ressorts` | **109** | 33 |
 *
 * Et sur la paire suivante (625 → 633, six fiches) : **92** et 15. Le moteur du
 * serveur réserve la place et ne remue rien ; c'est la **relaxation globale du
 * client** — huit tours sur toutes les rangées — qui redistribue un quart de
 * l'arbre pour six fiches. Le lecteur, lui, voit ses repères partir.
 *
 * Ce module ne corrige pas la relaxation : elle est juste, et c'est elle qui
 * rend les traits courts. Il décide **quand on la rejoue**. Par défaut on ne la
 * rejoue pas : on garde la mise en page qui est à l'écran, et l'on y **insère**
 * ce qui vient d'arriver.
 * ────────────────────────────────────────────────────────────────────
 *
 * ## Il est indépendant de la disposition, et ce n'est pas un hasard
 *
 * Il ne connaît ni PAVA, ni les ressorts, ni les blocs : il prend **deux
 * graphes déjà placés** — celui qui est à l'écran, celui que la disposition
 * vient de calculer — et rend le premier, augmenté du second. `/arbre`,
 * `/arbre` et les maquettes empruntaient exactement le même chemin, ce qui
 * est la règle de ce dossier : une seule vue, plusieurs mises en page. Une
 * insertion écrite **dans** `ressorts.ts` aurait été une sixième copie à
 * corriger, et elle aurait dû rediscuter le contrat de PAVA — *il place, il ne
 * réordonne pas* — pour y glisser un cas particulier.
 *
 * ## La carte va à sa place, ce sont les voisines qui cèdent
 *
 * L'autre partage était possible : glisser la nouvelle carte dans l'intervalle
 * libre le plus proche, et ne bouger personne. Il a été écarté — un enfant
 * posé à côté de la place de ses parents plutôt que dessous ne dit plus rien de
 * juste, et l'économie de mouvement se paierait en information fausse. La carte
 * se pose donc **exactement** à la moyenne de ses voisins déjà placés, et la
 * poussée s'éteint au premier intervalle qui l'absorbe.
 *
 * ## Quand on rejoue quand même — une CONDITION, pas un seuil
 *
 * Un seuil se règle, et le défaut réapparaît un cran plus loin. Ce qu'on peut
 * **constater**, en revanche, c'est le fait suivant, et il n'a pas de nombre à
 * l'intérieur : **la poussée a atteint le tronc du lecteur.**
 *
 * Le tronc est ce que la disposition fige — le lecteur et sa ligne directe,
 * `troncDuLecteur`, invariant I10 du serveur — et le lecteur y est tenu à
 * l'abscisse **zéro**, invariant I7. Si une poussée le déplace, tenir I7
 * demanderait de **translater tout l'arbre**, c'est-à-dire de déplacer chaque
 * carte de l'écran. L'insertion locale ne peut alors plus être locale **par
 * construction**, et ce n'est pas une opinion sur un seuil : c'est une
 * impossibilité.
 *
 * Et c'est exactement le cas que le commanditaire décrivait : *« le jour où
 * j'accroche quinze générations à quelqu'un, sa branche pèse plus lourd que les
 * autres »*. Le tronc est **entre les deux ascendances** ; une branche qui
 * grossit vers l'intérieur le pousse, une branche qui grossit vers l'extérieur
 * élargit l'arbre sans rien déranger — et elle n'a, elle, aucune raison de
 * déclencher quoi que ce soit.
 *
 * Ce que la condition donne sur l'arbre réel d'aujourd'hui est mesuré, pas
 * supposé ; le relevé est dans la suite de tests.
 */

import type { AreteGraphe, Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
import { boiteEnglobante, ESPACE } from './socle'

/**
 * La tolérance sous laquelle deux abscisses sont la même.
 *
 * Elle n'est pas un réglage : les abscisses viennent de moyennes et de sommes
 * de demi-largeurs, donc d'arithmétique flottante. Sans elle, une rangée déjà
 * séparée se déclarerait à séparer, chaque nœud « pousserait » son voisin d'un
 * milliardième de pixel, et la poussée n'atteindrait jamais un intervalle qui
 * l'absorbe — autrement dit, tout serait toujours à réorganiser.
 */
const EPSILON = 0.001

/**
 * Ce qui a rendu la réorganisation nécessaire — **un fait constaté**, pas un
 * seuil franchi.
 *
 * Le motif voyage jusqu'à l'écran : l'annonce doit dire *pourquoi* l'arbre a
 * bougé, sans quoi le lecteur subit un déplacement dont il ne peut rien faire.
 */
export interface MotifDeReorganisation {
  cas: 'tronc-pousse'
  /** Combien de nœuds du tronc ont bougé. */
  combien: number
  /** De combien, au plus. */
  ecart: number
  /** `true` quand le lecteur lui-même est du nombre. */
  lecteur: boolean
}

/** Une poussée de rangée : les seuls nœuds qui ont dû s'écarter. */
export type Poussee = Map<IdentifiantNoeud, number>

/** Le strict nécessaire pour placer : un identifiant, une abscisse, une largeur. */
interface Place {
  id: IdentifiantNoeud
  x: number
  w: number
}

/**
 * **Pose un nœud à l'abscisse voulue et écarte ses voisins, des deux côtés,
 * jusqu'au premier intervalle qui absorbe la poussée.**
 *
 * C'est le seul geste géométrique de ce module, et il est volontairement écrit
 * seul : il se prouve sur cinq nœuds à la main, là où une insertion complète
 * demande un arbre.
 *
 * La rangée est **déjà triée** par abscisse et **ne contient pas** le nouveau
 * venu. Elle n'est pas réordonnée — comme PAVA, on place sans permuter : un
 * ajout qui réordonnerait sa rangée rendrait au lecteur une fratrie dans un
 * autre ordre que celui qu'il regardait, ce qui est exactement le défaut qu'on
 * cherche à éviter.
 *
 * L'arrêt sur le premier intervalle suffisant est ce qui rend l'insertion
 * locale : sur l'arbre réel les rangées portent une quarantaine de fiches pour
 * plus de vingt mille pixels, et le jeu se trouve en général chez le deuxième
 * ou le troisième voisin.
 */
export function poserEnPoussant(rangee: readonly Place[], nouveau: Place): Poussee {
  const abscisses: Poussee = new Map()

  // Le rang d'insertion : tout ce qui est à gauche du nouveau venu reste à
  // gauche. À abscisse égale, le nouveau passe devant — il vient d'arriver,
  // rien ne lui a encore réservé de place.
  let rang = 0
  while (rang < rangee.length && rangee[rang]!.x < nouveau.x) rang += 1

  let limite = nouveau.x + nouveau.w / 2 + ESPACE
  for (let i = rang; i < rangee.length; i += 1) {
    const voisin = rangee[i]!
    const minimum = limite + voisin.w / 2
    if (voisin.x >= minimum - EPSILON) break

    abscisses.set(voisin.id, minimum)
    limite = minimum + voisin.w / 2 + ESPACE
  }

  limite = nouveau.x - nouveau.w / 2 - ESPACE
  for (let i = rang - 1; i >= 0; i -= 1) {
    const voisin = rangee[i]!
    const maximum = limite - voisin.w / 2
    if (voisin.x <= maximum + EPSILON) break

    abscisses.set(voisin.id, maximum)
    limite = maximum - voisin.w / 2 - ESPACE
  }

  return abscisses
}

/** Où la carte s'est finalement posée, et qui a dû s'écarter. */
export interface Pose {
  /** L'abscisse retenue — la place logique, ou tout près. */
  x: number
  poussee: Poussee
}

/**
 * **La carte se décale plutôt que d'ouvrir la rangée en deux — mais pas plus que
 * de la place qu'elle réclame.**
 *
 * PAVA laisse derrière lui de **longs blocs à écart minimal** : c'est sa nature,
 * il fusionne les voisins qui se gênent et les pose exactement séparés. Une carte
 * posée au milieu d'un tel bloc n'a nulle part où prendre sa largeur, et la
 * poussée court alors jusqu'au premier intervalle — des deux côtés à la fois.
 * Mesuré le 20/09/2026 sur l'arbre réel, à poser la carte exactement sur sa
 * moyenne : **13,6 fiches écartées en moyenne, 84 au pire.** Le reste de l'arbre
 * bougeait, et la promesse n'était pas tenue.
 *
 * On essaie donc aussi les abscisses **voisines** de la place logique — celles où
 * la carte se glisse juste à côté d'une fiche existante — et l'on retient celle
 * qui écarte le moins de monde. À égalité, la plus proche de la place logique :
 * le départage ne doit pas dépendre de l'ordre d'énumération.
 *
 * **Et le décalage est borné à la largeur d'une fiche plus un écart**, c'est-à-dire
 * exactement la place qu'il faut se faire. Sans cette borne, la carte irait
 * chercher l'intervalle gratuit le plus proche, fût-il à l'autre bout de la
 * rangée : un enfant apparaîtrait à quinze fiches de ses parents, et l'on aurait
 * troqué un déplacement visible contre une information fausse.
 */
export function poser(rangee: readonly Place[], nouveau: Place): Pose {
  const ecartMaximal = nouveau.w + ESPACE

  const candidates = [nouveau.x]
  for (const voisin of rangee) {
    candidates.push(voisin.x - voisin.w / 2 - ESPACE - nouveau.w / 2)
    candidates.push(voisin.x + voisin.w / 2 + ESPACE + nouveau.w / 2)
  }

  let meilleure: Pose = { x: nouveau.x, poussee: poserEnPoussant(rangee, nouveau) }

  for (const x of candidates) {
    if (Math.abs(x - nouveau.x) > ecartMaximal) continue

    const poussee = poserEnPoussant(rangee, { ...nouveau, x })
    const mieux =
      poussee.size < meilleure.poussee.size ||
      (poussee.size === meilleure.poussee.size &&
        Math.abs(x - nouveau.x) < Math.abs(meilleure.x - nouveau.x) - EPSILON)

    if (mieux) meilleure = { x, poussee }
  }

  return meilleure
}

/**
 * Les voisins de chaque nœud, par les arêtes — **personnes et losanges**.
 *
 * Même lecture que `ressorts.ts` : le losange est un nœud du graphe et non la
 * moyenne de ses époux. Elle est refaite ici plutôt qu'importée parce que ce
 * module ne doit rien devoir à une disposition en particulier ; c'est six lignes
 * et cela vaut mieux qu'une dépendance qui obligerait `/arbre` à charger le
 * solveur de `ressorts`.
 */
function voisinage(aretes: readonly AreteGraphe[]): Map<IdentifiantNoeud, IdentifiantNoeud[]> {
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
 * Le tronc du lecteur, lu sur `branch` comme le fait la disposition — **et le
 * lecteur lui-même, quoi que dise `branch`.**
 *
 * La seconde moitié n'est pas une ceinture de plus : l'ego est calculé **par
 * lecteur**, et il est déjà arrivé qu'un compte tombe sur un repli où il n'est
 * pas celui qu'on croit. Un ego sans `branch: 'tr'` passerait alors à travers la
 * condition, et l'invariant I7 — le lecteur à l'abscisse zéro — serait rompu
 * sans que rien ne le dise.
 */
function estDuTronc(noeud: NoeudGraphe, ego: IdentifiantNoeud): boolean {
  return noeud.id === ego || (noeud as { branch?: string | null }).branch === 'tr'
}

/** Ce que l'arrivée de nouvelles fiches a produit. */
export interface Arrivee {
  /** Le graphe à dessiner. */
  graphe: Graphe
  /** Les nœuds qui viennent d'entrer — **fiches et losanges**. */
  nouveaux: IdentifiantNoeud[]
  /**
   * Les seules **fiches** parmi eux. Comptées à part parce que c'est le nombre
   * que l'écran annonce : un losange n'est pas une personne, et « trois
   * nouvelles fiches » pour un couple et son trait d'union serait faux.
   */
  fichesNouvelles: IdentifiantNoeud[]
  /**
   * Les fiches **déjà présentes** dont l'abscisse a changé — le chiffre sur
   * lequel cette livraison se juge. `nouveaux` en est exclu : une carte qui
   * arrive bouge forcément.
   */
  deplacees: IdentifiantNoeud[]
  /**
   * Ceux qu'aucun voisin déjà placé n'a permis d'ancrer. Ils gardent l'abscisse
   * que la disposition leur a donnée — on ne leur invente pas de place, et on le
   * dit plutôt que de les poser sur l'ego, comme le serveur le dit déjà de ses
   * `nonPlaces`.
   */
  sansAncre: IdentifiantNoeud[]
  /** Non nul quand l'insertion a cessé d'être locale. */
  motif: MotifDeReorganisation | null
}

/**
 * **Insère les nouveaux nœuds dans la mise en page qui est à l'écran.**
 *
 * @param affiche le graphe **tel qu'il est dessiné** — pas la charge brute :
 *                ce sont ses abscisses que le lecteur a sous les yeux, et ce
 *                sont elles qu'on lui garde
 * @param servi   la charge fraîche, **déjà passée par la disposition de la
 *                route** : on n'y prend que la topologie, les ordonnées, les
 *                largeurs, et l'abscisse de secours des nœuds sans ancre
 *
 * ## Par vagues, comme le serveur
 *
 * Une branche proposée en bloc arrive d'un coup : l'enfant dont les parents
 * sont eux-mêmes nouveaux n'a, au premier passage, aucun voisin placé. On répète
 * donc tant qu'une vague place quelque chose. Ce qui reste après la dernière
 * vague n'a aucun chemin vers l'arbre existant, et {@link Arrivee.sansAncre} le
 * nomme.
 *
 * ## Ce qui disparaît disparaît
 *
 * Un nœud retiré de la charge — fiche supprimée, demande refusée, cercle de
 * visibilité qui s'est resserré — sort de la mise en page sans qu'on comble le
 * trou. Reboucher demanderait de déplacer ses voisins, c'est-à-dire de faire
 * exactement ce que ce module existe pour éviter.
 */
export function insererLocalement(affiche: Graphe, servi: Graphe): Arrivee {
  // **On se souvient de l'ordonnée autant que de l'abscisse.**
  //
  // Une fiche peut changer de RANGÉE d'une charge à l'autre — il suffit qu'on
  // lui donne des parents, et sa génération se décale. Lui rendre son ancienne
  // abscisse dans sa nouvelle rangée la poserait sur une place que personne ne
  // lui a réservée : elle chevaucherait sa voisine, et la séparation — la seule
  // garantie que ce module donne par construction — tomberait sans bruit.
  //
  // Une fiche qui a changé de rangée n'est donc plus « la même place » : elle
  // est traitée comme une arrivée, et replacée sous ses voisins.
  const connues = new Map(affiche.noeuds.map((noeud) => [noeud.id, { x: noeud.x, y: noeud.y }]))

  // L'abscisse de travail de chaque nœud : celle de l'écran si on la connaît,
  // celle de la disposition sinon — cette dernière ne servira que de secours.
  const x = new Map<IdentifiantNoeud, number>()
  const aPlacer: NoeudGraphe[] = []

  for (const noeud of servi.noeuds) {
    const ancienne = connues.get(noeud.id)
    if (ancienne === undefined || ancienne.y !== noeud.y) aPlacer.push(noeud)
    else x.set(noeud.id, ancienne.x)
  }

  const nouveaux = aPlacer.map((noeud) => noeud.id)
  const fichesNouvelles = aPlacer.filter((noeud) => noeud.type === 'personne').map((n) => n.id)

  if (aPlacer.length === 0) {
    return {
      graphe: grapheAuxAbscisses(servi, x),
      nouveaux,
      fichesNouvelles,
      deplacees: [],
      sansAncre: [],
      motif: null,
    }
  }

  const voisins = voisinage(servi.aretes)
  const parId = new Map(servi.noeuds.map((noeud) => [noeud.id, noeud]))
  const pousses = new Set<IdentifiantNoeud>()
  let motif: MotifDeReorganisation | null = null

  // Un ordre de traitement stable — du haut de l'arbre vers le bas, puis de
  // gauche à droite — pour que deux appels sur la même charge rendent la même
  // mise en page. Sans lui, l'ordre des nœuds dans la charge déciderait.
  const restants = [...aPlacer].sort(
    (a, b) => a.y - b.y || a.x - b.x || (a.id < b.id ? -1 : 1),
  )

  const glisser = (noeud: NoeudGraphe, abscisse: number): void => {
    const rangee: Place[] = []
    for (const [id, abs] of x) {
      const autre = parId.get(id)
      if (autre === undefined || autre.y !== noeud.y) continue
      rangee.push({ id, x: abs, w: autre.w })
    }
    rangee.sort((a, b) => a.x - b.x || (a.id < b.id ? -1 : 1))

    const pose = poser(rangee, { id: noeud.id, x: abscisse, w: noeud.w })

    for (const [id, valeur] of pose.poussee) {
      x.set(id, valeur)
      pousses.add(id)
    }
    x.set(noeud.id, pose.x)
  }

  let encore = true
  while (encore) {
    encore = false

    for (let i = restants.length - 1; i >= 0; i -= 1) {
      const noeud = restants[i]!
      const ancres = (voisins.get(noeud.id) ?? [])
        .map((id) => x.get(id))
        .filter((abscisse): abscisse is number => abscisse !== undefined)

      if (ancres.length === 0) continue

      // **La place logique : la moyenne des voisins déjà placés.** C'est la même
      // règle que le modèle local de la disposition — le minimum de l'objectif
      // pour cette seule abscisse, les autres tenues fixes. Aucune constante
      // n'est choisie ici non plus.
      glisser(noeud, ancres.reduce((somme, abscisse) => somme + abscisse, 0) / ancres.length)
      restants.splice(i, 1)
      encore = true
    }
  }

  const sansAncre = restants.map((noeud) => noeud.id)
  for (const noeud of restants) glisser(noeud, noeud.x)

  // **Le tronc se lit APRÈS coup, sur ce qui a effectivement bougé.** C'est un
  // constat ; un constat ne se devine pas au moment de pousser.
  //
  // Sur le tronc **qui était déjà là**, et lui seul : les nouveaux venus se
  // poussent aussi les uns les autres, et une branche greffée qui bouscule ses
  // propres fiches n'a encore rien dérangé de ce que le lecteur regardait. Sans
  // cette restriction, greffer quinze générations déclenchait la réorganisation
  // alors que pas une seule fiche de l'arbre n'avait bougé — mesuré le
  // 20/09/2026, et c'est la version qui a failli partir.
  const neufs = new Set(nouveaux)
  let combien = 0
  let ecart = 0
  let lecteur = false

  for (const id of pousses) {
    const ancienne = connues.get(id)
    if (ancienne === undefined || neufs.has(id)) continue
    const noeud = parId.get(id)
    if (noeud === undefined || !estDuTronc(noeud, servi.ego)) continue
    combien += 1
    ecart = Math.max(ecart, Math.abs((x.get(id) ?? noeud.x) - ancienne.x))
    if (id === servi.ego) lecteur = true
  }

  if (combien > 0) motif = { cas: 'tronc-pousse', combien, ecart, lecteur }

  const deplacees: IdentifiantNoeud[] = []
  for (const id of pousses) {
    if (!connues.has(id) || neufs.has(id)) continue
    if (parId.get(id)?.type !== 'personne') continue
    deplacees.push(id)
  }
  deplacees.sort()

  return {
    graphe: grapheAuxAbscisses(servi, x),
    nouveaux,
    fichesNouvelles,
    deplacees,
    sansAncre,
    motif,
  }
}

/**
 * La charge, aux abscisses données, boîte englobante recalculée.
 *
 * La boîte alimente le `viewBox` initial : la laisser telle quelle après avoir
 * déplacé des nœuds ouvrirait la vue sur un cadre qui ne contient plus tout
 * l'arbre — et le défaut ne se verrait qu'au bord, c'est-à-dire tard.
 */
function grapheAuxAbscisses(servi: Graphe, x: ReadonlyMap<IdentifiantNoeud, number>): Graphe {
  const noeuds = servi.noeuds.map((noeud) => {
    const abscisse = x.get(noeud.id)
    return abscisse === undefined || abscisse === noeud.x ? noeud : { ...noeud, x: abscisse }
  })

  return { ...servi, noeuds, boite: boiteEnglobante(noeuds, servi.boite) }
}

/**
 * **Ce que la vue appelle à chaque charge.**
 *
 * Elle n'a aucun cas particulier à écrire : au premier affichage il n'y a rien à
 * préserver, et `affiche` vaut `null` — la mise en page de la disposition est
 * alors rendue telle quelle. Ensuite, on insère ; et si l'insertion a cessé
 * d'être locale, **on rend la mise en page globale, avec le motif**, que l'écran
 * annoncera.
 *
 * C'est ici que se joue le partage que le commanditaire a demandé : *« par
 * défaut, insertion locale […] ; mais quand le déséquilibre dépasse un seuil,
 * une réorganisation globale se déclenche, et elle est annoncée plutôt que
 * subie »*.
 */
export function accueillir(affiche: Graphe | null, servi: Graphe): Arrivee {
  if (affiche === null) {
    return {
      graphe: servi,
      nouveaux: [],
      fichesNouvelles: [],
      deplacees: [],
      sansAncre: [],
      motif: null,
    }
  }

  const locale = insererLocalement(affiche, servi)
  if (locale.motif === null) return locale

  return {
    ...locale,
    graphe: servi,
    deplacees: fichesDeplacees(affiche, servi),
  }
}

/**
 * Les fiches déjà présentes dont l'abscisse change d'une mise en page à l'autre.
 *
 * Le seuil est un **demi-écart** entre fiches, soit dix pixels : en deçà, deux
 * captures sont superposables et le lecteur n'a rien perdu. Ce n'est pas un
 * réglage de la règle — la règle, c'est la condition de réorganisation — mais
 * l'unité dans laquelle on rend compte, et elle est dite plutôt que tue.
 */
export function fichesDeplacees(avant: Graphe, apres: Graphe): IdentifiantNoeud[] {
  const x = new Map(apres.noeuds.map((noeud) => [noeud.id, noeud.x]))
  const bougees: IdentifiantNoeud[] = []

  for (const noeud of avant.noeuds) {
    if (noeud.type !== 'personne') continue
    const nouvelle = x.get(noeud.id)
    if (nouvelle === undefined) continue
    if (Math.abs(nouvelle - noeud.x) > ESPACE / 2) bougees.push(noeud.id)
  }

  return bougees
}

/**
 * **Ce que le bouton « réorganiser » annonce avant qu'on le presse.**
 *
 * Il doit dire ce qu'il va faire : un bouton qui ne promet rien se presse une
 * fois, déplace deux cents fiches, et n'est plus jamais pressé. Le nombre est
 * donc **calculé sur les deux mises en page** — celle de l'écran et celle que la
 * disposition vient de produire — et non écrit dans une phrase, où il se
 * périmerait au premier correctif. C'est déjà arrivé sur le bandeau de maquette,
 * le 20/09/2026.
 */
export interface EffetAnnonce {
  deplacees: number
  fiches: number
}

export function effetDeLaReorganisation(affiche: Graphe, global: Graphe): EffetAnnonce {
  return {
    deplacees: fichesDeplacees(affiche, global).length,
    fiches: global.noeuds.filter((noeud) => noeud.type === 'personne').length,
  }
}

/* ------------------------------------------------------------------ */
/* Les phrases                                                         */
/* ------------------------------------------------------------------ */

/**
 * **Les phrases sont composées ici, pas dans le gabarit.**
 *
 * Une expression écrite dans un `<template>` n'est éprouvée par aucun test de ce
 * dépôt : elle se découvre à l'écran, et seulement quand elle est fausse. Un
 * accord au pluriel oublié sur un cas à une fiche, c'est précisément le genre de
 * chose qui ne se voit qu'une fois sur cent affichages.
 */
function pluriel(combien: number, singulier: string, plur: string): string {
  return `${combien} ${combien > 1 ? plur : singulier}`
}

/** L'annonce de la réorganisation : ce qui a bougé, et pourquoi. */
export function phraseDeReorganisation(motif: MotifDeReorganisation, deplacees: number): string {
  const cause = motif.lecteur
    ? 'la branche qui vient de grandir a poussé votre propre fiche'
    : `la branche qui vient de grandir a poussé votre lignée directe de ${Math.round(motif.ecart)} px`

  return `L’arbre a été réorganisé : ${cause}, et il ne tenait plus dans sa disposition d’avant. ${pluriel(deplacees, 'fiche a changé de place', 'fiches ont changé de place')}.`
}

/** L'annonce de l'insertion locale : ce qui est arrivé, et ce qui n'a pas bougé. */
export function phraseDArrivee(nouvelles: number, deplacees: number): string {
  const entrees = pluriel(nouvelles, 'nouvelle fiche', 'nouvelles fiches')

  return deplacees === 0
    ? `${entrees} dans l’arbre. Rien d’autre n’a bougé.`
    : `${entrees} dans l’arbre. ${pluriel(deplacees, 'fiche voisine s’est écartée', 'fiches voisines se sont écartées')}.`
}

/** Ce que l'écran a à dire, ou `null` quand il n'a rien à dire. */
export interface Annonce {
  /** `reorganisation` mérite qu'on s'arrête ; `arrivee` se lit en passant. */
  ton: 'arrivee' | 'reorganisation'
  texte: string
}

/**
 * **Ce que l'écran annonce après une charge — et quand il se tait.**
 *
 * Trois règles, et chacune a sa raison :
 *
 * 1. **Rien de neuf, rien à dire.** Un bandeau qui se lève à chaque
 *    revalidation devient un bandeau qu'on ne lit plus.
 * 2. **Une réorganisation qui n'a déplacé personne n'est pas une
 *    réorganisation.** Le cas existe et il est mesuré : sur l'arbre réel, les
 *    deux fiches dont l'arrivée pousse le tronc sont dans la rangée du lecteur,
 *    et la mise en page globale y remet **zéro** fiche ailleurs. Annoncer « 0
 *    fiche a changé de place » serait exact et inutile.
 * 3. **Sinon, on dit laquelle des deux choses s'est produite**, parce que ce ne
 *    sont pas les mêmes nouvelles : des voisines qui s'écartent, ou l'arbre qui
 *    se redispose.
 */
export function annonce(arrivee: Arrivee): Annonce | null {
  if (arrivee.fichesNouvelles.length === 0) return null

  if (arrivee.motif !== null && arrivee.deplacees.length > 0) {
    return {
      ton: 'reorganisation',
      texte: phraseDeReorganisation(arrivee.motif, arrivee.deplacees.length),
    }
  }

  return {
    ton: 'arrivee',
    texte: phraseDArrivee(arrivee.fichesNouvelles.length, arrivee.deplacees.length),
  }
}

/** Ce que le bouton « réorganiser » écrit sous son propre libellé. */
export function phraseDuBouton(effet: EffetAnnonce): string {
  return effet.deplacees === 0
    ? 'Rien à replacer : l’arbre est déjà dans sa meilleure position.'
    : `Replacera ${pluriel(effet.deplacees, 'fiche', 'fiches')} sur ${effet.fiches}.`
}
