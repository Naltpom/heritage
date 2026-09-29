<script setup lang="ts">
/**
 * Le menu ⋯ de la fiche : les gestes secondaires, et **la suppression à part.**
 *
 * Il porte, dans cet ordre :
 *
 * 1. « Ajouter un document » — ouvre l'onglet Preuves et documents, où vit le
 *    formulaire de dépôt de la galerie ;
 * 2. « Proposer avec l'assistant » — ouvre « Proposer » en mode assistant, avec
 *    cette fiche pour contexte (`?mode=assistant&personne=`). Même condition que
 *    les autres propositions (`peutProposer`) : l'analyse est une écriture pour
 *    l'API, refusée sous la bascule comme pour un compte en attente ;
 * 3. **un filet**, puis « Demander la suppression », en couleur d'alerte
 *    (`PointSuppression.vue`). Jamais dans le même bloc que « Proposer une
 *    modification » : ce sont deux gestes, dont un seul détruit.
 *
 * Chaque entrée porte sa propre condition (`gestes.ts`) ; **le menu lui-même
 * n'existe que s'il a au moins une entrée** — un ⋯ qui s'ouvre sur du vide est
 * un bouton qui ment. Aucun test de rôle ici pour décider d'un affichage : on ne
 * cache que des actions vouées au refus.
 *
 * Fermeture : `Échap` (le focus revient sur ⋯), un clic ailleurs, ou le choix
 * d'une entrée. Les écouteurs du document ne sont posés qu'**après le montage**,
 * et seulement tant que le menu est ouvert : le rendu serveur des tests ne touche
 * aucune interface de navigateur.
 */
import { computed, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import { useGestesFiche } from './gestes'
import { requeteDOnglet } from './onglets'
import PointSuppression from './PointSuppression.vue'

const { fiche, sens = 'bas' } = defineProps<{
  fiche: FichePersonneComplete
  /** `haut` : le menu s'ouvre au-dessus du bouton (barre fixée en bas). */
  sens?: 'bas' | 'haut'
}>()

const { peutProposer, peutDeposer, peutDemanderSuppression } = useGestesFiche()

const aDesEntrees = computed(
  () => peutProposer.value || peutDeposer.value || peutDemanderSuppression.value,
)

const ouvert = ref(false)
const racine = useTemplateRef<HTMLElement>('racine')
const bouton = useTemplateRef<HTMLButtonElement>('bouton')

function fermer(rendreFocus = false): void {
  ouvert.value = false
  if (rendreFocus) bouton.value?.focus()
}

function surTouche(evenement: KeyboardEvent): void {
  if (evenement.key === 'Escape') fermer(true)
}

function surClicDocument(evenement: MouseEvent): void {
  const cible = evenement.target
  if (cible instanceof Node && racine.value?.contains(cible)) return
  fermer()
}

watch(ouvert, (estOuvert) => {
  if (typeof document === 'undefined') return
  if (estOuvert) {
    document.addEventListener('keydown', surTouche)
    document.addEventListener('mousedown', surClicDocument)
  } else {
    document.removeEventListener('keydown', surTouche)
    document.removeEventListener('mousedown', surClicDocument)
  }
})

onBeforeUnmount(() => {
  if (typeof document === 'undefined') return
  document.removeEventListener('keydown', surTouche)
  document.removeEventListener('mousedown', surClicDocument)
})
</script>

<template>
  <div v-if="aDesEntrees" ref="racine" class="relative flex-none">
    <button
      ref="bouton"
      type="button"
      class="flex items-center justify-center rounded-champ border border-ligne bg-panneau text-lg leading-none text-attenue transition-colors hover:border-accent hover:text-texte max-md:size-11 md:size-9"
      aria-haspopup="menu"
      :aria-expanded="ouvert"
      aria-label="Autres actions sur cette fiche"
      @click="ouvert = !ouvert"
    >
      <span aria-hidden="true">⋯</span>
    </button>

    <div
      v-if="ouvert"
      role="menu"
      aria-label="Autres actions sur cette fiche"
      class="absolute right-0 z-40 w-64 overflow-hidden rounded-panneau border border-ligne bg-panneau py-1 shadow-panneau"
      :class="sens === 'haut' ? 'bottom-[calc(100%+8px)]' : 'top-[calc(100%+6px)]'"
      @click="fermer()"
    >
      <RouterLink
        v-if="peutDeposer"
        :to="{
          name: 'personne.detail',
          params: { id: fiche.id },
          query: requeteDOnglet('documents'),
        }"
        role="menuitem"
        class="flex min-h-11 items-center px-3.5 text-sm hover:bg-accent-doux md:min-h-10"
      >
        Ajouter un document
      </RouterLink>

      <RouterLink
        v-if="peutProposer"
        :to="{
          name: 'proposition.creation',
          query: { mode: 'assistant', personne: fiche.id },
        }"
        role="menuitem"
        class="flex min-h-11 items-center px-3.5 text-sm hover:bg-accent-doux md:min-h-10"
      >
        Proposer avec l’assistant
      </RouterLink>

      <!-- Le filet est porté par `PointSuppression` : il n'existe que si la
           suppression est offerte, et la sépare de tout ce qui précède. -->
      <PointSuppression :fiche="fiche" />
    </div>
  </div>
</template>
