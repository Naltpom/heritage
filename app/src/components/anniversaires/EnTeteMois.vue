<script setup lang="ts">
/**
 * Le titre du mois, et les trois commandes qui le changent.
 *
 * ────────────────────────────────────────────────────────────────────
 * **POURQUOI LA NAVIGATION N'EST PAS DANS LA BARRE D'OUTILS.**
 *
 * `TiroirOutils` range la barre derrière un bouton et un voile noir sous `md` :
 * c'est exactement ce qu'il faut pour des filtres, qu'on règle une fois. Les
 * deux flèches, elles, sont le geste **principal** de cet écran, et on les
 * actionne dix fois de suite en regardant le résultat. Dans le tiroir, chaque
 * pression peindrait un calendrier que le voile cache — ou demanderait
 * d'ouvrir, presser, refermer, regarder, rouvrir.
 *
 * Elles vivent donc ici, à côté du nom du mois qu'elles changent, visibles à
 * toutes les largeurs. C'est le seul point où cet écran s'écarte du partage
 * habituel « tout ce qui règle l'affichage va dans la barre ».
 * ────────────────────────────────────────────────────────────────────
 *
 * **`aria-live="polite"` sur le titre.** Changer de mois ne déplace pas le
 * focus : sans région vive, un lecteur d'écran reste sur la flèche et n'annonce
 * rien. `polite` et non `assertive` — dix pressions de suite ne doivent pas
 * hacher la parole en cours.
 *
 * **Les flèches nomment leur destination.** « Mois suivant » ne dit pas où l'on
 * va ; « Mois suivant — avril 2026 » le dit, et rend le passage d'année audible
 * au moment où il se produit.
 */
import { computed } from 'vue'
import { moisPrecedent, moisSuivant, titreDuMois } from './mois'

const { annee, mois } = defineProps<{
  annee: number
  mois: number
}>()

const emit = defineEmits<{
  precedent: []
  suivant: []
  aujourdhui: []
}>()

const titre = computed(() => titreDuMois({ annee, mois }))
const titreAvant = computed(() => titreDuMois(moisPrecedent({ annee, mois })))
const titreApres = computed(() => titreDuMois(moisSuivant({ annee, mois })))

const COMMANDE =
  'cursor-pointer rounded-pastille border border-ligne bg-fond px-2.5 py-1 text-xs text-attenue hover:border-accent'
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-2 pb-2">
    <h2 class="text-base font-semibold tracking-tight" aria-live="polite">{{ titre }}</h2>

    <div class="flex items-center gap-1.5">
      <button
        type="button"
        :class="COMMANDE"
        :title="`Mois précédent — ${titreAvant} (flèche gauche)`"
        :aria-label="`Mois précédent — ${titreAvant}`"
        @click="emit('precedent')"
      >
        ‹
      </button>

      <button
        type="button"
        :class="COMMANDE"
        title="Revenir au mois courant (touche Origine)"
        @click="emit('aujourdhui')"
      >
        Aujourd’hui
      </button>

      <button
        type="button"
        :class="COMMANDE"
        :title="`Mois suivant — ${titreApres} (flèche droite)`"
        :aria-label="`Mois suivant — ${titreApres}`"
        @click="emit('suivant')"
      >
        ›
      </button>
    </div>
  </div>
</template>
