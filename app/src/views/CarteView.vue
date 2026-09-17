<script setup lang="ts">
/**
 * La carte des lieux — § 19 du contrat.
 *
 * Où la famille a vécu ses événements : une bulle par lieu situé, dont l'aire
 * dit le nombre et la couleur dit, au choix, l'ancienneté ou la densité.
 *
 * Cette vue **orchestre** ; elle ne dessine pas. Le dessin est dans
 * `components/carte/`, et ce qui s'y décide est dans trois modules purs que des
 * tests couvrent — Leaflet touche le DOM et ne peut être monté sous
 * l'environnement `node` de vitest.
 *
 * ────────────────────────────────────────────────────────────────────
 * **LES DÉCISIONS PRISES ICI, ET NULLE PART AILLEURS.**
 *
 * 1. **Une seule charge, au montage.** Les filtres et la bascule du dégradé sont
 *    des **réglages de rendu** : ils ne changent pas ce que le serveur calcule.
 *    À l'échelle de cet arbre — environ 500 événements datés après le cercle —
 *    tout tient en une trentaine de kilo-octets, et chaque bascule devient
 *    instantanée. C'est la différence avec la chronologie, dont `bande` et
 *    `branches` changent réellement le calcul du serveur, et qui a donc une clé
 *    de demande là où cet écran n'en a pas besoin.
 *
 * 2. **Le fond détaillé est éteint par défaut.** C'est le seul endroit du
 *    produit où une requête sort vers un tiers. La CSP l'autorise depuis
 *    `app/nginx.conf`, ce qui n'est pas la même chose que de l'employer : tant
 *    que personne n'appuie, rien ne part. La barre d'outils dit ce que l'appui
 *    coûte **avant** l'appui, et ce que le serveur de tuiles voit alors : une
 *    zone regardée — un `{z}/{x}/{y}` — jamais un nom, jamais une date.
 *
 * 3. **Ce qui n'est pas situé est compté et énumérable, jamais escamoté.** Un
 *    lieu écrit que le référentiel n'a pas su placer ne devient pas une bulle à
 *    des coordonnées inventées : il alimente un compteur sur lequel on peut
 *    cliquer. C'est aussi le seul chiffre qui dise s'il vaut la peine de rejouer
 *    `heritage:resoudre-les-lieux` sur le serveur.
 *
 * 4. **Aucun total « sur N ».** Une personne vivante hors du cercle du lecteur
 *    ne produit aucun événement — elle est absente, et non réduite. Un total
 *    global dirait par soustraction combien de personnes lui sont cachées. Même
 *    règle que la chronologie et la recherche.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { useRouter } from 'vue-router'
import { hauteurDuCadre } from '@/views/cadre'
import { chargerCarte, type GenreEvenement, type VueCarte } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import TiroirOutils from '@/components/mise-en-page/TiroirOutils.vue'
import { basculerGenre, genresParDefaut } from '@/components/evenements/filtres'
import BarreOutilsCarte from '@/components/carte/BarreOutilsCarte.vue'
import LegendeCarte from '@/components/carte/LegendeCarte.vue'
import PanneauLieu from '@/components/carte/PanneauLieu.vue'
import { bornesDesMedianes, maximum, regrouperParLieu, type Bulle } from '@/components/carte/agregation'
import type { ModeCouleur } from '@/components/carte/echelles'

/**
 * **Leaflet n'entre jamais dans le graphe de modules de cette vue.**
 * `leaflet/src/core/Browser.js` lit `document.documentElement.style` à
 * l'évaluation du module ; sous SSR — c'est-à-dire dans tous les tests de rendu
 * du produit — il n'y a pas de `document`. Un composant asynchrone rend son
 * substitut sans évaluer le module, et la coquille charge Leaflet elle-même au
 * montage. C'est une contrainte, pas une préférence.
 */
const CarteLeaflet = defineAsyncComponent(() => import('@/components/carte/CarteLeaflet.vue'))

const routeur = useRouter()

const vue = shallowRef<VueCarte | null>(null)
const erreur = shallowRef<unknown>(null)
const chargement = ref(false)

const actifs = ref<ReadonlySet<GenreEvenement>>(genresParDefaut())
const mode = ref<ModeCouleur>('date')
const fond = ref<'vectoriel' | 'tuiles'>('vectoriel')

const selection = shallowRef<Bulle | null>(null)
const listeNonSitues = ref(false)

const racine = ref<HTMLElement | null>(null)
const carte = ref<InstanceType<typeof CarteLeaflet> | null>(null)
const hauteurCadre = ref('70vh')

let abandon: AbortController | null = null

const regroupement = computed(() => regrouperParLieu(vue.value, actifs.value))
const bulles = computed(() => regroupement.value.bulles)
const maximumBulles = computed(() => maximum(bulles.value))
const bornes = computed(() => bornesDesMedianes(bulles.value))
const vide = computed(() => vue.value !== null && bulles.value.length === 0)

async function charger(): Promise<void> {
  abandon?.abort()
  const controleur = new AbortController()
  abandon = controleur
  chargement.value = true
  erreur.value = null
  try {
    const reponse = await chargerCarte(controleur.signal)
    if (controleur.signal.aborted) return
    vue.value = reponse
  } catch (cause) {
    if (!controleur.signal.aborted) erreur.value = cause
  } finally {
    if (!controleur.signal.aborted) chargement.value = false
  }
}

function surGenre(genre: GenreEvenement): void {
  actifs.value = basculerGenre(actifs.value, genre)
  // La bulle sélectionnée peut avoir disparu du regroupement : la garder
  // afficherait un panneau qui décrit un cercle qu'on ne voit plus.
  const encore = bulles.value.some((b) => b.placeId === selection.value?.placeId)
  if (!encore) selection.value = null
}

function surBulle(bulle: Bulle | null): void {
  selection.value = bulle
  if (bulle !== null) listeNonSitues.value = false
}

function recadrer(): void {
  carte.value?.recadrer()
}

function mesurerHauteur(): void {
  const element = racine.value
  if (element === null) return
  hauteurCadre.value = hauteurDuCadre(element.getBoundingClientRect().top)
  carte.value?.invalider()
}

function surToucheGlobale(ev: KeyboardEvent): void {
  const cible = ev.target
  if (cible instanceof HTMLInputElement || cible instanceof HTMLTextAreaElement) return
  if (ev.key === '0') recadrer()
  else if (ev.key === 'Escape') {
    selection.value = null
    listeNonSitues.value = false
  }
}

async function allerVersPersonne(personId: number): Promise<void> {
  await routeur.push({ name: 'personne.detail', params: { id: personId } })
}

onMounted(() => {
  mesurerHauteur()
  window.addEventListener('resize', mesurerHauteur)
  window.addEventListener('keydown', surToucheGlobale)
  void charger()
})

onBeforeUnmount(() => {
  abandon?.abort()
  window.removeEventListener('resize', mesurerHauteur)
  window.removeEventListener('keydown', surToucheGlobale)
})
</script>

<template>
  <div ref="racine" class="flex flex-col" :style="{ height: hauteurCadre }">
    <TiroirOutils titre="Réglages de la carte">
      <BarreOutilsCarte
        :actifs="actifs"
        :resume="vue?.resume.parGenre ?? null"
        :mode="mode"
        :fond="fond"
        :non-situes="regroupement.nonSitues.length"
        :chargement="chargement"
        @genre="surGenre"
        @mode="(m) => (mode = m)"
        @fond="(f) => (fond = f)"
        @recadrer="recadrer"
        @non-situes="listeNonSitues = !listeNonSitues"
      />
      <template #legende>
        <LegendeCarte
          v-if="vue !== null && !vide"
          :mode="mode"
          :maximum="maximumBulles"
          :bornes="bornes"
          en-tiroir
        />
      </template>
    </TiroirOutils>

    <div v-if="erreur && vue !== null" class="border-b border-ligne px-4 py-2">
      <MessageErreur :erreur="erreur" titre="La carte n’a pas pu être rafraîchie" />
    </div>

    <div class="relative min-h-0 flex-1">
      <SqueletteChargement v-if="vue === null && erreur === null" :lignes="8" />

      <div v-else-if="vue === null" class="p-4">
        <MessageErreur :erreur="erreur" titre="La carte n’a pas pu être chargée" />
      </div>

      <div v-else-if="vide" class="p-4">
        <PanneauBase>
          <p class="text-sm">
            Aucun lieu situé pour les événements retenus.
          </p>
          <p class="mt-1 text-xs text-attenue">
            Choisissez d’autres genres d’événement, ou vérifiez que les lieux de l’arbre ont bien
            été rapprochés du référentiel.
          </p>
        </PanneauBase>
      </div>

      <CarteLeaflet
        v-else
        ref="carte"
        :bulles="bulles"
        :maximum="maximumBulles"
        :bornes="bornes"
        :mode="mode"
        :fond="fond"
        :selection="selection?.placeId ?? null"
        @bulle="surBulle"
      />

      <LegendeCarte
        v-if="vue !== null && !vide"
        :mode="mode"
        :maximum="maximumBulles"
        :bornes="bornes"
      />

      <div
        v-if="selection !== null"
        class="absolute top-3.5 right-3.5 z-[500] w-[min(22rem,calc(100%-1.75rem))]"
      >
        <PanneauLieu
          :bulle="selection"
          @fermer="selection = null"
          @aller="allerVersPersonne"
        />
      </div>

      <div
        v-else-if="listeNonSitues && regroupement.nonSitues.length > 0"
        class="absolute top-3.5 right-3.5 z-[500] max-h-[70%] w-[min(22rem,calc(100%-1.75rem))] overflow-y-auto"
      >
        <PanneauBase ombre>
          <template #entete>
            <div class="flex items-start justify-between gap-3">
              <h2 class="text-sm font-semibold tracking-tight">Lieux non situés</h2>
              <button
                type="button"
                class="cursor-pointer border-0 bg-transparent p-0 text-lg leading-none text-attenue hover:text-texte"
                aria-label="Fermer"
                @click="listeNonSitues = false"
              >
                ×
              </button>
            </div>
          </template>
          <p class="mb-2 text-xs text-attenue">
            Ces événements portent un lieu que le référentiel n’a pas su placer. Ils ne sont pas
            perdus : ils n’ont simplement pas de coordonnées.
          </p>
          <ul class="space-y-1 text-xs">
            <li v-for="evenement in regroupement.nonSitues.slice(0, 40)" :key="evenement.cle">
              <span class="text-attenue">{{ evenement.texte }}</span>
              <span aria-hidden="true"> · </span>{{ evenement.place }}
            </li>
          </ul>
          <p v-if="regroupement.nonSitues.length > 40" class="mt-2 text-xs text-attenue">
            et {{ regroupement.nonSitues.length - 40 }} autres.
          </p>
        </PanneauBase>
      </div>
    </div>
  </div>
</template>
