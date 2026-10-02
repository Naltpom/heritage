<script setup lang="ts">
/**
 * Le lecteur des vidéos de présentation — plein écran, vertical, sans fioriture.
 *
 * Il ne sert qu'à **une** sorte de vidéo : les présentations verticales 9:16 du
 * catalogue (`lib/videos.ts`), voix off et sous-titres incrustés. Ce n'est pas
 * un lecteur général, et il ne cherche pas à l'être : ni avance rapide, ni
 * vitesse, ni plein écran natif. Une vidéo de trente secondes se regarde, se
 * coupe, ou se ferme.
 *
 * Le parent le monte **quand il faut le montrer** (`v-if`) et le démonte à
 * `fin` ou à `fermer`. Le montage est l'ouverture, le démontage la fermeture :
 * pas d'état `ouvert` à synchroniser, donc pas de lecteur à moitié refermé.
 *
 * ────────────────────────────────────────────────────────────────────
 * **La mise en page.**
 *
 * - **Téléphone** : la vidéo occupe l'écran, sur fond noir. `object-contain`
 *   et non `cover` : sur un écran plus allongé que 9:16, `cover` rognerait le
 *   haut et le bas — c'est-à-dire les sous-titres incrustés.
 * - **Ordinateur** (`md:`) : un cadre vertical 9:16 centré, haut de 90 % de la
 *   fenêtre, coins arrondis, sur un fond assombri et flouté — la page reste
 *   devinée derrière, on sait qu'on n'en est pas sorti.
 * - Le tout est **téléporté dans `<body>`** : ouvert depuis le feuillet des
 *   filtres de l'arbre, il serait sinon prisonnier du contexte d'empilement
 *   `z-20` de `BarreEcran`, sous l'en-tête. Hors navigateur (tests, rendu
 *   serveur), la téléportation est éteinte et le lecteur se rend sur place.
 *
 * **La lecture.**
 *
 * - **Démarrage automatique, en muet.** Les navigateurs refusent la lecture
 *   automatique avec le son ; en muet, ils l'acceptent (`muted playsinline`).
 *   Un gros bouton « Activer le son » est donc là dès la première image. Une
 *   fois le son activé, il devient le petit bouton couper/rétablir.
 * - La lecture est lancée **par `play()`** plutôt que par l'attribut
 *   `autoplay` : on apprend ainsi qu'elle a été refusée (mode économie
 *   d'énergie d'iOS, réglage du navigateur), et on montre alors le bouton
 *   « Lire » au lieu d'une image figée qui a l'air cassée.
 * - **`prefers-reduced-motion: reduce`** : pas de lecture automatique du tout.
 *   L'affiche, et un bouton « Lire ». Le geste du lecteur autorise alors le
 *   son : on lance la vidéo **avec** — il l'a demandée.
 * - Toucher la vidéo met en pause ou relance ; une fine barre en bas dit où
 *   l'on en est (elle n'est pas interactive : trente secondes ne se parcourent pas).
 * - **À la fin**, un fondu, puis `fin`. À la croix ou à Échap, `fermer` tout
 *   de suite : on n'impose pas d'attendre un fondu à qui veut partir.
 * - **Si la vidéo ne charge pas** (fichier absent, réseau coupé, format
 *   refusé), le lecteur se ferme **sans rien dire** (`fermer` avec le motif
 *   `echec`) : une vidéo de présentation n'est jamais sur le chemin de
 *   personne, et un message d'erreur sur l'écran d'une invitation ferait croire
 *   que c'est l'invitation qui est cassée. La vidéo est la `src` de `<video>`,
 *   pas une `<source>` enfant : c'est à cette condition que l'erreur arrive sur
 *   l'élément `<video>` lui-même.
 *
 * **Les sous-titres.** Ils sont **incrustés dans l'image** (karaoké, mot à
 * mot) : tout le monde les voit, son coupé compris — c'est ce qui rend la
 * lecture muette utile. La piste `<track kind="captions">` (le `.vtt`) est
 * pourtant là, et volontairement **sans `default`** :
 *
 * - avec `default`, le navigateur la peindrait **par-dessus** les sous-titres
 *   incrustés — le même texte deux fois, décalé ;
 * - sans `default`, elle reste disponible pour ce qui sait la lire : les
 *   réglages d'accessibilité du système (iOS « Sous-titres codés + SDH »,
 *   qui l'affichent alors d'office — un doublon que ce lecteur-là a demandé),
 *   les extensions, et l'indexation. Elle est aussi la transcription de
 *   référence, si l'on veut un jour l'afficher en texte sous la vidéo.
 * - Si le `.vtt` manque, rien ne se passe : l'erreur d'une piste ne remonte
 *   pas à `<video>`, la vidéo joue sans.
 *
 * **L'accessibilité.** `role="dialog"` + `aria-modal`, nommé par le titre de
 * la vidéo et décrit par sa phrase. Le focus part sur la **croix** — visible
 * et atteignable dès la première seconde, 44 px au moins —, il **reste dans
 * le lecteur** (Tab boucle sur ses boutons) et il **revient** à l'élément qui
 * l'avait à la fermeture. Le défilement de la page est bloqué pendant la
 * lecture, et rendu tel qu'il était.
 *
 * **Échap** est écouté en capture sur `window` et arrêté là : l'arbre derrière
 * ne doit ni désélectionner ni refermer son feuillet. Le drapeau
 * `lecteurVideoOuvert` (`composables/lecteurVideo.ts`) couvre le seul écouteur
 * posé avant le nôtre, celui du menu Filtres du bureau.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { cheminsDe, prefereMoinsDAnimations, type MotifFermeture, type VideoAide } from '@/lib/videos'
import { signalerLecteurOuvert } from '@/composables/lecteurVideo'

const { video } = defineProps<{ video: VideoAide }>()

const emit = defineEmits<{
  /** La vidéo est allée au bout ; le fondu est passé. */
  fin: []
  /** Fermée avant la fin : la croix, Échap — ou un échec de chargement, sans bruit. */
  fermer: [motif: MotifFermeture]
  /** Le fichier existe et se lit (métadonnées reçues) : la vidéo a bien été montrée. */
  chargee: []
}>()

const chemins = computed(() => cheminsDe(video))
const id = useId()

/** Durée du fondu de fin, en millisecondes — la même que la classe `duration-300`. */
const DUREE_FONDU = 300

const element = ref<HTMLVideoElement | null>(null)
const racine = ref<HTMLElement | null>(null)
const croix = ref<HTMLButtonElement | null>(null)

/** La lecture est en cours (et non en pause, ni pas encore lancée). */
const enLecture = ref(false)
/** Le son est coupé. Vrai au départ : c'est la condition de la lecture automatique. */
const muet = ref(true)
/** Le lecteur a déjà activé le son une fois : le gros bouton cède la place au petit. */
const sonDejaActive = ref(false)
/** La lecture attend un geste : mouvement réduit demandé, ou lecture automatique refusée. */
const attenteGeste = ref(false)
/** Entre 0 et 1. */
const progression = ref(0)
/** Le fondu de sortie est en cours. */
const enSortie = ref(false)

/**
 * La cible de la téléportation : `<body>` dans un navigateur, rien ailleurs.
 * Rien plutôt qu'un sélecteur : un rendu sans DOM (tests, rendu serveur) ne sait
 * pas résoudre `'body'`, et la téléportation éteinte rend le lecteur sur place.
 */
const cible = typeof document !== 'undefined' ? document.body : null

let focusAvant: HTMLElement | null = null
let debordementAvant = ''
let minuterieFondu: ReturnType<typeof setTimeout> | null = null
let liberer: (() => void) | null = null
let fini = false

/** Lance la lecture ; si le navigateur refuse, on attend le geste du lecteur. */
async function lancer(): Promise<void> {
  const v = element.value
  if (v === null || typeof v.play !== 'function') return
  try {
    await v.play()
    attenteGeste.value = false
  } catch (cause) {
    // `NotAllowedError` : lecture automatique refusée — on montre « Lire ».
    // Toute autre cause (source illisible…) arrive aussi par l'événement
    // `error` de l'élément, qui ferme le lecteur : rien à faire ici.
    if (cause instanceof Error && cause.name === 'NotAllowedError') attenteGeste.value = true
  }
}

/** Toucher la vidéo, ou le bouton pause/lecture. */
function basculerLecture(): void {
  const v = element.value
  if (v === null) return
  if (attenteGeste.value) {
    lireAvecLeSon()
    return
  }
  if (v.paused) void lancer()
  else v.pause()
}

/** Le gros bouton « Lire » : le geste autorise le son, et il a été demandé. */
function lireAvecLeSon(): void {
  regler(false)
  void lancer()
}

/** Coupe ou rend le son. Relance la lecture si elle était arrêtée. */
function regler(coupe: boolean): void {
  const v = element.value
  muet.value = coupe
  if (!coupe) sonDejaActive.value = true
  if (v === null) return
  v.muted = coupe
}

function activerLeSon(): void {
  regler(false)
  if (element.value?.paused) void lancer()
}

/* ───────────────── Événements de l'élément ───────────────── */

function surTemps(): void {
  const v = element.value
  if (v === null || !Number.isFinite(v.duration) || v.duration <= 0) return
  progression.value = Math.min(1, v.currentTime / v.duration)
}

function surFin(): void {
  if (fini) return
  fini = true
  progression.value = 1
  enLecture.value = false
  enSortie.value = true
  const delai = prefereMoinsDAnimations() ? 0 : DUREE_FONDU
  minuterieFondu = setTimeout(() => emit('fin'), delai)
}

/** Fichier absent, réseau coupé, format refusé : on s'efface, sans un mot. */
function surErreur(): void {
  if (fini) return
  fini = true
  emit('fermer', 'echec')
}

function fermer(motif: Exclude<MotifFermeture, 'echec'>): void {
  if (fini) return
  fini = true
  element.value?.pause?.()
  emit('fermer', motif)
}

/* ───────────────── Clavier : Échap et le piège du focus ───────────────── */

function surEchap(evenement: KeyboardEvent): void {
  if (evenement.key !== 'Escape') return
  evenement.stopImmediatePropagation()
  evenement.preventDefault()
  fermer('echap')
}

/** Tab et Maj+Tab bouclent sur les boutons du lecteur, sans en sortir. */
function surTouche(evenement: KeyboardEvent): void {
  if (evenement.key !== 'Tab') return
  const zone = racine.value
  if (zone === null || typeof zone.querySelectorAll !== 'function') return
  const focalisables = [...zone.querySelectorAll<HTMLElement>('button:not([disabled])')]
  if (focalisables.length === 0) return
  const premier = focalisables[0]!
  const dernier = focalisables[focalisables.length - 1]!
  const ici = document.activeElement
  if (evenement.shiftKey && (ici === premier || !zone.contains(ici))) {
    evenement.preventDefault()
    dernier.focus()
  } else if (!evenement.shiftKey && (ici === dernier || !zone.contains(ici))) {
    evenement.preventDefault()
    premier.focus()
  }
}

/* ───────────────── Ouverture et fermeture ───────────────── */

onMounted(async () => {
  liberer = signalerLecteurOuvert()
  // Hors navigateur, rien d'autre à brancher : ni fenêtre, ni page à figer.
  if (typeof window === 'undefined' || typeof document === 'undefined') return

  focusAvant = document.activeElement instanceof HTMLElement ? document.activeElement : null
  debordementAvant = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  window.addEventListener('keydown', surEchap, true)

  await nextTick()
  croix.value?.focus()

  const v = element.value
  if (v === null) return
  // L'attribut ET la propriété : iOS lit l'attribut pour autoriser la lecture
  // automatique, et Vue ne pose que la propriété.
  v.muted = true
  v.defaultMuted = true
  v.setAttribute('muted', '')
  if (prefereMoinsDAnimations()) attenteGeste.value = true
  else void lancer()
})

onBeforeUnmount(() => {
  liberer?.()
  if (minuterieFondu !== null) clearTimeout(minuterieFondu)
  if (typeof window === 'undefined' || typeof document === 'undefined') return
  window.removeEventListener('keydown', surEchap, true)
  document.body.style.overflow = debordementAvant
  element.value?.pause?.()
  if (focusAvant?.isConnected) focusAvant.focus()
  focusAvant = null
})

const pourcentage = computed(() => `${(progression.value * 100).toFixed(2)}%`)
</script>

<template>
  <Teleport :to="cible" :disabled="cible === null">
    <div
      ref="racine"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="`${id}-titre`"
      :aria-describedby="`${id}-description`"
      data-lecteur-video
      :data-video="video.id"
      class="lecteur-video fixed inset-0 z-[700] flex items-center justify-center bg-black transition-opacity duration-300 md:bg-black/75 md:backdrop-blur-md"
      :class="enSortie ? 'opacity-0' : 'opacity-100'"
      @keydown="surTouche"
    >
      <!-- Le titre et la phrase : pour les lecteurs d'écran, qui annoncent le
           dialogue par eux. À l'écran, la vidéo dit déjà ce qu'elle est. -->
      <h2 :id="`${id}-titre`" class="lecteur-ecran">{{ video.titre }}</h2>
      <p :id="`${id}-description`" class="lecteur-ecran">{{ video.description }}</p>

      <div
        class="relative h-full w-full overflow-hidden bg-black md:aspect-[9/16] md:h-[90dvh] md:w-auto md:max-w-[calc(100vw-2rem)] md:rounded-[20px] md:shadow-panneau"
      >
        <!-- `muted` + `playsinline` : les deux conditions d'une lecture
             automatique sur téléphone ; sans `playsinline`, l'iPhone ouvrirait
             son propre lecteur plein écran. Pas d'attribut `autoplay` : la
             lecture est lancée par `play()`, qui dit si elle a été refusée. -->
        <video
          ref="element"
          class="block h-full w-full cursor-pointer object-contain"
          :src="chemins.mp4"
          :poster="chemins.affiche"
          muted
          playsinline
          preload="auto"
          :aria-label="video.titre"
          @click="basculerLecture"
          @play="enLecture = true"
          @pause="enLecture = false"
          @timeupdate="surTemps"
          @loadedmetadata="emit('chargee')"
          @ended="surFin"
          @error="surErreur"
        >
          <!-- Sans `default`, exprès : les sous-titres sont déjà dans l'image
               (voir l'en-tête). -->
          <track kind="captions" srclang="fr" label="Français" :src="chemins.sousTitres" />
        </video>

        <!-- La croix — en premier dans l'ordre du focus, visible dès la
             première image, 44 px de zone tactile au moins. -->
        <button
          ref="croix"
          type="button"
          aria-label="Fermer la vidéo"
          data-fermer-video
          class="absolute top-[max(0.75rem,env(safe-area-inset-top))] right-3 flex size-11 items-center justify-center rounded-full bg-black/55 text-white backdrop-blur-sm hover:bg-black/75 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          @click="fermer('croix')"
        >
          <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
            <path d="M6 6l12 12M18 6 6 18" />
          </svg>
        </button>

        <!-- Le son. Tant qu'il n'a jamais été activé : un gros bouton, avec
             ses mots. Ensuite : une icône qui coupe et rétablit. -->
        <button
          v-if="!sonDejaActive && !attenteGeste"
          type="button"
          data-activer-son
          class="absolute top-[max(0.75rem,env(safe-area-inset-top))] left-3 flex min-h-11 items-center gap-2 rounded-pastille bg-accent px-4 text-[15px] font-semibold text-fond shadow-panneau hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          @click="activerLeSon"
        >
          <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z" fill="currentColor" fill-opacity="0.2" />
            <path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11" />
          </svg>
          Activer le son
        </button>
        <button
          v-else-if="sonDejaActive"
          type="button"
          :aria-label="muet ? 'Rétablir le son' : 'Couper le son'"
          data-basculer-son
          class="absolute top-[max(0.75rem,env(safe-area-inset-top))] left-3 flex size-11 items-center justify-center rounded-full bg-black/55 text-white backdrop-blur-sm hover:bg-black/75 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          @click="regler(!muet)"
        >
          <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z" fill="currentColor" fill-opacity="0.2" />
            <path v-if="muet" d="m16 9.5 5 5M21 9.5l-5 5" />
            <path v-else d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11" />
          </svg>
        </button>

        <!-- La lecture attend un geste (mouvement réduit, lecture automatique
             refusée) : un bouton « Lire » au milieu de l'affiche. -->
        <button
          v-if="attenteGeste"
          type="button"
          data-lire-video
          class="absolute top-1/2 left-1/2 flex -translate-x-1/2 -translate-y-1/2 items-center gap-2.5 rounded-pastille bg-accent px-6 py-3.5 text-base font-semibold text-fond shadow-panneau hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          @click="lireAvecLeSon"
        >
          <svg viewBox="0 0 24 24" class="size-5" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z" /></svg>
          Lire la vidéo
        </button>

        <!-- Pause / lecture, en bas à gauche, au-dessus de la barre. Toucher la
             vidéo fait la même chose ; le bouton est là pour le clavier. -->
        <button
          v-else
          type="button"
          :aria-label="enLecture ? 'Mettre en pause' : 'Reprendre la lecture'"
          data-pause-video
          class="absolute bottom-[max(1rem,calc(env(safe-area-inset-bottom)+0.5rem))] left-3 flex size-11 items-center justify-center rounded-full bg-black/45 text-white backdrop-blur-sm hover:bg-black/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          @click="basculerLecture"
        >
          <svg v-if="enLecture" viewBox="0 0 24 24" class="size-5" fill="currentColor" aria-hidden="true">
            <rect x="6.5" y="5" width="4" height="14" rx="1" /><rect x="13.5" y="5" width="4" height="14" rx="1" />
          </svg>
          <svg v-else viewBox="0 0 24 24" class="size-5" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z" /></svg>
        </button>

        <!-- La progression : une ligne fine, en bas, non interactive. -->
        <div class="pointer-events-none absolute inset-x-0 bottom-[env(safe-area-inset-bottom,0px)] h-1 bg-white/25" aria-hidden="true">
          <div class="barre-progression h-1 bg-white" :style="{ width: pourcentage }" />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/* `timeupdate` n'arrive que quatre fois par seconde : la transition linéaire
   comble les sauts, et la barre avance d'un trait continu. */
.barre-progression {
  transition: width 250ms linear;
}

@media (prefers-reduced-motion: reduce) {
  .barre-progression,
  .lecteur-video {
    transition: none;
  }
}
</style>
