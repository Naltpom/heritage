<script setup lang="ts">
/**
 * **« Mariés : oui / non (union libre) / on ne sait pas »** — la nature d'une union
 * (ADR 0020), en trois boutons radio.
 *
 * Trois et non une case à cocher : une case ne sait dire que deux choses, et la
 * troisième — on ne sait pas — est la plus fréquente d'un arbre ancien. Une case
 * décochée aurait déclaré « union libre » chaque couple dont l'acte manque.
 *
 * **Un mariage daté ou situé est un mariage.** Quand une date ou un lieu de mariage
 * sont saisis (`impose`), « Oui » s'affiche coché et les deux autres s'éteignent :
 * c'est la règle du serveur, dite avant l'envoi plutôt que découverte en `422`. Le
 * choix de l'auteur n'est pas écrasé pour autant — il reparaît si la date s'efface.
 */
import { computed, useId } from 'vue'
import type { NatureDUnion } from '@/components/personne/union'

const { impose = false } = defineProps<{
  /** Une date ou un lieu de mariage sont saisis : « oui » s'impose. */
  impose?: boolean
}>()

const nature = defineModel<NatureDUnion>({ required: true })

const nom = useId()

const CHOIX: readonly { cle: string; valeur: NatureDUnion; libelle: string }[] = [
  { cle: 'oui', valeur: true, libelle: 'Oui' },
  { cle: 'non', valeur: false, libelle: 'Non (union libre)' },
  { cle: 'inconnu', valeur: null, libelle: 'On ne sait pas' },
]

const affichee = computed<NatureDUnion>(() => (impose ? true : nature.value))
</script>

<template>
  <fieldset class="m-0 flex min-w-0 flex-col gap-1 border-0 p-0" data-choix-nature>
    <legend class="mb-1 p-0 text-sm font-medium">Mariés :</legend>
    <div class="flex flex-wrap gap-x-4 gap-y-1">
      <label
        v-for="choix in CHOIX"
        :key="choix.cle"
        class="flex min-h-9 items-center gap-1.5 text-sm"
        :class="impose && choix.valeur !== true ? 'text-attenue' : ''"
      >
        <input
          type="radio"
          class="size-4 accent-[var(--color-accent)]"
          :name="nom"
          :value="choix.cle"
          :checked="affichee === choix.valeur"
          :disabled="impose && choix.valeur !== true"
          :data-nature="choix.cle"
          @change="nature = choix.valeur"
        />
        {{ choix.libelle }}
      </label>
    </div>
    <p v-if="impose" class="m-0 text-xs text-attenue">Une date ou un lieu de mariage attestent le mariage.</p>
  </fieldset>
</template>
