<script setup lang="ts">
/**
 * Le point d'entrée « demander la suppression de cette fiche ».
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Un lien, et rien d'autre — pour une raison de plus qu'ailleurs
 *
 * `MenuFiche.vue` explique déjà pourquoi il ne pousse pas la `version` :
 * l'écran cible relit la fiche, et ce qu'il en tire est par construction ce que
 * l'auteur a sous les yeux au moment où il commence. Ici, l'argument est plus
 * fort encore.
 *
 * L'écran de suppression n'affiche pas des valeurs de champs : il affiche
 * **l'inventaire de ce que la suppression emporte**, c'est-à-dire un décompte
 * qu'aucune charge utile de fiche ne porte — elle ne sait pas combien de notes
 * partiraient avec les faits, ni si une union survivrait à la perte d'un époux.
 * Ce décompte est servi par sa propre route, `GET
 * /api/demandes/suppression/personne/{id}`, relue à chaque ouverture. Lui
 * transmettre quoi que ce soit d'ici ne ferait que lui donner une seconde source,
 * plus ancienne, pour une opération qui détruit.
 *
 * ## Un geste séparé (règle n° 9 de la refonte)
 *
 * « Corriger » et « effacer » ne sont pas deux variantes d'un même geste : un seul
 * détruit — et un doublon d'arbre généalogique ne se corrige pas, il se
 * supprime. Ce lien n'est donc **jamais** mêlé aux entrées qui corrigent : il
 * vit en dernier dans le menu « Proposer » (`MenuFiche.vue`), **séparé par un
 * filet**, et en couleur d'alerte. La couleur signale qu'on quitte
 * le registre de la correction ; le libellé dit « demander », parce que ce lien
 * ne supprime rien — il ouvre un formulaire qui montre d'abord tout ce que la
 * suppression emporterait, puis dépose une demande qu'un administrateur tranche.
 *
 * ## Pourquoi un `v-if` sur le rôle est légitime ici
 *
 * Mêmes deux conditions, mêmes raisons que les autres entrées du menu (voir `gestes.ts`) : un compte en
 * attente de rattachement recevrait `403 regime_en_attente`, et toute écriture
 * sous la bascule « voir comme un membre » rend `409`. Masquer le lien n'est pas
 * une mesure de sécurité — la garde de route le double, et l'API double les deux.
 * C'est éviter de proposer un geste voué au refus.
 * ────────────────────────────────────────────────────────────────────
 */
import type { FichePersonneComplete } from '@/lib/api'
import { useGestesFiche } from './gestes'

const { fiche } = defineProps<{ fiche: FichePersonneComplete }>()

const { peutDemanderSuppression } = useGestesFiche()
</script>

<template>
  <RouterLink
    v-if="peutDemanderSuppression"
    :to="{ name: 'proposition.suppression', params: { id: fiche.id } }"
    role="menuitem"
    data-geste="demander-suppression"
    title="L’écran vous montrera d’abord tout ce que la suppression emporterait, et vous demandera d’écrire pourquoi."
    class="flex min-h-11 items-center border-t border-ligne px-3.5 text-sm font-semibold text-alerte hover:bg-alerte-doux md:min-h-10"
  >
    Demander la suppression
  </RouterLink>
</template>
