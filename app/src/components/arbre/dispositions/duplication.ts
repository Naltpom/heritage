/**
 * **Disposition « duplication »** — `/arbre-5`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **LA RÉPONSE DES ÉVENTAILS D'ASCENDANCE, ET DE PRESQUE TOUS LES LOGICIELS.**
 *
 * Une personne occupe deux places à la fois sur sa rangée : comme enfant, sous le
 * losange de ses parents, au milieu de sa fratrie ; comme conjoint, collée à son
 * partenaire. En une dimension, les deux ne tiennent que si les groupes sont
 * déjà voisins — et ils ne le sont pas toujours.
 *
 * Les deux premières dispositions choisissent l'une des deux places. Celle-ci
 * refuse de choisir : **elle dessine la personne deux fois.** Une fois pleine, à
 * sa place principale ; une fois en **écho**, grisé et tireté, à l'endroit d'où le
 * trait serait parti. Le trait long disparaît, remplacé par un doublon assumé.
 *
 * C'est ce que font les éventails d'ascendance depuis toujours, parce qu'ils n'ont
 * pas le choix : dès qu'un ancêtre revient par deux chemins — un implexe, très
 * fréquent sur les vieilles familles —, il faut ou bien le dupliquer, ou bien
 * renoncer à la forme en éventail.
 *
 * ── Ce qu'on gagne, ce qu'on perd ───────────────────────────────────────────
 *
 * On gagne : plus aucun trait ne traverse une rangée, et l'on peut **tout**
 * résoudre — les 36 couples, pas 15.
 *
 * On perd : le compte des fiches ne veut plus rien dire à l'œil, et cliquer sur un
 * écho doit mener à la vraie fiche sans faire croire qu'il y a deux personnes.
 * L'écho porte donc le **même identifiant de personne** que son original, et un
 * suffixe d'identifiant de nœud pour que le rendu sache les distinguer.
 *
 * ── CE QU'ELLE DUPLIQUE — ET CE QU'ELLE A CESSÉ DE DUPLIQUER LE 20/09/2026 ──
 *
 * **Avant**, elle dupliquait le **conjoint rapporté** d'un couple séparé — celui
 * qui n'a aucun parent dans l'arbre —, et refusait le cas où les deux époux ont
 * des parents, au motif qu'un tel écho porterait deux traits.
 *
 * **Deux mesures ont renversé les deux choix.**
 *
 * **1. Le rapporté ne se duplique plus : il se déplace.** Son exemplaire d'origine
 * n'avait **aucune arête** — pas de parents, et l'union partie à l'écho. Le dessin
 * montrait une carte pleine reliée à rien à côté d'une carte pâle reliée au
 * conjoint, et la plus visible était l'orpheline. Un exemplaire qui ne porte aucun
 * trait ne dit rien : on ne le laisse pas.
 *
 * **2. Le cas refusé était le seul qui comptait.** Sur l'arbre réel, **61 unions**
 * ont leurs deux époux enfants de l'arbre, et leurs filiations portent **71 des 79
 * croisements** — mesuré en les retirant. L'ancienne règle en couvrait **zéro**.
 *
 * Et la raison du refus ne tient pas pour la construction retenue : **l'original
 * reste collé à son conjoint avec l'union, et l'écho se pose sous le losange de
 * ses parents avec la filiation.** Chaque exemplaire porte exactement **un** trait,
 * et les deux sont courts. Ce qui portait deux traits, c'était l'autre répartition.
 *
 * **Ce n'est pas un couple séparé qu'on répare ici, c'est une fratrie quittée** —
 * le moteur garde le couple accolé et c'est la filiation qui traverse l'arbre.
 * La condition de déclenchement le dit : on duplique celui que l'arbitrage
 * désigne **et qui ne touche plus aucun des siens**. Une condition, pas un seuil.
 *
 * ── Ce que ça rend, mesuré sur 644 nœuds le 20/09/2026 ──────────────────────
 *
 * | | serveur | duplication étendue |
 * |---|---|---|
 * | croisements | 79 | **28** |
 * | somme des carrés | 129,8 M | **118,5 M** |
 * | trait le plus long | 5 879 | **4 076** |
 * | largeur | 26 860 | 27 262 |
 * | couples séparés | 7 | 24, **dont 19 par des échos seuls** |
 *
 * 59 échos posés. Le prix est réel et il est dans la dernière ligne : une carte
 * pâle qui s'assoit entre deux époux les sépare à l'œil comme une autre.
 * ────────────────────────────────────────────────────────────────────
 */

import type { AreteGraphe, Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
// **L'arbitrage du conjoint entrant n'est écrit qu'une fois.** `absorbes()` dit
// quel époux quitte sa fratrie quand les deux en ont une — la règle du serveur,
// réénoncée là-bas. La recopier ici en ferait une seconde, qui dériverait.
import { absorbes, topologie } from './blocs'
import {
  boiteEnglobante,
  ecarterVersLaDroite,
  epouxParFamille,
  ESPACE,
  personnesAvecParents,
  rangeesDePersonnes,
  type OptionsDeDisposition,
} from './socle'

/** Ce qui marque un écho dans l'identifiant de nœud. */
export const SUFFIXE_ECHO = '#echo'

export function estUnEcho(id: IdentifiantNoeud): boolean {
  return id.endsWith(SUFFIXE_ECHO)
}

/**
 * L'identifiant de l'original derrière un écho.
 *
 * **Le suffixe est la seule marque**, et c'est délibéré : ajouter un champ
 * `echoDe` au type du contrat ferait porter à l'API une notion qu'elle n'émet
 * jamais. Un écho reste un nœud ordinaire pour tout le reste du rendu, et son
 * `personId` est celui de l'original — cliquer dessus mène à la bonne fiche,
 * sans laisser croire qu'il y a deux personnes.
 */
export function originalDeLEcho(id: IdentifiantNoeud): IdentifiantNoeud {
  return estUnEcho(id) ? (id.slice(0, -SUFFIXE_ECHO.length) as IdentifiantNoeud) : id
}

export function duplication(graphe: Graphe, _options: OptionsDeDisposition): Graphe {
  const epoux = epouxParFamille(graphe.aretes)
  const avecParents = personnesAvecParents(graphe.aretes)
  const rangees = rangeesDePersonnes(graphe.noeuds)

  const parId = new Map(graphe.noeuds.map((noeud) => [noeud.id, noeud]))
  const indexDansSaRangee = new Map<IdentifiantNoeud, { rangee: NoeudGraphe[]; index: number }>()

  for (const rangee of rangees.values()) {
    rangee.forEach((noeud, index) => indexDansSaRangee.set(noeud.id, { rangee, index }))
  }

  // L'arbitrage du serveur, emprunté et non recopié : qui quitte sa fratrie.
  const topo = topologie(graphe)
  const absorbe = absorbes(topo, new Map(graphe.noeuds.map((n) => [n.id, n.x])))
  const enfantsDe = topo.enfants
  const familleDesParents = topo.familleDesParents

  const echos: {
    original: NoeudGraphe
    ancre: NoeudGraphe
    famille: IdentifiantNoeud
    porte: 'union' | 'filiation'
  }[] = []
  const deplaces: { personne: NoeudGraphe; ancre: NoeudGraphe }[] = []

  for (const membres of epoux.values()) {
    if (membres.length !== 2) continue

    const a = indexDansSaRangee.get(membres[0]!)
    const b = indexDansSaRangee.get(membres[1]!)

    if (a === undefined || b === undefined || a.rangee !== b.rangee) continue
    if (Math.abs(a.index - b.index) === 1) continue

    // **Le conjoint rapporté ne se duplique pas : il se déplace.**
    //
    // Mesuré le 20/09/2026 : les six originaux laissés derrière par la version
    // précédente n'avaient **aucune arête** — pas de parents, et l'union partie à
    // l'écho. Le dessin montrait donc une carte pleine reliée à rien, à côté d'une
    // carte pâle reliée au conjoint, et la plus visible était l'orpheline. Un
    // exemplaire qui ne porte aucun trait ne dit rien : on ne le laisse pas.
    const rapporte = membres.find((id) => !avecParents.has(id))

    if (rapporte !== undefined) {
      const ancre = membres.find((id) => id !== rapporte)
      const personne = parId.get(rapporte)
      const noeudAncre = ancre === undefined ? undefined : parId.get(ancre)

      if (personne !== undefined && noeudAncre !== undefined) deplaces.push({ personne, ancre: noeudAncre })
      continue
    }

  }

  // ── Le second cas, et c'est le seul qui compte pour les croisements ──────────
  //
  // **Les deux époux sont enfants de l'arbre.** La rangée ne peut pas rendre à
  // chacun sa fratrie ET les garder accolés. Le moteur choisit le couple : l'un
  // des deux quitte les siens, et **son trait de FILIATION traverse alors tout
  // l'arbre** pour rejoindre le losange de ses parents. Ces traits-là portent
  // **71 des 79 croisements** de l'arbre réel — mesuré en les retirant — et
  // aucune géométrie ne les atteint, pas même des blocs rigides.
  //
  // **Ce n'est donc pas un couple séparé qu'on répare ici**, c'est une fratrie
  // quittée. La boucle précédente ne pouvait pas le voir : elle ne regardait que
  // les couples que le moteur avait dû écarter, et ceux-ci sont bien accolés.
  //
  // La construction : **l'original ne bouge pas** — il reste collé à son conjoint
  // avec l'union —, et **son écho se pose sous le losange de ses parents**, au
  // milieu de sa fratrie, avec la filiation. Chaque exemplaire ne porte qu'un
  // seul trait, et les deux sont courts. C'est le miroir de la formulation
  // habituelle, et c'est le sens qui déplace le moins : le moteur a déjà mis
  // l'original à côté du conjoint.
  for (const personne of absorbe) {
    const place = indexDansSaRangee.get(personne)
    const famille = familleDesParents.get(personne)
    const losange = famille === undefined ? undefined : parId.get(famille)
    const original = parId.get(personne)

    if (place === undefined || famille === undefined || losange === undefined || original === undefined) continue

    // **Condition, pas seuil** : si la personne touche déjà l'un des siens, sa
    // fratrie ne l'a pas perdue et il n'y a rien à dupliquer.
    const fratrie = new Set(enfantsDe.get(famille) ?? [])
    const voisinDeFratrie = [place.index - 1, place.index + 1].some((i) => {
      const voisin = place.rangee[i]
      return voisin !== undefined && fratrie.has(voisin.id)
    })

    if (voisinDeFratrie) continue

    echos.push({ original, ancre: losange, famille, porte: 'filiation' })
  }

  if (echos.length === 0 && deplaces.length === 0) return graphe

  const noeuds = [...graphe.noeuds]
  const aretes: AreteGraphe[] = []

  // Les arêtes d'union du couple partent désormais de l'écho : c'est tout
  // l'objet de la manœuvre. Celles qu'on ne remplace pas sont recopiées.
  const remplacees = new Set(
    echos.map(({ original, famille, porte }) =>
      porte === 'union'
        ? `union|${original.id}|${famille}`
        : `filiation|${famille}|${original.id}`,
    ),
  )

  for (const arete of graphe.aretes) {
    if (remplacees.has(`${arete.genre}|${arete.de}|${arete.vers}`)) continue
    aretes.push(arete)
  }

  /**
   * Les rangées touchées, avec les échos insérés **par position dans la liste** et
   * non par abscisse.
   *
   * Le tri par abscisse ne suffit pas : un écho posé exactement où se trouve déjà
   * quelqu'un se fait départager par l'identifiant, et le voisin peut lui passer
   * devant — l'écho se retrouve alors à un rang de son partenaire, ce qui est
   * précisément le défaut qu'il existe pour supprimer. On l'insère donc juste
   * après son ancre, et la passe d'écartement fait la place.
   */
  const rangeesTouchees = new Map<number, NoeudGraphe[]>()

  for (const { original, ancre, famille, porte } of echos) {
    // Un écho d'union se pose À DROITE de son conjoint ; un écho de filiation se
    // pose SOUS le losange de ses parents, c'est-à-dire à son abscisse.
    const echo = {
      ...original,
      id: `${original.id}${SUFFIXE_ECHO}` as IdentifiantNoeud,
      x: porte === 'union' ? ancre.x + ancre.w / 2 + ESPACE + original.w / 2 : ancre.x,
    } as NoeudGraphe

    noeuds.push(echo)
    aretes.push(
      porte === 'union'
        ? ({ de: echo.id, vers: famille, genre: 'union' } as AreteGraphe)
        : ({ de: famille, vers: echo.id, genre: 'filiation' } as AreteGraphe),
    )

    const rangee = rangeesTouchees.get(echo.y) ?? [...(rangees.get(echo.y) ?? [])]

    if (porte === 'union') {
      const place = rangee.findIndex((noeud) => noeud.id === ancre.id)
      rangee.splice(place < 0 ? rangee.length : place + 1, 0, echo)
    } else {
      // Le losange n'est pas sur cette rangée : on insère à l'abscisse, là où la
      // fratrie s'ouvre. La passe d'écartement fait ensuite la place.
      const place = rangee.findIndex((noeud) => noeud.x > echo.x)
      rangee.splice(place < 0 ? rangee.length : place, 0, echo)
    }

    rangeesTouchees.set(echo.y, rangee)
  }

  // **Les déplacés** : la personne quitte sa place et vient se coller à son
  // conjoint. Elle n'est pas dupliquée — elle ne laissait derrière elle qu'une
  // carte sans aucun trait. Le trou qu'elle laisse ne se referme pas : la passe
  // d'écartement ne pousse qu'à droite, comme le moteur, et ramener les voisins
  // vers la gauche serait une autre décision que celle qu'on éprouve ici.
  for (const { personne, ancre } of deplaces) {
    const rangee = rangeesTouchees.get(personne.y) ?? [...(rangees.get(personne.y) ?? [])]
    const ou = rangee.findIndex((noeud) => noeud.id === personne.id)

    if (ou >= 0) rangee.splice(ou, 1)

    const place = rangee.findIndex((noeud) => noeud.id === ancre.id)
    const pose = { ...personne, x: ancre.x + ancre.w / 2 + ESPACE + personne.w / 2 } as NoeudGraphe

    rangee.splice(place < 0 ? rangee.length : place + 1, 0, pose)
    rangeesTouchees.set(personne.y, rangee)
  }

  const abscisses = new Map<IdentifiantNoeud, number>()

  for (const rangee of rangeesTouchees.values()) {
    for (const [id, x] of ecarterVersLaDroite(rangee)) abscisses.set(id, x)
  }

  const replaces = noeuds.map((noeud) => {
    const x = abscisses.get(noeud.id)
    return x === undefined || x === noeud.x ? noeud : { ...noeud, x }
  })

  // Les losanges suivent leur couple, échos compris : un nœud de jonction qui ne
  // suit pas pointe à côté de l'union qu'il joint.
  const apresId = new Map(replaces.map((noeud) => [noeud.id, noeud]))
  const epouxApres = epouxParFamille(aretes)

  const finaux = replaces.map((noeud) => {
    if (noeud.type !== 'famille') return noeud

    const membres = (epouxApres.get(noeud.id) ?? [])
      .map((id) => apresId.get(id))
      .filter((n): n is NoeudGraphe => n !== undefined)

    if (membres.length === 0) return noeud

    const x = membres.reduce((somme, membre) => somme + membre.x, 0) / membres.length

    return x === noeud.x ? noeud : { ...noeud, x }
  })

  return { ...graphe, noeuds: finaux, aretes, boite: boiteEnglobante(finaux, graphe.boite) }
}

/** Combien d'échos une disposition a posés — le chiffre à annoncer. */
export function compterLesEchos(graphe: Graphe): number {
  return graphe.noeuds.filter((noeud) => estUnEcho(noeud.id)).length
}
