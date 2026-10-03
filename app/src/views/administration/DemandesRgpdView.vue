<script setup lang="ts">
/**
 * « RGPD » — les demandes des membres sur leurs données (ADR 0016, révision
 * du 3 octobre 2026).
 *
 * Une composante de date de naissance partagée reste visible au moins trente
 * jours ; la retirer plus tôt, rectifier une donnée ou toute autre demande
 * arrive ici, faite depuis « Mon compte ». La notification `demande_rgpd` y
 * mène (`linkRoute: 'administration.rgpd'`).
 *
 * La liste (`GET /api/administration/demandes-rgpd`) arrive dans l'ordre du
 * serveur : en attente d'abord, de la plus ancienne à la plus récente, puis les
 * traitées. Chaque demande en attente porte :
 *
 *  - **Appliquer** — une réponse facultative. Appliquer un `retrait_partage`
 *    retire les composantes **tout de suite**, verrou ou pas : c'est le
 *    serveur qui le fait, pas l'administrateur à la main ;
 *  - **Refuser** — la réponse est exigée : c'est tout ce que le membre en
 *    saura.
 *
 * La ligne traitée revient de la réponse et prend sa place. `409
 * demande_deja_traitee` : un autre administrateur est passé avant — la liste
 * est relue et le dit.
 *
 * Sous la bascule « voir comme », l'écran ne lit rien et le dit, avec le moyen
 * de sortir de la vue : une demande RGPD n'a pas de version « vue par un
 * membre ».
 */
import { computed, onMounted, ref, watch } from 'vue'
import {
  estErreurApi,
  listerDemandesRgpd,
  quitterVueComme,
  traiterDemandeRgpd,
  type DecisionDemandeRgpd,
  type DemandeRgpdAdministration,
} from '@/lib/api'
import { rafraichirSession, useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import OngletsAdministration from './OngletsAdministration.vue'
import {
  ecrituresSuspendues,
  infobulleSuspension,
  motifSuspension,
} from '@/components/administration/ecriture'
import {
  composantesARetirer,
  etatDeDemande,
  libelleNature,
  pastilleDeDemande,
} from '@/components/compte/demandesRgpd'
import { dateDuVerrou } from '@/components/compte/partageNaissance'

/** Le code d'une demande qu'un autre administrateur a traitée entre-temps. */
const CODE_DEJA_TRAITEE = 'demande_deja_traitee'

const { vueEmpruntee } = useSession()

const demandes = ref<DemandeRgpdAdministration[]>([])
const chargement = ref(false)
const erreur = ref<unknown>(null)

/** La réponse en cours de saisie, par demande. */
const reponses = ref<Record<number, string>>({})
/** Le refus du formulaire ou du serveur, par demande. */
const erreurs = ref<Record<number, unknown>>({})
/** « Une réponse est exigée pour refuser. », par demande. */
const reponseManquante = ref<Record<number, boolean>>({})
const enCours = ref<number | null>(null)
const dejaTraitee = ref(false)

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))
const enAttente = computed(() => demandes.value.filter((d) => d.decision === null).length)

async function charger(): Promise<void> {
  if (suspendu.value) {
    demandes.value = []
    return
  }
  chargement.value = true
  erreur.value = null
  try {
    demandes.value = (await listerDemandesRgpd()).elements
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

onMounted(charger)
// Entrer ou sortir de la vue empruntée change ce que l'écran a le droit de lire.
watch(suspendu, () => void charger())

function saisir(id: number, valeur: string): void {
  reponses.value = { ...reponses.value, [id]: valeur }
  if (valeur.trim() !== '') reponseManquante.value = { ...reponseManquante.value, [id]: false }
}

async function traiter(demande: DemandeRgpdAdministration, decision: DecisionDemandeRgpd): Promise<void> {
  if (enCours.value !== null || suspendu.value) return
  const reponse = (reponses.value[demande.id] ?? '').trim()
  erreurs.value = { ...erreurs.value, [demande.id]: null }
  dejaTraitee.value = false
  if (decision === 'refusee' && reponse === '') {
    reponseManquante.value = { ...reponseManquante.value, [demande.id]: true }
    return
  }
  enCours.value = demande.id
  try {
    const traitee = await traiterDemandeRgpd(demande.id, decision, reponse === '' ? undefined : reponse)
    demandes.value = demandes.value.map((d) => (d.id === traitee.id ? traitee : d))
  } catch (cause) {
    if (estErreurApi(cause) && cause.code === CODE_DEJA_TRAITEE) {
      dejaTraitee.value = true
      await charger()
    } else {
      erreurs.value = { ...erreurs.value, [demande.id]: cause }
    }
  } finally {
    enCours.value = null
  }
}

async function quitter(): Promise<void> {
  erreur.value = null
  try {
    await quitterVueComme()
    await rafraichirSession()
  } catch (cause) {
    erreur.value = cause
  }
}
</script>

<template>
  <BarreEcran ecran="Administration" :reinitialisable="false">
    <template #vue><OngletsAdministration /></template>
  </BarreEcran>

  <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 px-4 py-6">
    <div
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      <p>{{ raisonSuspension }}</p>
      <p class="mt-1">Les demandes RGPD ne s’affichent pas sous une vue empruntée.</p>
      <div class="mt-2">
        <BoutonBase variante="secondaire" @click="quitter">Quitter la vue empruntée</BoutonBase>
      </div>
    </div>

    <p v-else class="text-sm text-attenue">
      Les demandes des membres sur leurs données. Appliquer un retrait de date de naissance le fait
      tout de suite, même avant la fin des 30 jours. Un refus doit être expliqué au membre.
    </p>

    <p v-if="dejaTraitee" class="m-0 text-sm text-alerte" role="alert" data-deja-traitee>
      Cette demande a déjà été traitée par un autre administrateur. La liste est à jour.
    </p>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="charger" />

    <SqueletteChargement v-if="chargement && demandes.length === 0" :lignes="4" />

    <p
      v-else-if="!chargement && erreur === null && raisonSuspension === null && demandes.length === 0"
      class="rounded-panneau border border-ligne bg-panneau px-4 py-6 text-center text-sm text-attenue"
      role="status"
    >
      Aucune demande RGPD.
    </p>

    <p v-if="demandes.length > 0" class="m-0 text-xs text-attenue" data-compte-en-attente>
      {{ enAttente === 0 ? 'Aucune en attente.' : enAttente === 1 ? '1 en attente.' : `${enAttente} en attente.` }}
    </p>

    <article
      v-for="demande in demandes"
      :key="demande.id"
      class="flex flex-col gap-2 rounded-panneau border border-ligne bg-panneau px-4 py-3 text-sm"
      :data-demande-rgpd="demande.id"
    >
      <header class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-col">
          <span class="font-semibold">{{ libelleNature(demande.nature) }}</span>
          <span class="text-xs text-attenue">
            {{ demande.userEmail }} — le {{ dateDuVerrou(demande.creeeLe) }}
          </span>
        </div>
        <PastilleEtat :etat="pastilleDeDemande(demande)" :libelle="etatDeDemande(demande)" />
      </header>

      <p v-if="composantesARetirer(demande) !== null" class="m-0">
        À retirer : <strong>{{ composantesARetirer(demande) }}</strong>
      </p>
      <p v-if="demande.message" class="m-0 whitespace-pre-line">{{ demande.message }}</p>
      <p v-if="demande.reponse" class="m-0 whitespace-pre-line text-xs text-attenue">
        Réponse : {{ demande.reponse }}
      </p>

      <form
        v-if="demande.decision === null"
        class="flex flex-col gap-2"
        novalidate
        @submit.prevent="traiter(demande, 'appliquee')"
      >
        <label :for="`reponse-rgpd-${demande.id}`" class="text-xs font-medium">
          Réponse au membre (exigée pour refuser)
        </label>
        <textarea
          :id="`reponse-rgpd-${demande.id}`"
          :value="reponses[demande.id] ?? ''"
          rows="2"
          class="rounded-champ border bg-fond px-2.5 py-1.5 text-sm text-texte"
          :class="reponseManquante[demande.id] ? 'border-alerte' : 'border-ligne'"
          :disabled="suspendu"
          data-reponse-rgpd
          @input="saisir(demande.id, ($event.target as HTMLTextAreaElement).value)"
        />
        <p v-if="reponseManquante[demande.id]" class="m-0 text-xs text-alerte" data-reponse-manquante>
          Une réponse est exigée pour refuser.
        </p>
        <MessageErreur v-if="erreurs[demande.id]" :erreur="erreurs[demande.id]" />
        <div class="flex flex-wrap gap-2" :title="infobulle">
          <BoutonBase
            type="submit"
            :chargement="enCours === demande.id"
            :desactive="suspendu || (enCours !== null && enCours !== demande.id)"
          >
            Appliquer
          </BoutonBase>
          <BoutonBase
            variante="danger"
            :desactive="suspendu || enCours !== null"
            @click="traiter(demande, 'refusee')"
          >
            Refuser
          </BoutonBase>
        </div>
      </form>
    </article>
  </div>
</template>
