<script setup lang="ts">
/**
 * Le tiroir des filtres, sur mobile — et rien du tout au-delà de `md`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ce que `md:contents` garantit.** Au-delà du seuil, ce conteneur ne génère
 * aucune boîte : la barre d'outils qu'on lui a confiée redevient enfant flex
 * direct de la vue, exactement là où elle était. Un écran large rend donc le
 * même arbre de boîtes qu'avant l'existence de ce composant.
 *
 * **C'est un `<div>`, et jamais un `<aside>`.** `arbre-revalidation.test.ts`
 * localise le bloc qui rogne le panneau de fiche en cherchant le parent du
 * **premier `<aside>` dans l'ordre du document**, et `PanneauFiche` est le seul
 * `<aside>` de toute l'application. Un tiroir rendu en `<aside>` repointerait
 * silencieusement trois de ces quatre tests sur le mauvais élément : ils
 * continueraient de passer, ou d'échouer, pour une raison qui n'aurait plus rien
 * à voir avec ce qu'ils décrivent.
 *
 * **Le déclencheur n'est pas ici, il est dans l'en-tête.** Un bouton posé sur la
 * surface de l'écran prendrait de la hauteur au canevas, qui est précisément ce
 * dont on manque sur un téléphone. Ce composant se contente de s'annoncer au
 * montage, et l'en-tête fait paraître son bouton à l'autre bout de la barre —
 * en face du burger de navigation.
 *
 * **Fermé, le panneau est `max-md:hidden`.** `display: none` le retire de la
 * mise en page, du parcours clavier **et** de l'arbre d'accessibilité, et le
 * fait de façon bornée au point de rupture. `inert` n'a pas de variante de
 * largeur et resterait posé sur grand écran, où ce composant doit être
 * transparent.
 *
 * **Ce à quoi une barre d'outils doit ressembler pour entrer ici :** un
 * `flex flex-wrap` **nu**, sans bordure ni fond ni gouttière extérieure. Ce
 * décor appartient à l'hôte — la bande en haut de l'écran sur desktop, le
 * panneau ici — et non à la barre. Elle doit aussi **émettre sans rien
 * retenir** : tout l'état vit dans la vue, ce qui est déjà la règle de la
 * maison, et c'est ce qui fait de son emplacement une simple question de mise
 * en page.
 * ────────────────────────────────────────────────────────────────────
 */
import { onBeforeUnmount, onMounted } from 'vue'
import { annoncerBarreDOutils, useTiroirs } from '@/composables/useTiroirs'

const { titre = 'Filtres' } = defineProps<{
  /** Ce que le lecteur d'écran annonce en ouvrant le tiroir. */
  titre?: string
}>()

const { ouvert, fermerTiroirs } = useTiroirs()

let retirer: (() => void) | null = null

onMounted(() => {
  retirer = annoncerBarreDOutils()
})

onBeforeUnmount(() => {
  retirer?.()
  retirer = null
})
</script>

<template>
  <div
    v-if="ouvert === 'outils'"
    class="fixed inset-0 z-30 bg-black/40 md:hidden"
    aria-hidden="true"
    @click="fermerTiroirs"
  />

  <div
    id="tiroir-outils"
    class="md:contents max-md:fixed max-md:inset-y-0 max-md:right-0 max-md:z-40 max-md:flex max-md:w-[min(20rem,88vw)] max-md:flex-col max-md:gap-3 max-md:overflow-y-auto max-md:border-l max-md:border-ligne max-md:bg-panneau max-md:p-3 max-md:shadow-panneau"
    :class="ouvert === 'outils' ? '' : 'max-md:hidden'"
    role="group"
    :aria-label="titre"
    @keydown.escape="fermerTiroirs"
  >
    <slot />

    <div class="md:hidden">
      <slot name="legende" />
    </div>
  </div>
</template>
