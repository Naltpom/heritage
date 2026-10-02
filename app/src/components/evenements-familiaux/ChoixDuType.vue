<script setup lang="ts">
/**
 * **Que crée-t-on ?** — la première étape de « Créer un événement » : deux
 * grandes cartes, « Rassemblement » et « Tirage au sort ».
 *
 * Demande du commanditaire (02/10/2026) : « lors de la création d'un événement
 * on choisit Rassemblement ou Tirage au sort ». Chaque carte est un **lien**
 * vers la même page avec `?type=…` : le retour du navigateur ramène au choix,
 * et l'adresse d'un formulaire se partage telle quelle.
 *
 * Deux cartes pleine largeur sur téléphone, côte à côte au-delà : c'est un
 * choix qu'on fait du pouce, sans viser. Suite : `choix-du-type-rendu.test.ts`.
 */
import { TYPES_D_EVENEMENT } from './liste-commune'
import PastilleDeType from './PastilleDeType.vue'
</script>

<template>
  <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2" aria-label="Type d’événement">
    <li v-for="t in TYPES_D_EVENEMENT" :key="t.valeur">
      <RouterLink
        :to="{ name: 'evenement.nouveau', query: { type: t.valeur } }"
        class="flex h-full min-h-36 flex-col gap-2 rounded-panneau border border-ligne bg-panneau p-5 transition-colors hover:border-accent focus-visible:border-accent"
      >
        <PastilleDeType :type="t.valeur" grand />
        <span class="text-lg font-semibold text-texte">{{ t.libelle }}</span>
        <span class="text-sm text-attenue">{{ t.description }}</span>
      </RouterLink>
    </li>
  </ul>
</template>
