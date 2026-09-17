<script setup lang="ts">
/**
 * Le point d'entrée « demander la suppression de cette fiche ».
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Un lien, et rien d'autre — pour une raison de plus qu'ailleurs
 *
 * `PointProposition.vue` explique déjà pourquoi il ne pousse pas la `version` :
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
 * ## Le ton, et pourquoi il n'est pas alarmiste
 *
 * Ce bouton ne supprime rien. Il ouvre un formulaire qui dépose une demande,
 * qu'un administrateur validera ou refusera — exactement comme une correction de
 * date. Le rouge appartient à l'écran de confirmation, où l'on voit ce qui part ;
 * ici, une couleur d'alerte ferait croire à un geste irréversible et
 * découragerait de signaler un doublon, ce qui est le contraire du but.
 *
 * ## Pourquoi un `v-if` sur le rôle est légitime ici
 *
 * Mêmes deux conditions, mêmes raisons que `PointProposition.vue` : un compte en
 * attente de rattachement recevrait `403 regime_en_attente`, et toute écriture
 * sous la bascule « voir comme un membre » rend `409`. Masquer le lien n'est pas
 * une mesure de sécurité — la garde de route le double, et l'API double les deux.
 * C'est éviter de proposer un geste voué au refus.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import { useSession } from '@/composables/useSession'

const { fiche } = defineProps<{ fiche: FichePersonneComplete }>()

const { rattache, vueEmpruntee } = useSession()

const ouvert = computed(() => rattache.value && vueEmpruntee.value === null)
</script>

<template>
  <div v-if="ouvert" id="suppression" class="flex flex-col gap-2">
    <p class="m-0 text-sm text-attenue">
      Cette fiche fait doublon avec une autre, ou n’aurait jamais dû exister&nbsp;? Demandez sa
      suppression&nbsp;: l’écran vous montrera d’abord tout ce qu’elle emporterait — notes, sources,
      unions — et vous demandera d’écrire pourquoi. Un administrateur tranche ensuite.
    </p>

    <p class="m-0">
      <RouterLink
        :to="{ name: 'proposition.suppression', params: { id: fiche.id } }"
        class="inline-flex items-center rounded-champ border border-ligne bg-panneau px-3.5 py-1.5 text-sm font-semibold text-texte transition-colors hover:border-alerte"
      >
        Demander la suppression de cette fiche
      </RouterLink>
    </p>
  </div>
</template>
