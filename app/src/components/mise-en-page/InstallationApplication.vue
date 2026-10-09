<script setup lang="ts">
/**
 * « Installer Heritage sur votre téléphone » — le panneau de « Moi » et de
 * « Mon compte ».
 *
 * Toujours là tant que l'application n'est pas installée et qu'il y a de quoi
 * l'installer (un navigateur qui le propose, ou un iPhone) : c'est le chemin
 * de qui a répondu « Plus tard » à la fenêtre de l'arrivée
 * (`InvitationInstallation`), qui a remplacé le bandeau sous l'en-tête.
 *
 * L'icône et la phrase tiennent sur une ligne ; « Installer » passe dessous,
 * pleine largeur, sur un écran étroit — rien ne déborde à 320 px — et se
 * range à côté dès qu'il y a la place (`sm`).
 *
 * Sur iPhone, il n'y a pas de boîte du navigateur à rejouer : « Installer »
 * déplie les deux gestes de Safari, et le dit (`aria-expanded`).
 */
import { computed, ref, useId } from 'vue'
import { useInstallation } from '@/composables/installation'
import BoutonBase from '@/components/base/BoutonBase.vue'

const { invitation, installee, ios, installer } = useInstallation()
const aide = ref(false)
const idAide = `${useId()}-aide`

const visible = computed(() => !installee.value && (invitation.value !== null || ios))

async function surInstaller(): Promise<void> {
  if (invitation.value) await installer()
  else aide.value = !aide.value
}
</script>

<template>
  <div
    v-if="visible"
    class="flex flex-col gap-3 text-sm sm:flex-row sm:flex-wrap sm:items-center"
    data-installation-application
  >
    <div class="flex min-w-0 items-center gap-3 sm:flex-1">
      <img src="/icons/icone-192.png" alt="" width="36" height="36" class="size-9 flex-none rounded-lg" />
      <span class="min-w-0">Installez Heritage sur votre téléphone, comme une application.</span>
    </div>
    <BoutonBase
      variante="principal"
      class="w-full sm:w-auto"
      :aria-expanded="invitation === null ? aide : undefined"
      :aria-controls="aide ? idAide : undefined"
      @click="surInstaller"
    >
      Installer
    </BoutonBase>
    <p v-if="aide" :id="idAide" class="m-0 text-attenue sm:basis-full">
      Dans Safari, touchez <strong class="text-texte">Partager</strong>
      <svg class="inline size-4 align-text-bottom" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 3v12M8 7l4-4 4 4M5 12v8h14v-8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      (ou les trois points
      <svg class="inline size-4 align-text-bottom" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" />
      </svg>, puis <strong class="text-texte">Partager</strong>), puis <strong class="text-texte">Sur l’écran d’accueil</strong>.
    </p>
  </div>
</template>
