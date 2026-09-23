<script setup lang="ts">
/**
 * Un panneau du corps de la fiche (maquette 4c) : `bg-panneau border-ligne
 * rounded-panneau`, `padding: 16px 20px`, et un titre de 11 px en majuscules,
 * atténué.
 *
 * Ce n'est pas `PanneauBase`, dont l'en-tête est une barre à filet : la fiche
 * enchaîne plusieurs panneaux dans une colonne, et des titres discrets s'y
 * lisent mieux que des barres. Le titre reste un `<h2>`, pour la structure que
 * lit un lecteur d'écran.
 */
const { titre } = defineProps<{ titre: string }>()
</script>

<template>
  <section
    class="flex flex-col gap-3 rounded-panneau border border-ligne bg-panneau px-5 py-4 max-md:px-4 max-md:py-3.5"
  >
    <h2 class="m-0 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">{{ titre }}</h2>
    <slot />
  </section>
</template>
