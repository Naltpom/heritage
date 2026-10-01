<script setup lang="ts">
/**
 * Les réglages d'un tirage — à la création comme à la modification.
 *
 * Le composant ne soumet rien : il tient le brouillon (`v-model`) et affiche
 * les erreurs de champ que l'écran lui passe, telles que le serveur les a
 * nommées (`422`, `erreur.champs`).
 */
import { computed, useId } from 'vue'
import type { ReglesDuTirage } from '@/lib/api'
import ChampTexte from '@/components/base/ChampTexte.vue'
import { OPTIONS_DE_MOIS, avertissementDeMemoire, libelleDeMemoire } from './tirages'

const { erreurs = {}, memoireConseillee = null } = defineProps<{
  erreurs?: Record<string, string>
  /** `null` à la création : le nombre de participants n'est pas encore connu. */
  memoireConseillee?: number | null
}>()

const regles = defineModel<ReglesDuTirage>({ required: true })

const id = useId()

const nom = computed({
  get: () => regles.value.nom,
  set: (nom: string) => (regles.value = { ...regles.value, nom }),
})

const lieu = computed({
  get: () => regles.value.lieu ?? '',
  set: (lieu: string) => (regles.value = { ...regles.value, lieu: lieu.trim() === '' ? null : lieu }),
})

function modifier<K extends keyof ReglesDuTirage>(cle: K, valeur: ReglesDuTirage[K]): void {
  regles.value = { ...regles.value, [cle]: valeur }
}

const avertissement = computed(() =>
  memoireConseillee === null ? null : avertissementDeMemoire(regles.value.memoire, memoireConseillee),
)

const classeSelect = 'h-10 rounded-champ border border-ligne bg-fond px-2 text-sm text-texte'
</script>

<template>
  <div class="flex flex-col gap-4">
    <ChampTexte v-model="nom" etiquette="Nom du tirage" placeholder="Noël côté papa" requis :erreur="erreurs['nom']" />

    <fieldset class="flex flex-col gap-1.5">
      <legend class="mb-1.5 text-sm font-medium text-texte">Jour de l’événement, chaque année</legend>
      <div class="flex gap-2">
        <label class="sr-only" :for="`${id}-jour`">Jour</label>
        <select
          :id="`${id}-jour`"
          :class="classeSelect"
          :value="regles.jour"
          @change="modifier('jour', Number(($event.target as HTMLSelectElement).value))"
        >
          <option v-for="j in 31" :key="j" :value="j">{{ j }}</option>
        </select>
        <label class="sr-only" :for="`${id}-mois`">Mois</label>
        <select
          :id="`${id}-mois`"
          :class="classeSelect"
          :value="regles.mois"
          @change="modifier('mois', Number(($event.target as HTMLSelectElement).value))"
        >
          <option v-for="m in OPTIONS_DE_MOIS" :key="m.valeur" :value="m.valeur">{{ m.libelle }}</option>
        </select>
      </div>
      <p v-if="erreurs['jour'] || erreurs['mois']" class="text-xs text-alerte">{{ erreurs['jour'] ?? erreurs['mois'] }}</p>
    </fieldset>

    <ChampTexte
      v-model="lieu"
      etiquette="Lieu habituel"
      aide="Facultatif. Là où il faut apporter les cadeaux."
      placeholder="Chez les grands-parents"
      :erreur="erreurs['lieu']"
    />

    <div class="flex flex-col gap-1.5">
      <label class="text-sm font-medium text-texte" :for="`${id}-memoire`">Ne pas retomber sur la même personne</label>
      <select
        :id="`${id}-memoire`"
        :class="classeSelect"
        :value="regles.memoire"
        @change="modifier('memoire', Number(($event.target as HTMLSelectElement).value))"
      >
        <option v-for="n in 11" :key="n - 1" :value="n - 1">{{ libelleDeMemoire(n - 1) }}</option>
      </select>
      <p v-if="avertissement !== null" class="text-xs text-attenue">{{ avertissement }}</p>
      <p v-if="erreurs['memoire']" class="text-xs text-alerte">{{ erreurs['memoire'] }}</p>
    </div>

    <label class="flex items-start gap-2 text-sm text-texte">
      <input
        type="checkbox"
        class="mt-0.5"
        :checked="regles.exclureConjoints"
        @change="modifier('exclureConjoints', ($event.target as HTMLInputElement).checked)"
      />
      <span>
        Les couples ne se tirent pas l’un l’autre
      </span>
    </label>

    <label class="flex items-start gap-2 text-sm text-texte">
      <input
        type="checkbox"
        class="mt-0.5"
        :checked="regles.ouvert"
        @change="modifier('ouvert', ($event.target as HTMLInputElement).checked)"
      />
      <span>
        Ouvert à la famille
        <span class="block text-xs text-attenue">
          Toute la famille peut le voir et s’y inscrire. Sinon, vous ajoutez les participants.
        </span>
      </span>
    </label>
  </div>
</template>
