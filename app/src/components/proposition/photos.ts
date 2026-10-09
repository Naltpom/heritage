/**
 * L'envoi des photos d'une personne proposée, **après** le dépôt de sa demande.
 *
 * Une photo n'a de sujet qu'une fois la demande créée : elle vise une fiche
 * proposée de cette demande — `p1` pour le formulaire de création, sans dièse
 * (§ 7.1) —, et la validation la rattache à la vraie fiche. Elle n'a donc pas de
 * demande « Ajout d'un document » à elle : c'est la demande de création qui la
 * porte.
 *
 * Les envois sont **séquentiels**, comme dans `TeleversementMedia.vue` : six
 * requêtes parallèles sur un limiteur à vingt par heure produiraient six `429`
 * au lieu d'un. Un échec n'interrompt pas les suivantes, et ce qui est passé
 * n'est jamais renvoyé — un second clic ne double aucune photo.
 */
import { analyserErreur, type EnvoiMedia, type Media } from '@/lib/api'
import type { PhotoAJoindre } from './PhotosAJoindre.vue'

/** La fiche proposée par un formulaire de création : toujours la première. */
export const REFERENCE_DE_LA_FICHE = 'p1'

/*
 * Ce n'est qu'un défaut. Compléter une proposition déjà déposée visera la fiche
 * qu'on complète, qui n'est pas forcément `p1` : une demande de l'assistant en
 * porte plusieurs. La référence se passe alors à `envoyerLesPhotos`, telle que le
 * serveur l'a rendue (`brouillonRef`, sans dièse) — jamais retaillée dans un
 * identifiant de nœud.
 */

/** Les photos qui partiront au prochain envoi. */
export function photosAEnvoyer(photos: readonly PhotoAJoindre[]): PhotoAJoindre[] {
  return photos.filter((p) => p.etat === 'attente' || (p.etat === 'echec' && p.rejouable))
}

/**
 * Envoie ce qui reste à envoyer et rend le nombre d'échecs.
 *
 * `televerser` est injecté pour se tester sans réseau ; l'écran passe
 * `televerserMedia`. `brouillonRef` désigne la fiche proposée visée, `p1` par
 * défaut.
 */
export async function envoyerLesPhotos(
  photos: readonly PhotoAJoindre[],
  demandeId: number,
  televerser: (envoi: EnvoiMedia) => Promise<Media>,
  brouillonRef: string = REFERENCE_DE_LA_FICHE,
): Promise<number> {
  let echecs = 0
  for (const photo of photosAEnvoyer(photos)) {
    photo.etat = 'en_cours'
    photo.message = null
    try {
      const description = photo.description.trim()
      await televerser({
        fichier: photo.fichier,
        kind: 'preuve',
        subjectKind: 'personne',
        brouillon: { demandeId, brouillonRef },
        ...(description.length > 0 ? { caption: description } : {}),
      })
      photo.etat = 'reussie'
    } catch (cause) {
      photo.etat = 'echec'
      photo.message = analyserErreur(cause).message
      echecs++
    }
  }
  return echecs
}
