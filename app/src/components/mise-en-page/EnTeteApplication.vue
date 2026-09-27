<script setup lang="ts">
/**
 * L'en-tête applicatif — refonte « En-tête, barres d'écran et fiche » (4a/4b).
 *
 * **Bureau (≥ md), une seule barre de 56 px** : la marque, les quatre onglets
 * d'exploration (Arbre · Chronologie · Carte · Anniversaires, filet d'accent sous
 * l'actif), la recherche d'une personne au centre, puis Mes demandes, Validation,
 * « + Proposer » et le menu du compte (avatar).
 *
 * **Téléphone (< md), 52 px** : le burger — ou « ‹ parent » sur un écran de
 * détail —, le titre de l'écran et son résumé, et le bouton filtres, qui porte le
 * nombre de filtres actifs. Le burger ouvre un panneau latéral (86 %) : carte du
 * compte, « Proposer une personne », sections Explorer, Contribuer,
 * Administration, et « Se déconnecter » en pied.
 *
 * Ce qui ne change pas, et pourquoi :
 *
 * - **La navigation suit le régime**, pas le rôle : un compte en attente voit
 *   l'arbre et la chronologie, pas la contribution ; l'administration n'apparaît
 *   qu'à `ROLE_ADMIN`. Masquer une entrée n'est pas une mesure de sécurité — les
 *   gardes de route la doublent, et le serveur les double toutes les deux.
 * - **La navigation n'est rendue qu'une fois.** `#menu-principal` porte
 *   `md:contents` : au-delà du seuil il ne génère aucune boîte, et ses liens
 *   deviennent les onglets de la barre ; en deçà, c'est le panneau latéral. Le
 *   tiroir DÉPLACE, il ne recopie pas.
 * - **Le burger n'existe que si `connecte`** : un visiteur anonyme ne reçoit
 *   aucun `<button>` (`entete-creation.test.ts`).
 * - **Aucun `useRoute()` ici** : les tests simulent `vue-router` avec `useRouter`
 *   seul. La section active, le titre et le parent viennent de `entete.ts`, que
 *   le routeur publie à chaque navigation ; la fermeture des tiroirs au
 *   changement de page est dans `router/index.ts`.
 * - **Pas de piège à focus** : ce sont des *disclosures* (`aria-expanded` +
 *   `aria-controls`). Fermé, le panneau est `max-md:hidden` — hors mise en page,
 *   clavier et arbre d'accessibilité, borné au point de rupture. `inert` n'aurait
 *   pas de variante de largeur.
 * - `PointCreation` garde ses deux conditions d'affichage (rattachement validé,
 *   hors bascule « voir comme »).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { deconnecter, useSession } from '@/composables/useSession'
import { useTiroirs } from '@/composables/useTiroirs'
import { useEntete, type Section } from '@/composables/entete'
import { listerDemandesAdministration } from '@/lib/api'
import PointCreation from '@/components/proposition/PointCreation.vue'
import RechercheEntete from './RechercheEntete.vue'

const routeur = useRouter()
const etatSession = useSession()
const { regime, connecte, rattache, administrateur, nomAffiche } = etatSession
const { ouvert, outilsDisponibles, basculerTiroir, fermerTiroirs } = useTiroirs()
const { section, titre, resume, parent, filtresActifs } = useEntete()

const boutonMenu = ref<HTMLButtonElement | null>(null)
const boutonOutils = ref<HTMLButtonElement | null>(null)
const boutonCompte = ref<HTMLButtonElement | null>(null)
const menuCompte = ref<HTMLElement | null>(null)

const ONGLETS: { section: Section; nom: string; libelle: string }[] = [
  { section: 'arbre', nom: 'arbre', libelle: 'Arbre' },
  { section: 'chronologie', nom: 'chronologie', libelle: 'Chronologie' },
  { section: 'carte', nom: 'carte', libelle: 'Carte' },
  { section: 'anniversaires', nom: 'anniversaires', libelle: 'Anniversaires' },
]

const demandesEnAttente = computed(() => etatSession.session?.value?.demandesEnAttente?.length ?? 0)
const fileEnAttente = ref(0)

const libelleRole = computed(() =>
  administrateur.value ? 'Administrateur' : regime.value === 'en_attente' ? 'En attente de validation' : 'Membre',
)
const courriel = computed(() => etatSession.session?.value?.utilisateur.email ?? '')
const initiales = computed(() => {
  const mots = nomAffiche.value.split(/[\s@.]+/).filter(Boolean)
  return ((mots[0]?.[0] ?? '') + (mots[1]?.[0] ?? '')).toUpperCase() || '?'
})

/** Le focus revient au bouton qui a ouvert ce qu'on referme. */
function fermerEtRendreLeFocus(): void {
  const declencheur =
    ouvert.value === 'menu' ? boutonMenu.value : ouvert.value === 'compte' ? boutonCompte.value : boutonOutils.value
  if (ouvert.value === 'aucun') return
  fermerTiroirs()
  declencheur?.focus()
}

async function quitter(): Promise<void> {
  fermerTiroirs()
  await deconnecter()
  await routeur.push({ name: 'connexion' })
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

async function compterLaFile(): Promise<void> {
  if (!administrateur.value) return
  try {
    fileEnAttente.value = (await listerDemandesAdministration({ status: 'en_attente', parPage: 1 })).total
  } catch {
    fileEnAttente.value = 0
  }
}

watch(administrateur, () => void compterLaFile())
onMounted(() => {
  document.addEventListener('click', surClicExterieur, true)
  void compterLaFile()
})
onBeforeUnmount(() => document.removeEventListener('click', surClicExterieur, true))

const classeOnglet = (actif: boolean): string =>
  [
    'inline-flex items-center whitespace-nowrap',
    'md:h-14 md:border-b-2 md:px-3 md:text-sm',
    'max-md:h-11 max-md:rounded-champ max-md:px-3 max-md:text-[15px]',
    actif
      ? 'md:border-accent md:font-semibold md:text-accent max-md:bg-accent-doux max-md:font-semibold'
      : 'md:border-transparent text-attenue hover:text-texte max-md:text-texte',
  ].join(' ')

const classeLigneTiroir =
  'flex h-11 items-center justify-between rounded-champ px-3 text-[15px] hover:bg-accent-doux'
</script>

<template>
  <header
    class="relative flex h-14 items-center gap-1 border-b border-ligne bg-panneau px-5 max-md:h-[52px] max-md:px-1"
    @keydown.escape="fermerEtRendreLeFocus"
  >
    <!-- Téléphone : le retour d'un écran de détail remplace le burger. -->
    <button
      v-if="connecte && parent"
      type="button"
      class="flex h-11 items-center gap-1 px-2 text-[15px] font-semibold whitespace-nowrap text-accent md:hidden"
      @click="retour"
    >
      <span aria-hidden="true">‹</span> {{ parent.libelle }}
    </button>
    <button
      v-else-if="connecte"
      ref="boutonMenu"
      type="button"
      class="flex h-11 w-11 flex-none md:hidden items-center justify-center rounded-champ text-attenue hover:text-texte"
      :aria-label="ouvert === 'menu' ? 'Fermer le menu' : 'Ouvrir le menu'"
      :aria-expanded="ouvert === 'menu'"
      aria-controls="menu-principal"
      @click="basculerTiroir('menu')"
    >
      <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
        <path d="M3 5h14M3 10h14M3 15h14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
      </svg>
    </button>

    <RouterLink
      :to="{ name: 'arbre' }"
      class="mr-[18px] text-base font-bold tracking-[-.01em] whitespace-nowrap"
      :class="connecte ? 'max-md:hidden' : 'max-md:px-3'"
    >
      Heritage
    </RouterLink>

    <!-- Téléphone : le titre de l'écran et son résumé. -->
    <div v-if="connecte" class="titre-ecran flex min-w-0 flex-1 flex-col leading-tight md:hidden">
      <span class="truncate text-base font-semibold">{{ titre }}</span>
      <span v-if="resume" class="truncate text-[11.5px] text-attenue">{{ resume }}</span>
    </div>

    <div v-if="ouvert === 'menu'" class="fixed inset-0 z-30 bg-black/40 md:hidden" aria-hidden="true" @click="fermerTiroirs" />

    <div
      v-if="connecte"
      id="menu-principal"
      class="md:contents max-md:fixed max-md:inset-y-0 max-md:left-0 max-md:z-40 max-md:flex max-md:w-[86%] max-md:max-w-sm max-md:flex-col max-md:overflow-y-auto max-md:bg-panneau max-md:pt-1.5 max-md:pb-4 max-md:shadow-panneau"
      :class="ouvert === 'menu' ? '' : 'max-md:hidden'"
    >
      <!-- Tiroir : en-tête, compte, proposer. -->
      <div class="flex flex-col md:hidden">
        <div class="flex h-12 items-center justify-between pr-1 pl-5">
          <span class="text-base font-bold">Heritage</span>
          <button type="button" class="h-11 w-11 text-xl text-attenue" aria-label="Fermer le menu" @click="fermerEtRendreLeFocus">×</button>
        </div>
        <RouterLink :to="{ name: 'compte' }" class="mx-3 mt-1 flex items-center gap-3 rounded-panneau border border-ligne p-3">
          <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-accent-doux text-sm font-bold text-accent">{{ initiales }}</span>
          <span class="flex min-w-0 flex-col leading-snug">
            <span class="truncate font-semibold">{{ nomAffiche }}</span>
            <span class="text-xs text-attenue">{{ libelleRole }} · Mon compte</span>
          </span>
        </RouterLink>
        <div class="p-3 empty:hidden [&>a]:flex [&>a]:h-11 [&>a]:w-full [&>a]:justify-center">
          <PointCreation />
        </div>
      </div>

      <nav aria-label="Navigation principale" class="flex md:h-14 md:items-stretch max-md:flex-col max-md:px-2">
        <span class="px-3 pt-1.5 pb-1.5 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase md:hidden">Explorer</span>
        <RouterLink v-for="o in ONGLETS" :key="o.nom" :to="{ name: o.nom }" :class="classeOnglet(section === o.section)">
          {{ o.libelle }}
        </RouterLink>

        <template v-if="rattache">
          <span class="px-3 pt-3.5 pb-1.5 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase md:hidden">Contribuer</span>
          <RouterLink :to="{ name: 'demandes.miennes' }" :class="classeLigneTiroir" class="md:hidden">
            Mes demandes
            <span v-if="demandesEnAttente" class="flex h-[22px] min-w-[22px] items-center justify-center rounded-pastille bg-accent-doux px-1.5 text-xs font-bold text-accent">{{ demandesEnAttente }}</span>
          </RouterLink>
          <RouterLink :to="{ name: 'questions' }" :class="classeLigneTiroir" class="md:hidden">Questions</RouterLink>
          <RouterLink :to="{ name: 'tirages' }" :class="classeLigneTiroir" class="md:hidden">Tirages au sort</RouterLink>
        </template>

        <template v-if="administrateur">
          <span class="px-3 pt-3.5 pb-1.5 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase md:hidden">Administration</span>
          <RouterLink :to="{ name: 'administration.demandes' }" :class="classeLigneTiroir" class="md:hidden">
            File de validation
            <span v-if="fileEnAttente" class="flex h-[22px] min-w-[22px] items-center justify-center rounded-pastille bg-accent px-1.5 text-xs font-bold text-fond">{{ fileEnAttente }}</span>
          </RouterLink>
          <RouterLink :to="{ name: 'administration.invitations' }" :class="classeLigneTiroir" class="md:hidden">Invitations</RouterLink>
          <RouterLink :to="{ name: 'administration.utilisateurs' }" :class="classeLigneTiroir" class="md:hidden">Utilisateurs</RouterLink>
        </template>
      </nav>

      <div class="mt-auto border-t border-ligne pt-2 md:hidden">
        <button type="button" class="flex h-11 w-full items-center px-5 text-attenue" @click="quitter">Se déconnecter</button>
      </div>
    </div>

    <!-- Bureau : recherche, contribution, compte. -->
    <template v-if="connecte">
      <span class="flex-1 max-md:hidden" aria-hidden="true" />
      <RechercheEntete class="max-md:hidden" />
      <span class="flex-1 max-md:hidden" aria-hidden="true" />

      <RouterLink
        v-if="rattache"
        :to="{ name: 'demandes.miennes' }"
        class="flex h-[34px] items-center gap-1.5 rounded-champ px-2.5 text-[13px] whitespace-nowrap hover:bg-accent-doux max-md:hidden"
        active-class="bg-accent-doux font-semibold"
      >
        Mes demandes
        <span v-if="demandesEnAttente" class="flex h-[18px] min-w-[18px] items-center justify-center rounded-pastille bg-accent-doux px-1 text-[11px] font-bold text-accent">{{ demandesEnAttente }}</span>
      </RouterLink>
      <RouterLink
        v-if="administrateur"
        :to="{ name: 'administration.demandes' }"
        class="flex h-[34px] items-center gap-1.5 rounded-champ px-2.5 text-[13px] whitespace-nowrap hover:bg-accent-doux max-md:hidden"
        active-class="bg-accent-doux font-semibold"
      >
        Validation
        <span v-if="fileEnAttente" class="flex h-[18px] min-w-[18px] items-center justify-center rounded-pastille bg-accent px-1 text-[11px] font-bold text-fond">{{ fileEnAttente }}</span>
      </RouterLink>
      <div class="max-md:hidden [&>a]:h-[34px] [&>a]:py-0 [&>a]:text-[13px]">
        <PointCreation discret libelle="+ Proposer" />
      </div>

      <div class="relative max-md:hidden">
        <button
          ref="boutonCompte"
          type="button"
          class="ml-1 flex h-[34px] items-center gap-1 rounded-pastille pr-1.5 pl-0.5 hover:bg-accent-doux"
          :aria-expanded="ouvert === 'compte'"
          aria-controls="menu-compte"
          aria-label="Mon compte"
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
          class="absolute top-[calc(100%+10px)] right-0 z-50 w-[280px] rounded-panneau border border-ligne bg-panneau py-1 shadow-panneau"
        >
          <div class="border-b border-ligne px-4 pt-2 pb-2.5">
            <div class="truncate font-semibold">{{ nomAffiche }}</div>
            <div class="truncate text-xs text-attenue">{{ libelleRole }}<template v-if="courriel"> · {{ courriel }}</template></div>
          </div>
          <RouterLink :to="{ name: 'compte' }" class="flex h-[38px] items-center px-4 text-[13.5px] hover:bg-accent-doux" active-class="bg-accent-doux">Mon compte</RouterLink>
          <template v-if="rattache">
            <RouterLink :to="{ name: 'demandes.miennes' }" class="flex h-[38px] items-center justify-between px-4 text-[13.5px] hover:bg-accent-doux" active-class="bg-accent-doux">
              Mes demandes
              <span v-if="demandesEnAttente" class="rounded-pastille bg-accent-doux px-1.5 text-[11px] font-bold text-accent">{{ demandesEnAttente }}</span>
            </RouterLink>
            <RouterLink :to="{ name: 'questions' }" class="flex h-[38px] items-center px-4 text-[13.5px] hover:bg-accent-doux" active-class="bg-accent-doux">Questions</RouterLink>
            <RouterLink :to="{ name: 'tirages' }" class="flex h-[38px] items-center px-4 text-[13.5px] hover:bg-accent-doux" active-class="bg-accent-doux">Tirages au sort</RouterLink>
          </template>
          <template v-if="administrateur">
            <div class="px-4 pt-2.5 pb-1 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Administration</div>
            <RouterLink :to="{ name: 'administration.demandes' }" class="flex h-[38px] items-center justify-between px-4 text-[13.5px] hover:bg-accent-doux" active-class="bg-accent-doux">
              File de validation
              <span v-if="fileEnAttente" class="rounded-pastille bg-accent px-1.5 text-[11px] font-bold text-fond">{{ fileEnAttente }}</span>
            </RouterLink>
            <RouterLink :to="{ name: 'administration.invitations' }" class="flex h-[38px] items-center px-4 text-[13.5px] hover:bg-accent-doux" active-class="bg-accent-doux">Invitations</RouterLink>
            <RouterLink :to="{ name: 'administration.utilisateurs' }" class="flex h-[38px] items-center px-4 text-[13.5px] hover:bg-accent-doux" active-class="bg-accent-doux">Utilisateurs</RouterLink>
            <RouterLink :to="{ name: 'administration.utilisateurs' }" class="flex h-[38px] items-center px-4 text-[13.5px] hover:bg-accent-doux">Voir comme un membre…</RouterLink>
          </template>
          <div class="my-1 border-t border-ligne" />
          <button type="button" class="flex h-[38px] w-full items-center px-4 text-[13.5px] text-attenue hover:bg-accent-doux" @click="quitter">Se déconnecter</button>
        </div>
      </div>
    </template>

    <!-- Téléphone : le bouton filtres, à l'autre bout de la barre. -->
    <button
      v-if="connecte && outilsDisponibles"
      ref="boutonOutils"
      type="button"
      class="relative flex h-11 w-11 flex-none items-center justify-center rounded-champ md:hidden"
      :class="ouvert === 'outils' ? 'bg-accent-doux text-accent' : 'text-attenue hover:text-texte'"
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
  </header>
</template>
