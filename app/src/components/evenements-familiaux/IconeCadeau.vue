<script setup lang="ts">
/**
 * Le paquet cadeau des tirages de Noël — le même dessin que la grande pastille
 * de `PastilleDeType`, à la taille voulue. Décoratif : le texte voisin dit déjà
 * « Noël ».
 *
 * Taille et trait sont des props liées, pas des constantes : un SVG entièrement
 * statique est « stringifié » par le compilateur, et le rendu en mémoire des
 * tests `*.client.test.ts` ne sait pas insérer ce contenu-là.
 */
const { taille = 22, trait = 1.5 } = defineProps<{ taille?: number; trait?: number }>()
</script>

<template>
  <svg :width="taille" :height="taille" viewBox="0 0 20 20" fill="none" stroke="currentColor" :stroke-width="trait" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <rect x="3" y="8" width="14" height="9" rx="1" /><path d="M2 5h16v3H2zM10 5v12" />
    <path d="M10 5C8 1 5 2 6.5 4.5 7.2 5.2 10 5 10 5zM10 5c2-4 5-3 3.5-.5C12.8 5.2 10 5 10 5z" />
  </svg>
</template>
