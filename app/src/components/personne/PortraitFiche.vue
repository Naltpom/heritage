<script setup lang="ts">
/**
 * Le portrait **à la taille de la page** : 88 × 88 sur desktop, 64 × 64 sur
 * mobile, rayon 10.
 *
 * Quand la fiche porte un portrait validé, c'est `PortraitPersonne` — la
 * vignette, jamais l'original, et un clic qui demande la loupe à la vue (une
 * seule image d'origine décodée à la fois). Ce composant ne fait que le
 * **dimensionner** : `PortraitPersonne` garde sa taille propre pour l'aperçu
 * d'administration, et la page la réduit par un sélecteur descendant plutôt que
 * d'en dériver une copie.
 *
 * Sans portrait — ou sur une fiche réduite, qui n'en porte pas —, une case
 * vide teintée du sexe, comme les cartes de l'arbre. Elle est décorative
 * (`aria-hidden`) : elle n'annonce rien qu'un lecteur d'écran doive entendre.
 */
import type { PortraitDeFiche, Sexe } from '@/lib/api'
import PortraitPersonne from '@/components/medias/PortraitPersonne.vue'
import type { CibleLoupe } from '@/components/medias/medias'

const { portrait, nom, sexe } = defineProps<{
  portrait: PortraitDeFiche | null
  nom: string
  sexe: Sexe
}>()

const emit = defineEmits<{ agrandir: [cible: CibleLoupe] }>()

const TEINTES: Record<string, string> = {
  masculin: 'border-homme-bord bg-homme',
  feminin: 'border-femme-bord bg-femme',
}
</script>

<template>
  <div
    v-if="portrait !== null"
    class="flex-none [&_button]:rounded-[10px] [&_img]:size-[88px] [&_img]:rounded-[10px] max-md:[&_img]:size-16"
  >
    <PortraitPersonne :portrait="portrait" :nom="nom" @agrandir="emit('agrandir', $event)" />
  </div>
  <div
    v-else
    aria-hidden="true"
    class="size-[88px] flex-none rounded-[10px] border border-dashed max-md:size-16"
    :class="TEINTES[sexe] ?? 'border-ligne bg-fond'"
  />
</template>
