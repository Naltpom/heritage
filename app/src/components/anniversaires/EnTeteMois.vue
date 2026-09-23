<script setup lang="ts">
/**
 * Le titre du mois, et les trois commandes qui le changent : ‹ {mois} › et
 * « Aujourd’hui ».
 *
 * ────────────────────────────────────────────────────────────────────
 * **OÙ VIT LA NAVIGATION, ET POURQUOI PAS DANS LE FEUILLET.**
 *
 * Depuis la refonte de l'en-tête (handoff § 2), ce composant n'est plus posé
 * au-dessus de la grille : il est la **vue** de la barre d'écran — l'emplacement
 * `#vue` de `BarreEcran`, via `BarreOutilsAnniversaires`. La grille, elle, ne
 * garde que ses sept en-têtes de colonne.
 *
 * La raison qui le tenait hors de l'ancien tiroir reste entière. Le feuillet
 * range les filtres derrière un bouton et un voile noir sous `md` : c'est ce
 * qu'il faut pour des réglages qu'on fait une fois. Les deux flèches, elles,
 * sont le geste **principal** de cet écran, et on les actionne dix fois de
 * suite en regardant le résultat. Or la vue de `BarreEcran` est justement ce qui
 * **reste visible** sur téléphone, au rang 2 sous l'en-tête : les flèches y
 * sont à portée de pouce (42 px de haut, 44 px de large), jamais sous un voile.
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

const FLECHE =
  'flex h-8 w-8 flex-none cursor-pointer items-center justify-center text-attenue hover:text-texte max-md:h-10 max-md:w-11 max-md:text-lg'
</script>

<template>
  <div class="flex items-center gap-2.5 max-md:w-full">
    <div
      class="flex items-center rounded-[8px] border border-ligne bg-panneau max-md:h-[42px] max-md:flex-1 max-md:bg-fond"
    >
      <button
        type="button"
        :class="FLECHE"
        :title="`Mois précédent — ${titreAvant} (flèche gauche)`"
        :aria-label="`Mois précédent — ${titreAvant}`"
        @click="emit('precedent')"
      >
        ‹
      </button>

      <h2
        class="min-w-[120px] px-2.5 text-center text-[13.5px] font-semibold capitalize max-md:flex-1 max-md:text-[15px]"
        aria-live="polite"
      >
        {{ titre }}
      </h2>

      <button
        type="button"
        :class="FLECHE"
        :title="`Mois suivant — ${titreApres} (flèche droite)`"
        :aria-label="`Mois suivant — ${titreApres}`"
        @click="emit('suivant')"
      >
        ›
      </button>
    </div>

    <button
      type="button"
      class="flex-none cursor-pointer text-[13px] font-semibold text-accent hover:underline max-md:h-[42px] max-md:px-1"
      title="Revenir au mois courant (touche Origine)"
      @click="emit('aujourdhui')"
    >
      Aujourd’hui
    </button>
  </div>
</template>
