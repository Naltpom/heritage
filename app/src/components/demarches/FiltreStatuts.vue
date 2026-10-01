<script setup lang="ts">
/**
 * Le filtre de la page « Actes demandés » : **tous les statuts, cochables
 * ensemble**, chacun avec son compteur.
 *
 * Deux raccourcis d'abord, parce que ce sont les deux questions qu'on vient
 * poser : « qu'est-ce qui attend ? » (envoyées, relancées, expédiées par
 * courrier) et « tout ». Puis une pastille par statut, à bascule : on coche
 * « Refusée » et « Abandonnée » pour voir ce qui est à refaire autrement.
 *
 * Mobile d'abord : les pastilles passent à la ligne plutôt que de défiler, et
 * chacune est une cible de doigt (`min-h-9`). L'état se lit par `aria-pressed`,
 * pas seulement par la couleur.
 *
 * Le composant ne garde rien : la sélection vit dans l'adresse (`?statuts=`),
 * c'est la vue qui la lit et l'écrit.
 */
import type { StatutDeDemarche } from '@/lib/api'
import {
  STATUTS,
  STATUTS_EN_ATTENTE,
  TOUS_LES_STATUTS,
  basculerStatut,
  compteDe,
  memesStatuts,
  type ComptesDeDemarches,
} from './demarches'

const { selection, comptes = null } = defineProps<{
  selection: readonly StatutDeDemarche[]
  /** `null` tant que la liste n'est pas chargée : les pastilles s'affichent sans compteur. */
  comptes?: ComptesDeDemarches | null
}>()

const emit = defineEmits<{ 'update:selection': [statuts: StatutDeDemarche[]] }>()

const raccourcis: ReadonlyArray<{ libelle: string; statuts: readonly StatutDeDemarche[] }> = [
  { libelle: 'En attente', statuts: STATUTS_EN_ATTENTE },
  { libelle: 'Toutes', statuts: TOUS_LES_STATUTS },
]

const classeActive = 'border-accent bg-accent-doux font-semibold text-texte'
const classeInactive = 'border-ligne bg-fond text-attenue hover:text-texte'
</script>

<template>
  <div class="flex flex-col gap-2" role="group" aria-label="Filtrer les démarches par statut">
    <div class="flex flex-wrap gap-2 text-sm">
      <button
        v-for="r in raccourcis"
        :key="r.libelle"
        type="button"
        class="min-h-9 rounded-champ border px-3 py-1"
        :class="memesStatuts(selection, r.statuts) ? classeActive : classeInactive"
        :aria-pressed="memesStatuts(selection, r.statuts)"
        @click="emit('update:selection', [...r.statuts])"
      >
        {{ r.libelle }}<template v-if="comptes !== null"> ({{ compteDe(comptes, r.statuts) }})</template>
      </button>
    </div>
    <ul class="m-0 flex list-none flex-wrap gap-1.5 p-0 text-xs">
      <li v-for="s in STATUTS" :key="s.valeur">
        <button
          type="button"
          class="min-h-9 rounded-pastille border px-3 py-1"
          :class="selection.includes(s.valeur) ? classeActive : classeInactive"
          :aria-pressed="selection.includes(s.valeur)"
          :title="s.libelle"
          :data-statut="s.valeur"
          @click="emit('update:selection', basculerStatut(selection, s.valeur))"
        >
          {{ s.court }}<template v-if="comptes !== null"> ({{ comptes.parStatut[s.valeur] }})</template>
        </button>
      </li>
    </ul>
  </div>
</template>
