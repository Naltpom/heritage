/**
 * Les appels d'API de la page « Questions » — ADR 0008.
 *
 * L'application cherche dans l'arbre les trous que **ce compte-là** pourrait
 * combler et lui pose la question. Sous chaque question : « Je ne sais pas »,
 * « Je vais me renseigner », un champ de texte, et un dépôt de photo.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le `GET` écrit, et ce n'est pas une erreur de conception.**
 *
 * Ouvrir la page rejoue la détection côté serveur et crée les lignes
 * manquantes. La raison est écrite dans l'ADR 0008 § 3 : le cercle de
 * visibilité se résout **depuis la session**, donc la détection ne peut pas
 * tourner en tâche de fond sans qu'on réécrive la formule du cercle une
 * seconde fois — ce que l'ADR 0003 interdit.
 *
 * Conséquence pour le front, et c'est la seule qui le concerne :
 * **l'appel est idempotent** (un index unique s'en charge), donc on peut
 * recharger sans crainte — mais il n'est **pas** annulable sans effet, et il
 * ne doit pas être déclenché en rafale. Un `409 detection_concurrente`
 * signifie « deux ouvertures simultanées » : `details.rejouable` vaut alors
 * `true`, et la conduite est de **recharger**, pas de réessayer en boucle.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Une réponse n'écrit jamais dans l'arbre.** Elle dépose une ou deux
 * demandes dans la file de validation (ADR 0004), exactement comme les écrans
 * de proposition. La charge de retour porte `demandes[]` : c'est ce qui permet
 * de dire « votre réponse est partie » plutôt que « enregistré », et savoir
 * qu'une contribution est arrivée quelque part est la moitié de sa valeur.
 *
 * **La photo se téléverse d'abord**, par `televerserMedia()` du module
 * `medias`, puis son identifiant accompagne la réponse. Aucun octet ne
 * transite par les routes de ce module : le téléversement, la purge des
 * métadonnées EXIF, la vignette et l'empreinte appartiennent à `Media` et y
 * restent.
 */

import { requeteApi } from './client'

/* ------------------------------------------------------------------ */
/* Les types de la charge                                              */
/* ------------------------------------------------------------------ */

/**
 * Les neuf natures de lacune. Valeurs du modèle, **non traduites** : le
 * serveur envoie aussi `natureLibelle`, et c'est lui qui fait foi.
 */
export type NatureDeLacune =
  | 'prenom_inconnu'
  | 'nom_inconnu'
  | 'mere_inconnue'
  | 'pere_inconnu'
  | 'date_naissance_inconnue'
  | 'lieu_naissance_inconnu'
  | 'date_deces_inconnue'
  | 'lieu_deces_inconnu'
  | 'aucune_photo'

/**
 * Qui a **écrit la phrase** — pas qui a trouvé la lacune.
 *
 * La distinction est tout l'ADR 0008 : la détection appartient au code, la
 * formulation peut être déléguée. Un `gemini` ici ne veut pas dire qu'un
 * modèle a décidé qu'il manquait quelque chose.
 */
export type RedacteurDeQuestion = 'regles' | 'gemini'

export type EtatDeQuestion = 'posee' | 'inconnue' | 'differee' | 'repondue'

/** Une demande déposée par une réponse. `nature` est une valeur de `RequestKind`. */
export interface DemandeLieeAUneQuestion {
  id: number
  nature: string
}

export interface Question {
  id: number
  texte: string
  nature: NatureDeLacune
  natureLibelle: string
  redacteur: RedacteurDeQuestion
  redacteurLibelle: string
  /**
   * La réponse attendue est une **image** plutôt qu'une phrase.
   *
   * On lit ce booléen plutôt que de tester `nature === 'aucune_photo'` : c'est
   * une règle métier, elle appartient au serveur, et la recopier ici
   * obligerait à la corriger à deux endroits le jour où une seconde nature
   * appelle une image.
   */
  attendPhoto: boolean
  sujet: {
    kind: string
    id: number
    libelle: string
  }
  etat: EtatDeQuestion
  etatLibelle: string
  reports: number
  demandes: DemandeLieeAUneQuestion[]
}

export interface PageDeQuestions {
  questions: Question[]
  total: number
  /**
   * Combien des questions servies ont été **rédigées par un modèle de
   * langage**.
   *
   * L'écran le dit à qui lit. Ce n'est pas un ornement : l'ADR 0008 le décide
   * — « un membre qui découvre par hasard que ses données familiales sont
   * passées chez un tiers le vit autrement que celui à qui on l'a dit ». La
   * mention s'affiche **une fois, en tête** : répétée sous vingt questions,
   * elle cesse d'être lue.
   */
  redigeesParModele: number
}

/** Ce qu'un membre envoie en réponse. Au moins l'un des deux est requis. */
export interface EnvoiDeReponse {
  texte?: string
  /** Un média **déjà téléversé** et en attente — voir `televerserMedia()`. */
  mediaId?: number
}

/* ------------------------------------------------------------------ */
/* Les quatre routes                                                   */
/* ------------------------------------------------------------------ */

/**
 * La page. Rejoue la détection côté serveur — voir l'en-tête.
 *
 * Peut rendre `409 detection_concurrente` (`details.rejouable: true`) quand
 * deux ouvertures du même compte se croisent : **recharger suffit**, rien
 * n'est perdu.
 */
export function chargerLesQuestions(signal?: AbortSignal): Promise<PageDeQuestions> {
  return requeteApi<PageDeQuestions>('/questions', {
    ...(signal === undefined ? {} : { signal }),
  })
}

/** « Je ne sais pas » — définitif, et **rien n'entre dans l'arbre**. */
export function jeNeSaisPas(questionId: number, signal?: AbortSignal): Promise<Question> {
  return requeteApi<Question>(`/questions/${questionId}/je-ne-sais-pas`, {
    methode: 'POST',
    ...(signal === undefined ? {} : { signal }),
  })
}

/**
 * « Je vais me renseigner » — elle revient, et **de plus en plus tard**.
 *
 * Le délai double à chaque report, côté serveur. Le front n'a rien à calculer
 * et surtout rien à afficher comme promesse de date : `reports` suffit à dire
 * « vous l'avez déjà repoussée ».
 */
export function plusTard(questionId: number, signal?: AbortSignal): Promise<Question> {
  return requeteApi<Question>(`/questions/${questionId}/plus-tard`, {
    methode: 'POST',
    ...(signal === undefined ? {} : { signal }),
  })
}

/**
 * Déposer une réponse : du texte, une photo, ou les deux.
 *
 * Rend la question mise à jour, avec `demandes[]` — ce qui a été déposé dans
 * la file. Une réponse vide est refusée en `422 reponse_invalide` : ce n'est
 * pas « je ne sais pas », et l'accepter marquerait la question répondue sans
 * rien déposer.
 */
export function repondre(
  questionId: number,
  envoi: EnvoiDeReponse,
  signal?: AbortSignal,
): Promise<Question> {
  return requeteApi<Question>(`/questions/${questionId}/reponse`, {
    methode: 'POST',
    corps: envoi,
    ...(signal === undefined ? {} : { signal }),
  })
}
