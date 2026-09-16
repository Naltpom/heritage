<script setup lang="ts">
/**
 * Ce qui appuie la fiche : les faits, les sources, les notes.
 *
 * **Les citations sont triées par `quay` décroissant.** `QUAY` dit *ce qui
 * étaye*, pas *combien on est sûr* : `2` veut dire « acte ou registre », `1`
 * « index ou déduction », `0` « recopie non vérifiée ». Sur cet arbre, 177
 * citations sont à `2` et 242 à `0` — mettre les actes en tête, c'est montrer
 * d'abord ce qui tient. Le **libellé** (`quayLabel`) vient du serveur : on
 * l'affiche, on ne le recompose pas, « pour que l'interface ne change pas de
 * vocabulaire » (§ 5.3).
 *
 * **Les images d'archive attachées à une citation n'arrivent qu'en vignette.**
 * La fiche rend `{ mediaId, kind, vignetteUrl }` et rien de plus (§ 5.3) : pas
 * d'`url`. On ne la fabrique donc pas — la loupe charge le média et lit `url`
 * dans sa réponse. Voir l'en-tête de `LoupeMedia.vue` : une URL devinée
 * court-circuiterait l'endroit où le serveur décide, et rendrait une image
 * cassée là où il faut un message.
 *
 * Les dates passent par `rendreDate()` — `texte` puis `raw`, jamais une
 * recomposition depuis `min` et `max` (§ 14).
 */
import { computed } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import type { CibleLoupe } from '@/components/medias/medias'
import { rendreDate } from './champs'

const { fiche } = defineProps<{ fiche: FichePersonneComplete }>()

const emit = defineEmits<{ agrandir: [cible: CibleLoupe] }>()

const citations = computed(() => [...fiche.citations].sort((a, b) => (b.quay ?? -1) - (a.quay ?? -1)))

/** Le liseré : vert pour un acte, accent pour un index, alerte pour une
 *  recopie. Même code couleur que le viewer d'origine. */
function lisere(quay: number | null): string {
  if ((quay ?? 0) >= 2) return 'border-ok'
  if ((quay ?? 0) >= 1) return 'border-accent'
  return 'border-alerte'
}
</script>

<template>
  <div class="flex flex-col gap-5 text-sm">
    <!-- Faits ---------------------------------------------------- -->
    <section v-if="fiche.faits.length > 0">
      <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">Faits</h3>
      <ul class="m-0 list-none p-0">
        <li v-for="fait in fiche.faits" :key="fait.id" class="py-0.5">
          <!-- `type` est une valeur du modèle (`residence`, `profession`…) :
               affichée telle quelle quand aucun libellé ne l'accompagne. -->
          <span>{{ fait.label ?? fait.type.replace(/_/g, ' ') }}</span>
          <span v-if="fait.happened !== null" class="text-xs text-attenue">
            — {{ rendreDate(fait.happened, 'date inconnue').principal }}
            <template v-if="fait.happened.place"> — {{ fait.happened.place }}</template>
          </span>
          <span v-if="fait.address" class="block text-xs text-attenue">{{ fait.address }}</span>
        </li>
      </ul>
    </section>

    <!-- Sources -------------------------------------------------- -->
    <section v-if="citations.length > 0">
      <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">
        Source<template v-if="citations.length > 1">s — {{ citations.length }}</template>
      </h3>

      <div
        v-for="citation in citations"
        :key="citation.id"
        class="mb-2.5 border-l-[3px] pl-3"
        :class="lisere(citation.quay)"
      >
        <p class="m-0 font-semibold">{{ citation.source?.title ?? 'Source non nommée' }}</p>
        <p v-if="citation.source?.author" class="m-0 text-xs text-attenue">
          {{ citation.source.author }}
        </p>
        <p v-if="citation.page" class="m-0 text-xs break-words text-attenue">{{ citation.page }}</p>
        <p v-else-if="citation.dataText" class="m-0 text-xs break-words text-attenue">
          {{ citation.dataText }}
        </p>

        <span
          v-if="citation.quayLabel"
          class="mt-1 inline-block rounded-pastille bg-accent-doux px-1.5 py-px text-[10.5px] font-semibold text-accent"
        >
          {{ citation.quayLabel }}
        </span>

        <p v-if="citation.url" class="m-0 mt-0.5">
          <a :href="citation.url" target="_blank" rel="noopener" class="break-all text-accent underline">
            Consulter en ligne ↗
          </a>
        </p>

        <ul v-if="citation.medias.length > 0" class="m-0 mt-1.5 flex list-none flex-wrap gap-2 p-0">
          <li v-for="image in citation.medias" :key="image.mediaId">
            <button
              type="button"
              class="cursor-zoom-in border-0 bg-transparent p-0"
              aria-label="Agrandir l’image d’archive"
              @click="
                emit('agrandir', {
                  mode: 'identifiant',
                  mediaId: image.mediaId,
                  legende: citation.source?.title ?? 'Image d’archive',
                })
              "
            >
              <img
                :src="image.vignetteUrl"
                alt="Image d’archive"
                class="block h-20 w-20 rounded-champ border border-ligne object-cover"
                loading="lazy"
                decoding="async"
              />
            </button>
          </li>
        </ul>
      </div>
    </section>

    <!-- Notes ---------------------------------------------------- -->
    <section v-if="fiche.notes.length > 0">
      <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">Notes</h3>
      <p
        v-for="note in fiche.notes"
        :key="note.id"
        class="my-1.5 border-l-[3px] border-ligne py-0.5 pl-3 text-xs whitespace-pre-line"
      >
        {{ note.body }}
      </p>
    </section>

    <p
      v-if="fiche.faits.length === 0 && citations.length === 0 && fiche.notes.length === 0"
      class="m-0 text-attenue italic"
    >
      Rien n’appuie encore cette fiche : ni fait, ni source, ni note.
    </p>
  </div>
</template>
