<script setup lang="ts">
/**
 * L'accueil — la page où tout lecteur connecté atterrit (`/home`).
 *
 * Trois choses, dans l'ordre où on les cherche en ouvrant l'application :
 *
 * 1. **les deux portes** — l'arbre, et « proposer une personne » ;
 * 2. **le jour** — les anniversaires, en carrousel : on glisse à gauche ou à
 *    droite (ou les flèches, au clavier comme à la souris) pour voir les jours
 *    suivants et précédents, et les tirages dont c'est le jour y figurent ;
 * 3. **ses tirages** — le prochain rendez-vous de chacun.
 *
 * Les anniversaires viennent de `GET /api/anniversaires`, l'année entière en une
 * fois : glisser d'un jour à l'autre ne redemande rien. Les tirages exigent un
 * compte rattaché ; un compte en attente ne les demande donc pas.
 *
 * **Aucun receveur n'est nommé ici**, même au lecteur : l'accueil s'ouvre
 * volontiers devant d'autres, et c'est l'écran du tirage qui le dévoile.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import {
  chargerAnniversaires,
  listerTirages,
  type ResumeDeTirage,
  type VueAnniversaires,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import LigneEvenement from '@/components/anniversaires/LigneEvenement.vue'
import {
  anniversairesDuJour,
  dansCombien,
  decaler,
  etatDuTirageCeJour,
  jourCourt,
  libelleDate,
  libelleRelatif,
  prochaineOccurrence,
  tiragesDuJour,
} from '@/components/accueil/jours'

const { rattache, nomAffiche } = useSession()

const aujourdhui = decaler(new Date(), 0)
const prenom = computed(() => nomAffiche.value.split(/\s+/)[0] ?? '')

/* ───────────────── Chargement ───────────────── */

const anniversaires = shallowRef<VueAnniversaires | null>(null)
const erreurAnniversaires = ref<unknown>(null)
const tirages = shallowRef<ResumeDeTirage[]>([])
let avorteur: AbortController | null = null

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  const { signal } = avorteur
  erreurAnniversaires.value = null

  // Les deux en parallèle, et chacun pour soi : des tirages en panne ne
  // retirent pas les anniversaires, ni l'inverse.
  await Promise.all([
    chargerAnniversaires(signal).then(
      (vue) => (anniversaires.value = vue),
      (e: unknown) => (erreurAnniversaires.value = e),
    ),
    rattache.value
      ? listerTirages(signal).then(
          (liste) => (tirages.value = liste.tirages),
          () => (tirages.value = []),
        )
      : Promise.resolve(),
  ])
}

onMounted(charger)
onBeforeUnmount(() => avorteur?.abort())

/* ───────────────── Le carrousel des jours ───────────────── */

/** L'écart au jour d'aujourd'hui : 0 aujourd'hui, 1 demain, −1 hier. */
const ecart = ref(0)
/** Le sens de la dernière glissade, pour que la carte parte du bon côté. */
const sens = ref<'suivant' | 'precedent'>('suivant')

const jourAffiche = computed(() => decaler(aujourdhui, ecart.value))

function aller(delta: number): void {
  if (delta === 0) return
  sens.value = delta > 0 ? 'suivant' : 'precedent'
  ecart.value += delta
}

/** Sept jours, celui qui est affiché au milieu. */
const bandeau = computed(() =>
  [-3, -2, -1, 0, 1, 2, 3].map((d) => {
    const date = decaler(aujourdhui, ecart.value + d)
    const nombre =
      anniversairesDuJour(anniversaires.value?.jours ?? [], date).length + tiragesDuJour(tirages.value, date).length
    return { delta: d, date, nombre, estAujourdhui: ecart.value + d === 0 }
  }),
)

const evenementsDuJour = computed(() => anniversairesDuJour(anniversaires.value?.jours ?? [], jourAffiche.value))
const tiragesDuJourAffiche = computed(() => tiragesDuJour(tirages.value, jourAffiche.value))

/**
 * Glisser du doigt. `touch-action: pan-y` sur la zone laisse le défilement
 * vertical au navigateur ; seul un geste franchement horizontal change de jour.
 */
let depart: { x: number; y: number } | null = null

function surPression(e: PointerEvent): void {
  depart = { x: e.clientX, y: e.clientY }
}

function surRelache(e: PointerEvent): void {
  if (depart === null) return
  const dx = e.clientX - depart.x
  const dy = e.clientY - depart.y
  depart = null
  if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy) * 1.5) return
  aller(dx < 0 ? 1 : -1)
}

function surTouche(e: KeyboardEvent): void {
  if (e.key === 'ArrowRight') aller(1)
  else if (e.key === 'ArrowLeft') aller(-1)
  else return
  e.preventDefault()
}

/* ───────────────── Les prochains tirages ───────────────── */

const prochainsTirages = computed(() =>
  tirages.value
    .filter((t) => t.suisParticipant || t.organisateur.estMoi)
    .map((t) => ({ tirage: t, date: prochaineOccurrence(t, aujourdhui) }))
    .sort((a, b) => a.date.getTime() - b.date.getTime()),
)
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-6">
    <header class="mb-5">
      <h1 class="text-xl font-semibold text-texte">Bonjour{{ prenom ? `, ${prenom}` : '' }}</h1>
      <p class="mt-1 text-sm text-attenue">{{ libelleRelatif(aujourdhui, aujourdhui) }}, {{ libelleDate(aujourdhui) }}</p>
    </header>

    <!-- ───────── Les deux portes ───────── -->
    <nav aria-label="Accès rapides" class="mb-6 grid gap-3 sm:grid-cols-2">
      <RouterLink
        :to="{ name: 'arbre' }"
        class="group flex items-center gap-3 rounded-panneau border border-ligne bg-panneau p-4 transition-colors hover:border-accent"
      >
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-pastille bg-accent-doux text-accent" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
            <circle cx="10" cy="4" r="2" /><circle cx="4" cy="15" r="2" /><circle cx="16" cy="15" r="2" />
            <path d="M10 6v4M10 10H4v3M10 10h6v3" />
          </svg>
        </span>
        <span class="min-w-0">
          <span class="block font-medium text-texte">Explorer l’arbre</span>
          <span class="block text-xs text-attenue">La famille, génération par génération</span>
        </span>
      </RouterLink>

      <RouterLink
        v-if="rattache"
        :to="{ name: 'proposition.creation' }"
        class="group flex items-center gap-3 rounded-panneau border border-ligne bg-panneau p-4 transition-colors hover:border-accent"
      >
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-pastille bg-accent-doux text-accent" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
            <path d="M10 4v12M4 10h12" />
          </svg>
        </span>
        <span class="min-w-0">
          <span class="block font-medium text-texte">Proposer une personne</span>
          <span class="block text-xs text-attenue">Ajouter quelqu’un qui manque, relu avant d’entrer</span>
        </span>
      </RouterLink>
      <p v-else class="flex items-center rounded-panneau border border-dashed border-ligne p-4 text-xs text-attenue">
        Vous pourrez proposer des personnes une fois votre rattachement validé.
      </p>
    </nav>

    <!-- ───────── Le jour, en carrousel ───────── -->
    <section
      class="mb-6 rounded-panneau border border-ligne bg-panneau"
      aria-roledescription="carrousel"
      aria-label="Anniversaires du jour"
      tabindex="0"
      @keydown="surTouche"
    >
      <div class="flex items-center gap-2 border-b border-ligne px-2 py-2">
        <button
          type="button"
          class="flex h-9 w-9 items-center justify-center rounded-champ text-lg text-attenue hover:bg-accent-doux hover:text-texte"
          aria-label="Jour précédent"
          @click="aller(-1)"
        >
          ‹
        </button>
        <div class="min-w-0 flex-1 text-center" aria-live="polite">
          <p class="truncate text-sm font-semibold text-texte">{{ libelleRelatif(jourAffiche, aujourdhui) }}</p>
          <p class="truncate text-xs text-attenue">{{ libelleDate(jourAffiche) }}</p>
        </div>
        <button
          type="button"
          class="flex h-9 w-9 items-center justify-center rounded-champ text-lg text-attenue hover:bg-accent-doux hover:text-texte"
          aria-label="Jour suivant"
          @click="aller(1)"
        >
          ›
        </button>
      </div>

      <!-- Les sept jours autour : un point dit qu'il s'y passe quelque chose. -->
      <div class="grid grid-cols-7 gap-1 border-b border-ligne px-2 py-2">
        <button
          v-for="j in bandeau"
          :key="j.delta"
          type="button"
          class="flex flex-col items-center rounded-champ py-1.5 text-xs transition-colors"
          :class="j.delta === 0 ? 'bg-accent text-fond' : 'text-attenue hover:bg-accent-doux hover:text-texte'"
          :aria-label="`${libelleRelatif(j.date, aujourdhui)} ${libelleDate(j.date)}, ${j.nombre} événement${j.nombre > 1 ? 's' : ''}`"
          :aria-current="j.delta === 0 ? 'date' : undefined"
          @click="aller(j.delta)"
        >
          <span class="capitalize">{{ jourCourt(j.date).replace('.', '') }}</span>
          <span class="tnum text-sm font-semibold" :class="j.estAujourdhui && j.delta !== 0 ? 'text-accent' : ''">{{ j.date.getDate() }}</span>
          <span
            class="mt-0.5 h-1.5 w-1.5 rounded-pastille"
            :class="j.nombre > 0 ? (j.delta === 0 ? 'bg-fond' : 'bg-accent') : 'bg-transparent'"
            aria-hidden="true"
          />
        </button>
      </div>

      <div class="zone-glisser min-h-40 overflow-hidden px-4 py-4" @pointerdown="surPression" @pointerup="surRelache" @pointercancel="depart = null">
        <SqueletteChargement v-if="anniversaires === null && erreurAnniversaires === null" :lignes="3" />
        <MessageErreur
          v-else-if="anniversaires === null"
          :erreur="erreurAnniversaires"
          titre="Les anniversaires n’ont pas pu être chargés"
          @action="charger"
        />
        <Transition v-else :name="`glisse-${sens}`" mode="out-in">
          <div :key="ecart" class="flex flex-col gap-4">
            <RouterLink
              v-for="t in tiragesDuJourAffiche"
              :key="`tirage-${t.id}`"
              :to="{ name: 'tirage.detail', params: { id: t.id } }"
              class="flex items-center gap-3 rounded-panneau border border-accent bg-accent-doux p-3"
            >
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-pastille bg-panneau text-accent" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round">
                  <rect x="3" y="8" width="14" height="9" rx="1" /><path d="M2 5h16v3H2zM10 5v12" />
                  <path d="M10 5C8 1 5 2 6.5 4.5 7.2 5.2 10 5 10 5zM10 5c2-4 5-3 3.5-.5C12.8 5.2 10 5 10 5z" />
                </svg>
              </span>
              <span class="min-w-0">
                <span class="block text-sm font-semibold text-texte">{{ t.nom }}</span>
                <span class="block text-xs text-attenue">
                  {{ etatDuTirageCeJour(t, jourAffiche) }}<template v-if="t.lieu"> · {{ t.lieu }}</template>
                </span>
              </span>
            </RouterLink>

            <ul v-if="evenementsDuJour.length > 0" class="flex flex-col gap-3">
              <li v-for="e in evenementsDuJour" :key="e.cle">
                <LigneEvenement :evenement="e" :annee-affichee="jourAffiche.getFullYear()" />
              </li>
            </ul>
            <p v-else-if="tiragesDuJourAffiche.length === 0" class="py-6 text-center text-sm text-attenue">
              Rien à fêter ce jour-là. Glissez pour voir les jours voisins.
            </p>
          </div>
        </Transition>
      </div>

      <div class="flex items-center justify-between border-t border-ligne px-4 py-2 text-xs">
        <button v-if="ecart !== 0" type="button" class="font-semibold text-accent underline" @click="aller(-ecart)">
          Revenir à aujourd’hui
        </button>
        <span v-else class="text-attenue">Glissez ou utilisez les flèches pour changer de jour.</span>
        <RouterLink :to="{ name: 'anniversaires' }" class="text-accent underline">Tout le calendrier</RouterLink>
      </div>
    </section>

    <!-- ───────── Les prochains tirages ───────── -->
    <section v-if="rattache" aria-labelledby="titre-tirages" class="rounded-panneau border border-ligne bg-panneau">
      <div class="flex items-center justify-between border-b border-ligne px-4 py-2.5">
        <h2 id="titre-tirages" class="text-sm font-semibold tracking-tight">Vos tirages au sort</h2>
        <RouterLink :to="{ name: 'tirages' }" class="text-xs text-accent underline">Tous les tirages</RouterLink>
      </div>
      <ul v-if="prochainsTirages.length > 0" class="flex flex-col divide-y divide-ligne">
        <li v-for="{ tirage, date } in prochainsTirages" :key="tirage.id">
          <RouterLink
            :to="{ name: 'tirage.detail', params: { id: tirage.id } }"
            class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-accent-doux"
          >
            <span class="min-w-0">
              <span class="block truncate text-sm font-medium text-texte">{{ tirage.nom }}</span>
              <span class="block text-xs text-attenue">{{ libelleDate(date) }} · {{ etatDuTirageCeJour(tirage, date) }}</span>
            </span>
            <span class="shrink-0 rounded-pastille bg-accent-doux px-2 py-0.5 text-xs font-semibold text-accent">
              {{ dansCombien(date, aujourdhui) }}
            </span>
          </RouterLink>
        </li>
      </ul>
      <p v-else class="px-4 py-4 text-sm text-attenue">
        Vous ne participez à aucun tirage pour l’instant.
        <RouterLink :to="{ name: 'tirages' }" class="text-accent underline">En créer ou en rejoindre un</RouterLink>
      </p>
    </section>
  </div>
</template>

<style scoped>
.zone-glisser {
  touch-action: pan-y;
}

.glisse-suivant-enter-active,
.glisse-suivant-leave-active,
.glisse-precedent-enter-active,
.glisse-precedent-leave-active {
  transition:
    transform 160ms ease,
    opacity 160ms ease;
}

.glisse-suivant-enter-from,
.glisse-precedent-leave-to {
  transform: translateX(24px);
  opacity: 0;
}

.glisse-suivant-leave-to,
.glisse-precedent-enter-from {
  transform: translateX(-24px);
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .glisse-suivant-enter-active,
  .glisse-suivant-leave-active,
  .glisse-precedent-enter-active,
  .glisse-precedent-leave-active {
    transition: none;
  }
}
</style>
