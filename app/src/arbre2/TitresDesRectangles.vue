<script setup lang="ts">
/**
 * Le titre de chaque rectangle de famille de `/arbre`, en haut à gauche ; il suit le
 * bord gauche de l'écran quand le rectangle en déborde, jamais sous la gouttière des
 * générations.
 *
 * Collé au bord, il suit le moindre déplacement : `placer()` l'écrit à chaque image,
 * sans rendu de composant (ADR 0007, règle 3). Vue ne rend que la liste des titres,
 * une fois par disposition (ou quand on affiche ou masque les rectangles).
 */
import { onUpdated, ref } from 'vue'
import { syDe, type Cadrage, type Canevas } from '@/histoire/vue'
import { dansLaFenetre } from './cadrage'

export interface TitreDeRectangle {
  cle: string
  /** Le rectangle, en coordonnées MONDE. */
  x: number
  y: number
  w: number
  h: number
  couleur: string
  texte: string
  effectif: number
}

const props = defineProps<{
  titres: TitreDeRectangle[]
  /** La largeur de la gouttière des générations : aucun titre dessous. */
  gouttiere: number
}>()

const racine = ref<HTMLElement | null>(null)
let dernier: [Cadrage, Canevas] | null = null

function placer(c: Cadrage, cv: Canevas): void {
  dernier = [c, cv]
  const el = racine.value
  if (!el) return
  const sy = syDe(c.s, cv)
  const parCle = new Map(props.titres.map((t) => [t.cle, t]))
  for (const titre of el.querySelectorAll<HTMLElement>('[data-rectangle]')) {
    const t = parCle.get(titre.dataset['rectangle'] ?? '')
    if (!t) continue
    const x = Math.max(c.tx + t.x * c.s + 8, props.gouttiere + 8)
    const largeur = c.tx + (t.x + t.w) * c.s - x - 8
    const visible = dansLaFenetre(c, cv, t.x, t.y, t.w, t.h) && largeur > 40
    titre.style.display = visible ? '' : 'none'
    if (!visible) continue
    titre.style.left = `${x}px`
    titre.style.top = `${c.ty + t.y * sy + 4}px`
    titre.style.maxWidth = `${largeur}px`
  }
}

onUpdated(() => dernier && placer(...dernier))

defineExpose({ placer })
</script>

<template>
  <div ref="racine" class="contents">
    <div
      v-for="t in titres"
      :key="`titre-${t.cle}`"
      :data-rectangle="t.cle"
      class="titre-rectangle pointer-events-none absolute truncate"
      :style="{ color: t.couleur }"
      style="display: none"
    >
      {{ t.texte }} <span class="titre-rectangle-effectif">· {{ t.effectif }}</span>
    </div>
  </div>
</template>

<style scoped>
.titre-rectangle {
  font-size: 11px;
  font-weight: 600;
  line-height: 14px;
  letter-spacing: 0.02em;
  white-space: nowrap;
}
/* Plus discret que le titre : sa couleur, aux trois quarts — dans la peinture, sans `opacity`. */
.titre-rectangle-effectif {
  font-weight: 400;
  color: color-mix(in srgb, currentColor 75%, transparent);
}
</style>
