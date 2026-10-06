<script setup lang="ts">
/**
 * La bascule « Mon arbre / Toute la base » d'Explorer — administration seulement
 * (`usePorteeExplorer`). Rien n'est rendu pour un membre.
 */
import { PORTEES, usePorteeExplorer } from '@/composables/usePorteeExplorer'

const { portee, bascule, changerPortee } = usePorteeExplorer()
</script>

<template>
  <div v-if="bascule" class="flex items-center gap-1.5" role="group" aria-label="Portée d’Explorer">
    <button
      v-for="p in PORTEES"
      :key="p.valeur"
      type="button"
      class="cursor-pointer whitespace-nowrap rounded-pastille border px-2.5 py-1 text-xs"
      :class="p.valeur === portee ? 'border-accent bg-accent-doux font-semibold text-texte' : 'border-ligne bg-fond text-attenue hover:border-accent'"
      :aria-pressed="p.valeur === portee"
      :title="p.titre"
      @click="changerPortee(p.valeur)"
    >
      {{ p.libelle }}
    </button>
  </div>
</template>
