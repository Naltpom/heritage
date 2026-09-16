/**
 * Les deux décisions de l'écran de connexion qui méritent d'être pures — donc
 * testables sans monter un navigateur.
 *
 * 1. **Où atterrir après une connexion réussie**, et surtout : comment ne pas
 *    obéir aveuglément au `?suite=` de l'URL.
 * 2. **Comment dire « revenez dans un quart d'heure »** à quelqu'un qui vient de
 *    se tromper cinq fois de mot de passe.
 */

import type { RouteLocationRaw } from 'vue-router'
import { routeParDefaut, type RegimeAcces } from '@/router/acces'

/**
 * Le `?suite=` est-il une destination interne acceptable ?
 *
 * La garde de route écrit `?suite=<destination>` avant de renvoyer un visiteur
 * sans session vers la connexion, et l'écran doit l'y ramener après succès
 * (§ 2 des conventions du socle). Mais **cette valeur vient de l'URL**, donc de
 * n'importe qui : un lien `…/connexion?suite=https://heritage.local.example/`
 * envoyé par courriel ferait atterrir un membre qui vient de taper son mot de
 * passe sur une page étrangère — au moment précis où il s'attend à être chez
 * lui, et où il retaperait ses identifiants sans y penser.
 *
 * Est accepté : un chemin absolu de ce site, et rien d'autre.
 *
 * Les trois refus qui ne se devinent pas :
 *
 * - `//ailleurs.example/x` est une URL **protocole-relatif** : elle commence
 *   bien par `/`, et elle sort du site ;
 * - `/\ailleurs.example` aussi — les navigateurs normalisent l'antislash en
 *   barre oblique, ce qui redonne le cas précédent après coup. On refuse donc
 *   l'antislash partout, plutôt que de tenter de normaliser à notre tour ;
 * - `/connexion…` renverrait à l'écran qu'on vient de quitter, et la garde y
 *   renverrait aussitôt : une boucle, pas une redirection.
 */
export function suiteAcceptable(suite: unknown): suite is string {
  if (typeof suite !== 'string' || suite.length === 0) return false
  if (suite.includes('\\')) return false
  if (!suite.startsWith('/')) return false
  if (suite.startsWith('//')) return false
  // Écrit sur le chemin seul : `/connexions-familiales` doit rester recevable.
  const chemin = suite.split(/[?#]/, 1)[0] ?? ''
  return chemin !== '/connexion' && !chemin.startsWith('/connexion/')
}

/**
 * Où envoyer le lecteur une fois la session ouverte.
 *
 * On **n'interprète pas ici le régime du compte** au-delà du repli : si le
 * `?suite=` désigne une route que ce compte n'a pas le droit d'ouvrir — un
 * compte en attente de validation qui revient sur `/demandes/14` —, c'est la
 * garde globale qui tranchera, et elle l'emmènera à l'écran d'état plutôt qu'à
 * la connexion (`acces.ts`). Refaire ce calcul ici donnerait deux règles d'accès
 * à maintenir, et c'est exactement ce que le socle évite en les gardant pures et
 * à un seul endroit.
 */
export function destinationApresConnexion(
  suite: unknown,
  regime: RegimeAcces,
): RouteLocationRaw {
  return suiteAcceptable(suite) ? suite : routeParDefaut(regime)
}

/**
 * Le délai d'attente, en français lisible.
 *
 * `login_throttling` plafonne à cinq tentatives par quart d'heure et rend
 * `Retry-After` en **secondes** (§ 1.9). « Nouvel essai possible dans 847
 * secondes » est exact et inutilisable : personne ne divise mentalement par
 * soixante devant un écran qui vient de le refuser. On arrondit **vers le
 * haut** — annoncer une minute quand il en reste une et demie ferait revenir
 * trop tôt, pour se faire refuser une fois de plus.
 *
 * Sans `Retry-After`, on reste vague plutôt que d'inventer un chiffre.
 */
export function delaiAvantNouvelEssai(secondes: number | null): string {
  if (secondes === null || !Number.isFinite(secondes) || secondes <= 0) {
    return 'dans quelques minutes'
  }
  if (secondes < 60) return "dans moins d'une minute"

  const minutes = Math.ceil(secondes / 60)
  if (minutes === 1) return 'dans une minute'
  if (minutes < 60) return `dans ${minutes} minutes`

  const heures = Math.ceil(minutes / 60)
  return heures === 1 ? 'dans une heure' : `dans ${heures} heures`
}
