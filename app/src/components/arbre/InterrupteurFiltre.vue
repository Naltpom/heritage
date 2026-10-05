<script setup lang="ts">
/**
 * Une ligne des filtres de l'arbre : un libellé, une précision dessous, et un
 * interrupteur au bout de la ligne (refonte des filtres du 05/10/2026).
 *
 * Toute la ligne est le bouton — 44 px de haut au moins, la cible d'un pouce —,
 * et l'interrupteur est à droite, là où le pouce le cherche. Les anciennes cases
 * à cocher alignaient le libellé à gauche et la précision tout au bout de la
 * ligne : sur un téléphone, les deux se chevauchaient ou partaient à la ligne.
 *
 * `role="switch"` : c'est une bascule qui prend effet tout de suite, pas une
 * case qu'on valide ensuite.
 */
const { libelle, sous = '', actif } = defineProps<{
  libelle: string
  /** Ce que la bascule fait, en une ligne sous le libellé. */
  sous?: string
  actif: boolean
}>()
const emit = defineEmits<{ basculer: [] }>()
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="actif"
    class="flex min-h-11 w-full items-center gap-3 rounded-champ py-1.5 text-left"
    @click="emit('basculer')"
  >
    <span class="flex min-w-0 flex-1 flex-col leading-tight">
      <span class="text-sm" :class="actif ? 'font-semibold' : ''">{{ libelle }}</span>
      <span v-if="sous" class="mt-0.5 text-xs text-attenue">{{ sous }}</span>
    </span>
    <span
      class="relative h-6 w-10 flex-none rounded-full transition-colors"
      :class="actif ? 'bg-accent' : 'bg-ligne'"
      aria-hidden="true"
    >
      <span
        class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-panneau shadow transition-transform"
        :class="actif ? 'translate-x-4' : ''"
      />
    </span>
  </button>
</template>
