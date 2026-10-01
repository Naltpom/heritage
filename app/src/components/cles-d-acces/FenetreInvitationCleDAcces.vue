<script setup lang="ts">
/**
 * La fenêtre « Se connecter plus vite ? » — l'**affichage** seul.
 *
 * Le pilote (quand l'ouvrir, sur quelle étape, que retenir de la réponse) est
 * dans `useClesDAcces` (`piloterInvitationCleDAcces`) et le branchement dans
 * `InvitationCleDAcces` ; ici on ne fait que montrer et rapporter `activer`,
 * `confirmer` (le mot de passe) ou `plus-tard`.
 *
 * **Même cadre, même mécanique que `FenetreInvitationNotifications`**, exprès :
 * les deux fenêtres se suivent (jamais ensemble, voir `fenetresAutomatiques.ts`)
 * et doivent se lire comme la même famille d'offre.
 *
 * - Un `<dialog>` natif ouvert en modal : focus piégé, reste de la page inerte,
 *   Échap qui vaut « Plus tard ». `role` et `aria-modal` redits pour les
 *   lecteurs d'écran anciens.
 * - Le focus part sur « Plus tard », pas sur « Activer » ni sur le champ : une
 *   clé créée d'un Entrée réflexe ne serait pas un choix, et un clavier qui
 *   surgit sur une fenêtre qu'on n'a pas demandée non plus.
 * - Feuille qui monte du bas sur téléphone, boîte centrée sur bureau.
 *
 * Deux étapes (voir l'en-tête de `useClesDAcces.ts`) :
 *
 * - `activer` : un geste, « Activer », qui ouvre Face ID / l'empreinte ;
 * - `mot-de-passe` : la session est trop ancienne pour que le serveur délivre
 *   un défi. Un champ, « Confirmer » ; puis l'étape `activer` revient, et son
 *   bouton dit ce qu'il ouvre (« Activer Face ID / empreinte »). Le mot de
 *   passe est remis au pilote **et effacé du champ** dans le même geste : il
 *   ne reste nulle part.
 *
 * Un refus du navigateur (« annulé ») **ne ferme pas** la fenêtre : il s'écrit
 * dedans, et la personne peut réessayer ou dire « Plus tard ».
 */
import { computed, nextTick, ref, useId, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import {
  libelleActivationCleDAcces,
  texteInvitationCleDAcces,
  type SystemeAppareil,
} from '@/lib/cleDAcces'
import type { EtapeInvitationCleDAcces } from '@/composables/useClesDAcces'

const {
  ouverte,
  etape = 'activer',
  motDePasseConfirme = false,
  systeme = 'autre',
  enCours = false,
  message = null,
  erreurMotDePasse = null,
  erreur = null,
} = defineProps<{
  ouverte: boolean
  etape?: EtapeInvitationCleDAcces
  /** Le mot de passe vient d'être confirmé : « Activer » devient « Activer
   *  Face ID / empreinte », le second geste. */
  motDePasseConfirme?: boolean
  systeme?: SystemeAppareil
  enCours?: boolean
  /** Ce que le navigateur a répondu, en clair. */
  message?: string | null
  /** Le mot de passe refusé, sous le champ. */
  erreurMotDePasse?: string | null
  /** Une erreur du serveur, pour `MessageErreur`. */
  erreur?: unknown
}>()

const emit = defineEmits<{ activer: []; confirmer: [motDePasse: string]; 'plus-tard': [] }>()

const dialogue = ref<HTMLDialogElement | null>(null)
const boutonSecondaire = ref<InstanceType<typeof BoutonBase> | null>(null)
const boutonPrincipal = ref<InstanceType<typeof BoutonBase> | null>(null)
const id = useId()

/** Le temps de la saisie, pas plus : vidé à l'envoi et à la fermeture. */
const motDePasse = ref('')

const texte = computed(() => texteInvitationCleDAcces(systeme))
const libelleActiver = computed(() => (motDePasseConfirme ? libelleActivationCleDAcces(systeme) : 'Activer'))

watch(
  () => ouverte,
  async (actuelle) => {
    const element = dialogue.value
    if (!actuelle) motDePasse.value = ''
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

/** Mot de passe accepté : le formulaire disparaît, et le focus avec lui. Il
 *  va au bouton du second geste — c'est la suite que la personne attend. */
watch(
  () => motDePasseConfirme,
  async (confirme) => {
    if (!confirme) return
    await nextTick()
    ;(boutonPrincipal.value?.$el as HTMLElement | undefined)?.focus()
  },
  { flush: 'post' },
)

function confirmer(): void {
  if (enCours || motDePasse.value === '') return
  const saisi = motDePasse.value
  motDePasse.value = ''
  emit('confirmer', saisi)
}

/** Échap : rapporté comme « Plus tard » ; sans effet pendant que le navigateur
 *  attend Face ID ou l'empreinte, ou que le serveur vérifie le mot de passe. */
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
          <h2 :id="`${id}-titre`" class="m-0 text-base font-semibold">Se connecter plus vite ?</h2>
          <p :id="`${id}-message`" class="m-0 text-sm text-attenue">{{ texte }}</p>
        </div>
      </div>

      <!-- Étape « mot de passe » : la session est trop ancienne pour que le
           serveur délivre un défi sans lui (§ 2.4). Un formulaire, pour
           qu'Entrée envoie et que le gestionnaire de mots de passe remplisse. -->
      <form
        v-if="etape === 'mot-de-passe'"
        class="flex flex-col gap-4"
        novalidate
        data-confirmation-mot-de-passe
        @submit.prevent="confirmer"
      >
        <p class="m-0 text-sm">Pour votre sécurité, confirmez votre mot de passe.</p>
        <ChampTexte
          v-model="motDePasse"
          etiquette="Mot de passe"
          type="password"
          autocomplete="current-password"
          :erreur="erreurMotDePasse ?? ''"
          :desactive="enCours"
          requis
        />
        <MessageErreur v-if="erreur !== null" :erreur="erreur" />

        <div class="flex flex-wrap justify-end gap-2">
          <BoutonBase ref="boutonSecondaire" variante="secondaire" :desactive="enCours" @click="emit('plus-tard')">
            Plus tard
          </BoutonBase>
          <BoutonBase type="submit" variante="principal" :chargement="enCours" :desactive="motDePasse === ''">
            Confirmer
          </BoutonBase>
        </div>
      </form>

      <template v-else>
        <!-- Le navigateur a dit non (annulé, impossible) : on le dit, la
             fenêtre reste, « Activer » permet de réessayer. -->
        <p v-if="message !== null" class="m-0 text-sm" role="alert">{{ message }}</p>
        <MessageErreur v-else-if="erreur !== null" :erreur="erreur" />

        <div class="flex flex-wrap justify-end gap-2">
          <BoutonBase ref="boutonSecondaire" variante="secondaire" :desactive="enCours" @click="emit('plus-tard')">
            Plus tard
          </BoutonBase>
          <BoutonBase ref="boutonPrincipal" variante="principal" :chargement="enCours" @click="emit('activer')">
            {{ libelleActiver }}
          </BoutonBase>
        </div>
      </template>
    </div>
  </dialog>
</template>
