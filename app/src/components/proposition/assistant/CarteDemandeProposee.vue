<script setup lang="ts">
/**
 * Une demande proposée par l'assistant, **à relire avant de déposer**.
 *
 * Cochée par défaut : l'assistant a fait le travail, le membre retire ce qui ne
 * lui convient pas. Mais rien ne part d'ici — la case ne fait que dire si la
 * demande fera partie du dépôt, que seul le bouton du bas déclenche.
 *
 * **Les personnes restreintes n'ont que leur nom.** Le modèle a lu tout l'arbre ;
 * ce qui revient au lecteur passe par son cercle. `datesAffichables()` tient la
 * règle (et un test la tient) : le gabarit n'écrit une date que si elle rend
 * quelque chose.
 *
 * **Une valeur absente avant la flèche n'est pas un champ vide** sur une
 * modification : c'est une valeur que le lecteur n'a pas le droit de lire. Voir
 * `libelleAvant()`.
 */
import { computed, useId } from 'vue'
import type { DemandeProposee } from '@/lib/api'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import { LIBELLES_CONFIANCE, VALEUR_MASQUEE, datesAffichables, libelleAvant } from './assistant'

const { demande, cochee, desactive = false } = defineProps<{
  demande: DemandeProposee
  cochee: boolean
  desactive?: boolean
}>()

const emit = defineEmits<{ basculer: [cochee: boolean] }>()

const identifiant = useId()

const TONS = { haute: 'validee', moyenne: 'en_attente', faible: 'neutre' } as const
const ton = computed(() => TONS[demande.confiance])

const NATURES = {
  creation_personne: 'Nouvelle personne',
  modification_personne: 'Modification de fiche',
  ajout_lien: 'Nouveau lien',
} as const
</script>

<template>
  <article
    class="rounded-panneau border bg-panneau transition-colors"
    :class="cochee ? 'border-accent' : 'border-ligne opacity-80'"
    :aria-labelledby="`${identifiant}-titre`"
  >
    <header class="flex items-start gap-3 px-4 pt-3.5">
      <label
        class="-m-2 flex size-11 flex-none cursor-pointer items-center justify-center"
        :class="desactive ? 'cursor-not-allowed' : ''"
      >
        <input
          type="checkbox"
          class="size-[18px] cursor-pointer accent-accent"
          :checked="cochee"
          :disabled="desactive"
          :aria-describedby="`${identifiant}-titre`"
          :aria-label="`Inclure : ${demande.titre}`"
          @change="emit('basculer', ($event.target as HTMLInputElement).checked)"
        />
      </label>

      <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-1.5">
          <span class="text-[11.5px] tracking-wide text-attenue uppercase">
            {{ NATURES[demande.corps.kind] }}
          </span>
          <PastilleEtat :etat="ton" :libelle="LIBELLES_CONFIANCE[demande.confiance]" />
        </div>
        <h3 :id="`${identifiant}-titre`" class="mt-1 text-[15px] leading-snug font-semibold">
          {{ demande.titre }}
        </h3>
      </div>
    </header>

    <div class="flex flex-col gap-3 px-4 pt-2 pb-4 md:pl-[52px]">
      <p class="m-0 text-sm text-attenue">{{ demande.justification }}</p>

      <div v-if="demande.personnes.length > 0">
        <p class="m-0 mb-1 text-xs font-semibold text-attenue">Personnes concernées</p>
        <ul class="m-0 flex list-none flex-wrap gap-1.5 p-0">
          <li v-for="personne in demande.personnes" :key="personne.personId">
            <RouterLink
              :to="{ name: 'personne.detail', params: { id: personne.personId } }"
              class="inline-flex min-h-9 items-center gap-1.5 rounded-pastille border border-ligne bg-fond px-3 text-[13px] hover:border-accent max-md:min-h-11"
            >
              <span class="font-medium">{{ personne.displayName }}</span>
              <span
                v-if="datesAffichables(personne) !== null"
                class="text-xs text-attenue tabular-nums"
              >
                {{ datesAffichables(personne) }}
              </span>
            </RouterLink>
          </li>
        </ul>
      </div>

      <div v-if="demande.changements.length > 0">
        <p class="m-0 mb-1 text-xs font-semibold text-attenue">Ce qui changerait</p>
        <ul class="m-0 flex list-none flex-col divide-y divide-ligne rounded-champ border border-ligne p-0">
          <li
            v-for="(changement, index) in demande.changements"
            :key="index"
            class="grid gap-x-3 gap-y-0.5 px-3 py-2 text-sm md:grid-cols-[minmax(0,11rem)_minmax(0,1fr)]"
          >
            <span class="text-attenue">{{ changement.libelle }}</span>
            <span class="flex flex-wrap items-baseline gap-x-1.5 break-words">
              <template v-if="libelleAvant(changement, demande) !== null">
                <span
                  class="text-attenue"
                  :class="libelleAvant(changement, demande) === VALEUR_MASQUEE ? 'italic' : 'line-through decoration-1'"
                >
                  {{ libelleAvant(changement, demande) }}
                </span>
                <span class="text-attenue" aria-label="devient">→</span>
              </template>
              <span class="font-medium">{{ changement.apres ?? '—' }}</span>
            </span>
          </li>
        </ul>
      </div>
    </div>
  </article>
</template>
