/**
 * « Je ne me trouve pas » : les règles de la description de famille, vérifiées
 * **avant** l'envoi.
 *
 * Quand la recherche de l'étape 1b ne rend pas la bonne personne, le candidat
 * peut quand même ouvrir son compte : il décrit sa famille en quelques phrases,
 * et un administrateur identifiera sa fiche — ou la créera — plus tard. Le
 * compte naît **en attente et sans fiche** (`matchKind: 'a_rattacher'`) : il
 * voit les défunts, comme tout compte en attente, et n'écrit rien.
 *
 * Même doctrine que `reglesMotDePasse.ts` :
 *
 * - **le serveur fait foi.** Ses messages (`champs["declaration.description"]`…)
 *   l'emportent à l'affichage ; ce module évite seulement un aller-retour qui
 *   reviendrait dire la même chose, et permet de compter pendant la saisie ;
 * - **on compte les points de code**, après `trim()`, comme le validateur du
 *   serveur : « é » composé ou un emoji comptent pour un caractère.
 *
 * Les bornes (60 à 4 000) viennent du contrat. Soixante caractères, c'est une
 * phrase : « Mon père est Jean Dupont, né vers 1960 à Lille. » en fait déjà
 * cinquante. En dessous, l'administrateur n'aurait rien pour chercher.
 *
 * **Texte OU vocal OU les deux** (2 octobre 2026). La personne peut aussi
 * **dire** sa famille (`EnregistreurVocal.vue`, `messageVocal.ts`). Avec un
 * message vocal, le texte devient facultatif : il peut être vide, ou court — il
 * complète ce qui a été dit. Sans vocal, rien ne change : 60 caractères au
 * moins. Le plafond de 4 000 tient dans les deux cas. Le serveur applique la
 * même règle (`DeclarationDeFamille::lire()`).
 *
 * **Ce texte parle de personnes vivantes.** Rien ici ne le stocke ni ne le
 * journalise : ce module ne fait que compter.
 */

import type { DeclarationDeFamille } from '@/lib/api'
import { longueurVisible } from './reglesMotDePasse'

/** § 4.3 : bornes de la description, après `trim()`. */
export const DESCRIPTION_MINIMALE = 60
export const DESCRIPTION_MAXIMALE = 4000

/** Bornes de l'identité déclarée (colonnes de `mbr_member`). */
export const NOM_MAXIMAL = 120
export const PRENOMS_MAXIMAL = 160

/** La première année admise pour une date de naissance déclarée. */
export const ANNEE_MINIMALE = 1900

/** L'exemple du champ : il montre la forme attendue mieux qu'une consigne. */
export const EXEMPLE_DESCRIPTION =
  'Mon père est Jean Dupont, né vers 1960 à Lille ; ma mère Marie Martin ; ma grand-mère paternelle …'

/**
 * La mention qui accompagne le champ, **obligatoire** : le candidat doit savoir
 * qui lira ce texte, et qu'un modèle de langage peut l'analyser.
 */
export const MENTION_DESCRIPTION =
  'Lu seulement par les administrateurs, et parfois analysé automatiquement (Google Gemini) pour retrouver votre fiche.'

/**
 * La mention du message vocal. Depuis le 2 octobre 2026 au soir, il est
 * **transcrit automatiquement** (Google Gemini, le même fournisseur que
 * l'analyse de la description) et la transcription sert à retrouver la fiche :
 * la mention le dit, avant l'enregistrement — c'est elle qui fonde l'envoi
 * (ADR 0008, addendum ; ADR 0013).
 */
export const MENTION_MESSAGE_VOCAL =
  'Votre message est écouté par l’administrateur et transcrit automatiquement (Google Gemini) pour retrouver votre fiche.'

/** Longueur de la description telle que le serveur la compte. */
export function longueurDescription(texte: string): number {
  return longueurVisible(texte.trim())
}

/**
 * Le message sous le champ, ou `null` si la description convient.
 *
 * Comme pour le mot de passe, il dit **ce qu'il manque** : « encore
 * 23 caractères » se corrige, « trop court » se subit.
 *
 * `avecVocal` : un message vocal accompagne-t-il la déclaration ? Il rend le
 * texte facultatif — seul le plafond demeure.
 */
export function erreurDescription(texte: string, avecVocal = false): string | null {
  const longueur = longueurDescription(texte)
  if (avecVocal) {
    return longueur > DESCRIPTION_MAXIMALE
      ? `Ce texte dépasse ${DESCRIPTION_MAXIMALE.toLocaleString('fr-FR')} caractères : resserrez-le un peu.`
      : null
  }
  if (longueur === 0) return 'Décrivez votre famille en quelques phrases, ou enregistrez un message vocal.'
  if (longueur < DESCRIPTION_MINIMALE) {
    const manquants = DESCRIPTION_MINIMALE - longueur
    return manquants === 1
      ? `Encore un caractère : il en faut ${DESCRIPTION_MINIMALE} au minimum.`
      : `Encore ${manquants} caractères : il en faut ${DESCRIPTION_MINIMALE} au minimum.`
  }
  if (longueur > DESCRIPTION_MAXIMALE) {
    return `Ce texte dépasse ${DESCRIPTION_MAXIMALE.toLocaleString('fr-FR')} caractères : resserrez-le un peu.`
  }
  return null
}

/**
 * Le compteur affiché sous le champ : « 42 / 60 caractères au minimum » tant
 * qu'il en manque, puis « 180 / 4 000 ».
 *
 * (Aucun rapport avec les recherches de l'étape 1b, dont rien ne se compte côté
 * client : ce compteur-ci mesure le texte que le candidat est en train d'écrire.)
 */
export function compteurDescription(texte: string, avecVocal = false): string {
  const longueur = longueurDescription(texte)
  const fmt = (n: number): string => n.toLocaleString('fr-FR')
  // Avec un vocal, il n'y a plus de minimum à atteindre : le compteur ne
  // réclame rien.
  return longueur < DESCRIPTION_MINIMALE && !avecVocal
    ? `${fmt(longueur)} / ${fmt(DESCRIPTION_MINIMALE)} caractères au minimum`
    : `${fmt(longueur)} / ${fmt(DESCRIPTION_MAXIMALE)} caractères`
}

/** `YYYY-MM-DD` d'une date locale : ce que rend un `<input type="date">`. */
function jourLocal(date: Date): string {
  const a = String(date.getFullYear()).padStart(4, '0')
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const j = String(date.getDate()).padStart(2, '0')
  return `${a}-${m}-${j}`
}

/**
 * La date de naissance déclarée : `YYYY-MM-DD` valide, pas avant 1900, pas dans
 * le futur. `aujourdhui` est un paramètre pour que les tests ne dépendent pas
 * du jour où ils tournent.
 */
export function erreurDateDeNaissance(valeur: string, aujourdhui: Date = new Date()): string | null {
  const forme = /^(\d{4})-(\d{2})-(\d{2})$/.exec(valeur)
  if (forme === null) return 'Indiquez votre date de naissance : jour, mois et année.'

  const [annee, mois, jour] = [Number(forme[1]), Number(forme[2]), Number(forme[3])]
  // Un 31 avril ou un 29 février d'année ordinaire : `Date` le fait déborder sur
  // le mois suivant, et c'est ce débordement qui le trahit.
  const date = new Date(annee, mois - 1, jour)
  if (date.getFullYear() !== annee || date.getMonth() !== mois - 1 || date.getDate() !== jour) {
    return 'Cette date n’existe pas.'
  }
  if (annee < ANNEE_MINIMALE) return `La date doit être postérieure à ${ANNEE_MINIMALE}.`
  // Comparaison de chaînes `YYYY-MM-DD` : l'ordre lexical est l'ordre des dates.
  if (valeur > jourLocal(aujourdhui)) return 'Cette date est dans le futur.'
  return null
}

/** Les erreurs de toute la déclaration, par clé du contrat (`declaration.*`). */
export function erreursDeclaration(
  declaration: DeclarationDeFamille,
  aujourdhui: Date = new Date(),
): Partial<Record<keyof DeclarationDeFamille, string>> {
  const erreurs: Partial<Record<keyof DeclarationDeFamille, string>> = {}

  const nom = longueurVisible(declaration.surname.trim())
  if (nom === 0) erreurs.surname = 'Indiquez votre nom de famille.'
  else if (nom > NOM_MAXIMAL) erreurs.surname = `${NOM_MAXIMAL} caractères au plus.`

  const prenoms = longueurVisible(declaration.givenNames.trim())
  if (prenoms === 0) erreurs.givenNames = 'Indiquez vos prénoms.'
  else if (prenoms > PRENOMS_MAXIMAL) erreurs.givenNames = `${PRENOMS_MAXIMAL} caractères au plus.`

  const date = erreurDateDeNaissance(declaration.birthDate, aujourdhui)
  if (date !== null) erreurs.birthDate = date

  const avecVocal = declaration.messageVocal !== undefined && declaration.messageVocal !== null
  const description = erreurDescription(declaration.description, avecVocal)
  if (description !== null) erreurs.description = description

  return erreurs
}

/** La déclaration peut-elle partir ? La garde qui passe avant l'envoi. */
export function declarationAcceptable(
  declaration: DeclarationDeFamille,
  aujourdhui: Date = new Date(),
): boolean {
  return Object.keys(erreursDeclaration(declaration, aujourdhui)).length === 0
}

/** La déclaration telle qu'elle part : espaces de bord retirés, rien d'autre. */
export function normaliserDeclaration(declaration: DeclarationDeFamille): DeclarationDeFamille {
  return {
    surname: declaration.surname.trim(),
    givenNames: declaration.givenNames.trim(),
    birthDate: declaration.birthDate,
    description: declaration.description.trim(),
    // Le `Blob` passe tel quel : il n'a pas d'espaces à retirer.
    ...(declaration.messageVocal ? { messageVocal: declaration.messageVocal } : {}),
  }
}

/**
 * Le libellé du bouton qui ouvre la description, sous le résultat d'une
 * recherche.
 *
 * Il ne dépend **que** du nombre de candidats affichés — donc de ce que le
 * candidat voit déjà. Un résultat vide donne toujours « Je ne me trouve pas »,
 * quelle qu'en soit la cause : recherche infructueuse, plafond horaire, date non
 * confirmée. Aucune entrée ne permettrait d'ailleurs de les distinguer ici.
 */
export function libelleSansFiche(nombreDeCandidats: number): string {
  return nombreDeCandidats === 0 ? 'Je ne me trouve pas' : 'Aucune de ces personnes'
}
