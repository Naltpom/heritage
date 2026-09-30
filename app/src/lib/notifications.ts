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

/* ------------------------------------------------------------------ */
/* Ce que la cloche affiche — sans navigateur, donc testable           */
/* ------------------------------------------------------------------ */

/**
 * Le type d'une notification, dit en une étiquette.
 *
 * Le serveur envoie déjà un titre et un texte en français ; l'étiquette ne les
 * remplace pas, elle les **classe** : dans une liste de dix, « Demande refusée »
 * se repère avant d'avoir lu la phrase. Un type inconnu (ajouté côté serveur
 * après ce front) retombe sur « Notification » — jamais sur le code brut
 * `snake_case`, qui n'est pas un mot.
 */
const LIBELLES_DE_TYPE: Readonly<Record<string, string>> = {
  demande_soumise: 'Nouvelle demande',
  demande_validee: 'Demande acceptée',
  demande_refusee: 'Demande refusée',
  demande_caduque: 'Demande dépassée',
  doublon_probable: 'Doublon probable',
  invitation_utilisee: 'Invitation utilisée',
  compte_en_attente: 'Compte à valider',
  media_valide: 'Média accepté',
  media_refuse: 'Média refusé',
  tirage_effectue: 'Tirage au sort',
}

export function libelleDeType(kind: string): string {
  return LIBELLES_DE_TYPE[kind] ?? 'Notification'
}

/** Le ton de l'étiquette : un refus ou un conflit se voit, le reste est neutre. */
export function tonDeType(kind: string): 'alerte' | 'ok' | 'neutre' {
  if (kind === 'demande_refusee' || kind === 'media_refuse' || kind === 'demande_caduque' || kind === 'doublon_probable') {
    return 'alerte'
  }
  if (kind === 'demande_validee' || kind === 'media_valide') return 'ok'
  return 'neutre'
}

/**
 * Le chiffre de la pastille. Au-delà de neuf, « 9+ » : la pastille tient dans
 * un rond de 18 px, et « 37 » n'apprend rien de plus que « beaucoup ».
 * Zéro ne s'affiche pas — `null` dit « pas de pastille ».
 */
export function texteCompteur(nonLues: number): string | null {
  if (!Number.isFinite(nonLues) || nonLues <= 0) return null
  return nonLues > 9 ? '9+' : String(Math.floor(nonLues))
}

/** L'étiquette du bouton pour un lecteur d'écran : le **vrai** nombre, pas « 9+ ». */
export function etiquetteCloche(nonLues: number): string {
  if (nonLues <= 0) return 'Notifications, aucune non lue'
  return nonLues === 1 ? 'Notifications, 1 non lue' : `Notifications, ${nonLues} non lues`
}

const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'] as const

/**
 * « À l'instant », « il y a 5 min », « il y a 3 h », « hier », « il y a 4 j »,
 * puis la date : « 12 sept. », et l'année quand ce n'est pas celle en cours.
 *
 * `maintenant` est un paramètre et non `new Date()` : c'est ce qui rend la
 * fonction testable sans simuler l'horloge. Une date illisible rend une chaîne
 * vide plutôt que « NaN » — la ligne reste lisible.
 */
export function dateRelative(iso: string, maintenant: Date): string {
  const date = new Date(iso)
  const t = date.getTime()
  if (Number.isNaN(t)) return ''
  const secondes = Math.max(0, Math.round((maintenant.getTime() - t) / 1000))
  if (secondes < 60) return 'à l’instant'
  const minutes = Math.floor(secondes / 60)
  if (minutes < 60) return `il y a ${minutes} min`
  const heures = Math.floor(minutes / 60)
  if (heures < 24) return `il y a ${heures} h`

  // Au-delà d'un jour, on compte en jours **calendaires** : une notification
  // d'hier soir 23 h lue ce matin 8 h est « hier », pas « il y a 9 h ».
  const jourDe = (d: Date): number => new Date(d.getFullYear(), d.getMonth(), d.getDate(), 12).getTime()
  const jours = Math.round((jourDe(maintenant) - jourDe(date)) / 86_400_000)
  if (jours <= 1) return 'hier'
  if (jours < 7) return `il y a ${jours} j`
  const base = `${date.getDate() === 1 ? '1er' : String(date.getDate())} ${MOIS_COURTS[date.getMonth()]}`
  return date.getFullYear() === maintenant.getFullYear() ? base : `${base} ${date.getFullYear()}`
}
