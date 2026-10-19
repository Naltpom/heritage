<script setup lang="ts">
/**
 * **Que crée-t-on ?** — la première étape de « Créer un événement » : deux
 * grandes cartes, « Rassemblement » et « Tirage de Noël ».
 *
 * Demande du commanditaire (02/10/2026) : « lors de la création d'un événement
 * on choisit le type ». La carte « Rassemblement » est un **lien** vers la
 * même page avec `?type=rassemblement` : le retour du navigateur ramène au
 * choix, et l'adresse du formulaire se partage telle quelle.
 *
 * Le tirage au sort d'heritage a été retiré : un tirage de Noël se crée sur
 * **le site de Noël** (noel2026, `/creer`). Sa carte y mène — un lien sortant,
 * qui le dit (« sur le site de Noël »), pour que personne ne s'étonne de
 * changer de site.
 *
 * Deux cartes pleine largeur sur téléphone, côte à côte au-delà : c'est un
 * choix qu'on fait du pouce, sans viser. Suite : `evenements-rendu.test.ts`.
 */
import { TYPES_D_EVENEMENT } from './liste-commune'
import { CREER_SUR_LE_SITE_DE_NOEL } from './noel'
import PastilleDeType from './PastilleDeType.vue'
</script>

<template>
  <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2" aria-label="Type d’événement">
    <li v-for="t in TYPES_D_EVENEMENT" :key="t.valeur">
      <RouterLink
        v-if="t.valeur === 'rassemblement'"
        :to="{ name: 'evenement.nouveau', query: { type: t.valeur } }"
        class="flex h-full min-h-36 flex-col gap-2 rounded-panneau border border-ligne bg-panneau p-5 transition-colors hover:border-accent focus-visible:border-accent"
      >
        <PastilleDeType :type="t.valeur" grand />
        <span class="text-lg font-semibold text-texte">{{ t.libelle }}</span>
        <span class="text-sm text-attenue">{{ t.description }}</span>
      </RouterLink>
      <a
        v-else
        :href="CREER_SUR_LE_SITE_DE_NOEL"
        data-creer-noel
        class="flex h-full min-h-36 flex-col gap-2 rounded-panneau border border-cadeau/40 bg-cadeau-doux p-5 transition-colors hover:border-cadeau focus-visible:border-cadeau"
      >
        <PastilleDeType :type="t.valeur" grand />
        <span class="text-lg font-semibold text-texte">{{ t.libelle }}</span>
        <span class="text-sm text-attenue">{{ t.description }}</span>
        <span class="mt-auto inline-flex items-center gap-1 text-sm font-semibold text-cadeau">
          Créer sur le site de Noël
          <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M8 4H4v12h12v-4M11 3h6v6M17 3l-8 8" />
          </svg>
        </span>
      </a>
    </li>
  </ul>
</template>
