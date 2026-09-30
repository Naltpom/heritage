/**
 * Le formulaire d'une source — ADR 0012, § 10.7 du contrat.
 *
 * En fonctions pures, pour la même raison que `decision.ts` : on veut vérifier
 * sans navigateur ce qui part au serveur.
 *
 * Deux règles de forme :
 *
 * - **un champ blanc part à `null`**, jamais en chaîne vide : c'est ce que fait
 *   le serveur, et un `""` qui y arriverait se lirait « renseigné » ailleurs ;
 * - **la validation d'ici ne remplace pas celle du serveur** (voir
 *   `ChampTexte.vue`) : elle évite un aller-retour sur les deux fautes
 *   évidentes, le titre vide et le titre trop long. Le reste — le type de
 *   source, les longueurs des autres champs — revient en `422` avec `champs`,
 *   et s'affiche tel quel.
 */

import type { GenreDeSource, NoticeDeSource, SourceAdministration } from '@/lib/api'

/** `tree_source.title` est un `VARCHAR(255)`. */
export const LONGUEUR_TITRE = 255

/** Ce que tient le formulaire : des chaînes, toujours. */
export interface SaisieDeSource {
  title: string
  sourceKind: GenreDeSource
  author: string
  publication: string
  note: string
}

export function saisieVide(): SaisieDeSource {
  return { title: '', sourceKind: 'acte_etat_civil', author: '', publication: '', note: '' }
}

export function saisieDepuisSource(source: SourceAdministration): SaisieDeSource {
  return {
    title: source.title,
    sourceKind: source.sourceKind,
    author: source.author ?? '',
    publication: source.publication ?? '',
    note: source.note ?? '',
  }
}

function texteOuNull(valeur: string): string | null {
  const rogne = valeur.trim()
  return rogne === '' ? null : rogne
}

/** Ce qui part au serveur : rogné, et `null` pour ce qui est blanc. */
export function noticeDepuisSaisie(saisie: SaisieDeSource): NoticeDeSource {
  return {
    title: saisie.title.trim(),
    sourceKind: saisie.sourceKind,
    author: texteOuNull(saisie.author),
    publication: texteOuNull(saisie.publication),
    note: texteOuNull(saisie.note),
  }
}

/** Les fautes qu'on sait voir avant l'envoi, au format de `champs` (§ 15.1). */
export function fautesDeSaisie(saisie: SaisieDeSource): Record<string, string> {
  const titre = saisie.title.trim()
  if (titre === '') return { title: 'Le titre est obligatoire.' }
  // En caractères, comme `mb_strlen` côté serveur — pas en unités UTF-16.
  if ([...titre].length > LONGUEUR_TITRE) return { title: `${LONGUEUR_TITRE} caractères au plus.` }
  return {}
}

/** « 3 citations », « 1 citation », « Jamais citée ». */
export function libelleCitations(nombre: number): string {
  if (nombre === 0) return 'Jamais citée'
  return `${nombre} citation${nombre > 1 ? 's' : ''}`
}
