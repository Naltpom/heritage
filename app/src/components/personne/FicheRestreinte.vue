<script setup lang="ts">
/**
 * L'impasse assumée — **la fiche d'une personne vivante hors de ce que le
 * lecteur peut consulter.**
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ce n'est pas une panne, et l'écran doit le dire au lieu de le subir.**
 *
 * Le serveur rend `200` avec **sept clés** : `id`, `displayName`, `sex`,
 * `generation`, `branch`, `vivant`, `visibilite` (§ 11.1). C'est la **seule
 * exception** à la règle « une ressource qu'on n'a pas le droit de voir rend
 * `404` » — et elle existe pour une raison précise : « son nœud est visible dans
 * le graphe et il faut pouvoir cliquer dessus » (§ 7.4, § 5.1). Le cercle masque
 * des **champs**, pas des **nœuds** : un arbre dont on retire des nœuds n'est
 * plus un arbre, « les branches flottent, les fratries sont fausses, le calcul
 * de parenté est faux » (ADR 0003).
 *
 * Cet écran sera donc vu souvent — une fois par clic sur chacune des personnes
 * vivantes hors cercle du lecteur. Il doit rendre deux choses, et rien d'autre :
 * une **raison** et une **sortie**. Ni écran d'erreur, ni chargement qui ne finit
 * pas, ni case vide qu'on prendrait pour un bogue.
 *
 * **Pas de nom** (révision du 2026-10-01, ADR 0003) : le serveur n'envoie plus que le
 * libellé neutre « Personne vivante » dans `displayName`, et cet écran ne l'écrit pas
 * — le titre du panneau et la phrase disent déjà tout.
 *
 * **Le front ne filtre rien et n'a rien à filtrer.** Les champs manquants ne
 * sont pas cachés ici : ils ne sont pas arrivés. C'est la différence que le
 * § 11 tient à rendre indiscutable — « il suffit d'ouvrir l'onglet réseau du
 * navigateur pour voir que filtrer à l'affichage n'en serait pas une
 * implémentation acceptable ».
 * ────────────────────────────────────────────────────────────────────
 *
 * La seule chose que cet écran lit de la session est le **régime**, et
 * uniquement pour ne pas écrire une phrase fausse : dire « cette personne n'est
 * pas dans votre cercle » à un compte en attente de validation serait doublement
 * inexact — il n'a **pas** de cercle (§ 12), et *aucune* personne vivante ne lui
 * est accessible, pas même celle à laquelle il prétend se rattacher. La donnée
 * affichée, elle, ne dépend pas du lecteur : elle est identique dans les deux cas.
 */
import { useSession } from '@/composables/useSession'
import type { FichePersonneRestreinte } from '@/lib/api'
import PanneauBase from '@/components/base/PanneauBase.vue'
import IdentiteFiche from './IdentiteFiche.vue'

defineProps<{ fiche: FichePersonneRestreinte }>()

const { regime } = useSession()
</script>

<template>
  <PanneauBase titre="Fiche réservée" class="mb-4">
    <p class="text-sm leading-relaxed">
      <template v-if="regime === 'en_attente'">
        <strong class="font-semibold">Cette personne est vivante.</strong>
        Vous la verrez une fois votre inscription validée.
      </template>
      <!-- Depuis le 2026-10-01, un membre validé voit tous les vivants : une fiche
           réduite est, pour lui, une fiche à confidentialité forcée (ADR 0003). -->
      <template v-else>
        <strong class="font-semibold">Fiche confidentielle.</strong>
        Un administrateur l’a réservée : ni dates, ni lieux, ni sources, ni photos, ni parenté
        ne vous sont envoyés.
      </template>
    </p>

    <p class="mt-2 text-sm leading-relaxed text-attenue">
      Sa place dans l’arbre reste visible.
    </p>

    <!-- Les trois champs que les sept clés permettent d'écrire. Aucun n'est
         choisi ici : `construireLignes()` les tire de la charge utile. -->
    <div class="mt-4">
      <IdentiteFiche :fiche="fiche" />
    </div>

    <template #pied>
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
        <RouterLink :to="{ name: 'arbre' }" class="font-semibold text-accent underline">
          Revenir à l’arbre
        </RouterLink>
        <span class="text-xs text-attenue">
          Seul un administrateur peut changer cet accès.
        </span>
      </div>
    </template>
  </PanneauBase>
</template>
