<script setup lang="ts">
/**
 * « Clés d'accès », dans Mon compte : les voir, les renommer, les supprimer, et
 * en ajouter une **sur cet appareil**.
 *
 * Quelques choix qui ne se devinent pas :
 *
 * - **« Sur cet appareil »**, dans le libellé du bouton : une clé d'accès vit
 *   dans le téléphone ou l'ordinateur qui la crée (et son trousseau). Le dire
 *   évite de croire qu'on « active les clés d'accès » pour tout le compte.
 * - **Le bouton n'existe pas** si le navigateur ne sait pas faire : une phrase
 *   le dit à la place. La liste, elle, reste — on peut supprimer depuis un PC
 *   la clé d'un téléphone perdu.
 * - **Pendant une incarnation**, rien ne se modifie : la clé serait créée sur
 *   l'appareil de l'administrateur, au nom d'un autre. Le serveur refuse de
 *   toute façon ; on ne propose pas le geste.
 * - **Supprimer demande confirmation**, et la confirmation dit la conséquence :
 *   l'appareil ne pourra plus se connecter sans mot de passe. Le mot de passe,
 *   lui, ne change pas.
 * - Les options d'enregistrement sont **préparées au montage** : Safari
 *   n'ouvre Face ID que si l'appel part dans le toucher (`useClesDAcces`).
 * - **Une session ne suffit pas pour ajouter une clé** : passé dix minutes
 *   après la connexion, le serveur veut le mot de passe actuel (§ 2.4) — une
 *   clé survit à la session, une session volée ne doit pas pouvoir en planter
 *   une. On le sait dès la préparation : le champ mot de passe remplace alors
 *   le bouton, et le bouton revient, options prêtes, une fois le mot de passe
 *   confirmé. Deux gestes, et non un : le second doit rester libre de toute
 *   attente réseau pour Safari.
 */
import { computed, onMounted, ref, shallowRef } from 'vue'
import { listerClesDAcces, renommerCleDAcces, supprimerCleDAcces, type CleDAcces } from '@/lib/api'
import { useSession } from '@/composables/useSession'
import {
  ajouterCleDAccesSurCetAppareil,
  clesDeCetAppareil,
  confirmerMotDePassePourAjout,
  oublierCleDeCetAppareil,
  preparerAjoutCleDAcces,
  preuveDIdentiteRequise,
} from '@/composables/useClesDAcces'
import { analyserErreur } from '@/lib/api'
import { environnementCleDAcces, issueDeLErreur, messageDeLIssue } from '@/lib/cleDAcces'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import DialogueConfirmation, { type DemandeDeConfirmation } from '@/components/base/DialogueConfirmation.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import ListeClesDAcces from './ListeClesDAcces.vue'

const { session, incarnation } = useSession()
const possible = environnementCleDAcces().webauthn
/** `incarnation` est facultatif dans les doublures de session des tests. */
const enIncarnation = computed(() => incarnation?.value != null)
const utilisateurId = computed(() => session.value?.utilisateur.id ?? null)

const cles = shallowRef<CleDAcces[] | null>(null)
const erreurChargement = ref<unknown>(null)
const erreurAction = ref<unknown>(null)
const message = ref<string | null>(null)
const ajoutEnCours = ref(false)
const enRenommage = ref<number | null>(null)
const aSupprimer = shallowRef<CleDAcces | null>(null)
const suppressionEnCours = ref(false)
/** Le serveur veut le mot de passe avant de délivrer un défi (voir l'en-tête). */
const motDePasseRequis = ref(false)
const motDePasse = ref('')
const erreurMotDePasse = ref<unknown>(null)
const confirmationEnCours = ref(false)
const erreurChampMotDePasse = computed(() =>
  erreurMotDePasse.value === null ? '' : (analyserErreur(erreurMotDePasse.value).champs.motDePasseActuel ?? ''),
)
/** Relu après chaque ajout ou suppression : c'est un `localStorage`, pas un ref. */
const memoireLocale = ref(0)

const deCetAppareil = computed(() => {
  void memoireLocale.value
  return utilisateurId.value === null ? [] : (clesDeCetAppareil(utilisateurId.value) ?? [])
})

const demandeSuppression = computed<DemandeDeConfirmation | null>(() =>
  aSupprimer.value === null
    ? null
    : {
        titre: `Supprimer « ${aSupprimer.value.nom} » ?`,
        message:
          'Cet appareil ne pourra plus vous connecter sans mot de passe.',
        confirmer: 'Supprimer la clé',
        danger: true,
      },
)

async function charger(): Promise<void> {
  erreurChargement.value = null
  try {
    cles.value = await listerClesDAcces()
  } catch (cause) {
    erreurChargement.value = cause
  }
}

/** Prépare un défi ; si le serveur veut d'abord le mot de passe, le demande. */
async function preparer(): Promise<void> {
  const refus = await preparerAjoutCleDAcces()
  if (preuveDIdentiteRequise(refus)) motDePasseRequis.value = true
}

onMounted(() => {
  void charger()
  if (possible && !enIncarnation.value) void preparer()
})

async function confirmerMotDePasse(): Promise<void> {
  if (confirmationEnCours.value || motDePasse.value === '') return
  confirmationEnCours.value = true
  erreurMotDePasse.value = null
  message.value = null
  try {
    await confirmerMotDePassePourAjout(motDePasse.value)
    motDePasseRequis.value = false
    message.value = 'Mot de passe confirmé : vous pouvez ajouter la clé d’accès.'
  } catch (cause) {
    erreurMotDePasse.value = cause
  } finally {
    motDePasse.value = ''
    confirmationEnCours.value = false
  }
}

async function ajouter(): Promise<void> {
  if (ajoutEnCours.value) return
  ajoutEnCours.value = true
  erreurAction.value = null
  message.value = null
  try {
    const cle = await ajouterCleDAccesSurCetAppareil()
    cles.value = [...(cles.value ?? []), cle]
    message.value = `Clé d’accès « ${cle.nom} » ajoutée.`
  } catch (cause) {
    const issue = issueDeLErreur(cause)
    if (preuveDIdentiteRequise(cause)) motDePasseRequis.value = true
    else if (issue !== null) message.value = messageDeLIssue(issue, 'ajout')
    else erreurAction.value = cause
    // Un défi neuf pour l'essai suivant, toujours dans le geste.
    if (!motDePasseRequis.value) void preparer()
  } finally {
    memoireLocale.value++
    ajoutEnCours.value = false
  }
}

async function renommer(id: number, nom: string): Promise<void> {
  enRenommage.value = id
  erreurAction.value = null
  message.value = null
  try {
    const renommee = await renommerCleDAcces(id, nom)
    cles.value = (cles.value ?? []).map((c) => (c.id === id ? renommee : c))
  } catch (cause) {
    erreurAction.value = cause
  } finally {
    enRenommage.value = null
  }
}

async function confirmerSuppression(): Promise<void> {
  const cle = aSupprimer.value
  if (cle === null) return
  suppressionEnCours.value = true
  erreurAction.value = null
  message.value = null
  try {
    await supprimerCleDAcces(cle.id)
    cles.value = (cles.value ?? []).filter((c) => c.id !== cle.id)
    if (utilisateurId.value !== null) oublierCleDeCetAppareil(utilisateurId.value, cle.id)
    memoireLocale.value++
    message.value = `Clé d’accès « ${cle.nom} » supprimée.`
  } catch (cause) {
    erreurAction.value = cause
  } finally {
    suppressionEnCours.value = false
    aSupprimer.value = null
  }
}
</script>

<template>
  <section class="flex flex-col gap-3 p-4" data-section-cles-d-acces>
    <h2 class="text-base font-semibold">Clés d’accès</h2>
    <p class="m-0 text-sm text-attenue">
      Connectez-vous avec Face ID, votre empreinte ou Windows Hello, sans mot de passe.
    </p>

    <SqueletteChargement v-if="cles === null && erreurChargement === null" :lignes="2" />
    <MessageErreur v-else-if="erreurChargement !== null" :erreur="erreurChargement" @action="charger" />
    <p v-else-if="cles !== null && cles.length === 0" class="m-0 text-sm">
      Aucune clé d’accès pour l’instant.
    </p>
    <ListeClesDAcces
      v-else-if="cles !== null"
      :cles="cles"
      :de-cet-appareil="deCetAppareil"
      :en-renommage="enRenommage"
      :desactive="enIncarnation"
      @renommer="renommer"
      @supprimer="aSupprimer = $event"
    />

    <MessageErreur v-if="erreurAction !== null" :erreur="erreurAction" />
    <p v-if="message !== null" class="m-0 text-sm" role="status">{{ message }}</p>

    <p v-if="enIncarnation" class="m-0 text-sm text-attenue">
      Vous êtes connecté à la place de ce membre : ses clés d’accès ne se modifient pas.
    </p>
    <form
      v-else-if="possible && motDePasseRequis"
      class="flex flex-col gap-3"
      novalidate
      data-confirmation-mot-de-passe
      @submit.prevent="confirmerMotDePasse"
    >
      <p class="m-0 text-sm">
        Confirmez d’abord votre mot de passe.
      </p>
      <ChampTexte
        v-model="motDePasse"
        etiquette="Mot de passe actuel"
        type="password"
        autocomplete="current-password"
        :erreur="erreurChampMotDePasse"
        requis
      />
      <MessageErreur v-if="erreurMotDePasse !== null && erreurChampMotDePasse === ''" :erreur="erreurMotDePasse" />
      <BoutonBase
        type="submit"
        variante="secondaire"
        class="self-start"
        :chargement="confirmationEnCours"
        :desactive="motDePasse === ''"
      >
        Confirmer le mot de passe
      </BoutonBase>
    </form>
    <BoutonBase
      v-else-if="possible"
      variante="secondaire"
      class="self-start"
      :chargement="ajoutEnCours"
      @click="ajouter"
    >
      Ajouter une clé d’accès sur cet appareil
    </BoutonBase>
    <p v-else class="m-0 text-sm text-attenue">
      Ce navigateur ne gère pas les clés d’accès : essayez depuis votre téléphone.
    </p>

    <DialogueConfirmation
      :demande="demandeSuppression"
      :chargement="suppressionEnCours"
      @confirmer="confirmerSuppression"
      @annuler="aSupprimer = null"
    />
  </section>
</template>
