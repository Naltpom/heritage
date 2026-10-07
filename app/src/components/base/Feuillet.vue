<script setup lang="ts">
/**
 * Le feuillet — un panneau qui monte du bas de l'écran (handoff « mode
 * Administration », § 4.5). Formulaires de création (invitation, source),
 * actions d'un compte, filtres de l'historique.
 *
 * Un `<dialog>` natif ouvert en modal, comme `DialogueConfirmation` : il piège
 * le focus, se ferme à Échap et rend le reste de la page inerte. Le focus
 * revient au déclencheur à la fermeture — le navigateur le fait, on le refait
 * pour les moteurs qui l'oublient.
 *
 * Superposition sombre sur tout l'écran ; un clic dehors (sur le voile) ferme.
 * Panneau collé en bas, 520 px au plus, 86 % de la hauteur au plus, défilant,
 * rayons de 16 px en haut, poignée de 36 × 4. En-tête : le titre et « Fermer ».
 *
 * Le parent tient l'ouverture (`ouvert`) ; le feuillet rapporte `fermer`.
 */
import { nextTick, ref, useId, watch } from 'vue'

const { ouvert, titre } = defineProps<{
  ouvert: boolean
  titre: string
}>()

const emit = defineEmits<{ fermer: [] }>()

const dialogue = ref<HTMLDialogElement | null>(null)
const id = useId()
let declencheur: HTMLElement | null = null

watch(
  () => ouvert,
  async (actuel) => {
    const element = dialogue.value
    // Rendu hors navigateur (tests en mémoire) : pas de `showModal`.
    if (element === null || typeof element.showModal !== 'function') return
    if (actuel && !element.open) {
      const actif = globalThis.document?.activeElement as HTMLElement | null | undefined
      declencheur = actif != null && typeof actif.focus === 'function' ? actif : null
      element.showModal()
      await nextTick()
      // Le premier champ s'il y en a un ; sinon « Fermer », que le navigateur a choisi.
      const premier = element.querySelector?.<HTMLElement>('[data-feuillet-corps] input, [data-feuillet-corps] textarea')
      premier?.focus()
    } else if (!actuel && element.open) {
      element.close()
    }
  },
  { flush: 'post', immediate: true },
)

/** Échap ferme le `<dialog>` de lui-même : on le rapporte, et on rend le focus. */
function surFermeture(): void {
  if (ouvert) emit('fermer')
  const cible = declencheur
  declencheur = null
  if (cible !== null && cible.isConnected !== false) void nextTick(() => cible.focus())
}

/** Le voile est le `<dialog>` lui-même : un clic qui l'atteint est un clic dehors. */
function surClic(evenement: MouseEvent): void {
  if (evenement.target === dialogue.value) emit('fermer')
}
</script>

<template>
  <dialog
    ref="dialogue"
    class="feuillet mx-auto mt-auto mb-0 max-h-[86dvh] w-full max-w-[520px] overflow-y-auto rounded-t-[16px] border-0 bg-panneau p-0 text-texte"
    :aria-labelledby="`${id}-titre`"
    data-feuillet
    @close="surFermeture"
    @click="surClic"
  >
    <div v-if="ouvert" class="flex flex-col gap-3.5 px-5 pt-2 pb-[max(22px,env(safe-area-inset-bottom))]">
      <span class="h-1 w-9 self-center rounded-sm bg-ligne" aria-hidden="true" />
      <div class="flex items-center justify-between gap-2">
        <h2 :id="`${id}-titre`" class="text-base font-bold">{{ titre }}</h2>
        <button
          type="button"
          class="min-h-10 px-1 text-sm font-semibold text-accent"
          data-fermer-feuillet
          @click="emit('fermer')"
        >
          Fermer
        </button>
      </div>
      <div class="flex flex-col gap-3.5" data-feuillet-corps>
        <slot />
      </div>
    </div>
  </dialog>
</template>

<style scoped>
.feuillet {
  box-shadow: 0 -6px 22px rgb(0 0 0 / 0.14);
}
.feuillet::backdrop {
  background: rgb(27 27 27 / 0.34);
}
</style>
