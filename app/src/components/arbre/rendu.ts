/**
 * De l'index du graphe au dessin — **sans toucher au DOM**.
 *
 * Ce fichier tient entre `chemins.ts` (la géométrie brute) et `canevas.ts` (la
 * création des éléments SVG). Il existe pour une raison précise : c'est **ici**
 * que se décide quel tracé va sur quelle arête, et c'est le genre de décision
 * qui, fausse, produit un arbre plausible mais faux — une filiation branchée
 * sur la mauvaise barre de peigne ne saute pas aux yeux sur 377 arêtes.
 *
 * En le gardant pur, on le vérifie en `node`, sans navigateur, sur des chaînes
 * comparables à celles du viewer d'origine. `canevas.ts` n'a plus qu'à créer
 * des éléments à partir de ce que ces fonctions rendent.
 */

import type { AreteGraphe, NoeudGraphe } from '@/lib/api'
import {
  type BarreDUnion,
  hauteursDeBarre,
  hauteursDUnion,
  pointsFiliation,
  pointsUnion,
  trace,
} from './chemins'
import { classesDArete, type IndexGraphe } from './graphe'

/**
 * Une famille et ses enfants, tels qu'ils sortent de l'index.
 *
 * Le type reste celui des **nœuds** et non des simples boîtes de `chemins.ts` :
 * l'appelant a souvent besoin de l'identifiant à côté de la position, et le
 * perdre ici obligerait à ré-indexer pour le retrouver.
 */
export interface PeigneDeNoeuds {
  id: string
  famille: NoeudGraphe
  enfants: NoeudGraphe[]
}

/** Une famille et les enfants qui lui sont rattachés, prêts pour le peigne. */
export function peignesDeFamille(index: IndexGraphe): PeigneDeNoeuds[] {
  return index.familles.map((famille) => ({
    id: famille.id,
    famille,
    enfants: (index.enfantsParFamille.get(famille.id) ?? [])
      .map((id) => index.parId.get(id))
      .filter((n): n is NoeudGraphe => n !== undefined),
  }))
}

export interface AreteTracee {
  de: string
  vers: string
  /** Sans `long`, jamais — voir `classesDArete`. */
  classes: string[]
  /** L'attribut `d` du `<path>`. */
  d: string
}

/**
 * Le tracé de chaque arête du graphe.
 *
 * Une arête dont une extrémité manque est **ignorée** plutôt que signalée : la
 * projection peut légitimement ne pas envoyer un nœud, et faire tomber le rendu
 * entier pour cela serait pire que de ne pas dessiner un trait. Idem pour un
 * tracé vide (deux nœuds confondus).
 *
 * `barres` est calculée une fois pour tout le graphe : chaque famille a **une**
 * barre partagée, et c'est ce qui empêche les arêtes d'une même rangée de se
 * superposer. Une famille sans enfant placé n'en a pas ; on retombe alors sur
 * la médiane entre les deux nœuds.
 */
export function tracerAretes(index: IndexGraphe, aretes: readonly AreteGraphe[]): AreteTracee[] {
  const barres = hauteursDeBarre(peignesDeFamille(index))
  const unions = hauteursDUnion(unionsDuGraphe(index, aretes))
  const tracees: AreteTracee[] = []

  for (const arete of aretes) {
    const de = index.parId.get(arete.de)
    const vers = index.parId.get(arete.vers)
    if (de === undefined || vers === undefined) continue

    // Rôles déduits du **type**, pas de la place dans l'arête — même raison que
    // dans `indexer()` : une inversion d'ordre côté serveur donnerait un tracé
    // qui part du mauvais bord sans rien casser de visible.
    const personne = de.type === 'personne' ? de : vers.type === 'personne' ? vers : null
    const famille = de.type === 'famille' ? de : vers.type === 'famille' ? vers : null
    if (personne === null || famille === null) continue

    const points =
      arete.genre === 'union'
        ? pointsUnion(personne, famille, unions.get(cleDUnion(arete.de, arete.vers)))
        : pointsFiliation(
            famille,
            personne,
            barres.get(famille.id) ?? (famille.y + personne.y) / 2,
          )

    const d = trace(points)
    if (d === '') continue

    tracees.push({ de: arete.de, vers: arete.vers, classes: classesDArete(arete), d })
  }
  return tracees
}

/**
 * La clé d'une arête d'union. Les deux extrémités, dans l'ordre où l'API les
 * donne : c'est déjà unique, et la recomposer ailleurs qu'ici donnerait deux
 * définitions pour une même chose.
 */
function cleDUnion(de: string, vers: string): string {
  return `${de}\u0000${vers}`
}

/** Les arêtes d'union du graphe, prêtes pour {@link hauteursDUnion}. */
function unionsDuGraphe(index: IndexGraphe, aretes: readonly AreteGraphe[]): BarreDUnion[] {
  const barres: BarreDUnion[] = []
  for (const arete of aretes) {
    if (arete.genre !== 'union') continue
    const de = index.parId.get(arete.de)
    const vers = index.parId.get(arete.vers)
    if (de === undefined || vers === undefined) continue
    const epoux = de.type === 'personne' ? de : vers.type === 'personne' ? vers : null
    const famille = de.type === 'famille' ? de : vers.type === 'famille' ? vers : null
    if (epoux === null || famille === null) continue
    barres.push({ cle: cleDUnion(arete.de, arete.vers), epoux, famille })
  }
  return barres
}

/** Les quatre sommets du losange d'une famille — 22 × 14, pas un cercle. */
export function pointsDeLosange(famille: { x: number; y: number; w: number; h: number }): string {
  const dx = famille.w / 2
  const dy = famille.h / 2
  return [
    `${famille.x - dx},${famille.y}`,
    `${famille.x},${famille.y - dy}`,
    `${famille.x + dx},${famille.y}`,
    `${famille.x},${famille.y + dy}`,
  ].join(' ')
}

/** La barre oblique du divorce, conservée (§ 7.3) : c'est une information. */
export function barreDeDivorce(famille: { x: number; y: number }): string {
  return `M${famille.x - 8},${famille.y + 8} L${famille.x + 8},${famille.y - 8}`
}
