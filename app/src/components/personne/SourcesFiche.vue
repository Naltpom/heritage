<script setup lang="ts">
/**
 * Ce qui appuie la fiche : les sources et les notes — l'onglet « Sources et
 * notes » de la page. Les faits, eux, sont passés dans l'onglet Identité
 * (`FaitsFiche.vue`) : ils disent ce qu'a été la personne, pas ce qui le prouve.
 *
 * **Chaque source et chaque note est datée** — date et heure de son entrée dans
 * l'arbre (`createdAt`), au même format (`horodatageAvecHeure`). La date se lit,
 * elle ne trie pas les sources : l'ordre chronologique est celui de l'onglet
 * Historique.
 *
 * **Les citations sont triées par `appui` décroissant, et non par `quay`.**
 *
 * `quay` dit ce que le contributeur a **déclaré** ; `appui` dit ce que ça **vaut**.
 * Le serveur compose le second à partir de deux colonnes — la nature du fonds et
 * le `QUAY` de cette lecture-ci — en retenant **le plus bas des deux**, parce
 * qu'une fiche ne doit jamais paraître mieux tenue qu'elle ne l'est — et dans les
 * deux sens : citer un acte sans l'avoir vérifié reste une recopie.
 *
 * Trois niveaux, validés par le commanditaire : **tenu par un acte**, **tenu par
 * un index ou un relevé**, **tenu par un arbre en ligne**. La distinction entre
 * les deux premiers compte — un index se trompe, mais il a été dressé par
 * quelqu'un qui tenait le registre — et c'est pour cela qu'il y en a trois.
 *
 * **194 citations sur 483 sont au troisième niveau**, et elles étaient déjà cotées
 * `0` en base : l'information existait, rien ne la montrait. La pastille disait
 * « recopie non vérifiée » dans la même couleur que tout le reste, et la nature du
 * fonds n'était affichée nulle part. Mettre
 * les actes en tête, c'est montrer d'abord ce qui tient. Les **libellés**
 * (`appuiLabel`, `quayLabel`) viennent du serveur : on les affiche, on ne les
 * recompose pas, « pour que l'interface ne change pas de vocabulaire » (§ 5.3).
 *
 * **Les adresses d'arbres en ligne n'arrivent pas jusqu'ici.** Le serveur les
 * retire de `page`, de `dataText` et d'`url` — « retire tous les liens vers ces
 * URL » — **sans rien effacer en base**, « garde-les en DB uniquement pour
 * l'IA ». Cet écran n'a donc rien à filtrer, et ne doit surtout pas s'y mettre :
 * les liens qui restent sont ceux des visionneuses d'archives, qu'on veut voir.
 *
 * **Les images d'archive attachées à une citation n'arrivent qu'en vignette.**
 * La fiche rend `{ mediaId, kind, vignetteUrl }` et rien de plus (§ 5.3) : pas
 * d'`url`. On ne la fabrique donc pas — la loupe charge le média et lit `url`
 * dans sa réponse. Voir l'en-tête de `LoupeMedia.vue` : une URL devinée
 * court-circuiterait l'endroit où le serveur décide, et rendrait une image
 * cassée là où il faut un message.
 */
import { computed } from 'vue'
import type { CitationDeFiche, FichePersonneComplete } from '@/lib/api'
import type { CibleLoupe } from '@/components/medias/medias'
import { horodatageAvecHeure } from '@/lib/horodatage'

const { fiche } = defineProps<{ fiche: FichePersonneComplete }>()

const emit = defineEmits<{ agrandir: [cible: CibleLoupe] }>()

/** Les notes de la plus ancienne à la plus récente — l'ordre où les lire. */
const notes = computed(() =>
  [...fiche.notes].sort((a, b) => a.createdAt.localeCompare(b.createdAt) || a.id - b.id),
)

/** Le rang d'affichage des trois niveaux — ce qui tient en premier. */
const RANG: Record<CitationDeFiche['appui'], number> = { acte: 2, index: 1, arbre: 0 }

/**
 * Par appui, toujours : la date d'ajout s'affiche sur chaque source, mais ne
 * la range pas. Ce qui tient passe avant ce qui est récent — l'ordre « du plus
 * récent au plus ancien » est celui de l'onglet Historique.
 */
const citations = computed(() =>
  [...fiche.citations].sort((a, b) => RANG[b.appui] - RANG[a.appui]),
)

/** Le liseré : vert pour un acte, accent pour un index, alerte pour un arbre en
 *  ligne. Même code couleur que le viewer d'origine, sur le niveau composé. */
const LISERE: Record<CitationDeFiche['appui'], string> = {
  acte: 'border-ok',
  index: 'border-accent',
  arbre: 'border-alerte',
}

/** La pastille : neutre pour ce qui tient, alerte pour ce qui ne tient pas. Une
 *  citation d'arbre en ligne doit se repérer sans lire, c'est la demande. */
const PASTILLE: Record<CitationDeFiche['appui'], string> = {
  acte: 'bg-accent-doux text-accent',
  index: 'bg-accent-doux text-accent',
  arbre: 'bg-alerte-doux text-alerte',
}
</script>

<template>
  <div class="flex flex-col gap-5 text-sm">
    <!-- Sources -------------------------------------------------- -->
    <section v-if="citations.length > 0">
      <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">
        Source<template v-if="citations.length > 1">s — {{ citations.length }}</template>
      </h3>

      <div
        v-for="citation in citations"
        :key="citation.id"
        class="mb-2.5 border-l-[3px] pl-3"
        :class="LISERE[citation.appui]"
      >
        <!-- Quand la source est entrée dans l'arbre — même forme que la date
             d'une note. C'est la date de la ligne, pas celle de l'acte. -->
        <p class="m-0 mb-0.5 text-[11px] text-attenue">
          Ajoutée le <time :datetime="citation.createdAt">{{ horodatageAvecHeure(citation.createdAt) }}</time>
        </p>
        <p class="m-0 font-semibold">{{ citation.source?.title ?? 'Source non nommée' }}</p>
        <p v-if="citation.source?.author" class="m-0 text-xs text-attenue">
          {{ citation.source.author }}
        </p>
        <p v-if="citation.page" class="m-0 text-xs break-words text-attenue">{{ citation.page }}</p>
        <p v-else-if="citation.dataText" class="m-0 text-xs break-words text-attenue">
          {{ citation.dataText }}
        </p>

        <!-- Le niveau composé par le serveur, et lui seul en évidence : c'est la
             réponse à « lesquelles de mes citations sont tenues par un acte ». -->
        <span
          class="mt-1 inline-block rounded-pastille px-1.5 py-px text-[10.5px] font-semibold"
          :class="PASTILLE[citation.appui]"
        >
          {{ citation.appuiLabel }}
        </span>

        <!-- Le fonds, en second et en gris : « Arbre d'un autre utilisateur » dit
             **d'où** ça vient, là où la pastille dit ce que ça vaut. Les deux se
             lisent ensemble sur une citation d'arbre en ligne, et la seconde est
             muette quand le fonds n'apprend rien de plus. -->
        <span
          v-if="citation.appui === 'arbre' && citation.source"
          class="mt-1 ml-1 inline-block text-[10.5px] text-attenue"
        >
          {{ citation.source.sourceKindLabel }}
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
      <!-- Dans l'ordre où elles ont été écrites, datées : une note récente
           précise ou corrige souvent une plus ancienne, et la plus récente
           est signalée. -->
      <ol class="m-0 list-none p-0">
        <li
          v-for="(note, rang) in notes"
          :key="note.id"
          class="my-2 border-l-[3px] py-0.5 pl-3 text-xs"
          :class="rang === notes.length - 1 && notes.length > 1 ? 'border-accent' : 'border-ligne'"
        >
          <p class="m-0 mb-0.5 text-[11px] text-attenue">
            <time :datetime="note.createdAt">{{ horodatageAvecHeure(note.createdAt) }}</time>
            <template v-if="note.authorLabel"> · {{ note.authorLabel }}</template>
            <span
              v-if="rang === notes.length - 1 && notes.length > 1"
              class="ml-1.5 rounded-pastille bg-accent-doux px-1.5 py-px font-semibold text-accent"
            >la plus récente</span>
          </p>
          <p class="m-0 whitespace-pre-line">{{ note.body }}</p>
        </li>
      </ol>
    </section>

    <p
      v-if="citations.length === 0 && fiche.notes.length === 0"
      class="m-0 text-attenue italic"
    >
      Aucune source ni note pour l’instant.
    </p>
  </div>
</template>
