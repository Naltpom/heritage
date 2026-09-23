<script setup lang="ts">
/**
 * « Installer Heritage sur votre téléphone ».
 *
 * Deux formes : le **bandeau**, sur téléphone seulement, que « Plus tard »
 * écarte (mémorisé par appareil) ; le **panneau** de « Mon compte », toujours
 * là tant que l'application n'est pas installée — pour qui a écarté le bandeau.
 */
import { computed, ref } from 'vue'
import { useInstallation } from '@/composables/installation'
import { useEstMobile } from '@/composables/useTiroirs'
import BoutonBase from '@/components/base/BoutonBase.vue'

const { forme = 'bandeau' } = defineProps<{ forme?: 'bandeau' | 'panneau' }>()

const { invitation, installee, ios, installer } = useInstallation()
const estMobile = useEstMobile()

const CLE = 'heritage.installation.plus-tard'
function lireEcarte(): boolean {
  try {
    return globalThis.localStorage?.getItem(CLE) === '1'
  } catch {
    return false
  }
}
const ecarte = ref(lireEcarte())
const aide = ref(false)

const possible = computed(() => !installee.value && (invitation.value !== null || ios))
const visible = computed(() => possible.value && (forme === 'panneau' || (estMobile.value && !ecarte.value)))

async function surInstaller(): Promise<void> {
  if (invitation.value) await installer()
  else aide.value = !aide.value
}

function plusTard(): void {
  ecarte.value = true
  try {
    globalThis.localStorage?.setItem(CLE, '1')
  } catch {
    // Le bandeau reviendra à la prochaine ouverture.
  }
}
</script>

<template>
  <div
    v-if="visible"
    :class="
      forme === 'bandeau'
        ? 'flex flex-wrap items-center gap-3 border-b border-ligne bg-panneau px-4 py-2 text-sm'
        : 'flex flex-wrap items-center gap-3 text-sm'
    "
    role="status"
  >
    <img src="/icons/icone-192.png" alt="" class="h-9 w-9 rounded-lg" />
    <span class="min-w-0 flex-1">Installez Heritage sur votre téléphone : l'arbre s'ouvrira comme une application.</span>
    <div class="flex gap-2">
      <BoutonBase variante="principal" @click="surInstaller">Installer</BoutonBase>
      <BoutonBase v-if="forme === 'bandeau'" variante="discret" @click="plusTard">Plus tard</BoutonBase>
    </div>
    <p v-if="aide" class="m-0 w-full text-attenue">
      Dans Safari, touchez <strong class="text-texte">Partager</strong>
      <svg class="inline h-4 w-4 align-text-bottom" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 3v12M8 7l4-4 4 4M5 12v8h14v-8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      puis <strong class="text-texte">Sur l'écran d'accueil</strong>.
    </p>
  </div>
</template>
