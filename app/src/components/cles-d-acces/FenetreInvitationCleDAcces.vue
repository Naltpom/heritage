<script setup lang="ts">
/**
 * La fenêtre « Se connecter plus vite la prochaine fois ? » — l'**affichage**
 * seul.
 *
 * Le pilote (quand l'ouvrir, que retenir de la réponse) est dans
 * `useClesDAcces` (`piloterInvitationCleDAcces`) et le branchement dans
 * `InvitationCleDAcces` ; ici on ne fait que montrer et rapporter `activer` ou
 * `plus-tard`.
 *
 * **Même cadre, même mécanique que `FenetreInvitationNotifications`**, exprès :
 * les deux fenêtres se suivent (jamais ensemble, voir `fenetresAutomatiques.ts`)
 * et doivent se lire comme la même famille d'offre.
 *
 * - Un `<dialog>` natif ouvert en modal : focus piégé, reste de la page inerte,
 *   Échap qui vaut « Plus tard ». `role` et `aria-modal` redits pour les
 *   lecteurs d'écran anciens.
 * - Le focus part sur « Plus tard », pas sur « Activer » : une clé créée d'un
 *   Entrée réflexe ne serait pas un choix.
 * - Feuille qui monte du bas sur téléphone, boîte centrée sur bureau.
 *
 * Un refus du navigateur (« annulé ») **ne ferme pas** la fenêtre : il s'écrit
 * dedans, et la personne peut réessayer ou dire « Plus tard ».
 */
import { nextTick, ref, useId, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'

const {
  ouverte,
  enCours = false,
  message = null,
  erreur = null,
} = defineProps<{
  ouverte: boolean
  enCours?: boolean
  /** Ce que le navigateur a répondu, en clair. */
  message?: string | null
  /** Une erreur du serveur, pour `MessageErreur`. */
  erreur?: unknown
}>()

const emit = defineEmits<{ activer: []; 'plus-tard': [] }>()

const dialogue = ref<HTMLDialogElement | null>(null)
const boutonSecondaire = ref<InstanceType<typeof BoutonBase> | null>(null)
const id = useId()

watch(
  () => ouverte,
  async (actuelle) => {
    const element = dialogue.value
    if (element === null) return
    if (actuelle && !element.open) {
      element.showModal()
      await nextTick()
      ;(boutonSecondaire.value?.$el as HTMLElement | undefined)?.focus()
    } else if (!actuelle && element.open) {
      element.close()
    }
  },
  { flush: 'post' },
)

/** Échap : rapporté comme « Plus tard » ; sans effet pendant que le navigateur
 *  attend Face ID ou l'empreinte. */
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
    data-invitation-cle-d-acces
    class="mx-0 mt-auto mb-0 w-full max-w-full rounded-t-panneau border border-b-0 border-ligne bg-panneau p-0 text-texte shadow-panneau backdrop:bg-black/50 md:m-auto md:w-[min(26rem,calc(100vw-1.5rem))] md:rounded-panneau md:border-b"
    @cancel="surAnnulation"
  >
    <div
      v-if="ouverte"
      class="flex flex-col gap-4 px-5 pt-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] md:pb-5"
    >
      <div class="flex items-start gap-3">
        <span
          class="flex size-10 flex-none items-center justify-center rounded-pastille bg-accent-doux text-accent"
          aria-hidden="true"
        >
          <!-- Une empreinte digitale : le geste qu'on propose, plus parlant
               qu'une clé pour qui n'a jamais entendu « passkey ». -->
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 11v3a8 8 0 0 1-1.5 4.7" />
            <path d="M8.5 9.5A3.5 3.5 0 0 1 15.5 11v1.5a12 12 0 0 1-.9 4.6" />
            <path d="M5.6 7.5A7.5 7.5 0 0 1 19.5 11v1a16 16 0 0 1-.6 4.3" />
            <path d="M5 11v.5a11 11 0 0 1-1 4.5" />
            <path d="M8.4 14.5a10 10 0 0 1-1.6 4.3" />
          </svg>
        </span>
        <div class="flex min-w-0 flex-col gap-1.5">
          <h2 :id="`${id}-titre`" class="m-0 text-base font-semibold">
            Se connecter plus vite la prochaine fois ?
          </h2>
          <p :id="`${id}-message`" class="m-0 text-sm text-attenue">
            Utilisez Face ID ou votre empreinte.
          </p>
        </div>
      </div>

      <!-- Le navigateur a dit non (annulé, impossible) : on le dit, la fenêtre
           reste, « Activer » permet de réessayer. -->
      <p v-if="message !== null" class="m-0 text-sm" role="alert">{{ message }}</p>
      <MessageErreur v-else-if="erreur !== null" :erreur="erreur" />

      <div class="flex flex-wrap justify-end gap-2">
        <BoutonBase ref="boutonSecondaire" variante="secondaire" :desactive="enCours" @click="emit('plus-tard')">
          Plus tard
        </BoutonBase>
        <BoutonBase variante="principal" :chargement="enCours" @click="emit('activer')">Activer</BoutonBase>
      </div>
    </div>
  </dialog>
</template>
