<script setup lang="ts">
/**
 * Un événement, tel qu'on le lit : son type, ses sujets, sa date, son lieu.
 *
 * Il sert à deux endroits — le panneau d'un jour choisi, et la liste des 29
 * février qui n'ont pas de case cette année-là. Les deux montrent exactement la
 * même chose ; deux gabarits jumeaux auraient divergé à la première retouche, et
 * c'est le genre de divergence qu'on ne voit qu'une année sur quatre.
 *
 * ────────────────────────────────────────────────────────────────────
 * **`texte` est recopié, jamais recomposé.** Le serveur écrit la date en toutes
 * lettres — « 14 mars 1903 », mais aussi « vers 1903 » et « entre 1901 et
 * 1909 ». L'imprécision d'une date généalogique **est** une information (§ 14) ;
 * un front qui reformerait « 14/03/1903 » à partir de ses morceaux la
 * supprimerait, et toutes les dates approchées deviendraient certaines.
 *
 * **Un sujet, ou deux, et rien d'autre.** Une union porte `familyId` et peut
 * porter deux sujets ; un événement de personne en porte un. On rend ce qui est
 * là. La charge n'omet un époux que lorsque le cercle de visibilité du lecteur
 * ne le laisse pas voir, et compléter la ligne inventerait la personne même que
 * le serveur a retirée.
 *
 * **L'âge se tait sous un an.** `ageALAnniversaire()` rend toujours un nombre,
 * négatif compris quand on remonte avant l'événement — voir son en-tête. Le
 * choix de ne rien peindre appartient à ce gabarit, et à lui seul : c'est ce qui
 * évite un `null` que chaque appelant aurait à réinterpréter.
 *
 * **Sans année, aucun âge** (ADR 0016) : la naissance d'une personne vivante
 * dont l'année n'est pas partagée arrive sans `annee`, et `texte` dit
 * « 12 mars ». On n'invente pas d'écart à partir de rien.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import type { EvenementAnniversaire } from '@/lib/api'
import { libelleDeType } from './libelles'
import { ageALAnniversaire } from './regroupement'

const { evenement, anneeAffichee } = defineProps<{
  evenement: EvenementAnniversaire
  anneeAffichee: number
}>()

const ecart = computed(() =>
  evenement.annee === undefined ? null : ageALAnniversaire(evenement.annee, anneeAffichee),
)

const libelleEcart = computed(() => (ecart.value === 1 ? 'il y a 1 an' : `il y a ${ecart.value} ans`))
</script>

<template>
  <div class="min-w-0">
    <p class="text-xs font-semibold text-attenue">{{ libelleDeType(evenement.type) }}</p>

    <p class="mt-0.5 min-w-0">
      <template v-for="(sujet, index) in evenement.sujets" :key="sujet.personId">
        <span v-if="index > 0" class="text-attenue"> · </span>
        <RouterLink
          :to="{ name: 'personne.detail', params: { id: sujet.personId } }"
          class="text-sm text-accent underline"
        >
          {{ sujet.displayName }}
        </RouterLink>
      </template>
    </p>

    <p class="tnum mt-0.5 text-sm">{{ evenement.texte }}</p>

    <p v-if="evenement.lieu" class="mt-0.5 text-xs text-attenue">{{ evenement.lieu }}</p>

    <p v-if="ecart !== null && ecart >= 1" class="tnum mt-0.5 text-xs text-attenue">{{ libelleEcart }}</p>
  </div>
</template>
