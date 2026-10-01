/**
 * Une seule fenêtre **automatique** à la fois.
 *
 * Deux fenêtres s'ouvrent d'elles-mêmes, sans que le lecteur les ait demandées :
 * « Activer les notifications » (`usePush`) et « Se connecter plus vite la
 * prochaine fois ? » (`useClesDAcces`). Deux `<dialog>` modaux empilés, c'est
 * un lecteur qui ferme le premier sans le lire pour atteindre le second — et
 * qui refuse les deux.
 *
 * L'ordre est fixé : **la clé d'accès d'abord**, juste après une connexion par
 * mot de passe — c'est le moment où l'on vient de taper ce mot de passe, celui
 * où l'offre de ne plus le faire a du sens. Les notifications **ensuite**, une
 * fois la première réglée (ou au chargement suivant).
 *
 * Le mécanisme est un simple drapeau : la clé d'accès le lève dès la connexion
 * réussie — **avant** de savoir si elle s'ouvrira, ce qui demande une question
 * asynchrone au navigateur — et le baisse quand elle a répondu ou renoncé. Le
 * pilote des notifications le lit dans sa décision, donc s'abstient tant qu'il
 * est levé et repart de lui-même quand il retombe.
 *
 * Module sans dépendance (Vue seulement) : `usePush` l'importe, et ses tests
 * simulent l'API et la session — un import de plus ne doit rien y réveiller.
 */
import { readonly, shallowRef, type Ref } from 'vue'

const cleDAccesEnPremier = shallowRef(false)

/** Vrai tant que l'invitation à la clé d'accès est en attente ou ouverte. */
export const invitationCleDAccesEnCours: Readonly<Ref<boolean>> = readonly(cleDAccesEnPremier)

export function reserverPourLaCleDAcces(): void {
  cleDAccesEnPremier.value = true
}

export function libererApresLaCleDAcces(): void {
  cleDAccesEnPremier.value = false
}
