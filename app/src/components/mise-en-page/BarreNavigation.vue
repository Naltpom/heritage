<script setup lang="ts">
/**
 * La barre de navigation du bas, sur téléphone — façon Instagram (refonte du
 * 30/09/2026 : les lecteurs sont d'abord sur téléphone).
 *
 * **Quatre entrées, pas une de plus** : Accueil · Arbre · Explorer · Moi. Tout
 * le reste a UNE porte d'entrée ailleurs : les demandes, événements, tirages, actes,
 * questions et l'administration sont dans Moi ; « Proposer » est l'assistant,
 * ouvert depuis l'accueil et la fiche ; les notifications sont à la cloche de
 * l'en-tête. Une icône et un petit libellé par entrée ; l'entrée active est
 * marquée par la couleur d'accent et `aria-current="page"`. Moi montre les
 * photo de sa fiche (ou une silhouette), et une pastille quand la file de validation attend un
 * administrateur.
 *
 * **Un compte en attente n'en voit que deux** : Inscription · Moi (ADR 0003,
 * révision du 01/10/2026). Il ne lit plus rien de l'arbre avant sa validation :
 * Accueil, Arbre et Explorer ne le mèneraient qu'à un refus, et la garde le
 * renverrait aussitôt à l'écran d'attente. « Inscription » est cet écran — sa
 * page à lui, d'où l'icône de la maison et la section `accueil` qui l'allume
 * (`sectionDe`). La barre se règle sur le régime, pas sur le rôle : la
 * validation venue, la session relue rend les quatre entrées.
 *
 * Ce qui est moins visible, et pourquoi :
 *
 * - **Cachée au-delà de `md`** (`md:hidden`) : sur bureau, les onglets de
 *   l'en-tête et le menu de l'avatar font ce travail.
 * - **Absente pour un anonyme** et sur les écrans sans cadre : `App.vue` la
 *   monte dans le même bloc `v-if="avecCadre"` que l'en-tête.
 * - **`env(safe-area-inset-bottom)`** : sur un iPhone sans bouton, la barre
 *   s'arrête au-dessus du trait d'accueil au lieu de passer dessous.
 * - **Elle publie sa hauteur** dans `--hauteur-barre-bas`, sur `<html>`. Le
 *   `<main>` s'en sert comme marge basse, et `hauteurDuCadre()` la retranche :
 *   l'arbre, la carte et la chronologie ne passent pas dessous. La mesure vient
 *   d'un `ResizeObserver` sur la barre elle-même : `display: none` au-delà du
 *   seuil rend 0, et l'encoche d'un iPhone est comprise. Rien n'est écrit en
 *   dur ; au démontage, la variable est retirée.
 * - **Effacée sous le feuillet des filtres** (`invisible` tant que le tiroir
 *   `outils` est ouvert) : le feuillet de `BarreEcran` vit dans le contexte
 *   d'empilement `z-20` de sa barre, sous cette barre-ci (`z-30`) ; sans cela
 *   le bas du feuillet et son voile passeraient sous la navigation.
 *   `visibility` et non `v-if` : la hauteur publiée ne bouge pas, et la carte
 *   ou l'arbre ne se redimensionnent pas à chaque ouverture.
 * - **Aucun `useRoute()`** : comme l'en-tête, la barre lit la section active
 *   dans `entete.ts`, que le routeur publie à chaque navigation.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AvatarCompte from '@/components/mise-en-page/AvatarCompte.vue'
import { useSession } from '@/composables/useSession'
import { useEntete, type Section } from '@/composables/entete'
import { useTiroirs } from '@/composables/useTiroirs'
import { listerDemandesAdministration } from '@/lib/api'

const { connecte, rattache, administrateur } = useSession()
const { section } = useEntete()
const { ouvert } = useTiroirs()

const barre = ref<HTMLElement | null>(null)

type Entree = { section: Exclude<Section, 'autre'>; nom: string; libelle: string }
const ENTREES: Entree[] = [
  { section: 'accueil', nom: 'accueil', libelle: 'Accueil' },
  { section: 'arbre', nom: 'arbre', libelle: 'Arbre' },
  { section: 'explorer', nom: 'explorer', libelle: 'Explorer' },
  { section: 'moi', nom: 'moi', libelle: 'Moi' },
]

/** Un compte en attente : son inscription et Moi, rien qui lise l'arbre. */
const ENTREES_EN_ATTENTE: Entree[] = [
  { section: 'accueil', nom: 'inscription.etat', libelle: 'Inscription' },
  { section: 'moi', nom: 'moi', libelle: 'Moi' },
]

const entrees = computed(() => (rattache.value ? ENTREES : ENTREES_EN_ATTENTE))


/** La file de validation, pour la pastille de Moi. Même requête que Moi :
 *  une ligne, le total seul. */
const fileEnAttente = ref(0)
async function compterLaFile(): Promise<void> {
  if (!administrateur.value) {
    fileEnAttente.value = 0
    return
  }
  try {
    fileEnAttente.value = (await listerDemandesAdministration({ status: 'en_attente', parPage: 1 })).total
  } catch {
    fileEnAttente.value = 0
  }
}
watch(administrateur, () => void compterLaFile())

/* ───────────────── La hauteur publiée ───────────────── */

const VARIABLE = '--hauteur-barre-bas'
let observateur: ResizeObserver | null = null

function publierHauteur(): void {
  const h = barre.value?.offsetHeight ?? 0
  document.documentElement.style.setProperty(VARIABLE, `${h}px`)
}

function brancher(element: HTMLElement | null): void {
  observateur?.disconnect()
  observateur = null
  publierHauteur()
  if (element && typeof ResizeObserver === 'function') {
    observateur = new ResizeObserver(publierHauteur)
    observateur.observe(element)
  }
}

onMounted(() => {
  void compterLaFile()
  brancher(barre.value)
})
// La barre peut naître ou disparaître après le montage (connexion,
// déconnexion) : on observe l'élément tel qu'il est, et on remesure.
watch(barre, brancher, { flush: 'post' })
onBeforeUnmount(() => {
  observateur?.disconnect()
  document.documentElement.style.removeProperty(VARIABLE)
})
</script>

<template>
  <nav
    v-if="connecte"
    ref="barre"
    aria-label="Navigation principale"
    :class="{ invisible: ouvert === 'outils' }"
    class="barre-navigation fixed inset-x-0 bottom-0 z-30 border-t border-ligne bg-panneau pb-[env(safe-area-inset-bottom)] md:hidden"
  >
    <ul class="m-0 grid h-14 list-none p-0" :class="entrees.length === 4 ? 'grid-cols-4' : 'grid-cols-2'">
      <li v-for="e in entrees" :key="e.nom" class="flex">
        <RouterLink
          :to="{ name: e.nom }"
          class="flex flex-1 flex-col items-center justify-center gap-0.5 text-[11px] leading-none"
          :class="section === e.section ? 'font-semibold text-accent' : 'text-attenue'"
          :aria-current="section === e.section ? 'page' : undefined"
        >
          <span class="relative flex h-7 w-7 items-center justify-center" aria-hidden="true">
            <svg v-if="e.section === 'accueil'" width="24" height="24" viewBox="0 0 24 24">
              <path
                d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4.5v-6h-5v6H5a1 1 0 0 1-1-1z"
                :fill="section === e.section ? 'currentColor' : 'none'"
                :fill-opacity="section === e.section ? 0.15 : 0"
                stroke="currentColor"
                :stroke-width="section === e.section ? 2 : 1.7"
                stroke-linejoin="round"
              />
            </svg>
            <svg v-else-if="e.section === 'arbre'" width="24" height="24" viewBox="0 0 24 24">
              <g fill="none" stroke="currentColor" :stroke-width="section === e.section ? 2 : 1.7" stroke-linecap="round" stroke-linejoin="round">
                <rect x="9" y="3" width="6" height="5" rx="1.2" />
                <rect x="3" y="16" width="6" height="5" rx="1.2" />
                <rect x="15" y="16" width="6" height="5" rx="1.2" />
                <path d="M12 8v4M6 16v-4h12v4" />
              </g>
            </svg>
            <svg v-else-if="e.section === 'explorer'" width="24" height="24" viewBox="0 0 24 24">
              <circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" :stroke-width="section === e.section ? 2 : 1.7" />
              <path
                d="m15.5 8.5-2 5-5 2 2-5z"
                :fill="section === e.section ? 'currentColor' : 'none'"
                stroke="currentColor"
                :stroke-width="section === e.section ? 2 : 1.7"
                stroke-linejoin="round"
              />
            </svg>
            <AvatarCompte v-else :taille="26" :actif="section === e.section" />
            <span
              v-if="e.section === 'moi' && fileEnAttente > 0"
              class="pastille-moi absolute -top-1 -right-2 flex h-4 min-w-4 items-center justify-center rounded-pastille bg-accent px-1 text-[10px] font-bold text-fond tnum ring-2 ring-panneau"
            >{{ fileEnAttente }}</span>
          </span>
          <span>{{ e.libelle }}</span>
        </RouterLink>
      </li>
    </ul>
  </nav>
</template>
