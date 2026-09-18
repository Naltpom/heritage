/**
 * **Disposition « conjoints accolés »** — `/arbre-2`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **LE DÉFAUT QU'ELLE VISE.** Sur l'arbre réel, 36 couples sur 127 dont les deux
 * époux partagent une rangée ne sont **pas voisins** : quelqu'un d'autre s'assoit
 * entre eux, et les deux traits qui montent vers leur losange traversent la
 * rangée en se croisant. Relevé jusqu'à dix-neuf personnes intercalées.
 *
 * La cause tient en trois lignes du moteur :
 *
 * - un enfant est placé au barycentre de ses parents ;
 * - un conjoint est placé par rapport à son partenaire ;
 * - les deux calculs s'ignorent, atterrissent dans la même zone, et la passe de
 *   décrochage **ne fait que pousser vers la droite, sans jamais réordonner**.
 *
 * ── La limite, et c'est elle qui décide de la portée ────────────────────────
 *
 * Une personne occupe **deux places à la fois** sur sa rangée : comme enfant, sa
 * place est sous le losange de ses parents, au milieu de sa fratrie ; comme
 * conjoint, elle est collée à son partenaire. En une dimension, les deux ne
 * tiennent que si les deux groupes sont déjà voisins.
 *
 * Déplacer quelqu'un qui a des parents ne supprime donc pas le trait long : il le
 * **transfère** de son trait d'union vers son trait de filiation. On a l'air
 * d'avoir corrigé, on a déménagé le problème.
 *
 * > **On ne déplace que les conjoints *rapportés*** — ceux qui n'ont aucun parent
 * > dans l'arbre, donc aucun trait qui monte et aucune fratrie à quitter. Pour
 * > eux le gain est net et sans contrepartie.
 *
 * Mesuré : cela couvre **15 des 36**. Les 21 autres unissent deux personnes qui
 * sont chacune enfant d'une famille de l'arbre, et aucun échange local ne peut
 * les rapprocher — il faudrait réordonner les fratries entières, ce que fait la
 * disposition « croisements ».
 * ────────────────────────────────────────────────────────────────────
 */

import type { Graphe, IdentifiantNoeud, NoeudGraphe } from '@/lib/api'
import {
  ecarterVersLaDroite,
  epouxParFamille,
  grapheReplace,
  personnesAvecParents,
  rangeesDePersonnes,
} from './socle'

export function conjointsAccoles(graphe: Graphe): Graphe {
  const epoux = epouxParFamille(graphe.aretes)
  const avecParents = personnesAvecParents(graphe.aretes)
  const rangees = rangeesDePersonnes(graphe.noeuds)

  const abscisses = new Map<IdentifiantNoeud, number>()

  for (const rangee of rangees.values()) {
    const ordonnee = accolerDansLaRangee(rangee, epoux, avecParents)

    for (const [id, x] of ecarterVersLaDroite(ordonnee)) abscisses.set(id, x)
  }

  return grapheReplace(graphe, abscisses)
}

/**
 * Réordonne une rangée pour accoler ce qui peut l'être, et rend le nouvel ordre.
 *
 * ── Trois garde-fous, chacun pour un dommage constaté ───────────────────────
 *
 * **1. On ne déplace qu'un conjoint sans parents.** Voir l'en-tête : déplacer un
 * enfant déménage le trait long au lieu de le supprimer.
 *
 * **2. On n'insère jamais entre deux époux déjà accolés.** Poser le nouveau venu
 * juste après son partenaire casserait le couple qui se trouvait là — on
 * échangerait un couple séparé contre un autre, à somme nulle. On glisse donc
 * après le dernier voisin immédiat qui est lui-même un conjoint de l'ancre.
 *
 * **3. Une seule famille par personne déplacée.** Quelqu'un qui a deux unions ne
 * peut pas être accolé aux deux ; on le pose près de la première rencontrée et on
 * ne le redéplace plus, sinon l'ordre dépendrait de l'ordre de lecture des
 * familles, ce qui n'est pas une propriété de l'arbre.
 */
function accolerDansLaRangee(
  rangee: readonly NoeudGraphe[],
  epoux: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
  avecParents: Set<IdentifiantNoeud>,
): NoeudGraphe[] {
  const ordre = [...rangee]
  const presents = new Set(ordre.map((noeud) => noeud.id))
  const deplaces = new Set<IdentifiantNoeud>()

  // L'ordre de parcours est celui des familles dans la charge, qui est celui du
  // serveur : déterministe, donc le résultat l'est aussi.
  for (const membres of epoux.values()) {
    if (membres.length !== 2) continue

    const [a, b] = membres as [IdentifiantNoeud, IdentifiantNoeud]
    if (!presents.has(a) || !presents.has(b)) continue

    const ia = ordre.findIndex((noeud) => noeud.id === a)
    const ib = ordre.findIndex((noeud) => noeud.id === b)

    if (Math.abs(ia - ib) === 1) continue

    const mobile = choisirLeMobile(a, b, ia, ib, avecParents, deplaces)
    if (mobile === null) continue

    const ancre = mobile === a ? b : a
    deplacerApres(ordre, mobile, ancre, epoux)
    deplaces.add(mobile)
  }

  return ordre
}

/**
 * Lequel des deux peut bouger — ou aucun.
 *
 * Quand les deux sont rapportés, on déplace **celui de droite vers celui de
 * gauche** : le camp de gauche ne bouge jamais, c'est la règle du moteur, et s'en
 * écarter ferait glisser toute la rangée à chaque exécution.
 */
function choisirLeMobile(
  a: IdentifiantNoeud,
  b: IdentifiantNoeud,
  ia: number,
  ib: number,
  avecParents: Set<IdentifiantNoeud>,
  deplaces: Set<IdentifiantNoeud>,
): IdentifiantNoeud | null {
  const aPeut = !avecParents.has(a) && !deplaces.has(a)
  const bPeut = !avecParents.has(b) && !deplaces.has(b)

  if (aPeut && bPeut) return ia > ib ? a : b
  if (aPeut) return a
  if (bPeut) return b

  return null
}

/**
 * Retire `mobile` et le réinsère juste après `ancre` — ou après le dernier
 * conjoint déjà accolé à `ancre`, pour ne casser aucun couple en place.
 */
function deplacerApres(
  ordre: NoeudGraphe[],
  mobile: IdentifiantNoeud,
  ancre: IdentifiantNoeud,
  epoux: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
): void {
  const iMobile = ordre.findIndex((noeud) => noeud.id === mobile)
  const [noeud] = ordre.splice(iMobile, 1)
  if (noeud === undefined) return

  let cible = ordre.findIndex((n) => n.id === ancre)
  if (cible < 0) {
    ordre.splice(iMobile, 0, noeud)
    return
  }

  // Sauter les conjoints déjà collés à l'ancre : les enjamber serait défaire un
  // couple pour en faire un autre.
  const conjointsDeLAncre = conjointsDe(ancre, epoux)
  while (cible + 1 < ordre.length && conjointsDeLAncre.has(ordre[cible + 1]!.id)) cible += 1

  ordre.splice(cible + 1, 0, noeud)
}

function conjointsDe(
  personne: IdentifiantNoeud,
  epoux: Map<IdentifiantNoeud, IdentifiantNoeud[]>,
): Set<IdentifiantNoeud> {
  const sortie = new Set<IdentifiantNoeud>()

  for (const membres of epoux.values()) {
    if (membres.length !== 2 || !membres.includes(personne)) continue
    for (const membre of membres) if (membre !== personne) sortie.add(membre)
  }

  return sortie
}
