<script setup lang="ts">
/**
 * La pagination d'une liste **paginée par l'API** (§ 1.5) : « ‹ Précédente »,
 * « 26–50 sur 102 · page 2 sur 5 », « Suivante › ».
 *
 * Elle ne tient aucun état : la page vit dans l'écran (et dans l'adresse), qui
 * relit la page demandée au serveur. Une liste qui tient en une page ne
 * l'affiche pas — deux boutons éteints n'apprennent rien.
 *
 * Les boutons gardent 44 px de haut sur téléphone, comme les puces.
 */
import { computed } from 'vue'
import { bornesDeLaPage, nombreDePages } from './pagination'

const { page, parPage, total, chargement = false } = defineProps<{
  /** Commence à 1. */
  page: number
  parPage: number
  total: number
  /** Une page est en cours de lecture : les boutons attendent. */
  chargement?: boolean
}>()

const emit = defineEmits<{ aller: [page: number] }>()

const pages = computed(() => nombreDePages(total, parPage))
const bornes = computed(() => bornesDeLaPage(page, parPage, total))
</script>

<template>
  <nav
    v-if="pages > 1"
    class="flex flex-wrap items-center justify-between gap-2"
    aria-label="Pagination"
    data-pagination
  >
    <button
      type="button"
      class="inline-flex min-h-11 items-center rounded-champ border border-ligne bg-panneau px-3 text-[13.5px] hover:border-accent disabled:cursor-not-allowed disabled:opacity-55 md:min-h-9"
      :disabled="page <= 1 || chargement"
      data-page-precedente
      @click="emit('aller', page - 1)"
    >
      ‹ Précédente
    </button>
    <span class="text-center text-[13px] text-attenue tnum" aria-live="polite">
      {{ bornes.debut }}–{{ bornes.fin }} sur {{ total }} · page {{ page }} sur {{ pages }}
    </span>
    <button
      type="button"
      class="inline-flex min-h-11 items-center rounded-champ border border-ligne bg-panneau px-3 text-[13.5px] hover:border-accent disabled:cursor-not-allowed disabled:opacity-55 md:min-h-9"
      :disabled="page >= pages || chargement"
      data-page-suivante
      @click="emit('aller', page + 1)"
    >
      Suivante ›
    </button>
  </nav>
</template>
