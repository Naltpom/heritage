<script setup lang="ts">
/**
 * Le lien personnel d'un participant, juste après sa création — à copier et à
 * lui envoyer.
 *
 * ────────────────────────────────────────────────────────────────────
 * **C'est le seul moment où ce lien existe en clair**, comme le lien
 * d'invitation (`LienInvitation`). Le serveur n'en garde qu'une empreinte : une
 * fois ce panneau fermé, on ne peut plus le relire. Pour le renvoyer, il faut en
 * **régénérer** un — et l'ancien cesse de marcher. L'écran le dit avant qu'on
 * ferme.
 * ────────────────────────────────────────────────────────────────────
 *
 * L'URL est affichée **en entier**, en chasse fixe et sélectionnable : le
 * presse-papiers peut être refusé (contexte non sécurisé, permission), et la
 * recopier à la main reste alors le chemin de repli.
 *
 * Et l'avertissement, toujours : ce lien ouvre la présence **et le cadeau** de
 * la personne, sans mot de passe. Qui le reçoit par erreur lit son secret.
 */
import { ref, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'

const { libelle, adresse, dejaCopie = false } = defineProps<{
  libelle: string
  adresse: string
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

async function copier(): Promise<void> {
  try {
    await navigator.clipboard.writeText(adresse)
    copie.value = true
  } catch {
    // Refusé : l'URL reste affichée en entier, sélectionnable. Ce n'est pas une panne.
    copie.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-2 rounded-panneau border border-ligne bg-fond p-3" role="region" :aria-label="`Lien de ${libelle}`">
    <p class="text-sm font-semibold text-texte">Le lien de {{ libelle }}</p>

    <p class="rounded-champ border border-ligne bg-panneau px-3 py-2 font-mono text-xs break-all select-all">
      {{ adresse }}
    </p>

    <div class="flex flex-wrap items-center gap-2">
      <BoutonBase variante="secondaire" @click="copier">Copier son lien</BoutonBase>
      <span v-if="copie" class="text-sm text-ok" role="status">Copié dans le presse-papiers.</span>
      <BoutonBase variante="discret" @click="emit('fermer')">J’ai envoyé le lien</BoutonBase>
    </div>

    <p class="text-xs font-semibold text-alerte">
      Ce lien donne accès à sa présence et à son cadeau : ne le partagez qu’avec {{ libelle }}.
    </p>
    <p class="text-xs text-attenue">
      Il ne s’affichera plus : pour le renvoyer, régénérez-le.
    </p>
  </div>
</template>
