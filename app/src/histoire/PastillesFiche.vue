<script setup lang="ts">
/**
 * Les pastilles d'une fiche choisie — « vous » ou la parenté, la branche (ou
 * « famille par alliance »), « sans lien connu », « en attente » —, partagées
 * par le volet (PC) et le panneau du bas (téléphone).
 */
import type { FicheHistoire } from './lignees'
import { libelleBranche } from './branches'

defineProps<{
  fiche: FicheHistoire
  /** La fiche est celle du lecteur. */
  moi: boolean
  /** La parenté calculée par le serveur, une fois la fiche lue. */
  parente: string | null
  /** La branche affichée : celle du nœud sur téléphone, celle de la fiche sur PC (comme avant). */
  branche: string
  /** Libellé de « sans lien connu » : plus long sur téléphone, où il n'y a pas d'infobulle. */
  detache: string
}>()
</script>

<template>
  <div class="flex flex-wrap gap-1.5">
    <span v-if="moi" class="rounded-pastille bg-accent-doux px-2.5 py-0.5 text-xs font-semibold text-accent">vous</span>
    <span v-else-if="parente" class="rounded-pastille bg-accent-doux px-2.5 py-0.5 text-xs font-semibold text-accent">{{ parente }}</span>
    <span
      v-if="fiche.parente === 'alliance'"
      class="rounded-pastille border border-dashed border-ligne px-2.5 py-px text-xs text-[var(--hi-gouttiere-texte)]"
      title="Sa famille est liée à la vôtre par un mariage."
    >famille par alliance</span>
    <span v-else class="rounded-pastille border border-ligne px-2.5 py-px text-xs text-[var(--hi-gouttiere-texte)]">{{
      libelleBranche(branche)
    }}</span>
    <span
      v-if="fiche.detache"
      class="rounded-pastille border border-dashed border-ligne px-2.5 py-px text-xs text-attenue"
      title="Sans lien connu avec vous : génération estimée d’après les dates."
    >{{ detache }}</span>
    <span v-if="fiche.noeud.enAttente" class="rounded-pastille border border-accent px-2.5 py-px text-xs text-accent">en attente</span>
  </div>
</template>
