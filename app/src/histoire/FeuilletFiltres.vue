<script setup lang="ts">
/**
 * Le feuillet « Filtres » de l'arbre, dans la barre d'écran : format des
 * fiches, branches, famille du conjoint, familles par alliance, fratries, et les
 * bascules « Afficher seulement ».
 *
 * Les filtres **estompent**, ils ne retirent rien ; seules les bascules du
 * conjoint et des alliances retirent avant la disposition. Le feuillet ne décide
 * rien : il montre l'état que l'écran lui donne et lui remonte chaque geste.
 */
import ReglageFormatDesCartes from '@/components/arbre/ReglageFormatDesCartes.vue'
import { attenuer, couleurBranche } from './branches'
import type { BrancheDeQuart, Quart } from './lignees'

export type BasculeAffichage = 'ligneDirecte' | 'vivants'

defineProps<{
  quarts: Quart[]
  masquees: BrancheDeQuart[]
  aUneFamilleDeConjoint: boolean
  avecConjoint: boolean
  aDesAlliances: boolean
  avecAlliances: boolean
  effectifAlliances: number
  alliancesDansLArbre: boolean
  fratriesParNaissance: boolean
  ligneDirecte: boolean
  vivants: boolean
  libelleLigne: string
}>()

const emit = defineEmits<{
  basculerBranche: [branche: BrancheDeQuart]
  basculerConjoint: []
  basculerAlliances: []
  rangerAlliances: [dansLArbre: boolean]
  basculerFratries: []
  basculer: [cle: BasculeAffichage]
  aide: []
}>()

const OPTIONS_ALLIANCES = [
  { dans: false, libelle: 'À part', sous: 'chacune son bloc, à droite' },
  { dans: true, libelle: 'Dans mon arbre', sous: 'dans la branche qui les relie' },
] as const

const BASCULES = [
  { cle: 'ligneDirecte', libelle: 'Ligne directe', sous: 'ancêtres et descendants' },
  { cle: 'vivants', libelle: 'Personnes vivantes', sous: '' },
] as const
</script>

<template>
  <!-- Pas un filtre : la forme des fiches. Ni dans « Filtres · n », ni dans « Réinitialiser ». -->
  <ReglageFormatDesCartes />
  <div v-if="quarts.length" class="mt-1 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Branches</div>
  <div class="grid grid-cols-2 gap-2">
    <button
      v-for="q in quarts"
      :key="q.branche"
      type="button"
      class="flex min-h-11 items-center gap-2 rounded-champ border px-2.5 py-1.5 text-left"
      :style="
        masquees.includes(q.branche)
          ? undefined
          : { borderColor: couleurBranche(q.branche), background: `color-mix(in srgb, ${couleurBranche(q.branche)} 12%, transparent)` }
      "
      :class="masquees.includes(q.branche) ? 'border-ligne text-attenue' : ''"
      :aria-pressed="!masquees.includes(q.branche)"
      @click="emit('basculerBranche', q.branche)"
    >
      <!-- Masquée : la pastille pâlit dans sa couleur même, sans `opacity` (ADR 0007). -->
      <span
        class="h-2.5 w-2.5 flex-none rounded-[3px]"
        :style="{ background: attenuer(couleurBranche(q.branche), masquees.includes(q.branche) ? 0.55 : 1) }"
      />
      <span class="flex flex-col leading-tight">
        <span class="text-[13px] font-semibold">{{ q.nom || '—' }}</span>
        <span class="text-[11px] text-attenue">{{ q.libelle }}</span>
      </span>
    </button>
  </div>
  <template v-if="aUneFamilleDeConjoint">
    <div class="mt-1 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Famille du conjoint</div>
    <button
      type="button"
      role="checkbox"
      :aria-checked="avecConjoint"
      class="flex min-h-11 items-center gap-3 border-b border-ligne/60 px-0.5 text-left"
      @click="emit('basculerConjoint')"
    >
      <span
        class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
        :class="avecConjoint ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
      >{{ avecConjoint ? '✓' : '' }}</span>
      <span class="flex-1 text-sm">Afficher sa famille</span>
      <span class="text-xs text-attenue">masquée, l’arbre se resserre</span>
    </button>
  </template>
  <template v-if="aDesAlliances">
    <div class="mt-1 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Familles par alliance</div>
    <button
      type="button"
      role="checkbox"
      :aria-checked="avecAlliances"
      class="flex min-h-11 items-center gap-3 border-b border-ligne/60 px-0.5 text-left"
      @click="emit('basculerAlliances')"
    >
      <span
        class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
        :class="avecAlliances ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
      >{{ avecAlliances ? '✓' : '' }}</span>
      <span class="flex flex-1 flex-col leading-tight">
        <span class="text-sm">Les afficher · {{ effectifAlliances }}</span>
        <span class="text-xs text-attenue">les familles des conjoints de vos proches</span>
      </span>
    </button>
    <div v-if="avecAlliances" class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Où afficher les familles par alliance">
      <button
        v-for="o in OPTIONS_ALLIANCES"
        :key="o.libelle"
        type="button"
        role="radio"
        :aria-checked="alliancesDansLArbre === o.dans"
        class="flex min-h-11 flex-col justify-center rounded-champ border px-2.5 py-1.5 text-left leading-tight"
        :class="alliancesDansLArbre === o.dans ? 'border-accent bg-accent-doux' : 'border-ligne'"
        @click="emit('rangerAlliances', o.dans)"
      >
        <span class="text-[13px] font-semibold">{{ o.libelle }}</span>
        <span class="text-[11px] text-attenue">{{ o.sous }}</span>
      </button>
    </div>
  </template>
  <div class="mt-1 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Fratries</div>
  <button
    type="button"
    role="checkbox"
    :aria-checked="fratriesParNaissance"
    class="flex min-h-11 items-center gap-3 border-b border-ligne/60 px-0.5 text-left"
    @click="emit('basculerFratries')"
  >
    <span
      class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
      :class="fratriesParNaissance ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
    >{{ fratriesParNaissance ? '✓' : '' }}</span>
    <span class="flex flex-1 flex-col leading-tight">
      <span class="text-sm">Par ordre de naissance</span>
      <span class="text-xs text-attenue">l’aîné à gauche, le plus jeune à droite</span>
    </span>
  </button>
  <div class="mt-1 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Afficher seulement</div>
  <button
    v-for="t in BASCULES"
    :key="t.cle"
    type="button"
    role="checkbox"
    :aria-checked="t.cle === 'ligneDirecte' ? ligneDirecte : vivants"
    class="flex min-h-11 items-center gap-3 border-b border-ligne/60 px-0.5 text-left"
    @click="emit('basculer', t.cle)"
  >
    <span
      class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
      :class="(t.cle === 'ligneDirecte' ? ligneDirecte : vivants) ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
    >{{ (t.cle === 'ligneDirecte' ? ligneDirecte : vivants) ? '✓' : '' }}</span>
    <span class="flex-1 text-sm">{{ t.cle === 'ligneDirecte' ? libelleLigne : t.libelle }}</span>
    <span class="text-xs text-attenue">{{ t.sous }}</span>
  </button>
  <p class="m-0 text-xs text-attenue">
    Les autres fiches restent visibles, estompées.
  </p>
  <button
    type="button"
    data-aide-filtres
    class="flex min-h-11 items-center gap-2 self-start text-left text-sm text-accent hover:underline"
    @click="emit('aide')"
  >
    <span class="flex size-5 flex-none items-center justify-center rounded-full border-[1.5px] border-current text-[11px] font-bold" aria-hidden="true">?</span>
    Voir la vidéo : comment marchent les filtres
  </button>
</template>
