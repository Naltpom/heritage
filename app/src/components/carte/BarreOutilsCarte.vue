<script setup lang="ts">
/**
 * La barre d'outils de la carte : les genres, le dégradé, le fond.
 *
 * Elle **émet et ne retient rien** — tout l'état vit dans `CarteView`. C'est
 * déjà la règle des deux autres barres du produit, et c'est ce qui fait de son
 * emplacement une simple question de mise en page. Le seul état local est le
 * **survol** du bouton « Fond détaillé » : un état d'interface, qui ne dit rien
 * de ce que la carte affiche.
 *
 * **La mise en page est celle de `BarreEcran`** (handoff « En-tête, barres
 * d'écran et fiche », § 2) : Par date · Par nombre en segmenté, les cinq genres
 * en pastilles, puis à droite « {n} sans lieu situé », le segmenté Fond sobre ·
 * Fond détaillé et Recadrer. Il n'y a **pas** de menu Filtres sur bureau : tout
 * tient dans la barre. Sur téléphone, seul le segmenté du dégradé reste sous
 * l'en-tête ; les genres, le fond, les lieux non situés, la légende et Recadrer
 * passent dans le feuillet. Les genres ne sont rendus qu'**une fois**, dans la
 * barre ou dans le feuillet selon la largeur (`useEstMobile`).
 *
 * **La ligne d'avertissement du fond détaillé est affichée AVANT l'appui, pas
 * après.** C'est le seul endroit du produit où une requête sort vers un tiers ;
 * le dire au moment du choix est la moindre des choses, et le dire après ne
 * servirait plus à rien. Sur bureau, elle paraît sous la barre dès que « Fond
 * détaillé » est survolé ou reçoit le focus — par-dessus la carte, pour ne pas
 * la faire sauter — et reste, dans le flux, tant qu'il est choisi. L'hôte est
 * aussi nommé dans l'infobulle du bouton. Sur téléphone, où rien ne se survole,
 * elle est écrite en permanence sous le choix du fond, dans le feuillet.
 */
import { computed, ref } from 'vue'
import type { GenreEvenement } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import Segmente from '@/components/mise-en-page/Segmente.vue'
import PastilleFiltre from '@/components/evenements/PastilleFiltre.vue'
import { GENRES, genreDisponible, genresParDefaut, titreDeLaPastille } from '@/components/evenements/filtres'
import { useEstMobile, useTiroirs } from '@/composables/useTiroirs'
import type { ModeCouleur } from './echelles'

type Fond = 'vectoriel' | 'tuiles'

const { actifs, resume, mode, fond, nonSitues, chargement, evenementsSitues = null } = defineProps<{
  actifs: ReadonlySet<GenreEvenement>
  resume: Readonly<Record<GenreEvenement, number>> | null
  mode: ModeCouleur
  fond: Fond
  /** Combien d'événements ont un lieu écrit mais non situé. */
  nonSitues: number
  chargement: boolean
  /** Combien d'événements les bulles portent, pour « Voir {n} événements » au
   *  pied du feuillet. `null` tant que rien n'est chargé. */
  evenementsSitues?: number | null
}>()

const emit = defineEmits<{
  genre: [genre: GenreEvenement]
  mode: [mode: ModeCouleur]
  fond: [fond: Fond]
  recadrer: []
  nonSitues: []
}>()

defineSlots<{
  /** La légende de la carte, rendue dans le feuillet sur téléphone (elle y est
   *  masquée en flottant). */
  legende?: () => unknown
}>()

const estMobile = useEstMobile()
const { fermerTiroirs } = useTiroirs()

const MODES: readonly { valeur: ModeCouleur; libelle: string; description: string }[] = [
  {
    valeur: 'date',
    libelle: 'Par date',
    description: 'Couleur selon l’ancienneté.',
  },
  {
    valeur: 'nombre',
    libelle: 'Par nombre',
    description: 'Couleur selon le nombre d’événements.',
  },
]

const FONDS: readonly { id: Fond; libelle: string; description: string }[] = [
  {
    id: 'vectoriel',
    libelle: 'Fond sobre',
    description: 'Les contours des pays, sans service extérieur.',
  },
  {
    id: 'tuiles',
    libelle: 'Fond détaillé',
    description: 'Images d’openstreetmap.org, jusqu’à la rue.',
  },
]

/** « Fond détaillé » est survolé ou a le focus : l'avertissement paraît avant l'appui. */
const envisage = ref(false)
const avertir = computed(() => fond === 'tuiles' || envisage.value)

/** Les genres sans donnée : la pastille est grisée, et le feuillet dit pourquoi
 *  en toutes lettres — sur téléphone, l'infobulle ne se lit pas. */
const indisponibles = computed(() => GENRES.filter((g) => !genreDisponible(resume, g.id)))

/** Un filtre est actif dès que les genres allumés ne sont plus ceux du départ. */
const filtresActifs = computed(() => {
  const depart = genresParDefaut()
  return GENRES.filter((g) => actifs.has(g.id) !== depart.has(g.id)).length
})

const voir = computed(() =>
  evenementsSitues === null
    ? 'Voir la carte'
    : `Voir ${evenementsSitues} événement${evenementsSitues > 1 ? 's' : ''}`,
)

function reinitialiser(): void {
  // Allumer d'abord, éteindre ensuite : le dernier genre allumé ne s'éteint pas,
  // et l'ordre inverse buterait sur cette règle.
  const depart = genresParDefaut()
  for (const g of GENRES) if (depart.has(g.id) && !actifs.has(g.id)) emit('genre', g.id)
  for (const g of GENRES) if (!depart.has(g.id) && actifs.has(g.id)) emit('genre', g.id)
  // Le fond sobre est celui qui ne parle à personne : c'est l'état de départ.
  if (fond !== 'vectoriel') emit('fond', 'vectoriel')
}

/** La liste des non situés s'ouvre sur la carte : le feuillet se referme pour la laisser voir. */
function montrerNonSitues(): void {
  emit('nonSitues')
  fermerTiroirs()
}
</script>

<template>
  <div class="relative">
    <BarreEcran ecran="Carte" :filtres-actifs="filtresActifs" :voir="voir" @reinitialiser="reinitialiser">
      <!-- Vue : le dégradé --------------------------------------------- -->
      <template #vue>
        <div class="max-md:w-full" :title="MODES.map((m) => `${m.libelle} : ${m.description}`).join(' ')">
          <Segmente
            :options="MODES"
            :valeur="mode"
            etiquette="Couleur des lieux"
            :colonnes="2"
            @choisir="(m) => emit('mode', m)"
          />
        </div>
      </template>

      <!-- Les genres, sur bureau : dans la barre ------------------------- -->
      <template v-if="!estMobile" #pastilles>
        <div class="flex items-center gap-1.5" role="group" aria-label="Événements">
          <PastilleFiltre
            v-for="genre in GENRES"
            :key="genre.id"
            :libelle="genre.libelle"
            :actif="actifs.has(genre.id)"
            :disponible="genreDisponible(resume, genre.id)"
            :titre="titreDeLaPastille(genre, resume)"
            @basculer="emit('genre', genre.id)"
          />
        </div>
      </template>

      <!-- Le feuillet, sur téléphone seulement : sur bureau, tout tient dans la barre. -->
      <template v-if="estMobile" #filtres>
        <section class="flex flex-col gap-2">
          <h3 class="text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Événements</h3>
          <div class="flex flex-wrap gap-2 [&>*]:h-10! [&>*]:w-auto! [&>*]:px-3.5! [&>*]:text-sm!" role="group" aria-label="Événements">
            <PastilleFiltre
              v-for="genre in GENRES"
              :key="genre.id"
              :libelle="genre.libelle"
              :actif="actifs.has(genre.id)"
              :disponible="genreDisponible(resume, genre.id)"
              :titre="titreDeLaPastille(genre, resume)"
              @basculer="emit('genre', genre.id)"
            />
          </div>
          <p v-for="g in indisponibles" :key="g.id" class="text-xs text-attenue">
            « {{ g.libelle }} » : rien de visible dans votre arbre.
          </p>
        </section>

        <section class="flex flex-col gap-2">
          <h3 class="text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Fond de carte</h3>
          <div class="grid grid-cols-2 gap-[2px] rounded-[8px] border border-ligne p-[3px]" role="group" aria-label="Fond de carte">
            <button
              v-for="f in FONDS"
              :key="f.id"
              type="button"
              class="h-10 rounded-md"
              :class="fond === f.id ? 'bg-accent-doux font-semibold text-texte' : 'text-attenue'"
              :aria-pressed="fond === f.id"
              :title="f.description"
              @click="emit('fond', f.id)"
            >
              {{ f.libelle }}
            </button>
          </div>
          <p class="text-xs text-attenue">
            Le fond détaillé vient d’openstreetmap.org : ce site voit les zones regardées, jamais les noms ni les dates.
          </p>
        </section>

        <button
          v-if="nonSitues > 0"
          type="button"
          class="flex min-h-11 items-center justify-between border-t border-ligne text-left text-sm"
          title="Leur lieu n’a pas pu être placé sur la carte"
          @click="montrerNonSitues"
        >
          {{ nonSitues }} événement{{ nonSitues > 1 ? 's' : '' }} sans lieu situé
          <span class="text-accent" aria-hidden="true">›</span>
        </button>

        <div v-if="$slots.legende"><slot name="legende" /></div>
      </template>

      <template #actions-mobile>
        <BoutonBase variante="discret" pleine-largeur class="h-11" :desactive="chargement" @click="emit('recadrer')">
          Recadrer
        </BoutonBase>
      </template>

      <!-- Actions (bureau) ------------------------------------------------ -->
      <template #actions>
        <button
          v-if="nonSitues > 0"
          type="button"
          class="flex h-8 cursor-pointer items-center rounded-champ border border-ligne px-3 text-[13px] text-attenue hover:border-accent hover:text-texte"
          title="Leur lieu n’a pas pu être placé sur la carte"
          @click="emit('nonSitues')"
        >
          {{ nonSitues }} sans lieu situé
        </button>

        <div
          class="inline-flex gap-[2px] rounded-[8px] border border-ligne bg-panneau p-[3px]"
          role="group"
          aria-label="Fond de carte"
        >
          <button
            v-for="f in FONDS"
            :key="f.id"
            type="button"
            class="inline-flex h-[26px] items-center rounded-md px-2.5 text-[12.5px] whitespace-nowrap"
            :class="fond === f.id ? 'bg-accent-doux font-semibold text-texte' : 'text-attenue hover:text-texte'"
            :aria-pressed="fond === f.id"
            :title="f.description"
            @click="emit('fond', f.id)"
            @mouseenter="envisage = f.id === 'tuiles'"
            @mouseleave="envisage = false"
            @focus="envisage = f.id === 'tuiles'"
            @blur="envisage = false"
          >
            {{ f.libelle }}
          </button>
        </div>

        <BoutonBase
          variante="secondaire"
          class="h-8"
          title="Recadrer sur tous les lieux (touche 0)"
          :desactive="chargement"
          @click="emit('recadrer')"
        >
          Recadrer
        </BoutonBase>
      </template>
    </BarreEcran>

    <!--
      L'avertissement, sous la barre, sur bureau. Choisi, le fond détaillé le
      garde dans le flux ; seulement survolé, il flotte par-dessus la carte pour
      ne pas la redimensionner à chaque passage de la souris.
    -->
    <p
      v-if="avertir"
      class="border-b border-ligne bg-fond px-5 py-1.5 text-xs text-attenue max-md:hidden"
      :class="fond === 'tuiles' ? '' : 'absolute inset-x-0 top-full z-20 shadow-panneau'"
      role="note"
    >
      Le fond détaillé vient d’openstreetmap.org : ce site voit les zones regardées, jamais les noms ni les dates.
    </p>
  </div>
</template>
