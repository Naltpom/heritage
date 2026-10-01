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
 * **Le ton d'accent va à `inactif`, et le ton neutre à `absent`** — l'inverse
 * de ce que leurs noms suggèrent, et c'est délibéré. `absent` ne désigne en
 * pratique que le compte d'amorçage du propriétaire, qui retombe sur une racine
 * qui est justement sa fiche : il obtient le bon arbre par coïncidence. Le
 * peindre en rouge allumerait un voyant permanent sur la ligne du propriétaire
 * et userait l'attention avant la ligne qui la mérite. `inactif`, lui, désigne
 * un compte qui a une ligne `mbr_member` sans qu'elle serve de point de vue —
 * c'est le cas qui touche réellement un membre de la famille. Le raisonnement
 * complet, et ce qui l'a mesuré, sont dans `rattachement.ts`.
 *
 * Aucun des deux n'est masqué pour autant : les deux sortent du flux du texte
 * dans un encadré, et c'était tout l'objet du correctif.
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

/** La fiche visée, pour le lien — quand le cercle laisse voir son identifiant. */
const fiche = computed(() => {
  const id = membre?.personId
  if (id == null) return null
  const nom = membre?.personLabel
  return { id, libelle: nom != null && nom.trim() !== '' ? nom : `la fiche n° ${id}` }
})

const TONS: Record<Exclude<EtatDuRattachement, 'actif'>, string> = {
  absent: 'border-ligne text-attenue',
  inactif: 'border-accent bg-accent-doux text-accent',
}
</script>

<template>
  <!-- Rattaché : le nom de la fiche est un lien vers elle. -->
  <p
    v-if="mention.etat === 'actif'"
    class="mt-0.5 text-sm text-attenue"
    :data-rattachement="mention.etat"
  >
    <template v-if="fiche !== null">
      Rattaché à
      <RouterLink
        :to="{ name: 'personne.detail', params: { id: fiche.id } }"
        class="font-medium text-accent underline"
      >{{ fiche.libelle }}</RouterLink>
    </template>
    <template v-else>{{ mention.etiquette }}</template>
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
