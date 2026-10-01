<script setup lang="ts">
/**
 * Les gestes du lien personnel, sur la ligne d'un participant (page du tirage).
 *
 * Deux états, et le libellé du bouton principal en dépend — c'est tout
 * l'enjeu d'une clé qu'on ne garde qu'en empreinte :
 *
 * - **pas de lien** : « Copier son lien » en crée un et le copie. Rien ne meurt ;
 * - **lien actif** : on ne peut plus le relire. Le même geste s'appelle donc
 *   « Régénérer le lien » — il en fabrique un nouveau, et l'ancien cesse de
 *   marcher (la page le fait confirmer). « Révoquer » l'éteint sans le remplacer.
 *
 * Le composant n'appelle rien : il émet, la page décide et confirme. Suite :
 * `actions-du-lien-rendu.test.ts`.
 */
import BoutonBase from '@/components/base/BoutonBase.vue'

const { lienActif, chargement = false, desactive = false } = defineProps<{
  lienActif: boolean
  chargement?: boolean
  desactive?: boolean
}>()

const emit = defineEmits<{ copier: []; revoquer: [] }>()
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <span
      class="rounded-pastille px-2 py-0.5 text-xs"
      :class="lienActif ? 'bg-accent-doux font-semibold text-accent' : 'border border-ligne text-attenue'"
    >
      {{ lienActif ? 'Lien actif' : 'Pas de lien' }}
    </span>
    <BoutonBase variante="secondaire" :chargement="chargement" :desactive="desactive" @click="emit('copier')">
      {{ lienActif ? 'Régénérer le lien' : 'Copier son lien' }}
    </BoutonBase>
    <BoutonBase v-if="lienActif" variante="discret" :desactive="desactive || chargement" @click="emit('revoquer')">
      Révoquer
    </BoutonBase>
  </div>
</template>
