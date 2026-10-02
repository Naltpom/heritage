<script setup lang="ts">
/**
 * Une liste de proches en GRILLE : la personne (lien), sa naissance, son décès.
 *
 * Les dates arrivent compactes du serveur (`naissance`, `deces` — « 1816 »,
 * « 5.10.1816 ») et **seulement si le cercle montre la personne** : hors cercle, les
 * clés sont absentes et la ligne n'a que le nom (masqué). Rien n'est recalculé ici.
 *
 * `trier` range par année de naissance, l'aîné en haut — pour une fratrie ou des
 * enfants. Une date inconnue va à la fin, dans l'ordre reçu.
 *
 * `versArbre` remplace le lien vers la fiche par un choix dans l'arbre (le volet de
 * `/arbre`) : on se promène de proche en proche sans quitter l'arbre.
 *
 * `principal` : le conjoint d'une union. Son nom s'écrit en gras, couleur du texte,
 * pour qu'on ne le confonde pas avec les enfants listés dessous, en liens d'accent.
 */
import { computed } from 'vue'

export interface Proche {
  personId: number
  displayName: string
  naissance?: string | null
  deces?: string | null
  /** Une précision courte après le nom (« adoption »). */
  mention?: string
}

const props = defineProps<{ proches: Proche[]; trier?: boolean; versArbre?: boolean; principal?: boolean }>()
const classeNom = computed(() =>
  props.principal ? 'font-semibold text-texte underline decoration-ligne underline-offset-2 hover:decoration-texte' : 'text-accent underline',
)
const emit = defineEmits<{ choisir: [personId: number] }>()

const annee = (d: string | null | undefined): number => {
  const m = typeof d === 'string' ? /\b(1[0-9]{3}|20[0-9]{2})\b/.exec(d) : null
  return m ? Number(m[1]) : Number.POSITIVE_INFINITY
}
const lignes = computed(() =>
  props.trier
    ? props.proches
        .map((p, i) => ({ p, i }))
        .sort((a, b) => annee(a.p.naissance) - annee(b.p.naissance) || a.i - b.i)
        .map(({ p }) => p)
    : props.proches,
)
</script>

<template>
  <ul class="m-0 grid list-none grid-cols-[minmax(0,1fr)_auto_auto] gap-x-3 p-0">
    <li v-for="p in lignes" :key="p.personId" class="col-span-3 grid grid-cols-subgrid items-baseline py-0.5">
      <span class="min-w-0 truncate">
        <button
          v-if="versArbre"
          type="button"
          class="text-left"
          :class="classeNom"
          @click="emit('choisir', p.personId)"
        >
          {{ p.displayName }}
        </button>
        <RouterLink v-else :to="{ name: 'personne.detail', params: { id: p.personId } }" :class="classeNom">
          {{ p.displayName }}
        </RouterLink>
        <span v-if="p.mention" class="text-xs text-attenue"> — {{ p.mention }}</span>
      </span>
      <span class="font-mono text-xs whitespace-nowrap text-attenue" :title="p.naissance ? 'Naissance' : undefined">
        <template v-if="p.naissance">° {{ p.naissance }}</template>
      </span>
      <span class="font-mono text-xs whitespace-nowrap text-attenue" :title="p.deces ? 'Décès' : undefined">
        <template v-if="p.deces">† {{ p.deces }}</template>
      </span>
    </li>
  </ul>
</template>
