<script setup lang="ts">
/**
 * « Présent » / « Absent » — la réponse d'un invité, en deux grands boutons.
 *
 * Deux et pas trois : pas de « peut-être » (demande du commanditaire, voir
 * `Evenement\Enum\Reponse`). Chacun fait toute la moitié de la largeur et
 * 48 px de haut : c'est le geste pour lequel on ouvre l'annonce, il se fait du
 * pouce sans viser. La réponse courante est marquée (`aria-pressed`), et
 * l'autre reste à portée — on peut changer d'avis jusqu'à la fin de
 * l'événement.
 *
 * Le composant **émet sans retenir** : la page appelle l'API et repasse la
 * réponse enregistrée. Il sert à la page de l'événement, à la carte d'accueil
 * et au lien personnel. Suite : `boutons-de-reponse-rendu.test.ts`.
 *
 * **`confirmable`** : les dates ont changé depuis la réponse (ADR 0014,
 * addendum du 02/10/2026). Toucher la réponse **déjà marquée** la renvoie
 * alors — c'est « je confirme » —, au lieu de ne rien faire.
 */
import type { ReponseAInvitation } from '@/lib/api'

const { reponse, desactive = false, enCours = null, confirmable = false } = defineProps<{
  reponse: ReponseAInvitation | null
  desactive?: boolean
  /** La réponse courante est à confirmer : la toucher de nouveau l'envoie. */
  confirmable?: boolean
  /** La réponse en train de partir : son bouton tourne, l'autre attend. */
  enCours?: ReponseAInvitation | null
}>()

const emit = defineEmits<{ choisir: [reponse: ReponseAInvitation] }>()

const OPTIONS: { valeur: ReponseAInvitation; libelle: string; actif: string }[] = [
  { valeur: 'present', libelle: 'Présent', actif: 'border-ok bg-ok text-fond' },
  { valeur: 'absent', libelle: 'Absent', actif: 'border-texte bg-texte text-fond' },
]

function choisir(valeur: ReponseAInvitation): void {
  if (desactive || enCours !== null || (valeur === reponse && !confirmable)) return
  emit('choisir', valeur)
}
</script>

<template>
  <div class="grid grid-cols-2 gap-2" role="group" aria-label="Ma réponse">
    <button
      v-for="o in OPTIONS"
      :key="o.valeur"
      type="button"
      class="inline-flex min-h-12 items-center justify-center gap-2 rounded-panneau border px-3 text-base font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-55"
      :class="o.valeur === reponse ? o.actif : 'border-ligne bg-panneau text-texte hover:border-accent'"
      :aria-pressed="o.valeur === reponse"
      :aria-busy="enCours === o.valeur"
      :disabled="desactive || (enCours !== null && enCours !== o.valeur)"
      @click="choisir(o.valeur)"
    >
      <span
        v-if="enCours === o.valeur"
        class="size-4 animate-spin rounded-full border-2 border-current border-t-transparent"
        aria-hidden="true"
      />
      <svg v-else-if="o.valeur === reponse" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 8.5l3.2 3L13 4.5" />
      </svg>
      {{ o.libelle }}
    </button>
  </div>
</template>
