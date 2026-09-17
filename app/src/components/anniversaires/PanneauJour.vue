<script setup lang="ts">
/**
 * Le panneau d'un jour choisi : là où l'on **lit**, quand la grille sert à
 * choisir.
 *
 * ────────────────────────────────────────────────────────────────────
 * **IL NE FLOTTE PAS SUR UN TÉLÉPHONE, ET C'EST UNE DIVERGENCE ASSUMÉE.**
 *
 * L'arbre et la chronologie posent leur fiche en `absolute top-3.5 right-3.5`,
 * quelle que soit la largeur. Ici, non : sous `md`, ce panneau est dans le flux
 * et descend **sous** la grille.
 *
 * La raison est géométrique. Sur un écran de 360 px, une carte de 22 rem est
 * plus large que la fenêtre, et une carte ramenée à la fenêtre couvre les sept
 * colonnes de bout en bout. Le lecteur ouvrirait donc un jour et perdrait le
 * calendrier — c'est-à-dire l'objet même de l'écran, et le seul moyen d'en
 * ouvrir un autre. Sur l'arbre, une fiche flottante cache une portion de canevas
 * qu'un glissement ramène ; ici il n'y a rien à ramener.
 *
 * Au-delà de `md`, le panneau reprend la position de la maison, parce qu'il y a
 * la place et que l'habitude en vaut la peine. Le positionnement est porté par
 * le `<div>` enveloppant et non par `PanneauBase`, qui ne reçoit qu'`ombre` :
 * c'est le partage de `ChronologieView`, et il permet de changer l'un sans
 * toucher à l'autre.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Le titre est une coordonnée, pas une date d'événement.** « 14 mars » se
 * compose ici à partir de la case. Les dates des événements, elles, sont
 * recopiées du serveur sans jamais être reformées — voir `LigneEvenement`.
 */
import { computed } from 'vue'
import type { EvenementAnniversaire } from '@/lib/api'
import PanneauBase from '@/components/base/PanneauBase.vue'
import type { CaseJour } from './calendrier'
import { libelleDeDate } from './mois'
import LigneEvenement from './LigneEvenement.vue'

const { cellule, evenements, anneeAffichee } = defineProps<{
  cellule: CaseJour
  evenements: readonly EvenementAnniversaire[]
  anneeAffichee: number
}>()

const emit = defineEmits<{ fermer: [] }>()

const titre = computed(() => libelleDeDate(cellule.mois, cellule.jour))
</script>

<template>
  <div
    class="mt-3 w-full md:absolute md:top-3.5 md:right-3.5 md:z-10 md:mt-0 md:w-[min(22rem,calc(100%-1.75rem))]"
  >
    <PanneauBase ombre>
      <template #entete>
        <div class="flex items-start justify-between gap-3">
          <h2 class="text-sm font-semibold tracking-tight">{{ titre }}</h2>
          <button
            type="button"
            class="cursor-pointer border-0 bg-transparent p-0 text-lg leading-none text-attenue hover:text-texte"
            aria-label="Fermer"
            @click="emit('fermer')"
          >
            ×
          </button>
        </div>
      </template>

      <ul class="flex list-none flex-col gap-3">
        <li v-for="evenement in evenements" :key="evenement.cle">
          <LigneEvenement :evenement="evenement" :annee-affichee="anneeAffichee" />
        </li>
      </ul>
    </PanneauBase>
  </div>
</template>
