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
 * Les dates d'union passent par `union.ts` : `texte` d'abord, `raw` ensuite, jamais
 * une recomposition depuis `min` et `max` (§ 14) — et la nature de l'union décide de
 * ce qu'on dit (ADR 0020).
 */
import type { FichePersonneComplete } from '@/lib/api'
import ListeDeProches from './ListeDeProches.vue'
import { phraseDeLaRupture, phraseDuMariage } from './union'

/**
 * `versArbre` : dans le volet de `/arbre`, un proche se choisit dans l'arbre au lieu
 * d'ouvrir sa fiche (`choisir`).
 */
defineProps<{ fiche: FichePersonneComplete; versArbre?: boolean }>()
const emit = defineEmits<{ choisir: [personId: number] }>()

/** « 1re union », « 2e union »… dans l’ordre où le serveur les donne. */
function rangDUnion(rang: number): string {
  return rang === 0 ? '1re union' : `${rang + 1}e union`
}
</script>

<template>
  <div class="flex flex-col gap-5 text-sm">
    <!-- Unions --------------------------------------------------- -->
    <!-- Chaque union est un bloc à deux étages, pour qu'on lise d'un coup
         « avec qui » puis « leurs enfants » : le conjoint en gras, couleur du
         texte, avec le mariage et le divorce ; puis, en retrait sous un liseré,
         les enfants de CETTE union, en liens. Avant, conjoint et enfants avaient le
         même style et se suivaient sans rupture : avec deux unions, on ne savait
         plus qui était l'épouse et qui était l'enfant. -->
    <section v-if="fiche.unions.length > 0" class="flex flex-col gap-2.5">
      <h3 class="text-[11px] tracking-wider text-attenue uppercase">
        Union<template v-if="fiche.unions.length > 1">s — {{ fiche.unions.length }}</template>
      </h3>

      <article
        v-for="(union, rang) in fiche.unions"
        :key="union.familyId"
        data-union
        class="rounded-champ border bg-fond/40 px-3 py-2.5"
        :class="union.divorced ? 'border-alerte-bord' : 'border-ligne'"
      >
        <p v-if="fiche.unions.length > 1" class="m-0 mb-1 text-[11px] font-semibold tracking-wider text-attenue uppercase">
          {{ rangDUnion(rang) }}
        </p>

        <div class="flex items-baseline gap-2">
          <span class="text-attenue" aria-hidden="true">⚭</span>
          <ListeDeProches
            v-if="union.conjoint !== null"
            class="min-w-0 flex-1"
            principal
            :proches="[union.conjoint]"
            :vers-arbre="versArbre"
            @choisir="emit('choisir', $event)"
          />
          <p v-else class="m-0 text-attenue italic">conjoint inconnu</p>
        </div>

        <!-- La nature de l'union (ADR 0020) : « Mariés le … », « Mariés — date
             inconnue », « Union libre » — et rien quand on ne sait pas, plutôt que
             l'ancien « Mariage : date inconnue » qui affirmait un mariage sous
             chaque union, union libre comprise. -->
        <p v-if="phraseDuMariage(union) !== null" class="m-0 pl-5 text-xs text-attenue" data-nature-union>
          {{ phraseDuMariage(union) }}
        </p>

        <!-- `.ar.divorce` est **conservée** au rendu de l'arbre : « ce n'est pas
             une distance, c'est une information » (§ 7.3 du dossier). Ici aussi.
             « Divorcés » après un mariage, « Séparés » sinon. -->
        <p v-if="union.divorced" class="m-0 pl-5 text-xs text-alerte" data-rupture-union>
          {{ phraseDeLaRupture(union) }}
        </p>

        <div v-if="union.enfants.length > 0" data-enfants-de-l-union class="mt-2 ml-1.5 border-l-2 border-ligne pl-3">
          <p class="m-0 mb-0.5 text-[11px] tracking-wider text-attenue uppercase">
            {{ union.conjoint !== null ? 'Leurs enfants' : 'Enfants' }} · {{ union.enfants.length }}
          </p>
          <ListeDeProches
            :proches="union.enfants"
            trier
            :vers-arbre="versArbre"
            @choisir="emit('choisir', $event)"
          />
        </div>
      </article>
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
