<script setup lang="ts">
/**
 * La fiche d'une personne **à côté de sa demande**, en lecture seule — rendu seul.
 *
 * Elle ne lit rien : `DemandeView` tient la lecture (`useFicheDeLaDemande`) et
 * monte ce composant deux fois, en colonne sur ordinateur et en modale sur
 * téléphone, sur les **mêmes** données. C'est aussi ce qui la rend vérifiable
 * dans un test de rendu, sans réseau.
 *
 * **Les morceaux sont ceux de la vraie page** (`components/personne`) :
 * `EnTeteFiche`, `IdentiteFiche` — où le calque « en attente » se dessine à
 * côté de la valeur validée, ce qui est précisément ce qu'on veut voir ici —,
 * `FaitsFiche`, `ParenteFiche`, `SourcesFiche`. Rien n'est réécrit, et une
 * correction de la fiche profite aux deux écrans.
 *
 * **Elle ne propose rien.** Ni « Proposer », ni dépôt de document, ni demande de
 * suppression : ce sont les gestes de `PageFiche`, et les redoubler ici ferait
 * deux surfaces pour un même geste, dont une seule serait éprouvée. Ce qu'on
 * voit se lit — plus un lien vers la vraie page, pour qui veut y aller.
 */
import { computed } from 'vue'
import type { FichePersonne } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import EnTeteFiche from '@/components/personne/EnTeteFiche.vue'
import FaitsFiche from '@/components/personne/FaitsFiche.vue'
import FicheRestreinte from '@/components/personne/FicheRestreinte.vue'
import IdentiteFiche from '@/components/personne/IdentiteFiche.vue'
import ParenteFiche from '@/components/personne/ParenteFiche.vue'
import SectionFiche from '@/components/personne/SectionFiche.vue'
import SourcesFiche from '@/components/personne/SourcesFiche.vue'

const {
  fiche,
  legende,
  chargement = false,
  erreur = null,
} = defineProps<{
  fiche: FichePersonne | null
  /** De qui il s'agit et pourquoi elle (`legendeDeLaFiche()`). */
  legende: string
  chargement?: boolean
  erreur?: unknown
}>()

const emit = defineEmits<{
  fermer: []
  recharger: []
  agrandir: [cible: CibleLoupe]
}>()

/** La discrimination faite une fois, comme dans `PersonneView` : le typeur
 *  refuse `fiche.citations` tant qu'on n'a pas écarté la fiche réduite. */
const complete = computed(() => (fiche !== null && fiche.visibilite === 'complete' ? fiche : null))
const restreinte = computed(() => (fiche !== null && fiche.visibilite === 'restreinte' ? fiche : null))
</script>

<template>
  <div class="flex min-w-0 flex-col gap-3">
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <p class="m-0 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">{{ legende }}</p>
        <p class="m-0 mt-0.5 text-xs text-attenue">Lecture seule — rien ne se modifie ici.</p>
      </div>
      <button
        type="button"
        class="inline-flex size-9 flex-none cursor-pointer items-center justify-center rounded-champ border border-ligne bg-panneau text-xl leading-none text-texte transition-colors hover:border-accent"
        aria-label="Fermer la fiche"
        title="Fermer la fiche"
        @click="emit('fermer')"
      >
        ×
      </button>
    </div>

    <div v-if="chargement && fiche === null" class="rounded-panneau border border-ligne bg-panneau p-4">
      <p class="mb-3 text-sm text-attenue" role="status">Chargement de la fiche…</p>
      <SqueletteChargement :lignes="5" />
    </div>

    <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="emit('recharger')" />

    <template v-else-if="fiche !== null">
      <RouterLink
        :to="{ name: 'personne.detail', params: { id: fiche.id } }"
        class="flex h-9 items-center justify-center rounded-champ border border-ligne bg-panneau text-[13px] font-semibold text-accent transition-colors hover:border-accent"
      >
        Ouvrir la page de {{ fiche.displayName }} ↗
      </RouterLink>

      <div class="rounded-panneau border border-ligne bg-panneau px-4 pt-4">
        <EnTeteFiche :fiche="fiche" @agrandir="emit('agrandir', $event)" />
      </div>

      <FicheRestreinte v-if="restreinte !== null" :fiche="restreinte" />

      <template v-else-if="complete !== null">
        <SectionFiche titre="Identité">
          <IdentiteFiche :fiche="complete" />
        </SectionFiche>
        <SectionFiche v-if="complete.faits.length > 0" titre="Faits">
          <FaitsFiche :fiche="complete" />
        </SectionFiche>
        <SectionFiche titre="Famille">
          <ParenteFiche :fiche="complete" />
        </SectionFiche>
        <SectionFiche titre="Sources et notes">
          <SourcesFiche :fiche="complete" @agrandir="emit('agrandir', $event)" />
        </SectionFiche>
      </template>
    </template>
  </div>
</template>
