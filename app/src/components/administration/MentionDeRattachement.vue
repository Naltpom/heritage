<script setup lang="ts">
/**
 * La mention de rattachement d'une ligne de l'écran « Utilisateurs ».
 *
 * `rattachement.ts` décide **ce qu'il faut dire** ; ce composant décide
 * **comment cela se voit** — et c'est la seconde moitié qui a coûté : la charge
 * portait déjà `membre: null`, et le gabarit le jetait sous un `v-if`.
 *
 * ── Trois états, trois poids visuels ────────────────────────────────────────
 *
 * Le rattachement **actif** reste une ligne grise : c'est le cas normal, et le
 * mettre en valeur noierait les deux autres. Les deux états qui retombent sur
 * la racine sortent au contraire du flux du texte, dans un encadré — parce
 * qu'un administrateur qui balaie une liste de comptes ne lit pas les lignes
 * grises, il les survole.
 *
 * L'état **absent** porte le ton d'alerte et l'état **inactif** le ton d'accent
 * — le même que la pastille « en attente de validation ». Ce n'est pas une
 * nuance décorative : `inactif` a déjà son bouton de réparation sur la ligne,
 * `absent` n'en a aucun (il n'existe pas de route pour rattacher un compte
 * existant à une fiche). Le plus grave est celui qu'on ne peut pas refermer
 * d'un clic, et c'est celui qui doit crier le plus fort.
 *
 * `data-rattachement` porte l'état sur le balisage, comme `data-etat` sur
 * `PastilleEtat` : c'est la prise stable des tests de rendu, qui ne dépend ni
 * des classes utilitaires ni de la ponctuation des phrases.
 */
import { computed } from 'vue'
import type { MembreAdministration } from '@/lib/api'
import { mentionDeRattachement, type EtatDuRattachement } from './rattachement'

const { membre } = defineProps<{
  /** `null` **est** une valeur attendue : c'est le compte sans rattachement. */
  membre: MembreAdministration | null
}>()

const mention = computed(() => mentionDeRattachement(membre))

const TONS: Record<Exclude<EtatDuRattachement, 'actif'>, string> = {
  absent: 'border-alerte-bord bg-alerte-doux text-alerte',
  inactif: 'border-accent bg-accent-doux text-accent',
}
</script>

<template>
  <p
    v-if="mention.etat === 'actif'"
    class="mt-0.5 text-sm text-attenue"
    :data-rattachement="mention.etat"
  >
    {{ mention.etiquette }}
  </p>

  <div
    v-else
    class="mt-1.5 rounded-panneau border px-3 py-2 text-sm"
    :class="TONS[mention.etat]"
    :data-rattachement="mention.etat"
    role="status"
  >
    <p class="font-semibold">{{ mention.etiquette }}</p>
    <p v-if="mention.consequence !== null" class="mt-0.5">{{ mention.consequence }}</p>
  </div>
</template>
