<script setup lang="ts">
/**
 * Un rassemblement sur l'accueil — **avec sa réponse, ou les deux boutons pour
 * la donner**. L'accueil en montre un par rassemblement à venir où l'on est
 * invité **ou** qu'on organise (section « Mes événements », 02/10/2026) ; pour
 * l'organisateur qui ne s'est pas invité, la carte dit « Vous organisez » et
 * n'a pas de boutons.
 *
 * Quand les dates ont changé depuis la réponse, la carte le dit et la réponse
 * marquée devient un bouton « je confirme » (`confirmable`).
 *
 * C'est la carte d'une annonce : le titre, quand, où, et d'emblée le geste
 * qu'elle attend. Pas de dépliement :
 * il n'y a qu'une chose à faire, répondre, et elle tient sur la carte. Une fois
 * répondu, la carte le dit en une ligne et garde l'autre bouton à portée — on
 * peut changer d'avis jusqu'à la fin de l'événement.
 *
 * **La carte vue, l'annonce l'est aussi.** Elle montre ce que la page montrerait :
 * la vue est donc posée côté serveur au montage (`marquerVu`), sauf sous la
 * bascule « voir comme un membre » — où le serveur l'ignorerait de toute façon.
 * C'est fait par la page d'accueil, pas ici : le composant ne charge ni n'écrit
 * rien, il émet. Suite : `carte-evenement-accueil-rendu.test.ts`.
 */
import { computed } from 'vue'
import type { ReponseAInvitation, ResumeDEvenement } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import BoutonsDeReponse from '@/components/evenements-familiaux/BoutonsDeReponse.vue'
import { DATES_MODIFIEES, bilan, periodeEnTete, phraseDeReponseNotee, quand } from '@/components/evenements-familiaux/evenements'

const {
  evenement,
  aujourdhui,
  enCours = null,
  erreur = null,
  desactive = false,
} = defineProps<{
  evenement: ResumeDEvenement
  aujourdhui: Date
  enCours?: ReponseAInvitation | null
  erreur?: unknown
  desactive?: boolean
}>()

const emit = defineEmits<{ repondre: [reponse: ReponseAInvitation] }>()

const question = computed(() => (evenement.maReponse === null ? 'Venez-vous ?' : 'Ma réponse'))

/** « Invitation », ou « Vous organisez » pour qui n'est pas invité à son propre événement. */
const nature = computed(() => (evenement.suisInvite ? 'Invitation' : 'Vous organisez'))
</script>

<template>
  <article class="flex flex-col gap-3 rounded-panneau border border-accent bg-panneau p-4" :aria-label="`${nature} : ${evenement.titre}`">
    <RouterLink :to="{ name: 'evenement.detail', params: { id: evenement.id } }" class="flex flex-col gap-0.5">
      <span class="text-xs font-semibold text-accent">Rassemblement · {{ nature }} · {{ quand(evenement, aujourdhui) }}</span>
      <span class="text-base font-semibold text-texte">{{ evenement.titre }}</span>
      <span class="text-sm text-texte">{{ periodeEnTete(evenement.debut, evenement.fin) }}</span>
      <span class="truncate text-xs text-attenue">
        <template v-if="evenement.lieu">{{ evenement.lieu }} · </template>{{ bilan(evenement.nombreDePresents, evenement.nombreDInvites) }}
      </span>
    </RouterLink>

    <div v-if="evenement.peutRepondre" class="flex flex-col gap-1.5">
      <p v-if="evenement.reponseAConfirmer" class="text-xs font-semibold text-alerte" role="status">{{ DATES_MODIFIEES }}</p>
      <p v-else class="text-xs font-semibold text-attenue">{{ question }}</p>
      <BoutonsDeReponse
        :reponse="evenement.maReponse"
        :en-cours="enCours"
        :desactive="desactive"
        :confirmable="evenement.reponseAConfirmer"
        @choisir="(r) => emit('repondre', r)"
      />
      <p v-if="evenement.maReponseNoteePar !== null" class="text-xs text-attenue">{{ phraseDeReponseNotee(evenement.maReponseNoteePar) }}</p>
    </div>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" titre="La réponse n’a pas été enregistrée" />

    <RouterLink :to="{ name: 'evenement.detail', params: { id: evenement.id } }" class="self-start text-xs text-accent underline">
      Voir l’annonce et qui vient
    </RouterLink>
  </article>
</template>
