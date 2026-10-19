<script setup lang="ts">
/**
 * Un tirage de Noël dans la liste « Événements » — une ligne, qui mène au site
 * de Noël (noel2026) : c'est là qu'on répond et qu'on règle, heritage ne fait
 * que montrer.
 *
 * Ce qu'on y lit : le titre, le jour de la remise des cadeaux (celui qui range
 * la ligne, `liste-commune.ts`), le compte à rebours qui compte maintenant
 * (« Tirage dans 12 jours », puis celui des cadeaux), le lieu, le nombre de
 * participants, le budget, et ma réponse en pastille. **Rien n'y nomme à qui
 * j'offre** : la liste s'ouvre volontiers devant d'autres ; le secret se
 * découvre sur l'accueil (`CarteNoelAccueil`) ou sur le site de Noël.
 *
 * Suite : `ligne-noel-rendu.test.ts`.
 */
import { computed } from 'vue'
import type { ResumeDuTirageDeNoel } from '@/lib/api'
import IconeCadeau from './IconeCadeau.vue'
import PastilleDeType from './PastilleDeType.vue'
import {
  adresseSurLeSiteDeNoel,
  budgetEnEuros,
  compteARebours,
  dateLongue,
  etatDeMaReponse,
  lireInstant,
  participants,
} from './noel'

const { tirage, aujourdhui } = defineProps<{ tirage: ResumeDuTirageDeNoel; aujourdhui: Date }>()

const remise = computed(() => lireInstant(tirage.dateEvenement))

const details = computed(() => {
  const morceaux = [compteARebours(tirage, aujourdhui)]
  if (tirage.lieu) morceaux.push(tirage.lieu)
  morceaux.push(participants(tirage.nbParticipants))
  if (tirage.budget !== null) morceaux.push(`budget ${budgetEnEuros(tirage.budget)}`)
  return morceaux.join(' · ')
})

/** La pastille de ma réponse — courte : la ligne est étroite sur téléphone. */
const reponse = computed<{ texte: string; classe: string } | null>(() => {
  switch (etatDeMaReponse(tirage)) {
    case 'participe':
      return { texte: 'Tu participes', classe: 'bg-ok-doux text-ok' }
    case 'a_repondre':
      return { texte: 'À répondre', classe: 'bg-cadeau text-cadeau-texte' }
    case 'ne_participe_pas':
      return { texte: 'Tu ne participes pas', classe: 'border border-ligne text-attenue' }
    default:
      return null
  }
})
</script>

<template>
  <a
    :href="adresseSurLeSiteDeNoel(tirage.code)"
    class="flex items-center gap-3 rounded-panneau border border-cadeau/40 bg-panneau p-4 transition-colors hover:border-cadeau"
    data-ligne-noel
  >
    <span class="hidden size-11 flex-none items-center justify-center rounded-champ bg-cadeau-doux text-cadeau sm:flex" aria-hidden="true">
      <IconeCadeau :taille="22" />
    </span>
    <span class="min-w-0 flex-1">
      <PastilleDeType type="noel" />
      <span class="mt-1 block truncate font-semibold text-texte">{{ tirage.titre }}</span>
      <span class="mt-0.5 block text-sm text-texte">Cadeaux le {{ remise ? dateLongue(remise) : '—' }}</span>
      <span class="mt-0.5 block truncate text-xs text-attenue">{{ details }}</span>
    </span>
    <span class="flex shrink-0 flex-col items-end gap-1">
      <span v-if="reponse" class="rounded-pastille px-2 py-0.5 text-xs font-semibold" :class="reponse.classe">{{ reponse.texte }}</span>
      <span v-if="tirage.estCreateur" class="rounded-pastille bg-cadeau-doux px-2 py-0.5 text-xs text-texte">Vous organisez</span>
      <span class="inline-flex items-center gap-1 text-xs text-cadeau">
        Site de Noël
        <svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M8 4H4v12h12v-4M11 3h6v6M17 3l-8 8" />
        </svg>
      </span>
    </span>
  </a>
</template>
