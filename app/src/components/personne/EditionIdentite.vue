<script setup lang="ts">
/**
 * L'identité **en mode édition** : les champs de la fiche deviennent des champs
 * de saisie, à leur place.
 *
 * Rien n'est réécrit ici : ce sont les composants de l'écran de modification —
 * `ChampsIdentite` (prénoms, nom, nom d'usage, surnom, sexe, « personne
 * décédée ») et `ChampDateIncertaine` (une date **incertaine** : connue, vers,
 * entre, avant, après, inconnue, et son lieu) —, branchés sur la même
 * `SaisieModification` (`proposition/operations.ts`). Une date de la fiche se
 * saisit donc exactement comme dans le formulaire de modification, et repart
 * telle qu'elle était si l'éditeur ne sait pas l'exprimer.
 *
 * Une naissance que le lecteur ne voit pas entière (ADR 0016) se propose à
 * l'aveugle : le champ part vide, une aide le dit, et ne rien saisir ne
 * produit aucun op.
 *
 * Le calque « en attente » est **signalé**, jamais recopié dans un champ : partir
 * d'une valeur proposée déposerait une demande dont l'`oldValue` n'a jamais existé
 * en base (§ 13.2).
 */
import { computed } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import ChampDateIncertaine from '@/components/proposition/ChampDateIncertaine.vue'
import ChampsIdentite from '@/components/proposition/ChampsIdentite.vue'
import { etiquetteDeChamp, type ChampPersonne } from '@/components/proposition/champs'
import {
  MOTIF_NAISSANCE_VERROUILLEE,
  naissanceVerrouillee,
  type SaisieModification,
} from '@/components/proposition/operations'

const { fiche, erreurs = {} } = defineProps<{
  fiche: FichePersonneComplete
  erreurs?: Record<string, string>
}>()

const saisie = defineModel<SaisieModification>({ required: true })

const champsEnAttente = computed(() =>
  Object.keys(fiche.enAttente).map((champ) => etiquetteDeChamp(champ as ChampPersonne)),
)
</script>

<template>
  <div class="flex flex-col gap-4" data-edition-identite>
    <p
      v-if="champsEnAttente.length > 0"
      class="m-0 flex flex-wrap items-center gap-2 text-[13px]"
    >
      <PastilleEtat etat="en_attente" />
      <span>Déjà proposé : {{ champsEnAttente.join(', ') }}. Les champs montrent la valeur actuelle.</span>
    </p>

    <ChampsIdentite
      v-model:given-names="saisie.givenNames"
      v-model:surname="saisie.surname"
      v-model:married-name="saisie.marriedName"
      v-model:nickname="saisie.nickname"
      v-model:sex="saisie.sex"
      v-model:deceased="saisie.deceased"
      :erreurs="erreurs"
    />
    <!-- ADR 0016 : une naissance qu'on ne voit pas entière se propose
         à l'aveugle — le champ part vide, et le dit. -->
    <ChampDateIncertaine
      v-model="saisie.naissance"
      :etiquette="etiquetteDeChamp('birth')"
      :erreur="erreurs['birth'] ?? ''"
      :aide="naissanceVerrouillee(fiche) ? MOTIF_NAISSANCE_VERROUILLEE : ''"
    />
    <ChampDateIncertaine
      v-model="saisie.deces"
      :etiquette="etiquetteDeChamp('death')"
      :erreur="erreurs['death'] ?? ''"
      aide="Pour dater le décès, cochez « Décès attesté »."
    />
  </div>
</template>
