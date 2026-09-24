<script setup lang="ts">
/**
 * La dictée : parler plutôt qu'écrire, dans le champ de l'assistant.
 *
 * C'est la reconnaissance vocale **du navigateur** (`SpeechRecognition`, préfixée
 * `webkit` sur Chrome et Safari), en français : le texte s'écrit à mesure, et le
 * membre le corrige avant d'analyser. Rien n'est enregistré par Heritage.
 *
 * - Chrome envoie l'audio à Google pour le transcrire, Safari à Apple : c'est la
 *   dictée du système, dite comme telle dans l'infobulle.
 * - Le micro doit être permis au site (`Permissions-Policy: microphone=(self)`,
 *   `app/nginx.conf`) ; le navigateur demande ensuite l'autorisation.
 * - Navigateur sans reconnaissance (Firefox) : le bouton n'apparaît pas. On le
 *   décide au montage, jamais au rendu serveur des tests.
 *
 * Le composant n'a aucun état métier : il émet chaque phrase **finale** ; la vue
 * l'ajoute à son texte (`ajouterDictee`).
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

const { desactive = false } = defineProps<{ desactive?: boolean }>()
const emit = defineEmits<{ phrase: [texte: string] }>()

interface ResultatReconnaissance {
  isFinal: boolean
  0: { transcript: string }
}
interface EvenementReconnaissance {
  resultIndex: number
  results: ArrayLike<ResultatReconnaissance>
}
interface Reconnaissance {
  lang: string
  continuous: boolean
  interimResults: boolean
  start: () => void
  stop: () => void
  onresult: ((e: EvenementReconnaissance) => void) | null
  onerror: ((e: { error: string }) => void) | null
  onend: (() => void) | null
}
type ConstructeurReconnaissance = new () => Reconnaissance

const disponible = ref(false)
const ecoute = ref(false)
const provisoire = ref('')
const erreur = ref<string | null>(null)
let reconnaissance: Reconnaissance | null = null

function constructeur(): ConstructeurReconnaissance | null {
  const w = globalThis as unknown as { SpeechRecognition?: ConstructeurReconnaissance; webkitSpeechRecognition?: ConstructeurReconnaissance }
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null
}

onMounted(() => {
  disponible.value = constructeur() !== null
})

function demarrer(): void {
  const C = constructeur()
  if (!C) return
  erreur.value = null
  const r = new C()
  r.lang = 'fr-FR'
  r.continuous = true
  r.interimResults = true
  r.onresult = (e) => {
    let enCours = ''
    for (let i = e.resultIndex; i < e.results.length; i++) {
      const resultat = e.results[i]!
      const morceau = resultat[0].transcript
      if (resultat.isFinal) emit('phrase', morceau)
      else enCours += morceau
    }
    provisoire.value = enCours
  }
  r.onerror = (e) => {
    erreur.value =
      e.error === 'not-allowed' || e.error === 'service-not-allowed'
        ? 'Le micro est refusé : autorisez-le pour ce site dans les réglages du navigateur.'
        : e.error === 'no-speech'
          ? null
          : 'La dictée s’est interrompue. Réessayez, ou écrivez.'
  }
  r.onend = () => {
    ecoute.value = false
    provisoire.value = ''
    reconnaissance = null
  }
  reconnaissance = r
  ecoute.value = true
  r.start()
}

function arreter(): void {
  reconnaissance?.stop()
}

onBeforeUnmount(() => reconnaissance?.stop())
</script>

<template>
  <div v-if="disponible" class="flex min-w-0 flex-col gap-1">
    <button
      type="button"
      class="inline-flex min-h-11 items-center gap-2 self-start rounded-pastille border px-3.5 text-sm font-semibold"
      :class="ecoute ? 'border-alerte bg-alerte-doux text-alerte' : 'border-ligne bg-panneau hover:border-accent'"
      :disabled="desactive"
      :aria-pressed="ecoute"
      title="Dictée du navigateur, en français (Chrome transcrit chez Google, Safari chez Apple)"
      @click="ecoute ? arreter() : demarrer()"
    >
      <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true">
        <rect x="9" y="3" width="6" height="11" rx="3" fill="none" stroke="currentColor" stroke-width="1.8" />
        <path d="M5 11a7 7 0 0 0 14 0M12 18v3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      </svg>
      {{ ecoute ? 'Arrêter la dictée' : 'Dicter' }}
      <span v-if="ecoute" class="h-2 w-2 animate-pulse rounded-full bg-alerte" aria-hidden="true" />
    </button>
    <span v-if="provisoire" class="truncate text-xs text-attenue italic" aria-live="polite">{{ provisoire }}</span>
    <span v-if="erreur" class="text-xs text-alerte" role="alert">{{ erreur }}</span>
  </div>
</template>
