<script setup lang="ts">
/**
 * « Générer l'affiche » : le bouton de la fiche, et sa modale.
 *
 * ## Où il se pose, et pour qui
 *
 * À côté de « Partager », dans le bandeau, sur toutes les largeurs (réduit à son icône sur
 * téléphone) — et non parmi les icônes d'`ActionsFiche`, qui sont des **écritures** et ne
 * s'offrent qu'aux membres rattachés, hors bascule « voir comme un membre ». Une affiche ne
 * modifie rien : elle se tire de ce que le lecteur voit déjà, par une route en lecture, et
 * le serveur y applique le cercle et la réciprocité comme partout. Elle est donc offerte à
 * tout lecteur de la fiche complète.
 *
 * Une affiche est **un couple** : le bouton n'existe que si la personne a au moins une union
 * dont le conjoint est connu. Il se retire en mode édition, comme « Partager ».
 *
 * La modale n'est montée qu'au premier clic, et son code (le placement, le rendu) n'est
 * téléchargé qu'à ce moment : rien n'est lu — ni le code, ni l'arbre, ni les polices — pour
 * qui ne s'en sert pas.
 */
import { computed, defineAsyncComponent, ref } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'

const ModaleAffiche = defineAsyncComponent(() => import('./ModaleAffiche.vue'))

const { fiche } = defineProps<{ fiche: FichePersonneComplete }>()

const disponible = computed(() => fiche.unions.some((u) => u.conjoint !== null))
const ouverte = ref(false)
const montee = ref(false)

function ouvrir(): void {
  montee.value = true
  ouverte.value = true
}
</script>

<template>
  <div v-if="disponible" class="flex-none" data-affiche>
    <BoutonBase
      variante="secondaire"
      class="h-9 max-md:h-11 max-md:w-11 max-md:px-0"
      title="Générer l’affiche généalogique du couple"
      data-geste="affiche"
      @click="ouvrir"
    >
      <svg viewBox="0 0 24 24" class="size-4 flex-none" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 16a9 9 0 0 1 18 0z" />
        <path d="M12 16V7M12 16 5.6 9.6M12 16l6.4-6.4M7.5 16a4.5 4.5 0 0 1 9 0" />
        <path d="M9 20h6" />
      </svg>
      <span class="max-md:sr-only">Générer l’affiche</span>
    </BoutonBase>
    <ModaleAffiche v-if="montee" :fiche="fiche" :ouverte="ouverte" @fermer="ouverte = false" />
  </div>
</template>
