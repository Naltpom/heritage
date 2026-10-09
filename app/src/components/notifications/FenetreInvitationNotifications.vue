<script setup lang="ts">
/**
 * La fenêtre « Activer les notifications » — l'**affichage** seul.
 *
 * Le pilote (quand l'ouvrir, que retenir de la réponse) est dans `usePush`
 * (`piloterInvitationPush`) et le branchement dans `InvitationNotifications` ;
 * ici on ne fait que montrer et rapporter `activer` ou `plus-tard`.
 *
 * **Un `<dialog>` natif ouvert en modal**, comme `DialogueConfirmation` : il
 * piège le focus, rend le reste de la page inerte et se ferme à Échap sans une
 * ligne de JavaScript de plus. Échap vaut « Plus tard » : le lecteur qui ferme
 * sans répondre n'a pas dit oui, et ne doit pas être relancé demain. `role` et
 * `aria-modal` sont redits pour les lecteurs d'écran anciens, qui ne les
 * déduisent pas de la balise.
 *
 * La phrase et deux boutons. Le focus part sur « Plus tard », pas sur le
 * bouton qui agit — même parti que `DialogueConfirmation` : une demande de
 * permission franchie d'un Entrée réflexe ne serait pas un choix.
 *
 * L'iPhone hors écran d'accueil n'est plus invité ici : la fenêtre
 * d'installation (`FenetreInvitationInstallation`), qui passe juste avant,
 * lui dit les gestes de Safari et que c'est nécessaire aux notifications.
 *
 * **Feuille qui monte du bas sur téléphone, boîte centrée sur bureau.** La
 * feuille est collée au bas de l'écran, pleine largeur, coins arrondis en haut
 * seulement, et réserve la zone du geste d'accueil (`safe-area-inset-bottom`).
 */
import { nextTick, ref, useId, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import type { FormeInvitationPush } from '@/composables/usePush'

const { forme, enCours = false } = defineProps<{
  /** `null` = fermée. */
  forme: FormeInvitationPush | null
  enCours?: boolean
}>()

const emit = defineEmits<{ activer: []; 'plus-tard': [] }>()

const dialogue = ref<HTMLDialogElement | null>(null)
const boutonSecondaire = ref<InstanceType<typeof BoutonBase> | null>(null)
const id = useId()

watch(
  () => forme,
  async (actuelle) => {
    const element = dialogue.value
    if (element === null) return
    if (actuelle !== null && !element.open) {
      element.showModal()
      await nextTick()
      ;(boutonSecondaire.value?.$el as HTMLElement | undefined)?.focus()
    } else if (actuelle === null && element.open) {
      element.close()
    }
  },
  { flush: 'post' },
)

/** Échap : le navigateur annule le `<dialog>`. On l'en empêche — c'est le
 *  parent qui ferme, en passant `forme` à `null` — et on le rapporte comme un
 *  « Plus tard ». Pendant l'activation, Échap ne fait rien : la réponse du
 *  navigateur est attendue. */
function surAnnulation(evenement: Event): void {
  evenement.preventDefault()
  if (!enCours) emit('plus-tard')
}
</script>

<template>
  <dialog
    ref="dialogue"
    role="dialog"
    aria-modal="true"
    :aria-labelledby="`${id}-titre`"
    :aria-describedby="`${id}-message`"
    data-invitation-notifications
    :data-forme="forme ?? undefined"
    class="mx-0 mt-auto mb-0 w-full max-w-full rounded-t-panneau border border-b-0 border-ligne bg-panneau p-0 text-texte shadow-panneau backdrop:bg-black/50 md:m-auto md:w-[min(26rem,calc(100vw-1.5rem))] md:rounded-panneau md:border-b"
    @cancel="surAnnulation"
  >
    <div
      v-if="forme !== null"
      class="flex flex-col gap-4 px-5 pt-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] md:pb-5"
    >
      <div class="flex items-start gap-3">
        <span
          class="flex size-10 flex-none items-center justify-center rounded-pastille bg-accent-doux text-accent"
          aria-hidden="true"
        >
          <!-- La cloche de l'en-tête, en plus grand. -->
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 9a6 6 0 0 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9" />
            <path d="M10 20a2 2 0 0 0 4 0" />
          </svg>
        </span>
        <div class="flex min-w-0 flex-col gap-1.5">
          <h2 :id="`${id}-titre`" class="m-0 text-base font-semibold">Activer les notifications</h2>
          <p :id="`${id}-message`" class="m-0 text-sm text-attenue">
            Soyez prévenu quand une demande est traitée ou qu’un tirage est fait.
          </p>
        </div>
      </div>

      <div class="flex flex-wrap justify-end gap-2">
        <BoutonBase ref="boutonSecondaire" variante="secondaire" :desactive="enCours" @click="emit('plus-tard')">
          Plus tard
        </BoutonBase>
        <BoutonBase variante="principal" :chargement="enCours" @click="emit('activer')">Activer</BoutonBase>
      </div>
    </div>
  </dialog>
</template>
