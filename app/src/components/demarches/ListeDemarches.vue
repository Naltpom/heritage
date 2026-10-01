<script setup lang="ts">
/**
 * Les démarches — celles d'une fiche, ou celles de toute la famille — et le
 * bouton pour en enregistrer une.
 *
 * Sur une fiche, la personne est désignée d'office dans le formulaire : on
 * enregistre la demande d'acte là où l'on regarde la personne.
 *
 * Une démarche refusée ou abandonnée se **refait autrement** : la carte le
 * propose, et le formulaire de création s'ouvre ici, prérempli, avec le lien
 * « suite de ». Les liens entre démarches (« Suite de la démarche n° X »,
 * « Refaite : démarche n° Y ») font défiler jusqu'à la carte visée et l'ouvrent.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  listerDemarches,
  type DetailDeDemarche,
  type PersonneDeLaDemarche,
  type ResumeDeDemarche,
  type StatutDeDemarche,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import { useSession } from '@/composables/useSession'
import CarteDemarche from './CarteDemarche.vue'
import FormulaireDemarche from './FormulaireDemarche.vue'
import {
  STATUTS_EN_ATTENTE,
  TOUS_LES_STATUTS,
  compterParStatut,
  correspondARecherche,
  memesStatuts,
  type ComptesDeDemarches,
} from './demarches'

const { personne = null, statuts = null, recherche = '' } = defineProps<{
  /** Présente : les démarches de cette personne seulement. */
  personne?: PersonneDeLaDemarche | null
  /** Les statuts montrés ; `null` : tous, sans filtre (la fiche d'une personne). */
  statuts?: readonly StatutDeDemarche[] | null
  /** Des mots cherchés dans l'intitulé, les personnes, le service, les adresses. */
  recherche?: string
}>()

const emit = defineEmits<{ comptes: [comptes: ComptesDeDemarches] }>()

const route = useRoute()
const router = useRouter()
const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

const demarches = ref<ResumeDeDemarche[] | null>(null)
const erreur = ref<unknown>(null)
const enCreation = ref(false)
/** La démarche refaite autrement : le formulaire s'ouvre prérempli d'elle. */
const modele = ref<ResumeDeDemarche | null>(null)
/** Par carte, un compteur d'ouvertures demandées : chaque incrément l'ouvre. */
const ouvertures = ref(new Map<number, number>())
let avorteur: AbortController | null = null

/**
 * Tout est chargé une fois, et filtré ici : les pastilles et la recherche se
 * répondent sans aller-retour, et les compteurs restent justes. À l'échelle
 * d'une famille — quelques dizaines de démarches, sans le texte des
 * messages —, c'est moins qu'une vignette.
 *
 * Une démarche qu'on vient de faire changer de statut **reste affichée** même
 * si son nouveau statut n'est plus coché, jusqu'au prochain chargement : la
 * voir disparaître sous le bouton qu'on vient de presser ferait croire à une
 * perte. Une démarche qu'on rejoint par un lien « suite de » s'affiche aussi,
 * quels que soient le filtre et la recherche.
 */
const gardees = ref(new Set<number>())

const visibles = computed(() =>
  (demarches.value ?? []).filter(
    (d) =>
      gardees.value.has(d.id) ||
      ((statuts === null || statuts.includes(d.statut)) && correspondARecherche(d, recherche)),
  ),
)

const nombreARelancer = computed(() => visibles.value.filter((d) => d.aRelancer).length)

const filtreEnAttente = computed(() => statuts !== null && memesStatuts(statuts, STATUTS_EN_ATTENTE))
const filtrePartiel = computed(() => statuts !== null && !memesStatuts(statuts, TOUS_LES_STATUTS))

watch(
  demarches,
  (liste) => {
    if (liste !== null) emit('comptes', compterParStatut(liste))
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
    return
  }

  // Arrivée par un lien `#demarche-12` (depuis la fiche d'une personne) : on
  // ouvre la démarche visée.
  const vise = /^#demarche-(\d+)$/.exec(route.hash)
  if (vise !== null) void allerA(Number(vise[1]))
}

function changee(relue: ResumeDeDemarche): void {
  if (demarches.value === null) return
  gardees.value = new Set([...gardees.value, relue.id])
  demarches.value = demarches.value.map((d) => (d.id === relue.id ? relue : d))
}

function supprimee(id: number): void {
  if (demarches.value === null) return
  // Celles qui la reprenaient perdent le lien, comme en base.
  demarches.value = demarches.value
    .filter((d) => d.id !== id)
    .map((d) => (d.precedente?.id === id ? { ...d, precedente: null } : d))
}

function enregistree(creee: DetailDeDemarche): void {
  enCreation.value = false
  modele.value = null
  const reprise = creee.precedente
  // La démarche reprise apprend tout de suite qu'elle a été refaite : sa carte
  // dit « Refaite : démarche n° … » sans attendre un rechargement.
  const liste = (demarches.value ?? []).map((d) =>
    reprise !== null && d.id === reprise.id ? { ...d, suites: [...d.suites, { id: creee.id, statut: creee.statut }] } : d,
  )
  demarches.value = [creee, ...liste]
}

function nouvelle(): void {
  modele.value = null
  enCreation.value = true
}

async function refaire(d: ResumeDeDemarche): Promise<void> {
  modele.value = d
  enCreation.value = true
  await nextTick()
  if (typeof document !== 'undefined') document.getElementById('nouvelle-demarche')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function annulerCreation(): void {
  enCreation.value = false
  modele.value = null
}

/**
 * Rejoindre une démarche liée : on la montre (même hors filtre), on l'ouvre,
 * on fait défiler jusqu'à elle. Si elle n'est pas dans cette liste — sur la
 * fiche d'une personne, la démarche reprise peut en concerner d'autres —, on
 * part vers la page « Actes demandés », tous statuts, où elle sera ouverte.
 */
async function allerA(id: number): Promise<void> {
  if (!(demarches.value ?? []).some((d) => d.id === id)) {
    await router.push({ name: 'demarches', query: { statuts: 'toutes' }, hash: `#demarche-${id}` })
    return
  }

  gardees.value = new Set([...gardees.value, id])
  ouvertures.value = new Map(ouvertures.value).set(id, (ouvertures.value.get(id) ?? 0) + 1)
  await nextTick()
  if (typeof document !== 'undefined') document.getElementById(`demarche-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

watch(() => personne?.personId, charger, { immediate: true })
watch(
  () => statuts?.join(','),
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
      <BoutonBase v-if="!enCreation" variante="secondaire" :desactive="suspendu" @click="nouvelle">
        Enregistrer une demande d’acte
      </BoutonBase>
    </div>

    <p v-if="motifSuspendu !== null" class="rounded-panneau border border-ligne bg-panneau p-3 text-xs text-attenue">
      {{ motifSuspendu }}
    </p>

    <section v-if="enCreation" id="nouvelle-demarche" class="scroll-mt-4 rounded-panneau border border-ligne bg-panneau p-4">
      <h3 class="mb-3 text-sm font-semibold">
        {{ modele === null ? 'Nouvelle demande d’acte' : `Refaire autrement la démarche n° ${modele.id}` }}
      </h3>
      <FormulaireDemarche
        :key="modele?.id ?? 0"
        :personnes-initiales="personne === null ? [] : [personne]"
        :modele="modele"
        :suspendu="suspendu"
        @enregistree="enregistree"
        @annuler="annulerCreation"
      />
    </section>

    <SqueletteChargement v-if="demarches === null && erreur === null" :lignes="2" />
    <MessageErreur v-else-if="demarches === null" :erreur="erreur" titre="Les démarches n’ont pas pu être chargées" @action="charger" />

    <template v-else>
      <p
        v-if="visibles.length === 0 && !enCreation"
        class="rounded-panneau border border-ligne bg-panneau p-4 text-sm text-attenue"
      >
        <template v-if="statuts !== null && statuts.length === 0">Aucun statut coché : choisissez-en au moins un.</template>
        <template v-else-if="recherche.trim().length > 0">
          Aucune démarche ne correspond à « {{ recherche.trim() }} »<template v-if="filtrePartiel">
            parmi les statuts cochés — essayez « Toutes »</template>.
        </template>
        <template v-else-if="personne !== null">Aucun acte demandé pour cette personne.</template>
        <template v-else-if="filtreEnAttente">Aucune démarche en attente.</template>
        <template v-else-if="filtrePartiel">Aucune démarche avec les statuts cochés.</template>
        <template v-else>Aucun acte demandé pour l’instant.</template>
      </p>

      <ul class="m-0 flex list-none flex-col gap-3 p-0">
        <li v-for="d in visibles" :id="`demarche-${d.id}`" :key="d.id" class="scroll-mt-4">
          <CarteDemarche
            :demarche="d"
            :suspendu="suspendu"
            :avec-liens-vers-les-fiches="personne === null || d.personnes.length > 1"
            :ouverture="ouvertures.get(d.id) ?? 0"
            @changee="changee"
            @supprimee="supprimee"
            @refaire="refaire"
            @aller="allerA"
          />
        </li>
      </ul>
    </template>
  </div>
</template>
