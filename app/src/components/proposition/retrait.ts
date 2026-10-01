/**
 * **Annuler sa propre demande** tant que personne n'a décidé.
 *
 * L'API le savait depuis le premier lot — `POST /api/demandes/{id}/retirer`,
 * statut `annulee` — et **aucun écran ne l'appelait**. Un membre qui s'était
 * trompé de personne n'avait donc qu'un recours : attendre qu'un administrateur
 * refuse à sa place, avec un motif qui n'avait rien à dire. C'est un refus de
 * plus dans « Mes demandes » pour une erreur que l'auteur avait vue le premier.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Qui, et quand — la règle est celle du serveur, recopiée, pas réinventée.**
 *
 * - **Son auteur seulement**, administrateur compris (`DemandeVoter::MODIFIER`,
 *   `verifierQuIlEstLAuteur`). Un administrateur qui retirerait la demande d'un
 *   autre déciderait à sa place sans laisser de trace de décision ; pour
 *   trancher, il a « Refuser », qui en laisse une. Le bouton ne s'affiche donc
 *   pas sur la demande d'autrui, même pour lui.
 * - **Tant que le statut n'est pas définitif** (`RequestStatus::estDefinitif`) :
 *   `brouillon` et `en_attente`. Les quatre autres — validée, refusée, caduque,
 *   annulée — rendent `409 demande_deja_decidee`.
 *
 * Le front éteint le bouton ; l'API ne compte pas dessus. Entre l'affichage et
 * le clic, un administrateur a pu valider : le `409` arrive alors, la conduite
 * du socle est « Recharger » (`conduiteATenir`), et l'écran relit la demande
 * pour montrer ce qui s'est réellement passé. Aucun code n'est retesté ici.
 *
 * **Sous la vue empruntée** (« voir comme un membre »), toute écriture rend
 * `409 vue_comme_membre_active` : le bouton reste **visible et éteint**, avec le
 * motif en infobulle (`ecriture.ts`) — un bouton qui disparaît laisse croire
 * que la demande n'est plus annulable. **Sous incarnation** (ADR 0010),
 * l'administrateur agit au nom du membre et l'écriture passe : le bouton marche,
 * et la confirmation rappelle que le geste est tracé au nom de qui tient le
 * clavier.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { retirerDemande, type Demande, type StatutDemande } from '@/lib/api'
import type { DemandeDeConfirmation } from '@/components/base/DialogueConfirmation.vue'
import { ecrituresSuspendues, infobulleSuspension } from '@/components/administration/ecriture'
import type { ComptesParFiltre } from './mes-demandes'
import { rafraichirSession, useSession } from '@/composables/useSession'

/** Les statuts que le serveur accepte de retirer — le complément exact de
 *  `RequestStatus::estDefinitif()`. */
export const STATUTS_RETIRABLES: readonly StatutDemande[] = ['brouillon', 'en_attente']

/** Ce qu'il faut d'une demande pour en décider : la liste n'a qu'un résumé. */
export interface DemandeRetirable {
  id: number
  status: StatutDemande
  /** Facultatif au typage de `Demande` ; le serveur l'envoie sur les deux routes. */
  authorUserId?: number
}

/**
 * Le lecteur peut-il annuler cette demande ?
 *
 * Un `authorUserId` absent ne vaut **pas** « c'est la mienne » : le défaut est
 * fermé, comme celui du serveur.
 */
export function peutRetirer(demande: DemandeRetirable, lecteurId: number | null | undefined): boolean {
  return (
    lecteurId !== null &&
    lecteurId !== undefined &&
    demande.authorUserId === lecteurId &&
    STATUTS_RETIRABLES.includes(demande.status)
  )
}

/**
 * La question posée avant l'envoi.
 *
 * Le message dit ce que le geste **ne fait pas** autant que ce qu'il fait : la
 * demande n'est pas effacée — « une demande n'est jamais supprimée » (ADR 0004) —
 * et rien ne se rouvre. Sous incarnation, il dit à quel nom le geste s'écrit.
 */
export function confirmationDeRetrait(
  demande: DemandeRetirable,
  incarnePar: string | null = null,
): DemandeDeConfirmation {
  const brouillon = demande.status === 'brouillon'
  const trace =
    incarnePar === null
      ? ''
      : ` Vous êtes connecté en tant que ce membre : le geste sera tracé au nom de ${incarnePar}.`

  return {
    titre: `Annuler la demande n° ${demande.id} ?`,
    message:
      (brouillon
        ? 'Ce brouillon ne sera jamais soumis. '
        : 'Elle quittera la file des administrateurs et ne sera pas examinée. ') +
      'Elle restera consultable, marquée « Annulée », mais ne pourra pas être rouverte : il faudra en déposer une nouvelle.' +
      trace,
    confirmer: 'Annuler ma demande',
    danger: true,
  }
}

export interface RetraitDeDemande {
  /** La demande en cours de confirmation, ou `null` : pilote `DialogueConfirmation`. */
  confirmation: ComputedRef<DemandeDeConfirmation | null>
  /** L'identifiant en cours d'envoi, pour l'indicateur du bouton. */
  envoi: Ref<number | null>
  /** La dernière erreur, et la demande qu'elle concerne. */
  erreur: Ref<{ id: number; cause: unknown } | null>
  /** Écritures suspendues (vue empruntée) : bouton éteint. */
  suspendu: ComputedRef<boolean>
  infobulle: ComputedRef<string | undefined>
  lecteurId: ComputedRef<number | null>
  demander: (demande: DemandeRetirable) => void
  abandonner: () => void
  confirmer: () => Promise<void>
}

/**
 * L'état partagé par le détail et la liste : une demande à confirmer, un envoi,
 * une erreur. Les deux écrans ne diffèrent que par ce qu'ils font **après** —
 * `apres` reçoit la demande telle que le serveur la rend (`annulee`), ou `null`
 * après un échec, pour que l'écran relise l'état réel.
 */
export function useRetraitDeDemande(apres: (id: number, demande: Demande | null) => void | Promise<void>): RetraitDeDemande {
  const { session, vueEmpruntee, incarnation } = useSession()

  const cible = ref<DemandeRetirable | null>(null)
  const envoi = ref<number | null>(null)
  const erreur = ref<{ id: number; cause: unknown } | null>(null)

  const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
  const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))
  const lecteurId = computed(() => session.value?.utilisateur.id ?? null)

  const confirmation = computed(() =>
    cible.value === null
      ? null
      : confirmationDeRetrait(cible.value, incarnation.value?.par.displayName ?? null),
  )

  function demander(demande: DemandeRetirable): void {
    if (suspendu.value || envoi.value !== null) return
    erreur.value = null
    cible.value = demande
  }

  function abandonner(): void {
    if (envoi.value === null) cible.value = null
  }

  async function confirmer(): Promise<void> {
    const demande = cible.value
    if (demande === null || envoi.value !== null) return

    envoi.value = demande.id
    try {
      const retiree = await retirerDemande(demande.id)
      cible.value = null
      await apres(demande.id, retiree)
      // Le compteur « en attente » de la session (`demandesEnAttente`) vient de
      // perdre une entrée. Un échec ici ne doit pas faire croire que le retrait
      // a échoué : il a réussi, seule la pastille attendra la prochaine lecture.
      void rafraichirSession().catch(() => undefined)
    } catch (cause) {
      cible.value = null
      erreur.value = { id: demande.id, cause }
      // `409 demande_deja_decidee` : un administrateur a tranché entre-temps.
      // On relit, pour que la pastille dise ce qui s'est réellement passé.
      await apres(demande.id, null)
    } finally {
      envoi.value = null
    }
  }

  return { confirmation, envoi, erreur, suspendu, infobulle, lecteurId, demander, abandonner, confirmer }
}

/**
 * Les compteurs du segmenté après une annulation, **sans relire la liste**.
 *
 * On ne touche qu'aux comptes déjà connus : l'ancien statut perd une demande,
 * « annulée » en gagne une. Un compte inconnu le reste — en inventer un serait
 * afficher un nombre faux (voir `comptesDepuisListe()`). « Toutes » ne bouge pas.
 */
export function comptesApresRetrait(comptes: ComptesParFiltre, ancien: StatutDemande): ComptesParFiltre {
  const suite: ComptesParFiltre = { ...comptes }
  const avant = suite[ancien]
  if (avant !== undefined) suite[ancien] = Math.max(0, avant - 1)
  const annulees = suite.annulee
  if (annulees !== undefined) suite.annulee = annulees + 1
  return suite
}
