/**
 * La règle de mot de passe, vérifiée **avant** l'envoi.
 *
 * Douze caractères minimum (§ 6.1 du dossier, § 4.3 du contrat). Le serveur
 * refuse en `422` avec `champs: {"password": "…"}` — et c'est lui qui fait foi.
 * Ce module ne le remplace pas ; il évite un aller-retour qui reviendrait dire
 * la même chose, et il permet de le dire **pendant la saisie** plutôt qu'après
 * l'envoi du formulaire.
 *
 * Deux précautions, sans lesquelles ce genre de garde fait plus de mal que de
 * bien :
 *
 * - **Le message du serveur reste prioritaire à l'affichage.** Si les deux
 *   règles divergeaient un jour — la longueur est un réglage côté serveur —,
 *   c'est celle qui refuse réellement que le candidat doit lire. L'écran
 *   n'affiche le message d'ici que tant que le serveur n'a rien dit.
 * - **On compte les points de code, pas les unités UTF-16.** `"é"` composé et
 *   un emoji comptent chacun pour un caractère aux yeux du candidat comme à
 *   ceux du validateur de Symfony, alors que `"👋".length` vaut 2 en
 *   JavaScript. Refuser un mot de passe que le serveur accepterait serait le
 *   pire des deux mondes : la barrière du front serait plus stricte que la vraie
 *   règle, sans que personne puisse le diagnostiquer depuis l'écran.
 */

/** § 4.3 : douze caractères. Nommée pour que le chiffre ne se recopie pas. */
export const LONGUEUR_MINIMALE = 12

/** Nombre de caractères tel qu'un lecteur les compte (points de code). */
export function longueurVisible(valeur: string): number {
  return [...valeur].length
}

/**
 * Le message à afficher sous le champ, ou `null` si le mot de passe convient.
 *
 * Le message dit **ce qu'il manque**, pas seulement que c'est refusé : « il
 * manque 4 caractères » se corrige, « mot de passe invalide » se subit. Le
 * destinataire est un membre d'une famille devant un formulaire, pas un
 * utilisateur d'outil.
 */
export function erreurMotDePasse(valeur: string): string | null {
  if (valeur.length === 0) return 'Choisissez un mot de passe.'

  const longueur = longueurVisible(valeur)
  if (longueur >= LONGUEUR_MINIMALE) return null

  const manquants = LONGUEUR_MINIMALE - longueur
  return manquants === 1
    ? `Encore un caractère : il en faut ${LONGUEUR_MINIMALE} au minimum.`
    : `Encore ${manquants} caractères : il en faut ${LONGUEUR_MINIMALE} au minimum.`
}

/** Le formulaire peut-il partir ? Employé pour bloquer l'envoi, pas seulement
 *  pour colorer le champ. */
export function motDePasseAcceptable(valeur: string): boolean {
  return erreurMotDePasse(valeur) === null
}

/**
 * Contrôle de courriel volontairement **minimal** : non vide, une arobase
 * entourée de quelque chose.
 *
 * Toute expression rationnelle plus ambitieuse refuse un jour l'adresse d'un
 * cousin qui, elle, est parfaitement valide. La vérification sérieuse est celle
 * du serveur (`422`), et la seule preuve qu'une adresse existe est le courriel
 * qu'on y envoie. On n'intercepte ici que la faute de frappe évidente.
 */
export function erreurCourriel(valeur: string): string | null {
  const nettoye = valeur.trim()
  if (nettoye.length === 0) return 'Indiquez votre adresse de courriel.'
  if (!/^[^\s@]+@[^\s@]+$/.test(nettoye)) {
    return 'Cette adresse ne ressemble pas à une adresse de courriel.'
  }
  return null
}

/**
 * Le formulaire de l'étape 2 peut-il partir ?
 *
 * Nommée plutôt qu'écrite en ligne dans la vue pour une raison précise : c'est
 * **la** garde qui doit passer avant `creerCompte()`. Un mot de passe de huit
 * signes n'a pas à faire un aller-retour pour revenir avec la réponse qu'on
 * connaît déjà, et le message d'ici dit ce qu'il manque là où le `422` du
 * serveur constate.
 */
export function peutSoumettreCompte(email: string, motDePasse: string): boolean {
  return erreurCourriel(email) === null && erreurMotDePasse(motDePasse) === null
}
