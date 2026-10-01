<script setup lang="ts">
/**
 * Une preuve dans la galerie : sa vignette, sa légende, **son état**.
 *
 * L'état n'est pas décoratif. Un média naît `en_attente` et suit le même circuit
 * de validation que les autres modifications (§ 7.1) : tant qu'il n'est pas
 * validé, il n'est visible que de son déposant et des administrateurs. Sans la
 * pastille, un contributeur voit son acte à l'écran, en conclut qu'il est publié,
 * et ne revient jamais vérifier. Le libellé vient de `pastilleDeMedia()` — un
 * seul endroit, pour que deux écrans ne disent pas deux choses.
 *
 * **La suppression est une action réservée à l'administrateur** (§ 7.6) : « un
 * membre ne supprime pas un média validé, il dépose une demande ». C'est l'un des
 * rares endroits où le rôle du lecteur a le droit de décider de ce que l'écran
 * propose — parce qu'il s'agit d'une **action**, pas d'un champ de données. Le
 * serveur ne compte d'ailleurs pas dessus : il refuse de son côté.
 *
 * **Chaque pièce est datée** : date et heure du dépôt (`createdAt`, § 7.2), au
 * même format que les sources et les notes de la fiche (`horodatageAvecHeure`).
 *
 * **Jamais de `confirm()`.** La confirmation est un second bouton qui apparaît à
 * la place du premier : elle se style, se lit au lecteur d'écran, et ne bloque
 * pas le fil du navigateur.
 */
import { computed, ref } from 'vue'
import type { Media } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import ApercuPdf from './ApercuPdf.vue'
import { estImage, pastilleDeMedia, tailleLisible, type CibleLoupe } from './medias'
import { horodatageAvecHeure } from '@/lib/horodatage'

const { media, supprimable = false, pivotable = false } = defineProps<{
  media: Media
  /** Une image que le lecteur peut remettre droite. */
  pivotable?: boolean
  /** Rendu par l'appelant à partir du rôle : c'est une **action** réservée. */
  supprimable?: boolean
  /** L'erreur de **ce** média, pas celle de la galerie : « un état d'erreur
   *  lisible par média, pas une alerte globale ». */
  erreur?: unknown
}>()

const emit = defineEmits<{
  agrandir: [cible: CibleLoupe]
  supprimer: [id: number]
  pivoter: [id: number]
}>()

const pastille = computed(() => pastilleDeMedia(media.status))
const confirmation = ref(false)

function agrandir(): void {
  emit('agrandir', {
    mode: 'direct',
    url: media.url,
    legende: media.caption ?? 'Document d’archive',
    estImage: estImage(media.mimeType),
  })
}
</script>

<template>
  <figure class="m-0 flex flex-col gap-1.5 rounded-panneau border border-ligne bg-panneau p-2">
    <button
      type="button"
      class="cursor-zoom-in border-0 bg-transparent p-0"
      :aria-label="`Agrandir : ${media.caption ?? 'document d’archive'}`"
      @click="agrandir"
    >
      <img
        v-if="estImage(media.mimeType)"
        :src="media.vignetteUrl"
        :alt="media.caption ?? 'Document d’archive'"
        class="block aspect-[4/3] w-full rounded-champ border border-ligne object-cover"
        loading="lazy"
        decoding="async"
      />
      <!-- Un PDF n'a pas de vignette côté serveur : sa première page est
           dessinée ici (voir `ApercuPdf`). -->
      <ApercuPdf v-else :url="media.url" :pages="media.pageCount" />
    </button>

    <figcaption class="flex flex-col gap-1">
      <PastilleEtat :etat="pastille.etat" :libelle="pastille.libelle" class="self-start" />

      <span v-if="media.caption" class="text-xs leading-snug break-words">
        {{ media.caption }}
      </span>

      <!-- La date du dépôt, au même format que les sources et les notes de la
           fiche : c'est par elle que l'onglet Historique range les documents. -->
      <span class="text-[11px] text-attenue">
        Ajouté le <time :datetime="media.createdAt">{{ horodatageAvecHeure(media.createdAt) }}</time>
      </span>

      <span class="text-[11px] text-attenue">
        <template v-if="media.kind === 'portrait'">Portrait — </template>
        {{ tailleLisible(media.byteSize) }}
        <template v-if="media.pageCount !== null">
          — {{ media.pageCount }} page<template v-if="media.pageCount > 1">s</template>
        </template>
        <template v-if="media.uploadedByLabel"> — déposé par {{ media.uploadedByLabel }}</template>
      </span>

      <!-- Le doublon est un **avertissement, pas un refus** : le même acte de
           mariage étaye légitimement les fiches des deux époux et de leurs
           quatre parents (§ 7.2). On le montre sans dramatiser. -->
      <span
        v-for="avertissement in media.avertissements ?? []"
        :key="avertissement.code"
        class="rounded-champ border border-ligne px-1.5 py-1 text-[11px] text-attenue"
      >
        {{ avertissement.message }}
      </span>

      <!-- L'EXIF a-t-il été purgé ? « Une photo de famille non nettoyée publie
           l'adresse du domicile » (§ 6.5 du dossier). Quand le serveur dit que
           non, c'est une information, pas un détail technique. -->
      <span v-if="!media.exifStripped" class="text-[11px] text-alerte">
        Métadonnées d’origine non purgées.
      </span>

      <MessageErreur v-if="erreur" :erreur="erreur" />

      <div v-if="pivotable && estImage(media.mimeType)" class="mt-0.5">
        <BoutonBase variante="discret" :aria-label="`Pivoter d’un quart de tour`" @click="emit('pivoter', media.id)">
          ↻ Pivoter
        </BoutonBase>
      </div>

      <template v-if="supprimable">
        <div v-if="!confirmation" class="mt-0.5">
          <BoutonBase variante="discret" @click="confirmation = true">Supprimer</BoutonBase>
        </div>
        <div v-else class="mt-0.5 flex flex-wrap items-center gap-2">
          <span class="text-[11px] text-attenue">Supprimer définitivement ?</span>
          <BoutonBase variante="danger" @click="emit('supprimer', media.id)">Oui</BoutonBase>
          <BoutonBase variante="discret" @click="confirmation = false">Non</BoutonBase>
        </div>
      </template>
    </figcaption>
  </figure>
</template>
