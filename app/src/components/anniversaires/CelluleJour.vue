<script setup lang="ts">
/**
 * Une case du calendrier : un jour, et ce qu'il porte.
 *
 * ────────────────────────────────────────────────────────────────────
 * CE QUI SE DÉCIDE ICI.
 *
 * 1. **C'est un `<button>`, pas un `<div>` cliquable.** Le clavier, le focus,
 *    `Entrée` et `Espace` viennent avec, et `aria-pressed` dit au lecteur
 *    d'écran laquelle est ouverte. Un `<div @click>` aurait demandé quatre
 *    attributs et un gestionnaire de touches pour arriver au même point, moins
 *    bien.
 *
 * 2. **`aria-label` explicite, parce que le contenu visible ne suffit pas.** La
 *    case peint « 14 » et trois noms tronqués ; elle s'annonce « 14 mars —
 *    3 anniversaires ». Sans cela, un lecteur d'écran parcourant la grille
 *    énonce quarante-deux nombres nus.
 *
 * 3. **Une case sans rien est `disabled`.** Elle reste peinte — la grille garde
 *    sa forme — mais elle sort du parcours clavier : sans cela, atteindre le
 *    14 mars demande quatorze tabulations dans le vide.
 *
 * 4. **Aucun style de fin de semaine.** Samedi et dimanche sont des jours comme
 *    les autres : ceci est un almanach de famille, pas un agenda de bureau, et
 *    une naissance ne vaut pas moins un dimanche.
 *
 * 5. **Sous `sm`, la liste des noms disparaît et un pastillon la remplace.** À
 *    340 px, sept colonnes laissent 44 px par case : un nom y devient une
 *    bouillie de trois lettres. La grille redevient ce qu'elle est sur un
 *    téléphone — un **sélecteur** — et la lecture se fait dans le panneau.
 *
 * **Le titre de la case n'est pas une date d'événement.** « 14 mars » est
 * composé ici à partir de coordonnées de calendrier. L'interdiction du § 14
 * porte sur la date **d'un événement**, que le serveur écrit dans `texte` et que
 * `PanneauJour` recopie sans jamais la reformer.
 *
 * **Aucun `<ul>` ici.** Le contenu d'un `<button>` est du contenu de phrase :
 * une liste y est du balisage invalide, que les navigateurs rattrapent chacun à
 * leur façon. Des `<span class="block">` donnent la même colonne sans le pari.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import type { EvenementAnniversaire } from '@/lib/api'
import type { CaseJour } from './calendrier'
import { libelleDeDate } from './mois'

/** Trois noms, et « +n » au-delà. Quatre lignes de texte ne tiennent pas dans
 *  une case carrée à la largeur d'un septième d'écran. */
const NOMS_VISIBLES = 3

const {
  cellule,
  evenements,
  selectionnee = false,
} = defineProps<{
  cellule: CaseJour
  /** Vide pour les jours empruntés aux mois voisins : la grille du mois affiché
   *  ne montre que ses propres événements. */
  evenements: readonly EvenementAnniversaire[]
  selectionnee?: boolean
}>()

const emit = defineEmits<{ choisir: [cle: string] }>()

const vide = computed(() => evenements.length === 0)

/** Une union porte deux sujets : le jour d'un mariage montre donc deux noms pour
 *  un seul événement. C'est bien ce qu'on veut lire. */
const noms = computed(() => evenements.flatMap((e) => e.sujets.map((s) => s.displayName)))
const nomsVisibles = computed(() => noms.value.slice(0, NOMS_VISIBLES))
const nomsCaches = computed(() => noms.value.length - nomsVisibles.value.length)

/** Le compte annoncé est celui des **événements**, pas des noms : « 3
 *  anniversaires » et non « 4 anniversaires » parce que l'un d'eux est un
 *  mariage. Le pastillon compte la même chose, pour que les deux s'accordent. */
const libelle = computed(() => {
  const date = libelleDeDate(cellule.mois, cellule.jour)
  const compte = evenements.length
  if (compte === 0) return `${date} — aucun anniversaire`
  return `${date} — ${compte} anniversaire${compte > 1 ? 's' : ''}`
})

const apparence = computed<string[]>(() => {
  if (!cellule.dansLeMois) return ['border-transparent', 'bg-transparent', 'text-attenue', 'opacity-45']

  const classes = [cellule.aujourdhui ? 'border-accent font-semibold' : 'border-ligne']
  classes.push(selectionnee ? 'bg-accent-doux' : 'bg-panneau')
  if (!vide.value) classes.push('cursor-pointer', 'hover:border-accent')
  return classes
})
</script>

<template>
  <button
    type="button"
    class="flex w-full min-w-0 flex-col items-start gap-0.5 overflow-hidden rounded-champ border p-1 text-left disabled:cursor-default max-md:aspect-square sm:p-1.5"
    :class="apparence"
    :aria-pressed="selectionnee"
    :aria-label="libelle"
    :disabled="vide"
    @click="emit('choisir', cellule.cle)"
  >
    <span class="flex w-full items-center justify-between gap-1">
      <span class="tnum text-xs leading-none">{{ cellule.jour }}</span>
      <span
        v-if="!vide"
        class="tnum rounded-pastille bg-accent-doux px-1 text-[10px] leading-tight font-semibold text-texte sm:hidden"
      >
        {{ evenements.length }}
      </span>
    </span>

    <span v-if="!vide" class="hidden w-full min-w-0 sm:block">
      <span
        v-for="(nom, index) in nomsVisibles"
        :key="index"
        class="block truncate text-xs text-attenue"
      >
        {{ nom }}
      </span>
      <span v-if="nomsCaches > 0" class="tnum block truncate text-xs text-attenue">
        +{{ nomsCaches }}
      </span>
    </span>
  </button>
</template>
