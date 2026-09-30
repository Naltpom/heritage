<script setup lang="ts">
/**
 * Un message d'une démarche — la demande, une relance, la réponse du service.
 *
 * **Le texte se colle en entier**, sans résumé : celui qui reprendra la
 * démarche n'aura pas la boîte d'où elle est partie. C'est le seul champ
 * obligatoire avec le sens, le canal et la date.
 */
import { useId } from 'vue'
import type { MessageASaisir } from '@/lib/api'
import ChampTexte from '@/components/base/ChampTexte.vue'
import { CANAUX, SENS } from './demarches'

const { erreurs = {} } = defineProps<{ erreurs?: Record<string, string> }>()

const message = defineModel<MessageASaisir>({ required: true })

const id = useId()
const classeChamp = 'rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte'

function modifier<K extends keyof MessageASaisir>(cle: K, valeur: MessageASaisir[K]): void {
  message.value = { ...message.value, [cle]: valeur }
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="grid gap-3 sm:grid-cols-3">
      <div class="flex flex-col gap-1">
        <label :for="`${id}-sens`" class="text-sm font-medium">Sens</label>
        <select
          :id="`${id}-sens`"
          :class="classeChamp"
          :value="message.sens"
          @change="modifier('sens', ($event.target as HTMLSelectElement).value as MessageASaisir['sens'])"
        >
          <option v-for="s in SENS" :key="s.valeur" :value="s.valeur">{{ s.libelle }}</option>
        </select>
      </div>
      <div class="flex flex-col gap-1">
        <label :for="`${id}-canal`" class="text-sm font-medium">Par</label>
        <select
          :id="`${id}-canal`"
          :class="classeChamp"
          :value="message.canal"
          @change="modifier('canal', ($event.target as HTMLSelectElement).value as MessageASaisir['canal'])"
        >
          <option v-for="c in CANAUX" :key="c.valeur" :value="c.valeur">{{ c.libelle }}</option>
        </select>
        <p v-if="erreurs['canal']" class="text-xs text-alerte">{{ erreurs['canal'] }}</p>
      </div>
      <ChampTexte
        :model-value="message.date"
        type="date"
        etiquette="Date"
        requis
        :erreur="erreurs['date'] ?? ''"
        @update:model-value="modifier('date', $event)"
      />
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
      <ChampTexte
        :model-value="message.de ?? ''"
        etiquette="De"
        placeholder="L’adresse ou le service qui envoie"
        :erreur="erreurs['de'] ?? ''"
        @update:model-value="modifier('de', $event)"
      />
      <ChampTexte
        :model-value="message.a ?? ''"
        etiquette="À"
        placeholder="mairie@…, Service de l’état civil…"
        :erreur="erreurs['a'] ?? ''"
        @update:model-value="modifier('a', $event)"
      />
    </div>

    <div class="grid gap-3 sm:grid-cols-[2fr_1fr]">
      <ChampTexte
        :model-value="message.objet ?? ''"
        etiquette="Objet"
        :erreur="erreurs['objet'] ?? ''"
        @update:model-value="modifier('objet', $event)"
      />
      <ChampTexte
        :model-value="message.reference ?? ''"
        etiquette="Référence"
        aide="N° de dossier service-public, de courrier…"
        :erreur="erreurs['reference'] ?? ''"
        @update:model-value="modifier('reference', $event)"
      />
    </div>

    <div class="flex flex-col gap-1">
      <label :for="`${id}-texte`" class="text-sm font-medium">
        Texte du message
        <span class="text-alerte" aria-hidden="true">*</span>
      </label>
      <textarea
        :id="`${id}-texte`"
        :value="message.texte"
        rows="10"
        required
        :aria-invalid="erreurs['texte'] ? 'true' : undefined"
        :class="[classeChamp, erreurs['texte'] ? 'border-alerte' : '']"
        placeholder="Collez le message en entier, tel qu’il a été envoyé ou reçu."
        @input="modifier('texte', ($event.target as HTMLTextAreaElement).value)"
      />
      <p class="text-xs text-attenue">
        En entier : celui qui reprendra la démarche n’aura pas accès à la boîte d’où elle est partie.
      </p>
      <p v-if="erreurs['texte']" class="text-xs text-alerte">{{ erreurs['texte'] }}</p>
    </div>
  </div>
</template>
