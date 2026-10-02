/**
 * Les appels d'API de l'inscription — § 4 du contrat.
 *
 * Posé ici selon la convention de `routes.ts` : « les autres modules
 * (inscription, propositions, médias…) posent leurs propres fonctions, dans
 * `src/lib/api/<module>.ts` ». Une fonction par route, chemin écrit **sans** le
 * préfixe `/api`.
 *
 * Trois choses, propres à ce module, qui ne se devinent pas en lisant les
 * signatures — et qui expliquent pourquoi ce fichier ne « corrige » rien :
 *
 * 1. **Les trois routes publiques rendent `404`, jamais `403`**, sur un jeton
 *    inconnu, mal formé, expiré, révoqué ou déjà consommé — corps identique dans
 *    les cinq cas, même message « Ressource introuvable. » (§ 4). Un `403`
 *    confirmerait que la route existe et que le jeton a une forme valide. Aucune
 *    de ces fonctions ne tente de distinguer ces cas : elles n'en ont pas le
 *    moyen, et c'est le but.
 *
 * 2. **`POST /rattachement` ne rend jamais `429` sur son plafond par jeton.**
 *    La 6ᵉ tentative de l'heure rend `200 { "candidats": [] }` — exactement la
 *    réponse d'une recherche infructueuse, sans `Retry-After` et sans en-tête de
 *    quota (§ 4.2, exception nommée au § 15.3). C'est la seule dérogation à la
 *    convention d'erreur du projet, et elle est délibérée : une `429` rendrait
 *    les deux cas parfaitement distinguables et ferait du lien d'invitation un
 *    oracle permettant d'énumérer les personnes vivantes de l'arbre.
 *
 *    Le type de retour est donc `ResultatRattachement`, qui ne porte **que**
 *    `candidats`. Le contrat expose bien un `tentativesRestantes`, mais
 *    « uniquement pour être toujours `null` » : le type du socle ne le déclare
 *    pas, de sorte qu'aucun écran ne puisse le lire — pas même par accident.
 *
 *    Le limiteur **par IP** (30 / h), lui, rend une vraie `429` : il ne dit rien
 *    de la validité d'un jeton ni de l'existence d'une personne, seulement
 *    qu'une adresse s'acharne. Elle remonte telle quelle à l'écran, qui la passe
 *    à `analyserErreur()` comme n'importe quelle autre.
 *
 * 3. **`POST /compte` ouvre la session** (`Set-Cookie`) et rend la charge de
 *    session du § 2.2, à l'identique : le front n'enchaîne pas sur
 *    `POST /api/connexion`. Il lui reste à relire son état de session —
 *    `rafraichirSession()` — pour que le routeur et les gardes voient le nouveau
 *    régime.
 */

import { requeteApi } from './client'
import type {
  ChargeDeSession,
  EtatInscription,
  ResultatRattachement,
  VerificationJeton,
} from './types'

/**
 * § 4.1 — `POST /api/inscription/verifier`. Étape 1a : le jeton est-il
 * exploitable, et quel formulaire afficher ?
 *
 * La réponse ne porte **ni `personId`, ni `personLabel`, ni la moindre initiale**
 * de la personne pré-liée. `mode` dit seulement quel formulaire montrer ; les
 * deux modes demandent les mêmes trois champs, car un formulaire qui ne
 * réclamerait que la date en mode confirmation dirait au porteur « nous savons
 * déjà ton nom ».
 */
export function verifierJeton(jeton: string): Promise<VerificationJeton> {
  return requeteApi<VerificationJeton>('/inscription/verifier', {
    methode: 'POST',
    corps: { jeton },
    // Route `PUBLIC` : une 401 y serait un défaut de câblage, pas une session
    // perdue. Le rattrapage global n'a rien à y faire — il renverrait à la
    // connexion un visiteur en train de saisir son lien d'invitation.
    sansRattrapage: true,
  })
}

/**
 * § 4.2 — `POST /api/inscription/rattachement`. Étape 1b : la preuve de
 * connaissance.
 *
 * `birthDate` est une date **ferme** (`YYYY-MM-DD`) : c'est ce que le candidat
 * affirme savoir, pas une donnée généalogique. C'est la seule date ferme de
 * toute l'API — elle ne passe donc pas par un bloc date du § 14.
 *
 * Les deux modes envoient les trois champs. En mode confirmation, une date qui
 * ne correspond pas à la personne pré-liée rend `candidats: []`, et surtout pas
 * un « la date attendue est en 1934 ».
 */
export function rechercherRattachement(entree: {
  jeton: string
  surname: string
  givenNames: string
  birthDate: string
}): Promise<ResultatRattachement> {
  return requeteApi<ResultatRattachement>('/inscription/rattachement', {
    methode: 'POST',
    corps: {
      jeton: entree.jeton,
      surname: entree.surname,
      givenNames: entree.givenNames,
      birthDate: entree.birthDate,
    },
    sansRattrapage: true,
  })
}

/**
 * Ce que décrit, d'elle-même, une personne qui ne s'est pas trouvée dans l'arbre
 * (« Je ne me trouve pas » ou « Aucune de ces personnes » à l'étape 1b).
 *
 * L'identité est celle **déjà saisie** pour la recherche — nom de naissance,
 * prénoms dans l'ordre de l'état civil, date ferme `YYYY-MM-DD` — et la
 * description, en texte libre, dit à l'administrateur de qui elle descend.
 *
 * **Ce texte parle de personnes vivantes.** Il ne part que dans le corps de
 * `POST /inscription/compte`, n'est écrit dans aucun stockage du navigateur, ne
 * se journalise pas, et ne revient jamais au titulaire : seuls les
 * administrateurs le relisent, pour identifier ou créer la fiche.
 */
export interface DeclarationDeFamille {
  surname: string
  givenNames: string
  /** Date ferme `YYYY-MM-DD`, comme à l'étape 1b. */
  birthDate: string
  /**
   * 60 à 4 000 caractères après `trim()` — voir `components/inscription/declaration.ts`.
   * **Facultatif quand un message vocal l'accompagne** : il peut alors être vide.
   */
  description: string
  /** Le message vocal, s'il y en a un — un seul. */
  messageVocal?: MessageVocal | null
}

/**
 * Un message vocal enregistré dans le navigateur (`EnregistreurVocal.vue`).
 *
 * Le `Blob` ne vit qu'en mémoire, le temps du parcours : il part dans le corps
 * `multipart` de `POST /inscription/compte` et nulle part ailleurs.
 */
export interface MessageVocal {
  fichier: Blob
  /** Le type sans paramètres : `audio/webm`, `audio/mp4`, `audio/ogg`. */
  type: string
  /** Mesurée par le chrono : un WebM de `MediaRecorder` n'annonce pas la sienne. */
  dureeSecondes: number
}

/**
 * Les deux formes, **exclusives**, du rattachement demandé à l'étape 2 : un
 * candidat retenu (`choix`), ou une famille décrite (`declaration`). Le serveur
 * refuse les deux à la fois (`422` sur `choix`) ; le type l'interdit en amont.
 */
export type RattachementDemande =
  | { choix: string; declaration?: never }
  | { declaration: DeclarationDeFamille; choix?: never }

/**
 * § 4.3 — `POST /api/inscription/compte`. Étape 2.
 *
 * Deux formes de corps :
 *
 * - **`choix`** est un `ref` rendu par l'étape 1b, **opaque et à usage unique**,
 *   lié à la tentative qui l'a produit et valable 15 minutes. Ce n'est pas un
 *   identifiant de personne et il ne doit jamais en devenir un : expiré,
 *   inconnu, ou issu d'une autre tentative, il rend `404` — pas `422`, car
 *   confirmer « ce `ref` a existé » dirait déjà quelque chose de l'étape
 *   précédente.
 * - **`declaration`** ouvre un compte **sans fiche** : en attente, `matchKind`
 *   `a_rattacher`, régime `aucun`. Un administrateur identifiera la fiche — ou
 *   la créera — plus tard. Les erreurs de champ reviennent sous des clés
 *   pointées (`declaration.description`…), et un limiteur par adresse IP
 *   (5 comptes sans fiche par heure) rend une vraie `429`.
 *
 * **Avec un message vocal**, le corps part en `multipart` : le JSON habituel,
 * tel quel, dans le champ `donnees`, et l'enregistrement dans `messageVocal`.
 * Le serveur lit les deux formes de la même façon ; ses refus du fichier
 * reviennent sous `declaration.messageVocal`, dans le même `422`.
 *
 * Succès : `201`, jeton d'invitation consommé, `mbr_member` créé, **session
 * ouverte**. La charge rendue est celle du § 2.2.
 */
export function creerCompte(
  entree: {
    jeton: string
    email: string
    password: string
    /** Facultatif : ne part que s'il est donné. */
    telephone?: string
  } & RattachementDemande,
): Promise<ChargeDeSession> {
  // Le corps est écrit champ par champ plutôt que d'étaler `entree` : il ne part
  // que ce que le contrat attend, et jamais les deux formes à la fois.
  const vocal = entree.declaration?.messageVocal ?? null
  const rattachement =
    entree.declaration !== undefined
      ? {
          declaration: {
            surname: entree.declaration.surname,
            givenNames: entree.declaration.givenNames,
            birthDate: entree.declaration.birthDate,
            description: entree.declaration.description,
            // La durée accompagne le fichier, et seulement lui.
            ...(vocal !== null ? { dureeMessageVocal: Math.max(1, Math.round(vocal.dureeSecondes)) } : {}),
          },
        }
      : { choix: entree.choix }

  const corps = {
    jeton: entree.jeton,
    ...rattachement,
    email: entree.email,
    password: entree.password,
    ...(entree.telephone ? { telephone: entree.telephone } : {}),
  }

  return requeteApi<ChargeDeSession>('/inscription/compte', {
    methode: 'POST',
    corps: vocal !== null ? corpsAvecMessageVocal(corps, vocal) : corps,
    sansRattrapage: true,
  })
}

/**
 * Le corps `multipart` : le JSON dans `donnees`, le fichier dans `messageVocal`.
 * Exposé pour les tests — c'est la forme que le serveur attend, au champ près.
 */
export function corpsAvecMessageVocal(corps: Record<string, unknown>, vocal: MessageVocal): FormData {
  const formulaire = new FormData()
  formulaire.append('donnees', JSON.stringify(corps))
  // Le nom est indicatif : le serveur ne croit que les octets.
  const extension = vocal.type.includes('mp4') ? 'm4a' : vocal.type.includes('ogg') ? 'ogg' : 'webm'
  formulaire.append('messageVocal', vocal.fichier, `message-vocal.${extension}`)
  return formulaire
}

/**
 * § 4.4 — `GET /api/inscription/etat`. Étape 3, et écran permanent d'un compte
 * en attente de validation.
 *
 * Accès `ATTENTE` : la session existe déjà. **Aucune clé `personId` ni
 * `personLabel`** tant que `status` vaut `en_attente` — le candidat a vu
 * « P. Romarin, 1980 » à l'étape 1b, il ne reverra pas ce libellé ici, et
 * surtout pas le `displayName` réel de la fiche.
 *
 * Aucun chiffre de l'arbre : un compte en attente n'en lit plus aucun (ADR 0003,
 * révision du 2026-10-01 — `cequejevois` est retiré).
 *
 * `matchKind` vaut `a_rattacher` pour un compte ouvert sans fiche, et
 * `portraitEnAttente` dit, tant que le compte attend, si sa photo a été déposée :
 * elle ne deviendra le portrait de la fiche qu'au rattachement.
 */
export function lireEtatInscription(): Promise<EtatInscription> {
  return requeteApi<EtatInscription>('/inscription/etat')
}
