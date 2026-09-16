/**
 * Le regroupement de la file de validation.
 *
 * Le contrat est explicite sur le partage du travail : « triée par `submittedAt`
 * croissant, **regroupée par personne côté affichage** : l'API rend
 * `targetKind`/`targetId`, le front groupe » (§ 6.7, ADR 0004).
 *
 * Ce n'est pas une commodité visuelle. Deux demandes sur la même fiche
 * s'influencent : valider la première peut rendre la seconde caduque, et
 * l'administrateur qui les traite dans deux écrans séparés découvrira la
 * caducité **après** avoir cliqué. Les voir côte à côte, c'est décider dans le
 * bon ordre — et c'est ce que le bloc `demandesConcurrentes` du détail (§ 6.7)
 * dit à l'échelle d'une demande.
 *
 * Deux règles de forme :
 *
 * - **le regroupement ne réordonne rien de lui-même.** Il conserve l'ordre de ce
 *   qu'on lui donne : les groupes sortent dans l'ordre de leur demande la plus
 *   ancienne, et non par ordre alphabétique. Un tri *implicite* ajouté ici
 *   défferait celui du serveur sans que personne s'en aperçoive — le tri
 *   explicite, lui, est un choix du lecteur et vit dans `trierDemandes()` ;
 * - **les créations de personne n'ont pas de cible** (`targetId` à `null`) et
 *   forment leur propre groupe. Elles ne peuvent pas en avoir : la personne
 *   n'existe pas encore, c'est tout leur objet.
 */

import type { Demande } from '@/lib/api'

export interface GroupeDemandes {
  /** Clé technique, stable pour un `v-for`. */
  cle: string
  titre: string
  /** Vrai pour le groupe des demandes sans cible (les créations). */
  sansCible: boolean
  demandes: Demande[]
}

const CLE_SANS_CIBLE = 'sans-cible'

/**
 * Regroupe la file par fiche visée, en préservant l'ordre du serveur.
 *
 * Le groupe sans cible est laissé **à sa place** dans l'ordre d'arrivée, et non
 * poussé en fin de liste : une création déposée il y a trois semaines passe
 * avant une modification d'hier, et la remonter — ou la reléguer — reviendrait à
 * réordonner la file sur un critère que personne n'a demandé.
 */
export function grouperParCible(demandes: Demande[]): GroupeDemandes[] {
  const groupes = new Map<string, GroupeDemandes>()

  for (const demande of demandes) {
    const sansCible = demande.targetId === null || demande.targetKind === null
    const cle = sansCible ? CLE_SANS_CIBLE : `${demande.targetKind}:${demande.targetId}`

    let groupe = groupes.get(cle)
    if (groupe === undefined) {
      groupe = {
        cle,
        titre: sansCible ? 'Personnes proposées' : (demande.targetLabel ?? `Fiche n° ${demande.targetId}`),
        sansCible,
        demandes: [],
      }
      groupes.set(cle, groupe)
    }
    groupe.demandes.push(demande)
  }

  // `Map` préserve l'ordre d'insertion : le premier groupe est celui de la
  // demande la plus ancienne, ce qui est exactement l'ordre du serveur.
  return [...groupes.values()]
}

/**
 * Les groupes qui portent plus d'une demande — ceux où l'ordre de décision
 * compte.
 */
export function groupesEnConcurrence(groupes: GroupeDemandes[]): GroupeDemandes[] {
  return groupes.filter((groupe) => !groupe.sansCible && groupe.demandes.length > 1)
}

/* ------------------------------------------------------------------ */
/* Le tri — explicite, et seulement explicite                          */
/* ------------------------------------------------------------------ */

export type SensDeTri = 'anciennes' | 'recentes'

/**
 * Trie la file par date de soumission.
 *
 * `anciennes` **est l'ordre du serveur** et le défaut : une file se prend par le
 * début, et la demande qui attend depuis trois semaines est celle dont l'auteur
 * se demande si quelqu'un l'a lue. `recentes` existe parce qu'on cherche parfois
 * ce qu'on vient de recevoir — mais il faut l'avoir demandé.
 *
 * Deux précautions :
 *
 * - **le tableau d'entrée n'est pas modifié.** Il vient d'une `ref` que le
 *   composant relit ; le trier sur place ferait dépendre l'affichage de l'ordre
 *   dans lequel les `computed` se sont réveillés ;
 * - **une demande sans `submittedAt` est un brouillon** — jamais soumise, donc
 *   sans date. Elle est rangée en fin de liste dans les deux sens plutôt que
 *   traitée comme datée de 1970, ce qui la propulserait en tête du tri
 *   « anciennes » alors qu'elle n'attend aucune décision.
 */
export function trierDemandes(demandes: Demande[], sens: SensDeTri): Demande[] {
  const horodatage = (demande: Demande): number | null => {
    if (demande.submittedAt === null) return null
    const instant = new Date(demande.submittedAt).getTime()
    return Number.isNaN(instant) ? null : instant
  }

  return [...demandes].sort((a, b) => {
    const ta = horodatage(a)
    const tb = horodatage(b)
    if (ta === null && tb === null) return 0
    if (ta === null) return 1
    if (tb === null) return -1
    return sens === 'anciennes' ? ta - tb : tb - ta
  })
}

/**
 * La mention d'auteur d'une entrée de file : « par X, le 16 septembre 2026 ».
 *
 * **Pourquoi une fonction pour deux bouts de chaîne.** Le gabarit composait la
 * ligne en deux morceaux, la date dans un `<template v-if>` ouvert à la ligne
 * suivante :
 *
 * ```
 * par {{ demande.authorLabel }}
 * <template v-if="demande.submittedAt">
 *   , le {{ dateLisible(demande.submittedAt) }}
 * ```
 *
 * Le retour à la ligne entre le nom et la virgule n'est pas rien : la
 * condensation des blancs de Vue le réduit à **une espace**, qui se glisse
 * entre le nom et la virgule. Chaque ligne de la file affichait donc « par
 * quelqu'un **,** le 16 septembre 2026 » — en français, la virgule se colle au
 * mot qui la précède.
 *
 * Recoller la virgule dans le gabarit se fait, mais au prix d'un `}}<template`
 * sans blanc que la première mise en forme automatique défait sans bruit. La
 * chaîne est donc composée ici, où aucun blanc n'est significatif et où un test
 * peut la lire.
 *
 * Une demande **non soumise** (brouillon) n'a pas de date : la mention s'arrête
 * au nom, sans virgule orpheline.
 *
 * @param dateLisible la date **déjà mise en forme** — cette fonction reste ainsi
 *                    indépendante de la locale et de la zone du poste qui la teste.
 */
export function ligneAuteur(auteur: string, dateLisible: string): string {
  return dateLisible.length > 0 ? `par ${auteur}, le ${dateLisible}` : `par ${auteur}`
}
