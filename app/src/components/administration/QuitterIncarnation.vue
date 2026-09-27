<script setup lang="ts">
/**
 * « Revenir à mon compte » — la sortie d'une incarnation (ADR 0010).
 *
 * La session est relue **quoi qu'il arrive**, comme pour la vue empruntée : si
 * le serveur a déjà rendu le compte d'origine, garder le bandeau afficherait
 * l'inverse de la vérité. On repart ensuite de l'écran des utilisateurs, d'où
 * l'incarnation a été lancée.
 */
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { quitterIncarnation } from '@/lib/api'
import { rafraichirSession } from '@/composables/useSession'

const routeur = useRouter()
const enCours = ref(false)

async function revenir(): Promise<void> {
  if (enCours.value) return
  enCours.value = true
  try {
    await quitterIncarnation()
  } finally {
    await rafraichirSession()
    enCours.value = false
  }
  await routeur.push({ name: 'administration.utilisateurs' })
}
</script>

<template>
  <button
    type="button"
    :disabled="enCours"
    class="rounded-champ border border-alerte px-2.5 py-0.5 text-sm font-semibold transition-colors hover:bg-alerte hover:text-fond disabled:opacity-60"
    @click="revenir"
  >
    Revenir à mon compte
  </button>
</template>
