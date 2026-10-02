<script setup lang="ts">
/**
 * Le cadre des écrans d'entrée : connexion et inscription.
 *
 * Ces écrans sont rendus **sans le cadre applicatif** (`meta.cadre === false`) —
 * un en-tête portant « Se déconnecter » sur une page de connexion serait au
 * mieux étrange. Ils ont donc besoin de porter eux-mêmes le nom du produit, sans
 * quoi une personne invitée arrive sur un formulaire qui ne dit pas de quoi il
 * est la porte.
 *
 * C'est la première impression du produit pour tous ceux qui entrent par une
 * invitation. Le ton s'y règle : on s'adresse à des membres d'une famille, pas à
 * des utilisateurs d'un outil.
 *
 * Le fil des étapes est affiché quand il y en a un. Il ne sert pas à décorer :
 * un parcours en trois temps dont on ne voit pas la fin donne l'impression de ne
 * pas avancer, et c'est là qu'on abandonne.
 */
import { ref } from 'vue'
import { useChampAuDessusDuClavier } from '@/composables/useChampAuDessusDuClavier'

const { titre, sousTitre = '', etape = 0 } = defineProps<{
  titre: string
  sousTitre?: string
  /** 1, 2 ou 3 pour afficher le fil des étapes ; 0 pour ne rien afficher. */
  etape?: 0 | 1 | 2 | 3
}>()

const ETAPES = ['Rattachement', 'Compte', 'Validation'] as const

/*
 * Sur téléphone, le formulaire part du HAUT (centré, il passait sous le clavier) et le
 * champ touché est remonté au-dessus du clavier, dont la hauteur est réservée en bas.
 */
const zone = ref<HTMLElement | null>(null)
useChampAuDessusDuClavier(zone)
</script>

<template>
  <div
    ref="zone"
    class="mx-auto flex min-h-dvh w-full max-w-lg flex-col px-4 py-10 max-md:justify-start max-md:pt-[max(2.5rem,env(safe-area-inset-top))] max-md:pb-[calc(2.5rem+var(--clavier,0px))] md:justify-center"
  >
    <header class="mb-6">
      <p class="text-sm font-semibold tracking-tight text-accent">Heritage</p>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ titre }}</h1>
      <p v-if="sousTitre.length > 0" class="mt-1.5 text-sm text-attenue">{{ sousTitre }}</p>
    </header>

    <!-- Le fil des étapes. `aria-current` porte l'étape en cours : sans lui, un
         lecteur d'écran annonce trois intitulés sans dire où l'on en est. -->
    <nav v-if="etape > 0" class="mb-5" aria-label="Étapes de l’inscription">
      <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
        <li
          v-for="(nom, index) in ETAPES"
          :key="nom"
          class="flex items-center gap-2"
          :aria-current="index + 1 === etape ? 'step' : undefined"
        >
          <span
            class="inline-flex items-center gap-1.5 rounded-pastille border px-2 py-0.5 font-semibold"
            :class="
              index + 1 === etape
                ? 'border-accent bg-accent-doux text-accent'
                : 'border-ligne text-attenue'
            "
          >
            <span aria-hidden="true">{{ index + 1 }}</span>
            {{ nom }}
          </span>
          <span v-if="index < ETAPES.length - 1" class="text-attenue" aria-hidden="true">›</span>
        </li>
      </ol>
    </nav>

    <slot />

    <slot name="pied" />
  </div>
</template>
