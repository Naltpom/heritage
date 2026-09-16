<script setup lang="ts">
/**
 * Bouton de base.
 *
 * `type="button"` par défaut, et c'est délibéré : le défaut HTML est `submit`,
 * et un bouton « Annuler » posé dans un formulaire d'inscription le soumettrait.
 *
 * Pendant le chargement, le bouton est désactivé **et** garde sa largeur : un
 * bouton qui rétrécit pendant l'appel déplace ce qu'il y a à côté, et le clic
 * suivant tombe ailleurs.
 */
type Variante = 'principal' | 'secondaire' | 'discret' | 'danger'

const {
  variante = 'principal',
  type = 'button',
  chargement = false,
  desactive = false,
  pleineLargeur = false,
} = defineProps<{
  variante?: Variante
  type?: 'button' | 'submit' | 'reset'
  chargement?: boolean
  desactive?: boolean
  pleineLargeur?: boolean
}>()

const CLASSES: Record<Variante, string> = {
  principal: 'bg-accent text-fond border-accent hover:opacity-90',
  secondaire: 'bg-panneau text-texte border-ligne hover:border-accent',
  discret: 'bg-transparent text-attenue border-transparent hover:text-texte',
  danger: 'bg-alerte-doux text-alerte border-alerte-bord hover:border-alerte',
}
</script>

<template>
  <button
    :type="type"
    :disabled="desactive || chargement"
    :aria-busy="chargement"
    class="inline-flex items-center justify-center gap-2 rounded-champ border px-3.5 py-1.5 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-55"
    :class="[CLASSES[variante], pleineLargeur ? 'w-full' : '']"
  >
    <span
      v-if="chargement"
      class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"
      aria-hidden="true"
    />
    <slot />
  </button>
</template>
