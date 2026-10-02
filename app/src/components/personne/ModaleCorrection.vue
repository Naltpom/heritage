<script setup lang="ts">
/**
 * « Corriger » : **tout ce qui va partir**, relu avant de partir.
 *
 * La modale récapitule chaque changement de la session, dans la forme où un
 * administrateur le lira — « Naissance : 1894 → 18 mai 1893 », « Lien retiré :
 * enfant de X », « Lien ajouté : conjoint de Y » — avec un mot facultatif pour lui.
 * « Confirmer » dépose les demandes ; rien n'est écrit dans l'arbre, et la modale
 * le dit.
 *
 * Un `<dialog>` natif ouvert en modal, comme `DialogueConfirmation` : il piège le
 * focus, se ferme à Échap et rend le reste de la page inerte. Le parent tient
 * l'ouverture (`changements` non nul) ; la modale ne dépose rien elle-même.
 *
 * Le focus part sur « Revenir », pas sur « Confirmer » : un Entrée réflexe ne
 * doit pas envoyer cinq demandes.
 */
import { nextTick, ref, useId, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import type { ErreurSaisie } from '@/components/proposition/creation'
import type { Changement } from './edition'

const {
  changements,
  envoi = false,
  erreur = null,
  erreursSaisie = [],
} = defineProps<{
  changements: Changement[] | null
  envoi?: boolean
  erreur?: unknown
  erreursSaisie?: ErreurSaisie[]
}>()

const message = defineModel<string>('message', { required: true })
const emit = defineEmits<{ confirmer: []; annuler: [] }>()

const dialogue = ref<HTMLDialogElement | null>(null)
const revenir = ref<InstanceType<typeof BoutonBase> | null>(null)
const id = useId()

watch(
  () => changements,
  async (actuels) => {
    const element = dialogue.value
    if (element === null) return
    if (actuels !== null && !element.open) {
      element.showModal()
      await nextTick()
      ;(revenir.value?.$el as HTMLElement | undefined)?.focus()
    } else if (actuels === null && element.open) {
      element.close()
    }
  },
  { flush: 'post' },
)

function surFermeture(): void {
  if (changements !== null) emit('annuler')
}

const PUCES: Record<Changement['nature'], string> = { champ: '✎', retrait: '−', ajout: '+' }
</script>

<template>
  <dialog
    ref="dialogue"
    class="m-auto w-[min(34rem,calc(100vw-1.5rem))] rounded-panneau border border-edition-bord bg-panneau p-0 text-texte shadow-panneau backdrop:bg-black/50"
    :aria-labelledby="`${id}-titre`"
    data-modale-correction
    @close="surFermeture"
  >
    <div v-if="changements !== null" class="flex max-h-[85dvh] flex-col gap-4 overflow-y-auto border-l-4 border-l-edition p-5 max-md:p-4">
      <h2 :id="`${id}-titre`" class="m-0 text-base font-semibold">
        {{ changements.length === 0 ? 'Rien à corriger' : 'Relire avant d’envoyer' }}
      </h2>

      <p v-if="changements.length === 0" class="m-0 text-sm text-attenue">
        Vous n’avez rien modifié.
      </p>

      <template v-else>
        <ul class="m-0 flex list-none flex-col gap-1.5 p-0 text-sm" data-recapitulatif>
          <li
            v-for="(changement, index) in changements"
            :key="index"
            class="flex gap-2 rounded-champ bg-edition-doux px-2.5 py-1.5"
            :data-changement="changement.nature"
          >
            <span aria-hidden="true" class="w-3 flex-none font-bold text-edition">{{ PUCES[changement.nature] }}</span>
            <span class="min-w-0 break-words">{{ changement.texte }}</span>
          </li>
        </ul>

        <p class="m-0 text-xs text-attenue">
          Un administrateur validera ou refusera ces corrections ; d’ici là, vous seul les voyez.
        </p>

        <label class="flex flex-col gap-1">
          <span class="text-sm font-medium">Un mot pour l’administrateur <span class="font-normal text-attenue">(facultatif)</span></span>
          <textarea
            v-model="message"
            rows="2"
            placeholder="Ex. : j’ai l’acte de naissance"
            class="w-full rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
          />
        </label>
      </template>

      <ul v-if="erreursSaisie.length > 0" class="m-0 list-disc pl-5 text-sm text-alerte" role="alert">
        <li v-for="(e, index) in erreursSaisie" :key="index">{{ e.message }}</li>
      </ul>
      <MessageErreur v-if="erreur !== null" :erreur="erreur" />

      <div class="flex flex-wrap justify-end gap-2">
        <BoutonBase ref="revenir" variante="secondaire" :desactive="envoi" @click="emit('annuler')">
          Revenir
        </BoutonBase>
        <BoutonBase
          v-if="changements.length > 0"
          :chargement="envoi"
          :desactive="erreursSaisie.length > 0"
          data-geste="confirmer"
          @click="emit('confirmer')"
        >
          Confirmer
        </BoutonBase>
      </div>
    </div>
  </dialog>
</template>
