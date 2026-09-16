/**
 * Le triplet « chargement / erreur / données » d'un appel d'API.
 *
 * Son intérêt n'est pas d'économiser trois `ref()` : c'est d'avoir **une seule
 * façon** de traiter un échec. L'erreur est conservée brute et passée telle
 * quelle à `<MessageErreur>`, qui appelle `analyserErreur()` — aucun écran ne
 * réinterprète un code d'erreur pour son compte, et c'est ce qui garantit que
 * `demande_caduque` ne finira jamais derrière un bouton « Réessayer ».
 *
 * Deux détails qui évitent des bogues d'affichage :
 *
 * - **L'appel obsolète est ignoré.** Si deux appels se croisent (le lecteur
 *   change de fiche pendant un chargement), seule la réponse du dernier est
 *   retenue. Sans ce jeton de séquence, la fiche précédente écrase la nouvelle
 *   une fois sur dix, et le bogue ne se reproduit pas sur commande.
 * - **L'erreur est vidée au début de chaque appel**, sinon un message d'échec
 *   survit à un succès.
 */

import { shallowRef, type Ref } from 'vue'

export interface AppelApi<T> {
  donnees: Ref<T | null>
  /** L'erreur brute, à passer à `<MessageErreur :erreur="erreur" />`. */
  erreur: Ref<unknown>
  chargement: Ref<boolean>
  /** Lance (ou relance) l'appel. Ne rejette pas : l'échec est dans `erreur`. */
  executer: () => Promise<T | null>
}

export function useAppelApi<T>(appel: () => Promise<T>): AppelApi<T> {
  const donnees = shallowRef<T | null>(null)
  const erreur = shallowRef<unknown>(null)
  const chargement = shallowRef(false)

  let sequence = 0

  async function executer(): Promise<T | null> {
    const cette = ++sequence
    chargement.value = true
    erreur.value = null
    try {
      const resultat = await appel()
      if (cette !== sequence) return null
      donnees.value = resultat
      return resultat
    } catch (cause) {
      if (cette === sequence) erreur.value = cause
      return null
    } finally {
      if (cette === sequence) chargement.value = false
    }
  }

  return { donnees, erreur, chargement, executer }
}
