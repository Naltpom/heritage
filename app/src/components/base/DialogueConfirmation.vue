<script setup lang="ts">
/**
 * « Êtes-vous sûr ? » — une étape de validation avant un geste qui engage.
 *
 * Un `<dialog>` natif ouvert en modal : il piège le focus, se ferme à Échap et
 * rend le reste de la page inerte, sans une ligne de JavaScript de plus. Le
 * parent tient la **demande** (`demande` non nulle = ouvert) : le dialogue ne
 * décide de rien, il rapporte `confirmer` ou `annuler`.
 *
 * Le focus part sur « Annuler », pas sur le bouton qui agit : une validation
 * qu'un Entrée réflexe franchit ne valide rien.
 */
import { nextTick, ref, useId, watch } from 'vue'
import BoutonBase from './BoutonBase.vue'

export interface DemandeDeConfirmation {
  titre: string
  message: string
  /** Le libellé du bouton qui agit : un verbe, pas « OK ». */
  confirmer: string
  /** `true` pour un geste qui retire ou efface. */
  danger?: boolean
}

const { demande, chargement = false } = defineProps<{
  demande: DemandeDeConfirmation | null
  chargement?: boolean
}>()

const emit = defineEmits<{ confirmer: []; annuler: [] }>()

const dialogue = ref<HTMLDialogElement | null>(null)
const boutonAnnuler = ref<InstanceType<typeof BoutonBase> | null>(null)
const id = useId()

watch(
  () => demande,
  async (actuelle) => {
    const element = dialogue.value
    if (element === null) return

    if (actuelle !== null && !element.open) {
      element.showModal()
      await nextTick()
      ;(boutonAnnuler.value?.$el as HTMLElement | undefined)?.focus()
    } else if (actuelle === null && element.open) {
      element.close()
    }
  },
  { flush: 'post' },
)

/** Échap ferme le `<dialog>` de lui-même : on le rapporte comme un refus. */
function surFermeture(): void {
  if (demande !== null) emit('annuler')
}
</script>

<template>
  <dialog
    ref="dialogue"
    class="m-auto w-[min(26rem,calc(100vw-1.5rem))] rounded-panneau border border-ligne bg-panneau p-0 text-texte shadow-panneau backdrop:bg-black/50"
    :aria-labelledby="`${id}-titre`"
    :aria-describedby="`${id}-message`"
    @close="surFermeture"
  >
    <div v-if="demande !== null" class="flex flex-col gap-4 p-5">
      <h2 :id="`${id}-titre`" class="text-base font-semibold">{{ demande.titre }}</h2>
      <p :id="`${id}-message`" class="text-sm text-attenue">{{ demande.message }}</p>
      <div class="flex flex-wrap justify-end gap-2">
        <BoutonBase ref="boutonAnnuler" variante="secondaire" :desactive="chargement" @click="emit('annuler')">
          Annuler
        </BoutonBase>
        <BoutonBase :variante="demande.danger ? 'danger' : 'principal'" :chargement="chargement" @click="emit('confirmer')">
          {{ demande.confirmer }}
        </BoutonBase>
      </div>
    </div>
  </dialog>
</template>
