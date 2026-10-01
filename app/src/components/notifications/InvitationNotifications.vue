<script setup lang="ts">
/**
 * L'invitation **automatique** à activer les notifications du téléphone.
 *
 * Monté une fois, par `App.vue`, **hors** du bloc du cadre : il reçoit
 * `avecCadre` en propriété plutôt que d'être démonté à chaque écran sans cadre.
 * Démonté, il perdrait sa fenêtre ouverte et sa confirmation en cours.
 *
 * Toute la décision (qui, quand, combien de fois) est dans
 * `piloterInvitationPush()` — voir « L'invitation automatique » dans `usePush`.
 * Ici : la fenêtre, et le bref « Notifications activées » qui suit un accord.
 *
 * L'état du push est relu au montage, comme le fait la ligne de Moi : la
 * permission a pu changer dans les réglages pendant que l'application dormait.
 */
import { onMounted } from 'vue'
import { piloterInvitationPush, usePush } from '@/composables/usePush'
import FenetreInvitationNotifications from './FenetreInvitationNotifications.vue'

const { avecCadre } = defineProps<{ avecCadre: boolean }>()

const { evaluer } = usePush()
const { forme, confirmation, enCours, accepter, plusTard } = piloterInvitationPush(() => avecCadre)

onMounted(() => void evaluer())
</script>

<template>
  <FenetreInvitationNotifications :forme="forme" :en-cours="enCours" @activer="accepter" @plus-tard="plusTard" />

  <!-- Au-dessus de la barre du bas sur téléphone, en bas à droite sur bureau.
       `role="status"` : annoncé sans voler le focus. -->
  <div
    v-if="confirmation"
    role="status"
    class="fixed inset-x-4 bottom-[calc(var(--hauteur-barre-bas,0px)+1rem)] z-50 mx-auto w-fit rounded-pastille border border-ok-bord bg-ok-doux px-4 py-2 text-sm font-semibold text-ok shadow-panneau md:right-6 md:left-auto"
  >
    Notifications activées
  </div>
</template>
