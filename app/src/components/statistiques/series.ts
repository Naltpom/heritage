/**
 * De la charge du serveur aux séries des graphiques — pur, testé sans
 * navigateur (`series.test.ts`).
 *
 * Les couleurs sont des **jetons** existants : `--h-homme-bord` et
 * `--h-femme-bord` pour les hommes et les femmes (les bords des fiches de
 * l'arbre, lisibles en clair comme en sombre), `--h-accent` pour une série
 * seule, `--h-alerte` pour ce qui fait mal (la mortalité des enfants) — jamais
 * une couleur nouvelle.
 */

import type { Moyenne, ParSiecleHommesFemmes, Statistiques } from '@/lib/api'
import { MOIS_INITIALES, siecleCourt } from './format'

export const COULEUR_HOMMES = 'var(--h-homme-bord)'
export const COULEUR_FEMMES = 'var(--h-femme-bord)'
export const COULEUR_SEULE = 'var(--h-accent)'
export const COULEUR_DOULEUR = 'var(--h-alerte)'

/** Une série d'un histogramme (`Barres.vue`). */
export interface Serie {
  nom: string
  valeurs: (number | null)[]
  /** Un jeton CSS (`var(--h-…)`), jamais une couleur en dur. */
  couleur: string
}

export interface Graphique {
  categories: string[]
  series: Serie[]
  /** L'effectif total, pour « sur 412 personnes ». */
  n: number
}

/** Une moyenne publiable : `null` dès que l'échantillon est vide. */
function valeur(m: Moyenne): number | null {
  return m.n > 0 ? m.moyenne : null
}

/** Hommes et femmes, par siècle de naissance. */
export function hommesFemmesParSiecle(lignes: ParSiecleHommesFemmes[]): Graphique {
  return {
    categories: lignes.map((l) => siecleCourt(l.siecle)),
    series: [
      { nom: 'Hommes', valeurs: lignes.map((l) => valeur(l.hommes)), couleur: COULEUR_HOMMES },
      { nom: 'Femmes', valeurs: lignes.map((l) => valeur(l.femmes)), couleur: COULEUR_FEMMES },
    ],
    n: lignes.reduce((s, l) => s + l.ensemble.n, 0),
  }
}

/** Pères et mères, âge à la naissance d'un enfant, par siècle de naissance de l'enfant. */
export function parentsParSiecle(lignes: Statistiques['vie']['ageDesParents']): Graphique {
  return {
    categories: lignes.map((l) => siecleCourt(l.siecle)),
    series: [
      { nom: 'Pères', valeurs: lignes.map((l) => valeur(l.peres)), couleur: COULEUR_HOMMES },
      { nom: 'Mères', valeurs: lignes.map((l) => valeur(l.meres)), couleur: COULEUR_FEMMES },
    ],
    n: lignes.reduce((s, l) => s + l.peres.n + l.meres.n, 0),
  }
}

/** Les douze mois, une série. */
export function douzeMois(nom: string, mois: number[], couleur = COULEUR_SEULE): Graphique {
  return {
    categories: [...MOIS_INITIALES],
    series: [{ nom, valeurs: mois.map((m) => m), couleur }],
    n: mois.reduce((s, m) => s + m, 0),
  }
}

/** La part des enfants morts avant cinq ans, en pour cent, par siècle de naissance. */
export function mortaliteParSiecle(lignes: Statistiques['vie']['mortaliteInfantile']): Graphique {
  return {
    categories: lignes.map((l) => siecleCourt(l.siecle)),
    series: [
      {
        nom: 'Morts avant 5 ans',
        valeurs: lignes.map((l) => (l.n > 0 ? Math.round((100 * l.avantCinqAns) / l.n) : null)),
        couleur: COULEUR_DOULEUR,
      },
    ],
    n: lignes.reduce((s, l) => s + l.n, 0),
  }
}

/** La distance médiane entre naissance et décès, par siècle de naissance. */
export function distanceParSiecle(lignes: Statistiques['lieux']['distance']): Graphique {
  return {
    categories: lignes.map((l) => siecleCourt(l.siecle)),
    series: [{ nom: 'Distance médiane', valeurs: lignes.map((l) => (l.n > 0 ? l.mediane : null)), couleur: COULEUR_SEULE }],
    n: lignes.reduce((s, l) => s + l.n, 0),
  }
}

/** Demandes proposées et validées, mois par mois (chantier). */
export function demandesParMois(lignes: { mois: string; proposees: number; validees: number }[]): Graphique {
  return {
    categories: lignes.map((l) => {
      const mois = Number(l.mois.slice(5, 7))
      return MOIS_INITIALES[mois - 1] ?? l.mois
    }),
    series: [
      { nom: 'Proposées', valeurs: lignes.map((l) => l.proposees), couleur: COULEUR_SEULE },
      { nom: 'Validées', valeurs: lignes.map((l) => l.validees), couleur: 'var(--h-ok)' },
    ],
    n: lignes.reduce((s, l) => s + l.proposees, 0),
  }
}

/**
 * Un graphique n'a rien à dire quand aucune de ses valeurs n'existe. Un zéro,
 * lui, est une donnée : « 0 % d'enfants morts avant cinq ans » se dessine.
 */
export function estVide(graphique: Graphique): boolean {
  return graphique.series.every((s) => s.valeurs.every((v) => v === null))
}
