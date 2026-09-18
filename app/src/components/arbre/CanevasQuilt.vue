<script setup lang="ts">
/**
 * Le rendu du **quilt** — la seule vue de ce produit qui ne dessine aucune arête.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QU'IL PARTAGE AVEC LE CANEVAS DE L'ARBRE, ET CE QU'IL NE PARTAGE PAS.**
 *
 * Il partage tout ce qui n'est pas le dessin : le `viewBox` comme seul moyen de
 * zoomer — **jamais `transform: scale()`** (ADR 0007) —, les gestes à un et deux
 * doigts, le cadrage initial sur la boîte. Ces trois pièces sont écrites une fois
 * et éprouvées ailleurs ; les recopier ici aurait produit un second pincement à
 * corriger le jour où le premier l'est.
 *
 * Il ne partage pas la mise en scène. Le canevas de l'arbre construit 377 fiches
 * et leurs arêtes hors de la réactivité de Vue, parce qu'un `v-for` sur cette
 * quantité coûte trop cher. Ici, la matrice est faite de rectangles nus — pas de
 * texte par nœud sauf l'étiquette de ligne, pas de liseré, pas de chemin courbe —
 * et Vue les rend sans peine. On reste donc dans le gabarit, où le code est plus
 * court et plus lisible.
 *
 * **Toujours `fill-opacity`, jamais `opacity`.** Une couche de composition par
 * case coûterait, sur une matrice, bien plus cher que sur un arbre.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { IdentifiantNoeud } from '@/lib/api'
import { useGestesCanevas } from '@/composables/useGestesCanevas'
import { cadrer, viewBoxEnTexte, type Boite, type CadreEcran } from './geometrie'
import { H_LIGNE, L_COLONNE, type DispositionQuilt } from './dispositions/quilt'

const { quilt, selection } = defineProps<{
  quilt: DispositionQuilt | null
  /** La ligne mise en avant, ou `null`. */
  selection: IdentifiantNoeud | null
}>()

const emit = defineEmits<{
  personne: [id: IdentifiantNoeud]
  fond: []
}>()

const cadreRef = ref<HTMLDivElement | null>(null)
const svgRef = ref<SVGSVGElement | null>(null)

const boiteInitiale = ref<Boite>({ x: 0, y: 0, w: 1, h: 1 })
const vb = ref<Boite>({ x: 0, y: 0, w: 1, h: 1 })

const viewBox = computed(() => viewBoxEnTexte(vb.value))

function mesurerCadre(): CadreEcran {
  const element = cadreRef.value
  if (element === null) return { largeur: 1, hauteur: 1, gauche: 0, haut: 0 }
  const r = element.getBoundingClientRect()
  return { largeur: r.width, hauteur: r.height, gauche: r.left, haut: r.top }
}

/**
 * Le même contrat que le canevas de l'arbre, à une nuance près : ici le
 * `viewBox` est **réactif**. La matrice ne compte que quelques centaines de
 * rectangles, et Vue les repeint sans qu'on ait à sortir de sa réactivité — la
 * raison qui l'impose sur l'arbre n'existe pas.
 */
const gestes = useGestesCanevas({
  cadre: cadreRef,
  svg: svgRef,
  actif: () => quilt !== null,
  lireVb: () => vb.value,
  ecrireVb: (boite) => {
    vb.value = boite
  },
  boiteInitiale: () => boiteInitiale.value,
  mesurerCadre,
  appliquer: () => {},
})

function recadrer(): void {
  if (quilt === null) return

  boiteInitiale.value = quilt.boite

  // `cadrer()` prend des boîtes **centrées** — c'est la forme des nœuds de
  // l'arbre. La boîte du quilt, elle, est un rectangle coin-haut-gauche, comme
  // celle que l'API rend. On la convertit ici plutôt que d'ajouter une seconde
  // signature à une fonction éprouvée ailleurs.
  const centree = {
    x: quilt.boite.x + quilt.boite.w / 2,
    y: quilt.boite.y + quilt.boite.h / 2,
    w: quilt.boite.w,
    h: quilt.boite.h,
  }

  vb.value = cadrer([centree], mesurerCadre()) ?? quilt.boite
}

watch(() => quilt, recadrer, { immediate: true })

function surClic(id: IdentifiantNoeud | null): void {
  // Un déplacement se termine par un `click` : sans ce garde-fou, faire glisser
  // la matrice ouvrirait la fiche qui se trouve sous le doigt à l'arrivée.
  if (gestes.aBouge()) return

  if (id === null) emit('fond')
  else emit('personne', id)
}

let observateur: ResizeObserver | null = null

onMounted(() => {
  recadrer()

  if (typeof ResizeObserver === 'function' && cadreRef.value !== null) {
    observateur = new ResizeObserver(() => recadrer())
    observateur.observe(cadreRef.value)
  }
})

onBeforeUnmount(() => {
  observateur?.disconnect()
  observateur = null
})

defineExpose({ recadrer })
</script>

<template>
  <div
    ref="cadreRef"
    class="cadre-canevas relative h-full w-full overflow-clip bg-fond"
    @pointerdown="gestes.surPointeurBas"
    @pointermove="gestes.surPointeurDeplace"
    @pointerup="gestes.surPointeurFin"
    @pointercancel="gestes.surPointeurFin"
  >
    <svg
      v-if="quilt !== null"
      ref="svgRef"
      class="quilt h-full w-full"
      :viewBox="viewBox"
      preserveAspectRatio="xMidYMid meet"
      role="img"
      aria-label="Arbre en matrice : une ligne par personne, une colonne par famille"
      @click="surClic(null)"
    >
      <!-- Les colonnes d'abord : elles passent sous les lignes et les cases. -->
      <rect
        v-for="colonne in quilt.colonnes"
        :key="colonne.id"
        class="qcol"
        :class="{ div: colonne.divorce }"
        :x="colonne.x + 5"
        :y="colonne.y"
        :width="L_COLONNE - 10"
        :height="colonne.hauteur"
      />

      <g
        v-for="ligne in quilt.lignes"
        :key="ligne.id"
        class="qligne"
        :class="{ prive: ligne.vivant, attente: ligne.enAttente, vise: ligne.id === selection }"
        @click.stop="surClic(ligne.id)"
      >
        <rect :x="ligne.x" :y="ligne.y + 5" :width="ligne.largeur" :height="H_LIGNE - 10" />
        <text
          :x="ligne.xEtiquette"
          :y="ligne.y + H_LIGNE / 2"
          text-anchor="end"
          dominant-baseline="central"
        >
          {{ ligne.nom }}
        </text>
      </g>

      <!-- Les cases par-dessus : ce sont elles qu'on lit. -->
      <rect
        v-for="(c, index) in quilt.cases"
        :key="index"
        class="qcase"
        :class="c.role"
        :x="c.x + 2"
        :y="c.y + 2"
        :width="L_COLONNE - 4"
        :height="H_LIGNE - 4"
        :rx="c.role === 'enfant' ? 5 : 2"
      />
    </svg>
  </div>
</template>
