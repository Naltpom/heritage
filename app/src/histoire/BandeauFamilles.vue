<script setup lang="ts">
/**
 * Le bandeau des familles, collé en haut à tous les niveaux : chaque branche
 * garde son nom visible tant qu'elle occupe l'écran (`bandeau.ts`). Il remplace
 * les grands titres de branche posés au-dessus de l'arbre, qui disparaissaient
 * dès qu'on zoomait ou qu'on descendait dans les générations.
 *
 * Il suit le moindre déplacement, donc il est **placé impérativement** :
 * `placer()` est appelé à chaque image d'un geste et écrit la position des
 * traits et des noms (et le nom tronqué, s'il a fallu) sans rendu de
 * composant. Vue ne rend que la liste des branches, une fois par disposition.
 */
import { nextTick, ref, watch } from 'vue'
import { bandeauDesFamilles } from './bandeau'

/** Une branche, en coordonnées MONDE. */
export interface BrancheMonde {
  cle: string
  couleur: string
  nom: string
  sous: string
  x0: number
  x1: number
}

const props = defineProps<{
  branches: BrancheMonde[]
  /** Le bord gauche utile : la largeur de la gouttière des générations. */
  gouttiere: number
  mobile: boolean
}>()

const racine = ref<HTMLElement | null>(null)
let dernier: { versEcranX: (x: number) => number; largeur: number } | null = null

/** Pose les traits et les noms pour le cadrage courant. */
function placer(versEcranX: (x: number) => number, largeur: number): void {
  dernier = { versEcranX, largeur }
  const el = racine.value
  if (!el) return
  const segments = bandeauDesFamilles(
    props.branches.map((b) => ({ ...b, x0: versEcranX(b.x0), x1: versEcranX(b.x1) })),
    props.gouttiere,
    largeur,
    props.mobile ? 12 : 13,
  )
  const parCle = new Map(segments.map((s) => [s.cle, s]))
  for (const groupe of el.querySelectorAll<HTMLElement>('[data-branche]')) {
    const s = parCle.get(groupe.dataset['branche'] ?? '')
    const trait = groupe.querySelector<HTMLElement>('[data-trait]')!
    const etiquette = groupe.querySelector<HTMLElement>('[data-etiquette]')!
    if (!s) {
      groupe.style.display = 'none'
      continue
    }
    groupe.style.display = ''
    trait.style.left = `${s.gauche}px`
    trait.style.width = `${s.largeur}px`
    if (!s.etiquette) {
      etiquette.style.display = 'none'
      continue
    }
    etiquette.style.display = ''
    etiquette.style.left = `${s.etiquette.x}px`
    etiquette.querySelector<HTMLElement>('[data-nom]')!.textContent = s.etiquette.nom
    const sous = etiquette.querySelector<HTMLElement>('[data-sous]')!
    sous.textContent = s.etiquette.sous
    sous.style.display = s.etiquette.sous ? '' : 'none'
  }
}

// Les branches ont changé (nouvelle disposition, largeur d'écran) : on replace une fois rendues.
watch(
  () => [props.branches, props.gouttiere, props.mobile],
  () => void nextTick(() => dernier && placer(dernier.versEcranX, dernier.largeur)),
)

defineExpose({ placer })
</script>

<template>
  <div
    ref="racine"
    class="bandeau-familles pointer-events-none absolute inset-x-0 top-0 z-[1]"
    :style="{ height: `${mobile ? 30 : 40}px` }"
    aria-hidden="true"
  >
    <div v-for="b in branches" :key="b.cle" :data-branche="b.cle" style="display: none">
      <div data-trait class="absolute bottom-0 h-[3px] rounded-full" :style="{ background: b.couleur }" />
      <div data-etiquette class="absolute top-1 flex -translate-x-1/2 flex-col items-center leading-tight whitespace-nowrap">
        <span data-nom class="font-bold tracking-tight" :class="mobile ? 'text-xs' : 'text-[13px]'" :style="{ color: b.couleur }" />
        <span data-sous class="text-[11px] text-attenue" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.bandeau-familles {
  background: linear-gradient(180deg, var(--h-fond) 70%, transparent);
}
</style>
