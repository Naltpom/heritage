<script setup lang="ts">
/**
 * L'invitation **automatique** à installer Heritage sur le téléphone, à
 * l'arrivée dans l'application — après celle de la clé d'accès, avant celle des
 * notifications. Elle remplace le bandeau qu'on montait sous l'en-tête.
 *
 * Monté une fois, par `App.vue`, **hors** du bloc du cadre — comme
 * `InvitationCleDAcces` et `InvitationNotifications`, et pour la même raison :
 * démonté à chaque écran sans cadre, il perdrait sa fenêtre ouverte et la
 * réponse attendue du navigateur.
 *
 * Toute la décision (qui, quand, combien de fois) est dans
 * `piloterInvitationInstallation()`. Ici : la largeur de l'écran, que seul un
 * composant monté sait suivre, et la fenêtre.
 */
import { piloterInvitationInstallation } from '@/composables/invitationInstallation'
import { useEstMobile } from '@/composables/useTiroirs'
import FenetreInvitationInstallation from './FenetreInvitationInstallation.vue'

const { avecCadre } = defineProps<{ avecCadre: boolean }>()

const estMobile = useEstMobile()
const { forme, enCours, installer, plusTard } = piloterInvitationInstallation(
  () => avecCadre,
  () => estMobile.value,
)
</script>

<template>
  <FenetreInvitationInstallation :forme="forme" :en-cours="enCours" @installer="installer" @plus-tard="plusTard" />
</template>
