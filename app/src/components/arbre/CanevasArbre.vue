<script setup lang="ts">
/**
 * Le canevas de l'arbre : le `<svg>` et ses gestes.
 *
 * Ce composant est une **coquille**. Il monte un `<svg>` vide, le confie à
 * `construireCanevas()` quand un graphe arrive, et n'y touche plus que par
 * l'interface impérative que le pilote lui rend. Rien de ce qui se trouve dans
 * le canevas n'est réactif — voir l'en-tête de `canevas.ts` et le § 7.2 du
 * dossier de mission.
 *
 * **Le zoom passe par le `viewBox`.** `vb` est une variable ordinaire, pas une
 * `ref` : elle change à chaque image pendant un déplacement, et la faire
 * passer par le système réactif de Vue déclencherait un cycle de rendu par
 * mouvement de souris pour écrire un attribut que l'on sait écrire soi-même.
 *
 * **Reconstruction complète sur nouveau graphe, jamais de réconciliation.**
 * Un `304` ne passe pas ici du tout : la vue ne rappelle ce composant que sur
 * `statut === 'nouveau'` (§ 5.2, étape 3).
 */
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import type { Graphe, NoeudGraphe } from '@/lib/api'
import { useGestesCanevas } from '@/composables/useGestesCanevas'
import { construireCanevas, type PiloteCanevas } from './canevas'
import { cadrer, centrerSur, estDessinable, type Boite, type CadreEcran } from './geometrie'
import { tronc } from './graphe'

const { graphe, cadrageInitial = 'tout' } = defineProps<{
  /** `null` tant que le premier chargement n'a pas abouti. */
  graphe: Graphe | null
  /**
   * Ce que la vue montre **au premier affichage** d'un graphe.
   *
   * `tout` : la boîte englobante rendue par l'API — l'arbre entier. `tronc` : la
   * personne du lecteur, ses parents, sa fratrie, ses conjoints et ses enfants
   * ({@link tronc}). Sur un téléphone, l'arbre entier fait douze mille unités de
   * large pour un écran qui en montre trois cents : « tout » y est une vue de
   * rien. Le bouton « tout afficher » reste le chemin vers l'arbre complet.
   *
   * Lu à chaque construction, jamais entre deux : recadrer une vue que le
   * lecteur a déplacée serait lui reprendre la main.
   */
  cadrageInitial?: 'tout' | 'tronc'
}>()

const emit = defineEmits<{
  /** Une fiche ou un losange a été cliqué — jamais pendant un déplacement. */
  selection: [noeud: NoeudGraphe]
  /** Le fond a été cliqué : la vue en fait ce qu'elle veut (fermer le panneau). */
  fond: []
  /**
   * **Le canevas vient d'être (re)construit, et il est à jour.**
   *
   * Sans cet événement, la vue devait deviner le moment où le dessin existe — et
   * elle devinait mal. Les deux `watch` sont en `flush: 'post'` ; celui de la vue
   * est créé pendant le `setup` du PARENT, donc AVANT celui de l'enfant, et Vue
   * les exécute dans l'ordre de création. L'animation d'arrivée partait donc sur
   * le canevas d'AVANT, que `construire()` effaçait aussitôt : pas une image
   * peinte. **Un événement dit le fait ; un ordre de `flush` se devine.**
   */
  construit: []
}>()

const cadreRef = ref<HTMLDivElement | null>(null)
const svgRef = ref<SVGSVGElement | null>(null)

/** Exposé pour que la vue puisse lire l'index (voisinage, filtres…). */
const pilote = shallowRef<PiloteCanevas | null>(null)

/** La boîte du graphe telle que l'API la rend. **Jamais recalculée ici.** */
let boiteInitiale: Boite = { x: 0, y: 0, w: 1, h: 1 }
let vb: Boite = { x: 0, y: 0, w: 1, h: 1 }

function mesurerCadre(): CadreEcran {
  const element = cadreRef.value
  if (element === null) return { largeur: 1, hauteur: 1, gauche: 0, haut: 0 }
  const r = element.getBoundingClientRect()
  return { largeur: r.width, hauteur: r.height, gauche: r.left, haut: r.top }
}

function appliquer(): void {
  // Un `viewBox` sans surface n'est pas dessiné ; un `viewBox` infini est
  // ignoré, et le graphe surgit à l'échelle 1 depuis le coin. Ni l'un ni
  // l'autre ne se rattrape au geste : on revient à l'arbre entier, qui l'est.
  if (!estDessinable(vb)) vb = { ...boiteInitiale }
  pilote.value?.appliquerViewBox(vb)
}

/* ------------------------------------------------------------------ */
/* Construction                                                        */
/* ------------------------------------------------------------------ */

function construire(): void {
  const svg = svgRef.value
  if (svg === null) return

  pilote.value?.detruire()
  if (graphe === null) {
    pilote.value = null
    return
  }

  pilote.value = construireCanevas(svg, graphe)
  emit('construit')
  boiteInitiale = { ...graphe.boite }

  // D'abord l'arbre entier — un cadrage toujours valable, qui ne dépend pas de la
  // taille du cadre. Puis, sur téléphone, le tronc : la personne du lecteur et
  // ses proches. À défaut d'un tronc trouvable — lecteur inconnu du graphe —,
  // l'arbre entier reste.
  recadrer()
  const cible = cadrageInitial === 'tronc' ? tronc(pilote.value.index, graphe.ego) : []
  if (cible.length > 0) cadrerQuandLeCadreAUneTaille(cible)
}

/**
 * Le cadrage du tronc a besoin de la **taille du cadre à l'écran**, et elle peut
 * être nulle au moment où le graphe arrive : la vue tient ce composant sous un
 * `v-show`, et un `watch` ordinaire court **avant** qu'elle retire le
 * `display: none` — `getBoundingClientRect()` rend alors 0 × 0. Le `watch` du
 * bas est donc en `flush: 'post'`, {@link cadrer} refuse un cadre sans taille,
 * et ce qui suit est la ceinture par-dessus les bretelles.
 *
 * Ce que ça donnait sur téléphone, rapporté puis retrouvé dans le code : un
 * `viewBox` de largeur 0, que le navigateur ne dessine pas — rien à l'écran ;
 * au premier pincement, une division par cette largeur — un `viewBox` infini,
 * que le navigateur ignore : le graphe surgit à l'échelle 1 depuis le coin, et
 * plus aucun geste ne le rattrape. Deux bogues visibles, une seule cause.
 *
 * On cadre tout de suite si le cadre a une taille, et sinon **à la première
 * taille**, une seule fois : recadrer ensuite reprendrait la main au lecteur.
 */
let observateurDeCadre: ResizeObserver | null = null

function cadrerQuandLeCadreAUneTaille(ids: readonly string[]): void {
  observateurDeCadre?.disconnect()
  observateurDeCadre = null

  const cadre = mesurerCadre()
  if (cadre.largeur > 0 && cadre.hauteur > 0) {
    cadrerSur(ids)
    return
  }

  const element = cadreRef.value
  if (element === null || typeof ResizeObserver === 'undefined') return

  observateurDeCadre = new ResizeObserver(() => {
    const mesure = mesurerCadre()
    if (!(mesure.largeur > 0) || !(mesure.hauteur > 0)) return
    observateurDeCadre?.disconnect()
    observateurDeCadre = null
    cadrerSur(ids)
  })
  observateurDeCadre.observe(element)
}

onBeforeUnmount(() => {
  observateurDeCadre?.disconnect()
  observateurDeCadre = null
})

/* ------------------------------------------------------------------ */
/* Gestes                                                              */
/* ------------------------------------------------------------------ */

/**
 * Molette, déplacement et **pincement à deux doigts** vivent dans un composable
 * partagé avec la chronologie : les deux canevas portaient jusqu'ici le même
 * bloc, et le zoom à deux doigts manquait dans les deux.
 *
 * Seul `surClic` reste ici, parce que les deux canevas n'y font pas la même
 * chose : sur une fiche il ouvre le panneau, sur une barre il sélectionne.
 */
const gestes = useGestesCanevas({
  cadre: cadreRef,
  svg: svgRef,
  actif: () => pilote.value !== null,
  lireVb: () => vb,
  ecrireVb: (boite) => {
    vb = boite
  },
  boiteInitiale: () => boiteInitiale,
  mesurerCadre,
  appliquer,
})

function surClic(ev: MouseEvent): void {
  // Un déplacement — ou un pincement — se termine par un `click` : sans ce
  // garde-fou, déplacer l'arbre ouvrirait la fiche qui se trouve sous le doigt
  // à l'arrivée.
  if (gestes.aBouge() || pilote.value === null) return
  const noeud = pilote.value.noeudSousLeCurseur(ev.target)
  if (noeud === null) emit('fond')
  else emit('selection', noeud)
}

/* ------------------------------------------------------------------ */
/* Interface impérative offerte à la vue                               */
/* ------------------------------------------------------------------ */

function recadrer(): void {
  vb = { ...boiteInitiale }
  appliquer()
}

function centrer(id: string): void {
  const boite = pilote.value?.boiteDe(id)
  if (boite === undefined || boite === null) return
  vb = centrerSur(boite, boiteInitiale, mesurerCadre())
  appliquer()
}

function cadrerSur(ids: Iterable<string>): void {
  const boites = pilote.value?.boitesDe(ids) ?? []
  const nouvelle = cadrer(boites, mesurerCadre())
  if (nouvelle === null) return
  vb = nouvelle
  appliquer()
}

/**
 * Le pilote est exposé par une **fonction** et non par la `ref` elle-même :
 * `defineExpose` déballe les `ref` à l'exécution mais pas toujours au typage,
 * et un `.value` de trop sur un `null` ne se voit qu'au clic.
 */
defineExpose({
  piloteCourant: (): PiloteCanevas | null => pilote.value,
  recadrer,
  centrer,
  cadrerSur,
})

/* ------------------------------------------------------------------ */
/* Cycle de vie                                                        */
/* ------------------------------------------------------------------ */

onMounted(() => {
  // L'écoute de la molette appartient au composable : il lui faut
  // `passive: false` pour empêcher le défilement de la page, et il la retire.
  construire()
})

onBeforeUnmount(() => {
  pilote.value?.detruire()
  pilote.value = null
})

// Un nouveau graphe ⇒ reconstruction complète. Le `304` n'arrive jamais ici :
// la vue ne change la prop que sur `statut === 'nouveau'`.
//
// `flush: 'post'` : la construction mesure le cadre, et la vue ne retire son
// `display: none` qu'en rendant — un `watch` ordinaire courrait avant, sur un
// cadre de 0 × 0. Voir `cadrerQuandLeCadreAUneTaille`.
watch(
  () => graphe,
  () => {
    construire()
  },
  { flush: 'post' },
)
</script>

<template>
  <div
    ref="cadreRef"
    class="cadre-canevas relative h-full w-full overflow-hidden"
    @pointerdown="gestes.surPointeurBas"
    @pointermove="gestes.surPointeurDeplace"
    @pointerup="gestes.surPointeurFin"
    @pointercancel="gestes.surPointeurFin"
    @click="surClic"
  >
    <!--
      `preserveAspectRatio="xMidYMid meet"` : le contenu est centré et tient
      entier dans le cadre. Le `viewBox` est posé par le pilote, jamais ici —
      et il n'y a **aucun** `transform` sur cet élément (règle n° 1).
    -->
    <svg
      ref="svgRef"
      class="canevas"
      preserveAspectRatio="xMidYMid meet"
      role="img"
      aria-label="Arbre généalogique"
    ></svg>
  </div>
</template>
