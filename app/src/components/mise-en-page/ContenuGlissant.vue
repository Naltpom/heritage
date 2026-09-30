<script setup lang="ts">
/**
 * Le contenu d'un onglet, qui **coulisse** vers la gauche ou la droite selon
 * que l'on va vers l'onglet suivant ou le précédent — comme le carrousel
 * d'Instagram. Partagé par Explorer et par la fiche (30/09/2026).
 *
 *     <ContenuGlissant :ordre="['a', 'b', 'c']" :actif="onglet">
 *       <ContenuDeLOnglet />
 *     </ContenuGlissant>
 *
 * Le sens se déduit de la **position** des deux onglets dans `ordre` : on n'a
 * pas à le dire. L'ancien et le nouveau contenu glissent **ensemble** (le
 * sortant est retiré du flux pendant l'animation), si bien que la hauteur de la
 * zone suit le contenu entrant. `apres` est émis quand le nouvel onglet est en
 * place : un écran à carte ou à canevas s'y remesure.
 *
 * Sous `prefers-reduced-motion`, rien ne bouge : l'onglet change, c'est tout.
 */
import { ref, watch } from 'vue'

const { ordre, actif } = defineProps<{
  ordre: readonly string[]
  actif: string
}>()

const emit = defineEmits<{ apres: [] }>()

const sens = ref<'suivant' | 'precedent'>('suivant')

watch(
  () => actif,
  (nouveau, ancien) => {
    sens.value = ordre.indexOf(nouveau) >= ordre.indexOf(ancien) ? 'suivant' : 'precedent'
  },
)
</script>

<template>
  <div class="relative overflow-x-clip">
    <Transition :name="`coulisse-${sens}`" @after-enter="emit('apres')">
      <div :key="actif" class="w-full">
        <slot />
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.coulisse-suivant-enter-active,
.coulisse-suivant-leave-active,
.coulisse-precedent-enter-active,
.coulisse-precedent-leave-active {
  transition: transform 260ms cubic-bezier(0.2, 0.7, 0.2, 1);
}

/* Le sortant quitte le flux : l'entrant prend sa place, et la hauteur suit. */
.coulisse-suivant-leave-active,
.coulisse-precedent-leave-active {
  position: absolute;
  inset: 0 0 auto 0;
}

.coulisse-suivant-enter-from,
.coulisse-precedent-leave-to {
  transform: translateX(100%);
}

.coulisse-suivant-leave-to,
.coulisse-precedent-enter-from {
  transform: translateX(-100%);
}

@media (prefers-reduced-motion: reduce) {
  .coulisse-suivant-enter-active,
  .coulisse-suivant-leave-active,
  .coulisse-precedent-enter-active,
  .coulisse-precedent-leave-active {
    transition: none;
  }
}
</style>
