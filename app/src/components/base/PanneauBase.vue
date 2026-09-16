<script setup lang="ts">
/**
 * Panneau — le conteneur de contenu de la charte : fond `--panneau`, filet
 * `--ligne`, rayon 10 px. C'est le `aside` et le `#leg` de `arbre.html`.
 *
 * `titre` rend un `<h2>` : les panneaux sont des sections de page, et une page
 * dont les intitulés sont des `<div>` n'a pas de structure pour un lecteur
 * d'écran. Une page qui a besoin d'un autre niveau passe le titre en contenu.
 */
const { titre = '', ombre = false } = defineProps<{
  titre?: string
  /** Ombre portée : pour un panneau flottant (légende, résultats), pas pour un
   *  panneau posé dans le flux. */
  ombre?: boolean
}>()
</script>

<template>
  <section
    class="rounded-panneau border border-ligne bg-panneau"
    :class="ombre ? 'shadow-panneau' : ''"
  >
    <header v-if="titre.length > 0 || $slots.entete" class="border-b border-ligne px-4 py-2.5">
      <slot name="entete">
        <h2 class="text-sm font-semibold tracking-tight">{{ titre }}</h2>
      </slot>
    </header>

    <div class="px-4 py-3.5">
      <slot />
    </div>

    <footer v-if="$slots.pied" class="border-t border-ligne px-4 py-2.5">
      <slot name="pied" />
    </footer>
  </section>
</template>
