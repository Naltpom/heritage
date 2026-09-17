<script setup lang="ts">
/**
 * La barre d'outils des anniversaires : les cinq pastilles de genre, et ce que
 * le calendrier ne peut pas montrer.
 *
 * ────────────────────────────────────────────────────────────────────
 * **ELLE N'A AUCUN ÉTAT, ET AUCUN DÉCOR EXTÉRIEUR.** Les deux conditions posées
 * par `TiroirOutils` : tout vit dans la vue, et la bordure comme le fond sont
 * `max-md`-annulés, parce que sous le seuil c'est le tiroir qui porte le décor.
 * Un `flex flex-wrap` nu entre dans le tiroir ; une barre qui retient son propre
 * état n'y entre pas, parce que son emplacement cesserait d'être une simple
 * question de mise en page.
 *
 * **Les deux flèches de mois ne sont pas ici.** Elles sont dans `EnTeteMois`,
 * qui explique pourquoi.
 *
 * **« 166 événements sans jour connu » n'est pas un ornement.** Le serveur
 * compte à part ce dont personne n'a noté le jour. Sans cette ligne, un lecteur
 * qui voit trente et une naissances en septembre lit la grille comme un
 * inventaire complet — alors qu'il en manque cinq fois plus, dans une charge où
 * ils sont pourtant comptés. Le `<details>` est replié : le nombre suffit à
 * savoir qu'il y a un trou, le détail sert à savoir lequel.
 *
 * Le total de tête ne compte que les genres **allumés**, pour rester le
 * compagnon de ce qui est à l'écran, et le détail s'additionne exactement à ce
 * total. Ce que les pastilles éteintes retiennent encore est annoncé sur sa
 * propre ligne, dans le détail — jamais fondu dans le total, jamais tu.
 *
 * **Ce que cela coûte sous `md` :** la ligne part dans le tiroir avec le reste
 * de la barre, et ne se lit donc qu'en l'ouvrant. C'est le prix du tiroir, payé
 * par tous les écrans ; la contrepartie est que le nombre reste collé aux
 * pastilles qui le font varier, ce qui est la seule façon de le comprendre.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import type { GenreEvenement, ResumeAnniversaires } from '@/lib/api'
import {
  GENRES,
  compterRetenus,
  genreDisponible,
  titreDeLaPastille,
} from '@/components/evenements/filtres'
import PastilleFiltre from '@/components/evenements/PastilleFiltre.vue'
import {
  compterSansJour,
  compterSansJourMasques,
  detailSansJour,
} from './regroupement'

const { actifs, resume, chargement = false } = defineProps<{
  actifs: ReadonlySet<GenreEvenement>
  /** `null` tant que la charge n'est pas arrivée : les pastilles restent alors
   *  toutes disponibles — voir `genreDisponible()`. */
  resume: ResumeAnniversaires | null
  chargement?: boolean
}>()

const emit = defineEmits<{ genre: [genre: GenreEvenement] }>()

const parGenre = computed(() => resume?.parGenre ?? null)
const sansJour = computed(() => resume?.sansJour ?? null)

const retenus = computed(() => compterRetenus(parGenre.value, actifs))
const manquants = computed(() => compterSansJour(sansJour.value, actifs))
const detail = computed(() => detailSansJour(sansJour.value, actifs))
const masques = computed(() => compterSansJourMasques(sansJour.value, actifs))

const LIBELLES = computed(() => new Map(GENRES.map((genre) => [genre.id, genre.libelle])))

function libelleDuGenre(genre: GenreEvenement): string {
  return LIBELLES.value.get(genre) ?? genre
}
</script>

<template>
  <div
    class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-2 border-b border-ligne bg-panneau px-4 py-2 max-md:flex-col max-md:items-stretch max-md:gap-2 max-md:border-b-0 max-md:bg-transparent max-md:px-0 max-md:py-0"
  >
    <div
      class="flex min-w-0 flex-wrap items-center gap-1.5 max-md:flex-col max-md:items-stretch"
      role="group"
      aria-label="Genres d’événements affichés"
    >
      <PastilleFiltre
        v-for="genre in GENRES"
        :key="genre.id"
        :libelle="genre.libelle"
        :actif="actifs.has(genre.id)"
        :disponible="genreDisponible(parGenre, genre.id)"
        :titre="titreDeLaPastille(genre, parGenre)"
        @basculer="emit('genre', genre.id)"
      />
    </div>

    <span class="flex-auto max-md:hidden"></span>

    <span v-if="chargement" class="text-xs font-semibold text-accent" aria-live="polite">…</span>

    <span v-else-if="resume !== null" class="tnum text-xs text-attenue">
      {{ retenus }} événement{{ retenus > 1 ? 's' : '' }} daté{{ retenus > 1 ? 's' : '' }}
    </span>

    <details v-if="manquants > 0" class="w-full basis-full text-xs text-attenue">
      <summary class="tnum cursor-pointer">
        {{ manquants }} événement{{ manquants > 1 ? 's' : '' }} n’{{ manquants > 1 ? 'ont' : 'a' }}
        pas de jour connu
      </summary>

      <ul class="mt-1.5 list-disc space-y-0.5 pl-5">
        <li v-for="ligne in detail" :key="ligne.genre" class="tnum">
          {{ libelleDuGenre(ligne.genre) }} — {{ ligne.compte }}
        </li>
        <li v-if="masques > 0" class="tnum">
          et {{ masques }} de plus dans les genres que vous n’affichez pas
        </li>
      </ul>

      <p class="mt-1.5">
        Ces événements sont datés d’une année, parfois d’un mois, mais personne
        n’en a noté le jour : aucune case du calendrier ne peut les porter.
      </p>
    </details>
  </div>
</template>
