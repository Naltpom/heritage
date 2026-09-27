<script setup lang="ts">
/**
 * « Vous offrez un cadeau à… » — le nom masqué jusqu'au geste « Découvrir ».
 *
 * Masqué veut dire **absent du DOM**, pas caché par une classe : un nom rendu
 * puis estompé se lit dans l'inspecteur, se sélectionne au doigt et se lit à
 * voix haute par un lecteur d'écran. Le geste n'est pas mémorisé : recharger
 * remasque. Suite : `carte-attribution-rendu.test.ts`.
 */
import { ref } from 'vue'
import type { MonAttribution } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import { consigneDeRemise, phraseDAttribution } from './tirages'

const { attribution, monPersonId } = defineProps<{
  attribution: MonAttribution
  monPersonId: number | null
}>()

const devoile = ref(false)
</script>

<template>
  <div class="rounded-panneau border border-ligne bg-accent-doux p-4">
    <p class="text-sm text-texte">{{ phraseDAttribution(attribution, monPersonId) }}</p>
    <p v-if="devoile" class="mt-1 text-lg font-semibold text-texte" aria-live="polite">{{ attribution.receveur.libelle }}</p>
    <BoutonBase v-else class="mt-2" variante="secondaire" @click="devoile = true">Découvrir</BoutonBase>
    <p class="mt-2 text-xs text-attenue">{{ consigneDeRemise(attribution.presenceDuDonneur) }}</p>
  </div>
</template>
