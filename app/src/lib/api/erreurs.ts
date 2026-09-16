/**
 * Le traitement des erreurs d'API — **un seul endroit**.
 *
 * Le contrat n'a qu'une enveloppe d'erreur (§ 15.1) et un tableau de codes
 * stables (§ 15.2). Tout écran qui réinterpréterait un code pour son compte
 * finirait par en oublier un ; ici, un écran reçoit une `ErreurApi`, appelle
 * `analyserErreur()` et affiche ce qu'on lui rend.
 *
 * La distinction qui justifie à elle seule ce module : **les deux 409 de la
 * validation donnent des conseils opposés** (§ 15.4).
 *
 *   - `verrou_concurrent` — rien n'a été écrit, la demande reste `en_attente`,
 *     **réessayer** est la bonne action ;
 *   - `demande_caduque` — la demande est *morte*, elle est passée en `caduque`,
 *     son auteur a été notifié : **ne pas réessayer**, proposer « refaire cette
 *     demande » (§ 6.10).
 *
 * « Un front qui afficherait un bouton "réessayer" sur les deux ferait boucler
 * l'administrateur sur une demande caduque. » C'est pour cela que la conduite à
 * tenir est calculée ici et jamais dans un écran.
 */

import type { DetailsConflitDemande } from './types'

/** Conduite que l'interface doit proposer au lecteur. */
export type ConduiteATenir =
  /** Le geste peut être refait à l'identique : rien n'a été écrit. */
  | 'reessayer'
  /** Le geste est mort ; il faut en repartir (§ 6.10, « refaire cette demande »). */
  | 'refaire'
  /** L'état affiché est périmé : recharger avant de recommencer. */
  | 'recharger'
  /** La saisie est en cause : corriger le formulaire (`champs` est rempli). */
  | 'corriger'
  /** La session est tombée : se reconnecter. */
  | 'se_connecter'
  /** Plafond de débit atteint : attendre `secondesAvantNouvelEssai`. */
  | 'patienter'
  /** Rien à proposer : l'erreur est définitive pour ce lecteur. */
  | 'aucune'

/**
 * Une erreur d'API, portée telle que le contrat la décrit.
 *
 * `statut === 0` désigne les cas où **aucune réponse HTTP n'a été reçue** (réseau
 * coupé, requête annulée, corps illisible) : le client les emballe quand même en
 * `ErreurApi` pour qu'un écran n'ait jamais deux formes d'échec à distinguer.
 */
export class ErreurApi extends Error {
  readonly statut: number
  readonly code: string
  readonly champs: Record<string, string> | null
  readonly details: Record<string, unknown> | null

  constructor(
    statut: number,
    code: string,
    message: string,
    champs: Record<string, string> | null = null,
    details: Record<string, unknown> | null = null,
  ) {
    super(message)
    this.name = 'ErreurApi'
    this.statut = statut
    this.code = code
    this.champs = champs
    this.details = details
  }
}

/** Codes fabriqués par le client, hors du tableau du § 15.2. Préfixés pour
 *  qu'on ne les confonde jamais avec un code du serveur. */
export const CODE_RESEAU = 'client_reseau_indisponible'
export const CODE_REPONSE_ILLISIBLE = 'client_reponse_illisible'
export const CODE_ANNULE = 'client_requete_annulee'

/** Codes du § 15.2 dont le front se sert nommément. Les citer par constante
 *  évite la faute de frappe silencieuse dans un `if`. */
export const CODE_AUTHENTIFICATION_REQUISE = 'authentification_requise'
export const CODE_DEMANDE_CADUQUE = 'demande_caduque'
export const CODE_CIBLE_DISPARUE = 'cible_disparue'
export const CODE_VERROU_CONCURRENT = 'verrou_concurrent'
export const CODE_REGIME_EN_ATTENTE = 'regime_en_attente'
export const CODE_VUE_COMME_ACTIVE = 'vue_comme_membre_active'

export function estErreurApi(valeur: unknown): valeur is ErreurApi {
  return valeur instanceof ErreurApi
}

/**
 * Le corps d'une réponse ≥ 400 a-t-il la forme du § 15.1 ?
 *
 * Écrit en garde de type plutôt qu'en `as` : le serveur peut, sur un chemin mal
 * câblé, rendre du HTML ou une autre forme, et c'est précisément le moment où le
 * front a le plus besoin de ne pas exploser.
 */
export function estEnveloppeErreur(
  valeur: unknown,
): valeur is { erreur: { code: string; message: string; champs?: unknown; details?: unknown } } {
  if (typeof valeur !== 'object' || valeur === null || !('erreur' in valeur)) return false
  const erreur = (valeur as { erreur: unknown }).erreur
  if (typeof erreur !== 'object' || erreur === null) return false
  const objet = erreur as Record<string, unknown>
  return typeof objet['code'] === 'string' && typeof objet['message'] === 'string'
}

/** Extrait la carte `champs` d'une enveloppe, en refusant tout ce qui n'est pas
 *  une carte de chaînes : une 422 mal formée ne doit pas piéger un formulaire. */
function lireChamps(valeur: unknown): Record<string, string> | null {
  if (typeof valeur !== 'object' || valeur === null) return null
  const sortie: Record<string, string> = {}
  for (const [cle, brut] of Object.entries(valeur as Record<string, unknown>)) {
    if (typeof brut === 'string') sortie[cle] = brut
  }
  return Object.keys(sortie).length > 0 ? sortie : null
}

function lireDetails(valeur: unknown): Record<string, unknown> | null {
  if (typeof valeur !== 'object' || valeur === null || Array.isArray(valeur)) return null
  return valeur as Record<string, unknown>
}

/** Fabrique une `ErreurApi` depuis le statut et le corps déjà décodé. */
export function erreurDepuisCorps(statut: number, corps: unknown): ErreurApi {
  if (estEnveloppeErreur(corps)) {
    return new ErreurApi(
      statut,
      corps.erreur.code,
      corps.erreur.message,
      lireChamps(corps.erreur.champs),
      lireDetails(corps.erreur.details),
    )
  }
  // Le serveur n'a pas rendu l'enveloppe du § 15.1. C'est un défaut côté API —
  // on le signale par un code à nous, pour qu'il se distingue dans les journaux
  // d'un vrai code du contrat.
  return new ErreurApi(
    statut,
    CODE_REPONSE_ILLISIBLE,
    `Réponse inattendue du serveur (code HTTP ${statut}).`,
  )
}

/**
 * `details.rejouable` d'abord, **puis** la table des codes.
 *
 * Le contrat pose le booléen « pour que le front n'ait pas à connaître la liste
 * des codes » (§ 15.4) : on le lit donc en premier. La table n'est qu'un repli,
 * pour une réponse ancienne ou tronquée — mais elle doit exister, parce que le
 * défaut d'un booléen absent ne peut pas être « réessayer » : boucler sur une
 * demande morte est exactement ce qu'on veut éviter.
 */
export function estRejouable(erreur: ErreurApi): boolean {
  const details = erreur.details as Partial<DetailsConflitDemande> | null
  if (details && typeof details.rejouable === 'boolean') return details.rejouable

  switch (erreur.code) {
    case CODE_VERROU_CONCURRENT:
      return true
    case CODE_DEMANDE_CADUQUE:
    case CODE_CIBLE_DISPARUE:
      return false
    default:
      // Une 409 inconnue n'est pas déclarée rejouable : le défaut est fermé.
      return false
  }
}

/** Secondes à attendre sur une 429, quand le serveur les donne (§ 15.2). */
function secondesAvantNouvelEssai(erreur: ErreurApi): number | null {
  const brut = erreur.details?.['retryAfter']
  return typeof brut === 'number' && Number.isFinite(brut) ? brut : null
}

/** Ce que l'interface doit proposer. Une seule table, lue par tous les écrans. */
export function conduiteATenir(erreur: ErreurApi): ConduiteATenir {
  switch (erreur.code) {
    case CODE_AUTHENTIFICATION_REQUISE:
      return 'se_connecter'

    // Les deux 409 opposées. Elles sont traitées par code et non par statut :
    // c'est tout l'intérêt d'avoir deux codes pour un même code HTTP.
    case CODE_VERROU_CONCURRENT:
      return 'reessayer'
    case CODE_DEMANDE_CADUQUE:
    case CODE_CIBLE_DISPARUE:
      return 'refaire'

    // Une décision a déjà été prise ailleurs : l'écran est en retard sur l'état.
    case 'demande_deja_decidee':
    case 'demande_deja_soumise':
    case 'demande_non_refaisable':
    case 'invitation_deja_consommee':
    case 'personne_deja_rattachee':
    case 'invitation_en_cours':
    case 'import_en_cours':
    case CODE_VUE_COMME_ACTIVE:
      return 'recharger'

    case 'donnees_invalides':
    case 'valeur_hors_bornes':
    case 'demande_sans_lien':
    case 'courriel_deja_pris':
    case 'fichier_trop_volumineux':
    case 'type_de_fichier_refuse':
      return 'corriger'

    case 'trop_de_tentatives':
      return 'patienter'

    case CODE_RESEAU:
    case CODE_REPONSE_ILLISIBLE:
    case 'erreur_interne':
      return 'reessayer'

    default:
      break
  }

  // Repli par statut, pour un code que le contrat ajouterait sans qu'on le sache.
  if (erreur.statut === 422) return 'corriger'
  if (erreur.statut === 429) return 'patienter'
  if (erreur.statut === 409) return estRejouable(erreur) ? 'reessayer' : 'recharger'
  if (erreur.statut >= 500) return 'reessayer'
  return 'aucune'
}

/** Libellé du bouton d'action, ou `null` s'il n'y a rien à proposer. */
export function libelleDeConduite(conduite: ConduiteATenir): string | null {
  switch (conduite) {
    case 'reessayer':
      return 'Réessayer'
    // Jamais « Réessayer » : la demande est morte, on en repart (§ 6.10).
    case 'refaire':
      return 'Refaire cette demande'
    case 'recharger':
      return 'Recharger'
    case 'se_connecter':
      return 'Se connecter'
    case 'corriger':
    case 'patienter':
    case 'aucune':
      return null
  }
}

/**
 * Message affichable.
 *
 * **Le message du serveur fait foi** quand il existe : le contrat le décrit
 * comme « français, une phrase, affichable tel quel », et c'est lui qui porte le
 * contexte (quel champ, quelle demande). Le front ne le réécrit pas ; il ne
 * fournit un texte que pour ses propres codes, où aucun serveur n'a parlé.
 */
export function messageDErreur(erreur: ErreurApi): string {
  switch (erreur.code) {
    case CODE_RESEAU:
      return "Le serveur n'a pas répondu. Vérifiez votre connexion."
    case CODE_ANNULE:
      return 'Requête interrompue.'
    case CODE_REPONSE_ILLISIBLE:
      return erreur.message
    default:
      return erreur.message.trim().length > 0
        ? erreur.message
        : "Une erreur est survenue lors de l'échange avec le serveur."
  }
}

/** Le résultat unique que les écrans consomment. */
export interface AnalyseErreur {
  code: string
  statut: number
  message: string
  conduite: ConduiteATenir
  /** Libellé du bouton d'action, ou `null` s'il n'y en a pas. */
  libelleAction: string | null
  /** Messages par champ d'un formulaire — 422 uniquement (§ 15.1). */
  champs: Record<string, string>
  /** Le geste peut-il être refait à l'identique ? */
  rejouable: boolean
  /** Secondes à attendre sur une 429, quand le serveur les donne. */
  attenteSecondes: number | null
  /** L'erreur exige-t-elle un retour à l'écran de connexion ? */
  sessionPerdue: boolean
}

/**
 * Traduit **n'importe quoi** en analyse affichable.
 *
 * L'argument est `unknown` à dessein : un `catch` en TypeScript ne promet rien,
 * et un écran ne doit pas avoir à trier avant d'appeler. Une erreur qui n'est
 * pas une `ErreurApi` (bogue du front) est rendue lisible plutôt qu'avalée.
 */
export function analyserErreur(valeur: unknown): AnalyseErreur {
  const erreur = estErreurApi(valeur)
    ? valeur
    : new ErreurApi(
        0,
        CODE_REPONSE_ILLISIBLE,
        valeur instanceof Error && valeur.message.trim().length > 0
          ? valeur.message
          : 'Une erreur inattendue est survenue.',
      )

  const conduite = conduiteATenir(erreur)
  return {
    code: erreur.code,
    statut: erreur.statut,
    message: messageDErreur(erreur),
    conduite,
    libelleAction: libelleDeConduite(conduite),
    champs: erreur.champs ?? {},
    rejouable: estRejouable(erreur),
    attenteSecondes: secondesAvantNouvelEssai(erreur),
    sessionPerdue: erreur.code === CODE_AUTHENTIFICATION_REQUISE,
  }
}
