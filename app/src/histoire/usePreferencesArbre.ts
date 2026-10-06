/**
 * Les réglages de l'arbre qui sont des **préférences de ce navigateur** :
 * famille du conjoint, familles par alliance (et où les ranger), personnes sans
 * lien connu, fratries par ordre de naissance, ligne directe seule, seuil des noms.
 *
 * Trois bascules RETIRENT des fiches avant la disposition, pour que l'arbre se
 * recompacte (les filtres, eux, estompent sans rien déplacer) :
 *
 *  - la famille du conjoint (son ascendance, ses collatéraux) : affichée par défaut ;
 *  - les familles PAR ALLIANCE (décision du 2026-10-01) : masquées par défaut. Vue
 *    de la nièce, l'ascendance du mari de sa tante n'a aucun lien de sang avec
 *    elle ; affichée, chacune a son propre bloc, à droite des branches du lecteur ;
 *  - les personnes SANS LIEN CONNU (décision du 2026-10-04) : masquées par défaut,
 *    retenues une fois affichées. Aucun chemin ne les relie au lecteur ; affichées,
 *    elles gardent leur zone « sans lien connu », au-delà du bord droit.
 *
 * Chaque changement est écrit tout de suite ; un navigateur qui refuse le
 * stockage (navigation privée) garde la bascule pour la visite.
 */
import { ref, watch, type Ref } from 'vue'
import { SEUIL_DES_NOMS, bornerSeuilDesNoms } from './vue'

export const CLE_CONJOINT = 'heritage.arbre.familleDuConjoint'
export const CLE_ALLIANCES = 'heritage.arbre.famillesParAlliance'
export const CLE_SANS_LIEN = 'heritage.arbre.sansLienConnu'
/** Affichées : chacune à part (défaut, la vue d'avant) ou rangée dans les branches. */
export const CLE_ALLIANCES_DANS_L_ARBRE = 'heritage.arbre.alliancesDansLArbre'
/** Les fratries dans l'ordre des naissances (l'aîné à gauche) ; décochée, la règle d'avant. */
export const CLE_FRATRIES = 'heritage.arbre.fratriesParNaissance'
/** La ligne directe : les autres fiches estompées (défaut), ou retirées de l'écran. */
export const CLE_LIGNE_SEULE = 'heritage.arbre.ligneDirecteSeule'
/** Le zoom à partir duquel les noms s'écrivent (niveau Fiches), en fraction. */
export const CLE_SEUIL_DES_NOMS = 'heritage.arbre.seuilDesNoms'

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

/** Le seuil des noms gardé, ramené dans ses bornes ; le défaut s'il n'y en a pas. */
export function lireSeuilDesNoms(stockage: Pick<Storage, 'getItem'> | null = stockageOuNul()): number {
  try {
    const v = stockage?.getItem(CLE_SEUIL_DES_NOMS) ?? null
    return v === null ? SEUIL_DES_NOMS.defaut : bornerSeuilDesNoms(Number(v))
  } catch {
    return SEUIL_DES_NOMS.defaut
  }
}

/** Le défaut n'est pas écrit : un seuil revenu au défaut suit les défauts à venir. */
export function ecrireSeuilDesNoms(v: number, stockage: Pick<Storage, 'setItem' | 'removeItem'> | null = stockageOuNul()): void {
  try {
    if (v === SEUIL_DES_NOMS.defaut) stockage?.removeItem(CLE_SEUIL_DES_NOMS)
    else stockage?.setItem(CLE_SEUIL_DES_NOMS, String(v))
  } catch {
    /* navigation privée : le réglage vaut pour la visite */
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
  avecSansLien: Ref<boolean>
  alliancesDansLArbre: Ref<boolean>
  fratriesParNaissance: Ref<boolean>
  ligneSeule: Ref<boolean>
  seuilDesNoms: Ref<number>
}

/**
 * Les préférences, lues au montage et écrites à chaque changement.
 * `surChangement` est appelé après l'écriture de celles qui changent la
 * disposition : c'est là que l'écran la refait.
 */
export function usePreferencesArbre(surChangement: () => void): PreferencesArbre {
  const p: PreferencesArbre = {
    avecConjoint: ref(lirePreference(CLE_CONJOINT, true)),
    avecAlliances: ref(lirePreference(CLE_ALLIANCES, false)),
    avecSansLien: ref(lirePreference(CLE_SANS_LIEN, false)),
    alliancesDansLArbre: ref(lirePreference(CLE_ALLIANCES_DANS_L_ARBRE, false)),
    fratriesParNaissance: ref(lirePreference(CLE_FRATRIES, false)),
    ligneSeule: ref(lirePreference(CLE_LIGNE_SEULE, false)),
    seuilDesNoms: ref(lireSeuilDesNoms()),
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
  // Les alliances et les personnes sans lien n'ont pas d'observateur : la bascule se
  // refait TOUT DE SUITE (`basculerAlliances` dans l'écran) — la recherche l'allume puis
  // cherche la fiche dans la disposition, dans la même foulée. La ligne seule, elle, se
  // redispose par la clé de la ligne (`cleLigneSeule`), dans l'écran.
  watch(p.ligneSeule, (seule) => ecrirePreference(CLE_LIGNE_SEULE, seule))
  watch(p.seuilDesNoms, (v) => ecrireSeuilDesNoms(v))
  return p
}
