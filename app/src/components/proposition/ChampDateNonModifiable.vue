<script setup lang="ts">
/**
 * Une date **montrée, pas saisissable** — à la place de `ChampDateIncertaine`
 * quand le lecteur ne la voit pas entière (ADR 0016, réciprocité des vivants).
 *
 * Ce n'est pas le verrou de `ChampDateIncertaine` (une forme que l'éditeur ne
 * sait pas relire) : là, la date est entière et « Remplacer cette date » a un
 * sens. Ici, le lecteur n'en voit qu'une partie — ou rien —, et en proposer
 * une autre reviendrait à écraser à l'aveugle une valeur qu'il ignore. Aucun
 * bouton, donc : la valeur de la saisie reste celle d'origine et ne produit
 * aucun op.
 */
import type { BlocDate } from '@/lib/api'
import { rendreDate } from '@/components/personne/champs'

const { etiquette, bloc, motif } = defineProps<{
  etiquette: string
  bloc: BlocDate | null
  motif: string
}>()
</script>

<template>
  <fieldset class="flex flex-col gap-1.5" data-date-non-modifiable>
    <legend class="mb-1 text-sm font-medium">{{ etiquette }}</legend>
    <p class="m-0 rounded-champ border border-ligne bg-panneau px-2.5 py-1.5 text-sm">
      {{ rendreDate(bloc, 'inconnue').principal }}
      <span v-if="bloc?.place" class="text-attenue"> — {{ bloc.place }}</span>
    </p>
    <p class="m-0 text-xs text-attenue">{{ motif }}</p>
  </fieldset>
</template>
