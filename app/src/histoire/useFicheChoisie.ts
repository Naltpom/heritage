/**
 * La fiche entière de la personne choisie dans l'arbre, pour le volet (PC) et
 * la parenté du panneau (téléphone).
 *
 * La parenté est calculée par le serveur, relativement au lecteur : on ne la
 * recalcule pas. Une lecture dépassée (on a choisi quelqu'un d'autre) est
 * annulée, et son annulation n'est pas une erreur.
 *
 * **Une lecture qui échoue se dit.** Elle était avalée (`.catch(() => {})`) et
 * le volet affichait « Lecture de la fiche… » pour toujours ; `etat` distingue
 * désormais la lecture, la panne (avec `relire`, le bouton « Réessayer ») et la
 * fiche reçue.
 */
import { shallowRef, watch, type Ref } from 'vue'
import { lireFichePersonne, type FichePersonneComplete } from '@/lib/api'
import { estAnnulation } from '@/composables/useRecherchePersonnes'

export type EtatDeLecture = 'aucune' | 'lecture' | 'erreur' | 'lue'

export function useFicheChoisie(personId: Ref<number | null>) {
  const parente = shallowRef<string | null>(null)
  /** La fiche complète, ou `null` tant qu'elle se lit — ou si elle est réduite. */
  const ficheLue = shallowRef<FichePersonneComplete | null>(null)
  const erreur = shallowRef<unknown>(null)
  const etat = shallowRef<EtatDeLecture>('aucune')
  let lecture: AbortController | null = null

  function lire(id: number | null): void {
    lecture?.abort()
    lecture = null
    parente.value = null
    ficheLue.value = null
    erreur.value = null
    if (id === null) {
      etat.value = 'aucune'
      return
    }
    etat.value = 'lecture'
    const c = new AbortController()
    lecture = c
    lireFichePersonne(id, c.signal)
      .then((fiche) => {
        if (lecture !== c) return
        etat.value = 'lue'
        if (fiche.visibilite !== 'complete') return
        parente.value = fiche.parente
        ficheLue.value = fiche
      })
      .catch((cause: unknown) => {
        if (lecture !== c || estAnnulation(cause)) return
        erreur.value = cause
        etat.value = 'erreur'
      })
  }

  watch(personId, (id, avant) => {
    if (id !== avant) lire(id)
  })

  return {
    parente,
    ficheLue,
    erreur,
    etat,
    /** « Réessayer » : relit la fiche de la personne choisie. */
    relire: () => lire(personId.value),
    annuler: () => lecture?.abort(),
  }
}
