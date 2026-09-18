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
 * ── Ce qu'elle duplique, et rien d'autre ────────────────────────────────────
 *
 * Le **conjoint rapporté** d'un couple séparé — celui qui n'a aucun parent dans
 * l'arbre. Son écho se pose à côté de son partenaire ; son exemplaire d'origine
 * reste où le moteur l'avait mis. On ne duplique pas quelqu'un qui a des parents :
 * son écho aurait deux traits à lui seul, celui de sa filiation et celui de son
 * union, et l'on n'aurait rien simplifié.
 * ────────────────────────────────────────────────────────────────────
 */

import type { AreteGraphe, Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
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

  const echos: { original: NoeudGraphe; ancre: NoeudGraphe; famille: IdentifiantNoeud }[] = []

  for (const [famille, membres] of epoux) {
    if (membres.length !== 2) continue

    const a = indexDansSaRangee.get(membres[0]!)
    const b = indexDansSaRangee.get(membres[1]!)

    if (a === undefined || b === undefined || a.rangee !== b.rangee) continue
    if (Math.abs(a.index - b.index) === 1) continue

    // Le rapporté est celui qu'on duplique : son écho n'aura qu'un seul trait,
    // celui de l'union. Dupliquer quelqu'un qui a des parents en produirait deux.
    const rapporte = membres.find((id) => !avecParents.has(id))
    if (rapporte === undefined) continue

    const ancre = membres.find((id) => id !== rapporte)
    const original = parId.get(rapporte)
    const noeudAncre = ancre === undefined ? undefined : parId.get(ancre)

    if (original === undefined || noeudAncre === undefined) continue

    echos.push({ original, ancre: noeudAncre, famille })
  }

  if (echos.length === 0) return graphe

  const noeuds = [...graphe.noeuds]
  const aretes: AreteGraphe[] = []

  // Les arêtes d'union du couple partent désormais de l'écho : c'est tout
  // l'objet de la manœuvre. Celles qu'on ne remplace pas sont recopiées.
  const remplacees = new Set(echos.map(({ original, famille }) => `${original.id}→${famille}`))

  for (const arete of graphe.aretes) {
    if (arete.genre === 'union' && remplacees.has(`${arete.de}→${arete.vers}`)) continue
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

  for (const { original, ancre, famille } of echos) {
    const echo = {
      ...original,
      id: `${original.id}${SUFFIXE_ECHO}` as IdentifiantNoeud,
      x: ancre.x + ancre.w / 2 + ESPACE + original.w / 2,
    } as NoeudGraphe

    noeuds.push(echo)
    aretes.push({ de: echo.id, vers: famille, genre: 'union' } as AreteGraphe)

    const rangee = rangeesTouchees.get(echo.y) ?? [...(rangees.get(echo.y) ?? [])]
    const place = rangee.findIndex((noeud) => noeud.id === ancre.id)

    rangee.splice(place < 0 ? rangee.length : place + 1, 0, echo)
    rangeesTouchees.set(echo.y, rangee)
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
