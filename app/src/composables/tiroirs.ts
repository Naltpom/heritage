/**
 * Les deux tiroirs du mobile : le menu de navigation et la barre d'outils.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Deux tiroirs ouverts en même temps ne sont pas un défaut à éviter : c'est
 * un état qu'on ne sait pas écrire.** L'état est une seule valeur parmi trois,
 * et non deux booléens. Deux booléens rendent représentable `{menu: true,
 * outils: true}` — les deux panneaux se recouvrant, l'un par la gauche et
 * l'autre par la droite — et il faut alors se souvenir de fermer l'autre à
 * chaque ouverture, à chaque endroit.
 *
 * **Le seuil est écrit une seule fois.** `REQUETE_MOBILE` est, caractère pour
 * caractère, ce que Tailwind produit pour la variante `max-md:`. CSS et
 * JavaScript ne peuvent donc pas diverger — y compris sous zoom texte, où
 * `48rem` ne vaut plus 768 px et où un seuil recopié en pixels mentirait.
 *
 * **Tout est pur, et sans DOM.** La liaison au navigateur passe par
 * {@link surveillerLargeur}, à qui l'on donne l'objet à écouter. Un test lui
 * tend un objet écrit à la main ; il n'a besoin ni de jsdom ni de `matchMedia`.
 * ────────────────────────────────────────────────────────────────────
 */

/** La requête média du mobile — exactement celle que compile `max-md:`. */
export const REQUETE_MOBILE = '(width < 48rem)'

export type TiroirOuvert = 'aucun' | 'menu' | 'outils'

export interface EtatTiroirs {
  ouvert: TiroirOuvert
  /** Combien d'écrans ont déclaré une barre d'outils. C'est un compteur et non
   *  un booléen : pendant une transition de route, l'ancien écran se démonte
   *  après que le nouveau s'est monté, et un booléen retomberait à `false` juste
   *  après avoir été mis à `true` — le bouton disparaîtrait au changement de
   *  page, sur une page qui a pourtant une barre. */
  barres: number
}

export function creerEtatTiroirs(): EtatTiroirs {
  return { ouvert: 'aucun', barres: 0 }
}

/** Ouvre le tiroir demandé, ou le referme s'il l'était déjà. L'autre se ferme
 *  du même geste, sans qu'on ait à le dire. */
export function basculer(etat: EtatTiroirs, cible: Exclude<TiroirOuvert, 'aucun'>): TiroirOuvert {
  etat.ouvert = etat.ouvert === cible ? 'aucun' : cible
  return etat.ouvert
}

export function fermer(etat: EtatTiroirs): TiroirOuvert {
  etat.ouvert = 'aucun'
  return etat.ouvert
}

export function declarerOutils(etat: EtatTiroirs): void {
  etat.barres += 1
}

export function retirerOutils(etat: EtatTiroirs): void {
  etat.barres = Math.max(0, etat.barres - 1)
  // Un écran sans barre d'outils ne doit pas laisser derrière lui un tiroir
  // d'outils ouvert, dont le bouton vient de disparaître : il serait alors
  // impossible à refermer autrement qu'en touchant le voile.
  if (etat.barres === 0 && etat.ouvert === 'outils') etat.ouvert = 'aucun'
}

export function outilsDisponibles(etat: EtatTiroirs): boolean {
  return etat.barres > 0
}

/** Le strict nécessaire d'une `MediaQueryList`, pour qu'un test puisse en
 *  tendre une écrite à la main. */
export interface ListeMedia {
  matches: boolean
  addEventListener: (type: 'change', ecouteur: (ev: { matches: boolean }) => void) => void
  removeEventListener: (type: 'change', ecouteur: (ev: { matches: boolean }) => void) => void
}

/**
 * Prévient quand la fenêtre passe au-dessus du seuil, et rend de quoi s'en
 * détacher.
 *
 * **Pourquoi ce fil est nécessaire alors que le CSS fait déjà le travail.** Un
 * tiroir ouvert à 400 px puis élargi à 1200 px est masqué par `max-md:hidden`,
 * donc invisible — mais l'état, lui, dit toujours « ouvert ». La touche Échap
 * resterait captée sur un tiroir que personne ne voit, et le tiroir
 * réapparaîtrait au prochain rétrécissement. Le CSS cache ; il ne referme pas.
 */
export function surveillerLargeur(
  liste: ListeMedia,
  quand: (mobile: boolean) => void,
): () => void {
  const ecouteur = (ev: { matches: boolean }): void => {
    quand(ev.matches)
  }
  liste.addEventListener('change', ecouteur)
  return () => {
    liste.removeEventListener('change', ecouteur)
  }
}
