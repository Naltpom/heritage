<script setup lang="ts">
/**
 * La fiche proposée d'une création de personne, et ses liens.
 *
 * Une création ne se juge pas comme une modification : il n'y a pas de « valeur
 * actuelle » à comparer, puisque rien n'existe encore. Ce qu'il faut à
 * l'administrateur, c'est la fiche telle qu'elle sera écrite, **et ses liens** —
 * « au moins un lien, sinon la personne flotterait hors de l'arbre » (§ 6.4 du
 * dossier), et c'est le lien qui dit si la personne est à sa place.
 *
 * Deux choses que ce composant ne fait pas, délibérément :
 *
 * - **il ne recompose aucune date** : `texte` vient du serveur, on l'affiche ;
 * - **il n'affiche pas les clés absentes.** Une charge qui ne porte pas `death`
 *   ne donne pas lieu à une ligne « Décès : — » : absent et vide ont deux sens
 *   (§ 1.3), et inventer un tiret sur un champ absent efface la distinction.
 */
import { computed } from 'vue'
import type { BrouillonDemande, LienDemande } from '@/lib/api'
import { liensLisibles } from './liens'

const { brouillons, liens } = defineProps<{
  brouillons: BrouillonDemande[]
  liens: LienDemande[]
}>()

const SEXES: Record<string, string> = {
  masculin: 'masculin',
  feminin: 'féminin',
  inconnu: 'inconnu',
}

const lisibles = computed(() => liensLisibles(liens))

/** Le `texte` du serveur, ou `null` — jamais une date fabriquée ici. */
function texteDate(bloc: BrouillonDemande['birth']): string | null {
  if (bloc === null || bloc === undefined) return null
  const texte = (bloc as { texte?: unknown }).texte
  if (typeof texte === 'string' && texte.length > 0) return texte
  const brut = (bloc as { raw?: unknown }).raw
  return typeof brut === 'string' && brut.length > 0 ? brut : null
}

function nomComplet(brouillon: BrouillonDemande): string {
  return [brouillon.givenNames, brouillon.surname].filter((part) => Boolean(part)).join(' ')
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <article
      v-for="brouillon in brouillons"
      :key="brouillon.localRef"
      class="rounded-champ border border-ligne bg-fond px-3.5 py-3"
    >
      <h3 class="text-sm font-semibold">{{ nomComplet(brouillon) }}</h3>

      <dl class="mt-2 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
        <template v-if="brouillon.marriedName">
          <!-- Le terme unique du champ `marriedName`, cf. `administration/diff.ts`. -->
          <dt class="text-attenue">Nom d’usage</dt>
          <dd>{{ brouillon.marriedName }}</dd>
        </template>

        <template v-if="brouillon.nickname">
          <dt class="text-attenue">Surnom</dt>
          <dd>{{ brouillon.nickname }}</dd>
        </template>

        <dt class="text-attenue">Sexe</dt>
        <dd>{{ SEXES[brouillon.sex] ?? brouillon.sex }}</dd>

        <!-- `'birth' in brouillon` : la clé absente ne produit aucune ligne. -->
        <template v-if="'birth' in brouillon">
          <dt class="text-attenue">Naissance</dt>
          <dd>{{ texteDate(brouillon.birth) ?? 'inconnue' }}</dd>
        </template>

        <template v-if="'death' in brouillon">
          <dt class="text-attenue">Décès</dt>
          <dd>{{ texteDate(brouillon.death) ?? (brouillon.deceased ? 'inconnu' : 'vivant·e') }}</dd>
        </template>
      </dl>

      <p v-if="brouillon.note" class="mt-2.5 border-l-2 border-ligne pl-3 text-sm text-attenue">
        {{ brouillon.note }}
      </p>
    </article>

    <!-- --------------------------------------------------------------- -->
    <!-- Les liens : c'est eux qui disent si la personne est à sa place.  -->
    <!-- --------------------------------------------------------------- -->
    <section>
      <h3 class="text-sm font-semibold">Liens proposés</h3>

      <ul v-if="lisibles.length > 0" class="mt-2 flex flex-col gap-1.5">
        <li
          v-for="lien in lisibles"
          :key="lien.seq"
          class="rounded-champ border border-ligne bg-fond px-3 py-2 text-sm"
        >
          <!--
            **Les espaces sont dans les interpolations, pas entre les balises.**

            Le compilateur de gabarit replie les blancs : un espace posé entre
            deux éléments disparaît, et l'écran affichait « Conjoint·e deJargon
            Sarriette », deux mots collés. Une gouttière CSS ferait illusion sans
            réparer la chaîne — un lecteur d'écran lirait toujours un seul mot, et
            une copie du texte resterait fausse. La valeur d'une interpolation,
            elle, n'est jamais rognée.
          -->
          <!--
            Le **sujet** ouvre la ligne, et c'est le correctif qui compte ici.
            Sans lui, une demande portant plusieurs fiches rend une liste de
            « Enfant de X » dont rien ne dit qui est l'enfant : sur une branche
            de vingt-deux personnes, il fallait relever la référence provisoire
            du lien et la retrouver à la main dans la liste des brouillons.
          -->
          <span class="font-medium">{{ lien.sujet }}</span>
          <span class="text-attenue">{{ ` — ${lien.nature} ` }}</span>
          <span class="font-medium">{{ lien.autre }}</span>
          <!--
            Le second parent porte le même poids que le premier : une filiation
            à deux parents nommés dit à elle seule de quel couple l'enfant est
            issu, et c'est ce que l'administrateur juge.
          -->
          <template v-if="lien.secondParent !== null">
            <span class="text-attenue">{{ ' et ' }}</span>
            <span class="font-medium">{{ lien.secondParent }}</span>
          </template>
          <span v-if="lien.union !== null" class="text-attenue">{{ ` — ${lien.union}` }}</span>
          <span v-if="lien.rupture !== null" class="text-attenue">{{ ` — ${lien.rupture}` }}</span>
          <p v-if="lien.note !== null" class="mt-1 text-xs text-attenue">{{ lien.note }}</p>
          <!--
            Ce que la validation fera de cette filiation, quand ça ne se lit pas
            dans le lien : « Véronique, enfant de Jean-Marie » qui atterrit chez
            « Jean-Marie × Hélène ». À lire avant de cliquer, pas à découvrir dans
            l'arbre après.
          -->
          <div
            v-if="lien.effet !== null"
            class="mt-1.5 border-l-2 pl-2.5 text-xs"
            :class="lien.effet.refus !== null ? 'border-alerte-bord' : 'border-ligne'"
            data-test="effet-filiation"
          >
            <p>{{ lien.effet.phrase }}</p>
            <p v-for="quittee in lien.effet.quittees" :key="quittee" class="text-attenue">
              {{ quittee }}
            </p>
            <p v-if="lien.effet.refus !== null" class="mt-1 text-alerte" role="status">
              <strong class="font-semibold">La validation sera refusée.</strong>
              {{ ` ${lien.effet.refus}` }}
            </p>
          </div>
        </li>
      </ul>

      <!-- Une demande soumise sans lien est refusée en 422 par le serveur ; si
           l'écran en montre une, c'est un brouillon, et le dire évite de
           chercher ce qui manque. -->
      <p v-else class="mt-2 text-sm text-alerte">
        Aucun lien proposé : la personne n’aurait pas de place dans l’arbre.
      </p>
    </section>
  </div>
</template>
