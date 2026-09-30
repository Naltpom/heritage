<script setup lang="ts">
/**
 * L'en-tête applicatif — refonte de la navigation du 30/09/2026.
 *
 * La navigation tient en **quatre entrées** — Accueil · Arbre · Explorer · Moi —
 * plus la **cloche** des notifications, et **chaque chose n'a qu'une porte
 * d'entrée**. L'en-tête a donc perdu tout ce qui doublait : le tiroir (burger)
 * du téléphone, « Mes demandes », « Validation », « + Proposer », les sections
 * Contribuer et Administration. Les demandes, tirages, actes, questions et
 * l'administration vivent dans Moi ; « Proposer » est l'assistant, ouvert depuis
 * l'arbre et la fiche.
 *
 * **Téléphone (< md), 52 px** : « ‹ parent » sur un écran de détail, le titre de
 * l'écran et son résumé, puis la loupe, la cloche et — si l'écran en déclare —
 * le bouton filtres. La navigation elle-même est en BAS (`BarreNavigation`).
 * La loupe déplie la recherche PAR-DESSUS la barre, « Annuler » la replie.
 *
 * **Bureau (≥ md), 56 px** : la marque, les onglets Accueil · Arbre · Explorer
 * (filet d'accent sous l'actif), la recherche au centre, la cloche, puis
 * l'avatar, dont le menu reprend **le contenu de Moi** — le même composant
 * (`MoiView`, `forme="menu"`), pas une recopie.
 *
 * Ce qui ne change pas, et pourquoi :
 *
 * - **Un anonyme ne reçoit aucun `<button>`** : ni loupe, ni cloche, ni avatar
 *   (`entete-rendu.test.ts`). Son en-tête n'a que la marque.
 * - **Une seule recherche** : le même `RechercheEntete` sert aux deux largeurs.
 *   Son conteneur est `md:contents` — au-delà du seuil il ne génère aucune
 *   boîte ; en deçà, il est masqué tant que la loupe ne l'a pas ouvert.
 * - **Aucun `useRoute()` ici** : les tests simulent `vue-router` avec
 *   `useRouter` seul. La section active, le titre et le parent viennent de
 *   `entete.ts`, que le routeur publie à chaque navigation ; la fermeture des
 *   tiroirs au changement de page est dans `router/index.ts`.
 * - **Pas de piège à focus** : le menu du compte est un *disclosure*
 *   (`aria-expanded` + `aria-controls`), refermé par Échap ou un clic dehors.
 * - La vue empruntée et l'incarnation ne passent pas par ici : leurs bandeaux
 *   et leurs sorties sont montés par `App.vue`, sous l'en-tête.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useSession } from '@/composables/useSession'
import { useTiroirs } from '@/composables/useTiroirs'
import { useEntete, type Section } from '@/composables/entete'
import ClocheNotifications from '@/components/notifications/ClocheNotifications.vue'
import MoiView from '@/views/MoiView.vue'
import RechercheEntete from './RechercheEntete.vue'

const routeur = useRouter()
const { connecte, nomAffiche } = useSession()
const { ouvert, outilsDisponibles, basculerTiroir, fermerTiroirs } = useTiroirs()
const { section, titre, resume, parent, filtresActifs } = useEntete()

const boutonOutils = ref<HTMLButtonElement | null>(null)
const boutonCompte = ref<HTMLButtonElement | null>(null)
const boutonLoupe = ref<HTMLButtonElement | null>(null)
const menuCompte = ref<HTMLElement | null>(null)
const zoneRecherche = ref<HTMLElement | null>(null)

/** Les onglets du bureau. Moi n'en est pas un : c'est l'avatar, à droite. */
const ONGLETS: { section: Section; nom: string; libelle: string }[] = [
  { section: 'accueil', nom: 'accueil', libelle: 'Accueil' },
  { section: 'arbre', nom: 'arbre', libelle: 'Arbre' },
  { section: 'explorer', nom: 'explorer', libelle: 'Explorer' },
]

const initiales = computed(() => {
  const mots = nomAffiche.value.split(/[\s@.]+/).filter(Boolean)
  return ((mots[0]?.[0] ?? '') + (mots[1]?.[0] ?? '')).toUpperCase() || '?'
})

/* ───────────────── La recherche du téléphone ───────────────── */

const rechercheOuverte = ref(false)

async function ouvrirRecherche(): Promise<void> {
  fermerTiroirs()
  rechercheOuverte.value = true
  await nextTick()
  zoneRecherche.value?.querySelector('input')?.focus()
}

function fermerRecherche(rendreLeFocus = true): void {
  if (!rechercheOuverte.value) return
  rechercheOuverte.value = false
  if (rendreLeFocus) void nextTick(() => boutonLoupe.value?.focus())
}

// Changer d'écran replie la recherche, comme il referme les tiroirs.
watch([section, titre, parent], () => fermerRecherche(false))

/* ───────────────── Échap, clic dehors, retour ───────────────── */

/** Le focus revient au bouton qui a ouvert ce qu'on referme. */
function fermerEtRendreLeFocus(): void {
  if (rechercheOuverte.value) {
    fermerRecherche()
    return
  }
  if (ouvert.value === 'aucun') return
  const declencheur = ouvert.value === 'compte' ? boutonCompte.value : boutonOutils.value
  fermerTiroirs()
  declencheur?.focus()
}

/** « ‹ Arbre » : l'arbre reprend la vue quittée ; ailleurs, on revient d'où l'on vient. */
function retour(): void {
  const p = parent.value
  if (!p) return
  const precedent = (globalThis.history?.state as { back?: unknown } | null)?.back
  if (p.nom !== 'arbre' && typeof precedent === 'string') routeur.back()
  else void routeur.push({ name: p.nom })
}

function surClicExterieur(e: MouseEvent): void {
  if (ouvert.value !== 'compte') return
  const cible = e.target as Node
  if (menuCompte.value?.contains(cible) || boutonCompte.value?.contains(cible)) return
  fermerTiroirs()
}

onMounted(() => document.addEventListener('click', surClicExterieur, true))
onBeforeUnmount(() => document.removeEventListener('click', surClicExterieur, true))

const classeOnglet = (actif: boolean): string =>
  [
    'inline-flex h-14 items-center border-b-2 px-3 text-sm whitespace-nowrap',
    actif ? 'border-accent font-semibold text-accent' : 'border-transparent text-attenue hover:text-texte',
  ].join(' ')

const classeBoutonIcone =
  'flex h-11 w-11 flex-none items-center justify-center rounded-champ text-attenue hover:text-texte'
</script>

<template>
  <header
    class="relative flex h-14 items-center gap-1 border-b border-ligne bg-panneau px-5 max-md:h-[52px] max-md:px-1"
    @keydown.escape="fermerEtRendreLeFocus"
  >
    <!-- Téléphone : le retour d'un écran de détail. -->
    <button
      v-if="connecte && parent"
      type="button"
      class="flex h-11 items-center gap-1 px-2 text-[15px] font-semibold whitespace-nowrap text-accent md:hidden"
      @click="retour"
    >
      <span aria-hidden="true">‹</span> {{ parent.libelle }}
    </button>

    <!-- La marque n'est pas un lien : l'onglet Accueil, juste à côté, en est un. -->
    <span
      class="mr-[18px] text-base font-bold tracking-[-.01em] whitespace-nowrap"
      :class="connecte ? 'max-md:hidden' : 'max-md:px-3'"
    >
      Heritage
    </span>

    <template v-if="connecte">
      <!-- Téléphone : le titre de l'écran et son résumé. -->
      <div class="titre-ecran flex min-w-0 flex-1 flex-col leading-tight md:hidden" :class="parent ? '' : 'pl-3'">
        <span class="truncate text-base font-semibold">{{ titre }}</span>
        <span v-if="resume" class="truncate text-[11.5px] text-attenue">{{ resume }}</span>
      </div>

      <!-- Bureau : les onglets. Sur téléphone, c'est la barre du bas. -->
      <nav aria-label="Navigation principale" class="flex h-14 items-stretch max-md:hidden">
        <RouterLink
          v-for="o in ONGLETS"
          :key="o.nom"
          :to="{ name: o.nom }"
          :class="classeOnglet(section === o.section)"
          :aria-current="section === o.section ? 'page' : undefined"
        >
          {{ o.libelle }}
        </RouterLink>
      </nav>

      <!-- La recherche, une seule fois. Bureau : `md:contents`, ses enfants sont
           ceux de la barre. Téléphone : masquée, ou dépliée par-dessus la barre. -->
      <div
        id="recherche-entete"
        ref="zoneRecherche"
        class="md:contents"
        :class="
          rechercheOuverte
            ? 'max-md:absolute max-md:inset-0 max-md:z-20 max-md:flex max-md:items-center max-md:gap-1 max-md:bg-panneau max-md:pr-1 max-md:pl-2'
            : 'max-md:hidden'
        "
      >
        <span class="flex-1 max-md:hidden" aria-hidden="true" />
        <RechercheEntete class="max-md:w-auto max-md:flex-1" />
        <span class="flex-1 max-md:hidden" aria-hidden="true" />
        <button type="button" class="h-11 flex-none px-2 text-[15px] text-accent md:hidden" @click="fermerRecherche()">
          Annuler
        </button>
      </div>

      <button
        ref="boutonLoupe"
        type="button"
        :class="classeBoutonIcone"
        class="md:hidden"
        aria-label="Rechercher une personne"
        :aria-expanded="rechercheOuverte"
        aria-controls="recherche-entete"
        @click="ouvrirRecherche"
      >
        <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
          <circle cx="8.5" cy="8.5" r="5.5" fill="none" stroke="currentColor" stroke-width="1.7" />
          <path d="M13 13l4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
        </svg>
      </button>

      <ClocheNotifications />

      <!-- Téléphone : le bouton filtres, au bout de la barre. -->
      <button
        v-if="outilsDisponibles"
        ref="boutonOutils"
        type="button"
        class="relative md:hidden"
        :class="[classeBoutonIcone, ouvert === 'outils' ? 'bg-accent-doux text-accent' : '']"
        :aria-label="ouvert === 'outils' ? 'Fermer les filtres' : 'Ouvrir les filtres'"
        :aria-expanded="ouvert === 'outils'"
        aria-controls="tiroir-outils"
        @click="basculerTiroir('outils')"
      >
        <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
          <path d="M3 5h14M6 10h8M9 15h2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
        </svg>
        <span
          v-if="filtresActifs > 0"
          class="absolute top-1.5 right-1.5 flex h-4 min-w-4 items-center justify-center rounded-pastille bg-accent px-1 text-[10px] font-bold text-fond"
        >{{ filtresActifs }}</span>
      </button>

      <!-- Bureau : l'avatar, dont le menu est Moi. -->
      <div class="relative max-md:hidden">
        <button
          ref="boutonCompte"
          type="button"
          class="ml-1 flex h-[34px] items-center gap-1 rounded-pastille pr-1.5 pl-0.5 hover:bg-accent-doux"
          :class="section === 'moi' ? 'bg-accent-doux' : ''"
          :aria-expanded="ouvert === 'compte'"
          aria-controls="menu-compte"
          aria-label="Moi"
          @click="basculerTiroir('compte')"
        >
          <span class="flex h-8 w-8 items-center justify-center rounded-full bg-accent-doux text-xs font-bold text-accent">{{ initiales }}</span>
          <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" class="text-attenue">
            <path d="M3 4.5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
          </svg>
        </button>
        <div
          v-if="ouvert === 'compte'"
          id="menu-compte"
          ref="menuCompte"
          class="absolute top-[calc(100%+10px)] right-0 z-50 w-[280px] rounded-panneau border border-ligne bg-panneau shadow-panneau"
        >
          <MoiView forme="menu" />
        </div>
      </div>
    </template>
  </header>
</template>
