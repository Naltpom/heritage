<script setup lang="ts">
/**
 * La barre d'outils de la carte : les genres, le dégradé, le fond.
 *
 * Elle **émet et ne retient rien** — tout l'état vit dans `CarteView`. C'est
 * déjà la règle des deux autres barres du produit, et c'est ce qui fait de son
 * emplacement une simple question de mise en page : sur téléphone, `TiroirOutils`
 * la déplace dans un tiroir sans qu'elle ait à le savoir.
 *
 * **Le chrome — bordure, fond, gouttières — est neutralisé sous `md`** plutôt
 * que déplacé chez l'hôte : ainsi l'écran large rend exactement la même barre
 * qu'avant, et le tiroir reçoit un empilement nu.
 *
 * **La ligne d'avertissement du fond détaillé est affichée AVANT l'appui, pas
 * après.** C'est le seul endroit du produit où une requête sort vers un tiers ;
 * le dire au moment du choix est la moindre des choses, et le dire après ne
 * servirait plus à rien.
 */
import type { GenreEvenement } from '@/lib/api'
import PastilleFiltre from '@/components/evenements/PastilleFiltre.vue'
import { GENRES, genreDisponible, titreDeLaPastille } from '@/components/evenements/filtres'
import type { ModeCouleur } from './echelles'

const { actifs, resume, mode, fond, nonSitues, chargement } = defineProps<{
  actifs: ReadonlySet<GenreEvenement>
  resume: Readonly<Record<GenreEvenement, number>> | null
  mode: ModeCouleur
  fond: 'vectoriel' | 'tuiles'
  /** Combien d'événements ont un lieu écrit mais non situé. */
  nonSitues: number
  chargement: boolean
}>()

const emit = defineEmits<{
  genre: [genre: GenreEvenement]
  mode: [mode: ModeCouleur]
  fond: [fond: 'vectoriel' | 'tuiles']
  recadrer: []
  nonSitues: []
}>()

const MODES: readonly { id: ModeCouleur; libelle: string; description: string }[] = [
  {
    id: 'date',
    libelle: 'Par date',
    description: 'La couleur dit l’ancienneté : du plus ancien au plus récent.',
  },
  {
    id: 'nombre',
    libelle: 'Par nombre',
    description: 'La couleur dit la densité d’événements au même lieu.',
  },
]

const FONDS: readonly { id: 'vectoriel' | 'tuiles'; libelle: string; description: string }[] = [
  {
    id: 'vectoriel',
    libelle: 'Fond sobre',
    description: 'Les contours des pays, servis par Heritage. Aucune requête extérieure.',
  },
  {
    id: 'tuiles',
    libelle: 'Fond détaillé',
    description: 'Charge des images depuis openstreetmap.org, et zoome jusqu’à la rue.',
  },
]
</script>

<template>
  <div
    class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-2 border-b border-ligne bg-panneau px-4 py-2 max-md:flex-col max-md:items-stretch max-md:gap-2 max-md:border-b-0 max-md:bg-transparent max-md:px-0 max-md:py-0"
    role="group"
    aria-label="Réglages de la carte"
  >
    <div class="flex min-w-0 flex-wrap items-center gap-1.5 max-md:flex-col max-md:items-stretch">
      <span class="text-xs font-semibold text-attenue">Événements</span>
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

    <span class="hidden h-5 w-px bg-ligne md:block" aria-hidden="true" />

    <div class="flex min-w-0 flex-wrap items-center gap-1.5 max-md:flex-col max-md:items-stretch">
      <span class="text-xs font-semibold text-attenue">Couleur</span>
      <PastilleFiltre
        v-for="m in MODES"
        :key="m.id"
        :libelle="m.libelle"
        :actif="mode === m.id"
        :titre="m.description"
        @basculer="emit('mode', m.id)"
      />
    </div>

    <span class="hidden h-5 w-px bg-ligne md:block" aria-hidden="true" />

    <div class="flex min-w-0 flex-wrap items-center gap-1.5 max-md:flex-col max-md:items-stretch">
      <span class="text-xs font-semibold text-attenue">Fond</span>
      <PastilleFiltre
        v-for="f in FONDS"
        :key="f.id"
        :libelle="f.libelle"
        :actif="fond === f.id"
        :titre="f.description"
        @basculer="emit('fond', f.id)"
      />
    </div>

    <span class="flex-auto max-md:hidden" />

    <button
      v-if="nonSitues > 0"
      type="button"
      class="cursor-pointer rounded-champ border border-ligne bg-fond px-2.5 py-1 text-xs text-attenue hover:border-accent hover:text-texte max-md:w-full max-md:py-2"
      :title="'Ces événements portent un lieu que le référentiel n’a pas su situer.'"
      @click="emit('nonSitues')"
    >
      {{ nonSitues }} sans lieu situé
    </button>

    <button
      type="button"
      class="cursor-pointer rounded-champ border border-ligne bg-fond px-2.5 py-1 text-xs text-attenue hover:border-accent hover:text-texte max-md:w-full max-md:py-2"
      title="Recadrer sur tous les lieux (touche 0)"
      :disabled="chargement"
      @click="emit('recadrer')"
    >
      Recadrer
    </button>

    <p v-if="fond === 'tuiles'" class="w-full text-xs text-attenue">
      Le fond détaillé charge ses images depuis openstreetmap.org : ce serveur voit alors les
      zones que vous regardez. Il ne reçoit ni nom, ni date, ni fiche.
    </p>
  </div>
</template>
