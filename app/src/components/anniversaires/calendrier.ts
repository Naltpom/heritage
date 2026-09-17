/**
 * La grille d'un mois : six rangs de sept cases, la semaine commençant le lundi.
 *
 * ────────────────────────────────────────────────────────────────────
 * TROIS DÉCISIONS, ET CHACUNE TIENT UN DÉFAUT QUI NE SE VOIT PAS.
 *
 * 1. **La semaine commence le lundi**, et le décalage s'écrit
 *    `(getDay() + 6) % 7`. `getDay()` rend 0 pour dimanche : employé tel quel,
 *    il décale tout le mois d'un jour — c'est le défaut de calendrier le plus
 *    répandu, et il est invisible tant qu'on ne compare pas à un almanach. Le
 *    décalage est calculé ici, une fois, et `JOURS_SEMAINE` commence par lundi
 *    pour que les en-têtes ne puissent pas mentir à côté des cases.
 *
 * 2. **Toujours 42 cases, jamais 35.** Un nombre de rangs variable fait changer
 *    la hauteur de la grille entre deux mois : les flèches « précédent » et
 *    « suivant » déplacent alors tout ce qu'il y a dessous, et le geste se lit
 *    comme un bogue. 42 suffit toujours : le pire cas est un mois de 31 jours
 *    commençant un dimanche, soit 6 + 31 = 37 cases. Le prix est qu'un février
 *    de 28 jours commençant un lundi laisse deux rangs entiers au mois suivant ;
 *    c'est voulu, et ces cases-là sont `dansLeMois: false`.
 *
 * 3. **Le 29 février n'est jamais déplacé.** Une année non bissextile n'a pas de
 *    case au 29 : elle n'en a pas, et la longueur du mois le dit d'elle-même.
 *    Reporter l'anniversaire au 28 février ou au 1er mars **inventerait une
 *    date**, ce que ce produit ne fait nulle part (§ 14). Ce qui n'a pas de case
 *    est listé sous la grille — voir `horsGrille()` dans `regroupement.ts`.
 *
 * **La longueur des mois est une table, pas une soustraction de dates.**
 * `new Date(a, m, 0).getDate()` donne le même résultat et se relit trois fois
 * avant qu'on en soit sûr ; la table se lit d'un coup d'œil, et février passe
 * par `estBissextile()`, qui est la règle qu'on veut pouvoir montrer.
 * ────────────────────────────────────────────────────────────────────
 */
import { moisPrecedent, moisSuivant } from './mois'

/** Une case de la grille — un jour, qu'il appartienne au mois affiché ou à l'un
 *  de ses voisins. */
export interface CaseJour {
  jour: number
  mois: number
  annee: number
  /** `MM-JJ` : la clé de regroupement du serveur, sans l'année. C'est bien la
   *  clé du **propre** mois de la case, y compris pour une case débordante. */
  cle: string
  /** `false` pour les jours empruntés au mois précédent ou au suivant. */
  dansLeMois: boolean
  aujourdhui: boolean
}

export const COLONNES = 7
export const RANGS = 6
export const CASES_DE_GRILLE = COLONNES * RANGS

/** Lundi d'abord — voir la décision 1. `court` est ce qu'on peint, `long` ce
 *  qu'on énonce. */
export const JOURS_SEMAINE: readonly { court: string; long: string }[] = Object.freeze([
  { court: 'L', long: 'lundi' },
  { court: 'M', long: 'mardi' },
  { court: 'M', long: 'mercredi' },
  { court: 'J', long: 'jeudi' },
  { court: 'V', long: 'vendredi' },
  { court: 'S', long: 'samedi' },
  { court: 'D', long: 'dimanche' },
])

const LONGUEURS: readonly number[] = Object.freeze([31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31])

/** La règle grégorienne entière. Les trois termes comptent : 1900 n'est pas
 *  bissextile, 2000 l'est, et un `annee % 4 === 0` seul se trompe trois fois
 *  par quatre siècles — assez rare pour survivre à une relecture. */
export function estBissextile(annee: number): boolean {
  return (annee % 4 === 0 && annee % 100 !== 0) || annee % 400 === 0
}

export function joursDuMois(annee: number, mois: number): number {
  if (mois === 2) return estBissextile(annee) ? 29 : 28
  return LONGUEURS[mois - 1]
}

/** `MM-JJ`, sur deux chiffres chacun. C'est la clé que compose le serveur, et la
 *  seule forme qui trie correctement en tant que chaîne. */
export function cleJour(mois: number, jour: number): string {
  return `${String(mois).padStart(2, '0')}-${String(jour).padStart(2, '0')}`
}

/**
 * Le rang du 1er du mois dans une semaine qui commence le lundi : 0 pour lundi,
 * 6 pour dimanche.
 *
 * Le `setFullYear()` n'est pas décoratif : `new Date(47, 0, 1)` ne construit pas
 * l'an 47 mais 1947. Cet écran n'affiche que des années à quatre chiffres, mais
 * la fonction est appelée avec ce qu'on lui donne, et un piège qui coûte une
 * ligne à désamorcer ne mérite pas d'être laissé en place.
 */
function rangDuPremier(annee: number, mois: number): number {
  const premier = new Date(annee, mois - 1, 1)
  premier.setFullYear(annee)
  return (premier.getDay() + 6) % 7
}

function fabriquer(
  annee: number,
  mois: number,
  jour: number,
  dansLeMois: boolean,
  reference: Date,
): CaseJour {
  return {
    jour,
    mois,
    annee,
    cle: cleJour(mois, jour),
    dansLeMois,
    // Une case empruntée au mois voisin qui tombe aujourd'hui porte quand même
    // la marque : elle *est* aujourd'hui, et la taire serait un mensonge poli.
    aujourdhui:
      reference.getFullYear() === annee &&
      reference.getMonth() + 1 === mois &&
      reference.getDate() === jour,
  }
}

/**
 * Les 42 cases du mois, dans l'ordre de lecture.
 *
 * `reference` est la date du jour. Elle est un **paramètre** et non un
 * `new Date()` pris au vol dans le corps : une suite de tests qui dépend du jour
 * où elle tourne ne prouve rien, et ne le dit qu'une fois par an. Le défaut par
 * défaut est évalué **à l'appel**, jamais à l'évaluation du module.
 */
export function grilleDuMois(annee: number, mois: number, reference: Date = new Date()): CaseJour[] {
  const decalage = rangDuPremier(annee, mois)
  const longueur = joursDuMois(annee, mois)
  const avant = moisPrecedent({ annee, mois })
  const apres = moisSuivant({ annee, mois })
  const longueurAvant = joursDuMois(avant.annee, avant.mois)

  const cases: CaseJour[] = []
  for (let index = 0; index < CASES_DE_GRILLE; index += 1) {
    const rang = index - decalage
    if (rang < 0) {
      cases.push(fabriquer(avant.annee, avant.mois, longueurAvant + rang + 1, false, reference))
    } else if (rang < longueur) {
      cases.push(fabriquer(annee, mois, rang + 1, true, reference))
    } else {
      cases.push(fabriquer(apres.annee, apres.mois, rang - longueur + 1, false, reference))
    }
  }
  return cases
}
