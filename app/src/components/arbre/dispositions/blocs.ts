/**
 * **Blocs rigides** — la descendance comme un arbre enraciné, posée de bas en haut.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QUE CETTE MAQUETTE CHANGE, ET POURQUOI ELLE EXISTE.**
 *
 * Toutes les autres dispositions placent **rangée par rangée** : elles décident
 * d'un ordre, puis écartent les chevauchements horizontalement. Un sous-arbre n'y
 * est jamais une unité — ses membres sont répartis sur dix rangées, et rien
 * n'empêche les descendants d'une branche de se glisser sous les ancêtres d'une
 * autre. C'est exactement ce qui produit les croisements que le commanditaire
 * voit : deux familles qui se traversent.
 *
 * Ici, **un sous-arbre est un bloc, et un bloc ne se traverse pas**. Chaque bloc
 * réserve sa largeur maximale sur TOUS ses étages, et les blocs frères sont posés
 * côte à côte. Un croisement entre deux branches devient alors **irreprésentable**
 * plutôt qu'empêché : ce n'est pas une règle de plus, c'est une géométrie où le
 * défaut n'a pas de place.
 *
 * C'est la méthode de Reingold et Tilford, dans sa forme **sans contour**.
 *
 * ── CE QUE LA MESURE A DÉMENTI, ET IL FAUT LE LIRE AVANT D'UTILISER CE MODULE ──
 *
 * La maquette a été décidée sur deux chiffres. **Les deux étaient faux, et c'est
 * elle qui l'a montré** — mesuré le 20/09/2026 sur l'arbre réel, 644 nœuds :
 *
 * | | annoncé avant | mesuré ici |
 * |---|---|---|
 * | largeur | 29 033 px, soit ×1,08 | **51 448 px, soit ×1,92** |
 * | croisements | rendus impossibles | **80, contre 79 au serveur** |
 *
 * **La largeur annoncée venait d'une formule qui laissait les blocs se
 * chevaucher.** Elle prenait `max(Σ des étages, Σ des sous-blocs)` là où des
 * couloirs disjoints exigent `Σ des max`. Preuve chiffrée : la somme, racine par
 * racine, de l'étage le plus large de chaque bloc vaut **39 217 px**, et c'est un
 * plancher — aucun agencement côte à côte ne peut faire moins. Les 29 033 px
 * annoncés étaient **sous ce plancher** : ils ne mesuraient pas le même objet.
 *
 * **Les croisements ne viennent pas de la géométrie.** Mesuré en retirant les
 * arêtes en cause : sur les 79 croisements du serveur, **71 impliquent l'une des
 * 61 filiations d'un conjoint absorbé**. Sans elles, le serveur en a 8 et les
 * blocs 5. Autrement dit : l'arbre du commanditaire est **très marié en
 * dedans** — 61 couples dont les deux époux sont enfants de l'arbre —, et chacun
 * force l'un des deux à quitter sa fratrie en tirant derrière lui un trait qui
 * traverse tout. **Les blocs rigides ne peuvent rien contre ces 71-là**, quelle
 * que soit la largeur qu'on leur donne.
 *
 * Ce module reste ce qu'il promet : l'ordre y est **construit** et non obtenu, un
 * enfant tombe sous ses parents, et deux branches ne se traversent pas. Mais il
 * n'achète pas les croisements que le commanditaire voit, et il coûte **×1,92 en
 * largeur, ×3,25 sur le trait le plus long** (5 879 → 19 103 px). La disposition
 * qui vise ces 71 croisements est {@link duplication}, qui dessine le conjoint
 * deux fois au lieu de tirer le trait.
 *
 * **PAS DE CONTOUR, NI EN V1 NI APRÈS.** Mesuré avant de décider : l'imbrication
 * par contour récupère **491 px sur 2 173 d'excès** au sens de l'ancienne formule.
 * Le contour ne visait que la largeur, et la largeur n'est plus une contrainte du
 * commanditaire : *« la largeur n'est pas un souci, d'ici 100 ans l'arbre aura
 * grandi énormément »*.
 *
 * **CE QUI SE PAIE VRAIMENT ICI, C'EST LE TRAIT.** Quand deux personnes de blocs
 * éloignés s'unissent, la maquette garde le couple accolé — c'est la mesure qui
 * mord depuis le début — et **paie en longueur de filiation** : le conjoint
 * absorbé rejoint le bloc de l'autre, avec toute sa descendance, et le trait vers
 * SES parents traverse l'arbre. Un trait par union inter-blocs, jamais deux.
 * Voir {@link unionsInterBlocs}, qui les compte et les mesure : c'est le nombre à
 * regarder quand le commanditaire annonce que ses unions inter-branches vont se
 * multiplier. Elles sont **déjà 61**.
 * ────────────────────────────────────────────────────────────────────
 *
 * **L'ORDRE N'EST PAS RECALCULÉ, IL EST REÇU.** La charge du client ne porte
 * **aucune année de naissance** : `etiquetteDates` vaut « vivant·e » pour les
 * vivants, et 71 fiches sur 187 n'ont aucune année lisible. Reconstituer une clé
 * de naissance ici produirait un ordre faux pour un tiers des fratries — et ce
 * serait un SECOND arbitrage, concurrent de celui du serveur. On reprend donc
 * l'ordre des abscisses servies, qui porte déjà le tri du serveur, correctif I8
 * compris (`AffinageDuPlacement::cleDeNaissance` lit la personne de la lignée et
 * non la tête du bloc, depuis le 20/09/2026).
 *
 * **L'ARBITRAGE DU CONJOINT ENTRANT EST CELUI DU SERVEUR**, réénoncé et non
 * réinventé : quand les deux époux sont chacun enfant d'une famille de l'arbre,
 * le couple rejoint **la plus nombreuse des deux fratries**
 * (`PlacementLisibleTest::testLeCoupleRejointLaPlusGrandeDesDeuxFratries`). La
 * méthode PHP qui le décide est privée et hors d'atteinte d'ici ; ce qui compte
 * est qu'il n'existe qu'une règle, et que ce soit celle-là.
 */

import type { AreteGraphe, Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
import { ecarterVersLaDroite, ESPACE, grapheReplace, type OptionsDeDisposition } from './socle'

/* ------------------------------------------------------------------ */
/* La topologie dont les blocs ont besoin                              */
/* ------------------------------------------------------------------ */

export interface TopologieDesBlocs {
  /** Les époux de chaque losange. */
  epoux: Map<IdentifiantNoeud, IdentifiantNoeud[]>
  /** Les enfants de chaque losange, dans l'ordre des abscisses reçues. */
  enfants: Map<IdentifiantNoeud, IdentifiantNoeud[]>
  /** Le losange dont une personne est l'enfant — au plus un, par construction. */
  familleDesParents: Map<IdentifiantNoeud, IdentifiantNoeud>
  /** Les losanges dont une personne est un époux. */
  famillesDEpoux: Map<IdentifiantNoeud, IdentifiantNoeud[]>
}

export function topologie(graphe: Graphe): TopologieDesBlocs {
  const x = new Map(graphe.noeuds.map((n) => [n.id, n.x]))
  const epoux = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()
  const enfants = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()
  const familleDesParents = new Map<IdentifiantNoeud, IdentifiantNoeud>()
  const famillesDEpoux = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()

  for (const arete of graphe.aretes) {
    if (arete.genre === 'union') {
      epoux.set(arete.vers, [...(epoux.get(arete.vers) ?? []), arete.de])
      famillesDEpoux.set(arete.de, [...(famillesDEpoux.get(arete.de) ?? []), arete.vers])
    } else {
      enfants.set(arete.de, [...(enfants.get(arete.de) ?? []), arete.vers])
      familleDesParents.set(arete.vers, arete.de)
    }
  }

  const parAbscisse = (a: IdentifiantNoeud, b: IdentifiantNoeud): number =>
    (x.get(a) ?? 0) - (x.get(b) ?? 0) || (a < b ? -1 : 1)

  for (const liste of enfants.values()) liste.sort(parAbscisse)
  for (const liste of epoux.values()) liste.sort(parAbscisse)

  return { epoux, enfants, familleDesParents, famillesDEpoux }
}

/* ------------------------------------------------------------------ */
/* Les groupes : qui reste accolé à qui                                */
/* ------------------------------------------------------------------ */

/**
 * Un **groupe** est ce qui se dessine d'un seul tenant sur une rangée : une
 * personne, son conjoint, et le second conjoint d'un remariage.
 *
 * C'est l'unité du placement, et non la personne : *« les conjoints restent
 * accolés »* est la contrainte qui mord depuis le début du chantier, et la seule
 * façon de la garantir par construction est de ne jamais offrir au solveur la
 * possibilité de les séparer.
 *
 * @property membres  dans l'ordre de gauche à droite
 * @property ancre    le membre dont la famille de parents porte le groupe ;
 *                    `null` pour un groupe racine
 */
export interface GroupeAccole {
  membres: IdentifiantNoeud[]
  ancre: IdentifiantNoeud | null
}

/**
 * **Quel époux quitte sa fratrie quand les deux en ont une.**
 *
 * La règle du serveur, réénoncée : le couple rejoint la plus nombreuse. Celui qui
 * la quitte est dit *absorbé* — il sera dessiné à côté de son conjoint, avec sa
 * descendance, et son trait de filiation s'allongera d'autant.
 *
 * À égalité, l'abscisse reçue tranche, pour que la sortie ne dépende pas de
 * l'ordre d'itération d'une `Map`.
 */
export function absorbes(topo: TopologieDesBlocs, x: ReadonlyMap<IdentifiantNoeud, number>): Set<IdentifiantNoeud> {
  const absorbe = new Set<IdentifiantNoeud>()

  const familles = [...topo.epoux.keys()].sort((a, b) => (a < b ? -1 : 1))

  for (const famille of familles) {
    const membres = topo.epoux.get(famille) ?? []
    if (membres.length !== 2) continue

    const [a, b] = membres as [IdentifiantNoeud, IdentifiantNoeud]
    const fa = topo.familleDesParents.get(a)
    const fb = topo.familleDesParents.get(b)
    if (fa === undefined || fb === undefined) continue

    const ta = (topo.enfants.get(fa) ?? []).length
    const tb = (topo.enfants.get(fb) ?? []).length

    if (ta !== tb) absorbe.add(ta > tb ? b : a)
    else absorbe.add((x.get(a) ?? 0) <= (x.get(b) ?? 0) ? b : a)
  }

  return absorbe
}

/**
 * Les groupes, et l'appartenance de chaque personne au sien.
 *
 * Deux époux sont dans le même groupe — toujours. Un remarié tire donc ses deux
 * conjoints dans le sien, ce qui donne un groupe de trois : c'est voulu, et c'est
 * ce qui empêche qu'un remariage sépare le premier couple pour coller le second.
 *
 * **Une seule ancre par groupe.** Si plusieurs membres gardaient une famille de
 * parents, le groupe devrait pendre sous deux endroits à la fois. On garde celui
 * dont la fratrie est la plus nombreuse — la même règle que ci-dessus, appliquée
 * une seconde fois au cas rare du remariage entre deux enfants de l'arbre — et
 * les autres sont absorbés à leur tour.
 */
export function groupes(
  noeuds: readonly NoeudGraphe[],
  topo: TopologieDesBlocs,
  absorbe: Set<IdentifiantNoeud>,
): { groupes: GroupeAccole[]; groupeDe: Map<IdentifiantNoeud, number> } {
  const x = new Map(noeuds.map((n) => [n.id, n.x]))
  const personnes = noeuds.filter((n) => n.type === 'personne').map((n) => n.id)
  const rang = new Map(personnes.map((id, i) => [id, i]))

  // Union-find, sur les liens d'union : deux époux finissent dans le même paquet.
  const parent = new Map<IdentifiantNoeud, IdentifiantNoeud>(personnes.map((id) => [id, id]))
  const racine = (id: IdentifiantNoeud): IdentifiantNoeud => {
    let courant = id
    while (parent.get(courant) !== courant) courant = parent.get(courant) ?? courant
    return courant
  }
  const unir = (a: IdentifiantNoeud, b: IdentifiantNoeud): void => {
    const ra = racine(a)
    const rb = racine(b)
    if (ra === rb) return
    // Le plus ancien dans l'ordre reçu porte le paquet : la sortie ne dépend
    // d'aucun ordre d'itération.
    if ((rang.get(ra) ?? 0) <= (rang.get(rb) ?? 0)) parent.set(rb, ra)
    else parent.set(ra, rb)
  }

  for (const [, membres] of topo.epoux) {
    if (membres.length < 2) continue
    for (let i = 1; i < membres.length; i += 1) unir(membres[0]!, membres[i]!)
  }

  const paquets = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()
  for (const id of personnes) {
    const r = racine(id)
    paquets.set(r, [...(paquets.get(r) ?? []), id])
  }

  const liste: GroupeAccole[] = []
  const groupeDe = new Map<IdentifiantNoeud, number>()

  for (const membres of paquets.values()) {
    const ordonnes = [...membres].sort((a, b) => (x.get(a) ?? 0) - (x.get(b) ?? 0) || (a < b ? -1 : 1))

    let ancres = ordonnes.filter((id) => !absorbe.has(id) && topo.familleDesParents.has(id))

    // Cas rare : deux membres gardent une famille de parents (remariage entre
    // deux enfants de l'arbre). On tranche par la taille de la fratrie, et on
    // absorbe les autres — sinon le groupe pendrait sous deux endroits.
    if (ancres.length > 1) {
      const taille = (id: IdentifiantNoeud): number =>
        (topo.enfants.get(topo.familleDesParents.get(id) as IdentifiantNoeud) ?? []).length
      const gardee = ancres.reduce((a, b) => (taille(b) > taille(a) ? b : a))
      for (const id of ancres) if (id !== gardee) absorbe.add(id)
      ancres = [gardee]
    }

    const indice = liste.length
    liste.push({ membres: ordonnes, ancre: ancres[0] ?? null })
    for (const id of ordonnes) groupeDe.set(id, indice)
  }

  return { groupes: liste, groupeDe }
}

/* ------------------------------------------------------------------ */
/* L'arbre des blocs                                                   */
/* ------------------------------------------------------------------ */

/**
 * Les groupes-enfants d'un groupe : les enfants de toutes ses familles, chacun
 * ramené au groupe qui le porte, sans doublon et dans l'ordre reçu.
 *
 * Un enfant absorbé n'y figure pas : il pend sous le bloc de son conjoint, et
 * c'est précisément ce que l'absorption veut dire.
 */
export function blocsEnfants(
  indice: number,
  contexte: {
    groupes: GroupeAccole[]
    groupeDe: Map<IdentifiantNoeud, number>
    topo: TopologieDesBlocs
    absorbe: Set<IdentifiantNoeud>
  },
): number[] {
  const groupe = contexte.groupes[indice]
  if (groupe === undefined) return []

  const vus = new Set<number>()
  const sortie: number[] = []

  for (const membre of groupe.membres) {
    for (const famille of contexte.topo.famillesDEpoux.get(membre) ?? []) {
      for (const enfant of contexte.topo.enfants.get(famille) ?? []) {
        if (contexte.absorbe.has(enfant)) continue

        const cible = contexte.groupeDe.get(enfant)
        // Un groupe n'est l'enfant de celui-ci que si son ANCRE est cet enfant :
        // sans ce contrôle, un conjoint entrant ferait pendre son groupe deux fois.
        if (cible === undefined || vus.has(cible)) continue
        if (contexte.groupes[cible]?.ancre !== enfant) continue

        vus.add(cible)
        sortie.push(cible)
      }
    }
  }

  return sortie
}

/** La largeur d'un groupe posé seul : ses membres accolés, marge comprise. */
export function largeurPropre(membres: readonly IdentifiantNoeud[], w: ReadonlyMap<IdentifiantNoeud, number>): number {
  if (membres.length === 0) return 0

  return membres.reduce((somme, id) => somme + (w.get(id) ?? 0), 0) + (membres.length - 1) * ESPACE
}

/* ------------------------------------------------------------------ */
/* Le placement                                                        */
/* ------------------------------------------------------------------ */

interface Atelier {
  groupes: GroupeAccole[]
  groupeDe: Map<IdentifiantNoeud, number>
  topo: TopologieDesBlocs
  absorbe: Set<IdentifiantNoeud>
  w: Map<IdentifiantNoeud, number>
  enfantsDe: Map<number, number[]>
  largeurs: Map<number, number>
  /** Le couloir de chaque bloc, quand l'appelant veut les vérifier. */
  couloirs: Map<number, { gauche: number; largeur: number }> | undefined
}

/**
 * **La largeur d'un bloc, et c'est là que « rigide » se décide.**
 *
 * `max(sa propre rangée, la somme de ses blocs-enfants)` — et non la somme seule.
 * Un bloc large en haut réserve cette largeur sur toute sa hauteur : aucun voisin
 * ne peut se glisser dessous, donc aucune branche ne peut en traverser une autre.
 * C'est cette ligne qui coûte les 32 %, et c'est la même qui rend les croisements
 * entre branches impossibles.
 *
 * La pile coupe les cycles. Le graphe n'en porte pas — une personne a au plus une
 * famille de parents —, mais une charge abîmée ne doit pas faire tourner le
 * navigateur sans fin.
 */
export function largeurDuBloc(indice: number, atelier: Atelier, pile: Set<number> = new Set()): number {
  const connue = atelier.largeurs.get(indice)
  if (connue !== undefined) return connue
  if (pile.has(indice)) return 0

  pile.add(indice)

  const groupe = atelier.groupes[indice]
  const propre = largeurPropre(groupe?.membres ?? [], atelier.w)
  const enfants = atelier.enfantsDe.get(indice) ?? []
  const dessous = enfants.reduce(
    (somme, enfant, i) => somme + largeurDuBloc(enfant, atelier, pile) + (i > 0 ? ESPACE : 0),
    0,
  )

  pile.delete(indice)

  const largeur = Math.max(propre, dessous)
  atelier.largeurs.set(indice, largeur)

  return largeur
}

/**
 * Pose un bloc dans le couloir `[gauche, gauche + largeur]`, puis ses enfants.
 *
 * Les deux étages sont **centrés dans le couloir**, ce qui donne les deux
 * propriétés qu'on attend à l'œil : un enfant unique tombe exactement sous le
 * losange de ses parents, et une fratrie s'équilibre sous eux.
 */
function poser(
  indice: number,
  gauche: number,
  atelier: Atelier,
  x: Map<IdentifiantNoeud, number>,
  pile: Set<number> = new Set(),
): void {
  if (pile.has(indice)) return
  pile.add(indice)

  atelier.couloirs?.set(indice, { gauche, largeur: largeurDuBloc(indice, atelier) })

  const groupe = atelier.groupes[indice]
  const largeur = largeurDuBloc(indice, atelier)

  if (groupe !== undefined) {
    const propre = largeurPropre(groupe.membres, atelier.w)
    let curseur = gauche + (largeur - propre) / 2
    for (const membre of groupe.membres) {
      const l = atelier.w.get(membre) ?? 0
      x.set(membre, curseur + l / 2)
      curseur += l + ESPACE
    }
  }

  const enfants = atelier.enfantsDe.get(indice) ?? []
  if (enfants.length > 0) {
    const dessous = enfants.reduce(
      (somme, enfant, i) => somme + largeurDuBloc(enfant, atelier) + (i > 0 ? ESPACE : 0),
      0,
    )
    let curseur = gauche + (largeur - dessous) / 2
    for (const enfant of enfants) {
      poser(enfant, curseur, atelier, x, pile)
      curseur += largeurDuBloc(enfant, atelier) + ESPACE
    }
  }

  pile.delete(indice)
}

/* ------------------------------------------------------------------ */
/* Les mesures que cette maquette doit rendre                          */
/* ------------------------------------------------------------------ */

/**
 * **Les unions inter-blocs, et ce qu'elles coûtent.**
 *
 * C'est le nombre à regarder pour juger l'avenir de cette maquette : le
 * commanditaire annonce que ses unions entre branches vont se multiplier, et
 * c'est exactement le cas où des blocs rigides se paient — non pas en largeur,
 * mais en longueur de trait.
 *
 * Une union est dite inter-blocs quand un époux a été **absorbé** : il a quitté sa
 * fratrie pour rester accolé à son conjoint, et son trait de filiation relie
 * désormais deux blocs. `trait` mesure ce trait après placement.
 */
export function unionsInterBlocs(
  graphe: Graphe,
  topo: TopologieDesBlocs,
  absorbe: ReadonlySet<IdentifiantNoeud>,
): { personne: IdentifiantNoeud; famille: IdentifiantNoeud; trait: number }[] {
  const x = new Map(graphe.noeuds.map((n) => [n.id, n.x]))
  const sortie: { personne: IdentifiantNoeud; famille: IdentifiantNoeud; trait: number }[] = []

  for (const personne of absorbe) {
    const famille = topo.familleDesParents.get(personne)
    if (famille === undefined) continue

    sortie.push({
      personne,
      famille,
      trait: Math.abs((x.get(personne) ?? 0) - (x.get(famille) ?? 0)),
    })
  }

  return sortie.sort((a, b) => b.trait - a.trait)
}

/* ------------------------------------------------------------------ */
/* La disposition                                                      */
/* ------------------------------------------------------------------ */

/**
 * **Les racines**, dans l'ordre reçu : les blocs qui ne pendent sous personne.
 *
 * Un groupe sans ancre n'a aucun parent dans l'arbre — ni l'un ni l'autre des
 * époux. C'est le sommet d'une lignée, et il n'y en a qu'une poignée.
 */
export function racines(
  liste: readonly GroupeAccole[],
  x: ReadonlyMap<IdentifiantNoeud, number>,
): number[] {
  return liste
    .map((groupe, indice) => ({ groupe, indice }))
    .filter(({ groupe }) => groupe.ancre === null)
    .sort((a, b) => (x.get(a.groupe.membres[0] ?? '') ?? 0) - (x.get(b.groupe.membres[0] ?? '') ?? 0))
    .map(({ indice }) => indice)
}

/**
 * La disposition : mêmes ordonnées, mêmes données, abscisses reconstruites.
 *
 * Les personnes qu'aucun bloc n'atteint — il ne devrait y en avoir aucune, mais
 * une charge est une charge — **gardent leur abscisse reçue** plutôt que d'être
 * empilées sur zéro. Un défaut de couverture doit se voir comme un décalage, pas
 * comme un tas au milieu de l'écran.
 */
/**
 * **Les abscisses que les blocs rendent**, avant tout recadrage.
 *
 * Isolée de {@link blocsRigides} parce que deux appelants en ont besoin — la
 * disposition et la mesure de couverture — et qu'une seconde copie du pipeline
 * dériverait de la première au premier correctif.
 */
export function abscissesDesBlocs(
  graphe: Graphe,
  couloirs?: Map<number, { gauche: number; largeur: number }>,
): Map<IdentifiantNoeud, number> {
  const topo = topologie(graphe)
  const xRecu = new Map(graphe.noeuds.map((n) => [n.id, n.x]))
  const absorbe = absorbes(topo, xRecu)
  const { groupes: liste, groupeDe } = groupes(graphe.noeuds, topo, absorbe)

  const atelier: Atelier = {
    groupes: liste,
    groupeDe,
    topo,
    absorbe,
    w: new Map(graphe.noeuds.map((n) => [n.id, n.w])),
    enfantsDe: new Map(),
    largeurs: new Map(),
    couloirs,
  }
  for (let i = 0; i < liste.length; i += 1) {
    atelier.enfantsDe.set(i, blocsEnfants(i, { groupes: liste, groupeDe, topo, absorbe }))
  }

  const x = new Map<IdentifiantNoeud, number>()
  let curseur = 0
  for (const indice of racines(liste, xRecu)) {
    poser(indice, curseur, atelier, x)
    curseur += largeurDuBloc(indice, atelier) + ESPACE
  }

  return x
}

/**
 * La disposition : mêmes ordonnées, mêmes données, abscisses reconstruites.
 *
 * Les personnes qu'aucun bloc n'atteint — il ne devrait y en avoir aucune, mais
 * une charge est une charge — **gardent leur abscisse reçue** plutôt que d'être
 * empilées sur zéro. Un défaut de couverture doit se voir comme un décalage, pas
 * comme un tas au milieu de l'écran.
 */
export function blocsRigides(graphe: Graphe, _options: OptionsDeDisposition): Graphe {
  if (graphe.noeuds.length === 0) return graphe

  const x = degagerLesRangees(graphe, abscissesDesBlocs(graphe))

  // Le lecteur reste à l'origine, comme dans toutes les autres dispositions :
  // deux maquettes qui recadrent différemment ne se comparent pas à l'écran.
  const origine = x.get(graphe.ego) ?? 0
  const abscisses = new Map<IdentifiantNoeud, number>()
  for (const [id, valeur] of x) abscisses.set(id, valeur - origine)

  return grapheReplace(graphe, abscisses)
}

/**
 * **La garde de fin, et ce qu'elle rattrape.**
 *
 * Les couloirs ne se chevauchent jamais, et chaque fiche reste dans le sien :
 * c'est vérifié sur l'arbre réel, 293 blocs sur 293. La non-superposition serait
 * donc acquise **si les ordonnées descendaient strictement de parent à enfant.**
 *
 * Elles ne le font pas toujours, et c'est de la vraie généalogie, pas une charge
 * abîmée. Mesuré le 20/09/2026 sur l'arbre du commanditaire : **trois couples dont
 * les deux époux ne sont pas sur la même rangée** — l'un d'eux à trois générations
 * d'écart — et **un enfant posé sur la rangée de son père**. Six fiches finissent
 * alors l'une sur l'autre, jusqu'à 194 px, parce qu'un bloc descendant retombe sur
 * l'ordonnée d'un de ses ancêtres.
 *
 * On applique donc, en dernier, la passe du moteur : **pousser vers la droite sans
 * jamais réordonner** (`CalculDePlacement::decrocheLesChevauchements`, recopiée
 * dans le socle). Elle ne peut ni séparer un couple — rien ne s'insère — ni
 * défaire l'ordre des blocs ; elle ne fait qu'ouvrir l'écart là où deux rangées se
 * télescopent. Sur l'arbre réel elle déplace **six fiches**.
 *
 * Le contraire — traiter ces mariages inter-générations dans la géométrie
 * elle-même — demanderait qu'un bloc réserve sa largeur sur toutes les rangées
 * qu'il traverse, et non sur les siennes. C'est un autre modèle ; celui-ci est
 * une maquette, et elle doit d'abord se regarder.
 */
export function degagerLesRangees(
  graphe: Graphe,
  x: Map<IdentifiantNoeud, number>,
): Map<IdentifiantNoeud, number> {
  const rangees = new Map<number, NoeudGraphe[]>()

  for (const noeud of graphe.noeuds) {
    if (noeud.type !== 'personne') continue
    const place = x.get(noeud.id)
    const pose = place === undefined ? noeud : { ...noeud, x: place }
    rangees.set(noeud.y, [...(rangees.get(noeud.y) ?? []), pose])
  }

  const degage = new Map(x)

  for (const rangee of rangees.values()) {
    const triee = [...rangee].sort((a, b) => a.x - b.x || (a.id < b.id ? -1 : 1))
    for (const [id, valeur] of ecarterVersLaDroite(triee)) degage.set(id, valeur)
  }

  return degage
}

/** Les personnes qu'aucun bloc n'a atteintes — zéro attendu, mesuré par le test. */
export function personnesNonPlacees(graphe: Graphe): IdentifiantNoeud[] {
  const x = abscissesDesBlocs(graphe)

  return graphe.noeuds.filter((n) => n.type === 'personne' && !x.has(n.id)).map((n) => n.id)
}

/** Les arêtes, telles que la maquette les laisse — utilitaire de mesure. */
export function traitLePlusLong(graphe: Graphe, aretes: readonly AreteGraphe[] = graphe.aretes): number {
  const x = new Map(graphe.noeuds.map((n) => [n.id, n.x]))
  let maximum = 0

  for (const arete of aretes) {
    const de = x.get(arete.de)
    const vers = x.get(arete.vers)
    if (de === undefined || vers === undefined) continue
    maximum = Math.max(maximum, Math.abs(de - vers))
  }

  return maximum
}
