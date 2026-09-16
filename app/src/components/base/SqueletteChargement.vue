<script setup lang="ts">
/**
 * Squelette de chargement.
 *
 * Il occupe la place du contenu à venir plutôt que de la laisser vide : ce qui
 * arrive ensuite ne déplace pas ce qui est déjà lu.
 *
 * `aria-hidden` et `aria-busy` sur le conteneur : un lecteur d'écran n'a rien à
 * annoncer de barres grises. Le composant qui l'emploie porte l'information
 * (« Chargement de la fiche… ») dans une région `aria-live`.
 *
 * L'animation est coupée pour qui a demandé moins de mouvement — `motion-safe`
 * plutôt que de la poser inconditionnellement.
 */
const { lignes = 3, hauteur = 'h-4' } = defineProps<{
  lignes?: number
  /** Classe de hauteur Tailwind : `h-4` pour du texte, `h-40` pour un bloc. */
  hauteur?: string
}>()
</script>

<template>
  <div class="flex flex-col gap-2" aria-hidden="true" aria-busy="true">
    <div
      v-for="index in lignes"
      :key="index"
      class="rounded-champ bg-ligne motion-safe:animate-pulse"
      :class="hauteur"
      :style="{ width: index === lignes && lignes > 1 ? '60%' : '100%' }"
    />
  </div>
</template>
