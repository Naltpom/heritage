<script setup lang="ts">
/**
 * Les portraits d'une personne, **un à la fois** : celui en place d'abord, puis
 * ce qui attend une décision, puis les anciens (`remplace`).
 *
 * Les anciens portraits n'étaient montrés nulle part : la validation d'un second
 * portrait reléguait le premier en `remplace`, et il disparaissait de la fiche
 * sans avoir été supprimé (signalé le 05/10/2026). Ils sont ici, chacun avec sa
 * pastille, et l'administrateur peut en **remettre un en place** (§ 7.7).
 *
 * Un seul portrait : pas de flèches ni de points, rien à faire défiler.
 *
 * Le glissement du doigt fait défiler les portraits et **ne remonte pas** à la
 * page : sans le `.stop`, `PageFiche` le lirait aussi et changerait d'onglet.
 */
import { computed, ref, watch } from 'vue'
import type { Media } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import { horodatageAvecHeure } from '@/lib/horodatage'
import { pastilleDeMedia, type CibleLoupe } from './medias'

const { portraits, peutRemettre = false, supprimable = false, enCours = null } = defineProps<{
  /** Déjà ordonnés (`ordonnerPortraits`). */
  portraits: Media[]
  /** Actions réservées à l'administrateur : rendues par l'appelant. */
  peutRemettre?: boolean
  supprimable?: boolean
  /** Les mêmes règles que la galerie : l'administrateur, ou l'auteur tant que
   *  la pièce attend. */
  pivotable?: (media: Media) => boolean
  /** Le portrait dont la remise en place est en cours. */
  enCours?: number | null
  erreurParMedia?: Record<number, unknown>
}>()

const emit = defineEmits<{
  agrandir: [cible: CibleLoupe]
  remettre: [id: number]
  supprimer: [id: number]
  pivoter: [id: number]
}>()

const rang = ref(0)
/** La confirmation de suppression, pour le portrait affiché seulement. */
const confirmation = ref(false)
watch(rang, () => {
  confirmation.value = false
})

// La liste change (dépôt, remise en place) : on revient au portrait en place.
watch(
  () => portraits.map((p) => `${p.id}:${p.status}`).join(','),
  () => {
    rang.value = 0
  },
)

const courant = computed(() => portraits[Math.min(rang.value, portraits.length - 1)] ?? null)

function aller(pas: number): void {
  const n = portraits.length
  if (n === 0) return
  rang.value = (rang.value + pas + n) % n
}

function pastille(media: Media): { etat: 'validee' | 'en_attente' | 'refusee' | 'neutre'; libelle: string } {
  if (media.isActivePortrait) return { etat: 'validee', libelle: 'Portrait actuel' }
  return pastilleDeMedia(media.status)
}

function agrandir(media: Media): void {
  emit('agrandir', {
    mode: 'direct',
    url: media.url,
    legende: media.caption ?? 'Portrait',
    estImage: true,
  })
}

let departX: number | null = null
function surToucheDebut(e: TouchEvent): void {
  departX = e.touches.length === 1 ? (e.touches[0]?.clientX ?? null) : null
}
function surToucheFin(e: TouchEvent): void {
  const fin = e.changedTouches[0]?.clientX
  if (departX === null || fin === undefined) return
  const ecart = fin - departX
  departX = null
  if (Math.abs(ecart) > 40) aller(ecart < 0 ? 1 : -1)
}

function auClavier(e: KeyboardEvent): void {
  if (e.key === 'ArrowLeft') aller(-1)
  else if (e.key === 'ArrowRight') aller(1)
}
</script>

<template>
  <div
    v-if="courant !== null"
    class="flex flex-col items-center gap-2"
    role="group"
    aria-roledescription="carrousel"
    :aria-label="`Portraits — ${rang + 1} sur ${portraits.length}`"
    data-carrousel-portraits
    tabindex="0"
    @keydown="auClavier"
    @touchstart.passive.stop="surToucheDebut"
    @touchend.passive.stop="surToucheFin"
  >
    <div class="flex w-full items-center justify-center gap-2">
      <button
        v-if="portraits.length > 1"
        type="button"
        class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-full border border-ligne bg-panneau text-lg transition-colors hover:border-accent"
        aria-label="Portrait précédent"
        @click="aller(-1)"
      >
        ‹
      </button>

      <button
        type="button"
        class="cursor-zoom-in border-0 bg-transparent p-0"
        :aria-label="`Agrandir : ${courant.caption ?? 'portrait'}`"
        @click="agrandir(courant)"
      >
        <img
          :key="courant.id"
          :src="courant.vignetteUrl"
          :alt="courant.caption ?? 'Portrait'"
          class="block h-56 w-56 rounded-panneau border border-ligne bg-fond object-cover max-md:h-48 max-md:w-48"
          decoding="async"
        />
      </button>

      <button
        v-if="portraits.length > 1"
        type="button"
        class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-full border border-ligne bg-panneau text-lg transition-colors hover:border-accent"
        aria-label="Portrait suivant"
        @click="aller(1)"
      >
        ›
      </button>
    </div>

    <!-- Les points : où l'on est, et un accès direct à chacun. -->
    <div v-if="portraits.length > 1" class="flex items-center gap-1.5">
      <button
        v-for="(portrait, i) in portraits"
        :key="portrait.id"
        type="button"
        class="size-2.5 cursor-pointer rounded-full border-0 p-0"
        :class="i === rang ? 'bg-accent' : 'bg-ligne'"
        :aria-label="`Portrait ${i + 1}`"
        :aria-current="i === rang ? 'true' : undefined"
        @click="rang = i"
      />
    </div>

    <div class="flex flex-col items-center gap-1 text-center">
      <PastilleEtat :etat="pastille(courant).etat" :libelle="pastille(courant).libelle" />
      <span v-if="courant.caption" class="text-xs leading-snug break-words">{{ courant.caption }}</span>
      <span class="text-[11px] text-attenue">
        Ajouté le <time :datetime="courant.createdAt">{{ horodatageAvecHeure(courant.createdAt) }}</time>
        <template v-if="courant.uploadedByLabel"> — déposé par {{ courant.uploadedByLabel }}</template>
      </span>

      <MessageErreur v-if="erreurParMedia?.[courant.id]" :erreur="erreurParMedia[courant.id]" />

      <BoutonBase
        v-if="peutRemettre && courant.status === 'remplace'"
        variante="secondaire"
        :chargement="enCours === courant.id"
        class="mt-1"
        @click="emit('remettre', courant.id)"
      >
        Remettre comme portrait
      </BoutonBase>

      <div class="mt-0.5 flex flex-wrap items-center justify-center gap-2">
        <BoutonBase
          v-if="pivotable?.(courant)"
          variante="discret"
          aria-label="Pivoter d’un quart de tour"
          @click="emit('pivoter', courant.id)"
        >
          ↻ Pivoter
        </BoutonBase>
        <template v-if="supprimable">
          <BoutonBase v-if="!confirmation" variante="discret" @click="confirmation = true">Supprimer</BoutonBase>
          <template v-else>
            <span class="text-[11px] text-attenue">Supprimer définitivement ?</span>
            <BoutonBase variante="danger" @click="emit('supprimer', courant.id)">Oui</BoutonBase>
            <BoutonBase variante="discret" @click="confirmation = false">Non</BoutonBase>
          </template>
        </template>
      </div>
    </div>
  </div>
</template>
