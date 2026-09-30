<script setup lang="ts">
/**
 * Les actes demandés par la famille — à une mairie, à des archives — et où ils
 * en sont.
 *
 * Par défaut, **ce qui attend une réponse** : c'est la question qu'on vient
 * poser ici (« a-t-on des retours en attente ? »), et celle qui dit quoi
 * relancer. L'historique complet est à un clic.
 *
 * La recherche sert le geste le plus courant : **une réponse arrive, on
 * retrouve la démarche** — par un nom, une commune, ou l'adresse de la mairie
 * qui écrit. Avant d'écrire à une mairie, c'est aussi ici qu'on vérifie que
 * personne ne l'a déjà fait.
 */
import { ref } from 'vue'
import ListeDemarches from '@/components/demarches/ListeDemarches.vue'

const enAttenteSeulement = ref(true)
const recherche = ref('')
const comptes = ref<{ total: number; enAttente: number } | null>(null)
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-6">
    <header class="mb-5">
      <h1 class="text-xl font-semibold text-texte">Actes demandés</h1>
      <p class="mt-1 text-sm text-attenue">
        Les pièces demandées à une mairie ou à des archives, avec le texte de chaque message. Une réponse arrive :
        retrouvez la démarche par un nom, une commune ou l’adresse de la mairie. Avant d’écrire à une mairie, vérifiez
        ici que personne ne l’a déjà fait.
      </p>
    </header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
      <div class="inline-flex shrink-0 rounded-champ border border-ligne p-0.5 text-sm" role="group" aria-label="Filtrer les démarches">
        <button
          type="button"
          class="rounded-champ px-3 py-1"
          :class="enAttenteSeulement ? 'bg-accent-doux font-semibold text-texte' : 'text-attenue'"
          :aria-pressed="enAttenteSeulement"
          @click="enAttenteSeulement = true"
        >
          En attente<template v-if="comptes !== null"> ({{ comptes.enAttente }})</template>
        </button>
        <button
          type="button"
          class="rounded-champ px-3 py-1"
          :class="!enAttenteSeulement ? 'bg-accent-doux font-semibold text-texte' : 'text-attenue'"
          :aria-pressed="!enAttenteSeulement"
          @click="enAttenteSeulement = false"
        >
          Toutes<template v-if="comptes !== null"> ({{ comptes.total }})</template>
        </button>
      </div>

      <label class="flex min-w-0 flex-1 flex-col gap-1">
        <span class="lecteur-ecran">Rechercher une démarche</span>
        <input
          v-model="recherche"
          type="search"
          class="w-full rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
          placeholder="Rechercher : Provost, Fleurance, mairie@…, mariage…"
        />
      </label>
    </div>

    <ListeDemarches :en-attente-seulement="enAttenteSeulement" :recherche="recherche" @comptes="comptes = $event" />
  </div>
</template>
