<script setup lang="ts">
/**
 * La saisie d'une date généalogique — **et la raison pour laquelle ce n'est pas
 * un `<input type="date">`**.
 *
 * Un champ de date du navigateur ne sait exprimer qu'un **point**. Sur ce
 * corpus, le point est l'exception : `BET 1901 AND 1909`, `ABT 1889`,
 * `BEF 1931`. Le brancher ici obligerait le contributeur à inventer un jour et
 * un mois qu'aucune source ne donne — et cette invention deviendrait une
 * donnée, indiscernable d'une date réellement connue. « Ne jamais aplatir une
 * fourchette en date ferme : ni en base, ni à l'écran » (§ 4 du dossier).
 *
 * D'où la forme retenue : **une nature, une ou deux bornes, un lieu**. Celui qui
 * ne connaît que l'année écrit l'année, et rien ne lui réclame davantage.
 *
 * L'aperçu sous le champ n'est pas décoratif. Il montre la fourchette obtenue
 * avant l'envoi, parce que « avant 1920 » n'inclut pas 1920 et que personne ne
 * devine cette borne exclue. Il ne remplace pas `texte`, qui vient du serveur et
 * fait foi à l'affichage (§ 14.4) : ici, aucun serveur n'a encore parlé.
 *
 * Toute la logique — analyse des bornes, calcul de `min`/`max`, composition de
 * `raw` — vit dans `dates.ts`, testée sans monter de composant. Ce fichier ne
 * fait que relier des champs à cet état.
 */
import { computed, ref, useId } from 'vue'
import {
  LIBELLES_FORME,
  apercuDeValeur,
  relectureFidele,
  saisieDepuisBloc,
  valeurAEnvoyer,
  videVautAucuneDate,
  type FormeDate,
  type SaisieDate,
} from './dates'
import type { ValeurDate } from '@/lib/api'

const {
  etiquette,
  erreur = '',
  aide = '',
  sansLieu = false,
  etiquetteLieu = 'Lieu',
  videPermis = false,
} = defineProps<{
  etiquette: string
  /** Message d'erreur venu de la validation locale ou de `champs` du § 15.1. */
  erreur?: string
  aide?: string
  /** Pas de case « Lieu » (la date d'un divorce). Un lieu déjà enregistré est conservé. */
  sansLieu?: boolean
  etiquetteLieu?: string
  /** Une date « connue » laissée vide vaut « pas de date » au lieu d'une erreur
   *  ({@link videVautAucuneDate}). */
  videPermis?: boolean
}>()

const saisie = defineModel<SaisieDate>({ required: true })

const identifiant = useId()
const idAide = computed(() => `${identifiant}-aide`)
const idErreur = computed(() => `${identifiant}-erreur`)
const idApercu = computed(() => `${identifiant}-apercu`)

const FORMES: readonly FormeDate[] = ['connue', 'environ', 'entre', 'avant', 'apres', 'inconnue']

/** Deux bornes pour « entre », une pour les autres, aucune pour « inconnue ». */
const bornes = computed<0 | 1 | 2>(() => {
  if (saisie.value.forme === 'inconnue') return 0
  return saisie.value.forme === 'entre' ? 2 : 1
})

function modifier(champ: keyof SaisieDate, valeur: string): void {
  saisie.value = { ...saisie.value, [champ]: valeur }
}

/**
 * Passer à « Inconnue » **n'efface pas les bornes saisies**.
 *
 * Elles restent dans l'état sans partir dans la requête : un contributeur qui
 * change de nature pour comparer retrouve ce qu'il avait tapé. Effacer serait
 * puni celui qui explore.
 */
function changerForme(valeur: string): void {
  saisie.value = { ...saisie.value, forme: valeur as FormeDate }
}

/**
 * **Cette date se rouvre-t-elle sans risque ?**
 *
 * Certaines formes que l'import initial a produites — les `FROM` et `TO` du GEDCOM — ne se
 * laissent pas exprimer par ce champ. Les afficher quand même les viderait, et
 * un champ vide se compose en « aucune date », c'est-à-dire en **proposition de
 * suppression** d'une date sourcée que personne n'a demandé d'effacer.
 *
 * Le champ se verrouille donc de lui-même, plutôt que d'attendre qu'un appelant
 * pense à lui passer une option : un garde-fou qu'on peut oublier de brancher
 * n'en est pas un. La valeur d'origine repart alors intacte, `valeurAEnvoyer()`
 * s'en charge, et aucun op n'est produit pour ce champ.
 */
const verrouille = computed(() => !relectureFidele(saisie.value.origine))

/**
 * **Remplacer une date verrouillée — par un geste explicite.**
 *
 * Le verrou protège d'une suppression *que personne n'a demandée* ; il n'a pas
 * à empêcher celui qui connaît la bonne date de l'écrire. Le bouton pré-remplit
 * au mieux (`FROM 1900` devient « après 1900 ») puis détache l'origine : la
 * saisie est dès lors composée comme n'importe quelle autre, et l'aperçu montre
 * ce qui partira. `construireOps` compare à la fiche, pas à `origine` — un
 * remplacement identique ne produit donc toujours aucun op.
 *
 * L'original est gardé ici pour « Annuler », qui remet le verrou tel quel.
 */
const remplacee = ref<ValeurDate | null>(null)

function remplacer(): void {
  const origine = saisie.value.origine
  if (origine === null) return
  remplacee.value = origine
  saisie.value = { ...saisieDepuisBloc(origine), origine: null }
}

function annulerRemplacement(): void {
  if (remplacee.value === null) return
  saisie.value = saisieDepuisBloc(remplacee.value)
  remplacee.value = null
}

/** Ce que la saisie produira, ou le motif du refus. Recalculé à chaque frappe :
 *  c'est ce qui rend la borne exclue visible avant l'envoi et non après. */
const resultat = computed(() => valeurAEnvoyer(videPermis ? videVautAucuneDate(saisie.value) : saisie.value))
const apercu = computed(() =>
  resultat.value.valide ? apercuDeValeur(resultat.value.valeur) : resultat.value.message,
)
const enDefaut = computed(() => erreur.length > 0 || !resultat.value.valide)

const classeChamp = computed(() => [
  'rounded-champ border bg-fond px-2.5 py-1.5 text-sm text-texte transition-colors',
  enDefaut.value ? 'border-alerte' : 'border-ligne',
])
</script>

<template>
  <fieldset class="flex flex-col gap-1.5">
    <legend class="mb-1 text-sm font-medium">{{ etiquette }}</legend>

    <!-- Verrouillé : on montre la date telle qu'elle est, et on explique. La
         vider ici en ferait une suppression que personne n'a demandée. -->
    <template v-if="verrouille">
      <p class="rounded-champ border border-ligne bg-panneau px-2.5 py-1.5 text-sm">
        <span class="font-mono tnum">{{ saisie.origine?.raw ?? apercuDeValeur(saisie.origine) }}</span>
        <span v-if="saisie.origine?.place" class="text-attenue"> — {{ saisie.origine.place }}</span>
      </p>
      <p class="text-xs text-attenue">
        Cette date ne peut pas être modifiée ici : elle reste telle quelle si vous ne la
        remplacez pas.
      </p>
      <button
        type="button"
        class="self-start rounded-champ border border-ligne px-2.5 py-1 text-xs hover:bg-panneau"
        @click="remplacer"
      >
        Remplacer cette date
      </button>
    </template>

    <!-- `flex-wrap` plutôt qu'une grille : à 400 px, la nature prend une ligne
         et chaque borne la sienne, sans qu'aucune règle de média ne l'impose. -->
    <div v-else class="flex flex-wrap items-end gap-2">
      <label class="flex min-w-[9.5rem] flex-1 flex-col gap-1">
        <span class="text-xs text-attenue">Nature</span>
        <select
          :value="saisie.forme"
          :class="classeChamp"
          :aria-describedby="idApercu"
          @change="changerForme(($event.target as HTMLSelectElement).value)"
        >
          <option v-for="forme in FORMES" :key="forme" :value="forme">
            {{ LIBELLES_FORME[forme] }}
          </option>
        </select>
      </label>

      <label v-if="bornes >= 1" class="flex min-w-[7.5rem] flex-1 flex-col gap-1">
        <span class="text-xs text-attenue">{{ bornes === 2 ? 'De' : 'Date' }}</span>
        <input
          :value="saisie.debut"
          type="text"
          inputmode="numeric"
          placeholder="1901, 03/1930 ou 11/03/1930"
          :class="[...classeChamp, 'font-mono tnum']"
          :aria-describedby="idApercu"
          @input="modifier('debut', ($event.target as HTMLInputElement).value)"
        />
      </label>

      <label v-if="bornes === 2" class="flex min-w-[7.5rem] flex-1 flex-col gap-1">
        <span class="text-xs text-attenue">À</span>
        <input
          :value="saisie.fin"
          type="text"
          inputmode="numeric"
          placeholder="1909"
          :class="[...classeChamp, 'font-mono tnum']"
          :aria-describedby="idApercu"
          @input="modifier('fin', ($event.target as HTMLInputElement).value)"
        />
      </label>
    </div>

    <!-- Le lieu reste saisissable même sans date : « né à Saule, date
         inconnue » est le cas courant ici, et le perdre perdrait une donnée. -->
    <label v-if="!verrouille && !sansLieu" class="flex flex-col gap-1">
      <span class="text-xs text-attenue">{{ etiquetteLieu }}</span>
      <input
        :value="saisie.lieu"
        type="text"
        placeholder="Commune, département, pays"
        class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
        @input="modifier('lieu', ($event.target as HTMLInputElement).value)"
      />
    </label>

    <p
      v-if="!verrouille"
      :id="idApercu"
      class="text-xs"
      :class="resultat.valide ? 'text-attenue' : 'text-alerte'"
    >
      {{ apercu }}
    </p>
    <p v-if="!verrouille && aide.length > 0" :id="idAide" class="text-xs text-attenue">{{ aide }}</p>
    <button
      v-if="remplacee !== null"
      type="button"
      class="self-start text-xs text-attenue underline"
      @click="annulerRemplacement"
    >
      Annuler — garder « {{ remplacee.raw ?? apercuDeValeur(remplacee) }} »
    </button>
    <p v-if="erreur.length > 0" :id="idErreur" class="text-xs text-alerte">{{ erreur }}</p>
  </fieldset>
</template>
