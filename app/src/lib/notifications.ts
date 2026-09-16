/**
 * Résolution du lien d'une notification.
 *
 * Une notification porte `linkRoute` (un **nom de route du front**) et
 * `linkParams`, jamais une URL construite : « une refonte du routage front ne
 * casse pas les notifications d'hier » (§ 8 du contrat).
 *
 * D'où la règle que ce module existe pour tenir : **une route inconnue n'est pas
 * un lien, et ce n'est pas une erreur.** Une notification écrite il y a six mois
 * peut désigner un écran qui n'existe plus ; elle doit rester lisible, sans
 * lien, sans message d'avertissement et sans exception dans la console.
 * `routeur.resolve()` sur un nom inconnu **lève** — c'est ce qu'on attrape ici.
 */

import type { RouteLocationRaw, Router } from 'vue-router'
import type { Notification } from '@/lib/api'

export function lienDeNotification(
  routeur: Router,
  notification: Notification,
): RouteLocationRaw | null {
  const nom = notification.linkRoute
  if (nom === null || nom.length === 0) return null
  if (!routeur.hasRoute(nom)) return null

  const cible: RouteLocationRaw = { name: nom, params: notification.linkParams ?? {} }
  try {
    // `hasRoute` ne dit pas si les paramètres suffisent : une route à `:id`
    // résolue sans `id` lève aussi. On vérifie en résolvant réellement.
    routeur.resolve(cible)
    return cible
  } catch {
    return null
  }
}
