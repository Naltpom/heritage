<script setup lang="ts">
/**
 * La barre d'écran de la refonte (handoff « En-tête, barres d'écran et fiche »,
 * § 2) — la même pour toutes les vues filtrables, dans le même ordre :
 *
 *     [ titre ] [ vue ] | [ pastilles ] ——— [ compteur ] [ Filtres · n ] [ actions ]
 *
 * **Bureau** : une barre de 50 px, `bg-fond`, sur une ligne. « Filtres · n »
 * ouvre un menu déroulant de 360 px sous la barre.
 *
 * **Téléphone** : la VUE reste visible, en rang 2 sous l'en-tête. Tout le reste
 * — pastilles, contenu des filtres, actions de `#actions-mobile` — va dans le
 * FEUILLET qui monte du bas, ouvert par le bouton filtres de l'en-tête.
 *
 * Contraintes reprises de `TiroirOutils`, toujours vraies :
 * - le conteneur n'est **jamais un `<aside>`** ;
 * - le déclencheur mobile est dans l'en-tête (`annoncerBarreDOutils`) ; sans
 *   filtre au-delà de la vue, pas de bouton (Mes demandes) ;
 * - le contenu des filtres n'est rendu **qu'une fois** : le même bloc est le
 *   menu déroulant sur bureau et le feuillet sur téléphone ;
 * - fermé, il est `max-md:hidden` (et `md:hidden`), jamais `inert`.
 *
 * L'en-tête est collé en haut, en `z-30`. La barre vit en `z-20` (son menu
 * passe au-dessus du contenu, sous l'en-tête) ; feuillet ouvert, elle monte en
 * `z-40` pour que son voile couvre aussi l'en-tête.
 *
 * La barre **n'a aucun état métier** : le nombre de filtres actifs et le
 * libellé du bouton « Voir … » lui sont donnés ; elle émet `reinitialiser`.
 */
import { computed, onBeforeUnmount, onMounted, ref, useSlots, watch } from 'vue'
import { annoncerBarreDOutils, useEstMobile, useTiroirs } from '@/composables/useTiroirs'
import { publierFiltresActifs } from '@/composables/entete'
import { useGlisserPourFermer } from '@/composables/useGlisserPourFermer'
import { lecteurVideoOuvert } from '@/composables/lecteurVideo'

const {
  filtresActifs = 0,
  ecran,
  voir = '',
  reinitialisable = true,
  titrePastilles = 'Afficher',
} = defineProps<{
  /** Nombre de filtres actifs : pastille du bouton et du bouton de l'en-tête. */
  filtresActifs?: number
  /** Nom de l'écran, dans « Filtres · {écran} ». */
  ecran: string
  /** Libellé du bouton de pied du feuillet : « Voir 467 personnes ». */
  voir?: string
  reinitialisable?: boolean
  /** Titre de la section des pastilles, dans le feuillet du téléphone. */
  titrePastilles?: string
}>()
const emit = defineEmits<{ reinitialiser: [] }>()

const slots = useSlots()
const estMobile = useEstMobile()
const { ouvert, fermerTiroirs } = useTiroirs()

/** Y a-t-il quelque chose au-delà de la vue ? Sinon, pas de bouton Filtres. */
const aDesFiltres = computed(() => Boolean(slots.filtres || slots.pastilles || slots['actions-mobile']))
const menuBureau = ref(false)
const ouvertMobile = computed(() => ouvert.value === 'outils')
const zone = ref<HTMLElement | null>(null)
/** Le feuillet du téléphone : il se ferme en le tirant vers le bas, depuis son haut. */
const feuillet = ref<HTMLElement | null>(null)
useGlisserPourFermer(feuillet, computed(() => ouvertMobile.value && estMobile.value), () => fermer())

let retirer: (() => void) | null = null
onMounted(() => {
  if (aDesFiltres.value) retirer = annoncerBarreDOutils()
  document.addEventListener('click', surClicExterieur, true)
  window.addEventListener('keydown', surEchap, true)
  publierFiltresActifs(filtresActifs)
})
onBeforeUnmount(() => {
  retirer?.()
  document.removeEventListener('click', surClicExterieur, true)
  window.removeEventListener('keydown', surEchap, true)
})

/**
 * Échap, menu ouvert : il ne sert qu'à fermer le menu. Écouté en capture et
 * arrêté là, pour que l'écran derrière (désélection…) ne l'entende pas.
 *
 * Sauf sous une vidéo : ouverte depuis ce menu (« Voir la vidéo » des filtres
 * de l'arbre), elle le recouvre, et Échap est à elle. Cet écouteur-ci, inscrit
 * avant le sien, passerait sinon le premier et fermerait un menu invisible
 * (`composables/lecteurVideo.ts`).
 */
function surEchap(e: KeyboardEvent): void {
  if (e.key !== 'Escape' || !menuBureau.value || lecteurVideoOuvert.value) return
  e.stopImmediatePropagation()
  menuBureau.value = false
}
watch(
  () => filtresActifs,
  (n) => publierFiltresActifs(n),
)

function surClicExterieur(e: MouseEvent): void {
  if (!menuBureau.value || estMobile.value) return
  if (zone.value?.contains(e.target as Node)) return
  menuBureau.value = false
}

function fermer(): void {
  menuBureau.value = false
  fermerTiroirs()
}
</script>

<template>
  <div
    class="relative flex flex-none items-center gap-2.5 border-b border-ligne whitespace-nowrap md:h-[50px] md:bg-fond md:px-5"
    :class="[$slots.vue ? 'max-md:bg-panneau max-md:px-3 max-md:pb-2.5' : 'max-md:contents', ouvertMobile ? 'z-40' : 'z-20']"
    @keydown.escape="fermer"
  >
    <span v-if="$slots.titre" class="mr-1 text-base font-semibold max-md:hidden"><slot name="titre" /></span>

    <div v-if="$slots.vue" class="flex items-center gap-2 max-md:w-full max-md:overflow-x-auto">
      <slot name="vue" />
    </div>

    <template v-if="$slots.pastilles && !estMobile">
      <span v-if="$slots.vue" class="h-5 w-px flex-none bg-ligne" aria-hidden="true" />
      <div class="flex min-w-0 items-center gap-1.5 overflow-hidden"><slot name="pastilles" /></div>
    </template>

    <span class="flex-1 max-md:hidden" aria-hidden="true" />

    <span v-if="$slots.compteur" class="text-[12.5px] text-attenue tabular-nums max-md:hidden"><slot name="compteur" /></span>

    <div ref="zone" class="md:relative max-md:contents">
      <button
        v-if="$slots.filtres"
        type="button"
        class="flex h-8 items-center gap-1.5 rounded-champ border px-3 text-[13px] max-md:hidden"
        :class="filtresActifs > 0 ? 'border-accent' : 'border-ligne hover:border-accent'"
        :aria-expanded="menuBureau"
        aria-controls="tiroir-outils"
        @click="menuBureau = !menuBureau"
      >
        <svg width="15" height="15" viewBox="0 0 20 20" aria-hidden="true">
          <path d="M3 5h14M6 10h8M9 15h2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
        </svg>
        Filtres
        <span v-if="filtresActifs > 0" class="flex h-[18px] min-w-[18px] items-center justify-center rounded-pastille bg-accent px-1 text-[11px] font-bold text-fond">{{ filtresActifs }}</span>
      </button>

      <!-- Le voile du feuillet, sur téléphone seulement. -->
      <div v-if="ouvertMobile && estMobile" class="fixed inset-0 z-30 bg-black/40 md:hidden" aria-hidden="true" @click="fermer" />

      <!-- UN seul bloc : menu déroulant sur bureau, feuillet sur téléphone (voir le script). -->
      <div
        v-if="aDesFiltres"
        id="tiroir-outils"
        ref="feuillet"
        role="group"
        :aria-label="`Filtres · ${ecran}`"
        class="flex flex-col gap-3 border border-ligne bg-panneau shadow-panneau whitespace-normal md:absolute md:top-[calc(100%+8px)] md:right-0 md:z-40 md:max-h-[calc(100dvh-140px)] md:w-[360px] md:overflow-y-auto md:overscroll-contain md:rounded-panneau md:p-4 max-md:fixed max-md:inset-x-0 max-md:bottom-0 max-md:z-40 max-md:max-h-[84%] max-md:overflow-y-auto max-md:overscroll-contain max-md:rounded-t-2xl max-md:px-4 max-md:pt-2 max-md:pb-[max(16px,env(safe-area-inset-bottom))]"
        :class="[ouvertMobile ? '' : 'max-md:hidden', menuBureau && $slots.filtres ? '' : 'md:hidden']"
      >
        <!-- La poignée : on tire le feuillet vers le bas pour le fermer. -->
        <span class="-mt-2 -mb-1 flex h-6 flex-none items-center justify-center md:hidden" aria-hidden="true">
          <span class="h-[5px] w-10 rounded-pastille bg-ligne" />
        </span>
        <div class="flex items-center justify-between">
          <span class="text-[17px] font-semibold md:text-[15px]">Filtres · {{ ecran }}</span>
          <button v-if="reinitialisable" type="button" class="h-9 text-sm font-semibold text-accent" @click="emit('reinitialiser')">Réinitialiser</button>
        </div>
        <div v-if="$slots.pastilles && estMobile" class="flex flex-col gap-2">
          <span class="text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">{{ titrePastilles }}</span>
          <div class="grid grid-cols-2 gap-2 [&>*]:min-h-10"><slot name="pastilles" /></div>
        </div>
        <slot name="filtres" />
        <div v-if="$slots['actions-mobile'] && estMobile" class="flex flex-col gap-2"><slot name="actions-mobile" /></div>
        <button
          v-if="voir"
          type="button"
          class="mt-1 h-12 w-full flex-none rounded-champ bg-accent font-semibold text-fond md:hidden"
          @click="fermer"
        >
          {{ voir }}
        </button>
      </div>
    </div>

    <div v-if="$slots.actions" class="flex items-center gap-2 max-md:hidden"><slot name="actions" /></div>
  </div>
</template>
