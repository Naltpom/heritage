<script setup lang="ts">
/**
 * Le **recadrage sous un cercle** : l'image se déplace et se zoome sous un rond
 * fixe, et c'est exactement le carré qui contient ce rond qui part au serveur.
 *
 * Extrait de « Ma photo » (`ChoixPortrait.vue`) pour servir aussi aux portraits
 * repérés par l'assistant ; les gestes sont les mêmes partout :
 *  - **glisser** (souris, doigt) pour déplacer ;
 *  - **pincer** (deux doigts), **molette** ou le **curseur** pour zoomer ;
 *  - au clavier, les **flèches** et **+ / −** sur la scène.
 *
 * Il prend une image — une `<img>` déjà décodée, ou un fichier qu'il décode
 * lui-même — et, s'il est fourni, un **cadre initial** (millièmes de l'image,
 * la forme des boîtes du modèle) : la scène s'ouvre posée dessus
 * (`cadrageDepuisCadre`). Sans cadre, l'image est centrée, comme avant.
 *
 * Il ne décide pas de l'envoi : le parent appelle `exporter()` (le `File` JPEG
 * carré, 1024 px au plus) ou `cadreActuel()` (le cadre, pour rouvrir au même
 * endroit). Deux nœuds racines — la scène et le curseur — pour se glisser dans
 * la colonne du parent sans rien changer à ses espacements.
 *
 * Toute la géométrie est pure, dans `cadrage.ts`.
 */
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import {
  borner,
  cadrageDepuisCadre,
  cadrageInitial,
  cadreDeZone,
  zoneSource,
  zoomerAutour,
  ZOOM_MAX,
  type Cadrage,
  type CadreVisage,
} from './cadrage'
import { decoderImage, decouperCarre } from './decoupe'

const {
  source,
  cadre = null,
  libelle = 'Cadrage : glissez pour déplacer, molette ou pincement pour zoomer, flèches et + / − au clavier',
} = defineProps<{
  /** L'image à cadrer : décodée par le parent, ou un fichier à décoder ici. */
  source: HTMLImageElement | Blob
  /** Le cadre sur lequel ouvrir, en millièmes de l'image. */
  cadre?: CadreVisage | null
  /** Le nom accessible de la scène. */
  libelle?: string
}>()

const emit = defineEmits<{
  /** Le fichier reçu n'a pas pu être décodé (jamais émis pour une `<img>`). */
  illisible: []
}>()

/* --- L'image -------------------------------------------------------- */

const image = shallowRef<HTMLImageElement | null>(null)
/** L'URL d'objet créée ici, pour un fichier : à révoquer. Jamais celle d'une
 *  `<img>` reçue, qui appartient au parent. */
let urlPropre: string | null = null
const dims = computed(() =>
  image.value ? { largeur: image.value.naturalWidth, hauteur: image.value.naturalHeight } : null,
)

function libererPropre(): void {
  if (urlPropre) URL.revokeObjectURL(urlPropre)
  urlPropre = null
}

/** Un fichier se décode de façon asynchrone : si la source change entre-temps,
 *  l'ancien décodage ne doit pas écraser le nouveau. */
let jeton = 0
watch(
  () => source,
  async (s) => {
    const moi = ++jeton
    libererPropre()
    if (typeof HTMLImageElement !== 'undefined' && s instanceof HTMLImageElement) {
      image.value = s
    } else {
      image.value = null
      const img = await decoderImage(s as Blob)
      if (moi !== jeton) {
        if (img) URL.revokeObjectURL(img.src)
        return
      }
      if (!img) {
        emit('illisible')
        return
      }
      urlPropre = img.src
      image.value = img
    }
    placer()
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  jeton++
  libererPropre()
})

/* --- Le cadrage ----------------------------------------------------- */

const scene = ref<HTMLElement | null>(null)
const cote = ref(280)
const cadrage = ref<Cadrage>({ zoom: 1, x: 0, y: 0 })

function mesurerScene(): void {
  cote.value = Math.max(200, Math.min(320, window.innerWidth - 64))
}

/** Mesure la scène et pose le cadrage de départ : sur le cadre s'il y en a un. */
function placer(): void {
  const d = dims.value
  if (!d) return
  mesurerScene()
  cadrage.value = cadre ? cadrageDepuisCadre(cadre, d, cote.value) : cadrageInitial(d, cote.value)
}

const styleImage = computed(() => {
  const d = dims.value
  if (!d) return {}
  const k = (cote.value / Math.min(d.largeur, d.hauteur)) * cadrage.value.zoom
  return {
    width: `${d.largeur * k}px`,
    height: `${d.hauteur * k}px`,
    transform: `translate(${cadrage.value.x}px, ${cadrage.value.y}px)`,
  }
})

const pointeurs = new Map<number, { x: number; y: number }>()
let glisser: { x: number; y: number; cx: number; cy: number } | null = null
let pincement: number | null = null

function local(e: PointerEvent | WheelEvent): { x: number; y: number } {
  const r = scene.value!.getBoundingClientRect()
  return { x: e.clientX - r.left, y: e.clientY - r.top }
}

function surBas(e: PointerEvent): void {
  scene.value?.setPointerCapture(e.pointerId)
  pointeurs.set(e.pointerId, local(e))
  if (pointeurs.size === 1) glisser = { x: e.clientX, y: e.clientY, cx: cadrage.value.x, cy: cadrage.value.y }
  if (pointeurs.size === 2) {
    const [a, b] = [...pointeurs.values()] as [{ x: number; y: number }, { x: number; y: number }]
    pincement = Math.hypot(a.x - b.x, a.y - b.y)
    glisser = null
  }
}

function surDeplace(e: PointerEvent): void {
  const d = dims.value
  if (!d || !pointeurs.has(e.pointerId)) return
  pointeurs.set(e.pointerId, local(e))
  if (pointeurs.size === 2 && pincement) {
    const [a, b] = [...pointeurs.values()] as [{ x: number; y: number }, { x: number; y: number }]
    const dist = Math.hypot(a.x - b.x, a.y - b.y)
    cadrage.value = zoomerAutour(cadrage.value, d, cote.value, cadrage.value.zoom * (dist / pincement), (a.x + b.x) / 2, (a.y + b.y) / 2)
    pincement = dist
    return
  }
  if (!glisser) return
  cadrage.value = borner(
    { zoom: cadrage.value.zoom, x: glisser.cx + e.clientX - glisser.x, y: glisser.cy + e.clientY - glisser.y },
    d,
    cote.value,
  )
}

function surHaut(e: PointerEvent): void {
  pointeurs.delete(e.pointerId)
  if (pointeurs.size < 2) pincement = null
  if (pointeurs.size === 0) glisser = null
}

function surMolette(e: WheelEvent): void {
  const d = dims.value
  if (!d) return
  const { x, y } = local(e)
  cadrage.value = zoomerAutour(cadrage.value, d, cote.value, cadrage.value.zoom * Math.exp(-e.deltaY * 0.002), x, y)
}

function surCurseurZoom(e: Event): void {
  const d = dims.value
  if (!d) return
  const z = Number((e.target as HTMLInputElement).value)
  cadrage.value = zoomerAutour(cadrage.value, d, cote.value, z, cote.value / 2, cote.value / 2)
}

function surToucheScene(e: KeyboardEvent): void {
  const d = dims.value
  if (!d) return
  const pas = 10
  const c = cadrage.value
  const moves: Record<string, [number, number]> = {
    ArrowLeft: [pas, 0],
    ArrowRight: [-pas, 0],
    ArrowUp: [0, pas],
    ArrowDown: [0, -pas],
  }
  if (moves[e.key]) {
    e.preventDefault()
    const [dx, dy] = moves[e.key]!
    cadrage.value = borner({ ...c, x: c.x + dx, y: c.y + dy }, d, cote.value)
  } else if (e.key === '+' || e.key === '=') {
    cadrage.value = zoomerAutour(c, d, cote.value, c.zoom * 1.15, cote.value / 2, cote.value / 2)
  } else if (e.key === '-') {
    cadrage.value = zoomerAutour(c, d, cote.value, c.zoom / 1.15, cote.value / 2, cote.value / 2)
  }
}

/* --- Ce que le parent en tire ---------------------------------------- */

/** Le carré sous le cercle, en JPEG (1024 px au plus). Rejette tant que
 *  l'image n'est pas prête, ou si la toile échoue. */
async function exporter(nom = 'portrait.jpg'): Promise<File> {
  const img = image.value
  const d = dims.value
  if (!img || !d) throw new Error("La photo n'a pas pu être préparée.")
  return decouperCarre(img, zoneSource(cadrage.value, d, cote.value), nom)
}

/** Le cadre actuel, en millièmes de l'image — `null` tant qu'elle n'est pas prête. */
function cadreActuel(): CadreVisage | null {
  const d = dims.value
  return d ? cadreDeZone(zoneSource(cadrage.value, d, cote.value), d) : null
}

defineExpose({ exporter, cadreActuel })
</script>

<template>
  <div
    ref="scene"
    class="relative mx-auto cursor-grab touch-none overflow-hidden rounded-panneau bg-black select-none active:cursor-grabbing"
    :style="{ width: `${cote}px`, height: `${cote}px` }"
    tabindex="0"
    role="application"
    :aria-label="libelle"
    @pointerdown="surBas"
    @pointermove="surDeplace"
    @pointerup="surHaut"
    @pointercancel="surHaut"
    @wheel.prevent="surMolette"
    @keydown="surToucheScene"
  >
    <img
      v-if="image"
      :src="image.src"
      alt=""
      draggable="false"
      class="pointer-events-none absolute top-0 left-0 max-w-none origin-top-left"
      :style="styleImage"
    />
    <!-- Le cercle : ce qui sera visible sur la carte de l'arbre. -->
    <div class="pointer-events-none absolute inset-0 rounded-full shadow-[0_0_0_9999px_rgba(0,0,0,.55)] ring-2 ring-white/85" />
  </div>
  <label class="flex items-center gap-3 text-sm">
    <span class="text-attenue" aria-hidden="true">−</span>
    <input
      type="range"
      min="1"
      :max="ZOOM_MAX"
      step="0.01"
      :value="cadrage.zoom"
      class="flex-1 accent-[var(--h-accent)]"
      aria-label="Zoom"
      @input="surCurseurZoom"
    />
    <span class="text-attenue" aria-hidden="true">+</span>
  </label>
</template>
