<script setup lang="ts">
/**
 * **`/arbre-6` — l'arbre en quilt.**
 *
 * ────────────────────────────────────────────────────────────────────
 * **POURQUOI CELLE-CI A SA PROPRE VUE, ET PAS LES QUATRE AUTRES.**
 *
 * Les dispositions `/arbre-2` à `/arbre-5` sont des transformations de la charge :
 * elles rendent un `Graphe`, que le canevas de l'arbre dessine sans savoir qu'il
 * a été retouché. Une seule vue suffit donc, et c'est ce qui les rend comparables.
 *
 * Le quilt n'est pas une disposition, c'est une **autre représentation**. Il ne
 * rend pas un graphe : il rend une matrice, qui n'a ni fiche, ni losange, ni
 * arête. Le canevas de l'arbre n'a rien à en faire, et lui faire porter les deux
 * en aurait fait un composant à deux modes — celui qu'on ne sait plus lire.
 *
 * Ce qui est **partagé** l'est quand même : les gestes, le `viewBox`, le panneau
 * de fiche, le cadre à hauteur mesurée. Voir {@link CanevasQuilt}.
 *
 * ── Ce que cette maquette ne fait pas ──────────────────────────────────────
 *
 * Ni recherche, ni filtres, ni calque. Ce sont des surfaces à part entière, et
 * les recopier ici aurait doublé ce qu'il faudra corriger une fois si la maquette
 * est retenue, et jeté deux fois si elle ne l'est pas. Ce qu'elle doit permettre,
 * c'est de **juger la représentation** : la matrice se lit-elle, ou non.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { hauteurDuCadre } from '@/views/cadre'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import CanevasQuilt from '@/components/arbre/CanevasQuilt.vue'
import PanneauFiche from '@/components/arbre/PanneauFiche.vue'
import { quilt as composerLeQuilt } from '@/components/arbre/dispositions/quilt'
import { useAppelApi } from '@/composables/useAppelApi'
import {
  chargerGraphe,
  lireFichePersonne,
  type FichePersonne,
  type Graphe,
  type IdentifiantNoeud,
} from '@/lib/api'

const graphe = shallowRef<Graphe | null>(null)

const {
  erreur: erreurGraphe,
  chargement: chargementGraphe,
  executer: appelerGraphe,
} = useAppelApi(chargerGraphe)

async function charger(): Promise<void> {
  const resultat = await appelerGraphe()

  if (resultat !== null && resultat.statut === 'nouveau') graphe.value = resultat.donnees
}

/**
 * La matrice, recomposée quand le graphe change.
 *
 * `shallowRef` et non `computed` : la composition parcourt les 377 nœuds et leurs
 * arêtes plusieurs fois, et un `computed` la rejouerait à chaque lecture d'une
 * dépendance réactive voisine. Ici elle ne tourne qu'au chargement.
 */
const matrice = shallowRef<ReturnType<typeof composerLeQuilt> | null>(null)

watch(graphe, (g) => {
  matrice.value = g === null ? null : composerLeQuilt(g)
})

/* ------------------------------------------------------------------ */
/* La fiche                                                            */
/* ------------------------------------------------------------------ */

const selection = ref<IdentifiantNoeud | null>(null)
const fiche = shallowRef<FichePersonne | null>(null)
const erreurFiche = ref<unknown>(null)
const chargementFiche = ref(false)

let abandon: AbortController | null = null

async function ouvrir(id: IdentifiantNoeud): Promise<void> {
  const noeud = graphe.value?.noeuds.find((n) => n.id === id)

  if (noeud === undefined || noeud.type !== 'personne' || noeud.personId === null) {
    // Un nœud en attente n'a pas d'identifiant d'arbre : son détail passe par la
    // demande qui le porte (§ 6.6), et cette maquette ne l'ouvre pas.
    return
  }

  selection.value = id
  abandon?.abort()
  const controleur = new AbortController()
  abandon = controleur

  fiche.value = null
  erreurFiche.value = null
  chargementFiche.value = true

  try {
    const lue = await lireFichePersonne(noeud.personId)
    if (!controleur.signal.aborted) fiche.value = lue
  } catch (cause) {
    if (!controleur.signal.aborted) erreurFiche.value = cause
  } finally {
    if (!controleur.signal.aborted) chargementFiche.value = false
  }
}

function fermer(): void {
  selection.value = null
  fiche.value = null
  erreurFiche.value = null
  abandon?.abort()
}

function allerVers(personId: number): void {
  const noeud = graphe.value?.noeuds.find(
    (n) => n.type === 'personne' && n.personId === personId,
  )

  if (noeud !== undefined) void ouvrir(noeud.id)
}

/* ------------------------------------------------------------------ */
/* Le cadre                                                            */
/* ------------------------------------------------------------------ */

const racine = ref<HTMLElement | null>(null)
const hauteurCadre = ref('100vh')

function mesurer(): void {
  const element = racine.value
  if (element === null) return

  // `hauteurDuCadre()` prend l'ordonnée du haut du cadre, pas l'élément : c'est
  // la même lecture que l'arbre, et elle vit à un seul endroit pour que les deux
  // écrans ne divergent pas d'un pixel.
  hauteurCadre.value = hauteurDuCadre(element.getBoundingClientRect().top)
}

function auClavier(ev: KeyboardEvent): void {
  if (ev.key === 'Escape') fermer()
}

onMounted(() => {
  mesurer()
  window.addEventListener('resize', mesurer)
  window.addEventListener('keydown', auClavier)
  void charger()
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', mesurer)
  window.removeEventListener('keydown', auClavier)
  abandon?.abort()
})

const lignes = computed(() => matrice.value?.lignes.length ?? 0)
const colonnes = computed(() => matrice.value?.colonnes.length ?? 0)
const etalement = computed(() => matrice.value?.etalement ?? 0)
</script>

<template>
  <div ref="racine" class="flex flex-col" :style="{ height: hauteurCadre }">
    <div class="border-b border-ligne bg-panneau px-4 py-2 text-xs text-texte">
      <p>
        <strong class="font-semibold">Maquette « quilt ».</strong>
        Il n’y a plus aucun trait, donc plus aucun croisement possible : chaque
        <strong>ligne</strong> est une personne, chaque <strong>colonne</strong> une famille, et une
        case dit qu’elle en fait partie — <strong>pleine</strong> comme parent,
        <strong>creuse</strong> comme enfant. La parenté se lit à l’alignement, pas en suivant un
        trait. C’est la réponse de GeneaQuilts (InfoVis 2010) au même problème.
      </p>
      <p class="mt-1 text-attenue tnum">
        {{ lignes }} lignes, {{ colonnes }} colonnes, étalement {{ etalement }}.
        <RouterLink :to="{ name: 'arbre' }" class="text-accent underline">
          Comparer à l’arbre d’origine
        </RouterLink>
      </p>
    </div>

    <div class="relative min-h-0 flex-1 overflow-clip">
      <div v-if="chargementGraphe && graphe === null" class="p-4">
        <p class="mb-3 text-sm text-attenue" role="status">Chargement de l’arbre…</p>
        <SqueletteChargement :lignes="6" />
      </div>

      <MessageErreur v-else-if="erreurGraphe !== null" :erreur="erreurGraphe" @action="charger" />

      <CanevasQuilt
        v-else
        :quilt="matrice"
        :selection="selection"
        @personne="ouvrir"
        @fond="fermer"
      />

      <PanneauFiche
        :fiche="fiche"
        :brouillon="null"
        :chargement="chargementFiche"
        :erreur="erreurFiche"
        :ouvert="selection !== null"
        @fermer="fermer"
        @aller="allerVers"
        @reessayer="selection !== null && ouvrir(selection)"
      />
    </div>
  </div>
</template>
