<script setup lang="ts">
/**
 * Les boîtes des fiches de `/arbre`, en coordonnées **monde** — construites et
 * mutées **impérativement** (ADR 0007, règle 3) : Vue ne tient aucune des fiches.
 *
 * - Les boîtes sont créées une fois par disposition (`fiches` change : le dessin
 *   entier est reconstruit, pas réconcilié — la fiche qui avait le focus le garde).
 * - Leurs styles sont réécrits quand `styles` change — passage au niveau Ensemble,
 *   filtre, recherche, ligne directe —, et seulement ceux qui ont changé ; jamais
 *   pendant un glisser.
 * - Pan et zoom : une seule écriture par image, la transformation du conteneur
 *   (`cadrer`), sans rendu de composant. Les boîtes sont du HTML et n'ont pas de
 *   `viewBox` : c'est la révision du 2026-10-14 de l'ADR 0007.
 * - **Un seul** écouteur de clic et un seul de clavier, délégués au conteneur, qui
 *   remontent la fiche choisie. Chaque boîte reste un bouton atteignable au clavier
 *   (`role="button"`, `tabindex="0"`, son nom en `aria-label`).
 *
 * Une fiche estompée l'est par la couleur de son fond et de ses bordures
 * (`apparence.ts`), **jamais par `opacity`**. `histoire/FichesMonde.vue`, le pendant de
 * `/arbre-2`, n'est pas touché.
 *
 * Aucun texte ici : les textes sont dessinés par la vue, en coordonnées écran, à
 * taille fixe.
 */
import { onMounted, ref, watch } from 'vue'
import type { FicheHistoire } from '@/histoire/lignees'

const props = defineProps<{
  fiches: FicheHistoire[]
  /** Le `cssText` de chaque boîte, dans l'ordre des fiches (`styleDeFiche`). */
  styles: string[]
  /**
   * Le motif d'une recherche close, une phrase par côté clos (`arbre2/sommets.ts`), dans
   * l'ordre des fiches — `null` pour les autres. Dit au survol (`title`) et à un lecteur
   * d'écran (`aria-label`).
   */
  infobulles?: (string | null)[]
  /** L'apparition au premier affichage : un seul fondu, pour tout le calque. */
  revelation: boolean
}>()

const emit = defineEmits<{ choisir: [fiche: FicheHistoire] }>()

const monde = ref<HTMLDivElement | null>(null)
let boites: HTMLDivElement[] = []
/** Ce qui est écrit dans chaque boîte : on ne réécrit que ce qui change. */
let ecrits: string[] = []

function construire(): void {
  const racine = monde.value
  if (!racine) return
  const focalisee = (document.activeElement as HTMLElement | null)?.closest?.('.fiche-groupe') as HTMLElement | null
  const idFocalisee = focalisee && racine.contains(focalisee) ? focalisee.dataset['id'] : undefined
  const fragment = document.createDocumentFragment()
  boites = props.fiches.map((f, i) => {
    const b = document.createElement('div')
    b.className = 'fiche-groupe'
    b.setAttribute('role', 'button')
    b.tabIndex = 0
    nommer(b, f.noeud.displayName, props.infobulles?.[i] ?? null)
    b.dataset['index'] = String(i)
    b.dataset['id'] = f.id
    fragment.append(b)
    return b
  })
  ecrits = []
  racine.replaceChildren(fragment)
  peindre()
  if (idFocalisee !== undefined) boites.find((b) => b.dataset['id'] === idFocalisee)?.focus({ preventScroll: true })
}

/** Le nom de la boîte, suivi du motif d'une recherche close : `title` (survol) et `aria-label` (clavier, lecteur d'écran). */
function nommer(b: HTMLElement, nom: string, motif: string | null): void {
  b.setAttribute('aria-label', motif === null ? nom : `${nom}. ${motif.replace(/\n/g, '. ')}`)
  if (motif === null) b.removeAttribute('title')
  else b.title = `${nom}\n${motif}`
}

/** Un motif qui change (graphe relu, parent lié depuis) : les noms se réécrivent, pas les boîtes. */
function nommerTout(): void {
  for (let i = 0; i < boites.length; i++) nommer(boites[i]!, props.fiches[i]!.noeud.displayName, props.infobulles?.[i] ?? null)
}

function peindre(): void {
  const styles = props.styles
  for (let i = 0; i < boites.length; i++) {
    const style = styles[i] ?? ''
    if (ecrits[i] === style) continue
    boites[i]!.style.cssText = style
    ecrits[i] = style
  }
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
watch(() => props.infobulles, nommerTout)
onMounted(construire)

defineExpose({
  /** Pan et zoom : la transformation du conteneur (`transformationMonde`). */
  cadrer(transformation: string): void {
    if (monde.value) monde.value.style.transform = transformation
  },
})
</script>

<template>
  <div
    ref="monde"
    class="absolute top-0 left-0 origin-top-left"
    :class="revelation ? 'calque-apparait' : ''"
    @click="surClic"
    @keydown="surTouche"
  />
</template>

<style>
/* Global : les boîtes sont créées impérativement, sans l'attribut d'un style `scoped`. */
.fiche-groupe {
  position: absolute;
  box-sizing: border-box;
  cursor: pointer;
}
.fiche-groupe:focus-visible {
  outline: 2px solid var(--color-accent);
  outline-offset: 2px;
}
</style>
