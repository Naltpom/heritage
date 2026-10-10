<script setup lang="ts">
/**
 * **« Cette demande complète la demande n° X »** — le bandeau d'un complément
 * (§ 6.3 ter, ADR 0017), en tête de son détail.
 *
 * Il dit ce que la charge dit, et rien de plus :
 *
 * - **l'administrateur** lit l'origine en clair (`complementDe.demandeId`,
 *   `displayName`, `status`) : son numéro, en lien, et son nom ;
 * - **l'auteur du complément** n'en tient que l'identifiant de fiche — la
 *   proposition d'un autre, souvent un vivant, qu'il ne voit pas : « une
 *   proposition déjà en attente », sans numéro ;
 * - clos, le complément porte `duplicateOfId` : ce numéro-là lui est rendu, un
 *   numéro n'apprend rien d'une personne. On écrit « rattachée » et non
 *   « fusionnée » : un refus peut aussi renvoyer à une autre demande, et c'est le
 *   motif de la décision, affiché plus bas, qui dit lequel des deux.
 *
 * Sur un complément **en attente** dont l'origine attend aussi, l'administrateur y
 * trouve la fusion elle-même — « Fusionner dans la demande n° X » —, d'après
 * l'aperçu que sa route calcule à blanc (`apercuFusion`). Le bouton « Valider »,
 * lui, reste éteint (`complement_a_fusionner`) : le geste est la fusion.
 */
import { computed, onMounted, ref, shallowRef, watch } from 'vue'
import { apercuFusion, type ApercuDeFusion, type DemandeAdministration, type ResultatFusion } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import FusionDuComplement from './FusionDuComplement.vue'
import { etatDeLOrigine, origineNommee } from './complements'

const { demande, peutFusionner = false } = defineProps<{
  demande: DemandeAdministration
  /** L'administration, hors bascule : la fusion est une action réservée. */
  peutFusionner?: boolean
}>()

const emit = defineEmits<{
  fusionne: [resultat: ResultatFusion]
  recharger: []
}>()

const origine = computed(() => origineNommee(demande.complementDe))
const etat = computed(() => etatDeLOrigine(origine.value?.statut ?? null))

/** Les deux demandes attendent : la fusion est le geste. */
const fusionPossible = computed(
  () => peutFusionner && demande.status === 'en_attente' && origine.value !== null && origine.value.statut === 'en_attente',
)

const apercu = shallowRef<ApercuDeFusion | null>(null)
const lecture = ref(false)
const erreur = ref<unknown>(null)
let jeton = 0

async function lire(): Promise<void> {
  const cible = origine.value
  if (!fusionPossible.value || cible === null) return
  const tour = ++jeton
  lecture.value = true
  erreur.value = null
  try {
    const lu = await apercuFusion(cible.demandeId, demande.id)
    if (tour === jeton) apercu.value = lu
  } catch (cause) {
    if (tour === jeton) erreur.value = cause
  } finally {
    if (tour === jeton) lecture.value = false
  }
}

// Au montage — le rendu serveur des tests ne lit rien —, puis à chaque relecture
// de la demande : une empreinte nouvelle, c'est un aperçu à refaire.
onMounted(lire)
watch(
  () => `${demande.id}/${demande.empreinte ?? ''}/${fusionPossible.value}`,
  () => {
    apercu.value = null
    void lire()
  },
)
</script>

<template>
  <section
    class="flex flex-col gap-2 rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm"
    data-bandeau-complement-de
  >
    <p v-if="origine !== null" class="m-0">
      <strong class="font-semibold">Cette demande complète la <RouterLink
        :to="{ name: 'demande.detail', params: { id: origine.demandeId } }"
        class="text-accent underline"
      >demande n° {{ origine.demandeId }}</RouterLink></strong><template v-if="origine.libelle !== null"> — {{ origine.libelle }}</template>.
      <template v-if="etat !== null"> Elle est {{ etat }}.</template>
    </p>
    <p v-else class="m-0">
      <strong class="font-semibold">Cette demande complète une proposition déjà en attente, faite par un autre membre.</strong>
      <template v-if="demande.duplicateOfId === null"> L’administrateur y fusionnera ses informations.</template>
    </p>

    <p v-if="demande.duplicateOfId !== null" class="m-0">
      Elle est close, rattachée à la <RouterLink
        :to="{ name: 'demande.detail', params: { id: demande.duplicateOfId } }"
        class="font-semibold text-accent underline"
      >demande n° {{ demande.duplicateOfId }}</RouterLink>.
    </p>

    <template v-if="fusionPossible">
      <SqueletteChargement v-if="lecture" :lignes="2" />
      <MessageErreur
        v-else-if="erreur !== null"
        :erreur="erreur"
        titre="L’aperçu de la fusion n’a pas pu être calculé."
        @action="emit('recharger')"
      />
      <FusionDuComplement
        v-else-if="apercu !== null && origine !== null"
        :key="`${apercu.origine.empreinte}-${apercu.complement.empreinte}`"
        :origine-id="origine.demandeId"
        :complement-id="demande.id"
        :entete="false"
        :rapport="apercu.rapport"
        :empreinte-origine="apercu.origine.empreinte"
        :empreinte-complement="apercu.complement.empreinte"
        :liens-du-complement="demande.liens"
        @fusionne="(resultat) => emit('fusionne', resultat)"
        @recharger="emit('recharger')"
      />
    </template>
  </section>
</template>
