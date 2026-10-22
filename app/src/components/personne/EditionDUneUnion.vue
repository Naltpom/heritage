<script setup lang="ts">
/**
 * **Les cases d'une union en mode édition** — sa nature, son mariage, sa rupture
 * (ADR 0020). Partagé par l'union existante et le conjoint qu'on ajoute : les deux
 * disent la même chose, et deux copies divergeraient au premier libellé.
 *
 * - « Mariés : oui / non (union libre) / on ne sait pas » ({@link ChoixNatureUnion}).
 *   Une date ou un lieu de mariage saisis imposent « oui ».
 * - La date et le lieu du mariage — **absents d'une union libre** : il n'y a pas de
 *   mariage à dater. Pour en saisir un, on répond d'abord « oui ».
 * - La rupture, **même sans date** : « Divorcés » après un mariage, « Séparés »
 *   sinon. Une date de rupture saisie coche la case et la verrouille.
 *
 * L'état et ses règles vivent dans `edition.ts` (`natureSaisie`, `ruptureSaisie`…),
 * éprouvés sans monter de composant : celui-ci ne fait que les dessiner.
 */
import { computed } from 'vue'
import ChampDateIncertaine from '@/components/proposition/ChampDateIncertaine.vue'
import ChoixNatureUnion from '@/components/proposition/ChoixNatureUnion.vue'
import { dateDeRuptureSaisie, mariageSaisi, natureSaisie, ruptureSaisie, type DatesDUnion } from './edition'
import { motDeLaRupture } from './union'

const dates = defineModel<DatesDUnion>({ required: true })

function modifier<C extends keyof DatesDUnion>(champ: C, valeur: DatesDUnion[C]): void {
  dates.value = { ...dates.value, [champ]: valeur }
}

const nature = computed(() => natureSaisie(dates.value))
const mot = computed(() => motDeLaRupture({ married: nature.value }))
</script>

<template>
  <div class="flex flex-col gap-2" data-edition-union>
    <ChoixNatureUnion
      :model-value="dates.marie"
      :impose="mariageSaisi(dates)"
      @update:model-value="modifier('marie', $event)"
    />

    <ChampDateIncertaine
      v-if="nature !== false"
      :model-value="dates.mariage"
      etiquette="Date de mariage"
      etiquette-lieu="Lieu de mariage"
      vide-permis
      @update:model-value="modifier('mariage', $event)"
    />

    <label class="flex min-h-9 items-start gap-2">
      <input
        type="checkbox"
        class="mt-0.5 size-4 accent-[var(--color-accent)]"
        :checked="ruptureSaisie(dates)"
        :disabled="dateDeRuptureSaisie(dates)"
        data-rupture
        @change="modifier('rompue', ($event.target as HTMLInputElement).checked)"
      />
      <span class="text-sm">
        {{ mot }}
        <span class="block text-xs text-attenue">
          {{ nature === true ? 'Divorce' : 'Séparation' }}, même sans date. Une date saisie coche la case.
        </span>
      </span>
    </label>

    <ChampDateIncertaine
      v-if="ruptureSaisie(dates)"
      :model-value="dates.divorce"
      :etiquette="nature === true ? 'Date du divorce' : 'Date de la séparation'"
      aide="Facultative."
      sans-lieu
      vide-permis
      @update:model-value="modifier('divorce', $event)"
    />
  </div>
</template>
