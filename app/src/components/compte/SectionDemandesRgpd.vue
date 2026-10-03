<script setup lang="ts">
/**
 * « Mon compte » › Demandes RGPD (ADR 0016, révision du 3 octobre 2026).
 *
 * Une composante de date de naissance partagée reste visible au moins trente
 * jours : la retirer plus tôt est une **demande**, faite ici, dans
 * l'application — pas de courriel. Un administrateur l'applique ou la refuse ;
 * la réponse revient dans la liste (et en notification).
 *
 * « Faire une demande RGPD » ouvre un petit formulaire : la nature, les trois
 * composantes pour un retrait, un message (exigé hors retrait). Le lien sous
 * une case verrouillée de la section « Confidentialité » l'ouvre aussi, la
 * composante déjà cochée : la vue appelle `ouvrir(cle)`.
 *
 * Sous la bascule « voir comme », le serveur refuse (`409
 * vue_comme_membre_active`) : on ne demande pas pour le membre qu'on regarde.
 * Le bouton s'éteint, la liste ne se lit pas, et la raison est dite.
 *
 * La vue la masque quand `partageNaissance` vaut `null` : un compte sans ligne
 * membre n'a pas de demande à faire ici.
 */
import { computed, onMounted, ref, useId, watch } from 'vue'
import {
  LONGUEUR_MAX_MESSAGE_RGPD,
  analyserErreur,
  creerDemandeRgpd,
  estErreurApi,
  listerMesDemandesRgpd,
  type DemandeRgpd,
  type NatureDemandeRgpd,
  type PartageNaissance,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import {
  NATURES_RGPD,
  brouillonVide,
  composantesARetirer,
  controlerDemande,
  corpsDeDemande,
  etatDeDemande,
  libelleNature,
  pastilleDeDemande,
  type BrouillonDemandeRgpd,
  type ErreursDemandeRgpd,
} from './demandesRgpd'
import { dateDuVerrou } from './partageNaissance'

const { vueEmpruntee } = useSession()
const id = useId()

const COMPOSANTES: readonly { cle: keyof PartageNaissance; libelle: string }[] = [
  { cle: 'annee', libelle: 'Année' },
  { cle: 'mois', libelle: 'Mois' },
  { cle: 'jour', libelle: 'Jour' },
]

const demandes = ref<DemandeRgpd[]>([])
const chargement = ref(false)
const erreurLecture = ref<unknown>(null)

const ouvert = ref(false)
const brouillon = ref<BrouillonDemandeRgpd>(brouillonVide())
/** Les refus affichés : ceux du contrôle, après une première tentative, ou ceux du serveur. */
const tentee = ref(false)
const envoi = ref(false)
const erreurEnvoi = ref<unknown>(null)
const envoyee = ref(false)

const suspendu = computed(() => vueEmpruntee.value !== null && vueEmpruntee.value !== undefined)

const controle = computed(() => controlerDemande(brouillon.value))
const erreurs = computed<ErreursDemandeRgpd>(() => {
  const serveur = estErreurApi(erreurEnvoi.value) ? analyserErreur(erreurEnvoi.value).champs : {}
  const depuisServeur: ErreursDemandeRgpd = {}
  for (const cle of ['nature', 'composantes', 'message'] as const) {
    if ((serveur[cle] ?? '') !== '') depuisServeur[cle] = serveur[cle]
  }
  return { ...(tentee.value ? controle.value : {}), ...depuisServeur }
})
const erreurGenerale = computed(() => erreurEnvoi.value !== null && Object.keys(erreurs.value).length === 0)

async function charger(): Promise<void> {
  if (suspendu.value) {
    demandes.value = []
    return
  }
  chargement.value = true
  erreurLecture.value = null
  try {
    demandes.value = (await listerMesDemandesRgpd()).elements
  } catch (cause) {
    erreurLecture.value = cause
  } finally {
    chargement.value = false
  }
}

onMounted(charger)
watch(suspendu, () => void charger())

/** Ouvre le formulaire — sur un retrait de `cle` quand le lien vient d'une case verrouillée. */
function ouvrir(cle?: keyof PartageNaissance): void {
  if (suspendu.value) return
  brouillon.value = brouillonVide(cle === undefined ? {} : { [cle]: true })
  tentee.value = false
  erreurEnvoi.value = null
  envoyee.value = false
  ouvert.value = true
}

function fermer(): void {
  ouvert.value = false
  erreurEnvoi.value = null
  tentee.value = false
}

defineExpose({ ouvrir })

function choisirNature(valeur: string): void {
  brouillon.value = { ...brouillon.value, nature: valeur as NatureDemandeRgpd }
  erreurEnvoi.value = null
}

function cocher(cle: keyof PartageNaissance, valeur: boolean): void {
  brouillon.value = { ...brouillon.value, aRetirer: { ...brouillon.value.aRetirer, [cle]: valeur } }
}

function saisir(valeur: string): void {
  brouillon.value = { ...brouillon.value, message: valeur }
}

async function envoyer(): Promise<void> {
  if (envoi.value || suspendu.value) return
  tentee.value = true
  erreurEnvoi.value = null
  if (Object.keys(controle.value).length > 0) return
  envoi.value = true
  try {
    const creee = await creerDemandeRgpd(corpsDeDemande(brouillon.value))
    demandes.value = [creee, ...demandes.value.filter((d) => d.id !== creee.id)]
    ouvert.value = false
    tentee.value = false
    envoyee.value = true
  } catch (cause) {
    erreurEnvoi.value = cause
  } finally {
    envoi.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-3 p-4" data-section-demandes-rgpd>
    <h2 class="text-base font-semibold">Demandes RGPD</h2>
    <p class="m-0 text-xs text-attenue">
      Retirer une information partagée avant la fin des 30 jours, rectifier une donnée, ou toute autre
      demande sur vos données : un administrateur vous répond ici.
    </p>

    <p v-if="suspendu" class="m-0 text-xs text-attenue">
      Vous voyez l’arbre comme un autre membre : aucune demande ne se fait pendant ce temps.
    </p>

    <p v-if="envoyee" class="m-0 text-sm" role="status">Demande envoyée.</p>

    <BoutonBase
      v-if="!ouvert"
      variante="secondaire"
      class="self-start"
      :desactive="suspendu"
      @click="ouvrir()"
    >
      Faire une demande RGPD
    </BoutonBase>

    <form
      v-else
      class="flex flex-col gap-3 rounded-panneau border border-ligne p-3"
      novalidate
      data-formulaire-rgpd
      @submit.prevent="envoyer"
    >
      <div class="flex flex-col gap-1">
        <label :for="`${id}-nature`" class="text-sm font-medium">Type de demande</label>
        <select
          :id="`${id}-nature`"
          :value="brouillon.nature"
          class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
          data-nature-rgpd
          @change="choisirNature(($event.target as HTMLSelectElement).value)"
        >
          <option v-for="n in NATURES_RGPD" :key="n.nature" :value="n.nature">{{ n.libelle }}</option>
        </select>
        <p v-if="erreurs.nature" class="m-0 text-xs text-alerte">{{ erreurs.nature }}</p>
      </div>

      <fieldset v-if="brouillon.nature === 'retrait_partage'" class="flex flex-col gap-1.5">
        <legend class="mb-1 text-sm font-medium">À retirer de ma date de naissance</legend>
        <div class="flex flex-wrap gap-x-5 gap-y-1.5">
          <label v-for="composante in COMPOSANTES" :key="composante.cle" class="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              class="size-4 accent-[var(--color-accent)]"
              :checked="brouillon.aRetirer[composante.cle]"
              :data-a-retirer="composante.cle"
              @change="cocher(composante.cle, ($event.target as HTMLInputElement).checked)"
            />
            {{ composante.libelle }}
          </label>
        </div>
        <p v-if="erreurs.composantes" class="m-0 text-xs text-alerte">{{ erreurs.composantes }}</p>
      </fieldset>

      <div class="flex flex-col gap-1">
        <label :for="`${id}-message`" class="text-sm font-medium">
          Message{{ brouillon.nature === 'retrait_partage' ? ' (facultatif)' : '' }}
        </label>
        <textarea
          :id="`${id}-message`"
          :value="brouillon.message"
          rows="3"
          :maxlength="LONGUEUR_MAX_MESSAGE_RGPD"
          class="rounded-champ border bg-fond px-2.5 py-1.5 text-sm text-texte"
          :class="erreurs.message ? 'border-alerte' : 'border-ligne'"
          data-message-rgpd
          @input="saisir(($event.target as HTMLTextAreaElement).value)"
        />
        <p v-if="erreurs.message" class="m-0 text-xs text-alerte">{{ erreurs.message }}</p>
      </div>

      <MessageErreur v-if="erreurGenerale" :erreur="erreurEnvoi" />

      <div class="flex flex-wrap gap-2">
        <BoutonBase type="submit" :chargement="envoi" :desactive="suspendu">Envoyer la demande</BoutonBase>
        <BoutonBase variante="discret" :desactive="envoi" @click="fermer">Annuler</BoutonBase>
      </div>
    </form>

    <MessageErreur v-if="erreurLecture !== null" :erreur="erreurLecture" @action="charger" />

    <ul v-if="demandes.length > 0" class="m-0 flex list-none flex-col gap-2 p-0">
      <li
        v-for="demande in demandes"
        :key="demande.id"
        class="flex flex-col gap-1 rounded-panneau border border-ligne px-3 py-2 text-sm"
        :data-ma-demande-rgpd="demande.id"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="font-medium">{{ libelleNature(demande.nature) }}</span>
          <PastilleEtat :etat="pastilleDeDemande(demande)" :libelle="etatDeDemande(demande)" />
        </div>
        <p v-if="composantesARetirer(demande) !== null" class="m-0 text-xs text-attenue">
          À retirer : {{ composantesARetirer(demande) }}
        </p>
        <p v-if="demande.message" class="m-0 whitespace-pre-line text-xs">{{ demande.message }}</p>
        <p class="m-0 text-xs text-attenue">Demandée le {{ dateDuVerrou(demande.creeeLe) }}</p>
        <p v-if="demande.reponse" class="m-0 whitespace-pre-line text-xs" data-reponse>
          Réponse : {{ demande.reponse }}
        </p>
      </li>
    </ul>
    <p
      v-else-if="!chargement && erreurLecture === null && !suspendu"
      class="m-0 text-xs text-attenue"
    >
      Aucune demande pour l’instant.
    </p>
  </section>
</template>
