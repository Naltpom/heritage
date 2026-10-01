<script setup lang="ts">
/**
 * Un tirage suivi par **lien personnel**, sans compte (`/t/<clé>`).
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ce qu'on voit ici est exactement ce que la clé ouvre, et rien d'autre.**
 *
 * Le nom du tirage, la date et le lieu de l'année, sa propre présence, et —
 * une fois le tirage fait — son receveur. Pas la liste des participants, rien
 * de l'arbre : le serveur ne le rend pas, l'écran n'a donc rien à cacher.
 * ────────────────────────────────────────────────────────────────────
 *
 * Trois choix, repris de l'accueil (`CarteTirageAccueil`) :
 *
 * - **le cadeau d'abord.** Une fois le tirage fait, le bouton violet « Voir à
 *   qui je fais le cadeau » est la première chose sous le titre : c'est la
 *   seule raison d'ouvrir ce lien ce jour-là ;
 * - **le nom est absent du DOM jusqu'au geste**, pas estompé : un téléphone se
 *   montre volontiers à table. Le geste n'est pas mémorisé, recharger remasque ;
 * - **la présence se règle d'un geste** (Sur place / À distance / Ne participe
 *   pas), tant que le serveur le permet (`peutChangerPresence`). Une fois le
 *   tirage fait, en changer changerait ce que l'autre reçoit : ce geste-là
 *   revient à l'organisateur.
 *
 * Le composant **ne charge rien** : la vue (`TirageLienView`) lit la clé et lui
 * passe un état. Il se rend donc côté serveur dans chacun de ses états — suite :
 * `suivi-par-lien-rendu.test.ts`.
 */
import { computed, ref } from 'vue'
import type { LectureParLien, PresenceAuTirage } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import Segmente from '@/components/mise-en-page/Segmente.vue'
import {
  PRESENCES,
  boutonDeCadeauParLien,
  consigneDeRemise,
  dateLongue,
  etatVuParLien,
} from './tirages'

export type EtatDuLien =
  | { type: 'chargement' }
  /** `404` : clé inconnue, révoquée ou remplacée — l'écran ne les distingue pas. */
  | { type: 'invalide' }
  | { type: 'erreur'; erreur: unknown }
  | { type: 'pret'; lecture: LectureParLien }

const {
  etat,
  enregistrement = false,
  erreurDePresence = null,
} = defineProps<{
  etat: EtatDuLien
  enregistrement?: boolean
  erreurDePresence?: unknown
}>()

const emit = defineEmits<{ presence: [presence: PresenceAuTirage]; reessayer: [] }>()

const devoiles = ref(new Set<string>())

function devoiler(cle: string): void {
  devoiles.value = new Set(devoiles.value).add(cle)
}

/** La lecture chargée, hors du rétrécissement de type du gabarit. */
const lecture = computed<LectureParLien | null>(() => (etat.type === 'pret' ? etat.lecture : null))

function choisir(presence: PresenceAuTirage): void {
  if (enregistrement || lecture.value === null || presence === lecture.value.participant.presence) return
  emit('presence', presence)
}

const LIBELLES: Record<PresenceAuTirage, string> = {
  sur_place: 'Sur place',
  a_distance: 'À distance',
  absente: 'Ne participe pas',
}
</script>

<template>
  <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col gap-5 px-4 py-8">
    <p class="text-xs font-semibold tracking-wide text-attenue uppercase">Tirage au sort · Heritage</p>

    <SqueletteChargement v-if="etat.type === 'chargement'" :lignes="4" />

    <section
      v-else-if="etat.type === 'invalide'"
      class="flex flex-col gap-2 rounded-panneau border border-ligne bg-panneau p-5"
    >
      <h1 class="text-lg font-semibold text-texte">Ce lien n’est plus valable</h1>
      <p class="text-sm text-texte">
        Il a peut-être été remplacé par un nouveau lien, ou révoqué. Demandez à la personne qui
        vous l’a envoyé de vous en donner un autre.
      </p>
    </section>

    <MessageErreur
      v-else-if="etat.type === 'erreur'"
      :erreur="etat.erreur"
      titre="Le tirage n’a pas pu être chargé"
      @action="emit('reessayer')"
    />

    <template v-else>
      <header class="flex flex-col gap-1">
        <h1 class="text-xl font-semibold text-texte">{{ etat.lecture.tirage.nom }}</h1>
        <p v-if="etat.lecture.edition !== null" class="text-sm text-texte">
          {{ dateLongue(etat.lecture.edition.date) }}<template v-if="etat.lecture.tirage.lieu"> · {{ etat.lecture.tirage.lieu }}</template>
        </p>
        <p v-else-if="etat.lecture.tirage.lieu" class="text-sm text-texte">{{ etat.lecture.tirage.lieu }}</p>
        <p class="text-sm text-attenue">Pour {{ etat.lecture.participant.libelle }}</p>
      </header>

      <!-- Le cadeau : en premier, et le nom absent du DOM jusqu'au geste. -->
      <div v-if="etat.lecture.edition?.statut === 'tiree' && etat.lecture.attributions.length > 0" class="flex flex-col gap-2">
        <template v-for="a in etat.lecture.attributions" :key="`${a.donneur.personId}`">
          <div
            v-if="devoiles.has(String(a.donneur.personId))"
            class="rounded-panneau border border-cadeau bg-cadeau-doux p-4"
            aria-live="polite"
          >
            <p class="text-sm text-texte">
              {{ a.donneur.libelle === etat.lecture.participant.libelle ? 'Vous offrez un cadeau à' : `${a.donneur.libelle} offre un cadeau à` }}
            </p>
            <p class="text-xl font-semibold text-cadeau">{{ a.receveur.libelle }}</p>
            <p class="mt-1 text-xs text-attenue">{{ consigneDeRemise(a.presenceDuDonneur) }}</p>
          </div>
          <button
            v-else
            type="button"
            class="flex w-full items-center justify-center gap-2 rounded-panneau bg-cadeau px-4 py-4 text-base font-semibold text-cadeau-texte shadow-sm transition-opacity hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cadeau"
            @click="devoiler(String(a.donneur.personId))"
          >
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true">
              <rect x="3" y="8" width="14" height="9" rx="1" /><path d="M2 5h16v3H2zM10 5v12" />
              <path d="M10 5C8 1 5 2 6.5 4.5 7.2 5.2 10 5 10 5zM10 5c2-4 5-3 3.5-.5C12.8 5.2 10 5 10 5z" />
            </svg>
            {{ boutonDeCadeauParLien(a, etat.lecture.participant.personId) }}
          </button>
        </template>
      </div>

      <p class="text-sm text-texte">{{ etatVuParLien(etat.lecture) }}</p>

      <!-- La présence : réglable d'un geste tant que le serveur le permet. -->
      <section v-if="etat.lecture.edition !== null" class="flex flex-col gap-2 rounded-panneau border border-ligne bg-panneau p-4">
        <template v-if="etat.lecture.participant.peutChangerPresence">
          <p class="text-xs font-semibold text-attenue">Ma présence en {{ etat.lecture.edition.annee }}</p>
          <Segmente
            :options="PRESENCES"
            :valeur="etat.lecture.participant.presence ?? 'sur_place'"
            etiquette="Ma présence"
            :colonnes="3"
            @choisir="choisir"
          />
          <p v-if="enregistrement" class="text-xs text-attenue" role="status">Enregistrement…</p>
        </template>
        <p v-else-if="etat.lecture.participant.presence !== null" class="text-sm text-texte">
          Ma présence : <span class="font-semibold">{{ LIBELLES[etat.lecture.participant.presence] }}</span>
          <span v-if="etat.lecture.edition.statut === 'tiree' && !etat.lecture.edition.passee" class="block text-xs text-attenue">
            Le tirage est fait : pour changer, prévenez l’organisateur.
          </span>
        </p>

        <MessageErreur v-if="erreurDePresence !== null" :erreur="erreurDePresence" titre="La présence n’a pas été enregistrée" />
      </section>

      <p class="mt-auto text-xs text-attenue">
        Ce lien est personnel : il donne accès à votre présence et à votre cadeau. Ne le transférez pas.
      </p>
    </template>
  </div>
</template>
