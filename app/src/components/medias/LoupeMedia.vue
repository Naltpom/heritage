<script setup lang="ts">
/**
 * La loupe — **une seule image d'origine décodée à la fois**.
 *
 * Une fiche bien documentée porte une dizaine d'actes photographiés à 3 000 px
 * de large. Les afficher tous en pleine résolution ferait décoder plusieurs
 * dizaines de mégapixels pour une page dont le lecteur regarde une image. La
 * galerie n'affiche donc que des vignettes, et l'original ne se charge qu'ici.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Aucune URL n'est fabriquée ici, même quand elle serait devinable.**
 *
 * La cible `identifiant` ne connaît qu'un `mediaId` — c'est le cas des médias
 * attachés à une citation, que la fiche rend sous la forme
 * `{ mediaId, kind, vignetteUrl }` (§ 5.3). Écrire `/api/medias/${id}/fichier`
 * marcherait, et c'est précisément le piège : l'URL serait juste jusqu'au jour
 * où elle ne le serait plus, et un média que le lecteur n'a pas le droit de voir
 * rend **`404`** (§ 7.4) — donc une image cassée, là où l'absence de la clé `url`
 * ne produirait rien du tout. On charge donc le média, et on lit `url` dedans :
 * le refus arrive alors comme une erreur qui se lit, pas comme un carré gris.
 * ────────────────────────────────────────────────────────────────────
 *
 * Un **PDF** ne se met pas dans une balise `<img>` (§ 6.5 du dossier : « acte,
 * document, photo d'archive, PDF »). Il se lit ici quand même, page par page,
 * dans `LecteurPdf` : une copie intégrale d'état civil arrive presque toujours en
 * PDF, et la renvoyer vers un autre onglet revenait à ne pas la montrer sur la
 * fiche. Le lien vers l'onglet reste sous le lecteur, pour imprimer.
 *
 * Une image se **zoome** (05/10/2026) : boutons − et +, touches `+` et `-`, ou un
 * clic sur l'image, qui passe de « ajustée à l'écran » à deux fois plus grand et
 * revient. Agrandie, elle défile dans la loupe — l'écriture d'un acte ancien ne se
 * lit pas à la taille de l'écran.
 *
 * Fermeture au clic sur le fond **et** à la touche Échap : une image en plein
 * écran sans échappement clavier est une impasse pour qui n'emploie pas la souris.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { lireMedia } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import LecteurPdf from './LecteurPdf.vue'
import { estImage, type CibleLoupe } from './medias'

const { cible } = defineProps<{ cible: CibleLoupe | null }>()

const emit = defineEmits<{ fermer: [] }>()

/** Résolu depuis l'API pour une cible `identifiant`. */
const resolu = ref<{ url: string; estImage: boolean } | null>(null)
const erreur = ref<unknown>(null)
const chargement = ref(false)

const legende = computed(() => cible?.legende ?? '')

/** Les crans de zoom ; `1` = ajustée à l'écran. */
const CRANS = [1, 1.5, 2, 3, 4] as const
const cran = ref(0)
const zoom = computed(() => CRANS[cran.value] ?? 1)

function zoomer(pas: number): void {
  cran.value = Math.min(CRANS.length - 1, Math.max(0, cran.value + pas))
}

/** Le clic sur l'image : ajustée ↔ deux fois plus grande. */
function basculerZoom(): void {
  cran.value = cran.value === 0 ? CRANS.indexOf(2) : 0
}

const affichable = computed(() => {
  if (cible === null) return null
  if (cible.mode === 'direct') return { url: cible.url, estImage: cible.estImage }
  return resolu.value
})

async function resoudre(): Promise<void> {
  cran.value = 0
  resolu.value = null
  erreur.value = null
  if (cible === null || cible.mode !== 'identifiant') return

  chargement.value = true
  try {
    const media = await lireMedia(cible.mediaId)
    resolu.value = { url: media.url, estImage: estImage(media.mimeType) }
  } catch (cause) {
    // Un `404` est ici une réponse de sécurité, pas une panne : le média est
    // celui d'une personne qu'on n'a pas le droit de voir, ou il attend encore
    // une validation. On passe la cause brute — `MessageErreur` appelle
    // `analyserErreur()`, et **aucun écran ne réinterprète un code d'erreur**.
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

watch(() => cible, resoudre, { immediate: true })

function auClavier(evenement: KeyboardEvent): void {
  if (evenement.key === 'Escape') emit('fermer')
  else if (evenement.key === '+' || evenement.key === '=') zoomer(1)
  else if (evenement.key === '-') zoomer(-1)
}

onMounted(() => window.addEventListener('keydown', auClavier))
onBeforeUnmount(() => window.removeEventListener('keydown', auClavier))
</script>

<template>
  <div
    v-if="cible !== null"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4"
    role="dialog"
    aria-modal="true"
    :aria-label="legende.length > 0 ? legende : 'Média en grand'"
    @click.self="emit('fermer')"
  >
    <button
      type="button"
      class="absolute top-3 right-4 cursor-pointer border-0 bg-transparent px-2 text-3xl leading-none text-white"
      aria-label="Fermer"
      @click="emit('fermer')"
    >
      ×
    </button>

    <!-- Le zoom : pour une image seulement, le lecteur PDF a ses pages. -->
    <div
      v-if="affichable?.estImage && !chargement && erreur === null"
      class="absolute top-3 left-4 z-10 flex items-center gap-1.5"
      data-zoom
    >
      <button
        type="button"
        class="flex size-9 cursor-pointer items-center justify-center rounded-full border border-white/40 bg-black/50 text-xl leading-none text-white disabled:opacity-40"
        aria-label="Dézoomer"
        :disabled="cran === 0"
        @click="zoomer(-1)"
      >
        −
      </button>
      <span class="min-w-12 text-center font-mono text-xs text-white/80 tnum" aria-live="polite">
        {{ Math.round(zoom * 100) }} %
      </span>
      <button
        type="button"
        class="flex size-9 cursor-pointer items-center justify-center rounded-full border border-white/40 bg-black/50 text-xl leading-none text-white disabled:opacity-40"
        aria-label="Zoomer"
        :disabled="cran === CRANS.length - 1"
        @click="zoomer(1)"
      >
        +
      </button>
    </div>

    <div class="max-h-full w-full max-w-4xl overflow-auto" :class="zoom > 1 ? 'h-full' : ''">
      <SqueletteChargement v-if="chargement" :lignes="1" hauteur="h-64" />

      <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="resoudre" />

      <template v-else-if="affichable !== null">
        <img
          v-if="affichable.estImage"
          :src="affichable.url"
          :alt="legende.length > 0 ? legende : 'Document d’archive'"
          class="mx-auto block rounded-panneau bg-white"
          :class="zoom > 1 ? 'max-w-none cursor-zoom-out' : 'max-h-[80vh] max-w-full cursor-zoom-in'"
          :style="zoom > 1 ? { width: `${zoom * 100}%` } : undefined"
          decoding="async"
          @click="basculerZoom"
        />

        <!-- Un PDF : ses pages dessinées par pdf.js. Pas d'`<iframe>` — l'API
             interdit qu'on encadre ses fichiers (`frame-ancestors 'none'`), et
             c'est voulu ; voir `pdf.ts`. -->
        <LecteurPdf v-else :url="affichable.url" :legende="legende" />

        <p v-if="legende.length > 0" class="mt-2 text-center text-sm text-white/80">
          {{ legende }}
        </p>
      </template>
    </div>
  </div>
</template>
