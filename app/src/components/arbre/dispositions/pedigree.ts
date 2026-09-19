/**
 * **L'éventail d'ascendance** — la mise en page qu'on attend quand on coche
 * « ascendance seule », et que le filtre seul ne donne pas.
 *
 * ────────────────────────────────────────────────────────────────────
 * Retirer tout ce qui n'est pas un ancêtre laisse un arbre binaire ; mais un
 * arbre binaire dessiné **aux places de l'arbre complet** reste éparpillé : chaque
 * ancêtre garde l'abscisse que lui avaient value ses frères, ses cousins et sa
 * belle-famille — qui ne sont plus là. Il faut donc le replacer, et pour un
 * arbre binaire il n'y a rien à optimiser : la place de chacun se déduit de sa
 * position dans l'arbre, comme sur tous les éventails de généalogie.
 *
 * **Le placement est compact, pas par créneaux binaires.** La première version
 * donnait à chaque ancêtre un créneau `2s` / `2s + 1` sous celui de son enfant,
 * comme sur un éventail imprimé : la génération la plus haute occupait
 * `2^G` créneaux, qu'ils soient remplis ou non. Mesuré sur l'arbre réel :
 * 210 ancêtres sur 11 générations, donc 2 048 créneaux pour 105 lignées
 * connues — une boîte de 563 648 unités de large, où une fiche fait 0,7 pixel
 * sur un écran de bureau. « Rien ne s'affiche », littéralement.
 *
 * Ici, seules les **lignées connues** prennent de la place : un ancêtre sans
 * parents connus — une feuille — reçoit le créneau suivant, de gauche à droite,
 * le père avant la mère ; un ancêtre dont on connaît les parents se pose **au
 * milieu d'eux**. Chaque couple se retrouve exactement au-dessus de son enfant,
 * et la largeur suit le nombre de lignées, pas la profondeur : 105 lignées ×
 * une fiche, soit à peu près l'arbre complet. Les ordonnées ne bougent pas —
 * la génération est une donnée, pas un choix de dessin. Le tout est recentré
 * sur le lecteur.
 *
 * ── L'implexe ────────────────────────────────────────────────────────
 *
 * Le même ancêtre atteint par deux chemins (deux cousins qui se sont épousés)
 * n'existe qu'une fois dans le graphe. Il est placé par le **premier** chemin
 * qui l'atteint, et il ne compte plus pour le second : sinon il tirerait la
 * seconde branche vers la première, par-dessus tout ce qui se trouve entre les
 * deux. Le trait qui le relie à son autre enfant est simplement plus long. Un
 * doublon dessiné serait un mensonge sur le nombre d'ancêtres.
 * ────────────────────────────────────────────────────────────────────
 */
import type { AreteGraphe, Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
import { ESPACE, epouxParFamille, grapheReplace } from './socle'

export function pedigree(graphe: Graphe): Graphe {
  const parId = new Map<IdentifiantNoeud, NoeudGraphe>()
  for (const noeud of graphe.noeuds) parId.set(noeud.id, noeud)
  if (!parId.has(graphe.ego)) return graphe

  const familleDesParents = new Map<IdentifiantNoeud, IdentifiantNoeud>()
  for (const arete of graphe.aretes) {
    if (arete.genre === 'filiation') familleDesParents.set(arete.vers, arete.de)
  }
  const epoux = epouxParFamille(graphe.aretes)
  const parentsDe = (id: IdentifiantNoeud): IdentifiantNoeud[] => {
    const famille = familleDesParents.get(id)
    return famille === undefined ? [] : pereDAbord(epoux.get(famille) ?? [], parId)
  }

  // Les ancêtres, pour le pas de base : la fiche la plus large parmi eux, plus
  // l'espace réglementaire. Deux feuilles voisines sont exactement à cette
  // distance — le rang est plein, sans être serré.
  const ancetres = new Set<IdentifiantNoeud>([graphe.ego])
  const file: IdentifiantNoeud[] = [graphe.ego]
  while (file.length > 0) {
    const courant = file.shift()
    if (courant === undefined) continue
    for (const parent of parentsDe(courant)) {
      if (ancetres.has(parent)) continue
      ancetres.add(parent)
      file.push(parent)
    }
  }
  let largeurMax = 0
  for (const id of ancetres) largeurMax = Math.max(largeurMax, parId.get(id)?.w ?? 0)
  const pas = largeurMax + ESPACE

  // Le placement, en post-ordre : les parents d'abord, l'enfant au milieu d'eux.
  // Un parent déjà placé — atteint par un autre chemin — ne compte pas (implexe).
  const abscisses = new Map<IdentifiantNoeud, number>()
  let prochainCreneau = 0
  const placer = (id: IdentifiantNoeud): number => {
    const propres = parentsDe(id).filter((parent) => !abscisses.has(parent))
    const xs = propres.map(placer)
    const x = xs.length === 0 ? prochainCreneau++ * pas : (Math.min(...xs) + Math.max(...xs)) / 2
    abscisses.set(id, x)
    return x
  }
  const xLecteur = placer(graphe.ego)
  for (const [id, x] of abscisses) abscisses.set(id, x - xLecteur)

  // Les losanges : au milieu de leurs époux, une fois ceux-ci posés.
  for (const [famille, membres] of epoux) {
    const xs = membres.map((id) => abscisses.get(id)).filter((x): x is number => x !== undefined)
    if (xs.length > 0 && parId.has(famille)) {
      abscisses.set(famille, (Math.min(...xs) + Math.max(...xs)) / 2)
    }
  }

  return grapheReplace(graphe, abscisses)
}

/**
 * Le père à gauche, la mère à droite — la convention de tout l'arbre (I6). À
 * sexe inconnu ou identique, l'ordre des arêtes, qui est celui du GEDCOM.
 */
function pereDAbord(
  membres: readonly IdentifiantNoeud[],
  parId: ReadonlyMap<IdentifiantNoeud, NoeudGraphe>,
): IdentifiantNoeud[] {
  if (membres.length !== 2) return [...membres]
  const [a, b] = membres
  const sexeDe = (id: IdentifiantNoeud): string => {
    const noeud = parId.get(id)
    return noeud !== undefined && noeud.type === 'personne' ? noeud.sex : 'inconnu'
  }
  return sexeDe(a) === 'feminin' && sexeDe(b) === 'masculin' ? [b, a] : [a, b]
}

/** Exposé pour le test : ce que le filtre laisse doit être un arbre — deux parents au plus. */
export function nombreDeParentsMax(aretes: readonly AreteGraphe[]): number {
  const epoux = epouxParFamille(aretes)
  let max = 0
  for (const arete of aretes) {
    if (arete.genre === 'filiation') max = Math.max(max, (epoux.get(arete.de) ?? []).length)
  }
  return max
}
