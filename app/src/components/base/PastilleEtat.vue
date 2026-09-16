<script setup lang="ts">
/**
 * Pastille d'état.
 *
 * La plus importante du produit est **« en attente de validation »** : c'est
 * elle qui distingue, à l'écran, une valeur proposée d'une valeur validée
 * (§ 13.2 du contrat). La règle d'affichage tient en une phrase : *si
 * `enAttente[champ]` existe, afficher `propose` avec cette pastille, et rendre
 * `actuel` accessible ; sinon, afficher `fiche[champ]`.*
 *
 * Les valeurs d'état sont celles du modèle, **jamais traduites ni recasées**
 * (§ 1.2) : `en_attente`, `validee`, `refusee`, `caduque`, `actif`. Le libellé
 * français est ici ; la valeur reste celle qui part en base.
 *
 * `caduque` et `refusee` ont deux couleurs parce qu'ils ont deux sens : personne
 * n'a refusé une demande caduque, le monde a bougé (ADR 0004). « Deux statuts,
 * deux notifications, deux écrans » — et deux pastilles.
 */
import { computed } from 'vue'

type Etat =
  | 'en_attente'
  | 'validee'
  | 'refusee'
  | 'caduque'
  | 'actif'
  | 'suspendu'
  | 'neutre'

const { etat, libelle = '' } = defineProps<{
  etat: Etat
  /** Remplace le libellé par défaut. La valeur d'état reste celle du modèle. */
  libelle?: string
}>()

const LIBELLES: Record<Etat, string> = {
  en_attente: 'En attente de validation',
  validee: 'Validée',
  refusee: 'Refusée',
  // Ni « refusée », ni « expirée » : la fiche a changé sous la demande.
  caduque: 'Devenue caduque',
  actif: 'Actif',
  suspendu: 'Suspendu',
  neutre: '',
}

const TONS: Record<Etat, string> = {
  en_attente: 'bg-accent-doux text-accent border-accent',
  validee: 'bg-ok-doux text-ok border-ok-bord',
  refusee: 'bg-alerte-doux text-alerte border-alerte-bord',
  caduque: 'bg-transparent text-attenue border-ligne',
  actif: 'bg-ok-doux text-ok border-ok-bord',
  suspendu: 'bg-alerte-doux text-alerte border-alerte-bord',
  neutre: 'bg-transparent text-attenue border-ligne',
}

const texte = computed(() => (libelle.length > 0 ? libelle : LIBELLES[etat]))
</script>

<template>
  <span
    class="inline-flex items-center gap-1 rounded-pastille border px-2 py-0.5 text-[11.5px] font-semibold whitespace-nowrap"
    :class="TONS[etat]"
    :data-etat="etat"
  >
    <slot>{{ texte }}</slot>
  </span>
</template>
