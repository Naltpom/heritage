/**
 * Les appels d'API du socle — session, graphe, notifications.
 *
 * Ce fichier ne couvre **que** ce dont la fondation a besoin. Les autres
 * modules (inscription, propositions, médias, chronologie, administration)
 * posent leurs propres fonctions, dans `src/lib/api/<module>.ts`, en appelant
 * `requeteApi` de la même façon. Voir `app/README.md`, section « Appeler l'API ».
 *
 * Convention : le chemin est écrit **sans** le préfixe `/api`, que le client
 * ajoute (§ 1.1). Une fonction par route, nommée en français, rendant le type
 * du contrat.
 */

import { requeteApi } from './client'
import { oublierEtag, requeteRevalidee, type ResultatRevalide } from './revalidation'
import type {
  ChargeDeSession,
  Graphe,
  ListePaginee,
  Notification,
  PartageNaissance,
  PartageNaissanceEnregistre,
} from './types'

/* ------------------------------------------------------------------ */
/* § 2 — session                                                       */
/* ------------------------------------------------------------------ */

/**
 * `GET /api/session`. **`sansRattrapage`** : au démarrage, une 401 est la
 * réponse normale d'un visiteur anonyme, pas une déconnexion — déclencher le
 * rattrapage global ici renverrait à la connexion depuis la page de connexion.
 */
export function lireSession(): Promise<ChargeDeSession> {
  return requeteApi<ChargeDeSession>('/session', { sansRattrapage: true })
}

/** `POST /api/connexion`. Les clés du corps sont celles de `json_login`
 *  (`username_path: email`, `password_path: password`) : ne pas les franciser. */
export function seConnecter(email: string, password: string): Promise<ChargeDeSession> {
  return requeteApi<ChargeDeSession>('/connexion', {
    methode: 'POST',
    corps: { email, password },
    // Les 401 de cette route sont `identifiants_invalides` / `compte_suspendu` :
    // des réponses métier, pas une session perdue.
    sansRattrapage: true,
  })
}

/** `POST /api/deconnexion` — interceptée par le listener `logout` du pare-feu. */
export function seDeconnecter(): Promise<{ deconnecte: boolean }> {
  return requeteApi<{ deconnecte: boolean }>('/deconnexion', { methode: 'POST' })
}

/**
 * `POST /api/session/mot-de-passe`.
 *
 * Un mot de passe actuel erroné rend **`422` sur `champs.motDePasseActuel`**, et
 * non `401` : le compte est authentifié, c'est la donnée soumise qui est mauvaise
 * (§ 2.1). L'écran affiche donc une erreur de champ, il ne déconnecte pas.
 *
 * **Toutes les clés d'accès du compte tombent** avec l'ancien mot de passe
 * (`clesDAccesRetirees` dit combien, § 2.4) : une clé plantée par celui qui
 * tenait la session rouvrirait sinon le compte aussitôt.
 */
export function changerMotDePasse(
  motDePasseActuel: string,
  nouveauMotDePasse: string,
): Promise<{ motDePasseChange: boolean; clesDAccesRetirees?: number }> {
  return requeteApi<{ motDePasseChange: boolean; clesDAccesRetirees?: number }>('/session/mot-de-passe', {
    methode: 'POST',
    corps: { motDePasseActuel, nouveauMotDePasse },
  })
}

/**
 * `PUT /api/session/partage-naissance` — ADR 0016, réciprocité des vivants.
 *
 * Les trois cases, toujours les trois. Le choix change ce que le lecteur voit
 * des autres : après l'appel, relire la session (`rafraichirSession()`), qui
 * périme aussi l'`ETag` du graphe.
 *
 * `409 compte_non_rattache` sans ligne membre ; `409 vue_comme_membre_active`
 * sous la bascule « voir comme » — on ne choisit pas à la place du membre ;
 * `409 partage_verrouille` pour une composante partagée il y a moins de trente
 * jours qu'on voudrait décocher (`details.jusquAu`, par composante) : la
 * retirer plus tôt passe par une demande RGPD. Partager est toujours immédiat.
 */
export function modifierPartageNaissance(partage: PartageNaissance): Promise<PartageNaissanceEnregistre> {
  return requeteApi<PartageNaissanceEnregistre>('/session/partage-naissance', {
    methode: 'PUT',
    corps: { annee: partage.annee, mois: partage.mois, jour: partage.jour },
  })
}

/**
 * `POST /api/session/courriel` — § 2.5, la demande.
 *
 * Rien ne change à cet appel : un lien part vers la **nouvelle** adresse, et
 * c'est le clic qui change le courriel. Le mot de passe actuel est exigé pour
 * la même raison qu'au § 2.1, et son refus est un `422` sur
 * `champs.motDePasseActuel`, pas une `401`.
 *
 * `409 courriel_non_envoyable` : aucun SMTP n'est configuré. Ce n'est pas une
 * panne passagère, l'écran doit le dire tel quel.
 */
export function demanderChangementDeCourriel(
  motDePasseActuel: string,
  nouveauCourriel: string,
): Promise<{ courrielEnvoye: boolean; expiresAt: string }> {
  return requeteApi<{ courrielEnvoye: boolean; expiresAt: string }>('/session/courriel', {
    methode: 'POST',
    corps: { motDePasseActuel, nouveauCourriel },
  })
}

/**
 * `POST /api/session/courriel/confirmer` — § 2.5, le clic sur le lien.
 *
 * Route **publique** : on clique depuis le courriel, souvent sur un autre
 * appareil, sans session. Tout jeton douteux — inconnu, périmé, déjà consommé,
 * mal formé — rend `404` avec le même corps, et l'écran ne cherche pas à
 * distinguer ces cas : il n'en a pas le moyen, et c'est le but.
 *
 * Après un succès, la session qui a confirmé — s'il y en avait une sur cet
 * appareil — continue sous la nouvelle adresse ; celles ouvertes ailleurs sous
 * l'ancienne tombent à leur requête suivante (§ 2.3, mesuré).
 */
export function confirmerChangementDeCourriel(
  jeton: string,
): Promise<{ courrielChange: boolean }> {
  return requeteApi<{ courrielChange: boolean }>('/session/courriel/confirmer', {
    methode: 'POST',
    corps: { jeton },
    sansRattrapage: true,
  })
}

/* ------------------------------------------------------------------ */
/* § 5.2 — le graphe                                                   */
/* ------------------------------------------------------------------ */

/** Clé de revalidation du graphe. Une seule route porte un `ETag` (§ 1.8). */
export const CLE_ETAG_GRAPHE = 'arbre.graphe'

/**
 * `GET /api/arbre/graphe`, revalidé.
 *
 * Quand revalider, d'après le contrat (§ 5.2, étape 4) : au montage de la vue,
 * au retour d'onglet, et après **toute** action qui écrit. Pas de sondage en
 * boucle — « à cette échelle, le graphe change quelques fois par semaine ».
 *
 * `inchange` ⇒ le canevas reste **en place**, on ne le reconstruit pas et on ne
 * le vide pas. `nouveau` ⇒ reconstruction **complète**, pas de réconciliation
 * maison (ADR 0007, règle 3).
 */
export function chargerGraphe(): Promise<ResultatRevalide<Graphe>> {
  return requeteRevalidee<Graphe>(CLE_ETAG_GRAPHE, '/arbre/graphe')
}

/**
 * Déclare que le graphe a pu changer : le prochain `chargerGraphe()` rendra un
 * `200` et non un `304`.
 *
 * À appeler après toute écriture. La réponse de validation porte d'ailleurs la
 * nouvelle `empreinte` de l'arbre, « pour savoir que son `ETag` de graphe est
 * périmé sans attendre une revalidation » (§ 6.8) — mais l'empreinte seule ne
 * suffit pas comme `ETag` (voir `revalidation.ts`), alors on oublie simplement.
 */
export function invaliderGraphe(): void {
  oublierEtag(CLE_ETAG_GRAPHE)
}

/* ------------------------------------------------------------------ */
/* § 8 — notifications                                                 */
/* ------------------------------------------------------------------ */

/** `GET /api/notifications/compteur` — la requête la plus chaude de l'API :
 *  elle ne rend qu'un nombre, et on ne lui en demande pas plus. */
export function compterNotificationsNonLues(): Promise<{ nonLues: number }> {
  return requeteApi<{ nonLues: number }>('/notifications/compteur')
}

export function listerNotifications(options: {
  nonLues?: boolean
  page?: number
  parPage?: number
}): Promise<ListePaginee<Notification>> {
  return requeteApi<ListePaginee<Notification>>('/notifications', {
    requete: {
      nonLues: options.nonLues === true ? 1 : undefined,
      page: options.page,
      parPage: options.parPage,
    },
  })
}

export function marquerNotificationLue(id: number): Promise<Notification> {
  return requeteApi<Notification>(`/notifications/${id}/lue`, { methode: 'POST' })
}

export function marquerToutesNotificationsLues(): Promise<{ marquees: number }> {
  return requeteApi<{ marquees: number }>('/notifications/lues', { methode: 'POST' })
}
