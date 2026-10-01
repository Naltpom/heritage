<script setup lang="ts">
/**
 * La fenêtre de choix du format des fiches, **ouverte d'elle-même** sur
 * téléphone à la première arrivée sur l'arbre.
 *
 * Montée par `HistoireView`, à la fin de l'écran : quitter l'arbre la démonte,
 * et sa portée annule l'attente en cours et rend sa place aux autres fenêtres
 * automatiques. `pret` dit que l'arbre est dessiné.
 *
 * Toute la décision (qui, quand, une fois) est dans `piloterInvitationFormat()`
 * — voir `composables/formatDesCartes.ts`. Ici : le branchement, comme
 * `InvitationNotifications`.
 */
import { piloterInvitationFormat, useFormatDesCartes } from '@/composables/formatDesCartes'
import { useEstMobile } from '@/composables/useTiroirs'
import FenetreFormatDesCartes from './FenetreFormatDesCartes.vue'

const { pret } = defineProps<{ pret: boolean }>()

const { format } = useFormatDesCartes()
const estMobile = useEstMobile()
const { ouverte, choisir, garder } = piloterInvitationFormat(
  () => pret,
  () => estMobile.value,
)
</script>

<template>
  <FenetreFormatDesCartes :ouverte="ouverte" :actuel="format" @choisir="choisir" @garder="garder" />
</template>
