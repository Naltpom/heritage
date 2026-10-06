/**
 * Les bascules de l'arbre qui sont des **préférences de ce navigateur** :
 * famille du conjoint, familles par alliance (et où les ranger), fratries par
 * ordre de naissance.
 *
 * Trois d'entre elles RETIRENT ou déplacent des fiches avant la disposition,
 * pour que l'arbre se resserre (les filtres, eux, estompent sans rien déplacer) :
 *
 *  - la famille du conjoint (son ascendance, ses collatéraux) : affichée par défaut ;
 *  - les familles PAR ALLIANCE (décision du 2026-10-01) : masquées par défaut. Vue
 *    de la nièce, l'ascendance du mari de sa tante n'a aucun lien de sang avec
 *    elle ; affichée, chacune a son propre bloc, à droite des branches du lecteur.
 *
 * Chaque changement est écrit tout de suite ; un navigateur qui refuse le
 * stockage (navigation privée) garde la bascule pour la visite.
 */
import { ref, watch, type Ref } from 'vue'

export const CLE_CONJOINT = 'heritage.arbre.familleDuConjoint'
export const CLE_ALLIANCES = 'heritage.arbre.famillesParAlliance'
/** Affichées : chacune à part (défaut, la vue d'avant) ou rangée dans les branches. */
export const CLE_ALLIANCES_DANS_L_ARBRE = 'heritage.arbre.alliancesDansLArbre'
/** Les fratries dans l'ordre des naissances (l'aîné à gauche) ; décochée, la règle d'avant. */
export const CLE_FRATRIES = 'heritage.arbre.fratriesParNaissance'

/** Une valeur absente vaut le défaut ; toute valeur autre que `masquee` vaut « affichée ». */
export function lirePreference(cle: string, parDefaut: boolean, stockage: Pick<Storage, 'getItem'> | null = stockageOuNul()): boolean {
  try {
    const v = stockage?.getItem(cle) ?? null
    return v === null ? parDefaut : v !== 'masquee'
  } catch {
    return parDefaut
  }
}

export function ecrirePreference(cle: string, affichee: boolean, stockage: Pick<Storage, 'setItem'> | null = stockageOuNul()): void {
  try {
    stockage?.setItem(cle, affichee ? 'affichee' : 'masquee')
  } catch {
    /* navigation privée : la bascule vaut pour la visite */
  }
}

function stockageOuNul(): Storage | null {
  try {
    return typeof localStorage === 'undefined' ? null : localStorage
  } catch {
    return null
  }
}

export interface PreferencesArbre {
  avecConjoint: Ref<boolean>
  avecAlliances: Ref<boolean>
  alliancesDansLArbre: Ref<boolean>
  fratriesParNaissance: Ref<boolean>
}

/**
 * Les quatre préférences, lues au montage et écrites à chaque changement.
 * `surChangement` est appelé après l'écriture : c'est là que l'écran refait la
 * disposition.
 */
export function usePreferencesArbre(surChangement: () => void): PreferencesArbre {
  const p: PreferencesArbre = {
    avecConjoint: ref(lirePreference(CLE_CONJOINT, true)),
    avecAlliances: ref(lirePreference(CLE_ALLIANCES, false)),
    alliancesDansLArbre: ref(lirePreference(CLE_ALLIANCES_DANS_L_ARBRE, false)),
    fratriesParNaissance: ref(lirePreference(CLE_FRATRIES, false)),
  }
  const lier = (r: Ref<boolean>, cle: string): void => {
    watch(r, (valeur) => {
      ecrirePreference(cle, valeur)
      surChangement()
    })
  }
  lier(p.avecConjoint, CLE_CONJOINT)
  lier(p.alliancesDansLArbre, CLE_ALLIANCES_DANS_L_ARBRE)
  lier(p.fratriesParNaissance, CLE_FRATRIES)
  // Les alliances n'ont pas d'observateur : la bascule se refait TOUT DE SUITE
  // (`basculerAlliances` dans l'écran) — la recherche l'allume puis cherche la
  // fiche dans la disposition, dans la même foulée.
  return p
}
