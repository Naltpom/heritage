/**
 * La connexion avec Google, côté heritage.
 *
 * Heritage ne charge aucun script tiers (CSP `script-src 'self'`) : pas de bouton
 * Google Identity Services ici. On **navigue** vers `/api/connexion/google/debut`,
 * le serveur renvoie vers Google, puis Google vers `/api/connexion/google/retour`,
 * qui ouvre la session et renvoie à la suite — ou à `/connexion?google=…`.
 */

import { requeteApi } from './client'

export interface ConfigurationGoogle {
  clientId: string | null
  /** La redirection est configurée (secret du client et adresse publique). */
  redirection: boolean
}

export function configurationGoogle(): Promise<ConfigurationGoogle> {
  return requeteApi<ConfigurationGoogle>('/connexion/google', { sansRattrapage: true })
}

/** L'adresse à ouvrir pour se connecter avec Google, puis revenir sur `suite`. */
export function adresseDeConnexionGoogle(suite: unknown): string {
  const chemin = typeof suite === 'string' && /^\/(?![/\\])/.test(suite) ? suite : '/'
  return `/api/connexion/google/debut?suite=${encodeURIComponent(chemin)}`
}

/** Le message à afficher au retour d'un essai qui n'a pas abouti (`?google=…`). */
export function messageDuRetourGoogle(code: unknown): string | null {
  switch (code) {
    case 'inconnu':
      return 'Aucun compte heritage n’utilise cette adresse Google. Connectez-vous avec votre mot de passe, ou demandez une invitation.'
    case 'suspendu':
      return 'Ce compte est suspendu. Contactez l’administrateur de l’arbre.'
    case 'echec':
      return 'La connexion avec Google n’a pas abouti. Réessayez.'
    default:
      return null
  }
}
