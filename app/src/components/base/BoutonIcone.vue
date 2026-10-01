<script setup lang="ts">
/**
 * Bouton réduit à son icône, pour les actions répétées ligne à ligne (tableau
 * des comptes).
 *
 * Le libellé n'est pas affiché mais il existe : `aria-label` pour les lecteurs
 * d'écran, `title` pour le survol. Quand le bouton est éteint pour une raison
 * (vue empruntée), `infobulle` remplace le libellé au survol — la raison compte
 * plus que le nom du geste qu'on ne peut pas faire.
 */
type Variante = 'neutre' | 'accent' | 'danger'

const {
  libelle,
  infobulle = undefined,
  variante = 'neutre',
  chargement = false,
  desactive = false,
} = defineProps<{
  libelle: string
  infobulle?: string | undefined
  variante?: Variante
  chargement?: boolean
  desactive?: boolean
}>()

const CLASSES: Record<Variante, string> = {
  neutre: 'border-ligne text-texte hover:border-accent hover:text-accent',
  accent: 'border-accent bg-accent text-fond hover:opacity-90',
  danger: 'border-alerte-bord text-alerte hover:border-alerte hover:bg-alerte-doux',
}
</script>

<template>
  <button
    type="button"
    class="inline-flex size-8 shrink-0 items-center justify-center rounded-champ border transition-colors disabled:cursor-not-allowed disabled:opacity-55"
    :class="CLASSES[variante]"
    :disabled="desactive || chargement"
    :aria-busy="chargement"
    :aria-label="libelle"
    :title="infobulle ?? libelle"
  >
    <span
      v-if="chargement"
      class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"
      aria-hidden="true"
    />
    <svg
      v-else
      viewBox="0 0 24 24"
      class="size-4"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <slot />
    </svg>
  </button>
</template>
