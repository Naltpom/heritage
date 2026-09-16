/**
 * Reconnaître un lien d'invitation refusé — et s'arrêter là.
 *
 * Les trois routes publiques du § 4 rendent **`404`, jamais `403`**, sur un
 * jeton inconnu, mal formé, expiré, révoqué ou déjà consommé. Corps identique
 * dans les cinq cas, même message. L'écran n'a donc qu'une seule chose à savoir
 * — « ce lien ne marche pas » — et il n'a aucun moyen d'en savoir plus.
 *
 * **C'est délibéré, et l'écran doit le respecter plutôt que de le combler.** Un
 * `403` confirmerait que la route existe et que le jeton a une forme valide ; un
 * message « votre invitation a expiré » distinguerait un jeton ayant existé d'un
 * jeton inventé, ce qui revient au même. Aucune formulation de l'interface ne
 * doit laisser entendre que le lien *aurait pu* être valide : ni « expiré », ni
 * « déjà utilisé », ni « demandez-en un nouveau » — cette dernière phrase
 * supposant qu'il y avait bien quelque chose à demander.
 *
 * Et **rien à réessayer** : le même jeton donnera le même `404`. Un bouton
 * « Réessayer » ferait tourner en rond quelqu'un dont le seul recours est
 * humain — écrire à la personne qui lui a envoyé l'invitation.
 */

import { estErreurApi } from '@/lib/api'

/**
 * L'erreur signifie-t-elle « ce lien n'est pas exploitable » ?
 *
 * Le test porte sur le **statut** et non sur le code : une `404` du routeur
 * arrive avec le code du client (`client_reponse_illisible`) quand l'enveloppe
 * n'est pas celle du § 15.1, et elle veut dire exactement la même chose pour le
 * candidat qui a collé un lien tronqué.
 */
export function estLienNonValide(valeur: unknown): boolean {
  return estErreurApi(valeur) && valeur.statut === 404
}

/**
 * Extrait le jeton d'une route d'entrée, sous les **deux** formes qu'il peut
 * prendre.
 *
 * Le contrat bâtit le lien d'invitation avec le jeton **dans le chemin** —
 * `https://heritage.local/inscription/kJ8s-2Qb…` (§ 3.2) — tandis que le
 * commentaire du routeur décrit la forme `?jeton=…`. Les deux se rencontreront :
 * les liens déjà envoyés portent la forme du serveur, et rien n'empêche un
 * administrateur de recopier l'autre. Les accepter toutes deux coûte trois
 * lignes ; n'en accepter qu'une ferait tomber la moitié des invitations sur la
 * page « introuvable », sans que personne comprenne pourquoi.
 *
 * La chaîne rendue n'est **pas** validée ici : sa forme ne nous regarde pas, et
 * la juger côté client rendrait « mal formé » distinguable de « inconnu ».
 * C'est le serveur qui répond `404` dans les deux cas.
 */
export function jetonDeLaRoute(source: {
  params: Record<string, unknown>
  query: Record<string, unknown>
}): string | null {
  const brut = source.params['jeton'] ?? source.query['jeton']
  if (typeof brut !== 'string') return null
  const nettoye = brut.trim()
  return nettoye.length > 0 ? nettoye : null
}
