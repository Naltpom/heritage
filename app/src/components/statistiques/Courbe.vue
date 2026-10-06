<script setup lang="ts">
/**
 * La part vérifiée, mois par mois — une courbe en SVG, de 0 à 100 %.
 *
 * Le `viewBox` est étiré (`preserveAspectRatio="none"`) pour remplir la
 * largeur du téléphone comme celle du bureau ; le trait garde son épaisseur
 * (`vector-effect: non-scaling-stroke`) et aucun texte n'est dans le SVG — les
 * libellés des deux bouts sont du HTML, qu'aucun étirement ne déforme.
 *
 * Un seul point (un arbre commencé ce mois-ci) se dessine en pastille : une
 * courbe d'un point n'a pas de trait.
 */
import { computed } from 'vue'
import { moisLisible, pourcent } from './format'

const props = defineProps<{
  points: { mois: string; verifiees: number; total: number }[]
}>()

const LARGEUR = 300
const HAUTEUR = 80

const coordonnees = computed(() => {
  const n = props.points.length
  return props.points.map((p, i) => ({
    x: n > 1 ? (i / (n - 1)) * LARGEUR : LARGEUR / 2,
    y: HAUTEUR - (p.total > 0 ? (p.verifiees / p.total) * HAUTEUR : 0),
    ...p,
  }))
})

const trace = computed(() => coordonnees.value.map((c, i) => `${i === 0 ? 'M' : 'L'}${c.x.toFixed(1)},${c.y.toFixed(1)}`).join(' '))
const aire = computed(() =>
  coordonnees.value.length > 1 ? `${trace.value} L${LARGEUR},${HAUTEUR} L0,${HAUTEUR} Z` : '',
)

const premier = computed(() => props.points[0] ?? null)
const dernier = computed(() => props.points[props.points.length - 1] ?? null)

const resume = computed(() => {
  if (premier.value === null || dernier.value === null) return 'Aucun historique'
  return `Part des fiches vérifiées : ${pourcent(premier.value.verifiees, premier.value.total)} en ${moisLisible(premier.value.mois)}, ${pourcent(dernier.value.verifiees, dernier.value.total)} en ${moisLisible(dernier.value.mois)}`
})
</script>

<template>
  <figure class="m-0">
    <div class="relative">
    <svg
      role="img"
      :aria-label="resume"
      :viewBox="`0 -4 ${LARGEUR} ${HAUTEUR + 8}`"
      preserveAspectRatio="none"
      class="block h-20 w-full overflow-visible"
    >
      <line x1="0" :y1="HAUTEUR" :x2="LARGEUR" :y2="HAUTEUR" stroke="var(--h-ligne)" stroke-width="1" vector-effect="non-scaling-stroke" />
      <line x1="0" :y1="HAUTEUR / 2" :x2="LARGEUR" :y2="HAUTEUR / 2" stroke="var(--h-ligne)" stroke-width="1" stroke-dasharray="3 4" vector-effect="non-scaling-stroke" />
      <path v-if="aire" :d="aire" fill="var(--h-ok-doux)" />
      <path v-if="coordonnees.length > 1" :d="trace" fill="none" stroke="var(--h-ok)" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
    </svg>
    <!-- Les points en HTML : dans un SVG étiré, un cercle deviendrait une ellipse. -->
    <span
      v-for="c in coordonnees"
      :key="c.mois"
      class="absolute block h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full"
      :style="{ left: `${(100 * c.x) / LARGEUR}%`, top: `${(100 * (c.y + 4)) / (HAUTEUR + 8)}%`, background: 'var(--h-ok)' }"
      :title="`${moisLisible(c.mois)} : ${pourcent(c.verifiees, c.total)} (${c.verifiees} / ${c.total})`"
      aria-hidden="true"
    />
    </div>
    <figcaption v-if="premier && dernier" class="mt-1 flex justify-between text-[11px] text-attenue" aria-hidden="true">
      <span>{{ moisLisible(premier.mois) }}</span>
      <span>50 % en pointillé</span>
      <span>{{ moisLisible(dernier.mois) }}</span>
    </figcaption>
  </figure>
</template>
