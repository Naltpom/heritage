<script setup lang="ts">
/**
 * Le canevas de l'arbre : le `<svg>` et ses gestes.
 *
 * Ce composant est une **coquille**. Il monte un `<svg>` vide, le confie à
 * `construireCanevas()` quand un graphe arrive, et n'y touche plus que par
 * l'interface impérative que le pilote lui rend. Rien de ce qui se trouve dans
 * le canevas n'est réactif — voir l'en-tête de `canevas.ts` et le § 7.2 du
 * dossier de mission.
 *
 * **Le zoom passe par le `viewBox`.** `vb` est une variable ordinaire, pas une
 * `ref` : elle change à chaque image pendant un déplacement, et la faire
 * passer par le système réactif de Vue déclencherait un cycle de rendu par
 * mouvement de souris pour écrire un attribut que l'on sait écrire soi-même.
 *
 * **Reconstruction complète sur nouveau graphe, jamais de réconciliation.**
 * Un `304` ne passe pas ici du tout : la vue ne rappelle ce composant que sur
 * `statut === 'nouveau'` (§ 5.2, étape 3).
 */
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import type { Graphe, NoeudGraphe } from '@/lib/api'
import { construireCanevas, type PiloteCanevas } from './canevas'
import {
  cadrer,
  centrerSur,
  deplacer,
  zoomer,
  type Boite,
  type CadreEcran,
} from './geometrie'

const { graphe } = defineProps<{
  /** `null` tant que le premier chargement n'a pas abouti. */
  graphe: Graphe | null
}>()

const emit = defineEmits<{
  /** Une fiche ou un losange a été cliqué — jamais pendant un déplacement. */
  selection: [noeud: NoeudGraphe]
  /** Le fond a été cliqué : la vue en fait ce qu'elle veut (fermer le panneau). */
  fond: []
}>()

const cadreRef = ref<HTMLDivElement | null>(null)
const svgRef = ref<SVGSVGElement | null>(null)

/** Exposé pour que la vue puisse lire l'index (voisinage, filtres…). */
const pilote = shallowRef<PiloteCanevas | null>(null)

/** La boîte du graphe telle que l'API la rend. **Jamais recalculée ici.** */
let boiteInitiale: Boite = { x: 0, y: 0, w: 1, h: 1 }
let vb: Boite = { x: 0, y: 0, w: 1, h: 1 }

function mesurerCadre(): CadreEcran {
  const element = cadreRef.value
  if (element === null) return { largeur: 1, hauteur: 1, gauche: 0, haut: 0 }
  const r = element.getBoundingClientRect()
  return { largeur: r.width, hauteur: r.height, gauche: r.left, haut: r.top }
}

function appliquer(): void {
  pilote.value?.appliquerViewBox(vb)
}

/* ------------------------------------------------------------------ */
/* Construction                                                        */
/* ------------------------------------------------------------------ */

function construire(): void {
  const svg = svgRef.value
  if (svg === null) return

  pilote.value?.detruire()
  if (graphe === null) {
    pilote.value = null
    return
  }

  pilote.value = construireCanevas(svg, graphe)
  boiteInitiale = { ...graphe.boite }
  recadrer()
}

/* ------------------------------------------------------------------ */
/* Gestes                                                              */
/* ------------------------------------------------------------------ */

function surMolette(ev: WheelEvent): void {
  if (pilote.value === null) return
  ev.preventDefault()
  vb = zoomer(vb, boiteInitiale, mesurerCadre(), ev.clientX, ev.clientY, ev.deltaY)
  appliquer()
}

let px = 0
let py = 0
let enfonce = false
let bouge = false
let pointeur: number | null = null

function surPointeurBas(ev: PointerEvent): void {
  enfonce = true
  bouge = false
  px = ev.clientX
  py = ev.clientY
  pointeur = ev.pointerId
}

function surPointeurDeplace(ev: PointerEvent): void {
  if (!enfonce || pilote.value === null) return
  if (!bouge) {
    // Trois pixels de tolérance : sans ce seuil, un clic un peu tremblant sur
    // une fiche est compté comme un déplacement et n'ouvre jamais le panneau.
    if (Math.abs(ev.clientX - px) + Math.abs(ev.clientY - py) <= 3) return
    bouge = true
    cadreRef.value?.classList.add('deplacement')
    svgRef.value?.classList.add('deplacement')
    if (pointeur !== null) {
      try {
        cadreRef.value?.setPointerCapture(pointeur)
      } catch {
        // Capture refusée (pointeur déjà relâché) : le déplacement fonctionne
        // quand même, il s'arrête simplement en sortant du cadre.
      }
    }
  }
  vb = deplacer(vb, mesurerCadre(), ev.clientX - px, ev.clientY - py)
  px = ev.clientX
  py = ev.clientY
  appliquer()
}

function surPointeurFin(): void {
  enfonce = false
  cadreRef.value?.classList.remove('deplacement')
  svgRef.value?.classList.remove('deplacement')
  if (pointeur !== null) {
    try {
      cadreRef.value?.releasePointerCapture(pointeur)
    } catch {
      /* déjà relâché */
    }
    pointeur = null
  }
}

function surClic(ev: MouseEvent): void {
  // Un déplacement se termine par un `click` : sans ce garde-fou, déplacer
  // l'arbre ouvrirait la fiche qui se trouve sous le doigt à l'arrivée.
  if (bouge || pilote.value === null) return
  const noeud = pilote.value.noeudSousLeCurseur(ev.target)
  if (noeud === null) emit('fond')
  else emit('selection', noeud)
}

/* ------------------------------------------------------------------ */
/* Interface impérative offerte à la vue                               */
/* ------------------------------------------------------------------ */

function recadrer(): void {
  vb = { ...boiteInitiale }
  appliquer()
}

function centrer(id: string): void {
  const boite = pilote.value?.boiteDe(id)
  if (boite === undefined || boite === null) return
  vb = centrerSur(boite, boiteInitiale, mesurerCadre())
  appliquer()
}

function cadrerSur(ids: Iterable<string>): void {
  const boites = pilote.value?.boitesDe(ids) ?? []
  const nouvelle = cadrer(boites, mesurerCadre())
  if (nouvelle === null) return
  vb = nouvelle
  appliquer()
}

/**
 * Le pilote est exposé par une **fonction** et non par la `ref` elle-même :
 * `defineExpose` déballe les `ref` à l'exécution mais pas toujours au typage,
 * et un `.value` de trop sur un `null` ne se voit qu'au clic.
 */
defineExpose({
  piloteCourant: (): PiloteCanevas | null => pilote.value,
  recadrer,
  centrer,
  cadrerSur,
})

/* ------------------------------------------------------------------ */
/* Cycle de vie                                                        */
/* ------------------------------------------------------------------ */

onMounted(() => {
  // Enregistré à la main, et non par `@wheel.prevent` : il faut
  // `passive: false` pour avoir le droit d'empêcher le défilement de la page.
  cadreRef.value?.addEventListener('wheel', surMolette, { passive: false })
  construire()
})

onBeforeUnmount(() => {
  cadreRef.value?.removeEventListener('wheel', surMolette)
  pilote.value?.detruire()
  pilote.value = null
})

// Un nouveau graphe ⇒ reconstruction complète. Le `304` n'arrive jamais ici :
// la vue ne change la prop que sur `statut === 'nouveau'`.
watch(
  () => graphe,
  () => {
    construire()
  },
)
</script>

<template>
  <div
    ref="cadreRef"
    class="relative h-full w-full overflow-hidden"
    @pointerdown="surPointeurBas"
    @pointermove="surPointeurDeplace"
    @pointerup="surPointeurFin"
    @pointercancel="surPointeurFin"
    @click="surClic"
  >
    <!--
      `preserveAspectRatio="xMidYMid meet"` : le contenu est centré et tient
      entier dans le cadre. Le `viewBox` est posé par le pilote, jamais ici —
      et il n'y a **aucun** `transform` sur cet élément (règle n° 1).
    -->
    <svg
      ref="svgRef"
      class="canevas"
      preserveAspectRatio="xMidYMid meet"
      role="img"
      aria-label="Arbre généalogique"
    ></svg>
  </div>
</template>
