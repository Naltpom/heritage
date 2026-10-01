<script setup lang="ts">
/**
 * L'invitation **automatique** à créer une clé d'accès, à l'arrivée dans
 * l'application — comme celle des notifications, et juste avant elle.
 *
 * Monté une fois, par `App.vue`, **hors** du bloc du cadre — comme
 * `InvitationNotifications`, et pour la même raison : la connexion est un écran
 * sans cadre, et c'est en le quittant que l'invitation doit pouvoir naître ;
 * démonté à chaque écran sans cadre, le pilote perdrait la session qu'il
 * attend.
 *
 * Toute la décision (qui, quand, combien de fois, sur quelle étape) est dans
 * `piloterInvitationCleDAcces()`. Ici : la fenêtre, et le bref message qui suit.
 */
import { piloterInvitationCleDAcces } from '@/composables/useClesDAcces'
import FenetreInvitationCleDAcces from './FenetreInvitationCleDAcces.vue'

const { avecCadre } = defineProps<{ avecCadre: boolean }>()

const {
  ouverte,
  etape,
  motDePasseConfirme,
  systeme,
  enCours,
  message,
  erreurMotDePasse,
  erreur,
  confirmation,
  accepter,
  confirmerMotDePasse,
  plusTard,
} = piloterInvitationCleDAcces(() => avecCadre)
</script>

<template>
  <FenetreInvitationCleDAcces
    :ouverte="ouverte"
    :etape="etape"
    :mot-de-passe-confirme="motDePasseConfirme"
    :systeme="systeme"
    :en-cours="enCours"
    :message="message"
    :erreur-mot-de-passe="erreurMotDePasse"
    :erreur="erreur"
    @activer="accepter"
    @confirmer="confirmerMotDePasse"
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
