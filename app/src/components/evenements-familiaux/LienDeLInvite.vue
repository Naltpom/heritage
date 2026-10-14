<script setup lang="ts">
/**
 * Le lien personnel d'un invité sans compte, juste après sa création — à
 * copier ou à partager.
 *
 * ────────────────────────────────────────────────────────────────────
 * **C'est le seul moment où ce lien existe en clair**, comme celui d'un
 * participant de tirage (`LienDuParticipant`). Le serveur n'en garde qu'une
 * empreinte : une fois ce panneau fermé, on ne peut plus le relire. Pour le
 * renvoyer, il faut en créer un nouveau — et l'ancien cesse de marcher.
 * ────────────────────────────────────────────────────────────────────
 *
 * Deux gestes, parce qu'on est sur un téléphone :
 *
 * - **Partager** ouvre la feuille de partage du système (SMS, WhatsApp,
 *   courriel…) avec un message tout fait — c'est le geste naturel pour
 *   envoyer un lien à sa grand-mère. Absent quand le navigateur ne sait pas
 *   partager (bureau, surtout).
 * - **Copier**, toujours : on colle où l'on veut. Refusé (contexte non
 *   sécurisé, permission), l'URL reste affichée en entier, sélectionnable.
 *
 * Le lien ouvre la réponse **de cette personne** : qui le reçoit par erreur
 * peut répondre à sa place. L'écran le dit.
 */
import { onMounted, ref, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import { copierTexte, partager as ouvrirFeuilleDePartage, peutPartager as navigateurSaitPartager } from '@/composables/partage'

const { libelle, adresse, message, dejaCopie = false } = defineProps<{
  libelle: string
  adresse: string
  /** Le texte qui accompagne le lien dans la feuille de partage. */
  message: string
  /** La copie automatique, tentée à la création, a réussi. */
  dejaCopie?: boolean
}>()

const emit = defineEmits<{ fermer: [] }>()

const copie = ref(dejaCopie)
watch(
  () => adresse,
  () => {
    copie.value = dejaCopie
  },
)

/** Lu au montage seulement : le rendu serveur n'a pas de `navigator`. */
const peutPartager = ref(false)
onMounted(() => {
  peutPartager.value = navigateurSaitPartager()
})

async function copier(): Promise<void> {
  // Refusé : l'URL reste affichée en entier, sélectionnable. Ce n'est pas une panne.
  copie.value = await copierTexte(adresse)
}

async function partager(): Promise<void> {
  // Annulé par la personne, ou refusé : rien à dire, le bouton « Copier » reste là.
  await ouvrirFeuilleDePartage({ titre: message, texte: message, url: adresse })
}
</script>

<template>
  <div class="flex flex-col gap-2 rounded-panneau border border-ligne bg-fond p-3" role="region" :aria-label="`Lien de ${libelle}`">
    <p class="text-sm font-semibold text-texte">Le lien de {{ libelle }}</p>

    <p class="rounded-champ border border-ligne bg-panneau px-3 py-2 font-mono text-xs break-all select-all">
      {{ adresse }}
    </p>

    <div class="flex flex-wrap items-center gap-2">
      <BoutonBase v-if="peutPartager" @click="partager">Partager</BoutonBase>
      <BoutonBase variante="secondaire" @click="copier">Copier le lien</BoutonBase>
      <span v-if="copie" class="text-sm text-ok" role="status">Copié dans le presse-papiers.</span>
      <BoutonBase variante="discret" @click="emit('fermer')">J’ai envoyé le lien</BoutonBase>
    </div>

    <p class="text-xs font-semibold text-alerte">
      Ce lien permet de répondre pour {{ libelle }} : ne l’envoyez qu’à cette personne.
    </p>
    <p class="text-xs text-attenue">
      Il ne s’affichera plus : pour le renvoyer, créez-en un nouveau.
    </p>
  </div>
</template>
