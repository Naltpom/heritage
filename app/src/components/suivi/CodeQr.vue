<script setup lang="ts">
/**
 * Un code QR dessiné en SVG, calculé dans le navigateur (`lib/qr.ts`).
 *
 * **Toujours sombre sur blanc**, thème sombre compris : un code inversé n'est
 * pas lu par tous les appareils photo. La marge blanche de quatre modules est
 * celle que la norme exige autour du code.
 */
import { computed } from 'vue'
import { cheminSvg, encoderQr } from '@/lib/qr'

const { texte, libelle } = defineProps<{
  texte: string
  /** Ce que le code ouvre, pour le lecteur d'écran. */
  libelle: string
}>()

const MARGE = 4
const code = computed(() => encoderQr(texte))
const cote = computed(() => code.value.taille + 2 * MARGE)
</script>

<template>
  <svg
    role="img"
    :aria-label="libelle"
    :viewBox="`0 0 ${cote} ${cote}`"
    shape-rendering="crispEdges"
    class="block h-auto w-full rounded-panneau"
    data-code-qr
  >
    <rect :width="cote" :height="cote" fill="#ffffff" />
    <path :d="cheminSvg(code, MARGE)" fill="#000000" />
  </svg>
</template>
