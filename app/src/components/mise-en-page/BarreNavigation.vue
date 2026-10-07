<script setup lang="ts">
/**
 * La barre de navigation du bas, sur téléphone — façon Instagram (refonte du
 * 30/09/2026 : les lecteurs sont d'abord sur téléphone).
 *
 * **Quatre entrées, pas une de plus** : Accueil · Arbre · Explorer · Moi. Tout
 * le reste a UNE porte d'entrée ailleurs : les demandes, événements (tirages compris), actes,
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
 *
 * **Le mode administration** (handoff « mode Administration », 1c). Un
 * administrateur a un 5ᵉ emplacement, après un séparateur : l'**interrupteur**
 * Membre / Admin (`InterrupteurMode`). En mode admin — toute route
 * `administration.*` —, les quatre entrées deviennent À traiter ·
 * Utilisateurs · Invitations · Sources ; « À traiter » porte en pastille le
 * nombre de ce qui attend. Les autres comptes gardent leur barre telle quelle :
 * ni 5ᵉ emplacement, ni séparateur.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AvatarCompte from '@/components/mise-en-page/AvatarCompte.vue'
import { useSession } from '@/composables/useSession'
import { useEntete, type Section } from '@/composables/entete'
import { useTiroirs } from '@/composables/useTiroirs'
import { compterATraiter, entreeAdminActive, useModeAdmin } from '@/composables/modeAdmin'
import InterrupteurMode from './InterrupteurMode.vue'

const { connecte, rattache, administrateur } = useSession()
const { section } = useEntete()
const { ouvert } = useTiroirs()
const { estAdmin, aTraiter } = useModeAdmin()

const barre = ref<HTMLElement | null>(null)

type Entree = { section: Exclude<Section, 'autre'>; nom: string; libelle: string; icone?: IconeAdmin }
type IconeAdmin = 'file' | 'utilisateurs' | 'invitations' | 'sources'
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

/** Le mode administration : les quatre écrans de l'administration. L'entrée
 *  active se lit au nom de la route ; toutes partagent la section `administration`. */
const ENTREES_ADMIN: Entree[] = [
  { section: 'administration', nom: 'administration.a-traiter', libelle: 'À traiter', icone: 'file' },
  { section: 'administration', nom: 'administration.utilisateurs', libelle: 'Utilisateurs', icone: 'utilisateurs' },
  { section: 'administration', nom: 'administration.invitations', libelle: 'Invitations', icone: 'invitations' },
  { section: 'administration', nom: 'administration.sources', libelle: 'Sources', icone: 'sources' },
]

/** Le mode admin n'est montré qu'à un administrateur : un lien profond vers
 *  l'administration ouvert par quelqu'un d'autre finit de toute façon refusé. */
const modeAdmin = computed(() => administrateur.value && estAdmin.value)

const entrees = computed(() =>
  modeAdmin.value ? ENTREES_ADMIN : rattache.value ? ENTREES : ENTREES_EN_ATTENTE,
)

/** Les chemins des icônes de l'administration (viewBox 24, trait 1,7). */
const ICONES_ADMIN: Record<IconeAdmin, string> = {
  file: 'M3 13l3-8h12l3 8v6H3zM3 13h5l1 2h6l1-2h5',
  utilisateurs:
    'M9 11a3.5 3.5 0 1 0 0-7a3.5 3.5 0 1 0 0 7zM2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 6.5M18 14.5c2 .6 3.2 2.5 3.5 5.5',
  invitations:
    'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1',
  sources: 'M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2zM4 19V5M8 7h7',
}

const { nomDeRoute } = useEntete()
/** L'entrée allumée. En mode admin, celle de la route courante ; l'historique
 *  des demandes allume « À traiter », d'où l'on y va. */
function active(e: Entree): boolean {
  if (e.section !== 'administration') return section.value === e.section
  return modeAdmin.value && entreeAdminActive(nomDeRoute.value) === e.nom
}

/** Le compte de ce qui attend, pour la pastille de Moi (mode membre) et
 *  d'« À traiter » (mode admin). Rien n'est compté pour un non-administrateur. */
const pastille = computed(() => (administrateur.value && aTraiter.value !== null ? aTraiter.value : 0))
function compterLaFile(): void {
  if (administrateur.value) void compterATraiter()
}
watch(administrateur, compterLaFile)

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
  compterLaFile()
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
    <div class="flex items-stretch" :class="administrateur ? 'px-1.5 py-1.5' : ''">
      <ul
        class="m-0 grid min-w-0 list-none p-0"
        :class="[entrees.length === 2 ? 'grid-cols-2' : 'grid-cols-4', administrateur ? 'min-h-[52px] flex-[4]' : 'h-14 flex-1']"
      >
        <li v-for="e in entrees" :key="e.nom" class="flex">
          <RouterLink
            :to="{ name: e.nom }"
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 text-[11px] leading-none"
            :class="active(e) ? (e.icone ? 'font-bold text-accent' : 'font-semibold text-accent') : e.icone ? 'font-medium text-attenue' : 'text-attenue'"
            :aria-current="active(e) ? 'page' : undefined"
          >
            <span class="relative flex h-7 w-7 items-center justify-center" aria-hidden="true">
              <svg v-if="e.icone" width="24" height="24" viewBox="0 0 24 24">
                <path
                  :d="ICONES_ADMIN[e.icone]"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.7"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
              <svg v-else-if="e.section === 'accueil'" width="24" height="24" viewBox="0 0 24 24">
                <path
                  d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4.5v-6h-5v6H5a1 1 0 0 1-1-1z"
                  :fill="active(e) ? 'currentColor' : 'none'"
                  :fill-opacity="active(e) ? 0.15 : 0"
                  stroke="currentColor"
                  :stroke-width="active(e) ? 2 : 1.7"
                  stroke-linejoin="round"
                />
              </svg>
              <svg v-else-if="e.section === 'arbre'" width="24" height="24" viewBox="0 0 24 24">
                <g fill="none" stroke="currentColor" :stroke-width="active(e) ? 2 : 1.7" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="9" y="3" width="6" height="5" rx="1.2" />
                  <rect x="3" y="16" width="6" height="5" rx="1.2" />
                  <rect x="15" y="16" width="6" height="5" rx="1.2" />
                  <path d="M12 8v4M6 16v-4h12v4" />
                </g>
              </svg>
              <svg v-else-if="e.section === 'explorer'" width="24" height="24" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" :stroke-width="active(e) ? 2 : 1.7" />
                <path
                  d="m15.5 8.5-2 5-5 2 2-5z"
                  :fill="active(e) ? 'currentColor' : 'none'"
                  stroke="currentColor"
                  :stroke-width="active(e) ? 2 : 1.7"
                  stroke-linejoin="round"
                />
              </svg>
              <AvatarCompte v-else :taille="26" :actif="active(e)" />
              <span
                v-if="e.section === 'moi' && pastille > 0"
                class="pastille-moi absolute -top-1 -right-2 flex h-4 min-w-4 items-center justify-center rounded-pastille bg-accent px-1 text-[10px] font-bold text-fond tnum ring-2 ring-panneau"
              >{{ pastille }}</span>
              <span
                v-if="e.icone === 'file' && pastille > 0"
                class="pastille-a-traiter absolute top-0.5 left-[calc(50%+6px)] flex h-[18px] min-w-[18px] items-center justify-center rounded-pastille bg-alerte px-[5px] text-[10.5px] font-bold text-fond tnum"
              >{{ pastille }}</span>
            </span>
            <span class="max-w-full truncate px-0.5">{{ e.libelle }}</span>
          </RouterLink>
        </li>
      </ul>

      <!-- L'interrupteur, aux seuls administrateurs, après un filet vertical. -->
      <template v-if="administrateur">
        <span class="mx-0.5 my-2 w-px flex-none bg-ligne" aria-hidden="true" />
        <div class="flex min-w-0 flex-1">
          <InterrupteurMode forme="barre" />
        </div>
      </template>
    </div>
  </nav>
</template>
