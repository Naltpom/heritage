<script setup lang="ts">
/**
 * Les événements de famille — « Cousinade été 2027 ».
 *
 * Deux listes : **à venir** (en cours compris), la plus proche d'abord ; puis
 * **passés et annulés**, repliés — on vient ici pour ce qui arrive. On n'y voit
 * que les événements où l'on est invité, ceux qu'on organise, et — pour un
 * administrateur — tous.
 *
 * Chaque ligne dit en un coup d'œil ce qu'on cherche : quand, où, et **sa
 * propre réponse** (ou qu'on n'a pas encore répondu). Répondre se fait sur la
 * page de l'événement, qui dit tout ; la liste ne fait que mener.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { listerEvenements, type ListeDEvenements, type ResumeDEvenement } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import {
  bilan,
  periodeEnTete,
  quand,
  repartir,
} from '@/components/evenements-familiaux/evenements'
import { useSession } from '@/composables/useSession'

const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

const aujourdhui = new Date()
const liste = ref<ListeDEvenements | null>(null)
const erreur = ref<unknown>(null)
let avorteur: AbortController | null = null

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  erreur.value = null

  try {
    liste.value = await listerEvenements(avorteur.signal)
  } catch (e) {
    erreur.value = e
  }
}

const listes = computed(() => repartir(liste.value?.evenements ?? [], aujourdhui))

function maReponse(e: ResumeDEvenement): { texte: string; classe: string } | null {
  if (!e.suisInvite) return null
  if (e.maReponse === 'present') return { texte: 'Présent', classe: 'bg-ok-doux text-ok' }
  if (e.maReponse === 'absent') return { texte: 'Absent', classe: 'border border-ligne text-attenue' }
  if (!e.peutRepondre) return null
  return { texte: 'À répondre', classe: 'bg-accent text-fond' }
}

onMounted(charger)
onBeforeUnmount(() => avorteur?.abort())
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-6">
    <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-texte">Événements</h1>
        <p class="mt-1 text-sm text-attenue">
          Cousinades, anniversaires, retrouvailles : qui vient, en un geste.
        </p>
      </div>
      <RouterLink
        v-if="!suspendu"
        :to="{ name: 'evenement.nouveau' }"
        class="inline-flex min-h-11 items-center justify-center rounded-champ border border-accent bg-accent px-4 text-sm font-semibold text-fond hover:opacity-90"
      >
        Créer un événement
      </RouterLink>
      <BoutonBase v-else desactive>Créer un événement</BoutonBase>
    </header>

    <p v-if="motifSuspendu !== null" class="mb-4 rounded-panneau border border-ligne bg-panneau p-3 text-xs text-attenue">
      {{ motifSuspendu }}
    </p>

    <SqueletteChargement v-if="liste === null && erreur === null" :lignes="3" />

    <MessageErreur v-else-if="liste === null" :erreur="erreur" titre="Les événements n’ont pas pu être chargés" @action="charger" />

    <template v-else>
      <p
        v-if="listes.aVenir.length === 0"
        class="rounded-panneau border border-ligne bg-panneau p-6 text-sm text-attenue"
      >
        Aucun événement à venir. Créez le prochain : la famille sera prévenue.
      </p>

      <ul class="flex flex-col gap-3">
        <li v-for="e in listes.aVenir" :key="e.id">
          <RouterLink
            :to="{ name: 'evenement.detail', params: { id: e.id } }"
            class="flex items-center gap-3 rounded-panneau border border-ligne bg-panneau p-4 transition-colors hover:border-accent"
          >
            <span class="min-w-0 flex-1">
              <span class="block truncate font-semibold text-texte">{{ e.titre }}</span>
              <span class="mt-0.5 block text-sm text-texte">{{ periodeEnTete(e.debut, e.fin) }}</span>
              <span class="mt-0.5 block truncate text-xs text-attenue">
                {{ quand(e, aujourdhui) }}<template v-if="e.lieu"> · {{ e.lieu }}</template>
                · {{ bilan(e.nombreDePresents, e.nombreDInvites) }}
              </span>
            </span>
            <span class="flex shrink-0 flex-col items-end gap-1">
              <span v-if="maReponse(e)" class="rounded-pastille px-2 py-0.5 text-xs font-semibold" :class="maReponse(e)?.classe">
                {{ maReponse(e)?.texte }}
              </span>
              <span v-if="e.organisateur.estMoi" class="rounded-pastille bg-accent-doux px-2 py-0.5 text-xs text-texte">Vous organisez</span>
            </span>
          </RouterLink>
        </li>
      </ul>

      <details v-if="listes.passes.length > 0" class="mt-6">
        <summary class="min-h-11 cursor-pointer py-2 text-sm font-semibold text-texte">
          Passés et annulés ({{ listes.passes.length }})
        </summary>
        <ul class="mt-2 flex flex-col gap-2">
          <li v-for="e in listes.passes" :key="e.id">
            <RouterLink
              :to="{ name: 'evenement.detail', params: { id: e.id } }"
              class="flex min-h-11 items-center justify-between gap-3 rounded-panneau border border-ligne bg-panneau px-4 py-3 text-sm"
            >
              <span class="min-w-0">
                <span class="block truncate text-texte" :class="e.annule ? 'line-through' : ''">{{ e.titre }}</span>
                <span class="block text-xs text-attenue">{{ periodeEnTete(e.debut, e.fin) }}</span>
              </span>
              <span class="shrink-0 text-xs text-attenue">{{ quand(e, aujourdhui) }}</span>
            </RouterLink>
          </li>
        </ul>
      </details>
    </template>
  </div>
</template>
