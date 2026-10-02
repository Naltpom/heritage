<script setup lang="ts">
/**
 * « Enregistrer un message vocal » — **dire** sa famille, à côté du champ de
 * texte, qui reste et suffit à lui seul.
 *
 * Le parcours, en quatre états :
 *
 * 1. **repos** — le bouton micro. Au premier clic, le navigateur demande
 *    l'autorisation (`getUserMedia({ audio: true })`) ;
 * 2. **enregistrement** — un chrono, un niveau sonore (s'il peut être mesuré),
 *    « Arrêter ». Au bout de cinq minutes l'enregistrement s'arrête seul, et
 *    l'écran le dit ;
 * 3. **prêt** — un lecteur pour réécouter, « Recommencer », « Supprimer ». Un
 *    seul message : recommencer remplace le précédent ;
 * 4. **indisponible** — page non sécurisée, navigateur sans `MediaRecorder`,
 *    micro refusé : un message clair, et rien d'autre ne change — le texte
 *    reste possible.
 *
 * Le message produit remonte par `v-model` (`MessageVocal` : le `Blob`, son
 * type, la durée du chrono) ; c'est la vue qui l'envoie avec la déclaration.
 * Rien n'est écrit dans un stockage du navigateur, et l'URL `blob:` de
 * réécoute est révoquée dès qu'elle ne sert plus.
 *
 * `demarrage` est émis au début de chaque enregistrement : la déclaration y
 * coupe la consigne parlée — on ne parle pas par-dessus la personne.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { MessageVocal } from '@/lib/api'
import {
  DEBIT_AUDIO,
  DUREE_MAX_SECONDES,
  choisirTypeMime,
  disponibiliteMicro,
  erreurMessageVocal,
  formaterChrono,
  messageDErreurMicro,
  messageIndisponible,
  tailleLisible,
  typeDuFichier,
  type DisponibiliteMicro,
} from './messageVocal'

const messageVocal = defineModel<MessageVocal | null>({ default: null })

const emit = defineEmits<{
  /** Un enregistrement commence : couper ce qui parle. */
  demarrage: []
}>()

/** `null` tant que le composant n'est pas monté : le rendu serveur n'en décide pas. */
const disponibilite = ref<DisponibiliteMicro | null>(null)
const etat = ref<'repos' | 'demande' | 'enregistrement'>('repos')
const secondes = ref(0)
/** Le niveau sonore, de 0 à 1 — `null` s'il ne peut pas être mesuré. */
const niveau = ref<number | null>(null)
const erreur = ref<string | null>(null)
/** L'enregistrement s'est-il arrêté seul, au bout des cinq minutes ? */
const arretAutomatique = ref(false)
/** L'URL de réécoute du message prêt. */
const urlDeReecoute = ref<string | null>(null)

const restant = computed(() => formaterChrono(DUREE_MAX_SECONDES - secondes.value))
const messageDIndisponibilite = computed(() =>
  disponibilite.value !== null && disponibilite.value !== 'disponible'
    ? messageIndisponible(disponibilite.value)
    : null,
)

let enregistreur: MediaRecorder | null = null
let flux: MediaStream | null = null
let minuterie: ReturnType<typeof setInterval> | null = null
let debut = 0
let contexteAudio: AudioContext | null = null
let trame: number | null = null
/** Démonté en cours d'enregistrement : on coupe le micro sans rien produire. */
let abandonne = false

onMounted(() => {
  disponibilite.value = disponibiliteMicro({
    contexteSecurise: typeof window !== 'undefined' && window.isSecureContext !== false,
    aUnEnregistreur: typeof MediaRecorder !== 'undefined',
    aUnMicro: typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia,
  })
})

/** Le message change (nouveau, supprimé, ou repris au retour de l'étape 2) : l'URL suit. */
watch(
  messageVocal,
  (message) => {
    if (urlDeReecoute.value !== null) URL.revokeObjectURL(urlDeReecoute.value)
    urlDeReecoute.value =
      message !== null && typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function'
        ? URL.createObjectURL(message.fichier)
        : null
  },
  { immediate: true },
)

function liberer(): void {
  if (minuterie !== null) clearInterval(minuterie)
  minuterie = null
  if (trame !== null) cancelAnimationFrame(trame)
  trame = null
  void contexteAudio?.close().catch(() => undefined)
  contexteAudio = null
  niveau.value = null
  for (const piste of flux?.getTracks() ?? []) piste.stop()
  flux = null
  enregistreur = null
  etat.value = 'repos'
}

/**
 * Le niveau sonore, mesuré sur le flux du micro. Facultatif : un navigateur
 * sans `AudioContext` enregistre quand même, sans vu-mètre.
 */
function mesurerLeNiveau(source: MediaStream): void {
  try {
    const Contexte = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
    if (Contexte === undefined) return
    contexteAudio = new Contexte()
    const analyseur = contexteAudio.createAnalyser()
    analyseur.fftSize = 512
    contexteAudio.createMediaStreamSource(source).connect(analyseur)
    const echantillons = new Uint8Array(analyseur.fftSize)
    const lire = (): void => {
      analyseur.getByteTimeDomainData(echantillons)
      let crete = 0
      for (const valeur of echantillons) crete = Math.max(crete, Math.abs(valeur - 128))
      niveau.value = Math.min(1, crete / 64)
      trame = requestAnimationFrame(lire)
    }
    lire()
  } catch {
    niveau.value = null
  }
}

async function demarrer(): Promise<void> {
  if (etat.value !== 'repos' || disponibilite.value !== 'disponible') return
  erreur.value = null
  arretAutomatique.value = false
  etat.value = 'demande'
  emit('demarrage')

  try {
    flux = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch (cause) {
    etat.value = 'repos'
    erreur.value = messageDErreurMicro(cause)
    return
  }

  const mime = choisirTypeMime((type) => MediaRecorder.isTypeSupported(type))
  const morceaux: Blob[] = []
  let r: MediaRecorder
  try {
    r = new MediaRecorder(flux, { ...(mime ? { mimeType: mime } : {}), audioBitsPerSecond: DEBIT_AUDIO })
  } catch {
    liberer()
    erreur.value = messageIndisponible('sans_enregistreur')
    return
  }

  r.ondataavailable = (e) => {
    if (e.data.size > 0) morceaux.push(e.data)
  }
  r.onstop = () => {
    const type = typeDuFichier(r.mimeType || mime)
    const duree = Math.max(1, Math.round((Date.now() - debut) / 1000))
    liberer()
    if (abandonne || morceaux.length === 0) return
    const fichier = new Blob(morceaux, { type })
    const refus = erreurMessageVocal(fichier.size, duree)
    if (refus !== null) {
      erreur.value = refus
      return
    }
    // Un seul message : celui-ci remplace le précédent.
    messageVocal.value = { fichier, type, dureeSecondes: duree }
  }
  r.onerror = () => {
    erreur.value = 'L’enregistrement s’est interrompu. Réessayez, ou écrivez votre réponse.'
    liberer()
  }

  enregistreur = r
  secondes.value = 0
  debut = Date.now()
  // D'un seul tenant, sans tranches : certains lecteurs refusent un MP4
  // fragmenté, et la réécoute doit marcher partout.
  r.start()
  etat.value = 'enregistrement'
  mesurerLeNiveau(flux)
  minuterie = setInterval(() => {
    secondes.value = Math.floor((Date.now() - debut) / 1000)
    if (secondes.value >= DUREE_MAX_SECONDES) {
      arretAutomatique.value = true
      arreter()
    }
  }, 250)
}

function arreter(): void {
  if (enregistreur?.state === 'recording') enregistreur.stop()
}

function supprimer(): void {
  messageVocal.value = null
  arretAutomatique.value = false
  erreur.value = null
}

/** Recommencer : l'ancien message part, un nouvel enregistrement commence. */
function recommencer(): void {
  supprimer()
  void demarrer()
}

onBeforeUnmount(() => {
  abandonne = true
  arreter()
  liberer()
  if (urlDeReecoute.value !== null) URL.revokeObjectURL(urlDeReecoute.value)
})
</script>

<template>
  <div class="flex flex-col gap-2" data-enregistreur>
    <!-- Indisponible : on le dit, et rien d'autre ne bouge. -->
    <p v-if="messageDIndisponibilite !== null" class="text-xs text-attenue" role="note">
      {{ messageDIndisponibilite }}
    </p>

    <!-- Prêt : réécouter, recommencer, supprimer. -->
    <div
      v-else-if="messageVocal !== null && etat === 'repos'"
      class="flex flex-col gap-2 rounded-champ border border-ligne bg-fond px-3 py-2.5"
      data-message-pret
    >
      <p class="text-sm font-medium">
        Message vocal enregistré
        <span class="tnum font-normal text-attenue">
          — {{ formaterChrono(messageVocal.dureeSecondes) }}, {{ tailleLisible(messageVocal.fichier.size) }}
        </span>
      </p>
      <audio
        v-if="urlDeReecoute !== null"
        :src="urlDeReecoute"
        controls
        preload="metadata"
        class="w-full"
        aria-label="Réécouter votre message vocal"
      />
      <p v-if="arretAutomatique" class="text-xs text-attenue" role="status">
        L’enregistrement s’est arrêté seul au bout de cinq minutes.
      </p>
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="inline-flex min-h-9 items-center rounded-champ border border-ligne bg-panneau px-3 text-sm font-medium hover:border-accent"
          @click="recommencer"
        >
          Recommencer
        </button>
        <button
          type="button"
          class="inline-flex min-h-9 items-center rounded-champ border border-transparent px-3 text-sm text-alerte hover:border-alerte-bord"
          @click="supprimer"
        >
          Supprimer
        </button>
      </div>
    </div>

    <!-- En cours : chrono, niveau, « Arrêter ». -->
    <div
      v-else-if="etat === 'enregistrement'"
      class="flex flex-wrap items-center gap-3 rounded-champ border border-alerte-bord bg-alerte-doux px-3 py-2.5"
      data-enregistrement
    >
      <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-alerte motion-safe:animate-pulse" aria-hidden="true" />
      <span class="tnum text-sm font-semibold text-alerte" role="timer" aria-live="off">
        {{ formaterChrono(secondes) }}
      </span>
      <span
        v-if="niveau !== null"
        class="h-1.5 w-16 overflow-hidden rounded-full bg-ligne"
        aria-hidden="true"
        title="Niveau sonore"
      >
        <span class="block h-full bg-alerte" :style="{ width: `${Math.round(niveau * 100)}%` }" />
      </span>
      <span class="text-xs text-attenue">encore {{ restant }} au plus</span>
      <button
        type="button"
        class="ml-auto inline-flex min-h-9 items-center rounded-champ border border-alerte bg-panneau px-3 text-sm font-semibold text-alerte"
        @click="arreter"
      >
        Arrêter
      </button>
    </div>

    <!-- Au repos : le micro. -->
    <button
      v-else
      type="button"
      class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-champ border border-ligne bg-panneau px-3.5 text-sm font-semibold text-texte hover:border-accent disabled:cursor-wait disabled:opacity-60 md:min-h-9"
      :disabled="etat === 'demande' || disponibilite === null"
      data-micro
      @click="demarrer"
    >
      <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true">
        <rect x="9" y="3" width="6" height="11" rx="3" fill="none" stroke="currentColor" stroke-width="1.8" />
        <path d="M5 11a7 7 0 0 0 14 0M12 18v3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      </svg>
      Enregistrer un message vocal
    </button>

    <p v-if="etat === 'repos' && messageVocal === null && messageDIndisponibilite === null" class="text-xs text-attenue">
      Cinq minutes au plus. Vous pourrez le réécouter avant de continuer.
    </p>

    <p v-if="erreur !== null" class="text-xs text-alerte" role="alert">{{ erreur }}</p>
  </div>
</template>
