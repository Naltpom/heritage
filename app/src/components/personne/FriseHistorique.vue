<script setup lang="ts">
/**
 * La frise de l'onglet Historique : une entrée par élément ajouté ou changement
 * appliqué, du plus récent au plus ancien (`historique.ts`).
 *
 * Séparée de `HistoriqueFiche`, qui charge les révisions, pour la même raison
 * que `ListeNotifications` l'est de la cloche : tout ce qu'on lit ici est une
 * fonction des props, donc se rend et se vérifie en rendu serveur, sans réseau.
 *
 * Chaque entrée porte une **icône par nature** (la forme suffit à distinguer
 * une source d'une note sans lire), sa **date et son heure** dans un `<time>`,
 * un libellé court, et, quand on la connaît, la **demande d'origine**. Une
 * source, une note ou un document renvoie à l'onglet Sources, où il vit.
 *
 * Téléphone (390 px) : la colonne d'icônes fait 28 px, le texte prend le reste
 * et se coupe (`break-words`) — une cote d'archive sans espace ne déborde pas.
 * Couleurs : uniquement les jetons du thème, qui ont leur version sombre.
 */
import MessageErreur from '@/components/base/MessageErreur.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import { horodatageAvecHeure } from '@/lib/horodatage'
import { requeteDOnglet } from './onglets'
import type { EntreeHistorique, EtatRevisions, NatureEntree } from './historique'

/**
 * `etatRevisions` dit où en sont les modifications appliquées — l'autre moitié
 * de la frise : `reserve` pour un lecteur à qui la route est fermée (compte en
 * attente), `tronque` quand le plafond de chargement a été atteint.
 */
const { entrees, ficheId, etatRevisions = 'pret', erreur = null } = defineProps<{
  entrees: readonly EntreeHistorique[]
  ficheId: number
  etatRevisions?: EtatRevisions
  /** L'erreur du chargement des révisions, quand `etatRevisions` vaut `erreur`. */
  erreur?: unknown
}>()

const emit = defineEmits<{ agrandir: [cible: CibleLoupe]; reessayer: [] }>()

/** Le pictogramme de chaque nature, en tracés 24 × 24 (même gabarit que `BoutonIcone`). */
const ICONES: Record<NatureEntree, string[]> = {
  // Un crayon : ce qui a été changé.
  revision: ['M12 20h9', 'M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z'],
  // Un livre ouvert : une source.
  citation: ['M2 4h6a4 4 0 0 1 4 4v12a3 3 0 0 0-3-3H2Z', 'M22 4h-6a4 4 0 0 0-4 4v12a3 3 0 0 1 3-3h7Z'],
  // Une feuille annotée : une note.
  note: ['M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z', 'M14 3v6h6', 'M8 13h8', 'M8 17h5'],
  // Une image : un document ou un portrait.
  media: ['M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z', 'M21 15l-5-5L5 21', 'M8.5 10a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z'],
}

const TEINTES: Record<NatureEntree, string> = {
  revision: 'bg-accent-doux text-accent',
  citation: 'bg-ok-doux text-ok',
  note: 'bg-fond text-attenue',
  media: 'bg-fond text-attenue',
}

const LIBELLES_DE_NATURE: Record<NatureEntree, string> = {
  revision: 'Modification',
  citation: 'Source',
  note: 'Note',
  media: 'Document',
}

const PASTILLE_APPUI: Record<'acte' | 'index' | 'arbre', string> = {
  acte: 'bg-accent-doux text-accent',
  index: 'bg-accent-doux text-accent',
  arbre: 'bg-alerte-doux text-alerte',
}
</script>

<template>
  <div class="flex flex-col gap-3 text-sm">
    <p
      v-if="etatRevisions === 'chargement'"
      class="m-0 text-xs text-attenue"
      data-etat-revisions="chargement"
      aria-live="polite"
    >
      Chargement des modifications…
    </p>
    <p
      v-else-if="etatRevisions === 'reserve'"
      class="m-0 text-xs text-attenue"
      data-etat-revisions="reserve"
    >
      Les modifications sont réservées aux membres : seuls les ajouts figurent ici.
    </p>
    <p
      v-else-if="etatRevisions === 'tronque'"
      class="m-0 text-xs text-attenue"
      data-etat-revisions="tronque"
    >
      Seules les modifications récentes sont affichées.
    </p>
    <!-- Les révisions n'ont pas pu être chargées : les ajouts restent lisibles
         dessous, et l'erreur dit quoi faire. -->
    <MessageErreur
      v-else-if="etatRevisions === 'erreur'"
      :erreur="erreur"
      titre="Les modifications n’ont pas pu être chargées."
      @action="emit('reessayer')"
    />

    <p
      v-if="entrees.length === 0 && etatRevisions !== 'chargement'"
      class="m-0 text-attenue italic"
      data-historique-vide
    >
      Rien n’a encore été ajouté ni modifié sur cette fiche.
    </p>

    <!-- La frise : un filet vertical derrière les pastilles d'icône. -->
    <ol v-else-if="entrees.length > 0" class="relative m-0 flex list-none flex-col gap-4 p-0" aria-label="Historique de la fiche">
      <span class="absolute top-2 bottom-2 left-[13px] w-px bg-ligne" aria-hidden="true" />
      <li
        v-for="entree in entrees"
        :key="entree.cle"
        class="relative grid grid-cols-[28px_minmax(0,1fr)] gap-3"
        :data-entree="entree.nature"
      >
        <span
          class="relative z-[1] flex size-7 items-center justify-center rounded-full border border-ligne"
          :class="TEINTES[entree.nature]"
          :title="LIBELLES_DE_NATURE[entree.nature]"
        >
          <svg
            viewBox="0 0 24 24"
            class="size-3.5"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path v-for="(d, i) in ICONES[entree.nature]" :key="i" :d="d" />
          </svg>
          <span class="sr-only">{{ LIBELLES_DE_NATURE[entree.nature] }}</span>
        </span>

        <div class="flex min-w-0 flex-col gap-0.5 pt-0.5">
          <p class="m-0 text-[11px] text-attenue">
            <time :datetime="entree.date">{{ horodatageAvecHeure(entree.date) }}</time>
            <template v-if="entree.auteur"> · {{ entree.auteur }}</template>
          </p>

          <p class="m-0 font-semibold">{{ entree.titre }}</p>

          <p
            v-for="(ligne, i) in entree.lignes"
            :key="i"
            class="m-0 text-xs break-words"
            :class="i === 0 && entree.nature !== 'revision' ? '' : 'text-attenue'"
          >
            {{ ligne }}
          </p>

          <span
            v-if="entree.appui"
            class="mt-0.5 self-start rounded-pastille px-1.5 py-px text-[10.5px] font-semibold"
            :class="PASTILLE_APPUI[entree.appui.niveau]"
          >
            {{ entree.appui.libelle }}
          </span>

          <button
            v-if="entree.media"
            type="button"
            class="mt-1 cursor-zoom-in self-start border-0 bg-transparent p-0"
            :aria-label="`Agrandir : ${entree.media.legende}`"
            @click="
              emit('agrandir', {
                mode: 'identifiant',
                mediaId: entree.media.mediaId,
                legende: entree.media.legende,
              })
            "
          >
            <img
              :src="entree.media.vignetteUrl"
              :alt="entree.media.legende"
              class="block h-16 w-16 rounded-champ border border-ligne object-cover"
              loading="lazy"
              decoding="async"
            />
          </button>

          <p
            v-if="entree.demandeId !== null || entree.importGedcom || entree.nature !== 'revision'"
            class="m-0 mt-0.5 flex flex-wrap gap-x-3 gap-y-1 text-xs"
          >
            <RouterLink
              v-if="entree.demandeId !== null"
              :to="{ name: 'demande.detail', params: { id: entree.demandeId } }"
              class="font-semibold text-accent hover:underline"
              data-lien="demande"
            >
              Demande n° {{ entree.demandeId }}
            </RouterLink>
            <span v-else-if="entree.importGedcom" class="text-attenue">Import de l’arbre</span>
            <RouterLink
              v-if="entree.nature !== 'revision'"
              :to="{ name: 'personne.detail', params: { id: ficheId }, query: requeteDOnglet('sources') }"
              class="text-accent hover:underline"
              data-lien="sources"
            >
              Voir dans Sources
            </RouterLink>
          </p>
        </div>
      </li>
    </ol>
  </div>
</template>
