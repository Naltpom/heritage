<script setup lang="ts" generic="Cle extends string">
/**
 * Une rangée d'onglets dont le soulignement **glisse** d'un onglet à l'autre.
 *
 * Partagée par Explorer (Chronologie · Carte · Calendrier) et par la fiche
 * (refonte du 30/09/2026). Chaque onglet est un **lien** : l'onglet vit dans
 * l'URL (route enfant ou `?onglet=`), si bien qu'il se partage, se recharge et
 * revient au « précédent ». Le composant ne retient rien : l'onglet actif vient
 * de la vue.
 *
 * Le soulignement est un seul trait, positionné sous l'onglet actif et animé
 * en `transform`/`width`. Sur téléphone, la rangée défile horizontalement si
 * elle déborde, et l'onglet actif est ramené dans le champ.
 *
 * Le contenu qui coulisse sous la rangée est l'affaire de `ContenuGlissant`.
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { RouteLocationRaw } from 'vue-router'

export interface OngletGlissant<C extends string = string> {
  cle: C
  libelle: string
  /** Libellé de la rangée étroite (téléphone). Par défaut, `libelle`. */
  libelleCourt?: string
  /** `null` ou absent : pas de compteur. */
  compte?: number | null
  vers: RouteLocationRaw
}

const { onglets, actif, etiquette } = defineProps<{
  onglets: readonly OngletGlissant<Cle>[]
  actif: Cle
  etiquette: string
  /** Onglets de largeur égale (Explorer) plutôt qu'ajustés au libellé (fiche). */
  egaux?: boolean
}>()

const rangee = ref<HTMLElement | null>(null)
const trait = ref({ x: 0, largeur: 0, pret: false })

function mesurer(): void {
  const el = rangee.value?.querySelector<HTMLElement>(`[data-onglet="${actif}"]`)
  if (!el || !rangee.value) return
  trait.value = { x: el.offsetLeft, largeur: el.offsetWidth, pret: true }
  // Ramène l'onglet actif dans le champ quand la rangée défile (téléphone).
  const r = rangee.value
  if (el.offsetLeft < r.scrollLeft || el.offsetLeft + el.offsetWidth > r.scrollLeft + r.clientWidth) {
    const calme = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
    r.scrollTo({ left: el.offsetLeft - 16, behavior: calme ? 'auto' : 'smooth' })
  }
}

let observateur: ResizeObserver | null = null
onMounted(() => {
  void nextTick(mesurer)
  void document.fonts?.ready.then(mesurer)
  if (typeof ResizeObserver !== 'undefined' && rangee.value) {
    observateur = new ResizeObserver(mesurer)
    observateur.observe(rangee.value)
  }
})
onBeforeUnmount(() => observateur?.disconnect())
watch(
  () => [actif, onglets.map((o) => `${o.cle}:${o.compte ?? ''}`).join('|')],
  () => void nextTick(mesurer),
)
</script>

<template>
  <nav
    ref="rangee"
    :aria-label="etiquette"
    class="relative flex whitespace-nowrap max-md:overflow-x-auto max-md:[scrollbar-width:none]"
  >
    <RouterLink
      v-for="o in onglets"
      :key="o.cle"
      :to="o.vers"
      :data-onglet="o.cle"
      :aria-current="o.cle === actif ? 'page' : undefined"
      class="flex h-11 items-center justify-center px-3 text-sm transition-colors"
      :class="[egaux ? 'flex-1' : 'flex-none', o.cle === actif ? 'font-semibold text-accent' : 'text-attenue hover:text-texte']"
    >
      <span :class="o.libelleCourt ? 'max-md:hidden' : ''">{{ o.libelle }}</span>
      <span v-if="o.libelleCourt" class="md:hidden">{{ o.libelleCourt }}</span>
      <template v-if="o.compte !== undefined && o.compte !== null">
        <span aria-hidden="true">&nbsp;·&nbsp;</span>
        <span class="tnum">{{ o.compte }}</span>
      </template>
    </RouterLink>
    <span
      aria-hidden="true"
      class="trait pointer-events-none absolute bottom-0 left-0 h-0.5 rounded-pastille bg-accent"
      :class="trait.pret ? '' : 'opacity-0'"
      :style="{ width: `${trait.largeur}px`, transform: `translateX(${trait.x}px)` }"
    />
  </nav>
</template>

<style scoped>
.trait {
  transition:
    transform 240ms cubic-bezier(0.2, 0.7, 0.2, 1),
    width 240ms cubic-bezier(0.2, 0.7, 0.2, 1);
}

@media (prefers-reduced-motion: reduce) {
  .trait {
    transition: none;
  }
}
</style>
