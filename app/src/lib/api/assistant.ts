/**
 * Les appels d'API de l'**assistant de proposition** (« Proposer avec
 * l'assistant »).
 *
 * Le membre écrit librement et joint des documents ; le serveur les confie à un
 * modèle qui lit les pièces, parcourt l'arbre avec ses outils et rend des
 * **demandes prêtes à déposer** — les mêmes natures que le formulaire. Rien n'est
 * appliqué par l'IA : ce sont des demandes ordinaires, validées ensuite par un
 * administrateur, et **rien n'est déposé sans le clic du membre**.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Deux appels, et le second ne renvoie pas le corps.**
 *
 * L'analyse rend, pour chaque demande, un `corps` qui n'est qu'une **vue
 * d'affichage** : le modèle voit tout l'arbre, le lecteur non, et les valeurs
 * qu'il n'a pas le droit de lire (`oldValue` d'une personne restreinte) ne
 * doivent jamais atteindre le navigateur. La demande complète — vrais
 * `oldValue`, vrais `baseVersion` — reste **côté serveur**, sous `analyseId`,
 * deux heures. Le dépôt se fait donc par
 * `POST /assistant/analyses/{analyseId}/demandes/{cle}`, et **jamais** par
 * `creerDemande()` : reposter le corps affiché déposerait une demande amputée
 * de ce qu'on nous a caché, que la détection de conflit rendrait caduque sans
 * cause réelle.
 * ────────────────────────────────────────────────────────────────────
 */

import { requeteApi } from './client'
import type {
  CorpsAjoutLien,
  CorpsCreationPersonne,
  CorpsModificationPersonne,
  Demande,
  OpProposee,
} from './propositions'

/** Degré d'assurance que l'assistant attache à une demande. */
export type ConfianceAssistant = 'haute' | 'moyenne' | 'faible'

/**
 * Une personne **existante** citée par une demande (cible, extrémité de lien),
 * décrite par ce que le lecteur a le droit de voir.
 *
 * `restreinte` : vivante hors du cercle du lecteur — **son nom seulement**. Même
 * règle que le graphe : `ligneDates` n'est pas à afficher, même si une réponse
 * mal filtrée la portait.
 */
export interface PersonneCitee {
  personId: number
  displayName: string
  ligneDates?: string | null
  visibilite: 'complete' | 'restreinte'
}

/**
 * Un changement lisible par un humain : un par op, par brouillon, par lien.
 *
 * `avant === null` a deux sens, que l'écran distingue par la nature de la
 * demande : « rien avant » (une personne ou un lien qui n'existait pas) ou
 * « valeur masquée » (une fiche que le lecteur voit restreinte).
 */
export interface ChangementPropose {
  libelle: string
  avant: string | null
  apres: string | null
}

/** Un op tel que l'assistant l'affiche : `oldValue` et `baseVersion` peuvent
 *  manquer — ils restent côté serveur quand le lecteur n'a pas le droit de les
 *  lire. */
export type OpAffichee = Omit<OpProposee, 'oldValue' | 'baseVersion'> &
  Partial<Pick<OpProposee, 'oldValue' | 'baseVersion'>>

/**
 * Le `corps` d'une demande proposée — **pour l'affichage seulement**.
 *
 * Même forme que ce que `POST /api/demandes` accepte, `soumettre` en moins,
 * et jamais de suppression. Il n'est **pas** reposté : voir l'en-tête.
 */
export type CorpsProposeParLAssistant =
  | Omit<CorpsCreationPersonne, 'soumettre'>
  | (Omit<CorpsModificationPersonne, 'soumettre' | 'ops'> & { ops: OpAffichee[] })
  | Omit<CorpsAjoutLien, 'soumettre'>

export interface DemandeProposee {
  /** Clé de la demande dans l'analyse (`d1`, `d2`…) : c'est elle qu'on dépose. */
  cle: string
  titre: string
  justification: string
  confiance: ConfianceAssistant
  corps: CorpsProposeParLAssistant
  personnes: PersonneCitee[]
  changements: ChangementPropose[]
}

/** La réponse `200` de `POST /api/assistant/analyses`. */
export interface AnalyseAssistant {
  /** Identifiant opaque de l'analyse gardée côté serveur (2 h). */
  analyseId: string
  resume: string
  demandes: DemandeProposee[]
  avertissements: string[]
  /** Analyses restantes aujourd'hui pour ce lecteur. */
  restant: number
}

export interface EntreeAnalyse {
  texte: string
  fichiers: readonly File[]
  /** La fiche d'où l'on vient : un contexte pour l'assistant, pas une cible. */
  personId?: number | null
}

/**
 * `POST /api/assistant/analyses` — `multipart/form-data`.
 *
 * Long : 20 à 60 secondes, parfois davantage. Le `signal` n'est pas un confort —
 * c'est ce qui permet à l'écran d'offrir « Annuler » sur une attente qu'on ne
 * peut pas raccourcir.
 *
 * Aucun `Content-Type` n'est posé : le navigateur écrit la frontière multipart
 * lui-même, et le client d'API le sait pour tout `FormData`.
 *
 * Erreurs : `429` (quota du jour), `502 assistant_erreur`, `503
 * assistant_indisponible`, `422` (entrée), `403`/`409` (régime, bascule) — à
 * passer telles quelles à `analyserErreur()`.
 */
export function analyserAvecAssistant(
  entree: EntreeAnalyse,
  signal?: AbortSignal,
): Promise<AnalyseAssistant> {
  const formulaire = new FormData()
  formulaire.append('texte', entree.texte)
  for (const fichier of entree.fichiers) {
    formulaire.append('fichiers[]', fichier, fichier.name)
  }
  // Rien plutôt que `personId=null` : le serveur chercherait une personne dont
  // l'identifiant n'est pas un nombre.
  if (entree.personId !== undefined && entree.personId !== null) {
    formulaire.append('personId', String(entree.personId))
  }
  return requeteApi<AnalyseAssistant>('/assistant/analyses', {
    methode: 'POST',
    corps: formulaire,
    ...(signal === undefined ? {} : { signal }),
  })
}

/**
 * `POST /api/assistant/analyses/{analyseId}/demandes/{cle}` — dépose, **soumise**,
 * la demande complète gardée côté serveur. Rend la `Demande` créée.
 *
 * Une `cle` ne se dépose qu'une fois (`409 deja_deposee`) ; une analyse
 * expirée, inconnue ou d'un autre compte rend `404`.
 */
export function deposerDemandeAssistant(
  analyseId: string,
  cle: string,
  message?: string,
): Promise<Demande> {
  const mot = message?.trim() ?? ''
  return requeteApi<Demande>(
    `/assistant/analyses/${encodeURIComponent(analyseId)}/demandes/${encodeURIComponent(cle)}`,
    { methode: 'POST', corps: mot.length > 0 ? { message: mot } : {} },
  )
}
