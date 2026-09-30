<script setup lang="ts">
/**
 * Les actes demandés par la famille — à une mairie, à des archives — et où ils
 * en sont.
 *
 * Par défaut, **ce qui attend une réponse** : c'est la question qu'on vient
 * poser ici (« a-t-on des retours en attente ? »), et celle qui dit quoi
 * relancer. L'historique complet est à un clic.
 *
 * Avant d'écrire à une mairie, c'est aussi ici qu'on vérifie que personne ne
 * l'a déjà fait.
 */
import { ref } from 'vue'
import ListeDemarches from '@/components/demarches/ListeDemarches.vue'

const enAttenteSeulement = ref(true)
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-6">
    <header class="mb-5">
      <h1 class="text-xl font-semibold text-texte">Actes demandés</h1>
      <p class="mt-1 text-sm text-attenue">
        Les pièces demandées à une mairie ou à des archives, avec le texte de chaque message. Avant d’écrire à une
        mairie, vérifiez ici que personne ne l’a déjà fait.
      </p>
    </header>

    <div class="mb-4 inline-flex rounded-champ border border-ligne p-0.5 text-sm" role="group" aria-label="Filtrer les démarches">
      <button
        type="button"
        class="rounded-champ px-3 py-1"
        :class="enAttenteSeulement ? 'bg-accent-doux font-semibold text-texte' : 'text-attenue'"
        :aria-pressed="enAttenteSeulement"
        @click="enAttenteSeulement = true"
      >
        En attente de réponse
      </button>
      <button
        type="button"
        class="rounded-champ px-3 py-1"
        :class="!enAttenteSeulement ? 'bg-accent-doux font-semibold text-texte' : 'text-attenue'"
        :aria-pressed="!enAttenteSeulement"
        @click="enAttenteSeulement = false"
      >
        Toutes
      </button>
    </div>

    <ListeDemarches :en-attente-seulement="enAttenteSeulement" />
  </div>
</template>
