<script setup lang="ts">
/**
 * « Affichage des fiches » : Compact ou Portrait, dans le contenu des filtres
 * de l'arbre — donc dans le menu déroulant sur bureau **et** dans le feuillet
 * sur téléphone, `BarreEcran` ne rendant ce contenu qu'une fois.
 *
 * Ce n'est **pas un filtre** : rien n'est caché, seule la forme des fiches
 * change. Il ne compte donc pas dans « Filtres · n », et « Réinitialiser » n'y
 * touche pas — c'est une préférence de l'appareil, enregistrée
 * (`composables/formatDesCartes.ts`). Un choix fait ici vaut réponse : la
 * fenêtre qui le propose sur téléphone ne s'ouvrira plus.
 */
import Segmente from '@/components/mise-en-page/Segmente.vue'
import { useFormatDesCartes } from '@/composables/formatDesCartes'
import type { FormatDeCarte } from '@/histoire/cartes'

const { format, choisirFormat } = useFormatDesCartes()

const OPTIONS: readonly { valeur: FormatDeCarte; libelle: string }[] = [
  { valeur: 'compact', libelle: 'Compact' },
  { valeur: 'portrait', libelle: 'Portrait' },
]
</script>

<template>
  <!-- Une ligne de la section « Affichage » des filtres : un libellé, pas un titre de section. -->
  <div class="flex flex-col gap-1.5">
    <span class="text-sm">Affichage des fiches</span>
    <Segmente :options="OPTIONS" :valeur="format" etiquette="Format des fiches" :colonnes="2" @choisir="choisirFormat" />
  </div>
</template>
