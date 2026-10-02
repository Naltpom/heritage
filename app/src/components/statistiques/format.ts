/**
 * Les mots et les nombres d'Explorer › Stats — de la logique pure, testée sans
 * navigateur (`format.test.ts`).
 *
 * Aucune fonction d'ici ne **calcule** une statistique : le serveur rend les
 * chiffres, déjà passés par le cercle et par le seuil de discrétion (voir
 * `lib/api/statistiques.ts`). Ce module ne fait que les dire : « XIXᵉ s. »,
 * « 42 % », « sur 412 personnes ».
 */

import { couleurBranche, generationLisible } from '@/components/personne/champs'

const ROMAINS: [number, string][] = [
  [1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'], [90, 'XC'],
  [50, 'L'], [40, 'XL'], [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I'],
]

/** 19 → « XIX ». */
export function enRomain(nombre: number): string {
  let reste = Math.max(0, Math.floor(nombre))
  let rendu = ''
  for (const [valeur, symbole] of ROMAINS) {
    while (reste >= valeur) {
      rendu += symbole
      reste -= valeur
    }
  }
  return rendu
}

/** 19 → « XIXᵉ s. » — la forme courte, sous une barre. */
export function siecleCourt(siecle: number): string {
  return `${enRomain(siecle)}ᵉ s.`
}

/** 19 → « XIXᵉ siècle ». */
export function siecleLong(siecle: number): string {
  return `${enRomain(siecle)}ᵉ siècle`
}

const NOMBRE = new Intl.NumberFormat('fr-FR')
const DECIMAL = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 })

/** 1012 → « 1 012 » (espace fine insécable, à la française). */
export function nombre(n: number): string {
  return NOMBRE.format(n)
}

/** 27.35 → « 27,4 ». `null` → « — ». */
export function decimal(n: number | null): string {
  return n === null ? '—' : DECIMAL.format(n)
}

/** La part, entre 0 et 1 ; 0 sur un total nul (jamais `NaN`). */
export function part(valeur: number, total: number): number {
  return total > 0 ? valeur / total : 0
}

/** 0,4237 → « 42 % ». Sous 1 % sans être nul : « < 1 % », pour ne pas écrire 0. */
export function pourcent(valeur: number, total: number): string {
  if (total <= 0) return '—'
  const p = (100 * valeur) / total
  if (p > 0 && p < 1) return '< 1 %'
  return `${Math.round(p)} %`
}

/**
 * La taille de l'échantillon, sous chaque graphique : « sur 412 personnes »,
 * « sur 1 couple ». Le pluriel français : 0 et 1 au singulier.
 */
export function surN(n: number, singulier: string, pluriel = `${singulier}s`): string {
  return `sur ${nombre(n)} ${n > 1 ? pluriel : singulier}`
}

/** « 1 personne », « 412 personnes ». */
export function compte(n: number, singulier: string, pluriel = `${singulier}s`): string {
  return `${nombre(n)} ${n > 1 ? pluriel : singulier}`
}

export const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'] as const
export const MOIS_INITIALES = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'] as const

/** « 2026-09 » → « sept. 2026 ». */
export function moisLisible(cle: string): string {
  const [annee, mois] = cle.split('-').map(Number)
  if (!annee || !mois || mois < 1 || mois > 12) return cle
  return `${MOIS_COURTS[mois - 1]} ${annee}`
}

/**
 * Une génération du serveur, lue depuis la racine de l'arbre : « Génération −4 »,
 * suivie de l'époque quand on la connaît — « vers 1850 ». Les noms de parenté
 * (« Trisaïeux ») ne sont pas employés : ils ne sont justes que pour la racine,
 * et le lecteur n'est pas toujours elle.
 */
export function generationEtEpoque(generation: number, annee: number | null): string {
  const g = `Génération ${generationLisible(generation)}`
  return annee === null ? g : `${g} · vers ${annee}`
}

/** Le libellé générique d'une branche, quand son patronyme de tête n'est pas connu. */
const BRANCHES: Record<string, string> = {
  pp: 'Père du père',
  pm: 'Mère du père',
  mp: 'Père de la mère',
  mm: 'Mère de la mère',
  tr: 'Tronc',
  cpp: 'Conjoint · père du père',
  cpm: 'Conjoint · mère du père',
  cmp: 'Conjoint · père de la mère',
  cmm: 'Conjoint · mère de la mère',
  hors: 'Hors des branches',
}

/** Le côté d'une branche (« Père du père »). */
export function coteDeBranche(branche: string): string {
  return BRANCHES[branche] ?? branche
}

/** Le nom à afficher : le patronyme de tête s'il est connu, sinon le côté. */
export function nomDeBranche(branche: string, nom: string | null): string {
  return nom !== null && nom.length > 0 ? nom : coteDeBranche(branche)
}

/** La couleur d'une branche : les jetons de la légende de l'arbre, jamais une couleur neuve. */
export function couleurDeBranche(branche: string): string {
  return branche === 'hors' ? 'var(--h-branche-hors)' : couleurBranche(branche)
}

/** « 1702 – 1805 », « née en 1921 », « depuis 1952 ». */
export function bornes(debut: number | null | undefined, fin: number | null | undefined, enCours = false): string {
  if (debut == null) return ''
  if (enCours || fin == null) return `depuis ${debut}`
  return `${debut} – ${fin}`
}
