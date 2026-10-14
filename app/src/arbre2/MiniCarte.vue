<script setup lang="ts">
/**
 * La mini-carte de `/arbre` (bureau) : les branches de gauche à droite, et le cadre de
 * ce qu'on regarde. Un clic y centre la vue.
 *
 * Les bandes ne changent qu'avec la disposition ; le cadre suit chaque image d'un
 * geste et s'écrit donc impérativement (`placer`), sans rendu (ADR 0007, règle 3).
 */
import { computed, onUpdated, ref } from 'vue'
import type { DispositionHistoire } from '@/histoire/lignees'
import type { Cadrage, Canevas } from '@/histoire/vue'
import { attenuer, couleurBranche } from './apparence'

const props = defineProps<{
  disposition: DispositionHistoire
  /** « Ensemble », « Familles », « Fiches ». */
  palier: string
}>()

const emit = defineEmits<{ centrer: [xMonde: number] }>()

const LARGEUR_MINI = 236

const k = computed(() => LARGEUR_MINI / props.disposition.largeur)

/** Les bandes de couleur, en pixels de mini-carte. */
const bandes = computed(() => {
  const d = props.disposition
  const e = k.value
  return d.quarts
    .filter((q) => !q.branche.startsWith('c'))
    .map((q) => ({ cle: q.branche as string, branche: q.branche as string, x: q.x0 * e, w: Math.max(2, (q.x1 - q.x0) * e) }))
    .concat(
      d.alliances.map((a) => ({ cle: `alliance-${a.pont}`, branche: 'hors', x: a.x0 * e, w: Math.max(2, (a.x1 - a.x0) * e) })),
      d.zoneDetachee
        ? [{ cle: 'detaches', branche: 'hors', x: d.zoneDetachee.x0 * e, w: Math.max(2, (d.zoneDetachee.x1 - d.zoneDetachee.x0) * e) }]
        : [],
    )
})

const cadre = ref<HTMLElement | null>(null)
let dernier: [Cadrage, Canevas] | null = null

/** Le cadre de la vue, d'après le cadrage courant. */
function placer(c: Cadrage, cv: Canevas): void {
  dernier = [c, cv]
  const el = cadre.value
  if (!el) return
  const x0 = Math.max(0, -c.tx / c.s)
  const x1 = Math.min(props.disposition.largeur, (cv.largeur - c.tx) / c.s)
  el.style.left = `${x0 * k.value}px`
  el.style.width = `${Math.max(6, (x1 - x0) * k.value)}px`
}

onUpdated(() => dernier && placer(...dernier))

function surClic(e: MouseEvent): void {
  const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
  emit('centrer', (e.clientX - r.left - 12) / k.value)
}

defineExpose({ placer })
</script>

<template>
  <div
    class="absolute right-4 bottom-4 flex w-[260px] cursor-pointer flex-col gap-1.5 rounded-panneau border border-ligne bg-panneau/95 px-3 py-2.5"
    role="button"
    aria-label="Mini-carte : cliquer pour centrer la vue"
    @pointerdown.stop
    @click="surClic"
  >
    <div class="relative h-[18px] overflow-hidden rounded bg-ligne/60">
      <div
        v-for="b in bandes"
        :key="b.cle"
        class="absolute inset-y-0"
        :style="{ left: `${b.x}px`, width: `${b.w}px`, background: attenuer(couleurBranche(b.branche), 0.75) }"
      />
      <div ref="cadre" class="absolute -inset-y-px box-border rounded-[3px] border-2 border-texte" />
    </div>
    <div class="flex justify-between text-[10.5px] text-attenue">
      <span>Côté père</span><span>{{ palier }}</span><span>Côté mère</span>
    </div>
  </div>
</template>
