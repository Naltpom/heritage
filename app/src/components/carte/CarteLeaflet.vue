<script setup lang="ts">
/**
 * La carte : Leaflet, piloté impérativement.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE COMPOSANT N'EST JAMAIS TESTÉ UNITAIREMENT, ET C'EST VOULU.** Tout ce
 * qui se décide — le regroupement, les deux échelles, les options — vit dans
 * `agregation.ts`, `echelles.ts` et `options-leaflet.ts`, qui sont purs et
 * couverts. Il ne reste ici que du dessin.
 *
 * **Leaflet ne s'importe qu'à l'exécution, dans `onMounted`.**
 * `leaflet/src/core/Browser.js` lit `document.documentElement.style` **à
 * l'évaluation du module**. L'environnement de vitest est `node`, sans
 * `document` : un `import 'leaflet'` statique, atteignable depuis une vue,
 * ferait tomber **toutes** les suites de rendu SSR du produit dès que quelqu'un
 * importerait cette vue. La vue monte donc ce composant en
 * `defineAsyncComponent`, et ce composant charge Leaflet en `await import`.
 * Deux gardes plutôt qu'une, parce que la panne serait générale.
 *
 * **L'instance `Map` est une variable ordinaire, jamais une `ref`.** Au-delà de
 * l'ADR 0007, une `ref()` la proxifie en profondeur : ses `_layers`, ses
 * `_events` et ses identifiants internes passent par un proxy, et les contrôles
 * d'identité de Leaflet — `layer._map === map` — se mettent à échouer. Le
 * symptôme est un calque qui ne se retire plus.
 *
 * **Le jeton `vivant`.** Le montage attend deux fois : l'import de Leaflet, puis
 * le `fetch` du fond. Un changement de route pendant l'une ou l'autre démonte le
 * composant, et la suite du montage s'exécuterait alors sur un élément détaché,
 * en construisant une carte que personne ne détruira. C'est le défaut qui
 * n'apparaît qu'au clic rapide.
 *
 * **`ResizeObserver`, et non `window.resize`.** Le tiroir des filtres du mobile
 * change la largeur du conteneur sans qu'aucun événement de fenêtre ne parte :
 * Leaflet dessinerait dans une taille périmée, avec une moitié de carte grise.
 *
 * **Le fond détaillé ne part pas tout seul.** La couche de tuiles n'est
 * construite que dans la branche `tuiles` ; tant que personne n'a appuyé,
 * l'URL ne quitte jamais ce fichier. La CSP l'autorise, ce qui n'est pas la
 * même chose que de l'utiliser.
 * ────────────────────────────────────────────────────────────────────
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { GeoJSON, Layer, Map as CarteLeaflet, TileLayer } from 'leaflet'
import urlMonde from '@/assets/monde-110m.geo.json?url'
import 'leaflet/dist/leaflet.css'
import type { Bulle } from './agregation'
import { cadrageInitial } from './agregation'
import { teinte, type ModeCouleur } from './echelles'
import { GABARIT_TUILES, MENTION_TUILES, optionsBulle, optionsPays } from './options-leaflet'

const { bulles, maximum, bornes, mode, fond, selection } = defineProps<{
  bulles: readonly Bulle[]
  maximum: number
  bornes: { min: number; max: number } | null
  mode: ModeCouleur
  fond: 'vectoriel' | 'tuiles'
  selection: number | null
}>()

const emit = defineEmits<{
  /** Une bulle a été cliquée, ou le fond (`null`). */
  bulle: [bulle: Bulle | null]
}>()

const cadreRef = ref<HTMLDivElement | null>(null)

let vivant = true
let carte: CarteLeaflet | null = null
let L: typeof import('leaflet') | null = null
let coucheVecteur: GeoJSON | null = null
let coucheTuiles: TileLayer | null = null
let coucheBulles: Layer | null = null
let observateur: ResizeObserver | null = null
let abandon: AbortController | null = null

/** L'erreur de chargement du fond, remontée à l'écran plutôt que tue. */
const erreurFond = ref(false)

async function construire(): Promise<void> {
  const element = cadreRef.value
  if (element === null) return

  L = await import('leaflet')
  if (!vivant) return

  carte = L.map(element, {
    zoomControl: true,
    attributionControl: true,
    worldCopyJump: true,
    // Le fond n'a pas de détail au-delà du pays ; laisser zoomer au 19 donnerait
    // un écran vide sur lequel personne ne comprendrait quoi faire. La bascule
    // « fond détaillé » relève ce plafond, puisqu'elle apporte le détail.
    minZoom: 2,
    maxZoom: 8,
  })
  carte.setView([46.6, 2.4], 5)
  carte.createPane('fond')
  const pane = carte.getPane('fond')
  if (pane !== undefined) pane.style.zIndex = '200'

  carte.on('click', () => emit('bulle', null))

  observateur = new ResizeObserver(() => carte?.invalidateSize())
  observateur.observe(element)

  await poserLeFond()
  if (!vivant) return
  dessinerLesBulles()
  recadrer()
}

async function poserLeFond(): Promise<void> {
  if (carte === null || L === null) return

  if (fond === 'tuiles') {
    coucheVecteur?.remove()
    coucheVecteur = null
    coucheTuiles ??= L.tileLayer(GABARIT_TUILES, {
      pane: 'fond',
      maxZoom: 19,
      attribution: MENTION_TUILES,
    })
    coucheTuiles.addTo(carte)
    carte.setMaxZoom(19)
    return
  }

  // Retirer la couche NE SUFFIT PAS : sans annuler la référence, Leaflet
  // continue de précharger l'anneau tampon de tuiles, donc de parler au serveur
  // distant alors que le lecteur a rebasculé sur le fond sobre.
  coucheTuiles?.remove()
  coucheTuiles = null
  carte.setMaxZoom(8)
  if (carte.getZoom() > 8) carte.setZoom(8)

  if (coucheVecteur !== null) {
    coucheVecteur.addTo(carte)
    return
  }

  abandon?.abort()
  abandon = new AbortController()
  try {
    const reponse = await fetch(urlMonde, { signal: abandon.signal })
    const donnees = (await reponse.json()) as Parameters<typeof L.geoJSON>[0]
    if (!vivant || carte === null) return
    coucheVecteur = L.geoJSON(donnees, { ...optionsPays(), pane: 'fond' })
    coucheVecteur.addTo(carte)
    erreurFond.value = false
  } catch (cause) {
    if (abandon?.signal.aborted !== true) erreurFond.value = true
    void cause
  }
}

function dessinerLesBulles(): void {
  if (carte === null || L === null) return

  coucheBulles?.remove()
  const groupe = L.layerGroup()

  // `bulles` arrive déjà trié par total décroissant : les grandes au fond, les
  // petites au-dessus, et c'est ce qui rend un village à deux événements
  // cliquable par-dessus une ville à quarante.
  for (const bulle of bulles) {
    const couleur = teinte(mode, bulle, bornes, maximum)
    const cercle = L.circleMarker(
      [bulle.lat, bulle.lon],
      optionsBulle(bulle.total, maximum, couleur, bulle.placeId === selection),
    )
    cercle.on('click', (ev) => {
      L?.DomEvent.stop(ev)
      emit('bulle', bulle)
    })
    cercle.bindTooltip(`${bulle.libelle} — ${bulle.total}`, { direction: 'top' })
    cercle.addTo(groupe)
  }

  groupe.addTo(carte)
  coucheBulles = groupe
}

function recadrer(): void {
  if (carte === null) return
  const boite = cadrageInitial(bulles)
  if (boite === null) {
    carte.setView([46.6, 2.4], 5)
    return
  }
  carte.fitBounds(boite, { padding: [40, 40], maxZoom: 8 })
}

function invalider(): void {
  carte?.invalidateSize()
}

watch(
  () => [bulles, mode, maximum, bornes, selection],
  () => dessinerLesBulles(),
)

watch(
  () => fond,
  () => {
    void poserLeFond()
  },
)

onMounted(() => {
  void construire()
})

onBeforeUnmount(() => {
  vivant = false
  abandon?.abort()
  observateur?.disconnect()
  observateur = null
  carte?.remove()
  carte = null
  coucheVecteur = null
  coucheTuiles = null
  coucheBulles = null
})

defineExpose({ recadrer, invalider })
</script>

<template>
  <div class="relative h-full w-full">
    <div ref="cadreRef" class="carte h-full w-full" role="application" aria-label="Carte des lieux" />
    <p
      v-if="erreurFond"
      class="absolute inset-x-3 top-3 z-[500] rounded-champ border border-alerte-bord bg-alerte-doux px-3 py-1.5 text-xs text-texte"
    >
      Le fond de carte n’a pas pu être chargé. Les bulles restent placées ; vous pouvez essayer le
      fond détaillé.
    </p>
  </div>
</template>
