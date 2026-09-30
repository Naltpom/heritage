<script setup lang="ts">
/**
 * Le document d'une demande `ajout_media`, montré à qui doit la décider.
 *
 * ────────────────────────────────────────────────────────────────────
 * **LE DÉFAUT QUE CE COMPOSANT RÉPARE.** L'écran de diff ne connaissait
 * `ajout_media` que par son libellé — « Ajout d'un document ». Ni vignette, ni
 * image, ni même la légende : on approuvait une pièce **sans jamais la
 * regarder**. C'est la seule nature de demande dont la valeur *est* le contenu,
 * et c'était la seule qu'on ne voyait pas.
 *
 * **L'URL n'est pas fabriquée, elle est lue.** L'op porte un `mediaId`, jamais
 * une adresse. On charge donc le média et on lit `url` / `vignetteUrl` dedans —
 * c'est la règle écrite en tête de `lib/api/medias.ts` et de `LoupeMedia.vue`,
 * et elle tient à ce qu'un média interdit rende **404** : une URL recomposée
 * donnerait une image cassée là où l'absence de la clé ne produit rien du tout.
 *
 * **La loupe n'est pas réécrite ici.** Elle vit dans `LoupeMedia`, tenue par la
 * vue — « une seule image d'origine décodée à la fois ». Ce composant émet une
 * `CibleLoupe` en mode `direct` : il a déjà la charge du média, donc l'`url` et
 * le type réel, et la loupe n'a pas à les redemander. C'est aussi ce qui fait
 * qu'un **PDF** — la forme courante d'un acte scanné — se lit dans la loupe
 * page par page, au lieu d'être poussé dans une `<img>` qui ne l'affichera jamais.
 *
 * **Un échec de chargement ne remplace pas l'écran.** Le diff reste juste sans
 * la vignette ; c'est la pièce qui manque, pas la demande. On le dit sur une
 * ligne, et l'administrateur sait alors qu'il déciderait sans voir.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { lireMedia, type Media } from '@/lib/api'
import { estImage, type CibleLoupe } from '@/components/medias/medias'
import ApercuPdf from '@/components/medias/ApercuPdf.vue'

const { mediaId } = defineProps<{ mediaId: number }>()

const emit = defineEmits<{ agrandir: [cible: CibleLoupe] }>()

const media = shallowRef<Media | null>(null)
const echec = ref(false)

const legende = computed(() => media.value?.caption ?? 'Document proposé')

let abandon: AbortController | null = null

onMounted(async () => {
  const controleur = new AbortController()
  abandon = controleur
  try {
    const lu = await lireMedia(mediaId, controleur.signal)
    if (!controleur.signal.aborted) media.value = lu
  } catch {
    // Le refus se lit sur une ligne plutôt que de remplacer le diff : la
    // demande reste décidable, et c'est au lecteur de juger s'il décide sans
    // avoir vu la pièce.
    if (!controleur.signal.aborted) echec.value = true
  }
})

onBeforeUnmount(() => {
  abandon?.abort()
})

function agrandir(): void {
  if (media.value === null) return
  emit('agrandir', {
    mode: 'direct',
    url: media.value.url,
    legende: legende.value,
    estImage: estImage(media.value.mimeType),
  })
}
</script>

<template>
  <div class="mt-2">
    <button
      v-if="media !== null"
      type="button"
      class="cursor-pointer rounded-champ border border-ligne bg-fond p-1"
      :aria-label="`Agrandir : ${legende}`"
      @click="agrandir"
    >
      <img
        v-if="estImage(media.mimeType)"
        :src="media.vignetteUrl"
        :alt="legende"
        class="max-h-40 rounded-[4px]"
        decoding="async"
      />
      <div v-else class="w-40">
        <ApercuPdf :url="media.url" :pages="media.pageCount" />
      </div>
    </button>

    <p v-else-if="echec" class="text-xs text-alerte">
      Ce document n’a pas pu être chargé — vous décideriez sans l’avoir vu.
    </p>

    <p v-else class="text-xs text-attenue" role="status">Chargement du document…</p>
  </div>
</template>
