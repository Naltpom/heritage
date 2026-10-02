<script setup lang="ts">
/**
 * Choisir les invités parmi les **vivants** de l'arbre — à la création de
 * l'événement, puis pour en ajouter.
 *
 * Pensé pour le pouce : chaque ligne et chaque raccourci fait 44 px de haut au
 * moins, la case se coche en touchant le nom, et la liste défile dans sa propre
 * zone pour que le compteur et la recherche restent sous les yeux.
 *
 * - **Le compteur** dit combien sont cochés — « 37 invités » — en gros, parce
 *   que c'est le chiffre qu'on vérifie avant d'envoyer.
 * - **La recherche** filtre par nom, accents et ordre des mots ignorés.
 * - **Les raccourcis** cochent un lot d'un coup : « Tous les vivants », puis
 *   une branche (« Romarin », « Tilleul »…, les noms de la légende de
 *   l'arbre). Un lot déjà entièrement coché se décoche du même geste.
 *
 * Ce que le composant propose est **ce que l'arbre montre déjà en clair** au
 * lecteur — voir l'en-tête de `invites.ts`. Il ne charge rien : la vue lui
 * passe les invitables, il rend tout, côté serveur compris (suite :
 * `selecteur-d-invites-rendu.test.ts`).
 */
import { computed, ref, useId } from 'vue'
import { basculer, filtrerParNom, groupesDisponibles, libelleDuGroupe, toutCoche, type Invitable } from './invites'
import { compteDInvites } from './evenements'

const { invitables, dejaInvites = [] } = defineProps<{
  invitables: readonly Invitable[]
  /** Déjà invités : ils ne sont plus proposés (l'ajout après coup). */
  dejaInvites?: readonly number[]
}>()

/** Les personnes cochées. Un tableau et non un `Set` : `v-model` doit pouvoir le comparer. */
const selection = defineModel<number[]>({ default: () => [] })

const id = useId()
const terme = ref('')

const proposes = computed(() => {
  const exclus = new Set(dejaInvites)
  return invitables.filter((i) => !exclus.has(i.personId))
})
const visibles = computed(() => filtrerParNom(proposes.value, terme.value))
const coches = computed(() => new Set(selection.value))
const groupes = computed(() => groupesDisponibles(proposes.value))

function idsDe(lot: readonly Invitable[]): number[] {
  return lot.map((i) => i.personId)
}

function appliquer(lot: readonly Invitable[]): void {
  const ids = idsDe(lot)
  selection.value = [...basculer(coches.value, ids, !toutCoche(coches.value, ids))]
}

function cocher(personId: number, coche: boolean): void {
  selection.value = [...basculer(coches.value, [personId], coche)]
}

const tousCoches = computed(() => toutCoche(coches.value, idsDe(proposes.value)))
const classeRaccourci = (actif: boolean): string =>
  [
    'inline-flex min-h-11 items-center gap-1.5 rounded-pastille border px-3.5 text-sm font-semibold transition-colors',
    actif ? 'border-accent bg-accent-doux text-accent' : 'border-ligne bg-panneau text-texte hover:border-accent',
  ].join(' ')
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex items-baseline justify-between gap-3">
      <p class="text-lg font-semibold text-texte tnum" role="status" aria-live="polite">{{ compteDInvites(selection.length) }}</p>
      <button
        v-if="selection.length > 0"
        type="button"
        class="min-h-11 px-2 text-sm font-semibold text-accent underline"
        @click="selection = []"
      >
        Tout décocher
      </button>
    </div>

    <p v-if="proposes.length === 0" class="rounded-panneau border border-ligne bg-fond p-4 text-sm text-attenue">
      {{ dejaInvites.length > 0 ? 'Tous les vivants que vous voyez dans l’arbre sont déjà invités.' : 'Aucune personne vivante à inviter dans l’arbre.' }}
    </p>

    <template v-else>
      <!-- Les raccourcis : un lot d'un geste. -->
      <div class="flex flex-wrap gap-2" role="group" aria-label="Cocher un groupe">
        <button type="button" :class="classeRaccourci(tousCoches)" :aria-pressed="tousCoches" @click="appliquer(proposes)">
          Tous les vivants <span class="tnum text-xs font-normal opacity-80">{{ proposes.length }}</span>
        </button>
        <template v-if="groupes.length > 1">
          <button
            v-for="g in groupes"
            :key="g.cle"
            type="button"
            :class="classeRaccourci(toutCoche(coches, idsDe(proposes.filter((i) => i.groupe === g.cle))))"
            :aria-pressed="toutCoche(coches, idsDe(proposes.filter((i) => i.groupe === g.cle)))"
            @click="appliquer(proposes.filter((i) => i.groupe === g.cle))"
          >
            {{ g.libelle }} <span class="tnum text-xs font-normal opacity-80">{{ g.nombre }}</span>
          </button>
        </template>
      </div>

      <label :for="`${id}-recherche`" class="lecteur-ecran">Chercher un nom</label>
      <input
        :id="`${id}-recherche`"
        v-model="terme"
        type="search"
        placeholder="Chercher un nom…"
        autocomplete="off"
        class="min-h-11 w-full rounded-champ border border-ligne bg-fond px-3 text-base text-texte placeholder:text-attenue focus:border-accent focus:outline-none"
      />

      <div v-if="terme.trim() !== '' && visibles.length > 0" class="flex justify-end">
        <button type="button" class="min-h-11 px-2 text-sm font-semibold text-accent underline" @click="appliquer(visibles)">
          {{ toutCoche(coches, idsDe(visibles)) ? 'Décocher' : 'Cocher' }} les {{ visibles.length }} résultat{{ visibles.length > 1 ? 's' : '' }}
        </button>
      </div>

      <ul
        class="max-h-[50vh] divide-y divide-ligne overflow-y-auto overscroll-contain rounded-panneau border border-ligne bg-fond"
        :aria-label="`Personnes vivantes, ${visibles.length} affichée${visibles.length > 1 ? 's' : ''}`"
      >
        <li v-for="i in visibles" :key="i.personId">
          <label class="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 hover:bg-accent-doux">
            <input
              type="checkbox"
              class="size-5 shrink-0 accent-accent"
              :checked="coches.has(i.personId)"
              @change="cocher(i.personId, ($event.target as HTMLInputElement).checked)"
            />
            <span class="min-w-0 flex-1 truncate text-[15px] text-texte">{{ i.libelle }}</span>
            <span class="shrink-0 text-xs text-attenue">{{ libelleDuGroupe(i.groupe) }}</span>
          </label>
        </li>
        <li v-if="visibles.length === 0" class="px-3 py-4 text-sm text-attenue">Personne ne porte ce nom parmi les vivants.</li>
      </ul>
    </template>
  </div>
</template>
