<script setup lang="ts">
/**
 * Champ de saisie avec étiquette, aide et message d'erreur.
 *
 * **L'erreur affichée vient de `champs` du § 15.1**, pas d'une validation
 * réécrite côté front : `{"nouveauMotDePasse": "12 caractères minimum."}` arrive
 * du serveur et s'affiche tel quel. Le front peut valider *en plus*, pour le
 * confort, mais il ne remplace jamais le message du serveur — celui-ci est la
 * règle réellement appliquée, et les deux dériveraient.
 *
 * L'erreur est reliée au champ par `aria-describedby` et `aria-invalid` : sans
 * cela, un lecteur d'écran annonce l'étiquette et laisse le message de côté.
 */
import { computed, useId } from 'vue'

const {
  etiquette,
  type = 'text',
  aide = '',
  erreur = '',
  requis = false,
  desactive = false,
  autocomplete = '',
  placeholder = '',
  chasseFixe = false,
} = defineProps<{
  etiquette: string
  type?: 'text' | 'email' | 'password' | 'date' | 'search' | 'number'
  aide?: string
  /** Message d'erreur pour ce champ — typiquement `analyse.champs[nom]`. */
  erreur?: string
  requis?: boolean
  desactive?: boolean
  autocomplete?: string
  placeholder?: string
  /** Chasse fixe : années, fourchettes, jetons, empreintes. */
  chasseFixe?: boolean
}>()

const valeur = defineModel<string>({ default: '' })

const identifiant = useId()
const idAide = computed(() => `${identifiant}-aide`)
const idErreur = computed(() => `${identifiant}-erreur`)
const enErreur = computed(() => erreur.length > 0)
const decritPar = computed(() => {
  const parties: string[] = []
  if (aide.length > 0) parties.push(idAide.value)
  if (enErreur.value) parties.push(idErreur.value)
  return parties.length > 0 ? parties.join(' ') : undefined
})
</script>

<template>
  <div class="flex flex-col gap-1">
    <label :for="identifiant" class="text-sm font-medium">
      {{ etiquette }}
      <span v-if="requis" class="text-alerte" aria-hidden="true">*</span>
      <span v-if="requis" class="lecteur-ecran">(obligatoire)</span>
    </label>

    <input
      :id="identifiant"
      v-model="valeur"
      :type="type"
      :required="requis"
      :disabled="desactive"
      :autocomplete="autocomplete.length > 0 ? autocomplete : undefined"
      :placeholder="placeholder.length > 0 ? placeholder : undefined"
      :aria-invalid="enErreur ? 'true' : undefined"
      :aria-describedby="decritPar"
      class="rounded-champ border bg-fond px-2.5 py-1.5 text-sm text-texte transition-colors disabled:opacity-60"
      :class="[enErreur ? 'border-alerte' : 'border-ligne', chasseFixe ? 'font-mono tnum' : '']"
    />

    <p v-if="aide.length > 0" :id="idAide" class="text-xs text-attenue">{{ aide }}</p>
    <p v-if="enErreur" :id="idErreur" class="text-xs text-alerte">{{ erreur }}</p>
  </div>
</template>
