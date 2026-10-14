/**
 * Partager un lien : la feuille de partage du système quand le navigateur en
 * a une, le presse-papiers sinon.
 *
 * Deux écrans s'en servent : le lien d'un invité sans compte
 * (`LienDeLInvite.vue`) et le bouton « Partager » de la fiche
 * (`PartageFiche.vue`). Les pièges sont les mêmes, et on ne les écrit qu'une
 * fois :
 *
 * - **`navigator.share` absent** sur la plupart des navigateurs de bureau :
 *   on copie, et l'écran le dit (« Lien copié ») ;
 * - **`AbortError`** : la personne a fermé la feuille de partage. Ce n'est pas
 *   une panne, on ne dit rien ;
 * - **un autre refus** de `share` (`NotAllowedError` hors geste de
 *   l'utilisateur, `DataError`…) : on se rabat sur la copie ; et si la copie
 *   est refusée à son tour (contexte non sécurisé, permission), l'écran
 *   affiche l'adresse, sélectionnable. C'est le chemin de repli, et il suffit.
 *
 * Le navigateur arrive en paramètre (`Navigateur`) : les tests tournent sans
 * `window`, et chaque cas s'y prouve avec une doublure.
 */
import type { RouteLocationRaw, Router } from 'vue-router'

/** Ce qu'on lit de `navigator` — et rien d'autre. */
export interface Navigateur {
  share?: ((donnees: ShareData) => Promise<void>) | undefined
  clipboard?: Pick<Clipboard, 'writeText'> | undefined
}

function navigateurCourant(): Navigateur | null {
  return typeof navigator === 'undefined' ? null : (navigator as Navigateur)
}

/** Le navigateur sait-il ouvrir la feuille de partage du système ? */
export function peutPartager(nav: Navigateur | null = navigateurCourant()): boolean {
  return typeof nav?.share === 'function'
}

/** Copie `texte` ; rend `false` si le presse-papiers est absent ou refusé. */
export async function copierTexte(texte: string, nav: Navigateur | null = navigateurCourant()): Promise<boolean> {
  if (typeof nav?.clipboard?.writeText !== 'function') return false
  try {
    await nav.clipboard.writeText(texte)
    return true
  } catch {
    return false
  }
}

export interface LienAPartager {
  titre: string
  url: string
  /** Le texte qui accompagne le lien dans la feuille de partage. */
  texte?: string
}

export type IssueDuPartage =
  /** La feuille de partage a été ouverte, et la personne a choisi où envoyer. */
  | 'partage'
  /** Le lien est dans le presse-papiers. */
  | 'copie'
  /** La personne a fermé la feuille de partage : rien à dire. */
  | 'annule'
  /** Ni partage ni copie : l'écran affiche l'adresse. */
  | 'echec'

function estAnnulation(erreur: unknown): boolean {
  return erreur !== null && typeof erreur === 'object' && (erreur as { name?: unknown }).name === 'AbortError'
}

/** La feuille de partage seule, sans repli. */
export async function partager(
  lien: LienAPartager,
  nav: Navigateur | null = navigateurCourant(),
): Promise<'partage' | 'annule' | 'echec'> {
  if (nav === null || typeof nav.share !== 'function') return 'echec'
  const donnees: ShareData = { title: lien.titre, url: lien.url }
  if (lien.texte !== undefined) donnees.text = lien.texte
  try {
    await nav.share(donnees)
    return 'partage'
  } catch (erreur) {
    return estAnnulation(erreur) ? 'annule' : 'echec'
  }
}

/** La feuille de partage si elle existe, le presse-papiers sinon — ou si elle échoue. */
export async function partagerOuCopier(
  lien: LienAPartager,
  nav: Navigateur | null = navigateurCourant(),
): Promise<IssueDuPartage> {
  if (peutPartager(nav)) {
    const issue = await partager(lien, nav)
    if (issue !== 'echec') return issue
  }
  return (await copierTexte(lien.url, nav)) ? 'copie' : 'echec'
}

/**
 * L'adresse **absolue** d'une route du front : `resolve()` du routeur — qui
 * connaît la base et l'encodage des paramètres —, posé sur l'origine.
 *
 * Rien d'autre ne voyage : ni l'onglet ouvert, ni une ancre, ni une donnée de
 * la charge. Qui reçoit le lien doit se connecter, et ne voit que ce que son
 * propre cercle lui ouvre.
 */
export function adresseAbsolue(
  routeur: Pick<Router, 'resolve'>,
  cible: RouteLocationRaw,
  origine: string = typeof location === 'undefined' ? '' : location.origin,
): string {
  const href = routeur.resolve(cible).href
  // Hors navigateur (rendu serveur), pas d'origine : le chemin seul.
  return origine === '' ? href : new URL(href, origine).href
}
