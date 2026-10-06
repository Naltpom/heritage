<script setup lang="ts">
/**
 * Les boîtes des fiches, en coordonnées **monde** — construites et mutées
 * **impérativement** (ADR 0007, règle 3) : Vue ne tient aucune des fiches.
 *
 * - Les boîtes sont créées une fois par disposition (`fiches` change : le
 *   dessin entier est reconstruit, pas réconcilié).
 * - Leurs styles sont réécrits quand `styles` change — passage au niveau
 *   Ensemble, filtre, recherche, ligne directe —, jamais pendant un glisser.
 * - Pan et zoom : une seule écriture par image, la transformation du conteneur
 *   (`cadrer`), sans rendu de composant.
 * - **Un seul** écouteur de clic et de clavier, délégué au conteneur, qui
 *   remonte la fiche choisie.
 *
 * Les boîtes restent du HTML et non du SVG : un rôle de bouton atteignable au
 * clavier, des bordures arrondies et le liseré de branche (note du 2026-10-06 à
 * l'ADR 0007). Une fiche estompée l'est par la couleur de son fond et de ses
 * bordures (`apparence.ts`), **jamais par `opacity`**.
 *
 * Aucun texte ici : les textes sont dessinés par la vue, en coordonnées écran,
 * à taille fixe.
 */
import { onMounted, ref, watch } from 'vue'
import type { FicheHistoire } from './lignees'

const props = defineProps<{
  fiches: FicheHistoire[]
  styles: Record<string, string>[]
}>()

const emit = defineEmits<{ choisir: [fiche: FicheHistoire] }>()

const monde = ref<HTMLDivElement | null>(null)
let boites: HTMLDivElement[] = []

function construire(): void {
  const racine = monde.value
  if (!racine) return
  const fragment = document.createDocumentFragment()
  boites = props.fiches.map((f, i) => {
    const b = document.createElement('div')
    b.className = 'fiche-histoire'
    b.setAttribute('role', 'button')
    b.tabIndex = 0
    b.setAttribute('aria-label', f.noeud.displayName)
    b.dataset['index'] = String(i)
    fragment.append(b)
    return b
  })
  racine.replaceChildren(fragment)
  peindre()
}

function peindre(): void {
  props.styles.forEach((style, i) => {
    const b = boites[i]
    if (!b) return
    b.style.cssText = ''
    Object.assign(b.style, style)
  })
}

/** La fiche sous l'événement, par délégation. */
function ficheDe(e: Event): FicheHistoire | null {
  const b = (e.target as HTMLElement | null)?.closest?.('[data-index]') as HTMLElement | null
  return b ? (props.fiches[Number(b.dataset['index'])] ?? null) : null
}

function surClic(e: MouseEvent): void {
  const f = ficheDe(e)
  if (f) emit('choisir', f)
}

function surTouche(e: KeyboardEvent): void {
  if (e.key !== 'Enter' && e.key !== ' ') return
  const f = ficheDe(e)
  if (!f) return
  e.preventDefault()
  emit('choisir', f)
}

watch(() => props.fiches, construire)
watch(() => props.styles, peindre)
onMounted(construire)

defineExpose({
  /** Pan et zoom : la transformation du conteneur (`transformationMonde`). */
  cadrer(transformation: string): void {
    if (monde.value) monde.value.style.transform = transformation
  },
})
</script>

<template>
  <div ref="monde" class="absolute top-0 left-0 origin-top-left" @click="surClic" @keydown="surTouche" />
</template>

<style>
/* Global : les boîtes sont créées impérativement, sans l'attribut d'un style `scoped`. */
.fiche-histoire {
  position: absolute;
  box-sizing: border-box;
  cursor: pointer;
}
.fiche-histoire:focus-visible {
  outline: 2px solid var(--color-accent);
  outline-offset: 2px;
}
/*
 * L'apparition au premier affichage : le fond et les bordures viennent de la
 * transparence, chaque fiche à son tour. Ni `opacity` ni `transform` animés : l'un
 * comme l'autre feraient de chaque fiche une couche de composition le temps de
 * l'animation — plusieurs centaines à la fois (ADR 0007, note du 2026-10-06).
 */
@keyframes apparition-fiche {
  from {
    background-color: transparent;
    border-color: transparent;
  }
}
</style>
