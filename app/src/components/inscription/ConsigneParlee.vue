<script setup lang="ts">
/**
 * La **consigne parlée** de « Décrivez votre famille » : un petit lecteur
 * discret — « Écouter les explications », une fine barre de progression, et
 * « Lire le texte » qui déplie la transcription.
 *
 * Elle dit, d'une voix, ce que l'écran demande : on n'a pas trouvé de fiche
 * exacte, ce n'est pas grave, racontez qui vous êtes — date de naissance,
 * parents, frères et sœurs, conjoint, enfants, avec leurs dates —, par écrit ou
 * à voix haute. Le texte exact est `TEXTE_CONSIGNE` :
 *
 *   « Nous n'avons pas trouvé de fiche qui corresponde exactement à ce que vous
 *   avez saisi. Ce n'est pas grave : racontez-nous simplement qui vous êtes.
 *   Votre date de naissance, puis vos parents, avec leurs dates. Si vous avez
 *   des frères et sœurs, un conjoint ou des enfants, donnez aussi leurs dates.
 *   Vous pouvez l'écrire ci-dessous, ou appuyer sur le micro pour le dire à
 *   voix haute, comme vous préférez. »
 *
 * Ce qui est délibéré (le détail dans `consigneVocale.ts`) :
 *
 * - **lecture automatique, une fois par onglet** (`lectureAuto`, retenu en
 *   `sessionStorage`). L'écran apparaît après « Rechercher ma fiche » : il y a
 *   eu un geste, et la lecture avec le son est en général permise ;
 * - **un refus du navigateur ne casse rien** : pas de message, le bouton reste ;
 * - **`arreter()`** est exposée : la déclaration l'appelle dès qu'on tape ou
 *   qu'on enregistre — on ne parle pas par-dessus la personne ;
 * - **`prefers-reduced-motion`** ne coupe que l'animation de la barre : le son,
 *   lui, reste permis — ce n'est pas un mouvement.
 *
 * L'élément `<audio>` est **partagé** (`elementDeConsigne()`), et non posé dans
 * le gabarit : c'est lui que le clic sur « Rechercher ma fiche » a
 * « déverrouillé » pour Safari. Ce composant ne fait donc que s'y abonner, et
 * se désabonne au démontage.
 */
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import {
  TEXTE_CONSIGNE,
  doitJouerAutomatiquement,
  elementDeConsigne,
  progression,
  retenirConsigneJouee,
} from './consigneVocale'

const { lectureAuto = false } = defineProps<{
  /** Se lancer seule au montage — si elle n'a pas déjà été entendue dans l'onglet. */
  lectureAuto?: boolean
}>()

const identifiant = useId()
const idTexte = `${identifiant}-texte`

const enLecture = ref(false)
const ecoule = ref(0)
const transcriptionOuverte = ref(false)
/** L'élément audio est-il là ? Faux au rendu serveur et avant le montage : la
 *  barre attend, le bouton est déjà là — un clic avant le montage ne fait rien. */
const disponible = ref(false)

const pourcentage = computed(() => `${Math.round(ecoule.value * 1000) / 10}%`)

let audio: HTMLAudioElement | null = null

function suivre(): void {
  if (audio === null) return
  ecoule.value = progression(audio.currentTime, audio.duration)
}
function surLecture(): void {
  // Le préchauffage muet du clic n'est pas une lecture.
  enLecture.value = audio !== null && !audio.muted
}
function surPause(): void {
  enLecture.value = false
}
function surFin(): void {
  enLecture.value = false
  ecoule.value = 1
}

const ABONNEMENTS: Array<[string, () => void]> = [
  ['timeupdate', suivre],
  ['loadedmetadata', suivre],
  ['playing', surLecture],
  ['pause', surPause],
  ['ended', surFin],
]

async function jouer(): Promise<void> {
  if (audio === null) return
  // Le préchauffage du clic l'a peut-être laissé muet, et un peu avancé : on
  // reprend du début, avec le son.
  if (audio.muted) {
    audio.currentTime = 0
    audio.muted = false
  }
  if (audio.ended) audio.currentTime = 0
  try {
    await audio.play()
    // Retenue dès qu'elle a **commencé** : la relance d'une recherche vide ne
    // la rejouera pas, même si la personne l'a coupée.
    retenirConsigneJouee()
  } catch {
    // `NotAllowedError` (pas de geste reconnu) ou `AbortError` (coupée avant de
    // commencer) : le bouton est là, c'est tout. Rien d'autre ne peut arriver
    // ici qui mériterait davantage — un fichier absent se signale par `error`,
    // et laisse aussi le bouton.
    enLecture.value = false
  }
}

/** Coupe la consigne. Appelée quand la personne tape ou enregistre. */
function arreter(): void {
  audio?.pause()
}

function basculer(): void {
  if (enLecture.value) arreter()
  else void jouer()
}

onMounted(() => {
  audio = elementDeConsigne()
  if (audio === null) return
  disponible.value = true
  audio.preload = 'auto'
  for (const [evenement, f] of ABONNEMENTS) audio.addEventListener(evenement, f)
  enLecture.value = !audio.paused && !audio.muted
  suivre()
  if (doitJouerAutomatiquement(lectureAuto)) void jouer()
})

onBeforeUnmount(() => {
  if (audio === null) return
  for (const [evenement, f] of ABONNEMENTS) audio.removeEventListener(evenement, f)
  // Un panneau refermé ne continue pas de parler.
  audio.pause()
  audio = null
})

defineExpose({ arreter })
</script>

<template>
  <section class="rounded-champ border border-ligne bg-fond px-3 py-2.5" data-consigne aria-label="Explications parlées">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
      <button
        type="button"
        class="inline-flex min-h-9 items-center gap-1.5 rounded-champ border border-ligne bg-panneau px-3 text-sm font-medium text-texte hover:border-accent"
        :aria-pressed="enLecture"
        :aria-describedby="transcriptionOuverte ? undefined : idTexte"
        data-ecouter
        @click="basculer"
      >
        <span aria-hidden="true">{{ enLecture ? '❚❚' : '▶' }}</span>
        {{ enLecture ? 'Pause' : 'Écouter les explications' }}
      </button>
      <button
        type="button"
        class="text-sm text-attenue underline underline-offset-2 hover:text-texte"
        :aria-expanded="transcriptionOuverte"
        :aria-controls="idTexte"
        @click="transcriptionOuverte = !transcriptionOuverte"
      >
        {{ transcriptionOuverte ? 'Masquer le texte' : 'Lire le texte' }}
      </button>
    </div>

    <!-- La progression : décorative pour qui entend, elle n'est pas annoncée
         seconde après seconde — le bouton dit déjà « Pause ». -->
    <div v-if="disponible" class="mt-2 h-1 overflow-hidden rounded-full bg-ligne" aria-hidden="true">
      <div
        class="h-full rounded-full bg-accent transition-[width] duration-300 ease-linear motion-reduce:transition-none"
        :style="{ width: pourcentage }"
        data-progression
      />
    </div>

    <!-- La transcription. Toujours dans le DOM : repliée, elle reste la
         description du bouton pour les lecteurs d'écran (`lecteur-ecran`). -->
    <p
      :id="idTexte"
      class="text-sm leading-relaxed"
      :class="transcriptionOuverte ? 'mt-2' : 'lecteur-ecran'"
      data-transcription
    >
      {{ TEXTE_CONSIGNE }}
    </p>
  </section>
</template>
