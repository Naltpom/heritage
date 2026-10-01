<script setup lang="ts">
/**
 * L'invitation **automatique** à créer une clé d'accès, juste après une
 * connexion par mot de passe.
 *
 * Monté une fois, par `App.vue`, **hors** du bloc du cadre — comme
 * `InvitationNotifications`, et pour la même raison : la connexion est un écran
 * sans cadre, et c'est en le quittant que l'invitation doit naître ; démonté à
 * chaque écran sans cadre, le pilote perdrait la connexion qu'il attend.
 *
 * Toute la décision (qui, quand, combien de fois) est dans
 * `piloterInvitationCleDAcces()`. Ici : la fenêtre, et le bref message qui suit.
 */
import { piloterInvitationCleDAcces } from '@/composables/useClesDAcces'
import FenetreInvitationCleDAcces from './FenetreInvitationCleDAcces.vue'

const { avecCadre } = defineProps<{ avecCadre: boolean }>()

const { ouverte, enCours, message, erreur, confirmation, accepter, plusTard } = piloterInvitationCleDAcces(
  () => avecCadre,
)
</script>

<template>
  <FenetreInvitationCleDAcces
    :ouverte="ouverte"
    :en-cours="enCours"
    :message="message"
    :erreur="erreur"
    @activer="accepter"
    @plus-tard="plusTard"
  />

  <!-- Même place que « Notifications activées » : au-dessus de la barre du bas
       sur téléphone, en bas à droite sur bureau. -->
  <div
    v-if="confirmation !== null"
    role="status"
    class="fixed inset-x-4 bottom-[calc(var(--hauteur-barre-bas,0px)+1rem)] z-50 mx-auto w-fit max-w-[calc(100vw-2rem)] rounded-pastille border border-ok-bord bg-ok-doux px-4 py-2 text-sm font-semibold text-ok shadow-panneau md:right-6 md:left-auto"
  >
    {{ confirmation }}
  </div>
</template>
