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
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import EnTeteApplication from '@/components/mise-en-page/EnTeteApplication.vue'
import BandeauPortrait from '@/components/mise-en-page/BandeauPortrait.vue'
import InstallationApplication from '@/components/mise-en-page/InstallationApplication.vue'
import BandeauRegime from '@/components/mise-en-page/BandeauRegime.vue'
// Le créneau `quitter-vue` du bandeau existait, vide : la sortie de la bascule
// doit être là où le bandeau est, c'est-à-dire partout. Un administrateur qui a
// activé « voir comme » depuis l'administration parcourt ensuite l'arbre ; le
// faire revenir dans l'administration pour en sortir, c'est le laisser explorer
// sous une identité qu'il a oubliée (§ 10.4).
import QuitterVueEmpruntee from '@/components/administration/QuitterVueEmpruntee.vue'

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
      </BandeauRegime>
    </template>

    <main id="contenu" class="flex-1">
      <RouterView />
    </main>
  </div>
</template>
