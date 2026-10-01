/**
 * Les deux formats de fiche de `/histoire` : « Compact » et « Portrait ».
 *
 * - **Compact** — l'affichage d'origine : la photo à gauche, le nom et la ligne de
 *   dates à côté, dans la boîte que le moteur a mesurée (`w`, `h` du nœud).
 * - **Portrait** — la photo EN HAUT, en grand, puis le NOM, le prénom et les
 *   dates, centrés. Pensé pour le téléphone tenu droit : un visage qu'on
 *   reconnaît vaut mieux qu'un nom de plus à l'écran.
 *
 * **Le format change la taille des boîtes, donc la disposition se refait** :
 * `auFormat()` pose la boîte Portrait sur chaque fiche AVANT `disposerLignees()`,
 * et tout le reste — pas des rangées, barres de fratrie, étiquettes de foyer,
 * bandeau, cadrage — se déduit de ces boîtes, comme en Compact. Aucun chevauchement
 * à surveiller ailleurs : la disposition n'a jamais lu que `w` et `h`.
 *
 * **Une boîte fixe, pas mesurée sur le texte**, pour la même raison que
 * l'emplacement photo côté serveur (`MesureDeFiche::LARGEUR_PHOTO`) : une largeur
 * qui dépendrait de qui a un portrait, ou de la longueur d'un nom, réorganiserait
 * l'arbre à chaque dépôt. Et une taille constante ne dit rien d'une personne
 * vivante (ADR 0003, `LARGEUR_AFFICHEE_VIVANT`) : en Portrait, la « Personne
 * vivante » a exactement la boîte de tout le monde. Le texte trop long, c'est
 * l'écran qui le raccourcit (`texteDePortrait`).
 *
 * La préférence (quel format, mémorisé où) est dans `composables/formatDesCartes.ts` ;
 * ce fichier-ci est pur, sans Vue ni DOM : tout se vérifie dans `cartes.test.ts`.
 */
import type { Graphe, NoeudPersonne } from '@/lib/api'
import { NOM_D_UNE_PERSONNE_VIVANTE } from '@/lib/api/arbre'
import { nomDeFamilleDe, prenomDe } from './lignees'

export type FormatDeCarte = 'compact' | 'portrait'

export const FORMATS_DE_CARTE: readonly FormatDeCarte[] = ['compact', 'portrait']

/** Le format tant que rien n'est choisi : celui d'avant, qui ne surprend personne. */
export const FORMAT_PAR_DEFAUT: FormatDeCarte = 'compact'

/**
 * La fiche Portrait, en pixels du monde (= pixels d'écran au zoom 1).
 *
 * Budget vertical : 8 (marge) + 88 (photo) + 8 (écart) + 15 (NOM) + 15 (prénom)
 * + 2 × 13 (dates, sur deux lignes au plus) + 8 (marge) = 168.
 *
 * 128 de large : la photo de 88 et 20 px d'air de chaque côté ; 112 px de texte,
 * de quoi écrire « 1901-1909 – 1987 » d'une ligne et un NOM de 12 à 13 capitales.
 * Plus étroite que la plupart des fiches Compact (152 à 215) : l'arbre devient
 * plus étroit et plus haut, ce qui va au téléphone tenu droit.
 */
export const GABARIT_PORTRAIT = { w: 128, h: 168, photo: 88, marge: 8, ecart: 8 } as const

/** Hauteurs de ligne et tailles de police du texte Portrait, en pixels d'écran. */
export const LIGNES_PORTRAIT = {
  nom: { taille: 12, hauteur: 15 },
  prenom: { taille: 11.5, hauteur: 15 },
  dates: { taille: 10.5, hauteur: 13 },
} as const

/**
 * Le graphe au format demandé.
 *
 * - `compact` : **le même objet**, rendu tel quel (aucune copie, et une
 *   comparaison d'identité reste vraie).
 * - `portrait` : une copie où chaque nœud personne prend la boîte
 *   `GABARIT_PORTRAIT` ; les familles et les arêtes sont rendues telles quelles,
 *   le graphe reçu n'est pas modifié.
 *
 * À appeler en DERNIER, après les bascules qui retirent des familles : celles-ci
 * ne lisent que les liens, jamais `w` ni `h`, et l'ordre n'y change donc rien —
 * mais c'est le graphe filtré qui doit être recopié, pas le graphe entier.
 */
export function auFormat(graphe: Graphe, format: FormatDeCarte): Graphe {
  if (format === 'compact') return graphe
  const { w, h } = GABARIT_PORTRAIT
  return {
    ...graphe,
    noeuds: graphe.noeuds.map((n) => (n.type === 'personne' ? { ...n, w, h } : n)),
  }
}

/* ------------------------------------------------------------------ */
/* Le texte d'une fiche Portrait                                       */
/* ------------------------------------------------------------------ */

export interface EntreePortrait {
  nom: string
  prenom: string
  /** La ligne de dates telle que reçue (`etiquetteDates`), `''` si absente. */
  dates: string
  /** Fiche restreinte : « Personne vivante », rien d'autre. */
  restreinte: boolean
}

export interface TexteDePortrait {
  /** La ligne du NOM (en capitales par le CSS) ; `''` si rien ne tient. */
  nom: string
  /** Le prénom ; `''` s'il n'y en a pas, ou plus la place. */
  prenom: string
  /** Zéro, une ou deux lignes de dates. */
  dates: string[]
}

/** Ce qu'écrit une fiche Portrait, lu sur le nœud — la règle de confidentialité ici, une fois. */
export function entreePortrait(noeud: NoeudPersonne): EntreePortrait {
  if (noeud.visibilite === 'restreinte') {
    return { nom: NOM_D_UNE_PERSONNE_VIVANTE, prenom: '', dates: '', restreinte: true }
  }
  let nom = nomDeFamilleDe(noeud)
  let prenom = prenomDe(noeud)
  // API antérieure à `etiquetteNom` : le nom de lecture, en entier, sur la ligne du nom.
  if (nom === '' && prenom === '') nom = noeud.displayName
  // Un seul mot (pas de nom de famille connu) : il monte sur la ligne du nom.
  else if (nom === '') [nom, prenom] = [prenom, '']
  return {
    nom,
    prenom,
    // Absente sur un nœud restreint (déjà écarté) ; jamais reconstituée.
    dates: typeof noeud.etiquetteDates === 'string' ? noeud.etiquetteDates : '',
    restreinte: false,
  }
}

/**
 * Ce qu'une fiche Portrait écrit sous sa photo, selon la place **à l'écran**.
 *
 * Même principe que `texteDeFiche` (`vue.ts`) : la police ne suit pas le zoom —
 * aucun texte sous 10 px —, c'est le contenu qui s'adapte. Par ordre d'abandon
 * quand la hauteur manque : la seconde ligne de dates, les dates, le prénom. Le
 * NOM reste tant qu'une ligne tient ; trop long pour la largeur, il est tronqué
 * par le CSS (« ROMARIN-DE-LA… ») — jamais réécrit.
 *
 * Une ligne de dates trop large se coupe en deux sur son dernier séparateur
 * (« – » ou « · ») plutôt que de se tronquer : une date tronquée est une date
 * fausse. Le séparateur reste en fin de première ligne.
 *
 * **Restreinte** : « Personne vivante », sur deux lignes si la largeur l'exige,
 * sans prénom ni dates — rien de plus que ce que le serveur a choisi d'écrire.
 *
 * La largeur d'un texte est estimée (chasse moyenne), comme dans `texteDeFiche`.
 */
export function texteDePortrait(entree: EntreePortrait, largeur: number, hauteur: number): TexteDePortrait {
  const { nom: N, prenom: P, dates: D } = LIGNES_PORTRAIT
  const tient = (texte: string, taille: number, chasse: number): boolean => texte.length * taille * chasse <= largeur
  const vide: TexteDePortrait = { nom: '', prenom: '', dates: [] }
  if (hauteur < N.hauteur || largeur <= 0) return vide

  if (entree.restreinte) {
    const libelle = entree.nom
    const espace = libelle.lastIndexOf(' ')
    // Deux lignes (« Personne » / « vivante ») quand une seule ne tient pas, et que la place y est.
    if (!tient(libelle, N.taille, 0.58) && espace > 0 && hauteur >= N.hauteur + P.hauteur) {
      return { nom: libelle.slice(0, espace), prenom: libelle.slice(espace + 1), dates: [] }
    }
    return { nom: libelle, prenom: '', dates: [] }
  }

  let reste = hauteur - N.hauteur
  const prenom = entree.prenom !== '' && reste >= P.hauteur ? entree.prenom : ''
  if (prenom !== '') reste -= P.hauteur

  let dates: string[] = []
  if (entree.dates !== '' && reste >= D.hauteur) {
    if (tient(entree.dates, D.taille, 0.62)) dates = [entree.dates]
    else {
      const coupe = Math.max(entree.dates.lastIndexOf(' – '), entree.dates.lastIndexOf(' · '))
      dates =
        coupe > 0 && reste >= 2 * D.hauteur
          ? [entree.dates.slice(0, coupe + 2), entree.dates.slice(coupe + 3)]
          : // Une seule ligne de place : la date entière, que le CSS tronque — mieux que rien
            // n'écrire, et le panneau de la fiche la donne en entier.
            [entree.dates]
    }
  }
  return { nom: entree.nom, prenom, dates }
}

/* ------------------------------------------------------------------ */
/* La géométrie à l'écran                                              */
/* ------------------------------------------------------------------ */

export interface GeometriePortrait {
  photo: { x: number; y: number; d: number }
  texte: { x: number; y: number; w: number; h: number }
}

/**
 * Où dessiner la photo et le texte d'une fiche Portrait dont la boîte, à l'écran,
 * commence en `(X, Y)`, à l'échelle `s` (horizontale) et `sy` (verticale).
 *
 * L'échelle verticale peut dépasser l'horizontale (`syDe`, `vue.ts`) : la photo,
 * carrée, suit la plus petite des deux, et le surplus de hauteur va au texte.
 */
export function geometriePortrait(X: number, Y: number, s: number, sy: number): GeometriePortrait {
  const { w, h, photo, marge, ecart } = GABARIT_PORTRAIT
  const k = Math.min(s, sy)
  const d = photo * k
  const yPhoto = Y + marge * k
  const yTexte = yPhoto + d + ecart * k
  return {
    photo: { x: X + (w * s - d) / 2, y: yPhoto, d },
    texte: {
      x: X + marge * s,
      y: yTexte,
      w: Math.max(0, (w - 2 * marge) * s),
      h: Math.max(0, Y + h * sy - marge * k - yTexte),
    },
  }
}
