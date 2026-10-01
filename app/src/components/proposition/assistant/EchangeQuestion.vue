<script setup lang="ts">
/**
 * Un échange de « Poser une question » : la question du membre, puis la réponse
 * de l'assistant, où chaque fiche citée est un lien vers `/personnes/{id}`.
 *
 * La réponse est découpée en paragraphes et en morceaux (`questions.ts`) et
 * rendue **comme du texte** : pas de `v-html`. Une balise écrite par le modèle
 * s'affiche telle quelle, elle ne s'exécute pas.
 *
 * « Fiches citées » reprend les personnes **telles que le lecteur les voit** : une
 * personne restreinte n'a que son nom masqué, jamais de dates.
 */
import type { EchangeAssistant } from '@/lib/api'
import { libelleDeFiche, paragraphes } from './questions'

const { echange } = defineProps<{ echange: EchangeAssistant }>()
</script>

<template>
  <div class="flex flex-col gap-2">
    <p
      class="m-0 ml-auto max-w-[88%] whitespace-pre-wrap rounded-panneau bg-accent-doux px-3.5 py-2.5 text-[15px]"
    >
      <span class="sr-only">Vous : </span>{{ echange.question }}
    </p>

    <div class="max-w-full rounded-panneau border border-ligne bg-panneau px-3.5 py-3 md:max-w-[92%]">
      <span class="sr-only">L’assistant : </span>
      <div class="flex flex-col gap-2 text-[15px] leading-relaxed">
        <p
          v-for="(paragraphe, rang) in paragraphes(echange.reponse)"
          :key="rang"
          class="m-0"
          :class="paragraphe.puce ? 'relative pl-4' : ''"
        >
          <span v-if="paragraphe.puce" class="absolute left-0 text-attenue" aria-hidden="true">•</span>
          <template v-for="(morceau, index) in paragraphe.morceaux" :key="index">
            <RouterLink
              v-if="morceau.type === 'fiche'"
              :to="{ name: 'personne.detail', params: { id: morceau.personId } }"
              class="font-medium text-accent underline"
            >{{ morceau.libelle }}</RouterLink>
            <template v-else>{{ morceau.texte }}</template>
          </template>
        </p>
      </div>

      <div v-if="echange.personnes.length > 0" class="mt-3 border-t border-ligne pt-2">
        <p class="m-0 mb-1.5 text-xs text-attenue">Fiches citées</p>
        <ul class="m-0 flex list-none flex-wrap gap-1.5 p-0">
          <li v-for="personne in echange.personnes" :key="personne.personId">
            <RouterLink
              :to="{ name: 'personne.detail', params: { id: personne.personId } }"
              class="inline-flex min-h-9 items-center rounded-champ border border-ligne bg-fond px-2.5 text-xs hover:border-accent max-md:min-h-11"
            >
              {{ libelleDeFiche(personne) }}
            </RouterLink>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
