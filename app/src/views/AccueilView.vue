<script setup lang="ts">
/**
 * L'accueil — la page où tout lecteur connecté atterrit (`/home`).
 *
 * Trois choses, dans l'ordre où on les cherche en ouvrant l'application
 * (refonte du 30/09/2026, pensée pour le téléphone) :
 *
 * 1. **deux boutons sur une ligne** — « Arbre » et « Ajouter une personne », qui
 *    ouvre le formulaire d'ajout (05/10/2026 : un formulaire, avec l'identité et
 *    les liens vers les autres personnes, y compris celles qu'on a proposées et
 *    qui ne sont pas encore validées) ;
 * 2. **mes événements** (02/10/2026 : une seule section, « le tirage au sort
 *    est un événement en soi ») — **tous** ceux qui m'attendent, le plus
 *    proche d'abord : les rassemblements où je suis invité ou que j'organise,
 *    avec ma réponse ou les deux boutons Présent / Absent
 *    (`CarteEvenementAccueil`) — la carte vue, l'annonce l'est aussi —, et les
 *    tirages dont je suis ou que j'organise, repliés sur une ligne, dépliés
 *    pour régler ma présence, et une fois le tirage fait « Voir à qui je fais
 *    le cadeau » en violet, sans rien déplier (`CarteTirageAccueil`) ;
 * 3. **écrire à l'administration** (07/10/2026) — « Une question, une idée,
 *    un bug ? » : trois boutons qui ouvrent une nouvelle conversation avec la
 *    nature déjà choisie, et « Poursuivre » quand on en a déjà une — avec le
 *    nombre de réponses non lues. Les messages **des membres**, eux, attendent
 *    l'administrateur dans « À traiter », avec la pastille du mode admin ;
 * 4. **les anniversaires**, en carrousel : on glisse (ou les flèches) pour voir
 *    les jours voisins. Les vivants d'abord, bien lisibles ; les défunts
 *    ensuite, en retrait, sous « En mémoire ».
 *
 * Les anniversaires viennent de `GET /api/anniversaires`, l'année entière en une
 * fois : glisser d'un jour à l'autre ne redemande rien. Les tirages exigent un
 * compte rattaché ; un compte en attente ne les demande donc pas.
 *
 * **Aucun receveur n'est rendu sans geste**, même au lecteur : l'accueil
 * s'ouvre volontiers devant d'autres. Le nom n'entre dans le DOM qu'au clic.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import {
  chargerAnniversaires,
  listerEvenements,
  listerMesFils,
  listerTirages,
  marquerVu,
  repondreAEvenement,
  type PersonneDuTirage,
  type ReponseAInvitation,
  type ResumeDEvenement,
  type ResumeDeFil,
  type ResumeDeTirage,
  type VueAnniversaires,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import LigneEvenement from '@/components/anniversaires/LigneEvenement.vue'
import CarteTirageAccueil from '@/components/accueil/CarteTirageAccueil.vue'
import CarteEvenementAccueil from '@/components/accueil/CarteEvenementAccueil.vue'
import { mesEvenements } from '@/components/evenements-familiaux/liste-commune'
import { NATURES } from '@/components/messagerie/messagerie'
import {
  anniversairesDuJour,
  decaler,
  jourCourt,
  libelleDate,
  libelleRelatif,
} from '@/components/accueil/jours'

const { rattache, nomAffiche, vueEmpruntee } = useSession()

const aujourdhui = decaler(new Date(), 0)
const prenom = computed(() => nomAffiche.value.split(/\s+/)[0] ?? '')

/* ───────────────── Chargement ───────────────── */

const anniversaires = shallowRef<VueAnniversaires | null>(null)
const erreurAnniversaires = ref<unknown>(null)
const tirages = shallowRef<ResumeDeTirage[]>([])
const moi = shallowRef<PersonneDuTirage | null>(null)
const evenements = shallowRef<ResumeDEvenement[]>([])
const mesFils = shallowRef<ResumeDeFil[]>([])
let avorteur: AbortController | null = null

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  const { signal } = avorteur
  erreurAnniversaires.value = null

  // En parallèle, et chacun pour soi : des tirages en panne ne retirent pas
  // les anniversaires, ni l'inverse — ni les événements.
  await Promise.all([
    chargerAnniversaires(signal).then(
      (vue) => (anniversaires.value = vue),
      (e: unknown) => (erreurAnniversaires.value = e),
    ),
    rattache.value
      ? listerTirages(signal).then(
          (liste) => {
            tirages.value = liste.tirages
            moi.value = liste.moi
          },
          () => (tirages.value = []),
        )
      : Promise.resolve(),
    rattache.value
      ? listerEvenements(signal).then(
          (liste) => {
            evenements.value = liste.evenements
            signalerLaVue()
          },
          () => (evenements.value = []),
        )
      : Promise.resolve(),
    // La messagerie : au mieux, une panne n'ôte que la ligne « Poursuivre ».
    listerMesFils(signal).then(
      (liste) => (mesFils.value = liste.elements),
      () => (mesFils.value = []),
    ),
  ])
}

/* ───────────────── Écrire à l'administration ───────────────── */

/** Les réponses de l'administration pas encore lues, toutes conversations confondues. */
const reponsesNonLues = computed(() => mesFils.value.reduce((n, f) => n + f.nonLus, 0))

onMounted(charger)
onBeforeUnmount(() => avorteur?.abort())

/* ───────────────── Mes événements ───────────────── */

/** Rassemblements et tirages mêlés, le plus proche d'abord (`mesEvenements`). */
const mesProchains = computed(() => mesEvenements(evenements.value, tirages.value, aujourdhui))

/** La réponse qui part, et pour quel rassemblement : une à la fois. */
const reponseEnCours = ref<{ id: number; reponse: ReponseAInvitation } | null>(null)
const erreurDeReponse = ref<{ id: number; erreur: unknown } | null>(null)

/**
 * Les cartes montrent les annonces : elles sont vues — chacune de celles où
 * l'on est invité. Au mieux : un échec ne se signale pas, l'annonce sera
 * marquée à l'ouverture de sa page. Rien sous la bascule « voir comme » :
 * l'administrateur ne voit pas à la place du membre.
 */
function signalerLaVue(): void {
  if (vueEmpruntee.value !== null) return
  for (const element of mesProchains.value) {
    if (element.type === 'rassemblement' && element.evenement.suisInvite) {
      void marquerVu(element.evenement.id).catch(() => undefined)
    }
  }
}

async function repondre(id: number, reponse: ReponseAInvitation): Promise<void> {
  if (reponseEnCours.value !== null) return
  reponseEnCours.value = { id, reponse }
  erreurDeReponse.value = null
  try {
    // La réponse est l'événement relu ; son résumé remplace celui de la liste.
    const relu = await repondreAEvenement(id, reponse)
    evenements.value = evenements.value.map((x) => (x.id === relu.id ? relu : x))
  } catch (erreur: unknown) {
    erreurDeReponse.value = { id, erreur }
  } finally {
    reponseEnCours.value = null
  }
}

/** La réponse d'un réglage de présence remplace le résumé du tirage. */
function remplacerTirage(maj: ResumeDeTirage): void {
  tirages.value = tirages.value.map((t) => (t.id === maj.id ? maj : t))
}

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
    const nombre = anniversairesDuJour(anniversaires.value?.jours ?? [], date).length
    return { delta: d, date, nombre, estAujourdhui: ecart.value + d === 0 }
  }),
)

const evenementsDuJour = computed(() => anniversairesDuJour(anniversaires.value?.jours ?? [], jourAffiche.value))

/** Un événement est « des vivants » dès qu'un de ses sujets l'est : un mariage
 *  dont un époux vit encore se fête encore. */
const evenementsVivants = computed(() => evenementsDuJour.value.filter((e) => e.sujets.some((s) => s.vivant)))
const evenementsEnMemoire = computed(() => evenementsDuJour.value.filter((e) => !e.sujets.some((s) => s.vivant)))

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
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-6">
    <header class="mb-5">
      <h1 class="text-xl font-semibold text-texte">Bonjour{{ prenom ? `, ${prenom}` : '' }}</h1>
      <p class="mt-1 text-sm text-attenue">{{ libelleRelatif(aujourdhui, aujourdhui) }}, {{ libelleDate(aujourdhui) }}</p>
    </header>

    <!-- ───────── Accès rapides : deux boutons, puis la question à l'IA ───────── -->
    <nav aria-label="Accès rapides" class="mb-6 grid grid-cols-2 gap-3">
      <RouterLink
        :to="{ name: 'arbre' }"
        class="flex items-center justify-center gap-2 rounded-panneau bg-accent px-3 py-3.5 text-sm font-semibold text-fond transition-opacity hover:opacity-90"
      >
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
          <circle cx="10" cy="4" r="2" /><circle cx="4" cy="15" r="2" /><circle cx="16" cy="15" r="2" />
          <path d="M10 6v4M10 10H4v3M10 10h6v3" />
        </svg>
        Arbre
      </RouterLink>
      <RouterLink
        v-if="rattache && vueEmpruntee === null"
        :to="{ name: 'proposition.creation', query: { mode: 'formulaire' } }"
        class="flex items-center justify-center gap-2 rounded-panneau border border-accent bg-panneau px-3 py-3.5 text-sm font-semibold text-accent transition-colors hover:bg-accent-doux"
      >
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="8" cy="6.5" r="3" /><path d="M2.5 17c0-3 2.5-5 5.5-5s5.5 2 5.5 5" /><path d="M16 5v6M13 8h6" />
        </svg>
        Ajouter une personne
      </RouterLink>
      <p v-else-if="!rattache" class="flex items-center rounded-panneau border border-dashed border-ligne p-3 text-xs text-attenue">
        Vous pourrez ajouter des personnes une fois votre rattachement validé.
      </p>
      <!-- L'assistant en mode question : interroger l'arbre en conversation.
           Sur toute la largeur, sous les deux autres. -->
      <RouterLink
        v-if="rattache && vueEmpruntee === null"
        :to="{ name: 'proposition.creation', query: { mode: 'question' } }"
        class="col-span-2 flex items-center justify-center gap-2 rounded-panneau border border-ligne bg-panneau px-3 py-3.5 text-sm font-semibold text-texte transition-colors hover:border-accent hover:text-accent"
      >
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 4h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H9l-4 3v-3H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z" />
          <path d="M8 8a2 2 0 1 1 2.5 1.9c-.3.1-.5.4-.5.7V11" /><path d="M10 12.8v.2" />
        </svg>
        Poser une question sur l’arbre
      </RouterLink>
    </nav>

    <!-- ───────── Écrire à l'administration : créer, ou poursuivre ───────── -->
    <section
      v-if="vueEmpruntee === null"
      aria-labelledby="titre-ecrire-administration"
      class="mb-6 rounded-panneau border border-ligne bg-panneau p-4"
    >
      <div class="flex items-start gap-3">
        <svg class="mt-0.5 flex-none text-accent" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M3 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H9l-4 3v-3H5a2 2 0 0 1-2-2z" />
          <path d="M7 7.5h6M7 10.5h4" />
        </svg>
        <div class="min-w-0 flex-1">
          <h2 id="titre-ecrire-administration" class="text-sm font-semibold text-texte">Une question, une idée, un bug ?</h2>
          <p class="mt-0.5 text-xs text-attenue">Écrivez aux administrateurs : vous verrez quand ils l’auront lu.</p>
        </div>
      </div>
      <div class="mt-3 grid grid-cols-3 gap-2">
        <RouterLink
          v-for="n in NATURES"
          :key="n.valeur"
          :to="{ name: 'messagerie', query: { nouveau: n.valeur } }"
          class="flex items-center justify-center rounded-champ border border-ligne px-2 py-2.5 text-center text-[13px] font-semibold text-texte transition-colors hover:border-accent hover:text-accent"
        >
          {{ n.libelle }}
        </RouterLink>
      </div>
      <RouterLink
        v-if="mesFils.length > 0"
        :to="{ name: 'messagerie' }"
        class="mt-3 flex items-center justify-between gap-2 rounded-champ px-1 py-1 text-sm text-accent hover:underline"
      >
        <span>Poursuivre une conversation{{ mesFils.length > 1 ? ` (${mesFils.length})` : '' }}</span>
        <span
          v-if="reponsesNonLues > 0"
          class="flex h-[20px] min-w-[20px] items-center justify-center rounded-pastille bg-accent px-1.5 text-xs font-bold text-fond tnum"
          :aria-label="`${reponsesNonLues} réponse${reponsesNonLues > 1 ? 's' : ''} non lue${reponsesNonLues > 1 ? 's' : ''}`"
        >{{ reponsesNonLues }}</span>
      </RouterLink>
    </section>

    <!-- ───────── Mes événements : rassemblements et tirages, le plus proche d'abord ───────── -->
    <section v-if="rattache && mesProchains.length > 0" aria-labelledby="titre-mes-evenements" class="mb-6">
      <div class="mb-2 flex items-baseline justify-between">
        <h2 id="titre-mes-evenements" class="text-sm font-semibold tracking-tight">Mes événements</h2>
        <RouterLink :to="{ name: 'evenements' }" class="text-xs text-accent underline">Tous les événements</RouterLink>
      </div>
      <div class="flex flex-col gap-3">
        <template v-for="element in mesProchains" :key="element.cle">
          <CarteEvenementAccueil
            v-if="element.type === 'rassemblement'"
            :evenement="element.evenement"
            :aujourdhui="aujourdhui"
            :en-cours="reponseEnCours?.id === element.evenement.id ? reponseEnCours.reponse : null"
            :erreur="erreurDeReponse?.id === element.evenement.id ? erreurDeReponse.erreur : null"
            :desactive="vueEmpruntee !== null || (reponseEnCours !== null && reponseEnCours.id !== element.evenement.id)"
            @repondre="(r) => repondre(element.evenement.id, r)"
          />
          <CarteTirageAccueil
            v-else
            :tirage="element.tirage"
            :moi="moi"
            :aujourdhui="aujourdhui"
            @maj="remplacerTirage"
          />
        </template>
      </div>
    </section>

    <!-- ───────── Les anniversaires, en carrousel ───────── -->
    <section
      class="rounded-panneau border border-ligne bg-panneau"
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
          <p class="truncate text-sm font-semibold text-texte">Anniversaires · {{ libelleRelatif(jourAffiche, aujourdhui) }}</p>
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
          <div :key="ecart" class="flex flex-col gap-5">
            <ul v-if="evenementsVivants.length > 0" class="flex flex-col gap-3">
              <li v-for="e in evenementsVivants" :key="e.cle">
                <LigneEvenement :evenement="e" :annee-affichee="jourAffiche.getFullYear()" />
              </li>
            </ul>

            <div v-if="evenementsEnMemoire.length > 0" class="en-memoire">
              <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-attenue">En mémoire</p>
              <ul class="flex flex-col gap-3">
                <li v-for="e in evenementsEnMemoire" :key="e.cle">
                  <LigneEvenement :evenement="e" :annee-affichee="jourAffiche.getFullYear()" />
                </li>
              </ul>
            </div>

            <p v-if="evenementsDuJour.length === 0" class="py-6 text-center text-sm text-attenue">
              Rien à fêter ce jour-là. Glissez pour voir les jours voisins.
            </p>
          </div>
        </Transition>
      </div>

      <div v-if="ecart !== 0" class="border-t border-ligne px-4 py-2 text-xs">
        <button type="button" class="font-semibold text-accent underline" @click="aller(-ecart)">
          Revenir à aujourd’hui
        </button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.zone-glisser {
  touch-action: pan-y;
}

/* Les défunts en retrait : lisibles, mais après les vivants et d'un ton plus bas. */
.en-memoire {
  opacity: 0.62;
}
.en-memoire:hover,
.en-memoire:focus-within {
  opacity: 1;
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
