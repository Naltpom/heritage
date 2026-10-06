<script setup lang="ts">
/**
 * Le cadre de l'application.
 *
 * Il porte l'en-tête, les bandeaux permanents et le point de rendu des écrans.
 * Les écrans d'entrée (connexion, inscription) sont rendus **sans cadre** :
 * `meta.cadre === false`. Un en-tête portant « Se déconnecter » sur une page de
 * connexion serait au mieux étrange.
 *
 * Le contenu est dans un `<main id="contenu">` unique, cible du lien d'évitement :
 * la navigation du clavier ne doit pas retraverser le menu à chaque écran.
 *
 * **La barre du bas** (téléphone, refonte du 30/09/2026) est montée dans le même
 * bloc que l'en-tête : un écran sans cadre n'a ni l'un ni l'autre. Elle est
 * `fixed` ; le `<main>` lui réserve donc sa hauteur en marge basse, lue dans
 * `--hauteur-barre-bas` que la barre publie (0 sur bureau, et 0 tant qu'elle
 * n'existe pas). Les écrans à canevas la retranchent de leur côté, dans
 * `hauteurDuCadre()`.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import EnTeteApplication from '@/components/mise-en-page/EnTeteApplication.vue'
import BarreNavigation from '@/components/mise-en-page/BarreNavigation.vue'
import BandeauPortrait from '@/components/mise-en-page/BandeauPortrait.vue'
import InstallationApplication from '@/components/mise-en-page/InstallationApplication.vue'
import BandeauRegime from '@/components/mise-en-page/BandeauRegime.vue'
// Le créneau `quitter-vue` du bandeau existait, vide : la sortie de la bascule
// doit être là où le bandeau est, c'est-à-dire partout. Un administrateur qui a
// activé « voir comme » depuis l'administration parcourt ensuite l'arbre ; le
// faire revenir dans l'administration pour en sortir, c'est le laisser explorer
// sous une identité qu'il a oubliée (§ 10.4).
import QuitterIncarnation from '@/components/administration/QuitterIncarnation.vue'
import QuitterVueEmpruntee from '@/components/administration/QuitterVueEmpruntee.vue'
// L'invitation automatique à activer les notifications du téléphone. Montée
// HORS du bloc du cadre, qui lui passe seulement `avec-cadre` : elle ne s'ouvre
// que sur un écran avec cadre, mais un passage par un écran sans cadre ne doit
// pas lui faire perdre sa fenêtre ni son « Notifications activées ».
import InvitationNotifications from '@/components/notifications/InvitationNotifications.vue'
// L'invitation à créer une clé d'accès (Face ID, empreinte), à l'arrivée dans
// l'application. Même montage que la précédente ; elle passe AVANT elle, et les deux ne
// s'ouvrent jamais ensemble (`composables/fenetresAutomatiques.ts`).
import InvitationCleDAcces from '@/components/cles-d-acces/InvitationCleDAcces.vue'
// L'avis global (un écran qui n'a pas pu se charger, une erreur que personne
// n'a attrapée) : hors du bloc du cadre, un écran sans cadre peut échouer aussi.
import AvisGlobal from '@/components/mise-en-page/AvisGlobal.vue'

const route = useRoute()
const avecCadre = computed(() => route.meta.cadre !== false)
</script>

<template>
  <a
    href="#contenu"
    class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-champ focus:border focus:border-accent focus:bg-panneau focus:px-3 focus:py-1.5 focus:text-sm"
  >
    Aller au contenu
  </a>

  <div class="flex min-h-dvh flex-col">
    <template v-if="avecCadre">
      <EnTeteApplication />
      <BandeauPortrait />
      <InstallationApplication />
      <BandeauRegime>
        <template #quitter-vue>
          <QuitterVueEmpruntee />
        </template>
        <template #quitter-incarnation>
          <QuitterIncarnation />
        </template>
      </BandeauRegime>
      <BarreNavigation />
    </template>

    <main id="contenu" class="flex-1 pb-[var(--hauteur-barre-bas,0px)]">
      <RouterView />
    </main>
  </div>

  <InvitationCleDAcces :avec-cadre="avecCadre" />
  <InvitationNotifications :avec-cadre="avecCadre" />
  <AvisGlobal />
</template>
