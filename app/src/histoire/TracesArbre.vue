<script setup lang="ts">
/**
 * Les traits de l'arbre — unions, filiations, divorces, liens en attente, la
 * famille en surbrillance — et les zones de clic des familles, dans **un seul
 * `<svg>` piloté impérativement**.
 *
 * ADR 0007, rappelé ici parce qu'un ADR qu'il faut aller chercher n'est pas lu :
 *  1. **Le zoom passe par le `viewBox`**, jamais par `transform: scale()` :
 *     `cadrer()` écrit les quatre nombres, une fois par image ; le découpage a
 *     lieu avant la rastérisation, seul le visible est peint.
 *  2. **Jamais `opacity` sur un élément SVG** : `stroke-opacity` (une propriété
 *     de la peinture), jamais une couche de composition par trait.
 *  3. **Vue ne tient aucun trait** : le `<svg>` est vide dans le gabarit ; ses
 *     chemins sont construits quand la disposition change, puis mutés par les
 *     observateurs des seuls réglages qui changent le dessin. Pas de `v-for`, pas
 *     de `:class`, pas de `v-if` à l'intérieur.
 *
 * Le pointeur traverse le `<svg>` (le glisser du canevas continue de marcher) ;
 * seuls les traits des zones de clic l'attrapent, et le clic remonte au seul
 * écouteur du canevas, qui lit `data-famille` (`eclairage.ts`).
 */
import { onMounted, ref, watch } from 'vue'
import type { TracesHistoire } from './lignees'
import { stylesDesTraits, type CheminDeTrait } from './apparence'
import type { Niveau } from './vue'

const props = defineProps<{
  traces: TracesHistoire
  niveau: Niveau
  surligner: boolean
  surlignerTraits: boolean
  /** Le tracé de la famille en surbrillance, ou `null`. */
  eclairage: string | null
  /** L'apparition au premier affichage : les traits viennent après les fiches. */
  revelation: boolean
}>()

const SVG = 'http://www.w3.org/2000/svg'
const svg = ref<SVGSVGElement | null>(null)
let chemins: Record<CheminDeTrait | 'eclairage', SVGPathElement> | null = null

function chemin(parent: Element, attributs: Record<string, string>): SVGPathElement {
  const p = document.createElementNS(SVG, 'path')
  for (const [cle, valeur] of Object.entries(attributs)) p.setAttribute(cle, valeur)
  parent.append(p)
  return p
}

/** Une fois par disposition : le dessin entier est reconstruit, pas réconcilié. */
function construire(): void {
  const racine = svg.value
  if (!racine) return
  const t = props.traces
  const commun = { fill: 'none', 'vector-effect': 'non-scaling-stroke', class: 'trait' }
  const visibles = document.createElementNS(SVG, 'g')
  chemins = {
    ordinaires: chemin(visibles, { ...commun, d: t.ordinaires }),
    divorces: chemin(visibles, { ...commun, d: t.divorces, 'stroke-dasharray': '5 4' }),
    attente: chemin(visibles, { ...commun, d: t.attente, 'stroke-dasharray': '7 4' }),
    directs: chemin(visibles, { ...commun, d: t.directs }),
    // La famille en surbrillance, par-dessus ses traits ordinaires.
    eclairage: chemin(visibles, {
      fill: 'none',
      'vector-effect': 'non-scaling-stroke',
      'stroke-width': '3',
      'stroke-linecap': 'round',
    }),
  }
  chemins.eclairage.style.setProperty('stroke', 'var(--h-accent)')
  // Les ZONES DE CLIC : un trait transparent et large par famille.
  const zones = document.createElementNS(SVG, 'g')
  for (const f of t.familles) {
    chemin(zones, {
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
  racine.replaceChildren(visibles, zones)
  regler()
  eclairer()
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
 * L'apparition des traits, après les fiches : `stroke-opacity` (une propriété de la
 * peinture), et non l'`opacity` du `<svg>` entier. Global : les chemins sont créés
 * impérativement et ne portent pas l'attribut d'un style `scoped`.
 */
@keyframes apparition-trait {
  from {
    stroke-opacity: 0;
  }
}
.traits-apparaissent .trait {
  animation: apparition-trait 900ms ease-out 560ms backwards;
}
</style>
