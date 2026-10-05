/**
 * Module `Statistiques` : l'onglet Explorer › Stats.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Tout se calcule côté serveur** (ADR 0015). Le front ne refait aucun
 * calcul sur le graphe : il reçoit des chiffres déjà passés par le cercle de
 * visibilité et par le seuil de discrétion, et il les dessine. Refaire un âge
 * moyen ici supposerait d'avoir les dates de toutes les fiches — dont celles
 * que le lecteur n'a pas le droit de lire.
 *
 * Deux appels, deux routes :
 *
 * - `GET /api/explorer/stats` — tout membre : l'arbre en chiffres, la vie
 *   d'avant, les records, les prénoms et les noms, les lieux et les métiers ;
 * - `GET /api/administration/stats/chantier` — l'administration seulement :
 *   les fiches à compléter et l'activité. Un membre qui l'appellerait reçoit
 *   `403` ; l'écran ne l'appelle donc que pour un administrateur.
 *
 * **La portée** (`?portee=arbre|tout`) : `arbre`, le défaut, ne compte que
 * l'arbre du lecteur — la famille reliée à sa fiche, sans les personnes sans
 * lien connu. `tout` couvre toute la base et n'est accordé qu'à
 * l'administration : un membre qui le demanderait reçoit `arbre`, et la charge
 * dit toujours la portée retenue.
 *
 * **Chaque chiffre porte son échantillon** (`n`) : l'écran l'affiche, pour
 * qu'une moyenne sur trois personnes ne se lise pas comme une loi.
 * ────────────────────────────────────────────────────────────────────
 */

import { requeteApi, type OptionsRequete } from './client'
import type { PorteeExplorer } from './types'

/** Une moyenne et son effectif. `moyenne` vaut `null` sur un échantillon vide. */
export interface Moyenne {
  moyenne: number | null
  n: number
}

/** Une case « par siècle », hommes, femmes et ensemble. */
export interface ParSiecleHommesFemmes {
  siecle: number
  hommes: Moyenne
  femmes: Moyenne
  ensemble: Moyenne
}

/** Une ligne de palmarès : un libellé, combien de fois. */
export interface LignePalmares {
  libelle: string
  n: number
}

/** Une personne nommée — seulement si le lecteur la voit. */
export interface PersonneDesStats {
  id: number
  nom: string
}

/** Un record : sa valeur, ses titulaires, et, selon le record, ses bornes. */
export interface RecordDeLArbre {
  valeur: number
  personnes: PersonneDesStats[]
  debut?: number | null
  fin?: number | null
  enCours?: boolean
  sexe?: string
}

export interface CompteVerifie {
  verifiees: number
  total: number
}

export interface BrancheDesStats {
  branche: string
  /** Le patronyme de tête du quart, quand il est connu et visible. */
  nom: string | null
  personnes: number
  verifiees: number
}

/** Ce que les chiffres couvrent : l'arbre du lecteur, ou toute la base (administration). */
export type PorteeDesStatistiques = PorteeExplorer

export interface Statistiques {
  calculeLe: string
  portee: PorteeDesStatistiques
  seuilDeDiscretion: number
  arbre: {
    personnes: number
    familles: number
    generations: number
    periode: { debut: number | null; fin: number | null; jusquAujourdhui: boolean }
    verification: {
      fiches: CompteVerifie
      naissances: CompteVerifie
      deces: CompteVerifie
      mariages: CompteVerifie
      historique: { mois: string; verifiees: number; total: number }[]
    }
    branches: BrancheDesStats[]
  }
  vie: {
    ageAuDeces: ParSiecleHommesFemmes[]
    premierMariage: ParSiecleHommesFemmes[]
    enfantsParCouple: { generation: number; moyenne: number | null; n: number; annee: number | null }[]
    ageDesParents: { siecle: number; peres: Moyenne; meres: Moyenne }[]
    ecartDansLeCouple: Moyenne & {
      mariPlusAge: number
      epousePlusAgee: number
      memeAnnee: number
      parSiecle: ({ siecle: number } & Moyenne)[]
    }
    moisDesNaissances: { mois: number[]; n: number }
    moisDesDeces: { mois: number[]; n: number }
    mortaliteInfantile: { siecle: number; avantCinqAns: number; n: number }[]
  }
  records: {
    doyen: RecordDeLArbre | null
    fratrie: RecordDeLArbre | null
    union: RecordDeLArbre | null
    ecartParentEnfant: RecordDeLArbre | null
    ancetres: ({ branche: string; nom: string | null } & RecordDeLArbre)[]
  }
  prenomsEtNoms: {
    prenomsParSiecle: { siecle: number; hommes: LignePalmares[]; femmes: LignePalmares[]; n: number }[]
    prenomsTransmis: {
      prenom: string
      generations: number
      porteurs: number
      depuis: PersonneDesStats
      annee: number | null
    }[]
    noms: { frequents: LignePalmares[]; patronymes: number; n: number }
  }
  lieux: {
    communes: { palmares: LignePalmares[]; n: number; distinctes: number }
    departements: { palmares: LignePalmares[]; n: number; distincts: number }
    distance: { siecle: number; moyenne: number | null; mediane: number | null; n: number }[]
    metiers: { siecle: number; metiers: LignePalmares[]; n: number }[]
    migrations: {
      pays: { code: string; nom: string; personnes: number; regions: LignePalmares[] }[]
      neesHorsDeFrance: number
      n: number
    }
  }
}

/** Une liste du chantier : le compte entier, et les fiches que le lecteur voit. */
export interface ListeDuChantier {
  n: number
  personnes: (PersonneDesStats & { naissance: number | null })[]
}

export type CleDuChantier = 'sansDateDeNaissance' | 'sansParents' | 'sansSource' | 'nonVerifiees'

export interface Chantier {
  fiches: { [cle in CleDuChantier]: ListeDuChantier }
  activite: {
    demandesParMois: { mois: string; proposees: number; validees: number }[]
    demandesEnAttente: number
    membres: { actifs: number; connectesRecemment: number; jours: number }
    actesEnAttente: number
  }
}

function options(portee: PorteeDesStatistiques, signal?: AbortSignal): OptionsRequete {
  const o: OptionsRequete = { requete: { portee } }
  if (signal !== undefined) o.signal = signal
  return o
}

/** `GET /api/explorer/stats` — tout membre. */
export function chargerStatistiques(
  portee: PorteeDesStatistiques = 'arbre',
  signal?: AbortSignal,
): Promise<Statistiques> {
  return requeteApi<Statistiques>('/explorer/stats', options(portee, signal))
}

/** `GET /api/administration/stats/chantier` — administration seulement. */
export function chargerChantier(portee: PorteeDesStatistiques = 'arbre', signal?: AbortSignal): Promise<Chantier> {
  return requeteApi<Chantier>('/administration/stats/chantier', options(portee, signal))
}
