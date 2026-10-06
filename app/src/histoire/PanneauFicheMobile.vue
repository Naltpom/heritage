<script setup lang="ts">
/**
 * La fiche choisie, sur téléphone : un panneau en bas du canevas — nom, dates,
 * pastilles, ligne directe, « Centrer » et « Fiche ». Le volet du PC n'y tient pas.
 */
import { NOM_D_UNE_PERSONNE_VIVANTE } from '@/lib/api'
import PastillesFiche from './PastillesFiche.vue'
import type { FicheHistoire } from './lignees'

defineProps<{
  fiche: FicheHistoire
  moi: boolean
  couleur: string
  parente: string | null
  ligneDirecte: boolean
}>()

const emit = defineEmits<{
  fermer: []
  centrer: []
  partir: []
  basculerLigne: []
}>()
</script>

<template>
  <section
    class="absolute inset-x-3 bottom-3 z-10 flex flex-col gap-2 rounded-panneau border border-ligne bg-panneau px-4 py-3.5 shadow-[0_10px_30px_rgba(0,0,0,.5)]"
    :style="{ borderTop: `3px solid ${couleur}` }"
    aria-live="polite"
    @pointerdown.stop
  >
    <div class="flex items-start gap-2">
      <div class="flex flex-1 flex-col leading-snug">
        <span class="text-[17px] font-semibold">{{
          fiche.noeud.visibilite === 'restreinte' ? NOM_D_UNE_PERSONNE_VIVANTE : fiche.noeud.displayName
        }}</span>
        <span v-if="typeof fiche.noeud.etiquetteDates === 'string'" class="font-mono text-xs text-attenue">{{
          fiche.noeud.etiquetteDates
        }}</span>
      </div>
      <button type="button" aria-label="Fermer" class="-mt-1.5 -mr-2 h-8 w-8 text-lg text-attenue hover:text-texte" @click="emit('fermer')">
        ×
      </button>
    </div>
    <PastillesFiche :fiche="fiche" :moi="moi" :parente="parente" :branche="fiche.noeud.branch" detache="sans lien connu · position estimée" />
    <button
      type="button"
      role="checkbox"
      :aria-checked="ligneDirecte"
      class="flex min-h-9 items-center gap-2.5 text-left text-sm"
      @click="emit('basculerLigne')"
    >
      <span
        class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
        :class="ligneDirecte ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
      >{{ ligneDirecte ? '✓' : '' }}</span>
      Afficher sa ligne directe
    </button>
    <div class="flex gap-2">
      <button type="button" class="h-9 flex-1 rounded-champ border border-ligne font-semibold hover:bg-accent-doux" @click="emit('centrer')">
        Centrer
      </button>
      <RouterLink
        v-if="fiche.noeud.personId !== null"
        :to="{ name: 'personne.detail', params: { id: fiche.noeud.personId } }"
        class="flex h-9 flex-1 items-center justify-center rounded-champ bg-accent font-semibold text-fond"
        @click="emit('partir')"
      >
        Fiche
      </RouterLink>
      <RouterLink
        v-else-if="fiche.noeud.demandeId !== undefined"
        :to="{ name: 'demande.detail', params: { id: fiche.noeud.demandeId } }"
        class="flex h-9 flex-1 items-center justify-center rounded-champ bg-accent font-semibold text-fond"
        @click="emit('partir')"
      >
        Demande
      </RouterLink>
    </div>
  </section>
</template>
