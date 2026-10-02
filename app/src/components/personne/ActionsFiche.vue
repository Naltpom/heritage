<script setup lang="ts">
/**
 * Les gestes de la fiche : **quatre icônes**, ou deux en mode édition.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Ce qu'elles remplacent (refonte du 02/10/2026)
 *
 * Le bouton « Proposer » ouvrait un menu de trois entrées. Chacune a désormais sa
 * propre icône, à la même place — dans l'en-tête sur ordinateur, dans la barre
 * fixée en bas sur téléphone :
 *
 * | Icône | Geste | Où elle mène |
 * |---|---|---|
 * | poubelle | demander la suppression | `proposition.suppression` — l'écran montre d'abord tout ce que la suppression emporterait |
 * | robot | l'assistant | `proposition.creation?personne=<id>`, pré-ciblé sur la fiche |
 * | document | joindre un document | l'onglet Sources, à la zone de dépôt (`#depot`) |
 * | crayon | **le mode édition** | ne mène nulle part : la fiche elle-même devient le formulaire |
 *
 * En mode édition, les quatre cèdent la place à deux : **Annuler** (on sort sans
 * rien envoyer) et **Corriger** (le récapitulatif, puis le dépôt). C'est
 * `PageFiche` qui tient l'état ; ce composant ne fait que le refléter et le
 * signaler.
 *
 * ## Une icône a toujours un nom
 *
 * `aria-label` pour les lecteurs d'écran, `title` pour le survol : une icône seule
 * ne se lit pas, et la poubelle d'un arbre généalogique ne dit pas d'elle-même
 * qu'elle ne supprime rien — qu'elle **demande**.
 *
 * ## Les conditions : celles de `gestes.ts`, chacune à sa place
 *
 * Rattaché, et pas sous la bascule « voir comme un membre ». Chaque icône porte la
 * sienne ; la rangée n'existe que si elle en a au moins une — c'est `PageFiche`
 * qui décide de la barre du bas sur la même règle. Masquer n'est pas une mesure de
 * sécurité : la garde de route le double, l'API double les deux.
 *
 * ## Un lien, et rien d'autre — surtout pas un état transmis
 *
 * Aucune icône ne transmet la `version` de la fiche : l'écran d'arrivée relit la
 * fiche à son ouverture. Le crayon, lui, prend son cliché **au clic** — c'est
 * `edition.ts` qui le garde.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import { useGestesFiche } from './gestes'
import { requeteDOnglet } from './onglets'

const {
  fiche,
  disposition = 'bandeau',
  enEdition = false,
  envoi = false,
} = defineProps<{
  fiche: FichePersonneComplete
  /** `bandeau` : icônes de 36 px (ordinateur). `barre` : 44 px, réparties (téléphone). */
  disposition?: 'bandeau' | 'barre'
  enEdition?: boolean
  envoi?: boolean
}>()

const emit = defineEmits<{ editer: []; annuler: []; corriger: [] }>()

const { peutProposer, peutDeposer, peutDemanderSuppression } = useGestesFiche()

const aDesGestes = computed(
  () => peutProposer.value || peutDeposer.value || peutDemanderSuppression.value,
)

const versSuppression = computed(() => ({ name: 'proposition.suppression', params: { id: fiche.id } }))
const versAssistant = computed(() => ({ name: 'proposition.creation', query: { personne: String(fiche.id) } }))
const versDepot = computed(() => ({
  name: 'personne.detail',
  params: { id: fiche.id },
  query: requeteDOnglet('sources'),
  hash: '#depot',
}))

const taille = computed(() => (disposition === 'barre' ? 'h-11 min-w-11 flex-1' : 'size-9'))
const BASE =
  'inline-flex items-center justify-center rounded-champ border transition-colors focus-visible:outline-2 focus-visible:outline-offset-2'
const NEUTRE = 'border-ligne bg-panneau text-texte hover:border-accent hover:text-accent'
</script>

<template>
  <div
    v-if="aDesGestes"
    class="flex items-center gap-2"
    :class="disposition === 'barre' ? 'w-full' : ''"
    role="group"
    :aria-label="enEdition ? 'Mode édition' : 'Gestes sur cette fiche'"
    data-actions-fiche
  >
    <template v-if="!enEdition">
      <RouterLink
        v-if="peutDemanderSuppression"
        :to="versSuppression"
        data-geste="demander-suppression"
        aria-label="Demander la suppression de cette fiche"
        title="Demander la suppression de cette fiche"
        :class="[BASE, taille, 'border-alerte-bord bg-panneau text-alerte hover:border-alerte hover:bg-alerte-doux']"
      >
        <svg viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12M9 7V4h6v3" />
        </svg>
      </RouterLink>

      <RouterLink
        v-if="peutProposer"
        :to="versAssistant"
        data-geste="assistant"
        aria-label="Demander à l’assistant"
        title="Dire à l’assistant ce qu’il faut corriger ou ajouter"
        :class="[BASE, taille, NEUTRE]"
      >
        <svg viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="4" y="8" width="16" height="12" rx="3" />
          <path d="M12 8V4M9 4h6M9 13.5h.01M15 13.5h.01M9.5 17h5M2 13v3M22 13v3" />
        </svg>
      </RouterLink>

      <RouterLink
        v-if="peutDeposer"
        :to="versDepot"
        data-geste="joindre-document"
        aria-label="Joindre un document"
        title="Joindre un acte, une photo ou une preuve"
        :class="[BASE, taille, NEUTRE]"
      >
        <svg viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
          <path d="M14 3v5h5M12 17v-6M9.5 13.5 12 11l2.5 2.5" />
        </svg>
      </RouterLink>

      <button
        v-if="peutProposer"
        type="button"
        data-geste="editer"
        aria-label="Corriger la fiche"
        title="Corriger la fiche : rien n’est envoyé avant « Corriger »"
        :class="[BASE, taille, 'border-edition bg-edition text-edition-texte hover:opacity-90']"
        @click="emit('editer')"
      >
        <svg viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z" />
          <path d="m13.5 6.5 4 4" />
        </svg>
      </button>
    </template>

    <template v-else>
      <button
        type="button"
        data-geste="annuler-edition"
        aria-label="Annuler les corrections"
        title="Quitter sans rien envoyer"
        :disabled="envoi"
        :class="[BASE, taille, NEUTRE, 'disabled:opacity-55']"
        @click="emit('annuler')"
      >
        <svg viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <path d="M6 6l12 12M18 6 6 18" />
        </svg>
      </button>
      <button
        type="button"
        data-geste="corriger"
        aria-label="Corriger : relire puis envoyer"
        title="Relire puis envoyer les changements"
        :disabled="envoi"
        :class="[BASE, taille, 'border-edition bg-edition text-edition-texte hover:opacity-90 disabled:opacity-55']"
        @click="emit('corriger')"
      >
        <svg viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="m5 12.5 4.5 4.5L19 7.5" />
        </svg>
      </button>
    </template>
  </div>
</template>
