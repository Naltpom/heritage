<script setup lang="ts">
/**
 * **Les compléments proposés** sur une proposition en attente — § 6.7, ADR 0017.
 *
 * D'autres membres ont retrouvé la même personne et, au lieu d'en proposer une
 * seconde, ont déposé un **complément** rattaché. Ce panneau, sur la demande
 * d'origine, les montre tous — tranchés compris, pour l'historique — et offre,
 * pour chaque complément en attente, sa **fusion** (`FusionDuComplement`).
 *
 * Tant qu'un complément attend, la proposition **ne se valide pas**
 * (`resolutionPrevue: complements_en_attente`) : la valider créerait la personne
 * sans lui, et il deviendrait une seconde création de la même personne. Le
 * panneau le dit en tête ; le bouton « Valider » le dit aussi, éteint.
 *
 * La fusion est une **action réservée** à l'administration, comme valider et
 * refuser : l'écran qui monte ce panneau dit s'il peut l'offrir (`peutFusionner`)
 * — la charge, elle, ne porte un aperçu que si la fusion est encore possible.
 */
import { computed } from 'vue'
import type { ComplementDeDemande, DemandeAdministration, ResultatFusion } from '@/lib/api'
import FusionDuComplement from './FusionDuComplement.vue'
import { etatDuComplement } from './complements'

const { origine, peutFusionner = false } = defineProps<{
  origine: DemandeAdministration
  /** L'administration, sur une proposition en attente. */
  peutFusionner?: boolean
}>()

const emit = defineEmits<{
  fusionne: [resultat: ResultatFusion]
  recharger: []
}>()

const complements = computed(() => origine.complements ?? [])
const enAttente = computed(() => complements.value.filter((c) => c.status === 'en_attente'))

/** Un complément se fusionne d'ici s'il attend, et si le serveur a pu en calculer l'aperçu. */
function fusionnable(complement: ComplementDeDemande): boolean {
  return peutFusionner && complement.status === 'en_attente' && complement.apercu !== null
}

const fusionnables = computed(() => complements.value.filter(fusionnable))
const autres = computed(() => complements.value.filter((c) => !fusionnable(c)))
</script>

<template>
  <div class="flex flex-col gap-3" data-complements>
    <p v-if="enAttente.length > 0" class="m-0 text-sm" data-complements-en-attente>
      {{
        enAttente.length === 1
          ? 'Un complément attend sur cette proposition.'
          : `${enAttente.length} compléments attendent sur cette proposition.`
      }}
      Fusionnez-les (ou refusez-les) avant de la valider : elle se validera ensuite telle quelle, avec
      tout ce qu’ils apportent.
    </p>

    <!-- La clé porte les empreintes : relue après un changement, la carte repart
         du nouvel aperçu, choix compris. -->
    <FusionDuComplement
        v-for="complement in fusionnables"
        :key="`${complement.id}-${complement.empreinte}-${origine.empreinte ?? ''}`"
        :origine-id="origine.id"
        :complement-id="complement.id"
        :auteur="complement.authorLabel"
        :depuis="complement.submittedAt"
        :libelle="complement.displayName"
        :ligne-dates="complement.ligneDates"
        :rapport="complement.apercu!"
        :empreinte-origine="origine.empreinte ?? null"
        :empreinte-complement="complement.empreinte"
        @fusionne="(resultat) => emit('fusionne', resultat)"
        @recharger="emit('recharger')"
      />

    <!-- Les autres : déjà tranchés, ou en attente sans aperçu possible. Une ligne
         chacun, et le lien vers la demande, qui dit ce qu'il en est advenu. -->
    <ul v-if="autres.length > 0" class="m-0 flex list-none flex-col gap-1.5 p-0 text-sm" data-complements-clos>
      <li v-for="complement in autres" :key="complement.id" class="flex flex-wrap items-baseline gap-x-2">
        <RouterLink :to="{ name: 'demande.detail', params: { id: complement.id } }" class="font-semibold text-accent underline">
          Complément n° {{ complement.id }}
        </RouterLink>
        <span v-if="complement.displayName !== null">{{ complement.displayName }}</span>
        <span class="text-attenue">par {{ complement.authorLabel }} — {{ etatDuComplement(complement.status) }}</span>
      </li>
    </ul>
  </div>
</template>
