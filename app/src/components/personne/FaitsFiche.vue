<script setup lang="ts">
/**
 * Les faits de la personne — profession, résidence… —, dans l'onglet Identité.
 *
 * Ils vivaient dans `SourcesFiche.vue`, avec les citations. La refonte les range
 * sous l'identité (maquette 4c) : un fait dit ce qu'a été la personne, une
 * citation dit ce qui le prouve.
 *
 * Même grille que le tableau d'identité (`140px 1fr`). Les dates passent par
 * `rendreDate()` — `texte` puis `raw`, jamais une recomposition depuis `min` et
 * `max` (§ 14) : « entre 1901 et 1909 » s'écrit tel quel.
 *
 * Le composant n'est monté que si la fiche porte au moins un fait : un panneau
 * « Faits » vide n'apprendrait rien.
 */
import type { FichePersonneComplete } from '@/lib/api'
import { rendreDate } from './champs'

defineProps<{ fiche: FichePersonneComplete }>()

/** `type` est une valeur du modèle (`residence`, `profession`…) que la fiche ne
 *  traduit pas : on la rend lisible sans l'inventer — soulignés en espaces,
 *  majuscule initiale. */
function etiquette(type: string): string {
  const texte = type.replace(/_/g, ' ')
  return texte.charAt(0).toUpperCase() + texte.slice(1)
}
</script>

<template>
  <dl
    class="m-0 grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-4 gap-y-2 text-sm md:grid-cols-[140px_minmax(0,1fr)]"
  >
    <template v-for="fait in fiche.faits" :key="fait.id">
      <dt class="text-attenue">{{ etiquette(fait.type) }}</dt>
      <!-- `label` est la valeur du fait (« Tisserand ») ; la date et le lieu
           viennent en second plan. -->
      <dd class="m-0 min-w-0">
        <span v-if="fait.label">{{ fait.label }}</span>
        <span v-if="fait.happened !== null" class="text-[13px] text-attenue">
          <template v-if="fait.label"> — </template>{{
            rendreDate(fait.happened, 'date inconnue').principal
          }}<template v-if="fait.happened.place">, {{ fait.happened.place }}</template>
        </span>
        <span v-if="fait.address" class="block text-xs text-attenue">{{ fait.address }}</span>
        <span
          v-if="!fait.label && fait.happened === null && !fait.address"
          class="text-attenue italic"
        >
          sans précision
        </span>
      </dd>
    </template>
  </dl>
</template>
