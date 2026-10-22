/**
 * **La nature d'une union, mise en mots** (ADR 0020) — un module pur, partagé par la
 * fiche, l'édition, la création d'une personne et l'administration.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Trois natures, et la date qui tranche
 *
 * `married` vaut `true` (mariés, daté ou non), `false` (union libre, PACS) ou
 * `null` (on ne sait pas). **Un mariage daté ou situé est un mariage** : une date ou
 * un lieu de mariage font la nature `true`, quoi que porte la clé — c'est la règle du
 * serveur (`ck_tree_family_mariage_coherent`), et la relire ici rend juste l'écran
 * d'un lien déposé avant que la nature existe, qui porte `null` sous sa date.
 *
 * ## « Divorcés » ou « séparés »
 *
 * `divorced` dit **toute** rupture, divorce ou séparation. On ne divorce que d'un
 * mariage, et l'on n'affirme pas un mariage qu'on ignore : « divorcés » quand la
 * nature est vraie, « séparés » sinon.
 *
 * ## Une date se dit avec le mot du serveur
 *
 * Le `texte` du serveur (« 23 septembre 1927 », « vers 1934 », « entre 1901 et
 * 1909 ») reçoit sa préposition d'après le qualificatif — « le » pour un jour, « en »
 * pour une année ou un mois —, et rien d'autre : **une fourchette ne s'aplatit
 * jamais**, et `min` / `max` ne sont pas lus (§ 14).
 * ────────────────────────────────────────────────────────────────────
 */

import type { BlocDate, ValeurDate } from '@/lib/api'
import { valeurAEnvoyer, videVautAucuneDate, type SaisieDate } from '@/components/proposition/dates'

/** `true` mariés, `false` union libre, `null` on ne sait pas. */
export type NatureDUnion = boolean | null

/** Ce qu'un bloc de mariage ou de rupture peut être, d'où qu'il vienne. */
type BlocDUnion = BlocDate | ValeurDate | null | undefined

function chaine(valeur: unknown): string | null {
  return typeof valeur === 'string' && valeur.trim().length > 0 ? valeur.trim() : null
}

/**
 * Le bloc dit-il **quelque chose** du mariage : une date, même illisible, ou un lieu ?
 *
 * Le critère du serveur (`UncertainDate::estRenseignee()`), terme pour terme : un
 * qualificatif autre que `inconnue`, une chaîne `raw`, ou un lieu.
 */
export function mariageRenseigne(bloc: BlocDUnion): boolean {
  if (bloc === null || bloc === undefined) return false
  return (bloc.qualifier !== undefined && bloc.qualifier !== 'inconnue') || chaine(bloc.raw) !== null || chaine(bloc.place) !== null
}

/**
 * Une **saisie** de mariage atteste-t-elle le mariage ? Une date ou un lieu tapés, oui
 * — et une date illisible aussi : on est en train de l'écrire, et « oui » ne doit pas
 * se rallumer et s'éteindre à chaque frappe. Une case « date connue » laissée vide ne
 * compte pas ({@link videVautAucuneDate}).
 */
export function saisieAtteste(saisie: SaisieDate): boolean {
  const resultat = valeurAEnvoyer(videVautAucuneDate(saisie))
  return !resultat.valide || mariageRenseigne(resultat.valeur)
}

/** La nature qu'une saisie donnera : « oui » dès qu'une date ou un lieu de mariage sont tapés. */
export function natureDeLaSaisie(marie: NatureDUnion, mariage: SaisieDate): NatureDUnion {
  return saisieAtteste(mariage) ? true : marie
}

/** L'aide sous la case « union rompue », dans le mot que la nature permet. */
export function aideDeLaRupture(nature: NatureDUnion): string {
  if (nature === true) return 'Divorce, même sans date.'
  if (nature === false) return 'Séparation, même sans date.'
  return 'Divorce ou séparation, même sans date.'
}

/** La nature telle que l'arbre la porte : la date ou le lieu de mariage l'emportent. */
export function natureEffective(married: NatureDUnion | undefined, mariage: BlocDUnion): NatureDUnion {
  return mariageRenseigne(mariage) ? true : (married ?? null)
}

/**
 * « le 23 septembre 1927 », « en 1927 », « vers 1934 », « entre 1901 et 1909 » — ou
 * `null` quand le bloc ne porte pas de date. Une chaîne `raw` qu'aucune forme n'a lue
 * se dit entre parenthèses, telle quelle : mieux vaut la provenance qu'une lecture
 * inventée.
 */
export function quandDUnEvenement(bloc: BlocDUnion): string | null {
  if (bloc === null || bloc === undefined) return null
  const texte = 'texte' in bloc ? chaine(bloc.texte) : null
  if (texte === null) {
    const brut = chaine(bloc.raw)
    return brut === null ? null : `(${brut})`
  }
  if (bloc.qualifier === 'exacte') return `le ${texte}`
  if (bloc.qualifier === 'annee' || bloc.qualifier === 'mois') return `en ${texte}`
  // « vers… », « avant… », « entre… et… » portent déjà leur mot.
  return texte
}

/** Ce qu'il faut d'une union pour la dire : la fiche, le lien d'une demande. */
export interface UnionADire {
  married?: NatureDUnion | undefined
  marriage?: BlocDUnion
  divorced?: boolean | null | undefined
  divorce?: BlocDUnion
}

/**
 * La ligne du mariage sur la fiche : « Mariés le 23 septembre 1927 — Saule »,
 * « Mariés — date inconnue », « Union libre », ou `null` quand on ne sait pas — la
 * fiche ne dit alors rien, plutôt qu'un « Mariage : date inconnue » qui affirmait un
 * mariage sous chaque union.
 */
export function phraseDuMariage(union: UnionADire): string | null {
  const nature = natureEffective(union.married, union.marriage)
  if (nature === false) return 'Union libre'
  if (nature !== true) return null

  const quand = quandDUnEvenement(union.marriage)
  const lieu = chaine(union.marriage?.place)
  if (quand === null) return lieu === null ? 'Mariés — date inconnue' : `Mariés à ${lieu} — date inconnue`
  return lieu === null ? `Mariés ${quand}` : `Mariés ${quand} — ${lieu}`
}

/** « Divorcés » après un mariage, « Séparés » sinon — union libre ou nature inconnue. */
export function motDeLaRupture(union: Pick<UnionADire, 'married' | 'marriage'>): 'Divorcés' | 'Séparés' {
  return natureEffective(union.married, union.marriage) === true ? 'Divorcés' : 'Séparés'
}

/** « Divorcés en 1998 », « Séparés », … — ou `null` sans rupture. */
export function phraseDeLaRupture(union: UnionADire): string | null {
  if (union.divorced !== true) return null
  const quand = quandDUnEvenement(union.divorce)
  const mot = motDeLaRupture(union)
  return quand === null ? mot : `${mot} ${quand}`
}

/** La nature en un mot, pour un diff ou un récapitulatif. Mêmes mots que le serveur. */
export function natureEnClair(nature: NatureDUnion): string {
  if (nature === true) return 'oui'
  if (nature === false) return 'non (union libre)'
  return 'on ne sait pas'
}
