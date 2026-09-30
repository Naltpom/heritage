<script setup lang="ts">
/**
 * Les démarches — celles d'une fiche, ou celles de toute la famille — et le
 * bouton pour en enregistrer une.
 *
 * Sur une fiche, la personne est désignée d'office dans le formulaire : on
 * enregistre la demande d'acte là où l'on regarde la personne.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { listerDemarches, type DetailDeDemarche, type PersonneDeLaDemarche, type ResumeDeDemarche } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import { useSession } from '@/composables/useSession'
import CarteDemarche from './CarteDemarche.vue'
import FormulaireDemarche from './FormulaireDemarche.vue'
import { aRelancer, correspondARecherche } from './demarches'

const { personne = null, enAttenteSeulement = false, recherche = '' } = defineProps<{
  /** Présente : les démarches de cette personne seulement. */
  personne?: PersonneDeLaDemarche | null
  enAttenteSeulement?: boolean
  /** Des mots cherchés dans l'intitulé, les personnes, le service, les adresses. */
  recherche?: string
}>()

const emit = defineEmits<{ comptes: [comptes: { total: number; enAttente: number }] }>()

const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

const demarches = ref<ResumeDeDemarche[] | null>(null)
const erreur = ref<unknown>(null)
const enCreation = ref(false)
let avorteur: AbortController | null = null

/**
 * Tout est chargé une fois, et filtré ici : l'onglet et la recherche se
 * répondent sans aller-retour, et les deux compteurs restent justes. À
 * l'échelle d'une famille — quelques dizaines de démarches, sans le texte des
 * messages —, c'est moins qu'une vignette.
 *
 * Une démarche qu'on vient de faire passer à « reçue » **reste affichée** dans
 * l'onglet « en attente » jusqu'au prochain chargement : la voir disparaître
 * sous le bouton qu'on vient de presser ferait croire à une perte.
 */
const gardees = ref(new Set<number>())

const visibles = computed(() =>
  (demarches.value ?? []).filter(
    (d) =>
      (!enAttenteSeulement || d.enAttente || gardees.value.has(d.id)) && correspondARecherche(d, recherche),
  ),
)

const nombreARelancer = computed(() => visibles.value.filter((d) => aRelancer(d)).length)

watch(
  demarches,
  (liste) => {
    if (liste !== null) emit('comptes', { total: liste.length, enAttente: liste.filter((d) => d.enAttente).length })
  },
  { deep: true },
)

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  erreur.value = null

  const filtre: { personId?: number } = {}
  if (personne !== null) filtre.personId = personne.personId
  gardees.value = new Set()

  try {
    demarches.value = (await listerDemarches(filtre, avorteur.signal)).elements
  } catch (e) {
    if (!avorteur.signal.aborted) erreur.value = e
  }
}

function changee(relue: ResumeDeDemarche): void {
  if (demarches.value === null) return
  gardees.value = new Set([...gardees.value, relue.id])
  demarches.value = demarches.value.map((d) => (d.id === relue.id ? relue : d))
}

function supprimee(id: number): void {
  if (demarches.value === null) return
  demarches.value = demarches.value.filter((d) => d.id !== id)
}

function enregistree(creee: DetailDeDemarche): void {
  enCreation.value = false
  demarches.value = [creee, ...(demarches.value ?? [])]
}

watch(() => personne?.personId, charger, { immediate: true })
watch(
  () => enAttenteSeulement,
  () => {
    gardees.value = new Set()
  },
)
onBeforeUnmount(() => avorteur?.abort())
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-sm text-attenue">
        <template v-if="demarches !== null && visibles.length > 0">
          {{ visibles.length }} démarche{{ visibles.length > 1 ? 's' : '' }}<template v-if="nombreARelancer > 0">
            · <span class="font-semibold text-alerte">{{ nombreARelancer }} à relancer</span></template>
        </template>
      </p>
      <BoutonBase v-if="!enCreation" variante="secondaire" :desactive="suspendu" @click="enCreation = true">
        Enregistrer une demande d’acte
      </BoutonBase>
    </div>

    <p v-if="motifSuspendu !== null" class="rounded-panneau border border-ligne bg-panneau p-3 text-xs text-attenue">
      {{ motifSuspendu }}
    </p>

    <section v-if="enCreation" class="rounded-panneau border border-ligne bg-panneau p-4">
      <h3 class="mb-3 text-sm font-semibold">Nouvelle demande d’acte</h3>
      <FormulaireDemarche
        :personnes-initiales="personne === null ? [] : [personne]"
        :suspendu="suspendu"
        @enregistree="enregistree"
        @annuler="enCreation = false"
      />
    </section>

    <SqueletteChargement v-if="demarches === null && erreur === null" :lignes="2" />
    <MessageErreur v-else-if="demarches === null" :erreur="erreur" titre="Les démarches n’ont pas pu être chargées" @action="charger" />

    <template v-else>
      <p
        v-if="visibles.length === 0 && !enCreation"
        class="rounded-panneau border border-ligne bg-panneau p-4 text-sm text-attenue"
      >
        <template v-if="recherche.trim().length > 0">
          Aucune démarche ne correspond à « {{ recherche.trim() }} »<template v-if="enAttenteSeulement">
            parmi celles en attente — essayez « Toutes »</template>.
        </template>
        <template v-else-if="personne !== null">Aucun acte demandé pour cette personne.</template>
        <template v-else-if="enAttenteSeulement">Aucune démarche en attente de réponse.</template>
        <template v-else>Aucun acte demandé pour l’instant.</template>
      </p>

      <ul class="m-0 flex list-none flex-col gap-3 p-0">
        <li v-for="d in visibles" :key="d.id">
          <CarteDemarche
            :demarche="d"
            :suspendu="suspendu"
            :avec-liens-vers-les-fiches="personne === null || d.personnes.length > 1"
            @changee="changee"
            @supprimee="supprimee"
          />
        </li>
      </ul>
    </template>
  </div>
</template>
