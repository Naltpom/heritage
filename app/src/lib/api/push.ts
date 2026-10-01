/**
 * Les appels d'API des notifications **sur le téléphone** (Web Push).
 *
 * Posé ici selon la convention de `routes.ts` : une fonction par route, chemin
 * écrit **sans** le préfixe `/api`. La logique — quand s'abonner, quand se
 * réabonner — vit dans `composables/usePush.ts` ; ce fichier ne fait que parler
 * au serveur.
 *
 * Trois choses du contrat qu'il faut avoir en tête en lisant les signatures :
 *
 * 1. **La clé publique ne change pas d'un déploiement à l'autre.** Le serveur la
 *    génère une fois et la garde en base (ou la lit dans l'environnement). Si
 *    elle changeait, chaque abonnement existant deviendrait inutilisable : c'est
 *    exactement « le jeton qui casse à chaque mise en prod ». Le front la relit
 *    quand même à chaque démarrage et se réabonne si elle diffère — c'est la
 *    ceinture sous les bretelles.
 * 2. **`PUT /push/abonnement` est idempotent** (upsert par `endpoint`) : le front
 *    le renvoie à chaque démarrage sans se demander s'il l'a déjà fait. Un
 *    endpoint déjà connu sous un autre compte (téléphone partagé, changement de
 *    session) est **réattribué** au compte courant.
 * 3. **`DELETE` ne touche que ses propres abonnements**, et un endpoint inconnu
 *    rend quand même `204` : se désabonner deux fois n'est pas une erreur.
 */

import { requeteApi } from './client'

/** Un abonnement tel que `PushSubscription.toJSON()` le décrit, réduit à ce que
 *  le serveur exige : l'adresse du service push et les deux clés de chiffrement. */
export interface AbonnementPush {
  endpoint: string
  keys: { p256dh: string; auth: string }
}

/** `GET /api/push/cle` — la clé publique VAPID, en base64url non paddé
 *  (point P-256 non compressé, 65 octets). Ouverte à un compte en attente. */
export function lireClePush(): Promise<{ clePublique: string }> {
  return requeteApi<{ clePublique: string }>('/push/cle')
}

/** `PUT /api/push/abonnement` → `204`. `400` si le corps est invalide — un
 *  endpoint qui n'est pas en `https`, par exemple. */
export async function enregistrerAbonnementPush(abonnement: AbonnementPush): Promise<void> {
  await requeteApi<null>('/push/abonnement', {
    methode: 'PUT',
    corps: { endpoint: abonnement.endpoint, keys: { p256dh: abonnement.keys.p256dh, auth: abonnement.keys.auth } },
  })
}

/** `DELETE /api/push/abonnement` → `204`, idempotent. */
export async function supprimerAbonnementPush(endpoint: string): Promise<void> {
  await requeteApi<null>('/push/abonnement', { methode: 'DELETE', corps: { endpoint } })
}
