<script setup lang="ts">
/**
 * Les documents joints à l'analyse : **Importer**, **Prendre en photo** et
 * **Enregistrer** (un témoignage audio, voir `EnregistrementAudio.vue`).
 *
 * Deux champs de fichier cachés, deux boutons visibles. « Prendre en photo »
 * pose `capture="environment"` : sur téléphone, l'appareil photo arrière s'ouvre
 * directement sur l'acte à photographier ; sur ordinateur, l'attribut est ignoré
 * et le sélecteur de fichiers s'ouvre — rien n'est cassé, rien n'est à cacher.
 * Les deux s'ouvrent par `click()` **dans le gestionnaire d'un vrai clic** : un
 * navigateur refuse d'ouvrir un sélecteur hors d'un geste de l'utilisateur.
 *
 * Le composant ne garde rien : la liste, les aperçus (URL d'objet) et leur
 * révocation vivent chez le parent, qui est seul à savoir quand un document
 * sert encore — il accompagne aussi le dépôt des preuves.
 */
import { computed, useTemplateRef } from 'vue'
import { tailleLisible } from '@/components/medias/medias'
import { dureeMesuree, rappelDesBornes } from './assistant'
import EnregistrementAudio from './EnregistrementAudio.vue'
import LecteurAudio from './LecteurAudio.vue'

export interface DocumentAffiche {
  cle: number
  fichier: File
  /** URL d'objet d'une image ou d'un enregistrement, ou `null` (PDF, ou type inconnu). */
  apercu: string | null
  pdf: boolean
  audio: boolean
}

const { documents, refus, desactive = false } = defineProps<{
  documents: readonly DocumentAffiche[]
  /** Les refus locaux de la dernière sélection (taille, nombre). */
  refus: readonly string[]
  desactive?: boolean
}>()

const emit = defineEmits<{
  ajouter: [fichiers: File[]]
  retirer: [cle: number]
  /** Un quart de tour horaire — c'est le parent qui détient le fichier. */
  pivoter: [cle: number]
}>()

/** Les enregistrements, à réécouter avant d'analyser : leur rang reste celui de la grille. */
const ecoutes = computed(() =>
  documents
    .map((document, index) => ({ document, rang: index + 1 }))
    .filter(({ document }) => document.audio && document.apercu !== null),
)

const champImport = useTemplateRef<HTMLInputElement>('champImport')
const champPhoto = useTemplateRef<HTMLInputElement>('champPhoto')

function recevoir(evenement: Event): void {
  const cible = evenement.target as HTMLInputElement
  const fichiers = Array.from(cible.files ?? [])
  // Vidé pour qu'un même fichier puisse être choisi de nouveau après un retrait.
  cible.value = ''
  if (fichiers.length > 0) emit('ajouter', fichiers)
}
</script>

<template>
  <div class="flex flex-col gap-2.5">
    <div class="flex flex-wrap gap-2 max-md:grid max-md:grid-cols-2">
      <button
        type="button"
        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-champ border border-ligne bg-panneau px-3.5 text-sm font-semibold text-texte transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-55 md:min-h-9"
        :disabled="desactive"
        @click="champImport?.click()"
      >
        <span aria-hidden="true" class="max-md:hidden">⤒</span>
        Importer
      </button>
      <button
        type="button"
        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-champ border border-ligne bg-panneau px-3.5 text-sm font-semibold text-texte transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-55 md:min-h-9"
        :disabled="desactive"
        @click="champPhoto?.click()"
      >
        <span aria-hidden="true" class="max-md:hidden">◉</span>
        Prendre en photo
      </button>
      <EnregistrementAudio :desactive="desactive" @enregistrement="emit('ajouter', [$event])" />

      <input
        ref="champImport"
        type="file"
        multiple
        accept="image/*,application/pdf,audio/*"
        class="sr-only"
        tabindex="-1"
        aria-hidden="true"
        @change="recevoir"
      />
      <input
        ref="champPhoto"
        type="file"
        accept="image/*"
        capture="environment"
        class="sr-only"
        tabindex="-1"
        aria-hidden="true"
        @change="recevoir"
      />
    </div>

    <p class="m-0 text-xs text-attenue">{{ rappelDesBornes() }}</p>

    <ul v-if="refus.length > 0" class="m-0 flex list-none flex-col gap-1 p-0" role="alert">
      <li
        v-for="(message, index) in refus"
        :key="index"
        class="rounded-champ border border-alerte-bord bg-alerte-doux px-2.5 py-1.5 text-xs"
      >
        {{ message }}
      </li>
    </ul>

    <ul
      v-if="documents.length > 0"
      class="m-0 grid list-none grid-cols-3 gap-2 p-0 sm:grid-cols-4 md:grid-cols-6"
      aria-label="Documents joints"
    >
      <li
        v-for="(document, index) in documents"
        :key="document.cle"
        class="relative overflow-hidden rounded-champ border border-ligne bg-fond"
      >
        <div
          v-if="document.audio"
          class="flex aspect-square w-full flex-col items-center justify-center gap-1 px-1.5 text-center"
        >
          <span class="rounded-pastille border border-accent bg-accent-doux px-2 py-0.5 text-[11px] font-semibold">
            Audio
          </span>
          <span class="line-clamp-2 text-[11px] break-all text-attenue">{{ document.fichier.name }}</span>
        </div>
        <img
          v-else-if="document.apercu !== null"
          :src="document.apercu"
          :alt="`Document ${index + 1} : ${document.fichier.name}`"
          class="aspect-square w-full object-cover"
        />
        <div
          v-else
          class="flex aspect-square w-full flex-col items-center justify-center gap-1 px-1.5 text-center"
        >
          <span class="rounded-pastille border border-ligne bg-panneau px-2 py-0.5 text-[11px] font-semibold">
            {{ document.pdf ? 'PDF' : 'Fichier' }}
          </span>
          <span class="line-clamp-2 text-[11px] break-all text-attenue">{{ document.fichier.name }}</span>
        </div>

        <div class="flex items-center justify-between gap-1 border-t border-ligne px-1.5 py-0.5">
          <span class="text-[10.5px] text-attenue tabular-nums">
            {{ index + 1 }} · {{ tailleLisible(document.fichier.size) }}
          </span>
          <button
            v-if="document.apercu !== null"
            type="button"
            class="flex min-h-7 min-w-7 items-center justify-center text-sm text-attenue hover:text-texte disabled:opacity-55"
            :aria-label="`Pivoter ${document.fichier.name} d’un quart de tour`"
            :disabled="desactive"
            @click="emit('pivoter', document.cle)"
          >
            ↻
          </button>
        </div>

        <!-- Zone de toucher de 44 px, pastille visible plus petite : sur une
             vignette de 80 px, un rond de 44 px masquerait le document. -->
        <button
          type="button"
          class="group absolute top-0 right-0 flex size-11 items-center justify-center disabled:opacity-55 md:size-9"
          :aria-label="`Retirer ${document.fichier.name}`"
          :disabled="desactive"
          @click="emit('retirer', document.cle)"
        >
          <span
            class="flex size-6 items-center justify-center rounded-pastille border border-ligne bg-panneau text-sm leading-none shadow-panneau group-hover:border-alerte group-hover:text-alerte"
            aria-hidden="true"
          >
            ×
          </span>
        </button>
      </li>
    </ul>

    <ul v-if="ecoutes.length > 0" class="m-0 flex list-none flex-col gap-1.5 p-0" aria-label="Réécouter les enregistrements">
      <li v-for="{ document, rang } in ecoutes" :key="document.cle" class="flex min-w-0 items-center gap-2">
        <span class="flex-none text-[11px] text-attenue tabular-nums">{{ rang }}</span>
        <LecteurAudio :src="document.apercu!" :mesuree="dureeMesuree(document.fichier)" />
      </li>
    </ul>
  </div>
</template>
