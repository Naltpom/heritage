<script setup lang="ts">
/**
 * Un record : une valeur en grand, ce qu'elle mesure, et ses titulaires — chacun
 * un lien vers sa fiche.
 *
 * Le titulaire est toujours une personne que le lecteur voit : le serveur passe
 * au suivant quand le premier lui est caché (voir `Statistiques\Service\Records`).
 * La carte n'a donc pas de cas « anonyme » à dessiner, et c'est voulu.
 */
import type { PersonneDesStats } from '@/lib/api'

defineProps<{
  titre: string
  valeur: string
  /** Une ligne sous la valeur : « 1702 – 1805 », « 11 enfants ». */
  detail?: string
  personnes: PersonneDesStats[]
  /** La liaison entre deux titulaires : « & » pour un couple, « → » parent puis enfant. */
  liaison?: string
  couleur?: string
}>()
</script>

<template>
  <article class="flex h-full flex-col rounded-panneau border border-ligne bg-panneau px-4 py-3">
    <h3 class="m-0 flex items-center gap-1.5 text-xs font-medium tracking-wide text-attenue uppercase">
      <span v-if="couleur" class="inline-block h-2.5 w-2.5 rounded-full" :style="{ background: couleur }" aria-hidden="true" />
      {{ titre }}
    </h3>
    <p class="m-0 mt-1 text-2xl font-semibold tracking-tight tabular-nums">{{ valeur }}</p>
    <p v-if="detail" class="m-0 text-xs text-attenue">{{ detail }}</p>
    <p class="m-0 mt-2 text-sm leading-snug">
      <template v-for="(personne, i) in personnes" :key="personne.id">
        <span v-if="i > 0" class="text-attenue">{{ ` ${liaison ?? '&'} ` }}</span>
        <RouterLink
          :to="{ name: 'personne.detail', params: { id: personne.id } }"
          class="font-medium text-accent underline-offset-2 hover:underline focus-visible:underline"
        >{{ personne.nom }}</RouterLink>
      </template>
    </p>
  </article>
</template>
