<script setup lang="ts">
/**
 * La fenêtre « Comment afficher les fiches de l'arbre ? » — l'**affichage**
 * seul.
 *
 * Le pilote (quand l'ouvrir, que retenir de la réponse) est dans
 * `composables/formatDesCartes.ts` (`piloterInvitationFormat`) et le
 * branchement dans `InvitationFormatDesCartes` ; ici on ne fait que montrer et
 * rapporter `choisir` ou `garder`.
 *
 * **Même cadre, même mécanique que `FenetreInvitationCleDAcces`**, exprès :
 *
 * - Un `<dialog>` natif ouvert **sans** `showModal()`, avec notre propre voile.
 *   `showModal()` rend inerte tout ce qui est hors de la fenêtre — y compris
 *   ce que les gestionnaires de mots de passe et autres extensions injectent
 *   dans la page, qui s'ouvrait et se refermait aussitôt. Échap vaut « Garder
 *   l'actuel » (écouté à la main : un dialogue non modal n'émet pas `cancel`),
 *   le voile aussi. `role` et `aria-modal` restent pour les lecteurs d'écran.
 * - Feuille qui monte du bas sur téléphone — c'est là qu'elle s'ouvre seule —,
 *   boîte centrée ailleurs.
 *
 * Deux options, chacune avec un **aperçu dessiné** de la fiche : un nom de
 * format ne dit rien à qui n'a jamais vu l'autre. L'aperçu est du HTML
 * statique, avec une personne inventée — jamais quelqu'un de l'arbre.
 *
 * Le focus part sur l'option **actuelle**, pas sur « Portrait » : un Entrée
 * réflexe garde l'affichage connu au lieu d'en changer. Il **reste dedans** tant
 * qu'elle est ouverte (Tab boucle sur ses boutons : sans `showModal()`, rien
 * d'autre ne l'y retient, et `aria-modal` le promet aux lecteurs d'écran), et
 * **revient** à l'élément qui l'avait à la fermeture.
 */
import { nextTick, ref, useId, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import type { FormatDeCarte } from '@/histoire/cartes'

const { ouverte, actuel = 'compact' } = defineProps<{
  ouverte: boolean
  /** Le format affiché en ce moment : coché, et focalisé à l'ouverture. */
  actuel?: FormatDeCarte
}>()

const emit = defineEmits<{ choisir: [format: FormatDeCarte]; garder: [] }>()

/** Une personne inventée (ADR 0006), la même dans les deux aperçus. */
const EXEMPLE = { nom: 'ROMARIN', prenom: 'Jade', dates: '1902 – 1981' } as const

const OPTIONS: readonly { valeur: FormatDeCarte; libelle: string; aide: string }[] = [
  { valeur: 'compact', libelle: 'Compact', aide: 'Petite photo, nom à côté : plus de monde à l’écran.' },
  { valeur: 'portrait', libelle: 'Portrait', aide: 'Grande photo, nom et dates dessous.' },
]

const dialogue = ref<HTMLDialogElement | null>(null)
const options = ref<HTMLButtonElement[]>([])
const id = useId()
/** L'élément qui avait le focus à l'ouverture : il le retrouve à la fermeture. */
let focusAvant: HTMLElement | null = null

watch(
  () => ouverte,
  async (actuelle) => {
    const element = dialogue.value
    if (element === null) return
    if (actuelle && !element.open) {
      focusAvant = document.activeElement instanceof HTMLElement ? document.activeElement : null
      element.show()
      await nextTick()
      const i = OPTIONS.findIndex((o) => o.valeur === actuel)
      options.value[Math.max(0, i)]?.focus()
    } else if (!actuelle && element.open) {
      element.close()
      if (focusAvant?.isConnected) focusAvant.focus()
      focusAvant = null
    }
  },
  { flush: 'post' },
)

function surAnnulation(evenement: Event): void {
  evenement.preventDefault()
  emit('garder')
}

/** Échap = garder ; les flèches passent d'une option à l'autre, comme dans
 *  tout groupe de boutons radio (sans choisir : choisir ferme la fenêtre). */
function surTouche(evenement: KeyboardEvent): void {
  if (evenement.key === 'Escape') {
    surAnnulation(evenement)
    return
  }
  if (evenement.key === 'Tab') {
    garderLeFocus(evenement)
    return
  }
  const sens = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[evenement.key]
  if (sens === undefined) return
  const i = options.value.findIndex((b) => b === document.activeElement)
  if (i < 0) return
  evenement.preventDefault()
  options.value[(i + sens + OPTIONS.length) % OPTIONS.length]?.focus()
}

/** Tab et Maj+Tab bouclent sur les boutons de la fenêtre, sans en sortir. */
function garderLeFocus(evenement: KeyboardEvent): void {
  const element = dialogue.value
  if (element === null) return
  const focalisables = [...element.querySelectorAll<HTMLElement>('button:not([disabled]):not([tabindex="-1"])')]
  if (focalisables.length === 0) return
  const premier = focalisables[0]!
  const dernier = focalisables[focalisables.length - 1]!
  const ici = document.activeElement
  if (evenement.shiftKey && (ici === premier || !element.contains(ici))) {
    evenement.preventDefault()
    dernier.focus()
  } else if (!evenement.shiftKey && (ici === dernier || !element.contains(ici))) {
    evenement.preventDefault()
    premier.focus()
  }
}
</script>

<template>
  <!-- Le voile : le nôtre, pas `::backdrop`, qui n'existe qu'en modal. Le
       toucher vaut « Garder l'actuel ». -->
  <div
    v-if="ouverte"
    class="fixed inset-0 z-[600] bg-black/50"
    aria-hidden="true"
    data-voile-format-des-cartes
    @click="emit('garder')"
  />
  <!-- La fenêtre défile si l'écran est trop bas (téléphone couché : ~360 px de
       haut, moins que les deux aperçus) — sans quoi le titre sortirait par le haut. -->
  <dialog
    ref="dialogue"
    role="dialog"
    aria-modal="true"
    :aria-labelledby="`${id}-titre`"
    :aria-describedby="`${id}-message`"
    data-format-des-cartes
    class="fixed inset-0 z-[610] mx-0 mt-auto mb-0 h-fit max-h-[100dvh] w-full max-w-full overflow-y-auto overscroll-contain rounded-t-panneau border border-b-0 border-ligne bg-panneau p-0 text-texte shadow-panneau md:m-auto md:w-[min(28rem,calc(100vw-1.5rem))] md:rounded-panneau md:border-b"
    @cancel="surAnnulation"
    @keydown="surTouche"
  >
    <div v-if="ouverte" class="flex flex-col gap-4 px-5 pt-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] md:pb-5">
      <div class="flex flex-col gap-1.5">
        <h2 :id="`${id}-titre`" class="m-0 text-base font-semibold">Comment afficher les fiches de l’arbre ?</h2>
        <p :id="`${id}-message`" class="m-0 text-sm text-attenue">Modifiable à tout moment dans Filtres.</p>
      </div>

      <div role="radiogroup" :aria-labelledby="`${id}-titre`" class="grid grid-cols-2 gap-3">
        <button
          v-for="o in OPTIONS"
          :key="o.valeur"
          ref="options"
          type="button"
          role="radio"
          :aria-checked="o.valeur === actuel"
          :tabindex="o.valeur === actuel ? 0 : -1"
          :data-option-format="o.valeur"
          class="flex flex-col items-center gap-2.5 rounded-panneau border-2 bg-fond p-3 text-left"
          :class="o.valeur === actuel ? 'border-accent' : 'border-ligne'"
          @click="emit('choisir', o.valeur)"
        >
          <!-- L'aperçu : une fiche dessinée, à l'échelle du format. -->
          <span class="flex h-[132px] w-full items-center justify-center" aria-hidden="true">
            <span
              v-if="o.valeur === 'compact'"
              class="flex w-full items-center gap-1.5 rounded-[7px] border border-l-[3px] border-ligne border-l-accent bg-panneau px-1.5 py-1.5"
            >
              <svg class="size-7 flex-none" viewBox="0 0 40 40">
                <circle cx="20" cy="20" r="20" class="fill-current text-attenue opacity-15" />
                <circle cx="20" cy="15.6" r="6.8" class="fill-current text-attenue opacity-40" />
                <path d="M8.8,34.8 a11.2,11.2 0 0 1 22.4,0 Z" class="fill-current text-attenue opacity-40" />
              </svg>
              <span class="flex min-w-0 flex-col leading-tight">
                <span class="truncate text-[11px] font-semibold">{{ EXEMPLE.prenom }} {{ EXEMPLE.nom }}</span>
                <span class="truncate font-mono text-[9.5px] text-attenue">{{ EXEMPLE.dates }}</span>
              </span>
            </span>
            <span
              v-else
              class="flex w-[88px] flex-col items-center gap-1 rounded-[9px] border border-t-[3px] border-ligne border-t-accent bg-panneau px-1.5 pt-2 pb-1.5 leading-tight"
            >
              <!-- Un carré aux coins arrondis, comme le cadre photo de l'arbre en Portrait. -->
              <svg class="size-14" viewBox="0 0 40 40">
                <rect width="40" height="40" rx="6" class="fill-current text-attenue opacity-15" />
                <circle cx="20" cy="15.6" r="6.8" class="fill-current text-attenue opacity-40" />
                <path d="M8.8,34.8 a11.2,11.2 0 0 1 22.4,0 Z" class="fill-current text-attenue opacity-40" />
              </svg>
              <span class="max-w-full truncate text-[11px] font-semibold uppercase">{{ EXEMPLE.nom }}</span>
              <span class="max-w-full truncate text-[11px]">{{ EXEMPLE.prenom }}</span>
              <span class="max-w-full truncate font-mono text-[9.5px] text-attenue">{{ EXEMPLE.dates }}</span>
            </span>
          </span>
          <span class="flex flex-col gap-0.5">
            <span class="text-sm font-semibold">{{ o.libelle }}</span>
            <span class="text-xs text-attenue">{{ o.aide }}</span>
          </span>
        </button>
      </div>

      <div class="flex justify-end">
        <BoutonBase variante="secondaire" @click="emit('garder')">Garder l’actuel</BoutonBase>
      </div>
    </div>
  </dialog>
</template>
