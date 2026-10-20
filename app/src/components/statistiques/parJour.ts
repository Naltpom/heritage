/**
 * Ajouts et vérifications, jour par jour (demande du 20/10/2026) — de la série
 * `arbre.parJour` du serveur à ce que le panneau montre : toutes les branches ou
 * une seule, les 30 ou 90 derniers jours ou toute la série, le total de la
 * période et son jour le plus actif. Pur, testé sans navigateur
 * (`parJour.test.ts`).
 *
 * Rien ne s'y **calcule** au sens de l'ADR 0015 : le serveur a compté chaque
 * jour et chaque branche, zéros compris. Ici, on choisit une colonne, on coupe
 * la série et on additionne ce qu'on montre — rien qui exige une date de fiche.
 *
 * Les couleurs sont celles des demandes du chantier (proposées, validées) : le
 * même couple « ce qui entre / ce qui est appuyé », les mêmes jetons.
 */

import type { JourDesStats } from '@/lib/api'
import { COULEUR_SEULE } from './series'

export const COULEUR_AJOUTEES = COULEUR_SEULE
export const COULEUR_VERIFIEES = 'var(--h-ok)'

export type PeriodeDesJours = 30 | 90 | 'tout'

export const PERIODES_DES_JOURS: readonly { valeur: PeriodeDesJours; libelle: string; titre: string }[] = [
  { valeur: 30, libelle: '30 jours', titre: 'Les 30 derniers jours' },
  { valeur: 90, libelle: '90 jours', titre: 'Les 90 derniers jours' },
  { valeur: 'tout', libelle: 'Tout', titre: 'Depuis la première fiche, sur un an au plus' },
]

/** Un jour tel que la courbe le dessine : une branche choisie, ou toutes. */
export interface JourAffiche {
  jour: string
  ajoutees: number
  verifiees: number
}

export interface AjoutsDeLaPeriode {
  jours: JourAffiche[]
  ajoutees: number
  verifiees: number
  /**
   * Le jour qui compte le plus d'ajouts et de vérifications réunis — le plus
   * récent à égalité. `null` sur une période où rien n'a bougé : « le jour le
   * plus actif » d'une suite de zéros n'en est pas un.
   */
  plusActif: JourAffiche | null
}

/**
 * La période retenue, pour `branche` (`null` : toutes). La série du serveur
 * finit aujourd'hui et ne saute aucun jour : les 30 derniers jours sont ses 30
 * dernières lignes. Un arbre commencé il y a dix jours n'en a que dix — on ne
 * prolonge pas la courbe avant sa première fiche.
 */
export function ajoutsDeLaPeriode(
  serie: JourDesStats[],
  branche: string | null,
  periode: PeriodeDesJours,
): AjoutsDeLaPeriode {
  const retenus = periode === 'tout' ? serie : serie.slice(-periode)
  const jours = retenus.map((j): JourAffiche => {
    const compte = branche === null ? j : j.branches[branche]
    return { jour: j.jour, ajoutees: compte?.ajoutees ?? 0, verifiees: compte?.verifiees ?? 0 }
  })

  let ajoutees = 0
  let verifiees = 0
  let plusActif: JourAffiche | null = null
  for (const j of jours) {
    ajoutees += j.ajoutees
    verifiees += j.verifiees
    const activite = j.ajoutees + j.verifiees
    if (activite > 0 && (plusActif === null || activite >= plusActif.ajoutees + plusActif.verifiees)) plusActif = j
  }

  return { jours, ajoutees, verifiees, plusActif }
}

/**
 * Le haut de l'échelle : la première valeur ronde (2, 4, 6, 8, 10, 20, 30, 40,
 * 50…) au moins égale au maximum. Ronde **et paire** : la ligne du milieu porte
 * un nombre entier de personnes, jamais « 2,5 ».
 */
export function hautDeLEchelle(maximum: number): number {
  if (!Number.isFinite(maximum) || maximum <= 2) return 2
  for (let puissance = 1; ; puissance *= 10) {
    for (const pas of [2, 3, 4, 5, 6, 8, 10]) {
      const haut = pas * puissance
      if (haut >= maximum && haut % 2 === 0) return haut
    }
  }
}
