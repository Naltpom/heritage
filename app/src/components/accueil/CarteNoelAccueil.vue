<script setup lang="ts">
/**
 * Un tirage de Noël sur l'accueil — section « Mes événements ».
 *
 * Les tirages sont ceux du **site de Noël** (noel2026) : heritage n'a plus de
 * tirage à lui. La carte dit où l'on en est et mène là-bas pour tout geste —
 * répondre, régler, voir les autres participants. Elle reprend la voix du site
 * de Noël (le tutoiement), puisqu'elle parle de lui.
 *
 * Deux moments :
 *
 * - **ouvert** : le compte à rebours jusqu'au tirage, ma réponse en pastille
 *   (« Tu participes », « Tu ne participes pas »), ou « Tu n'as pas encore
 *   répondu » avec un bouton « Répondre » qui ouvre le site de Noël ;
 * - **tiré**, et j'y suis : « Découvrir à qui j'offre ». **Le receveur est un
 *   secret** et l'accueil s'ouvre volontiers devant d'autres : son nom n'est
 *   demandé au serveur (`mon-attribution`) et n'entre dans le DOM **qu'au
 *   geste**, et « Cacher » le retire. Une fois découvert : sa photo, son nom,
 *   son âge, le lien de parenté, et le budget pour rappel.
 *
 * Le compte à rebours suit ce qui compte maintenant, en gros chiffres à droite
 * (`compteurDeLaCarte`, refonte du 30/10/2026) : le tirage tant qu'il est
 * ouvert, la remise des cadeaux ensuite. Les deux dates restent écrites sous
 * la carte tant que le tirage est ouvert. Suite : `carte-noel-accueil-rendu.test.ts`.
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import { estErreurApi, lireMonAttributionDeNoel, type AttributionDeNoel, type ResumeDuTirageDeNoel } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import {
  LIBELLES_DE_REPONSE,
  aUnReceveur,
  adresseSurLeSiteDeNoel,
  budgetEnEuros,
  compteARebours,
  compteurDeLaCarte,
  dateCourte,
  etatDeMaReponse,
  lienLisible,
  lireInstant,
  participants,
} from '@/components/evenements-familiaux/noel'

const { tirage, aujourdhui } = defineProps<{
  tirage: ResumeDuTirageDeNoel
  aujourdhui: Date
}>()

const adresse = computed(() => adresseSurLeSiteDeNoel(tirage.code))
const etat = computed(() => etatDeMaReponse(tirage))
const dateDuTirage = computed(() => lireInstant(tirage.dateTirage))
const dateDeRemise = computed(() => lireInstant(tirage.dateEvenement))
const compteur = computed(() => compteurDeLaCarte(tirage, aujourdhui))
const tire = computed(() => tirage.statut === 'tire')

/** « Noël · tirage fait · vous organisez ». */
const nature = computed(() =>
  ['Noël', tire.value ? 'tirage fait' : null, tirage.estCreateur ? 'vous organisez' : null].filter(Boolean).join(' · '),
)

/** « Tirage 4 nov. · cadeaux 25 déc. » */
const deuxDates = computed(() => {
  const morceaux: string[] = []
  if (dateDuTirage.value) morceaux.push(`${tire.value ? 'Tiré le' : 'Tirage'} ${dateCourte(dateDuTirage.value)}`)
  if (dateDeRemise.value) morceaux.push(`cadeaux ${dateCourte(dateDeRemise.value)}`)
  return morceaux.join(' · ')
})

/** Les informations pratiques, en une ligne : où, combien, quel budget. */
const infos = computed(() => {
  const morceaux: string[] = []
  if (tirage.lieu) morceaux.push(tirage.lieu)
  morceaux.push(participants(tirage.nbParticipants))
  if (tirage.budget !== null) morceaux.push(`budget ${budgetEnEuros(tirage.budget)}`)
  return morceaux.join(' · ')
})

const CLASSES_DE_REPONSE = {
  participe: 'bg-ok-doux text-ok',
  a_repondre: 'bg-alerte-doux text-alerte',
  ne_participe_pas: 'border border-ligne text-attenue',
  hors_tirage: 'border border-ligne text-attenue',
} as const

/* ───────────────── Découvrir à qui j'offre — au geste seulement ───────────────── */

const attribution = ref<AttributionDeNoel | null>(null)
const revele = ref(false)
const enCours = ref(false)
const erreur = ref<unknown>(null)
/** `404` : le serveur ne me connaît pas de receveur (retiré du tirage après coup). */
const sansReceveur = ref(false)
let avorteur: AbortController | null = null

async function decouvrir(): Promise<void> {
  if (attribution.value !== null) {
    revele.value = true
    return
  }
  avorteur?.abort()
  avorteur = new AbortController()
  enCours.value = true
  erreur.value = null
  sansReceveur.value = false
  try {
    attribution.value = await lireMonAttributionDeNoel(tirage.code, avorteur.signal)
    revele.value = true
  } catch (e) {
    if (avorteur.signal.aborted) return
    if (estErreurApi(e) && e.statut === 404) sansReceveur.value = true
    else erreur.value = e
  } finally {
    enCours.value = false
  }
}

/** Cacher retire le nom du DOM : la carte redevient montrable par-dessus l'épaule. */
function cacher(): void {
  revele.value = false
}

const initiales = computed(() => {
  const a = attribution.value
  if (a === null) return ''
  return `${a.prenom.charAt(0)}${a.nom.charAt(0)}`.toUpperCase()
})

onBeforeUnmount(() => avorteur?.abort())
</script>

<template>
  <article
    class="flex flex-col gap-3.5 rounded-panneau border bg-panneau p-4 lg:gap-4 lg:p-5"
    :class="tire ? 'border-cadeau/40' : 'border-ligne'"
    :aria-label="`Tirage de Noël : ${tirage.titre}`"
    data-carte-noel
  >
    <!-- ───────── L'en-tête : la nature, le titre, le compte à rebours ───────── -->
    <div class="flex items-start gap-3">
      <a :href="adresse" class="flex min-w-0 flex-1 flex-col gap-0.5">
        <span class="text-[11px] font-semibold text-cadeau lg:text-xs">{{ nature }}</span>
        <span class="text-[15px] font-semibold text-texte lg:text-base">{{ tirage.titre }}</span>
        <span class="truncate text-xs text-attenue">{{ infos }}</span>
      </a>
      <div v-if="compteur !== null" class="flex flex-none flex-col text-right" data-compteur>
        <span class="tnum text-[22px] leading-none font-semibold lg:text-[26px]" :class="tire ? 'text-cadeau' : 'text-texte'">{{ compteur.nombre }}</span>
        <span class="mt-0.5 text-[11px] text-attenue">{{ compteur.sous }}</span>
      </div>
      <span v-else class="flex-none text-xs text-attenue">{{ compteARebours(tirage, aujourdhui) }}</span>
    </div>

    <!-- ───────── Tiré, et j'y suis : le secret, au geste ───────── -->
    <template v-if="aUnReceveur(tirage)">
      <div
        v-if="revele && attribution !== null"
        class="flex items-center gap-3 rounded-champ bg-cadeau-doux px-3 py-2.5 lg:p-3"
        data-attribution
        aria-live="polite"
      >
        <img
          v-if="attribution.photoUrl"
          :src="attribution.photoUrl"
          alt=""
          class="size-9 flex-none rounded-full object-cover lg:size-10"
        />
        <span
          v-else
          class="flex size-9 flex-none items-center justify-center rounded-full bg-cadeau text-[13px] font-semibold text-cadeau-texte lg:size-10 lg:text-sm"
          aria-hidden="true"
        >
          {{ initiales }}
        </span>
        <span class="flex min-w-0 flex-1 flex-col">
          <span class="text-[11px] font-semibold text-cadeau">Tu offres à</span>
          <span class="truncate font-semibold text-texte">{{ attribution.prenom }} {{ attribution.nom }}</span>
          <span class="text-xs text-attenue">
            <template v-if="attribution.lien">{{ lienLisible(attribution.lien) }}</template>
            <template v-if="attribution.lien && attribution.age != null"> · </template>
            <template v-if="attribution.age != null">{{ attribution.age }} ans</template>
            <template v-if="tirage.budget !== null"><template v-if="attribution.lien || attribution.age != null"> · </template>budget {{ budgetEnEuros(tirage.budget) }}</template>
          </span>
        </span>
        <button type="button" class="min-h-11 shrink-0 px-2 text-xs text-attenue underline hover:text-texte" @click="cacher">Cacher</button>
      </div>

      <button
        v-else
        type="button"
        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-champ bg-cadeau px-4 text-sm font-semibold text-cadeau-texte transition-opacity hover:opacity-90 disabled:opacity-55"
        :disabled="enCours"
        :aria-busy="enCours"
        data-decouvrir
        @click="decouvrir"
      >
        <span v-if="enCours" class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true" />
        Découvrir à qui j’offre
      </button>
      <p v-if="sansReceveur" class="text-xs text-attenue" role="status">Aucun receveur ne t’est attribué dans ce tirage.</p>
      <MessageErreur v-if="erreur !== null" :erreur="erreur" titre="Impossible de savoir à qui tu offres" @action="decouvrir" />

      <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-attenue">
        <span>Le nom ne s’affiche qu’à ta demande.</span>
        <a :href="adresse" class="text-cadeau hover:underline" data-ouvrir-noel>Site de Noël ↗</a>
      </div>
    </template>

    <!-- ───────── Sinon : ma réponse, et le geste qu'elle attend ───────── -->
    <template v-else>
      <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
        <span class="inline-flex items-center gap-1.5 rounded-pastille px-2.5 py-[3px] text-xs font-semibold" :class="CLASSES_DE_REPONSE[etat]" data-reponse>
          <svg v-if="etat === 'participe'" width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 10.5l4 4 8-9" />
          </svg>
          {{ LIBELLES_DE_REPONSE[etat] }}
        </span>
        <span class="text-attenue" data-dates>{{ deuxDates }}</span>
      </div>
      <a
        v-if="etat === 'a_repondre'"
        :href="adresse"
        class="inline-flex min-h-11 items-center justify-center rounded-champ bg-cadeau px-4 text-sm font-semibold text-cadeau-texte transition-opacity hover:opacity-90"
        data-repondre
      >
        Répondre
      </a>
      <!-- Sur téléphone, le titre y mène déjà : la ligne de plus ne servirait qu'au bureau. -->
      <a v-else :href="adresse" class="hidden self-end text-xs text-cadeau hover:underline lg:block" data-ouvrir-noel>Site de Noël ↗</a>
    </template>
  </article>
</template>
