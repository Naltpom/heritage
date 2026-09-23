<script setup lang="ts">
/**
 * « Ma photo » : importer **ou prendre** une photo, puis la **cadrer** sous un
 * cercle avant de l'envoyer.
 *
 * Le portrait s'affiche partout en rond (carte de l'arbre, en-tête, fiche).
 * Sans cadrage, c'est le centre de la photo d'origine qui finit dans le rond —
 * un front coupé, une épaule, un décor. Ici, on place sa tête dans le cercle,
 * et c'est **exactement ce carré** qui part au serveur (JPEG, 1024 px au plus).
 *
 * Deux sources :
 *  - **importer** un fichier ;
 *  - **prendre** une photo : la caméra dans la page (`getUserMedia`) quand le
 *    navigateur la permet, sinon le sélecteur natif en mode appareil photo
 *    (`capture="user"`), qui ouvre la caméra frontale sur un téléphone.
 *
 * L'envoi passe par `POST /api/medias/portrait-du-compte`, comme avant : le
 * portrait de son propre compte est direct, sans circuit de validation.
 */
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { analyserErreur, televerserPortraitDuCompte } from '@/lib/api'
import { rafraichirSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import { borner, cadrageInitial, coteDeSortie, zoneSource, zoomerAutour, ZOOM_MAX, type Cadrage } from './cadrage'

const { ouvert } = defineProps<{ ouvert: boolean }>()
const emit = defineEmits<{ fermer: []; depose: [] }>()

const dialogue = ref<HTMLDialogElement | null>(null)
const etape = ref<'choix' | 'camera' | 'cadrage'>('choix')
const erreur = ref<string | null>(null)
const envoi = ref(false)

watch(
  () => ouvert,
  async (o) => {
    await nextTick()
    const d = dialogue.value
    if (!d) return
    if (o && !d.open) {
      reinitialiser()
      d.showModal()
    } else if (!o && d.open) d.close()
  },
  { immediate: true },
)

function reinitialiser(): void {
  arreterCamera()
  libererImage()
  etape.value = 'choix'
  erreur.value = null
  envoi.value = false
  repliNatif.value = false
}

function fermer(): void {
  arreterCamera()
  libererImage()
  emit('fermer')
}

/* ------------------------------------------------------------------ */
/* L'image chargée                                                     */
/* ------------------------------------------------------------------ */

const image = shallowRef<HTMLImageElement | null>(null)
let urlObjet: string | null = null
const dims = computed(() => (image.value ? { largeur: image.value.naturalWidth, hauteur: image.value.naturalHeight } : null))

function libererImage(): void {
  if (urlObjet) URL.revokeObjectURL(urlObjet)
  urlObjet = null
  image.value = null
}

async function chargerImage(source: Blob): Promise<void> {
  erreur.value = null
  libererImage()
  const url = URL.createObjectURL(source)
  const img = new Image()
  img.src = url
  try {
    await img.decode()
  } catch {
    URL.revokeObjectURL(url)
    erreur.value = "Cette image ne peut pas être lue ici. Essayez une photo JPEG ou PNG."
    return
  }
  urlObjet = url
  image.value = img
  mesurerScene()
  cadrage.value = cadrageInitial(dims.value!, cote.value)
  etape.value = 'cadrage'
}

function surFichier(e: Event): void {
  const entree = e.target as HTMLInputElement
  const fichier = entree.files?.[0]
  entree.value = ''
  if (fichier) void chargerImage(fichier)
}

/* ------------------------------------------------------------------ */
/* La caméra                                                           */
/* ------------------------------------------------------------------ */

const video = ref<HTMLVideoElement | null>(null)
const selecteurAppareil = ref<HTMLInputElement | null>(null)
let flux: MediaStream | null = null
const faceAvant = ref(true)
/** La caméra dans la page a été refusée : on propose l'appareil photo natif. */
const repliNatif = ref(false)

function arreterCamera(): void {
  flux?.getTracks().forEach((t) => t.stop())
  flux = null
}

async function ouvrirCamera(): Promise<void> {
  erreur.value = null
  if (!navigator.mediaDevices?.getUserMedia) {
    // Pas de caméra dans la page : le sélecteur natif, en mode appareil photo.
    selecteurAppareil.value?.click()
    return
  }
  arreterCamera()
  try {
    flux = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: faceAvant.value ? 'user' : 'environment', width: { ideal: 1280 }, height: { ideal: 1280 } },
      audio: false,
    })
  } catch {
    // Autorisation refusée, caméra occupée, ou navigateur qui ne la prête pas à
    // une page : l'appareil photo natif du téléphone ne dépend pas de tout cela.
    // Le bouton qui l'ouvre est un vrai geste du lecteur — un `click()` lancé
    // après un `await` est ignoré par Safari.
    erreur.value = "La caméra n'a pas pu s'ouvrir dans la page. Utilisez l'appareil photo du téléphone, ou importez une photo."
    repliNatif.value = true
    etape.value = 'choix'
    return
  }
  etape.value = 'camera'
  await nextTick()
  if (video.value) {
    video.value.srcObject = flux
    await video.value.play().catch(() => {})
  }
}

async function retournerCamera(): Promise<void> {
  faceAvant.value = !faceAvant.value
  await ouvrirCamera()
}

function declencher(): void {
  const v = video.value
  if (!v || !v.videoWidth) return
  const toile = document.createElement('canvas')
  toile.width = v.videoWidth
  toile.height = v.videoHeight
  const ctx = toile.getContext('2d')!
  // La caméra frontale est montrée en miroir, comme dans un miroir : la photo
  // prise garde ce que l'on a vu.
  if (faceAvant.value) {
    ctx.translate(toile.width, 0)
    ctx.scale(-1, 1)
  }
  ctx.drawImage(v, 0, 0)
  arreterCamera()
  toile.toBlob((blob) => {
    if (blob) void chargerImage(blob)
  }, 'image/jpeg', 0.92)
}

/* ------------------------------------------------------------------ */
/* Le cadrage                                                          */
/* ------------------------------------------------------------------ */

const scene = ref<HTMLElement | null>(null)
const cote = ref(280)
const cadrage = ref<Cadrage>({ zoom: 1, x: 0, y: 0 })

function mesurerScene(): void {
  cote.value = Math.max(200, Math.min(320, window.innerWidth - 64))
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

/* ------------------------------------------------------------------ */
/* L'envoi                                                             */
/* ------------------------------------------------------------------ */

async function enregistrer(): Promise<void> {
  const img = image.value
  const d = dims.value
  if (!img || !d || envoi.value) return
  envoi.value = true
  erreur.value = null
  try {
    const zone = zoneSource(cadrage.value, d, cote.value)
    const n = coteDeSortie(zone.cote)
    const toile = document.createElement('canvas')
    toile.width = n
    toile.height = n
    const ctx = toile.getContext('2d')!
    ctx.imageSmoothingQuality = 'high'
    ctx.drawImage(img, zone.x, zone.y, zone.cote, zone.cote, 0, 0, n, n)
    const blob = await new Promise<Blob | null>((ok) => toile.toBlob(ok, 'image/jpeg', 0.9))
    if (!blob) throw new Error("La photo n'a pas pu être préparée.")
    await televerserPortraitDuCompte(new File([blob], 'portrait.jpg', { type: 'image/jpeg' }))
    // La session porte le portrait courant : c'est elle qu'on relit.
    await rafraichirSession()
    emit('depose')
    fermer()
  } catch (e) {
    erreur.value = e instanceof Error && !('status' in e) ? e.message : analyserErreur(e).message
  } finally {
    envoi.value = false
  }
}

onBeforeUnmount(() => {
  arreterCamera()
  libererImage()
})
</script>

<template>
  <dialog
    ref="dialogue"
    class="m-auto w-[min(26rem,calc(100vw-1.5rem))] rounded-panneau border border-ligne bg-panneau p-0 text-texte shadow-panneau backdrop:bg-black/60"
    aria-labelledby="titre-choix-portrait"
    @close="fermer"
  >
    <div v-if="ouvert" class="flex flex-col gap-4 p-4">
      <div class="flex items-center justify-between">
        <h2 id="titre-choix-portrait" class="text-base font-semibold">Ma photo</h2>
        <button type="button" class="h-9 w-9 text-lg text-attenue hover:text-texte" aria-label="Fermer" @click="fermer">×</button>
      </div>

      <!-- 1. Choisir la source -->
      <template v-if="etape === 'choix'">
        <p class="m-0 text-sm text-attenue">
          Elle apparaîtra en rond sur votre carte dans l'arbre. Vous pourrez la cadrer pour que votre visage soit bien au centre.
        </p>
        <div class="grid grid-cols-2 gap-3">
          <label
            class="flex min-h-24 cursor-pointer flex-col items-center justify-center gap-2 rounded-panneau border border-ligne p-3 text-center text-sm font-semibold hover:border-accent focus-within:border-accent"
          >
            <svg width="28" height="28" viewBox="0 0 24 24" aria-hidden="true" class="text-accent">
              <path d="M12 16V4m0 0l-4 4m4-4l4 4M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Importer une photo
            <input type="file" accept="image/*" class="sr-only" @change="surFichier" />
          </label>
          <button
            type="button"
            class="flex min-h-24 flex-col items-center justify-center gap-2 rounded-panneau border border-ligne p-3 text-sm font-semibold hover:border-accent"
            @click="ouvrirCamera"
          >
            <svg width="28" height="28" viewBox="0 0 24 24" aria-hidden="true" class="text-accent">
              <path d="M4 8h3l2-3h6l2 3h3v11H4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
              <circle cx="12" cy="13" r="3.5" fill="none" stroke="currentColor" stroke-width="1.8" />
            </svg>
            Prendre une photo
          </button>
          <!-- Repli sans caméra dans la page : l'appareil photo natif du téléphone. -->
          <label
            v-if="repliNatif"
            class="col-span-2 flex min-h-11 cursor-pointer items-center justify-center rounded-panneau border border-accent px-3 text-sm font-semibold text-accent"
          >
            Ouvrir l'appareil photo
            <input ref="selecteurAppareil" type="file" accept="image/*" capture="user" class="sr-only" @change="surFichier" />
          </label>
          <input v-else ref="selecteurAppareil" type="file" accept="image/*" capture="user" class="hidden" @change="surFichier" />
        </div>
      </template>

      <!-- 2. La caméra -->
      <template v-else-if="etape === 'camera'">
        <div class="relative mx-auto aspect-square w-full max-w-[320px] overflow-hidden rounded-panneau bg-black">
          <video
            ref="video"
            autoplay
            playsinline
            muted
            class="h-full w-full object-cover"
            :class="faceAvant ? '-scale-x-100' : ''"
          />
          <div class="pointer-events-none absolute inset-[6%] rounded-full shadow-[0_0_0_9999px_rgba(0,0,0,.45)] ring-2 ring-white/80" />
        </div>
        <p class="m-0 text-center text-xs text-attenue">Placez votre visage dans le cercle.</p>
        <div class="flex flex-wrap justify-center gap-2">
          <BoutonBase variante="discret" @click="etape = 'choix'; arreterCamera()">Annuler</BoutonBase>
          <BoutonBase variante="secondaire" @click="retournerCamera">Retourner</BoutonBase>
          <BoutonBase @click="declencher">Prendre la photo</BoutonBase>
        </div>
      </template>

      <!-- 3. Le cadrage -->
      <template v-else-if="etape === 'cadrage' && image">
        <div
          ref="scene"
          class="relative mx-auto cursor-grab touch-none overflow-hidden rounded-panneau bg-black select-none active:cursor-grabbing"
          :style="{ width: `${cote}px`, height: `${cote}px` }"
          tabindex="0"
          role="application"
          aria-label="Cadrage : glissez pour déplacer, molette ou pincement pour zoomer, flèches et + / − au clavier"
          @pointerdown="surBas"
          @pointermove="surDeplace"
          @pointerup="surHaut"
          @pointercancel="surHaut"
          @wheel.prevent="surMolette"
          @keydown="surToucheScene"
        >
          <img
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
        <p class="m-0 text-center text-xs text-attenue">Glissez la photo pour placer votre visage dans le cercle.</p>
        <div class="flex flex-wrap justify-end gap-2">
          <BoutonBase variante="discret" @click="etape = 'choix'">Changer de photo</BoutonBase>
          <BoutonBase :chargement="envoi" @click="enregistrer">Enregistrer</BoutonBase>
        </div>
      </template>

      <p v-if="erreur" class="m-0 text-sm text-alerte" role="alert">{{ erreur }}</p>
    </div>
  </dialog>
</template>
