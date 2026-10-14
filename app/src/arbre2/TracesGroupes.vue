<script setup lang="ts">
/**
 * Les traits de `/arbre` — unions, filiations, divorces, liens en attente, la famille
 * en surbrillance —, les RECTANGLES des familles dessous, et les zones de clic des
 * familles, dans **un seul `<svg>` piloté impérativement**.
 *
 * ADR 0007, rappelé ici parce qu'un ADR qu'il faut aller chercher n'est pas lu :
 *  1. **Le zoom passe par le `viewBox`**, jamais par `transform: scale()` :
 *     `cadrer()` écrit les quatre nombres, une fois par image ; le découpage a lieu
 *     avant la rastérisation, seul le visible est peint.
 *  2. **Jamais `opacity` sur un élément SVG** : `stroke-opacity` (une propriété de la
 *     peinture), jamais une couche de composition par trait.
 *  3. **Vue ne tient aucun trait** : le `<svg>` est vide dans le gabarit ; ses
 *     chemins sont construits quand la disposition change, puis mutés par les
 *     observateurs des seuls réglages qui changent le dessin. Pas de `v-for`, pas de
 *     `:class`, pas de `v-if` à l'intérieur.
 *
 * Le pointeur traverse le `<svg>` (le glisser du canevas continue de marcher) ;
 * seuls les traits des zones de clic l'attrapent, et le clic remonte au seul
 * écouteur du canevas, qui lit `data-famille` (`histoire/eclairage.ts`).
 */
import { onMounted, ref, watch } from 'vue'
import type { TracesHistoire } from '@/histoire/lignees'
import type { Niveau } from '@/histoire/vue'
import type { RectangleDeGroupe } from './groupes'
import { stylesDesTraits, type CheminDeTrait } from './apparence'

const props = defineProps<{
  traces: TracesHistoire
  /** Les rectangles des familles ; vide quand ils sont masqués (ou en pedigree). */
  rectangles: RectangleDeGroupe[]
  /** La couleur d'un rectangle, d'après sa clé. */
  couleur: (cle: string) => string
  niveau: Niveau
  surligner: boolean
  surlignerTraits: boolean
  /** Le tracé de la famille en surbrillance, ou `null`. */
  eclairage: string | null
  /**
   * Les zones de clic des familles (un trait transparent par famille, des milliers) :
   * elles attendent que la première image soit peinte, pour ne pas la retarder.
   */
  zones: boolean
  /** L'apparition au premier affichage : les traits viennent après les fiches. */
  revelation: boolean
}>()

const SVG = 'http://www.w3.org/2000/svg'
const svg = ref<SVGSVGElement | null>(null)
let fonds: SVGGElement | null = null
let zones: SVGGElement | null = null
let chemins: Record<CheminDeTrait | 'eclairage', SVGPathElement> | null = null

function element<K extends keyof SVGElementTagNameMap>(nom: K, parent: ParentNode, attributs: Record<string, string>): SVGElementTagNameMap[K] {
  const e = document.createElementNS(SVG, nom)
  for (const [cle, valeur] of Object.entries(attributs)) e.setAttribute(cle, valeur)
  parent.append(e)
  return e
}

/** Une fois par disposition : le dessin entier est reconstruit, pas réconcilié. */
function construire(): void {
  const racine = svg.value
  if (!racine) return
  const t = props.traces
  const commun = { fill: 'none', 'vector-effect': 'non-scaling-stroke', class: 'trait' }
  fonds = document.createElementNS(SVG, 'g')
  const visibles = document.createElementNS(SVG, 'g')
  chemins = {
    ordinaires: element('path', visibles, { ...commun, d: t.ordinaires }),
    divorces: element('path', visibles, { ...commun, d: t.divorces, 'stroke-dasharray': '5 4' }),
    attente: element('path', visibles, { ...commun, d: t.attente, 'stroke-dasharray': '7 4' }),
    directs: element('path', visibles, { ...commun, d: t.directs }),
    // La famille en surbrillance, par-dessus ses traits ordinaires.
    eclairage: element('path', visibles, {
      fill: 'none',
      stroke: 'var(--h-accent)',
      'stroke-width': '3',
      'stroke-linecap': 'round',
      'vector-effect': 'non-scaling-stroke',
    }),
  }
  zones = document.createElementNS(SVG, 'g')
  racine.replaceChildren(fonds, visibles, zones)
  encadrer()
  regler()
  eclairer()
  poserLesZones()
}

/** Les rectangles des familles, sous tout le reste. */
function encadrer(): void {
  if (!fonds) return
  fonds.replaceChildren()
  for (const r of props.rectangles) {
    const c = props.couleur(r.cle)
    const rect = element('rect', fonds, {
      x: String(r.x),
      y: String(r.y),
      width: String(r.w),
      height: String(r.h),
      rx: '14',
      'stroke-width': '1.5',
      'vector-effect': 'non-scaling-stroke',
      class: 'trait',
    })
    rect.style.setProperty('fill', `color-mix(in srgb, ${c} 7%, transparent)`)
    rect.style.setProperty('stroke', `color-mix(in srgb, ${c} 55%, transparent)`)
  }
}

/** Les ZONES DE CLIC : un trait transparent et large par famille. */
function poserLesZones(): void {
  if (!zones) return
  if (!props.zones) {
    zones.replaceChildren()
    return
  }
  if (zones.childElementCount) return
  const fragment = document.createDocumentFragment()
  for (const f of props.traces.familles) {
    element('path', fragment, {
      d: f.d,
      'data-famille': f.id,
      class: 'cursor-pointer',
      fill: 'none',
      stroke: 'transparent',
      'stroke-width': '12',
      'stroke-linecap': 'round',
      'vector-effect': 'non-scaling-stroke',
      'pointer-events': 'stroke',
    })
  }
  zones.append(fragment)
}

/** Les réglages qui changent le dessin : `stroke-opacity`, jamais `opacity`. */
function regler(): void {
  if (!chemins) return
  const styles = stylesDesTraits(props.niveau, props.surligner, props.surlignerTraits)
  for (const nom of ['ordinaires', 'divorces', 'attente', 'directs'] as const) {
    for (const [propriete, valeur] of Object.entries(styles[nom])) chemins[nom].style.setProperty(propriete, valeur)
  }
}

function eclairer(): void {
  if (!chemins) return
  const d = props.eclairage
  if (d === null) chemins.eclairage.setAttribute('display', 'none')
  else {
    chemins.eclairage.setAttribute('d', d)
    chemins.eclairage.removeAttribute('display')
  }
}

function reveler(): void {
  svg.value?.classList.toggle('traits-apparaissent', props.revelation)
}

watch(() => props.traces, construire)
watch(() => props.rectangles, encadrer)
watch(() => props.zones, poserLesZones)
watch(() => [props.niveau, props.surligner, props.surlignerTraits], regler)
watch(() => props.eclairage, eclairer)
watch(() => props.revelation, reveler)
onMounted(() => {
  construire()
  reveler()
})

defineExpose({
  /** Pan et zoom : une seule écriture, le `viewBox` (`viewBoxDe`). */
  cadrer(viewBox: string): void {
    svg.value?.setAttribute('viewBox', viewBox)
  },
})
</script>

<template>
  <svg ref="svg" class="pointer-events-none absolute inset-0 h-full w-full" preserveAspectRatio="none" aria-hidden="true" />
</template>

<style>
/*
 * L'apparition des traits et des rectangles, un peu après les fiches : `stroke-opacity`
 * et `fill-opacity` (des propriétés de la peinture), et non l'`opacity` du `<svg>`
 * entier. Global : les chemins sont créés impérativement et ne portent pas
 * l'attribut d'un style `scoped`.
 */
@keyframes apparition-trait {
  from {
    stroke-opacity: 0;
    fill-opacity: 0;
  }
}
.traits-apparaissent .trait {
  animation: apparition-trait 600ms ease-out 210ms backwards;
}
</style>
