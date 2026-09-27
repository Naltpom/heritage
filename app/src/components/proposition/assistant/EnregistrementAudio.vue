<script setup lang="ts">
/**
 * **Enregistrer** : raconter à voix haute, et joindre l'enregistrement comme un
 * document.
 *
 * L'audio est une **source**, pas une saisie : rien ne s'écrit dans le champ de
 * texte. À l'arrêt, le composant émet un fichier (`enregistrement-1.m4a`…) que
 * la vue range avec les photos et les PDF ; l'assistant l'écoute à l'analyse,
 * comme il lit un acte. On peut ainsi enregistrer une grand-mère qui raconte,
 * sans avoir à tout retranscrire.
 *
 * - C'est `MediaRecorder`, dans le navigateur : l'audio ne part qu'avec
 *   l'analyse, dans la même requête que les autres documents.
 * - Le micro doit être permis au site (`Permissions-Policy: microphone=(self)`,
 *   `app/nginx.conf`) ; le navigateur demande ensuite l'autorisation.
 * - Le format est le premier que le navigateur sait produire
 *   (`choisirFormatEnregistrement`), à 32 kbit/s : la voix n'en demande pas plus,
 *   et un document est borné à 10 Mo. Au bout de `DUREE_MAX_ENREGISTREMENT`,
 *   l'enregistrement s'arrête seul.
 * - Navigateur sans `MediaRecorder` ni micro : le bouton n'apparaît pas. On le
 *   décide au montage, jamais au rendu serveur des tests.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import {
  DEBIT_ENREGISTREMENT,
  DUREE_MAX_ENREGISTREMENT,
  choisirFormatEnregistrement,
  dureeLisible,
  extensionDEnregistrement,
  nomDEnregistrement,
  retenirDuree,
} from './assistant'

const { desactive = false } = defineProps<{ desactive?: boolean }>()
const emit = defineEmits<{ enregistrement: [fichier: File] }>()

const disponible = ref(false)
const enregistre = ref(false)
const secondes = ref(0)
const erreur = ref<string | null>(null)

let enregistreur: MediaRecorder | null = null
let flux: MediaStream | null = null
let minuterie: ReturnType<typeof setInterval> | null = null
let rang = 0
let debut = 0
/** Démonté en cours d'enregistrement : on coupe le micro sans rien émettre. */
let abandonne = false

onMounted(() => {
  disponible.value =
    typeof MediaRecorder !== 'undefined' && typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia
})

function liberer(): void {
  if (minuterie !== null) clearInterval(minuterie)
  minuterie = null
  for (const piste of flux?.getTracks() ?? []) piste.stop()
  flux = null
  enregistreur = null
  enregistre.value = false
}

async function demarrer(): Promise<void> {
  if (enregistre.value) return
  erreur.value = null
  try {
    flux = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch (cause) {
    erreur.value =
      cause instanceof DOMException && (cause.name === 'NotAllowedError' || cause.name === 'SecurityError')
        ? 'Le micro est refusé : autorisez-le pour ce site dans les réglages du navigateur.'
        : 'Aucun micro n’est disponible sur cet appareil.'
    return
  }

  const format = choisirFormatEnregistrement((mime) => MediaRecorder.isTypeSupported(mime))
  const morceaux: Blob[] = []
  let r: MediaRecorder
  try {
    r = new MediaRecorder(flux, {
      ...(format.mime ? { mimeType: format.mime } : {}),
      audioBitsPerSecond: DEBIT_ENREGISTREMENT,
    })
  } catch {
    liberer()
    erreur.value = 'Ce navigateur ne sait pas enregistrer. Importez un fichier audio à la place.'
    return
  }

  r.ondataavailable = (e) => {
    if (e.data.size > 0) morceaux.push(e.data)
  }
  r.onstop = () => {
    const type = r.mimeType || format.mime || 'audio/webm'
    liberer()
    if (abandonne || morceaux.length === 0) return
    // `video/mp4` chez certains Chrome, même sans image : c'est de l'audio.
    const blob = new Blob(morceaux, { type: type.split(';')[0]!.replace(/^video\//, 'audio/') })
    const fichier = new File([blob], nomDEnregistrement(++rang, extensionDEnregistrement(type)), { type: blob.type })
    retenirDuree(fichier, Math.max(1, (Date.now() - debut) / 1000))
    emit('enregistrement', fichier)
  }
  r.onerror = () => {
    erreur.value = 'L’enregistrement s’est interrompu. Réessayez, ou importez un fichier audio.'
    liberer()
  }

  enregistreur = r
  secondes.value = 0
  enregistre.value = true
  // D'un seul tenant, sans tranches : un WebM découpé n'annonce aucune durée,
  // et certains lecteurs refusent un MP4 fragmenté.
  debut = Date.now()
  r.start()
  minuterie = setInterval(() => {
    secondes.value++
    if (secondes.value >= DUREE_MAX_ENREGISTREMENT) arreter()
  }, 1000)
}

function arreter(): void {
  if (enregistreur?.state === 'recording') enregistreur.stop()
}

onBeforeUnmount(() => {
  abandonne = true
  arreter()
  liberer()
})
</script>

<template>
  <template v-if="disponible">
    <button
      type="button"
      class="inline-flex min-h-11 items-center justify-center gap-2 rounded-champ border px-3.5 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-55 md:min-h-9"
      :class="enregistre ? 'border-alerte bg-alerte-doux text-alerte' : 'border-ligne bg-panneau text-texte hover:border-accent'"
      :disabled="desactive && !enregistre"
      :aria-pressed="enregistre"
      title="Enregistrez un témoignage : il est joint comme un document, et l’assistant l’écoute à l’analyse."
      @click="enregistre ? arreter() : demarrer()"
    >
      <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" class="max-md:hidden">
        <rect x="9" y="3" width="6" height="11" rx="3" fill="none" stroke="currentColor" stroke-width="1.8" />
        <path d="M5 11a7 7 0 0 0 14 0M12 18v3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      </svg>
      <template v-if="enregistre">
        <span class="h-2 w-2 animate-pulse rounded-full bg-alerte" aria-hidden="true" />
        Arrêter
        <span class="tabular-nums" aria-live="off">{{ dureeLisible(secondes) }}</span>
      </template>
      <template v-else>Enregistrer</template>
    </button>
    <span v-if="erreur" class="basis-full text-xs text-alerte" role="alert">{{ erreur }}</span>
  </template>
</template>
