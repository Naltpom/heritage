<script setup lang="ts">
/**
 * Un palmarès en barres horizontales : un libellé, une barre, un nombre. Les
 * communes, les départements, les noms de famille, les métiers.
 *
 * Une liste (`<ol>`) et non un dessin : l'ordre **est** l'information, et un
 * lecteur d'écran lit une liste ordonnée sans aide. La barre est décorative
 * (`aria-hidden`) — le nombre est écrit à côté.
 */
import { computed } from 'vue'
import { nombre } from './format'

const props = withDefaults(
  defineProps<{
    lignes: { libelle: string; n: number; couleur?: string }[]
    /** Un jeton CSS : la couleur de toutes les barres, sauf celle qu'une ligne porte. */
    couleur?: string
  }>(),
  { couleur: 'var(--h-accent)' },
)

const maximum = computed(() => Math.max(1, ...props.lignes.map((l) => l.n)))
</script>

<template>
  <ol class="m-0 flex list-none flex-col gap-1.5 p-0">
    <li v-for="ligne in lignes" :key="ligne.libelle" class="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-2 text-sm">
      <span class="truncate" :title="ligne.libelle">{{ ligne.libelle }}</span>
      <span class="block h-2.5 rounded-r-[4px] bg-ligne/40" aria-hidden="true">
        <span
          class="block h-full rounded-r-[4px]"
          :style="{ width: `${(100 * ligne.n) / maximum}%`, background: ligne.couleur ?? couleur }"
        />
      </span>
      <span class="font-mono text-xs tabular-nums text-attenue">{{ nombre(ligne.n) }}</span>
    </li>
  </ol>
</template>
