/**
 * La session d'édition d'une fiche — **l'état que `PageFiche` tient**, sorti de la
 * page pour s'éprouver sans elle (`edition-session.test.ts`).
 *
 * `edition.ts` dit ce qui a changé et en quels corps de demande ; ce composable
 * tient le reste : entrer et sortir du mode, la confirmation d'abandon, la modale
 * de récapitulatif, le dépôt **une demande après l'autre** et l'annonce de fin.
 *
 * ── Un second « Confirmer » ne redépose rien ────────────────────────────────
 *
 * Les demandes partent dans l'ordre — champs, retraits, ajouts — et chacune
 * déposée est retenue. Si la deuxième échoue (une `422` sur un lien, une `409`
 * parce que la fiche a bougé), la première est déjà dans la file : la redéposer
 * au second clic en ferait deux. C'est la règle que `PropositionModificationView`
 * tenait déjà pour ses deux demandes.
 */
import { computed, ref, watch, type Ref } from 'vue'
import { creerDemande, invaliderGraphe, type FichePersonneComplete } from '@/lib/api'
import type { DemandeDeConfirmation } from '@/components/base/DialogueConfirmation.vue'
import type { ErreurSaisie } from '@/components/proposition/creation'
import { valeursDepuisSaisie } from '@/components/proposition/operations'
import {
  aDesModifications,
  cleDeDepot,
  demandesDEdition,
  entrerEnEdition,
  recapitulatif,
  type Changement,
  type CorpsDEdition,
  type EtatEdition,
} from './edition'

export interface DemandeDeposee {
  kind: CorpsDEdition['kind']
  /** {@link cleDeDepot} : la nature, et la famille pour une union corrigée. */
  cle: string
  id: number
}

export interface OptionsEdition {
  /** Appelé à l'entrée : la page amène l'onglet Identité, qui porte ce qu'on corrige. */
  surEntree?: () => void
  /** Appelé après un dépôt réussi : la page relit la fiche (calque « en attente »). */
  surDepot?: () => void
}

export function useEditionFiche(fiche: Ref<FichePersonneComplete> | (() => FichePersonneComplete), options: OptionsEdition = {}) {
  const lire = typeof fiche === 'function' ? fiche : () => fiche.value

  /** `null` : la fiche se lit. Sinon, la session d'édition en cours. */
  const edition = ref<EtatEdition | null>(null)
  /** Le récapitulatif ouvert dans la modale, ou `null` : modale fermée. */
  const recap = ref<Changement[] | null>(null)
  const message = ref('')
  const envoi = ref(false)
  const erreurEnvoi = ref<unknown>(null)
  const erreursSaisie = ref<ErreurSaisie[]>([])
  /** Les natures déjà déposées pendant cette session — jamais redéposées. */
  const deposees = ref<DemandeDeposee[]>([])
  /** L'annonce de fin : les demandes déposées par la dernière session. */
  const annonce = ref<DemandeDeposee[]>([])
  const confirmationAnnulation = ref<DemandeDeConfirmation | null>(null)

  /** Les erreurs de saisie, à côté de chaque champ — une date mal écrite, un
   *  décès daté sans « Décès attesté ». */
  const erreursChamps = computed<Record<string, string>>(() =>
    edition.value === null ? {} : valeursDepuisSaisie(edition.value.saisie).erreurs,
  )

  function entrer(): void {
    edition.value = entrerEnEdition(lire())
    deposees.value = []
    annonce.value = []
    message.value = ''
    options.surEntree?.()
  }

  function quitter(): void {
    edition.value = null
    recap.value = null
    confirmationAnnulation.value = null
    erreurEnvoi.value = null
    erreursSaisie.value = []
  }

  /** « Annuler » : on sort sans rien envoyer — après confirmation s'il y a de quoi perdre. */
  function annuler(): void {
    if (edition.value !== null && aDesModifications(edition.value, lire())) {
      confirmationAnnulation.value = {
        titre: 'Abandonner ces corrections ?',
        message: 'Rien n’a été envoyé : vos modifications seront perdues.',
        confirmer: 'Abandonner',
        danger: true,
      }
      return
    }
    quitter()
  }

  /** « Corriger » : ouvre le récapitulatif ; rien ne part encore. */
  function corriger(): void {
    if (edition.value === null) return
    erreurEnvoi.value = null
    const demandes = demandesDEdition(lire(), edition.value, message.value)
    erreursSaisie.value = demandes.valide ? [] : demandes.erreurs
    recap.value = recapitulatif(lire(), edition.value)
  }

  /** « Confirmer » : dépose les demandes, puis sort du mode et les annonce. */
  async function confirmer(): Promise<void> {
    if (edition.value === null || envoi.value) return
    const demandes = demandesDEdition(lire(), edition.value, message.value)
    if (!demandes.valide) {
      erreursSaisie.value = demandes.erreurs
      return
    }

    envoi.value = true
    erreurEnvoi.value = null
    try {
      for (const corps of demandes.corps) {
        const cle = cleDeDepot(corps)
        if (deposees.value.some((d) => d.cle === cle)) continue
        const demande = await creerDemande(corps)
        deposees.value = [...deposees.value, { kind: corps.kind, cle, id: demande.id }]
      }
      // Le graphe incorpore les nœuds en attente du lecteur : son `ETag` est
      // périmé dès qu'une demande naît (§ 5.2, § 13.4).
      invaliderGraphe()
      annonce.value = deposees.value
      quitter()
      options.surDepot?.()
    } catch (cause) {
      erreurEnvoi.value = cause
    } finally {
      envoi.value = false
    }
  }

  // Une autre fiche : la session d'édition ne la suit pas.
  watch(
    () => lire().id,
    () => {
      quitter()
      annonce.value = []
    },
  )

  return {
    edition,
    recap,
    message,
    envoi,
    erreurEnvoi,
    erreursSaisie,
    erreursChamps,
    annonce,
    confirmationAnnulation,
    entrer,
    quitter,
    annuler,
    corriger,
    confirmer,
  }
}
