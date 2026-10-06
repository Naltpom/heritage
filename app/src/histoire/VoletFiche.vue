<script setup lang="ts">
/**
 * Le VOLET (PC) : la fiche de la personne choisie, sur toute la droite. Il
 * POUSSE l'arbre — le canevas rétrécit, rien n'est caché dessous.
 *
 * Sa lecture peut échouer (réseau, serveur) : le volet le dit, avec
 * « Réessayer », au lieu d'afficher « Lecture de la fiche… » pour toujours. Le
 * message est celui d'`analyserErreur` — l'écran ne réinterprète aucun code.
 */
import { computed } from 'vue'
import { NOM_D_UNE_PERSONNE_VIVANTE, analyserErreur, type FichePersonneComplete } from '@/lib/api'
import PortraitFiche from '@/components/personne/PortraitFiche.vue'
import IdentiteFiche from '@/components/personne/IdentiteFiche.vue'
import ParenteFiche from '@/components/personne/ParenteFiche.vue'
import FaitsFiche from '@/components/personne/FaitsFiche.vue'
import PastillesFiche from './PastillesFiche.vue'
import type { FicheHistoire } from './lignees'
import type { EtatDeLecture } from './useFicheChoisie'

const props = defineProps<{
  fiche: FicheHistoire
  moi: boolean
  couleur: string
  parente: string | null
  ficheLue: FichePersonneComplete | null
  etat: EtatDeLecture
  erreur: unknown
  ligneDirecte: boolean
}>()

const emit = defineEmits<{
  fermer: []
  centrer: []
  /** Départ vers la fiche ou la demande : la vue se mémorise pour le retour. */
  partir: []
  basculerLigne: []
  choisirProche: [personId: number]
  relire: []
}>()

const messageErreur = computed(() => (props.erreur === null ? '' : analyserErreur(props.erreur).message))
</script>

<template>
  <aside
    class="flex w-[380px] flex-none flex-col border-l border-ligne bg-panneau"
    :style="{ borderTop: `3px solid ${couleur}` }"
    aria-label="Fiche de la personne choisie"
  >
    <header class="flex flex-none flex-col gap-2 border-b border-ligne px-4 py-3.5">
      <div class="flex items-start gap-2">
        <div class="flex flex-1 flex-col leading-snug">
          <span class="text-[17px] font-semibold">{{
            fiche.noeud.visibilite === 'restreinte' ? NOM_D_UNE_PERSONNE_VIVANTE : fiche.noeud.displayName
          }}</span>
          <span v-if="typeof fiche.noeud.etiquetteDates === 'string'" class="font-mono text-xs text-attenue">{{
            fiche.noeud.etiquetteDates
          }}</span>
        </div>
        <button
          type="button"
          aria-label="Fermer"
          title="Fermer (Échap)"
          class="-mt-1.5 -mr-2 h-8 w-8 text-lg text-attenue hover:text-texte"
          @click="emit('fermer')"
        >
          ×
        </button>
      </div>
      <PastillesFiche :fiche="fiche" :moi="moi" :parente="parente" :branche="fiche.branche" detache="sans lien connu" />
      <div class="flex gap-2">
        <button type="button" class="h-9 flex-1 rounded-champ border border-ligne font-semibold hover:bg-accent-doux" @click="emit('centrer')">
          Centrer
        </button>
        <RouterLink
          v-if="fiche.noeud.personId !== null"
          :to="{ name: 'personne.detail', params: { id: fiche.noeud.personId } }"
          class="flex h-9 flex-1 items-center justify-center rounded-champ bg-accent font-semibold text-fond"
          @click="emit('partir')"
        >
          Fiche
        </RouterLink>
        <RouterLink
          v-else-if="fiche.noeud.demandeId !== undefined"
          :to="{ name: 'demande.detail', params: { id: fiche.noeud.demandeId } }"
          class="flex h-9 flex-1 items-center justify-center rounded-champ bg-accent font-semibold text-fond"
          @click="emit('partir')"
        >
          Demande
        </RouterLink>
      </div>
      <button
        type="button"
        role="checkbox"
        :aria-checked="ligneDirecte"
        class="flex min-h-9 items-center gap-2.5 text-left text-sm"
        @click="emit('basculerLigne')"
      >
        <span
          class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
          :class="ligneDirecte ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
        >{{ ligneDirecte ? '✓' : '' }}</span>
        <span class="flex flex-col leading-tight">
          <span>Afficher sa ligne directe</span>
          <span class="text-xs text-attenue">ancêtres et descendants · reste cochée d’une personne à l’autre</span>
        </span>
      </button>
    </header>

    <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-4 py-4">
      <template v-if="ficheLue && ficheLue.id === fiche.noeud.personId">
        <PortraitFiche
          v-if="ficheLue.portrait"
          class="self-center"
          :portrait="ficheLue.portrait"
          :nom="ficheLue.displayName"
          :sexe="ficheLue.sex"
        />
        <IdentiteFiche :fiche="ficheLue" :seulement="['birth', 'death']" />
        <ParenteFiche :fiche="ficheLue" vers-arbre @choisir="emit('choisirProche', $event)" />
        <section v-if="ficheLue.faits.length">
          <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">Faits</h3>
          <FaitsFiche :fiche="ficheLue" />
        </section>
        <p class="m-0 text-xs text-attenue">
          {{ ficheLue.citationCount }} source{{ ficheLue.citationCount > 1 ? 's' : '' }} ·
          {{ ficheLue.notes.length }} note{{ ficheLue.notes.length > 1 ? 's' : '' }} —
          <RouterLink
            v-if="fiche.noeud.personId !== null"
            :to="{ name: 'personne.detail', params: { id: fiche.noeud.personId } }"
            class="text-accent underline"
            @click="emit('partir')"
          >tout voir sur la fiche</RouterLink>
        </p>
      </template>
      <p v-else-if="fiche.noeud.visibilite === 'restreinte'" class="m-0 text-sm text-attenue">
        Personne vivante : sa fiche n’est visible que de ses proches.
      </p>
      <div v-else-if="etat === 'erreur'" class="flex flex-col items-start gap-2 text-sm" role="alert" data-erreur-fiche>
        <p class="m-0 text-attenue">La fiche n’a pas pu être lue. {{ messageErreur }}</p>
        <button type="button" class="rounded-champ border border-ligne px-3 py-1.5 font-semibold text-texte hover:bg-accent-doux" @click="emit('relire')">
          Réessayer
        </button>
      </div>
      <!-- Un nœud proposé n'a pas de fiche à lire : son détail est dans sa demande. -->
      <p v-else-if="fiche.noeud.personId === null" class="m-0 text-sm text-attenue">
        Fiche proposée, en attente de validation : son détail est dans la demande.
      </p>
      <p v-else class="m-0 text-sm text-attenue">Lecture de la fiche…</p>
    </div>
  </aside>
</template>
