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
 * document, photo d'archive, PDF »). On l'ouvre dans un nouvel onglet — la
 * visionneuse du navigateur fait mieux que tout ce qu'on écrirait ici.
 *
 * Fermeture au clic sur le fond **et** à la touche Échap : une image en plein
 * écran sans échappement clavier est une impasse pour qui n'emploie pas la souris.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { lireMedia } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { estImage, type CibleLoupe } from './medias'

const { cible } = defineProps<{ cible: CibleLoupe | null }>()

const emit = defineEmits<{ fermer: [] }>()

/** Résolu depuis l'API pour une cible `identifiant`. */
const resolu = ref<{ url: string; estImage: boolean } | null>(null)
const erreur = ref<unknown>(null)
const chargement = ref(false)

const legende = computed(() => cible?.legende ?? '')

const affichable = computed(() => {
  if (cible === null) return null
  if (cible.mode === 'direct') return { url: cible.url, estImage: cible.estImage }
  return resolu.value
})

async function resoudre(): Promise<void> {
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

    <div class="max-h-full w-full max-w-4xl overflow-y-auto">
      <SqueletteChargement v-if="chargement" :lignes="1" hauteur="h-64" />

      <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="resoudre" />

      <template v-else-if="affichable !== null">
        <img
          v-if="affichable.estImage"
          :src="affichable.url"
          :alt="legende.length > 0 ? legende : 'Document d’archive'"
          class="mx-auto block max-h-[80vh] max-w-full rounded-panneau bg-white"
          decoding="async"
        />

        <!-- Un PDF : la visionneuse du navigateur, dans un onglet. Pas de
             `<embed>` — la CSP du projet est stricte et same-origin, et un
             cadre imbriqué y ajouterait une directive pour rien. -->
        <p v-else class="rounded-panneau bg-panneau px-4 py-6 text-center text-sm">
          Ce document n’est pas une image.
          <a
            :href="affichable.url"
            target="_blank"
            rel="noopener"
            class="font-semibold text-accent underline"
          >
            L’ouvrir dans un nouvel onglet ↗
          </a>
        </p>

        <p v-if="legende.length > 0" class="mt-2 text-center text-sm text-white/80">
          {{ legende }}
        </p>
      </template>
    </div>
  </div>
</template>
