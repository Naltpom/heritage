<script setup lang="ts">
/**
 * Le canevas de la chronologie : le `<svg>` et ses gestes.
 *
 * Coquille, comme `CanevasArbre.vue` : il monte un `<svg>` vide, le confie à
 * `construireCanevasChronologie()` et n'y touche plus que par l'interface
 * impérative que le pilote lui rend. Rien de ce qui est dans le canevas n'est
 * réactif.
 *
 * **Le zoom passe par le `viewBox`.** `vb` est une variable ordinaire, pas une
 * `ref` : elle change à chaque image pendant un déplacement, et la faire passer
 * par le système réactif déclencherait un cycle de rendu par mouvement de
 * souris pour écrire un attribut qu'on sait écrire soi-même. Les fonctions de
 * zoom et de déplacement sont celles de l'arbre — elles ne connaissent qu'un
 * `viewBox` et un cadre, il n'y avait aucune raison d'en écrire une seconde
 * paire.
 *
 * **Le cadrage du lecteur survit à un changement de réglage d'affichage.**
 * Basculer l'incertitude reconstruit le dessin sans en changer la géométrie :
 * remettre le `viewBox` à zéro ferait perdre l'endroit qu'on regardait, pour
 * rien. Changer d'orientation, en revanche, change la boîte : on recadre.
 */
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import type { BarreChronologie, OrientationChronologie, VueChronologie } from '@/lib/api'
import { deplacer, zoomer, type Boite, type CadreEcran } from '@/components/arbre/geometrie'
import { cadrageInitial, memeBoite } from './geometrie'
import { construireCanevasChronologie, type PiloteChronologie } from './canevas'

const { vue, orientation, montrerIncertitude, selection } = defineProps<{
  /** `null` tant que le premier chargement n'a pas abouti. */
  vue: VueChronologie | null
  orientation: OrientationChronologie
  montrerIncertitude: boolean
  selection: number | null
}>()

const emit = defineEmits<{
  /** Une barre a été cliquée, ou le fond (`null`). Jamais pendant un
   *  déplacement. */
  barre: [barre: BarreChronologie | null]
}>()

const cadreRef = ref<HTMLDivElement | null>(null)
const svgRef = ref<SVGSVGElement | null>(null)

const pilote = shallowRef<PiloteChronologie | null>(null)

/** La boîte du contenu, telle que le pilote la rend. Jamais recalculée ici. */
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

  const ancienne = pilote.value?.boite ?? null
  pilote.value?.detruire()

  if (vue === null) {
    pilote.value = null
    return
  }

  pilote.value = construireCanevasChronologie(svg, vue, {
    orientation,
    montrerIncertitude,
    // L'année courante est lue **au rendu** : c'est elle qui arrête les barres
    // ouvertes, et l'API n'en envoie aucune (§ 9.1).
    anneeCourante: new Date().getFullYear(),
  })
  if (pilote.value === null) return

  boiteInitiale = { ...pilote.value.boite }
  if (memeBoite(ancienne, pilote.value.boite)) appliquer()
  else recadrer()
  pilote.value.selectionner(selection)
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
    // une barre est compté comme un déplacement et ne sélectionne rien.
    if (Math.abs(ev.clientX - px) + Math.abs(ev.clientY - py) <= 3) return
    bouge = true
    cadreRef.value?.classList.add('deplacement')
    svgRef.value?.classList.add('deplacement')
    if (pointeur !== null) {
      try {
        cadreRef.value?.setPointerCapture(pointeur)
      } catch {
        // Capture refusée (pointeur déjà relâché) : le déplacement fonctionne
        // quand même, il s'arrête en sortant du cadre.
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
  // Un déplacement se termine par un `click` : sans ce garde-fou, déplacer la
  // chronologie sélectionnerait la barre qui se trouve sous le doigt à
  // l'arrivée.
  if (bouge || pilote.value === null) return
  emit('barre', pilote.value.barreSousLeCurseur(ev.target))
}

/* ------------------------------------------------------------------ */
/* Interface impérative offerte à la vue                               */
/* ------------------------------------------------------------------ */

/**
 * Le cadrage de départ — **pas** la boîte entière du contenu.
 *
 * `boiteInitiale` reste la boîte du contenu parce que c'est elle qui fixe les
 * butées de zoom : calées sur la vue de départ, elles interdiraient de dézoomer
 * assez pour revoir les quatre siècles.
 */
let attentesDeCadre = 0

function recadrer(): void {
  const cadre = mesurerCadre()
  if (cadre.largeur < 2 || cadre.hauteur < 2) {
    // **Le cadre n'est pas encore posé.** À la première réponse, le canevas est
    // encore sous le `v-show` fermé de la vue au moment où l'on mesure :
    // `getBoundingClientRect()` rend 0 × 0, le rapport tombe à 1, et la
    // chronologie s'affiche dans un carré au milieu d'un écran large — à moitié
    // grande, sans que rien d'autre ne soit faux.
    //
    // `flush: 'post'` sur la surveillance ne suffit pas : l'ordre entre une
    // fonction différée de surveillance et le crochet `updated` de `v-show`
    // n'est pas garanti. On mesure donc à l'image suivante, et on renonce au
    // bout de vingt essais plutôt que de tourner en boucle si le canevas reste
    // caché (état vide, onglet en arrière-plan).
    if (attentesDeCadre >= 20) return
    attentesDeCadre += 1
    requestAnimationFrame(() => recadrer())
    return
  }
  attentesDeCadre = 0
  vb = cadrageInitial(boiteInitiale, cadre, orientation)
  appliquer()
}

defineExpose({
  recadrer,
  /** Exposé par une **fonction** et non par la `ref` : `defineExpose` déballe
   *  les `ref` à l'exécution mais pas toujours au typage. */
  piloteCourant: (): PiloteChronologie | null => pilote.value,
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

/**
 * `flush: 'post'` — et ce n'est pas un détail.
 *
 * Le cadrage de départ **mesure le cadre**. Par défaut, une surveillance
 * s'exécute *avant* la mise à jour du DOM : à la première réponse, le canevas
 * est encore sous le `v-show` fermé de la vue, `getBoundingClientRect()` rend
 * 0 × 0, et la vue se cale sur un rapport de 1 — la chronologie s'affiche alors
 * dans un carré au milieu de l'écran, à moitié grande, sans que rien ne soit
 * faux ailleurs. En différant après le rendu, on mesure le cadre réel.
 */
watch(
  () => [vue, orientation, montrerIncertitude],
  () => {
    construire()
  },
  { flush: 'post' },
)

// La sélection ne reconstruit rien : elle pose deux classes.
watch(
  () => selection,
  (id) => {
    pilote.value?.selectionner(id)
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
      class="canevas chrono"
      preserveAspectRatio="xMidYMid meet"
      role="img"
      aria-label="Chronologie des personnes de l’arbre"
    ></svg>
  </div>
</template>
