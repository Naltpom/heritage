<script setup lang="ts">
/**
 * La rangée d'onglets de la fiche : Identité · Famille · Sources et notes · {n} ·
 * Preuves et documents · {n} · Demandes · {n}.
 *
 * Chaque onglet est un **lien**, pas un bouton : il change `?onglet=` sur la
 * même route `personne.detail` (voir `onglets.ts`), si bien qu'un onglet se
 * partage, se recharge et revient au « précédent » du navigateur. L'onglet
 * courant porte `aria-current="page"`.
 *
 * Desktop : `padding: 10px 12px`, onglet actif en accent, 600, filet de 2 px.
 * Mobile : rangée de 44 px qui **défile** horizontalement, libellés courts
 * (« Sources · 4 », « Documents · 3 »).
 *
 * Le composant n'émet rien et ne retient rien : l'onglet actif vient de la vue,
 * qui le lit dans la requête.
 */
import { computed } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import { ongletsDeFiche, requeteDOnglet, type OngletFiche } from './onglets'

const { fiche, actif } = defineProps<{ fiche: FichePersonneComplete; actif: OngletFiche }>()

const onglets = computed(() => ongletsDeFiche(fiche))
</script>

<template>
  <nav
    aria-label="Sections de la fiche"
    class="flex gap-1 whitespace-nowrap max-md:-mx-5 max-md:overflow-x-auto max-md:px-2 max-md:[scrollbar-width:none]"
  >
    <RouterLink
      v-for="onglet in onglets"
      :key="onglet.cle"
      :to="{ name: 'personne.detail', params: { id: fiche.id }, query: requeteDOnglet(onglet.cle) }"
      :aria-current="onglet.cle === actif ? 'page' : undefined"
      :data-onglet="onglet.cle"
      class="flex flex-none items-center border-b-2 px-3 py-2.5 text-sm transition-colors max-md:h-11 max-md:py-0"
      :class="
        onglet.cle === actif
          ? 'border-accent font-semibold text-accent'
          : 'border-transparent text-attenue hover:text-texte'
      "
    >
      <span class="max-md:hidden">{{ onglet.libelle }}</span>
      <span class="md:hidden">{{ onglet.libelleCourt }}</span>
      <template v-if="onglet.compte !== null">
        <span aria-hidden="true">&nbsp;·&nbsp;</span>
        <span class="tnum">{{ onglet.compte }}</span>
      </template>
    </RouterLink>
  </nav>
</template>
