<script setup lang="ts">
/**
 * La rangée d'onglets de la fiche : Identité et famille · Sources et documents ·
 * {n} · Historique — toujours trois, dans cet ordre. Le compteur d'Historique
 * est celui des demandes en attente, et n'apparaît que s'il y en a.
 *
 * Depuis la refonte du 30/09/2026, la rangée est `OngletsGlissants`, partagée
 * avec Explorer : un seul trait souligne l'onglet courant et **glisse** vers le
 * suivant, au lieu d'un filet qui disparaît ici et réapparaît là. Le contenu,
 * lui, coulisse dans `PageFiche` (`ContenuGlissant`).
 *
 * Chaque onglet est un **lien**, pas un bouton : il change `?onglet=` sur la
 * même route `personne.detail` (voir `onglets.ts`), si bien qu'un onglet se
 * partage, se recharge et revient au « précédent » du navigateur. L'onglet
 * courant porte `aria-current="page"`.
 *
 * Mobile : libellés courts (« Identité », « Sources · 4 », « Historique · 2 ») ;
 * trois onglets tiennent sans défiler.
 *
 * Le composant n'émet rien et ne retient rien : l'onglet actif vient de la vue,
 * qui le lit dans la requête.
 */
import { computed } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import OngletsGlissants, { type OngletGlissant } from '@/components/mise-en-page/OngletsGlissants.vue'
import { ongletsDeFiche, requeteDOnglet, type OngletFiche } from './onglets'

const { fiche, actif } = defineProps<{ fiche: FichePersonneComplete; actif: OngletFiche }>()

const onglets = computed<OngletGlissant<OngletFiche>[]>(() =>
  ongletsDeFiche(fiche).map((o) => ({
    cle: o.cle,
    libelle: o.libelle,
    libelleCourt: o.libelleCourt,
    compte: o.compte,
    vers: { name: 'personne.detail', params: { id: fiche.id }, query: requeteDOnglet(o.cle) },
  })),
)
</script>

<template>
  <div class="max-md:-mx-5 max-md:px-2">
    <OngletsGlissants :onglets="onglets" :actif="actif" etiquette="Sections de la fiche" />
  </div>
</template>
