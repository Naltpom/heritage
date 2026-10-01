<script setup lang="ts">
/**
 * La cellule « Quotas » d'une ligne de compte : un élément par quota, sous la
 * forme « Questions IA 12 / 60 ».
 *
 * - Une limite **personnalisée** porte une marque (losange accent) et le dit
 *   en toutes lettres aux lecteurs d'écran et au survol, défaut compris : sans
 *   elle, « 12 / 15 » ne dirait pas que 15 n'est pas la règle commune.
 * - Une limite **atteinte** (ou fermée à 0) passe en couleur d'alerte.
 * - Un quota dont l'administrateur est **exempté** (téléversements) dit « non
 *   compté » plutôt qu'un plafond qui ne s'applique pas.
 *
 * Les chiffres sont en chasse fixe et tabulaires : d'une ligne à l'autre, les
 * barres obliques s'alignent et la colonne se lit d'un coup d'œil.
 */
import type { QuotaDeCompte } from '@/lib/api'
import { descriptionDuQuota, quotaAtteint, texteDuQuota } from './quotas'

const { quotas } = defineProps<{ quotas: readonly QuotaDeCompte[] }>()
</script>

<template>
  <ul class="flex flex-col gap-0.5 text-xs" data-quotas-du-compte>
    <li
      v-for="quota in quotas"
      :key="quota.cle"
      class="flex items-baseline justify-between gap-3 whitespace-nowrap"
      :title="descriptionDuQuota(quota)"
      :data-quota="quota.cle"
    >
      <span class="lecteur-ecran">{{ descriptionDuQuota(quota) }}</span>
      <span class="text-attenue" aria-hidden="true">{{ quota.libelle }}</span>
      <span v-if="quota.exempte" class="italic text-attenue" aria-hidden="true">non compté</span>
      <span
        v-else
        class="inline-flex items-baseline gap-1 font-mono tnum"
        :class="quotaAtteint(quota) ? 'font-semibold text-alerte' : 'text-texte'"
        aria-hidden="true"
      >
        {{ texteDuQuota(quota) }}
        <span
          v-if="quota.personnalisee"
          class="text-accent"
          data-quota-personnalise
        >◆</span>
      </span>
    </li>
  </ul>
</template>
