<script setup lang="ts">
/**
 * La grille d'un mois : sept en-têtes, quarante-deux cases.
 *
 * ────────────────────────────────────────────────────────────────────
 * CE QUI SE DÉCIDE ICI.
 *
 * 1. **Un mois vide le dit, et garde sa grille.** Une ligne explicite paraît
 *    au-dessus des cases — jamais quarante-deux cases muettes, qu'on prend pour
 *    une panne. Mais la grille reste : la remplacer par un panneau ferait
 *    changer la hauteur de tout l'écran entre deux mois, c'est-à-dire
 *    exactement le défaut que les six rangs fixes de `calendrier.ts`
 *    empêchent, ramené par la porte de l'état vide.
 *
 *    C'est aussi pourquoi cette décision est **ici** et non dans la vue : elle
 *    dépend de la grille et de la table, qui sont les deux entrées de ce
 *    composant, et le calcul qui la porte — `compterSurLaGrille()` — a sa suite
 *    de tests. Une vue qui la reprendrait la referait à sa façon.
 *
 * 2. **Pas de `role="grid"`.** Une grille ARIA exige `role="row"`,
 *    `role="gridcell"` et un `tabindex` mobile de case en case ; à moitié faite,
 *    elle est **pire** qu'absente, parce qu'elle promet une navigation qui
 *    n'existe pas. Un groupe nommé de boutons, chacun portant sa date complète,
 *    est correct tel quel et se parcourt au clavier sans qu'on écrive rien.
 *
 * 3. **Les en-têtes de colonne sont `aria-hidden`.** « L M M J V S D » lu à voix
 *    haute n'apprend rien, et chaque case dit déjà « 14 mars ». Les initiales
 *    sont un repère visuel, et le nom complet reste dans le `title` pour la
 *    souris.
 *
 * 4. **Les jours empruntés aux mois voisins ne portent rien.** Ils sont peints
 *    atténués, pour que la semaine reste entière, mais leur contenu n'est pas
 *    interrogé : un panneau montrant un 28 février sous un titre « mars 2026 »
 *    n'aurait aucun moyen de se faire comprendre, et le jour est à une flèche.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import type { EvenementAnniversaire } from '@/lib/api'
import { JOURS_SEMAINE, type CaseJour } from './calendrier'
import { compterSurLaGrille } from './regroupement'
import CelluleJour from './CelluleJour.vue'

const {
  grille,
  jours,
  titre,
  selection = null,
} = defineProps<{
  grille: readonly CaseJour[]
  /** La table `MM-JJ` → événements retenus, telle que `parJour()` la rend. */
  jours: ReadonlyMap<string, readonly EvenementAnniversaire[]>
  /** « mars 2026 » — pour nommer le groupe et pour le dire quand il est vide. */
  titre: string
  selection?: string | null
}>()

const emit = defineEmits<{ choisir: [cle: string] }>()

/** Une seule instance partagée : quarante-deux tableaux vides recréés à chaque
 *  rendu feraient repeindre quarante-deux cases pour rien. */
const AUCUN: readonly EvenementAnniversaire[] = Object.freeze([])

function evenementsDe(cellule: CaseJour): readonly EvenementAnniversaire[] {
  if (!cellule.dansLeMois) return AUCUN
  return jours.get(cellule.cle) ?? AUCUN
}

const vide = computed(() => compterSurLaGrille(grille, jours) === 0)
</script>

<template>
  <div>
    <p v-if="vide" class="pb-2 text-xs text-attenue" role="status">
      Aucun anniversaire connu en {{ titre }} parmi les genres affichés.
    </p>

    <div class="grid grid-cols-7 gap-1 pb-1" aria-hidden="true">
      <span
        v-for="(jourSemaine, index) in JOURS_SEMAINE"
        :key="index"
        class="text-center text-[11px] font-semibold text-attenue"
        :title="jourSemaine.long"
      >
        {{ jourSemaine.court }}
      </span>
    </div>

    <div class="grid grid-cols-7 gap-1" role="group" :aria-label="`Calendrier de ${titre}`">
      <CelluleJour
        v-for="cellule in grille"
        :key="`${cellule.annee}-${cellule.cle}`"
        :cellule="cellule"
        :evenements="evenementsDe(cellule)"
        :selectionnee="cellule.dansLeMois && cellule.cle === selection"
        @choisir="emit('choisir', $event)"
      />
    </div>
  </div>
</template>
