<script setup lang="ts">
/**
 * La gouttière des générations, à gauche : « Grands-parents · 1930 », ou
 * « +2 » sur téléphone, à la hauteur de chaque rangée.
 *
 * Le libellé ne change qu'avec la place (une rangée basse n'a la place que pour
 * une ligne) : il vient de l'instantané du cadrage, rendu par Vue. La HAUTEUR,
 * elle, suit le moindre déplacement : `placer()` l'écrit à chaque image, sans
 * rendu de composant (ADR 0007, règle 3).
 */
import { nextTick, ref, watch } from 'vue'

export interface EtiquetteDeGeneration {
  rangee: number
  /** La hauteur de la rangée, en coordonnées MONDE. */
  y: number
  libelle: string
  annee: string
}

const props = defineProps<{
  generations: EtiquetteDeGeneration[]
  largeur: number
  mobile: boolean
  /** Le décalage au-dessus de la rangée : 8 px sur une ligne, 14 sur deux. */
  decalage: number
}>()

const racine = ref<HTMLElement | null>(null)
let dernier: { versEcranY: (y: number) => number; hauteur: number } | null = null

/** Pose chaque libellé à la hauteur de sa rangée ; ceux qui sortent de l'écran se cachent. */
function placer(versEcranY: (y: number) => number, hauteur: number): void {
  dernier = { versEcranY, hauteur }
  const el = racine.value
  if (!el) return
  const parRangee = new Map(props.generations.map((g) => [String(g.rangee), g]))
  for (const ligne of el.querySelectorAll<HTMLElement>('[data-rangee]')) {
    const g = parRangee.get(ligne.dataset['rangee'] ?? '')
    if (!g) continue
    const y = versEcranY(g.y) - props.decalage
    const visible = y > -20 && y < hauteur
    ligne.style.display = visible ? '' : 'none'
    if (visible) ligne.style.top = `${y}px`
  }
}

watch(
  () => [props.generations, props.decalage],
  () => void nextTick(() => dernier && placer(dernier.versEcranY, dernier.hauteur)),
)

defineExpose({ placer })
</script>

<template>
  <div ref="racine" class="gouttiere pointer-events-none absolute inset-y-0 left-0" :style="{ width: `${largeur}px` }">
    <div v-for="g in generations" :key="g.rangee" :data-rangee="g.rangee" class="absolute left-3 flex flex-col leading-tight" style="display: none">
      <span class="font-semibold whitespace-nowrap text-[var(--hi-gouttiere-texte)]" :class="mobile ? 'text-[11px]' : 'text-xs'">{{ g.libelle }}</span>
      <span v-if="g.annee" class="font-mono text-[10.5px] whitespace-nowrap text-[var(--hi-gouttiere-annee)]">{{ g.annee }}</span>
    </div>
  </div>
</template>

<style scoped>
.gouttiere {
  background: linear-gradient(90deg, var(--h-fond) 62%, transparent);
}
</style>
