<script setup lang="ts">
/**
 * L'avatar du compte, dans l'en-tête et sur l'entrée « Moi » de la barre du bas.
 *
 * La photo de **sa fiche** quand elle existe (`session.portrait`, la vignette du
 * portrait actif), sinon une silhouette de personne inconnue — la même que sur
 * une fiche sans portrait. Plus d'initiales : « A » pour « Administration » ne
 * disait rien de qui l'on est dans l'arbre.
 *
 * Décorative : le bouton ou le lien qui la porte a déjà son nom (« Moi »).
 */
import { computed } from 'vue'
import { useSession } from '@/composables/useSession'

const { taille = 32, actif = false } = defineProps<{
  /** Côté en pixels. */
  taille?: number
  /** Entrée courante de la barre du bas : le fond passe à l'accent plein. */
  actif?: boolean
}>()

const { session } = useSession()
const photo = computed(() => session.value?.portrait?.vignetteUrl ?? null)
</script>

<template>
  <img
    v-if="photo !== null"
    :src="photo"
    alt=""
    class="shrink-0 rounded-full object-cover"
    :class="actif ? 'ring-2 ring-accent' : ''"
    :style="{ width: `${taille}px`, height: `${taille}px` }"
    data-avatar="photo"
  />
  <span
    v-else
    class="flex shrink-0 items-center justify-center overflow-hidden rounded-full"
    :class="actif ? 'bg-accent text-fond' : 'bg-accent-doux text-accent'"
    :style="{ width: `${taille}px`, height: `${taille}px` }"
    aria-hidden="true"
    data-avatar="silhouette"
  >
    <svg viewBox="0 0 24 24" class="size-[78%] translate-y-[12%]" fill="currentColor" aria-hidden="true">
      <circle cx="12" cy="8" r="4.2" />
      <path d="M3.5 22c0-4.7 3.8-8.2 8.5-8.2s8.5 3.5 8.5 8.2z" />
    </svg>
  </span>
</template>
