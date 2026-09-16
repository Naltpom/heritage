<script setup lang="ts">
/**
 * Emplacement d'un écran encore à poser.
 *
 * Chaque vue du socle rend ce composant. Il dit, à l'écran et dans le code, ce
 * qui doit venir là et quelles routes d'API le nourrissent — pour que l'agent
 * qui ouvre le fichier n'ait pas à relire les 3 457 lignes du contrat pour
 * retrouver la sienne.
 *
 * Ce composant disparaît au fur et à mesure : une vue terminée ne l'importe
 * plus. S'il reste un seul appel à `EcranAPoser` à la livraison, c'est qu'un
 * écran manque.
 */
import PanneauBase from '@/components/base/PanneauBase.vue'

const { titre, role, routes = [] } = defineProps<{
  titre: string
  /** Ce que l'écran doit faire, en une phrase. */
  role: string
  /** Les routes d'API concernées, telles qu'écrites dans le contrat. */
  routes?: string[]
}>()
</script>

<template>
  <div class="mx-auto w-full max-w-3xl p-6">
    <PanneauBase :titre="titre">
      <p class="text-sm">{{ role }}</p>

      <p class="mt-3 text-xs text-attenue">
        Écran non encore posé. Il revient à l'agent de ce module ; le socle fournit le routeur,
        la charte, le client d'API et les composants de base.
      </p>

      <ul v-if="routes.length > 0" class="mt-3 space-y-1">
        <li v-for="route in routes" :key="route" class="font-mono text-xs text-attenue">
          {{ route }}
        </li>
      </ul>

      <div v-if="$slots.default" class="mt-4">
        <slot />
      </div>
    </PanneauBase>
  </div>
</template>
