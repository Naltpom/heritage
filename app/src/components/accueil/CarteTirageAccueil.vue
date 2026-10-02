<script setup lang="ts">
/**
 * Un tirage sur l'accueil : replié, une ligne ; déplié, sa présence.
 *
 * **Le cadeau est hors du repli.** Une fois le tirage fait, « Voir à qui je
 * fais le cadeau » s'affiche sans rien déplier, en violet : c'est la seule
 * raison pour laquelle on ouvre l'application ce jour-là, elle ne doit pas se
 * chercher. Le nom reste **absent du DOM** jusqu'au geste, comme dans
 * `CarteAttribution` : l'accueil s'ouvre volontiers devant d'autres.
 *
 * **Déplié, sa propre présence se règle d'un geste** (sur place, à distance,
 * ne participe pas), tant que l'édition est ouverte. Une fois le tirage fait,
 * changer de présence change ce que l'autre reçoit : ce geste-là reste sur
 * l'écran du tirage, qui le fait confirmer.
 */
import { computed, ref } from 'vue'
import { definirPresence, type PersonneDuTirage, type PresenceAuTirage, type ResumeDeTirage } from '@/lib/api'
import Segmente from '@/components/mise-en-page/Segmente.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import { PRESENCES, consigneDeRemise, phraseDAttribution } from '@/components/tirages/tirages'
import { dateDuTirage } from '@/components/evenements-familiaux/liste-commune'
import { dansCombien, etatDuTirageCeJour, libelleDate } from './jours'

const { tirage, moi, aujourdhui } = defineProps<{
  tirage: ResumeDeTirage
  moi: PersonneDuTirage | null
  aujourdhui: Date
}>()

const emit = defineEmits<{ maj: [tirage: ResumeDeTirage] }>()

const ouvert = ref(false)
const devoiles = ref(new Set<number>())
const enCours = ref(false)
const erreur = ref<unknown>(null)

// La date qui range aussi la carte dans « Mes événements » : l'édition ouverte
// quand elle est à venir (un Noël fêté le 26), sinon le jour habituel.
const date = computed(() => dateDuTirage(tirage, aujourdhui))
const edition = computed(() => tirage.prochaineEdition)
const maPresence = computed(() => edition.value?.presences.find((p) => p.personId === moi?.personId) ?? null)
const reglable = computed(() => edition.value !== null && edition.value.statut === 'ouverte' && !edition.value.passee && maPresence.value !== null)
const attributions = computed(() => (edition.value?.statut === 'tiree' ? edition.value.mesAttributions : []))

function libelleDuBouton(donneur: PersonneDuTirage): string {
  return donneur.personId === moi?.personId ? 'Voir à qui je fais le cadeau' : `Voir à qui ${donneur.libelle} fait le cadeau`
}

function devoiler(personId: number): void {
  devoiles.value = new Set(devoiles.value).add(personId)
}

async function choisirPresence(presence: PresenceAuTirage): Promise<void> {
  const e = edition.value
  if (e === null || moi === null || enCours.value || presence === maPresence.value?.presence) return
  enCours.value = true
  erreur.value = null
  try {
    emit('maj', await definirPresence(tirage.id, e.annee, moi.personId, presence))
  } catch (err: unknown) {
    erreur.value = err
  } finally {
    enCours.value = false
  }
}
</script>

<template>
  <article class="rounded-panneau border border-ligne bg-panneau">
    <button
      type="button"
      class="flex w-full items-center gap-3 px-4 py-3 text-left"
      :aria-expanded="ouvert"
      :aria-controls="`tirage-${tirage.id}`"
      @click="ouvert = !ouvert"
    >
      <span class="min-w-0 flex-1">
        <span class="block truncate text-sm font-semibold text-texte">{{ tirage.nom }}</span>
        <span class="block truncate text-xs text-attenue">
          <!-- Sur l'accueil, rassemblements et tirages se suivent : le type se dit. -->
          <span class="font-semibold text-cadeau">Tirage au sort</span> ·
          {{ libelleDate(date) }} · {{ dansCombien(date, aujourdhui) }}<template v-if="tirage.lieu"> · {{ tirage.lieu }}</template>
        </span>
      </span>
      <span v-if="maPresence" class="shrink-0 rounded-pastille bg-accent-doux px-2 py-0.5 text-xs font-semibold text-accent">
        {{ maPresence.presenceLibelle }}
      </span>
      <svg
        class="shrink-0 text-attenue transition-transform"
        :class="ouvert ? 'rotate-180' : ''"
        width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"
      >
        <path d="M3 5l4 4 4-4" />
      </svg>
    </button>

    <!-- Le cadeau : visible sans rien déplier. -->
    <div v-if="attributions.length > 0" class="flex flex-col gap-2 px-4 pb-3">
      <template v-for="a in attributions" :key="a.donneur.personId">
        <div v-if="devoiles.has(a.donneur.personId)" class="rounded-panneau border border-cadeau bg-cadeau-doux p-3" aria-live="polite">
          <p class="text-sm text-texte">{{ phraseDAttribution(a, moi?.personId ?? null) }}</p>
          <p class="text-lg font-semibold text-cadeau">{{ a.receveur.libelle }}</p>
          <p class="mt-1 text-xs text-attenue">{{ consigneDeRemise(a.presenceDuDonneur) }}</p>
        </div>
        <button
          v-else
          type="button"
          class="flex w-full items-center justify-center gap-2 rounded-panneau bg-cadeau px-4 py-3 text-sm font-semibold text-cadeau-texte shadow-sm transition-opacity hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cadeau"
          @click="devoiler(a.donneur.personId)"
        >
          <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="8" width="14" height="9" rx="1" /><path d="M2 5h16v3H2zM10 5v12" />
            <path d="M10 5C8 1 5 2 6.5 4.5 7.2 5.2 10 5 10 5zM10 5c2-4 5-3 3.5-.5C12.8 5.2 10 5 10 5z" />
          </svg>
          {{ libelleDuBouton(a.donneur) }}
        </button>
      </template>
    </div>

    <div v-show="ouvert" :id="`tirage-${tirage.id}`" class="flex flex-col gap-3 border-t border-ligne px-4 py-3">
      <p v-if="edition === null" class="text-sm text-attenue">{{ etatDuTirageCeJour(tirage, date) }}.</p>

      <template v-else>
        <div v-if="reglable" class="flex flex-col gap-1.5">
          <p class="text-xs font-semibold text-attenue">Ma présence en {{ edition.annee }}</p>
          <Segmente
            :options="PRESENCES"
            :valeur="maPresence?.presence ?? 'sur_place'"
            etiquette="Ma présence"
            :colonnes="3"
            @choisir="choisirPresence"
          />
        </div>
        <p v-else-if="maPresence" class="text-sm text-texte">
          Ma présence : <span class="font-semibold">{{ maPresence.presenceLibelle }}</span>
          <span v-if="edition.statut === 'tiree'" class="block text-xs text-attenue">Le tirage est fait : pour changer, ouvrez le tirage.</span>
        </p>

        <MessageErreur v-if="erreur !== null" :erreur="erreur" titre="La présence n’a pas été enregistrée" />

        <div v-if="edition.presences.length > 0">
          <p class="mb-1 text-xs font-semibold text-attenue">Qui vient</p>
          <ul class="flex flex-col divide-y divide-ligne text-sm">
            <li v-for="p in edition.presences" :key="p.personId" class="flex items-center justify-between gap-3 py-1.5">
              <span class="min-w-0 truncate">{{ p.libelle }}</span>
              <span class="shrink-0 text-xs" :class="p.presence === 'absente' ? 'text-attenue' : 'text-texte'">{{ p.presenceLibelle }}</span>
            </li>
          </ul>
        </div>
      </template>

      <RouterLink :to="{ name: 'tirage.detail', params: { id: tirage.id } }" class="self-start text-xs text-accent underline">
        Ouvrir le tirage
      </RouterLink>
    </div>
  </article>
</template>
