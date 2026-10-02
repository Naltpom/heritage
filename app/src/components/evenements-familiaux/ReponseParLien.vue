<script setup lang="ts">
/**
 * Une invitation ouverte par **lien personnel**, sans compte (`/e/<clé>`).
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ce qu'on voit ici est exactement ce que la clé ouvre, et rien d'autre.**
 *
 * L'annonce — titre, dates, lieu, description —, le nom de l'invité, et ses
 * deux boutons. Pas la liste des invités, pas qui vient, pas l'organisateur,
 * rien de l'arbre : le serveur ne le rend pas, l'écran n'a donc rien à cacher.
 * Un lien transféré par erreur n'apprend rien de la famille à qui le reçoit.
 * ────────────────────────────────────────────────────────────────────
 *
 * Ouvrir cette page **marque l'annonce comme vue** côté serveur : la personne
 * n'a rien à cliquer pour que l'organisateur sache qu'elle l'a lue. Elle n'a
 * qu'un geste à faire — Présent ou Absent —, en grand, sous l'annonce.
 *
 * Le composant **ne charge rien** : la vue (`EvenementLienView`) lit la clé et
 * lui passe un état. Il se rend donc côté serveur dans chacun de ses états —
 * suite : `reponse-par-lien-rendu.test.ts`.
 */
import { computed } from 'vue'
import type { AnnonceParLien, ReponseAInvitation } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BoutonsDeReponse from './BoutonsDeReponse.vue'
import { DATES_MODIFIEES, periodeEnTete, phraseDeMaReponse } from './evenements'

export type EtatDuLienDEvenement =
  | { type: 'chargement' }
  /** `404` : clé inconnue, révoquée ou remplacée — l'écran ne les distingue pas. */
  | { type: 'invalide' }
  | { type: 'erreur'; erreur: unknown }
  | { type: 'pret'; lecture: AnnonceParLien }

const {
  etat,
  enCours = null,
  erreurDeReponse = null,
} = defineProps<{
  etat: EtatDuLienDEvenement
  enCours?: ReponseAInvitation | null
  erreurDeReponse?: unknown
}>()

const emit = defineEmits<{ repondre: [reponse: ReponseAInvitation]; reessayer: [] }>()

/** La lecture chargée, hors du rétrécissement de type du gabarit. */
const lecture = computed<AnnonceParLien | null>(() => (etat.type === 'pret' ? etat.lecture : null))
</script>

<template>
  <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col gap-5 px-4 py-8">
    <p class="text-xs font-semibold tracking-wide text-attenue uppercase">Invitation · Heritage</p>

    <SqueletteChargement v-if="etat.type === 'chargement'" :lignes="4" />

    <section
      v-else-if="etat.type === 'invalide'"
      class="flex flex-col gap-2 rounded-panneau border border-ligne bg-panneau p-5"
    >
      <h1 class="text-lg font-semibold text-texte">Ce lien n’est plus valable</h1>
      <p class="text-sm text-texte">
        Demandez-en un autre à la personne qui vous l’a envoyé.
      </p>
    </section>

    <MessageErreur
      v-else-if="etat.type === 'erreur'"
      :erreur="etat.erreur"
      titre="L’invitation n’a pas pu être chargée"
      @action="emit('reessayer')"
    />

    <template v-else-if="lecture !== null">
      <header class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold text-texte" :class="lecture.evenement.annule ? 'line-through decoration-2' : ''">
          {{ lecture.evenement.titre }}
        </h1>
        <p class="text-base text-texte">{{ periodeEnTete(lecture.evenement.debut, lecture.evenement.fin) }}</p>
        <p v-if="lecture.evenement.lieu" class="text-sm text-texte">{{ lecture.evenement.lieu }}</p>
        <p class="text-sm text-attenue">Pour {{ lecture.invite.libelle }}</p>
      </header>

      <p v-if="lecture.evenement.annule" class="rounded-panneau border border-alerte-bord bg-alerte-doux p-4 text-sm font-semibold text-alerte" role="status">
        Cet événement est annulé.
      </p>

      <p v-if="lecture.evenement.description" class="text-[15px] leading-relaxed whitespace-pre-line text-texte">
        {{ lecture.evenement.description }}
      </p>

      <section class="flex flex-col gap-2 rounded-panneau border border-ligne bg-panneau p-4">
        <template v-if="lecture.invite.peutRepondre">
          <!-- Sans compte, pas de notification : c'est ici seulement que l'invité
               apprend que les dates ont bougé depuis sa réponse. -->
          <p
            v-if="lecture.invite.aConfirmer"
            class="rounded-panneau border border-alerte-bord bg-alerte-doux p-3 text-sm font-semibold text-alerte"
            role="status"
          >
            {{ DATES_MODIFIEES }}
          </p>
          <p class="text-sm font-semibold text-texte">Venez-vous ?</p>
          <BoutonsDeReponse
            :reponse="lecture.invite.reponse"
            :en-cours="enCours"
            :confirmable="lecture.invite.aConfirmer"
            @choisir="(r) => emit('repondre', r)"
          />
          <p class="text-xs text-attenue">
            {{ lecture.invite.reponse === null ? 'Un geste suffit. Vous pourrez changer d’avis jusqu’à la fin de l’événement.' : 'Vous pouvez changer d’avis jusqu’à la fin de l’événement.' }}
          </p>
        </template>
        <p v-else class="text-sm text-texte">
          {{ phraseDeMaReponse(lecture.invite.reponse) }}
          <span v-if="lecture.evenement.passe && !lecture.evenement.annule" class="block text-xs text-attenue">L’événement est terminé.</span>
        </p>

        <MessageErreur v-if="erreurDeReponse !== null" :erreur="erreurDeReponse" titre="La réponse n’a pas été enregistrée" />
      </section>

      <p class="mt-auto text-xs text-attenue">
        Ce lien est personnel : ne le transférez pas.
      </p>
    </template>
  </div>
</template>
