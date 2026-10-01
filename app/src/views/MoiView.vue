<script setup lang="ts">
/**
 * « Moi » (`/moi`) — la quatrième entrée de la navigation (refonte du 30/09/2026).
 *
 * Tout ce qui concerne **le compte qui lit** tient ici, et nulle part ailleurs :
 * mon compte, mes demandes, les tirages, les actes demandés, les questions,
 * l'administration pour un administrateur, et la déconnexion. C'est la règle de
 * la refonte : **chaque chose a une seule porte d'entrée**. L'en-tête ne porte
 * plus « Mes demandes », « Validation » ni « + Proposer » ; le tiroir du
 * téléphone a disparu. Sur téléphone, on arrive ici par l'entrée « Moi » de la
 * barre du bas.
 *
 * **Deux formes, un seul contenu.** Sur bureau, le menu de l'avatar reprend
 * cette liste : l'en-tête monte ce composant avec `forme="menu"` (lignes plus
 * serrées, sans le panneau d'installation). Écrire la liste deux fois, c'était
 * la garantie qu'elles divergent — c'est exactement ce qui était arrivé entre
 * le tiroir et le menu du compte.
 *
 * Ce qui ne change pas :
 *
 * - **La liste suit le régime, pas le rôle.** Un compte en attente ne voit que
 *   son compte, l'installation et la déconnexion — l'API lui refuserait le
 *   reste, et la garde de route le renverrait vers l'écran d'attente.
 *   L'administration n'apparaît qu'à un administrateur. Masquer n'est pas une
 *   mesure de sécurité : les gardes de route doublent, le serveur double tout.
 * - **Lignes tactiles de 48 px au moins** (`min-h-12`) en forme page : c'est la
 *   cible recommandée pour un pouce.
 * - **Les notifications sur ce téléphone** (Web Push) ont leur ligne, en forme
 *   page seulement : c'est un réglage de l'appareil, et le menu du bureau n'a
 *   pas de téléphone à régler (`NotificationsTelephone`).
 * - **La déconnexion** est celle de l'ancien en-tête : on referme les tiroirs,
 *   on déconnecte, on part vers la connexion.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { deconnecter, useSession } from '@/composables/useSession'
import { useTiroirs } from '@/composables/useTiroirs'
import { listerDemandesAdministration } from '@/lib/api'
import InstallationApplication from '@/components/mise-en-page/InstallationApplication.vue'
import NotificationsTelephone from '@/components/notifications/NotificationsTelephone.vue'

const { forme = 'page' } = defineProps<{
  /** `page` : l'écran `/moi`. `menu` : le menu déroulant de l'avatar, sur bureau. */
  forme?: 'page' | 'menu'
}>()

const routeur = useRouter()
const etatSession = useSession()
const { regime, rattache, administrateur, nomAffiche } = etatSession
const { fermerTiroirs } = useTiroirs()

const courriel = computed(() => etatSession.session?.value?.utilisateur.email ?? '')
const libelleRole = computed(() =>
  administrateur.value ? 'Administrateur' : regime.value === 'en_attente' ? 'En attente de validation' : 'Membre',
)
const initiales = computed(() => {
  const mots = nomAffiche.value.split(/[\s@.]+/).filter(Boolean)
  return ((mots[0]?.[0] ?? '') + (mots[1]?.[0] ?? '')).toUpperCase() || '?'
})

/** Les demandes du lecteur encore en attente — déjà dans la charge de session. */
const demandesEnAttente = computed(() => etatSession.session?.value?.demandesEnAttente?.length ?? 0)

/** La file de validation, pour un administrateur. Une requête d'une ligne :
 *  seul le total nous intéresse. Un échec rend 0 — un compteur absent vaut
 *  mieux qu'un écran en erreur pour une pastille. */
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
onMounted(() => void compterLaFile())

async function quitter(): Promise<void> {
  fermerTiroirs()
  await deconnecter()
  await routeur.push({ name: 'connexion' })
}

interface Entree {
  nom: string
  libelle: string
  compte?: number
  /** Pastille pleine (action attendue de l'administrateur) plutôt que douce. */
  urgent?: boolean
}

const entrees = computed<Entree[]>(() => {
  const liste: Entree[] = [{ nom: 'compte', libelle: 'Mon compte' }]
  if (rattache.value) {
    liste.push(
      { nom: 'demandes.miennes', libelle: 'Mes demandes', compte: demandesEnAttente.value },
      { nom: 'tirages', libelle: 'Tirages au sort' },
      { nom: 'demarches', libelle: 'Actes demandés' },
      { nom: 'questions', libelle: 'Questions' },
    )
  }
  return liste
})

const entreesAdministration = computed<Entree[]>(() =>
  administrateur.value
    ? [
        { nom: 'administration.demandes', libelle: 'Administration', compte: fileEnAttente.value, urgent: true },
      ]
    : [],
)

const page = computed(() => forme === 'page')
const classeLigne = computed(() =>
  page.value
    ? 'flex min-h-12 items-center justify-between gap-3 px-4 text-[15px] hover:bg-accent-doux'
    : 'flex h-[38px] items-center justify-between gap-3 px-4 text-[13.5px] hover:bg-accent-doux',
)
const classePastille = (urgent: boolean | undefined): string =>
  [
    'flex min-w-[22px] items-center justify-center rounded-pastille px-1.5 text-xs font-bold tnum',
    page.value ? 'h-[22px]' : 'h-[18px] text-[11px]',
    urgent ? 'bg-accent text-fond' : 'bg-accent-doux text-accent',
  ].join(' ')
</script>

<template>
  <div :class="page ? 'mx-auto w-full max-w-xl px-4 py-5 md:py-8' : 'py-1'">
    <!-- Qui lit. -->
    <div v-if="page" class="flex items-center gap-4 px-1 pb-5">
      <span class="flex h-14 w-14 flex-none items-center justify-center rounded-full bg-accent-doux text-lg font-bold text-accent">{{ initiales }}</span>
      <div class="flex min-w-0 flex-col leading-snug">
        <h1 class="m-0 truncate text-xl font-bold">{{ nomAffiche }}</h1>
        <span class="text-sm text-attenue">{{ libelleRole }}</span>
        <span v-if="courriel" class="truncate text-sm text-attenue">{{ courriel }}</span>
      </div>
    </div>
    <div v-else class="border-b border-ligne px-4 pt-2 pb-2.5">
      <div class="truncate font-semibold">{{ nomAffiche }}</div>
      <div class="truncate text-xs text-attenue">{{ libelleRole }}<template v-if="courriel"> · {{ courriel }}</template></div>
    </div>

    <nav aria-label="Mon espace" :class="page ? 'overflow-hidden rounded-panneau border border-ligne bg-panneau' : ''">
      <ul class="m-0 list-none p-0" :class="page ? 'divide-y divide-ligne' : ''">
        <li v-for="e in entrees" :key="e.nom">
          <RouterLink :to="{ name: e.nom }" :class="classeLigne" active-class="bg-accent-doux font-semibold">
            <span>{{ e.libelle }}</span>
            <span class="flex items-center gap-2">
              <span v-if="e.compte" :class="classePastille(e.urgent)">{{ e.compte }}</span>
              <span v-if="page" class="text-attenue" aria-hidden="true">›</span>
            </span>
          </RouterLink>
        </li>
      </ul>
    </nav>

    <template v-if="entreesAdministration.length">
      <h2
        class="m-0 font-semibold tracking-[.08em] text-attenue uppercase"
        :class="page ? 'px-1 pt-6 pb-2 text-xs' : 'px-4 pt-2.5 pb-1 text-[11px]'"
      >
        Administration
      </h2>
      <nav aria-label="Administration" :class="page ? 'overflow-hidden rounded-panneau border border-ligne bg-panneau' : ''">
        <ul class="m-0 list-none p-0" :class="page ? 'divide-y divide-ligne' : ''">
          <li v-for="e in entreesAdministration" :key="e.libelle">
            <RouterLink :to="{ name: e.nom }" :class="classeLigne">
              <span>{{ e.libelle }}</span>
              <span class="flex items-center gap-2">
                <span v-if="e.compte" :class="classePastille(e.urgent)">{{ e.compte }}</span>
                <span v-if="page" class="text-attenue" aria-hidden="true">›</span>
              </span>
            </RouterLink>
          </li>
        </ul>
      </nav>
    </template>

    <!-- Les notifications push de CET appareil : un réglage par téléphone, pas
         par compte — d'où sa place à part, hors de la liste des écrans. -->
    <div v-if="page" class="mt-6 overflow-hidden rounded-panneau border border-ligne bg-panneau">
      <NotificationsTelephone forme="ligne" />
    </div>

    <!-- Installer l'application : le panneau ne se montre que s'il y a quelque
         chose à installer (navigateur qui le propose, ou iPhone). -->
    <div v-if="page" class="mt-6 empty:hidden">
      <InstallationApplication forme="panneau" />
    </div>

    <div :class="page ? 'mt-6' : 'my-1 border-t border-ligne pt-1'">
      <button
        type="button"
        :class="
          page
            ? 'flex min-h-12 w-full items-center justify-center rounded-panneau border border-ligne bg-panneau px-4 text-[15px] text-alerte hover:bg-alerte-doux'
            : 'flex h-[38px] w-full items-center px-4 text-[13.5px] text-attenue hover:bg-accent-doux'
        "
        @click="quitter"
      >
        Se déconnecter
      </button>
    </div>
  </div>
</template>
