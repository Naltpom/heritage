<script setup lang="ts">
/**
 * La fenêtre « Installer Heritage sur votre téléphone » — l'**affichage** seul.
 *
 * Le pilote (quand l'ouvrir, que retenir de la réponse) est dans
 * `invitationInstallation.ts` (`piloterInvitationInstallation`) et le
 * branchement dans `InvitationInstallation` ; ici on ne fait que montrer et
 * rapporter `installer` ou `plus-tard`.
 *
 * **Même cadre, même mécanique que `FenetreInvitationNotifications`**, exprès :
 * les deux se suivent à l'arrivée (jamais ensemble, voir
 * `fenetresAutomatiques.ts`) et doivent se lire comme la même famille d'offre.
 *
 * - Un `<dialog>` natif ouvert en modal : il piège le focus, rend le reste de
 *   la page inerte et se ferme à Échap. Échap vaut « Plus tard » (ou
 *   « Compris ») : qui ferme sans répondre n'a pas dit oui. `role` et
 *   `aria-modal` sont redits pour les lecteurs d'écran anciens.
 * - Le focus part sur le bouton secondaire (« Plus tard », ou « Compris »
 *   quand il est seul) : une installation lancée d'un Entrée réflexe ne
 *   serait pas un choix.
 * - Feuille qui monte du bas sur téléphone — pleine largeur, coins arrondis en
 *   haut, zone du geste d'accueil réservée (`safe-area-inset-bottom`) —, boîte
 *   centrée sur un écran large (un iPad).
 *
 * **Deux formes**, un seul cadre :
 *
 * - `android` : le navigateur sait installer. La phrase et deux boutons ;
 *   « Installer » ouvre la boîte du navigateur, et patiente pendant qu'elle est
 *   ouverte (Échap n'y fait rien : sa réponse est attendue) ;
 * - `ios` : Safari n'a pas de bouton à rejouer. On dit les deux gestes, et un
 *   seul bouton, « Compris » — il n'y a rien d'autre à faire ici. On dit aussi
 *   que c'est la condition des notifications : sur iPhone, Safari ne les offre
 *   qu'à l'application installée.
 *
 * Tout tient à 320 px de large : le texte passe à la ligne (`min-w-0`), les
 * boutons aussi (`flex-wrap`).
 */
import { nextTick, ref, useId, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import type { FormeInvitationInstallation } from '@/composables/invitationInstallation'

const { forme, enCours = false } = defineProps<{
  /** `null` = fermée. */
  forme: FormeInvitationInstallation | null
  /** La boîte du navigateur est ouverte. */
  enCours?: boolean
}>()

const emit = defineEmits<{ installer: []; 'plus-tard': [] }>()

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
 *  « Plus tard ». Pendant la boîte du navigateur, Échap ne fait rien. */
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
    data-invitation-installation
    :data-forme="forme ?? undefined"
    class="mx-0 mt-auto mb-0 w-full max-w-full rounded-t-panneau border border-b-0 border-ligne bg-panneau p-0 text-texte shadow-panneau backdrop:bg-black/50 md:m-auto md:w-[min(26rem,calc(100vw-1.5rem))] md:rounded-panneau md:border-b"
    @cancel="surAnnulation"
  >
    <div
      v-if="forme !== null"
      class="flex flex-col gap-4 px-5 pt-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] md:pb-5"
    >
      <div class="flex items-start gap-3">
        <!-- L'icône même qui sera posée sur l'écran d'accueil. -->
        <img src="/icons/icone-192.png" alt="" width="40" height="40" class="size-10 flex-none rounded-lg" />
        <div class="flex min-w-0 flex-col gap-1.5">
          <h2 :id="`${id}-titre`" class="m-0 text-base font-semibold">Installer Heritage sur votre téléphone</h2>
          <div :id="`${id}-message`" class="flex flex-col gap-2 text-sm text-attenue">
            <p class="m-0">
              Il s’ouvrira depuis votre écran d’accueil, en plein écran, comme une application — et pourra vous
              prévenir par des notifications.
            </p>
            <template v-if="forme === 'ios'">
              <p class="m-0">Sur iPhone, c’est même nécessaire pour recevoir les notifications.</p>
              <ol class="m-0 flex list-decimal flex-col gap-1 pl-5">
                <li>
                  Dans Safari, touchez <strong class="text-texte">Partager</strong>
                  <svg class="inline size-4 align-text-bottom" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 3v12M8 7l4-4 4 4M5 12v8h14v-8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  (ou <strong class="text-texte">⋯</strong> puis <strong class="text-texte">Partager</strong>) ;
                </li>
                <li>puis <strong class="text-texte">Sur l’écran d’accueil</strong>, et ouvrez Heritage depuis son icône.</li>
              </ol>
            </template>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap justify-end gap-2">
        <template v-if="forme === 'android'">
          <BoutonBase ref="boutonSecondaire" variante="secondaire" :desactive="enCours" @click="emit('plus-tard')">
            Plus tard
          </BoutonBase>
          <BoutonBase variante="principal" :chargement="enCours" @click="emit('installer')">Installer</BoutonBase>
        </template>
        <BoutonBase v-else ref="boutonSecondaire" variante="principal" @click="emit('plus-tard')">Compris</BoutonBase>
      </div>
    </div>
  </dialog>
</template>
