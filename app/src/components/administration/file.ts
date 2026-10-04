/**
 * La file de validation, **à plat et par numéro** (décision du 2026-10-04).
 *
 * Elle était regroupée par fiche visée (§ 6.7, ADR 0004) : deux demandes sur la
 * même fiche s'influencent, valider la première peut rendre la seconde caduque.
 * L'administrateur a demandé l'inverse : chaque demande par son **numéro**, dans
 * l'ordre des numéros, sans regroupement, et des onglets par nature. Ce que le
 * regroupement protégeait n'est pas perdu pour autant : chaque ligne dit combien
 * d'**autres demandes en attente** visent la même fiche ({@link autresSurLaMemeFiche}),
 * et le détail d'une demande garde son bloc `demandesConcurrentes`.
 */

import type { Demande, NatureDemande, StatutDemande } from '@/lib/api'

/* ------------------------------------------------------------------ */
/* Les onglets — les natures regroupées                                */
/* ------------------------------------------------------------------ */

export type OngletDeFile = 'toutes' | 'modifications' | 'personnes' | 'liens'

/**
 * Les natures de chaque onglet. `null` : toutes. Chaque nature est dans **un**
 * onglet, et un test le tient : une nature ajoutée un jour et oubliée ici ne
 * serait visible que sous « Toutes ».
 */
export const NATURES_DE_L_ONGLET: Readonly<Record<OngletDeFile, readonly NatureDemande[] | null>> = {
  toutes: null,
  modifications: [
    'modification_personne',
    'modification_famille',
    'modification_citation',
    'ajout_citation',
    'ajout_note',
    'ajout_media',
    'suppression_personne',
  ],
  personnes: ['creation_personne'],
  liens: ['ajout_lien', 'retrait_lien'],
}

export const ONGLETS: readonly { valeur: OngletDeFile; libelle: string }[] = [
  { valeur: 'toutes', libelle: 'Toutes' },
  { valeur: 'modifications', libelle: 'Modifications' },
  { valeur: 'personnes', libelle: 'Nouvelles personnes' },
  { valeur: 'liens', libelle: 'Liens' },
]

/** Le paramètre `kind` de la file pour cet onglet (§ 6.7 : une liste, séparée par des virgules). */
export function natureDeLOnglet(onglet: OngletDeFile): string | undefined {
  const natures = NATURES_DE_L_ONGLET[onglet]
  return natures === null ? undefined : natures.join(',')
}

/* ------------------------------------------------------------------ */
/* La ligne d'une demande                                              */
/* ------------------------------------------------------------------ */

/**
 * Pour chaque demande **en attente**, le nombre d'**autres** demandes en attente
 * qui visent la même fiche — ce que le regroupement par fiche montrait d'un coup
 * d'œil. Une création n'a pas de cible : elle n'en a jamais.
 */
export function autresSurLaMemeFiche(demandes: readonly Demande[]): Map<number, number> {
  const parCible = new Map<string, number>()
  const cle = (d: Demande): string | null =>
    d.status !== 'en_attente' || d.targetId === null || d.targetKind === null ? null : `${d.targetKind}:${d.targetId}`

  for (const d of demandes) {
    const c = cle(d)
    if (c !== null) parCible.set(c, (parCible.get(c) ?? 0) + 1)
  }

  const autres = new Map<number, number>()
  for (const d of demandes) {
    const c = cle(d)
    const n = c === null ? 0 : (parCible.get(c) ?? 1) - 1
    if (n > 0) autres.set(d.id, n)
  }
  return autres
}

/**
 * La personne dont cette demande permet d'ouvrir un aperçu, ou `null`.
 *
 * **Trois cas rendent `null`, et il faut les distinguer d'un oubli.** Une
 * création n'a pas de cible — la personne n'existe pas encore. Une cible de
 * nature `famille`, `citation` ou `source` n'est **pas** une personne :
 * `GET /api/arbre/personnes/{id}` y répondrait `404`, ou pire, rendrait la fiche
 * d'un homonyme numérique. Et un `targetId` absent ne se devine pas.
 */
export function personneDeLaDemande(demande: Demande): number | null {
  if (demande.targetKind !== 'personne' || demande.targetId === null) return null
  return demande.targetId
}

/** « Jeanne Dupont, Paul Dupont » — les personnes qu'une demande ajoute, ou `''`. */
export function nouvellesPersonnes(demande: Demande): string {
  return (demande.nouvellesPersonnes ?? []).join(', ')
}

/* ------------------------------------------------------------------ */
/* Le tri — explicite, et seulement explicite                          */
/* ------------------------------------------------------------------ */

export type SensDeTri = 'anciennes' | 'recentes'

/**
 * Trie la file **par numéro** (2026-10-04) : `anciennes`, le plus petit numéro
 * d'abord — c'est aussi l'ordre de dépôt, les numéros croissant avec le temps ;
 * `recentes`, l'inverse.
 *
 * **Le tableau d'entrée n'est pas modifié.** Il vient d'une `ref` que le
 * composant relit ; le trier sur place ferait dépendre l'affichage de l'ordre
 * dans lequel les `computed` se sont réveillés.
 */
export function trierDemandes(demandes: readonly Demande[], sens: SensDeTri): Demande[] {
  return [...demandes].sort((a, b) => (sens === 'anciennes' ? a.id - b.id : b.id - a.id))
}

/**
 * La mention d'auteur d'une entrée de file : « par X, le 16 septembre 2026 ».
 *
 * **Pourquoi une fonction pour deux bouts de chaîne.** Le gabarit composait la
 * ligne en deux morceaux, la date dans un `<template v-if>` ouvert à la ligne
 * suivante :
 *
 * ```
 * par {{ demande.authorLabel }}
 * <template v-if="demande.submittedAt">
 *   , le {{ dateLisible(demande.submittedAt) }}
 * ```
 *
 * Le retour à la ligne entre le nom et la virgule n'est pas rien : la
 * condensation des blancs de Vue le réduit à **une espace**, qui se glisse
 * entre le nom et la virgule. Chaque ligne de la file affichait donc « par
 * quelqu'un **,** le 16 septembre 2026 » — en français, la virgule se colle au
 * mot qui la précède.
 *
 * Recoller la virgule dans le gabarit se fait, mais au prix d'un `}}<template`
 * sans blanc que la première mise en forme automatique défait sans bruit. La
 * chaîne est donc composée ici, où aucun blanc n'est significatif et où un test
 * peut la lire.
 *
 * Une demande **non soumise** (brouillon) n'a pas de date : la mention s'arrête
 * au nom, sans virgule orpheline.
 *
 * @param dateLisible la date **déjà mise en forme** — cette fonction reste ainsi
 *                    indépendante de la locale et de la zone du poste qui la teste.
 */
export function ligneAuteur(auteur: string, dateLisible: string): string {
  return dateLisible.length > 0 ? `par ${auteur}, le ${dateLisible}` : `par ${auteur}`
}

/* ------------------------------------------------------------------ */
/* « Valider & suivant » — enchaîner dans l'ordre de la file           */
/* ------------------------------------------------------------------ */

/**
 * La file telle que l'administrateur la voit : son filtre d'état, son filtre
 * d'auteur, son tri. Elle voyage dans l'adresse de la demande ouverte depuis la
 * file (`?etat=&auteur=&tri=`), pour que « & suivant » enchaîne **dans la liste
 * qu'on avait sous les yeux**, et pas dans une autre.
 */
export interface ContexteDeFile {
  /** `''` : tous les états. */
  etat: StatutDemande | ''
  /** `0` : tout le monde. */
  auteur: number
  tri: SensDeTri
  onglet: OngletDeFile
  /** La recherche (`q`) : un numéro, ou un texte. `''` : aucune. */
  recherche: string
}

/** Le défaut de la file : en attente, tout le monde, le plus petit numéro d'abord. */
export const CONTEXTE_PAR_DEFAUT: Readonly<ContexteDeFile> = {
  etat: 'en_attente',
  auteur: 0,
  tri: 'anciennes',
  onglet: 'toutes',
  recherche: '',
}

const ETATS_DE_FILE: readonly StatutDemande[] = ['en_attente', 'validee', 'refusee', 'caduque']

function premiere(valeur: unknown): unknown {
  return Array.isArray(valeur) ? valeur[0] : valeur
}

/**
 * Relit le contexte depuis `route.query`. Une valeur absente ou inconnue vaut
 * le défaut : une adresse bricolée ne doit pas produire une file vide sans
 * raison visible.
 */
export function contexteDepuisRequete(requete: Record<string, unknown>): ContexteDeFile {
  const etat = premiere(requete['etat'])
  const auteur = Number(premiere(requete['auteur']))
  const tri = premiere(requete['tri'])
  const onglet = premiere(requete['onglet'])
  const recherche = premiere(requete['q'])

  return {
    etat:
      etat === 'toutes'
        ? ''
        : typeof etat === 'string' && (ETATS_DE_FILE as readonly string[]).includes(etat)
          ? (etat as StatutDemande)
          : CONTEXTE_PAR_DEFAUT.etat,
    auteur: Number.isInteger(auteur) && auteur > 0 ? auteur : CONTEXTE_PAR_DEFAUT.auteur,
    tri: tri === 'recentes' ? 'recentes' : 'anciennes',
    onglet: typeof onglet === 'string' && onglet in NATURES_DE_L_ONGLET ? (onglet as OngletDeFile) : CONTEXTE_PAR_DEFAUT.onglet,
    recherche: typeof recherche === 'string' ? recherche.trim() : '',
  }
}

/**
 * L'inverse, pour un lien. **Seuls les écarts au défaut** y figurent : la
 * demande ouverte depuis la file par défaut garde une adresse nue, celle que
 * portent les notifications.
 */
export function requeteDeContexte(contexte: ContexteDeFile): Record<string, string> {
  const requete: Record<string, string> = {}
  // `etat` vide (« toutes ») est un écart au défaut, et doit donc s'écrire.
  if (contexte.etat !== CONTEXTE_PAR_DEFAUT.etat) requete['etat'] = contexte.etat === '' ? 'toutes' : contexte.etat
  if (contexte.auteur !== CONTEXTE_PAR_DEFAUT.auteur) requete['auteur'] = String(contexte.auteur)
  if (contexte.tri !== CONTEXTE_PAR_DEFAUT.tri) requete['tri'] = contexte.tri
  if (contexte.onglet !== CONTEXTE_PAR_DEFAUT.onglet) requete['onglet'] = contexte.onglet
  if (contexte.recherche !== '') requete['q'] = contexte.recherche
  return requete
}

/** L'ordre **affiché** : par numéro, à plat, comme à l'écran. */
export function ordreDeLaFile(demandes: readonly Demande[], tri: SensDeTri): Demande[] {
  return trierDemandes(demandes, tri)
}

/**
 * La demande à ouvrir après avoir décidé de `courante`, ou `null` si la file
 * n'a plus rien à décider.
 *
 * `liste` est la file **relue après la décision**, avec le même filtre : une
 * validation peut en avoir rendu d'autres caduques, et elles ne doivent pas
 * être proposées. La courante n'y figure plus quand le filtre est « en
 * attente » ; on la replace donc à sa place pour savoir ce qui vient **après**
 * elle.
 *
 * Seules les demandes `en_attente` sont candidates — sous le filtre « toutes »,
 * enchaîner sur une demande déjà validée ne décide rien. Quand il n'en reste
 * aucune après la courante, on repart du début de la file plutôt que de
 * déclarer la file vide alors qu'il en reste en tête.
 */
export function demandeSuivante(liste: Demande[], courante: Demande, tri: SensDeTri): number | null {
  const avecCourante = liste.some((d) => d.id === courante.id) ? liste : [...liste, courante]
  const ordre = ordreDeLaFile(avecCourante, tri)
  const position = ordre.findIndex((d) => d.id === courante.id)
  const decidable = (d: Demande): boolean => d.id !== courante.id && d.status === 'en_attente'

  const apres = ordre.slice(position + 1).find(decidable)
  if (apres !== undefined) return apres.id

  return ordre.find(decidable)?.id ?? null
}
