<script setup lang="ts">
/**
 * La barre d'écran des anniversaires : ‹ {mois} › et « Aujourd’hui », les cinq
 * pastilles de genre, le compteur, et ce que le calendrier ne peut pas montrer.
 *
 * Elle est posée dans `BarreEcran` (handoff « En-tête, barres d'écran et
 * fiche », § 2), dans l'ordre commun à tous les écrans :
 *
 *     [ ‹ mois › Aujourd’hui ] | [ 5 genres ] ——— [ n événements datés ] [ n sans jour connu ▾ ]
 *
 * Sur téléphone, la navigation du mois reste visible au rang 2 sous l'en-tête
 * (`#vue`) ; les pastilles et la ligne « sans jour connu » vont dans le
 * feuillet de filtres.
 *
 * ────────────────────────────────────────────────────────────────────
 * **ELLE N'A AUCUN ÉTAT.** Tout vit dans la vue : le mois affiché, les genres
 * allumés, le nombre de filtres actifs. La barre reçoit et émet ; un
 * emplacement qui retiendrait son propre état cesserait d'être une simple
 * question de mise en page.
 *
 * **Les deux flèches de mois sont désormais ici**, par `EnTeteMois`, qui
 * explique pourquoi elles ne vont jamais dans le feuillet.
 *
 * **« 166 sans jour connu » n'est pas un ornement.** Le serveur compte à part
 * ce dont personne n'a noté le jour. Sans cette ligne, un lecteur qui voit
 * trente et une naissances en septembre lit la grille comme un inventaire
 * complet — alors qu'il en manque cinq fois plus, dans une charge où ils sont
 * pourtant comptés. Le `<details>` est replié : le nombre suffit à savoir qu'il
 * y a un trou, le détail sert à savoir lequel. Sur bureau il se déplie en menu
 * sous son bouton ; sur téléphone, en ligne dans le feuillet.
 *
 * Le total de tête ne compte que les genres **allumés**, pour rester le
 * compagnon de ce qui est à l'écran, et le détail s'additionne exactement à ce
 * total. Ce que les pastilles éteintes retiennent encore est annoncé sur sa
 * propre ligne, dans le détail — jamais fondu dans le total, jamais tu.
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
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import EnTeteMois from './EnTeteMois.vue'
import {
  compterSansJour,
  compterSansJourMasques,
  detailSansJour,
} from './regroupement'

const {
  actifs,
  resume,
  chargement = false,
  annee,
  mois,
  filtresActifs = 0,
} = defineProps<{
  actifs: ReadonlySet<GenreEvenement>
  /** `null` tant que la charge n'est pas arrivée : les pastilles restent alors
   *  toutes disponibles — voir `genreDisponible()`. */
  resume: ResumeAnniversaires | null
  chargement?: boolean
  /** Le mois affiché — la vue le tient, la barre le montre. */
  annee: number
  mois: number
  /** Calculé par la vue : pastille du bouton filtres de l'en-tête mobile. */
  filtresActifs?: number
}>()

const emit = defineEmits<{
  genre: [genre: GenreEvenement]
  precedent: []
  suivant: []
  aujourdhui: []
  /** « Réinitialiser » du feuillet : la vue remet les genres par défaut. */
  reinitialiser: []
}>()

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

const phraseManquants = computed(
  () =>
    `${manquants.value} événement${manquants.value > 1 ? 's' : ''} n’${manquants.value > 1 ? 'ont' : 'a'} pas de jour connu`,
)

const voir = computed(() =>
  resume === null ? 'Voir le calendrier' : `Voir ${retenus.value} événement${retenus.value > 1 ? 's' : ''}`,
)
</script>

<template>
  <BarreEcran
    ecran="Anniversaires"
    titre-pastilles="Événements"
    :filtres-actifs="filtresActifs"
    :voir="voir"
    @reinitialiser="emit('reinitialiser')"
  >
    <template #vue>
      <EnTeteMois
        :annee="annee"
        :mois="mois"
        @precedent="emit('precedent')"
        @suivant="emit('suivant')"
        @aujourdhui="emit('aujourdhui')"
      />
    </template>

    <template #pastilles>
      <div
        class="contents max-md:[&>*]:min-h-10"
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
    </template>

    <template #compteur>
      <span v-if="chargement" class="font-semibold text-accent" aria-live="polite">…</span>
      <template v-else-if="resume !== null">
        {{ retenus }} événement{{ retenus > 1 ? 's' : '' }} daté{{ retenus > 1 ? 's' : '' }}
      </template>
    </template>

    <!-- Bureau : un bouton de 32 px qui déplie le détail en menu, sous lui. -->
    <template v-if="manquants > 0" #actions>
      <details class="relative">
        <summary
          class="tnum flex h-8 cursor-pointer list-none items-center rounded-champ border border-ligne px-3 text-[13px] text-attenue hover:border-accent [&::-webkit-details-marker]:hidden"
        >
          {{ manquants }} sans jour connu ▾
        </summary>

        <div
          class="absolute top-[calc(100%+8px)] right-0 z-40 w-[340px] rounded-panneau border border-ligne bg-panneau p-4 text-xs whitespace-normal text-attenue shadow-panneau"
        >
          <p class="tnum font-semibold text-texte">{{ phraseManquants }}</p>

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
        </div>
      </details>
    </template>

    <!-- Téléphone : la même ligne, dépliée sur place dans le feuillet. -->
    <template v-if="manquants > 0" #actions-mobile>
      <details class="border-t border-ligne text-attenue">
        <summary
          class="tnum flex min-h-11 cursor-pointer list-none items-center justify-between text-sm text-texte [&::-webkit-details-marker]:hidden"
        >
          {{ phraseManquants }}
          <span class="text-accent" aria-hidden="true">›</span>
        </summary>

        <ul class="list-disc space-y-0.5 pl-5 text-xs">
          <li v-for="ligne in detail" :key="ligne.genre" class="tnum">
            {{ libelleDuGenre(ligne.genre) }} — {{ ligne.compte }}
          </li>
          <li v-if="masques > 0" class="tnum">
            et {{ masques }} de plus dans les genres que vous n’affichez pas
          </li>
        </ul>

        <p class="mt-1.5 text-xs">
          Ces événements sont datés d’une année, parfois d’un mois, mais personne
          n’en a noté le jour : aucune case du calendrier ne peut les porter.
        </p>
      </details>
    </template>
  </BarreEcran>
</template>
