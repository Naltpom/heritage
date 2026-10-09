<script setup lang="ts">
/**
 * Les champs d'identité, **partagés par les deux formulaires**.
 *
 * C'est la traduction en composant de l'instruction du commanditaire : proposer
 * une personne se fait « sur ce même principe » que proposer une modification.
 * Si les deux écrans dessinaient leur propre bloc d'identité, ils divergeraient —
 * un surnom ici, pas là ; un libellé de sexe traduit d'un côté, brut de l'autre.
 * Un seul bloc, deux écrans.
 *
 * Il ne porte **que** des champs de la surface éditable du § 6.2. Ni `branch`,
 * ni `generation`, ni `displayName` : ce sont des colonnes dérivées, « une
 * demande qui les vise est un malentendu, pas une contribution ».
 *
 * `deceased` mérite son aide : c'est le `DEAT` du GEDCOM, **stocké**, à ne pas
 * confondre avec `vivant`, qui est **calculé** par le serveur et n'est pas
 * éditable. Un décès attesté sans date reste un décès attesté — 25 fiches de
 * cet arbre sont exactement dans ce cas.
 */
import { computed } from 'vue'
import type { Sexe } from '@/lib/api'
import ChampTexte from '@/components/base/ChampTexte.vue'
import { LIBELLES_SEXE, etiquetteDeChamp } from './champs'

const { erreurs = {}, uneColonne = false } = defineProps<{
  /** Messages par champ : `analyse.champs[nom]` du § 15.1, ou la validation
   *  locale. La clé est le nom de propriété Doctrine, comme côté serveur. */
  erreurs?: Record<string, string>
  /**
   * Une colonne, quelle que soit la place : le volet de `/arbre` fait 440 px, et
   * deux champs de 13 rem n'y tiennent côte à côte que si la police de base est
   * réduite — la règle `flex-wrap` les y mettrait alors à 180 px chacun.
   */
  uneColonne?: boolean
}>()

/** Les rangées de deux champs : côte à côte dès que la place existe, ou empilées. */
const rangee = computed(() => (uneColonne ? 'flex flex-col gap-3' : 'flex flex-wrap gap-3'))
const cellule = computed(() => (uneColonne ? '' : 'min-w-[13rem] flex-1'))

const givenNames = defineModel<string>('givenNames', { required: true })
const surname = defineModel<string>('surname', { required: true })
const marriedName = defineModel<string>('marriedName', { required: true })
const nickname = defineModel<string>('nickname', { required: true })
const sex = defineModel<Sexe>('sex', { required: true })
const deceased = defineModel<boolean>('deceased', { required: true })

const SEXES: readonly Sexe[] = ['masculin', 'feminin', 'inconnu']
</script>

<template>
  <div class="flex flex-col gap-3">
    <!-- Deux colonnes dès que la place existe, une seule au téléphone : la
         règle est portée par `flex-wrap` et une largeur minimale, pas par un
         point de rupture choisi au jugé. `uneColonne` l'impose (volet de l'arbre). -->
    <div :class="rangee">
      <div :class="cellule">
        <ChampTexte
          v-model="givenNames"
          :etiquette="etiquetteDeChamp('givenNames')"
          :erreur="erreurs['givenNames'] ?? erreurs['identite'] ?? ''"
          autocomplete="off"
        />
      </div>
      <div :class="cellule">
        <ChampTexte
          v-model="surname"
          :etiquette="etiquetteDeChamp('surname')"
          :erreur="erreurs['surname'] ?? ''"
          autocomplete="off"
        />
      </div>
    </div>

    <div :class="rangee">
      <div :class="cellule">
        <ChampTexte
          v-model="marriedName"
          :etiquette="etiquetteDeChamp('marriedName')"
          :erreur="erreurs['marriedName'] ?? ''"
          autocomplete="off"
        />
      </div>
      <div :class="cellule">
        <ChampTexte
          v-model="nickname"
          :etiquette="etiquetteDeChamp('nickname')"
          :erreur="erreurs['nickname'] ?? ''"
          autocomplete="off"
        />
      </div>
    </div>

    <div class="flex flex-wrap items-end gap-4">
      <label class="flex min-w-[10rem] flex-1 flex-col gap-1">
        <span class="text-sm font-medium">{{ etiquetteDeChamp('sex') }}</span>
        <!-- La **valeur** reste celle du modèle (`feminin`), jamais le libellé :
             c'est elle qui part dans `newValue` (§ 1.2). -->
        <select
          v-model="sex"
          class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
        >
          <option v-for="valeur in SEXES" :key="valeur" :value="valeur">
            {{ LIBELLES_SEXE[valeur] }}
          </option>
        </select>
      </label>

      <label class="flex flex-1 items-start gap-2 py-1.5">
        <input v-model="deceased" type="checkbox" class="mt-0.5 size-4 accent-[var(--color-accent)]" />
        <span class="text-sm">{{ etiquetteDeChamp('deceased') }}</span>
      </label>
    </div>

    <p v-if="erreurs['deceased']" class="text-xs text-alerte">{{ erreurs['deceased'] }}</p>
  </div>
</template>
