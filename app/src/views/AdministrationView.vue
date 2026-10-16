<script setup lang="ts">
/**
 * Administration — le cadre du **mode administration** (handoff « mode
 * Administration », option 1c), et rien d'autre.
 *
 * Les sous-écrans sont des **routes enfants** (`app/README.md`, « Ajouter une
 * route ») : À traiter, Utilisateurs, Invitations, Sources, et l'historique des
 * demandes. Chacun a son chemin, donc son lien profond. La navigation entre eux
 * n'est plus ici (les six onglets d'`OngletsAdministration` ont disparu) : elle
 * est celle de l'application elle-même, qui, en mode admin, montre les quatre
 * entrées de l'administration — barre du bas sur téléphone, onglets de
 * l'en-tête sur bureau.
 *
 * Ce cadre pose ce que les écrans partagent :
 *
 * - sur bureau, le **titre** de l'écran (22 px) et sa description, en tête d'une
 *   colonne de 1 080 px ; sur téléphone, le titre est dans l'en-tête ;
 * - le **toast** des décisions (`composables/toast.ts`), en bas au centre,
 *   au-dessus de la barre du bas.
 *
 * Tout ce qui écrit vit dans les enfants, et ce qui suspend les écritures — la
 * bascule « voir comme un membre » — est dit par le bandeau permanent du socle.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useToast } from '@/composables/toast'

const route = useRoute()
const { message } = useToast()

const DESCRIPTIONS: Record<string, string> = {
  'administration.a-traiter': 'Demandes, inscriptions à rattacher et demandes RGPD, dans une seule file.',
  'administration.demandes':
    'Toutes les demandes des membres, traitées comprises : validées, refusées, devenues caduques.',
  'administration.utilisateurs': 'Comptes, rattachements, rôles et quotas.',
  'administration.invitations': 'On n’entre sur l’arbre que par un lien d’invitation.',
  'administration.sources': 'Les notices des fonds cités par les fiches.',
}

const titre = computed(() => (typeof route.meta.titre === 'string' ? route.meta.titre : ''))
const description = computed(() => DESCRIPTIONS[String(route.name ?? '')] ?? '')
</script>

<template>
  <div class="mx-auto flex w-full max-w-[1080px] flex-col gap-[18px] px-4 pt-4 pb-6 md:px-8 md:pt-7">
    <div class="flex flex-col gap-0.5 max-md:hidden">
      <h2 class="text-[22px] font-bold tracking-[-.01em]">{{ titre }}</h2>
      <p v-if="description" class="text-sm text-attenue">{{ description }}</p>
    </div>

    <!-- Le tableau de suivi s'ouvre d'ici, sur bureau comme sur téléphone. -->
    <RouterLink
      v-if="route.name === 'administration.a-traiter'"
      :to="{ name: 'administration.suivi' }"
      class="self-start text-sm text-accent underline"
      data-vers-suivi
    >
      Suivi en direct ›
    </RouterLink>

    <RouterView />
  </div>

  <!-- Au-dessus de la barre du bas (téléphone), en bas au centre. -->
  <div
    v-if="message !== null"
    role="status"
    class="toast-administration fixed left-1/2 z-50 max-w-[calc(100%-32px)] -translate-x-1/2 rounded-panneau bg-texte px-4 py-2.5 text-[13.5px] text-fond shadow-panneau"
  >
    {{ message }}
  </div>
</template>

<style scoped>
.toast-administration {
  bottom: calc(var(--hauteur-barre-bas, 0px) + 24px);
}
</style>
