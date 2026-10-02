<script setup lang="ts">
/**
 * La parenté : parents, fratrie, unions et enfants.
 *
 * **Ces trois listes disparaissent entièrement d'une fiche réduite**, alors que
 * les arêtes correspondantes restent dans le graphe — et ce n'est pas une
 * incohérence (§ 11.1) : « le graphe expose la topologie ; la fiche, elle,
 * exposerait les noms et les identifiants de la parenté sous forme de liste,
 * c'est-à-dire une énumération. Le graphe montre, il n'énumère pas. » Ce
 * composant ne reçoit donc qu'une fiche complète, et il n'a aucun cas d'absence
 * à traiter : si la clé était absente, le composant ne serait pas monté.
 *
 * **Une union sans conjoint nommé reste une union** (§ 5.4). Les dates
 * appartiennent à la famille, pas à la personne : elles restent visibles même
 * quand un époux est masqué, « sans quoi la fratrie devient fausse ». C'est
 * pourquoi `conjoint` est `null`-able et que son absence ne fait pas disparaître
 * le bloc.
 *
 * Les dates passent par `rendreDate()` : `texte` d'abord, `raw` ensuite, jamais
 * une recomposition depuis `min` et `max` (§ 14).
 */
import type { FichePersonneComplete } from '@/lib/api'
import { rendreDate } from './champs'
import ListeDeProches from './ListeDeProches.vue'

/**
 * `versArbre` : dans le volet de `/arbre`, un proche se choisit dans l'arbre au lieu
 * d'ouvrir sa fiche (`choisir`).
 */
defineProps<{ fiche: FichePersonneComplete; versArbre?: boolean }>()
const emit = defineEmits<{ choisir: [personId: number] }>()
</script>

<template>
  <div class="flex flex-col gap-5 text-sm">
    <!-- Unions --------------------------------------------------- -->
    <section v-if="fiche.unions.length > 0">
      <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">
        Union<template v-if="fiche.unions.length > 1">s — {{ fiche.unions.length }}</template>
      </h3>

      <div
        v-for="union in fiche.unions"
        :key="union.familyId"
        class="mb-2.5 border-l-2 pl-3"
        :class="union.divorced ? 'border-alerte' : 'border-ligne'"
      >
        <ListeDeProches
          v-if="union.conjoint !== null"
          class="font-semibold"
          :proches="[union.conjoint]"
          :vers-arbre="versArbre"
          @choisir="emit('choisir', $event)"
        />
        <p v-else class="m-0 text-attenue italic">conjoint inconnu</p>

        <p v-if="union.marriage !== null" class="m-0 text-xs text-attenue">
          ⚭ {{ rendreDate(union.marriage, 'date inconnue').principal }}
          <template v-if="union.marriage.place"> — {{ union.marriage.place }}</template>
        </p>

        <!-- `.ar.divorce` est **conservée** au rendu de l'arbre : « ce n'est pas
             une distance, c'est une information » (§ 7.3 du dossier). Ici aussi. -->
        <p v-if="union.divorced" class="m-0 text-xs text-alerte">
          Divorce<template v-if="union.divorce !== null">
            — {{ rendreDate(union.divorce, 'date inconnue').principal }}</template
          >
        </p>

        <ListeDeProches
          v-if="union.enfants.length > 0"
          class="mt-1"
          :proches="union.enfants"
          trier
          :vers-arbre="versArbre"
          @choisir="emit('choisir', $event)"
        />
      </div>
    </section>

    <!-- Parents -------------------------------------------------- -->
    <section v-if="fiche.parents.length > 0">
      <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">Parents</h3>
      <!-- `relation` vaut `naissance`, `adoption`… — valeur du modèle, non traduite.
           On ne l'affiche que lorsqu'elle apprend quelque chose. -->
      <ListeDeProches
        :proches="
          fiche.parents.map((p) => ({
            ...p,
            ...(p.relation !== 'naissance' ? { mention: p.relation.replace(/_/g, ' ') } : {}),
          }))
        "
        :vers-arbre="versArbre"
        @choisir="emit('choisir', $event)"
      />
    </section>

    <!-- Fratrie -------------------------------------------------- -->
    <section v-if="fiche.fratrie.length > 0">
      <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">Frères et sœurs</h3>
      <!-- L'aîné en haut : par année de naissance, puis l'ordre de la fratrie. -->
      <ListeDeProches
        :proches="[...fiche.fratrie].sort((a, b) => a.siblingOrder - b.siblingOrder)"
        trier
        :vers-arbre="versArbre"
        @choisir="emit('choisir', $event)"
      />
    </section>

    <p
      v-if="fiche.unions.length === 0 && fiche.parents.length === 0 && fiche.fratrie.length === 0"
      class="m-0 text-attenue italic"
    >
      Aucun lien de parenté connu.
    </p>
  </div>
</template>
