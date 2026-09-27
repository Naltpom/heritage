<script setup lang="ts">
/**
 * Réécouter un enregistrement avant l'analyse : lecture / pause et le temps.
 *
 * Pas le lecteur natif (`<audio controls>`) : il affiche la durée **annoncée par
 * le fichier**, et ce que `MediaRecorder` produit n'en annonce souvent aucune —
 * Chrome Android montrait « 0:00 / 0:00 » pour cinq secondes enregistrées. Ici la
 * durée vient du fichier quand elle est vraisemblable, sinon du chrono de
 * l'enregistrement (`dureeMesuree`). Une lecture impossible est dite : le
 * document part quand même à l'analyse.
 */
import { onBeforeUnmount, ref } from 'vue'
import { dureeAffichable, dureeLisible } from './assistant'

const { src, mesuree = null } = defineProps<{
  src: string
  /** La durée mesurée à l'enregistrement, en secondes ; `null` pour un fichier importé. */
  mesuree?: number | null
}>()

const lecteur = ref<HTMLAudioElement | null>(null)
const joue = ref(false)
const position = ref(0)
const duree = ref<number | null>(mesuree)
const erreur = ref(false)

function surMetadonnees(): void {
  duree.value = dureeAffichable(lecteur.value?.duration ?? NaN, mesuree)
}

async function basculer(): Promise<void> {
  const audio = lecteur.value
  if (!audio) return
  if (!audio.paused) {
    audio.pause()
    return
  }
  try {
    erreur.value = false
    await audio.play()
  } catch {
    erreur.value = true
  }
}

function surFin(): void {
  joue.value = false
  position.value = 0
}

onBeforeUnmount(() => lecteur.value?.pause())
</script>

<template>
  <div class="flex min-w-0 flex-1 flex-col gap-0.5">
    <div class="flex min-w-0 items-center gap-2 rounded-pastille border border-ligne bg-panneau px-1.5 py-1">
      <button
        type="button"
        class="flex size-9 flex-none items-center justify-center rounded-full hover:bg-accent-doux max-md:size-11"
        :aria-label="joue ? 'Mettre en pause' : 'Écouter'"
        @click="basculer"
      >
        <svg v-if="joue" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M7 5h3v14H7zM14 5h3v14h-3z" fill="currentColor" />
        </svg>
        <svg v-else width="16" height="16" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M8 5v14l11-7z" fill="currentColor" />
        </svg>
      </button>
      <div class="h-1 min-w-0 flex-1 overflow-hidden rounded-full bg-ligne" aria-hidden="true">
        <div
          class="h-full bg-accent"
          :style="{ width: duree ? `${Math.min(100, (position / duree) * 100)}%` : '0%' }"
        />
      </div>
      <span class="flex-none pr-2 text-xs text-attenue tabular-nums">
        {{ dureeLisible(position) }}<template v-if="duree !== null"> / {{ dureeLisible(duree) }}</template>
      </span>
    </div>
    <span v-if="erreur" class="text-xs text-alerte" role="alert">
      Ce navigateur ne sait pas relire ce fichier. Il sera tout de même envoyé à l’analyse.
    </span>
    <audio
      ref="lecteur"
      :src="src"
      preload="metadata"
      class="hidden"
      @loadedmetadata="surMetadonnees"
      @durationchange="surMetadonnees"
      @timeupdate="position = lecteur?.currentTime ?? 0"
      @play="joue = true"
      @pause="joue = false"
      @ended="surFin"
      @error="erreur = true"
    />
  </div>
</template>
