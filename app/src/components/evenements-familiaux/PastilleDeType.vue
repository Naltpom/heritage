<script setup lang="ts">
/**
 * « Rassemblement » ou « Tirage au sort » — la pastille qui dit, dans la liste
 * commune des événements, de quelle sorte est chaque ligne : un pictogramme
 * (des personnes réunies, un paquet cadeau) et le mot. Le mot reste : un
 * pictogramme seul se devine, il ne se lit pas.
 *
 * `grand` : le pictogramme seul, en grand, pour les cartes du choix du type.
 */
import type { TypeDEvenement } from './liste-commune'
import { libelleDuType } from './liste-commune'

const { type, grand = false } = defineProps<{ type: TypeDEvenement; grand?: boolean }>()
</script>

<template>
  <svg
    v-if="grand"
    width="40"
    height="40"
    viewBox="0 0 20 20"
    fill="none"
    stroke="currentColor"
    stroke-width="1.3"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    :class="type === 'tirage' ? 'text-cadeau' : 'text-accent'"
  >
    <template v-if="type === 'tirage'">
      <rect x="3" y="8" width="14" height="9" rx="1" /><path d="M2 5h16v3H2zM10 5v12" />
      <path d="M10 5C8 1 5 2 6.5 4.5 7.2 5.2 10 5 10 5zM10 5c2-4 5-3 3.5-.5C12.8 5.2 10 5 10 5z" />
    </template>
    <template v-else>
      <circle cx="6" cy="6" r="2" /><circle cx="14" cy="6" r="2" /><circle cx="10" cy="9" r="2" />
      <path d="M2.5 15c0-2.2 1.6-4 3.5-4M17.5 15c0-2.2-1.6-4-3.5-4M6 17c0-2.5 1.8-4.5 4-4.5s4 2 4 4.5" />
    </template>
  </svg>
  <span
    v-else
    class="inline-flex items-center gap-1 rounded-pastille px-2 py-0.5 text-xs font-semibold"
    :class="type === 'tirage' ? 'bg-cadeau-doux text-cadeau' : 'bg-accent-doux text-accent'"
  >
    <svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <template v-if="type === 'tirage'">
        <rect x="3" y="8" width="14" height="9" rx="1" /><path d="M2 5h16v3H2zM10 5v12" />
      </template>
      <template v-else>
        <circle cx="6" cy="7" r="2.2" /><circle cx="14" cy="7" r="2.2" />
        <path d="M2 16c0-2.5 1.8-4.5 4-4.5s4 2 4 4.5M10 16c0-2.5 1.8-4.5 4-4.5s4 2 4 4.5" />
      </template>
    </svg>
    {{ libelleDuType(type) }}
  </span>
</template>
