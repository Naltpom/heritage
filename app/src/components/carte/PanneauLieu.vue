<script setup lang="ts">
/**
 * Ce qui s'est passé à un lieu — la carte flottante d'une bulle.
 *
 * **Elle ne charge rien.** Tout ce qu'elle montre est déjà dans la bulle, que
 * `agregation.ts` a composée depuis la charge unique de l'écran. C'est la règle
 * de la carte de la chronologie, et elle vaut ici pour la même raison : un
 * panneau qui déclenche un appel fait attendre pour montrer ce qu'on avait déjà.
 *
 * **La graphie brute est affichée, et ce n'est pas du bruit.** Deux orthographes
 * réunies sous une même bulle sont soit la même commune écrite de deux façons —
 * ce qu'on veut —, soit un mauvais rapprochement du référentiel. Les montrer est
 * le seul moyen de faire la différence, et le rapprochement se corrige en base,
 * pas ici.
 *
 * **La date vient du serveur, telle quelle.** Le front ne recompose jamais une
 * date (§ 14) : `texte` porte déjà « entre 1901 et 1909 » ou « vers 1889 », avec
 * l'incertitude que l'acte portait.
 */
import { computed } from 'vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import type { Bulle } from './agregation'

/** Au-delà, la carte devient une liste à faire défiler, et l'on ne voit plus
 *  la carte derrière. Le lieu est un point d'entrée, pas un inventaire. */
const PLAFOND = 12

const { bulle } = defineProps<{ bulle: Bulle }>()

const emit = defineEmits<{
  fermer: []
  aller: [personId: number]
}>()

const montres = computed(() => bulle.evenements.slice(0, PLAFOND))
const restants = computed(() => Math.max(0, bulle.evenements.length - PLAFOND))
const graphies = computed(() => bulle.brut.filter((g) => g !== bulle.libelle))
</script>

<template>
  <PanneauBase ombre>
    <template #entete>
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 class="truncate text-sm font-semibold tracking-tight">{{ bulle.libelle }}</h2>
          <p class="tnum text-xs text-attenue">
            {{ bulle.total }} événement{{ bulle.total > 1 ? 's' : '' }} ·
            {{ bulle.anneeMin }}–{{ bulle.anneeMax }}
          </p>
        </div>
        <button
          type="button"
          class="cursor-pointer border-0 bg-transparent p-0 text-lg leading-none text-attenue hover:text-texte"
          aria-label="Fermer"
          @click="emit('fermer')"
        >
          ×
        </button>
      </div>
    </template>

    <ul class="space-y-1.5">
      <li v-for="evenement in montres" :key="evenement.cle" class="text-xs">
        <span class="text-attenue">{{ evenement.texte }}</span>
        <template v-if="evenement.libelle !== null">
          <span aria-hidden="true"> · </span>
          <span class="italic">{{ evenement.libelle }}</span>
        </template>
        <span aria-hidden="true"> · </span>
        <template v-for="(sujet, rang) in evenement.sujets" :key="sujet.personId">
          <span v-if="rang > 0" aria-hidden="true"> &amp; </span>
          <button
            type="button"
            class="cursor-pointer border-0 bg-transparent p-0 text-left underline decoration-dotted underline-offset-2 hover:text-accent"
            @click="emit('aller', sujet.personId)"
          >
            {{ sujet.displayName }}
          </button>
        </template>
      </li>
    </ul>

    <p v-if="restants > 0" class="mt-2 text-xs text-attenue">et {{ restants }} autres.</p>

    <template #pied>
      <div class="space-y-1 text-xs text-attenue">
        <p v-if="graphies.length > 0">
          Écrit aussi : <span class="italic">{{ graphies.join(' · ') }}</span>
        </p>
        <p v-if="bulle.confiance < 100">
          Position approximative (fiabilité {{ bulle.confiance }} sur 100).
        </p>
      </div>
    </template>
  </PanneauBase>
</template>
