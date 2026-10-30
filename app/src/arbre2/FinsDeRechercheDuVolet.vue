<script setup lang="ts">
/**
 * Les côtés **clos** de la personne choisie, dans le volet de la fiche (PC) et le panneau
 * du téléphone de `/arbre` : l'icône « mur », « Recherche close côté père », le motif, le
 * texte, « close le … par … » (ADR 0023 § 8). Rien quand aucun côté n'est clos. Pas de
 * saisie ici : on modifie depuis la fiche (le lien « Fiche » existe déjà).
 *
 * Un composant à part : le canevas de l'écran n'écrit aucun `<svg>` (ADR 0007, règle 3),
 * et le panneau du téléphone en fait partie.
 */
import { TRACE_DU_MUR } from '@/lib/finDeRecherche'

export interface FinDeLaChoisie {
  cote: 'pere' | 'mere'
  titre: string
  motif: string
  texte: string | null
  par: string | null
  le: string | null
}

defineProps<{ fins: FinDeLaChoisie[] }>()
</script>

<template>
  <section v-if="fins.length" class="flex flex-col gap-2" data-fins-de-recherche>
    <div
      v-for="fin in fins"
      :key="fin.cote"
      class="flex gap-2.5 rounded-champ border-2 border-fin-de-recherche-bord bg-fin-de-recherche-doux px-3 py-2"
      data-fin-de-recherche
      :data-cote="fin.cote"
    >
      <svg
        viewBox="0 0 24 24"
        width="18"
        height="18"
        fill="none"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        class="mt-0.5 flex-none"
        :style="{ stroke: 'var(--h-fin-de-recherche)' }"
      >
        <path :d="TRACE_DU_MUR" />
      </svg>
      <div class="flex min-w-0 flex-col gap-0.5 text-sm leading-snug">
        <span class="font-semibold">{{ fin.titre }}</span>
        <span>{{ fin.motif }}</span>
        <span v-if="fin.texte" class="text-attenue">{{ fin.texte }}</span>
        <span v-if="fin.le || fin.par" class="text-xs text-attenue">
          close<template v-if="fin.le"> le {{ fin.le }}</template><template v-if="fin.par"> par {{ fin.par }}</template>
        </span>
      </div>
    </div>
  </section>
</template>
