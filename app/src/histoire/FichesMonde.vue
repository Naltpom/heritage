<script setup lang="ts">
/**
 * Les boîtes des fiches, en coordonnées **monde**.
 *
 * Composant à part pour une raison de coût : déplacer la vue ne change que la
 * transformation portée par le parent. Ce composant ne se rend à nouveau que
 * lorsque ses styles changent — au passage du niveau Ensemble, ou quand un
 * filtre bouge —, jamais à chaque image d'un glisser.
 *
 * Aucun texte ici : les textes sont dessinés par la vue, en coordonnées écran,
 * à taille fixe.
 */
import type { FicheHistoire } from './lignees'

defineProps<{
  fiches: FicheHistoire[]
  styles: Record<string, string>[]
}>()

const emit = defineEmits<{ choisir: [fiche: FicheHistoire] }>()

function surTouche(e: KeyboardEvent, fiche: FicheHistoire): void {
  if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault()
    emit('choisir', fiche)
  }
}
</script>

<template>
  <div
    v-for="(fiche, i) in fiches"
    :key="fiche.id"
    role="button"
    tabindex="0"
    class="fiche-histoire"
    :aria-label="fiche.noeud.displayName"
    :style="styles[i]"
    @click="emit('choisir', fiche)"
    @keydown="surTouche($event, fiche)"
  />
</template>

<style scoped>
.fiche-histoire {
  position: absolute;
  box-sizing: border-box;
  cursor: pointer;
}
.fiche-histoire:focus-visible {
  outline: 2px solid var(--color-accent);
  outline-offset: 2px;
}
</style>
