<script setup lang="ts">
/**
 * La barre d'outils de l'arbre : recherche, filtres, recadrage.
 *
 * Contrairement au canevas, tout est ici **réactif** — cinq boutons et un champ
 * ne coûtent rien à Vue. La frontière est celle du § 7.2 : Vue gère le cadre et
 * les contrôles, il ne gère pas les 377 nœuds.
 *
 * **Un filtre dont la donnée n'est pas arrivée est désactivé, pas silencieux.**
 * `GET /api/arbre/graphe` n'émet pas encore `bestQuay`, `citationCount` ni
 * `avecImage` (§ 5.2 du contrat) : sans ce garde-fou, les quatre filtres
 * documentaires afficheraient « aucune fiche », ce qui ressemble exactement à
 * un filtre qui marche et ne trouve rien. Le survol dit pourquoi.
 */
import { ref } from 'vue'
import type { DefinitionFiltre, IdFiltre } from './graphe'

const { filtres, disponibles, actif, resultat, tronque, chargement } = defineProps<{
  filtres: readonly DefinitionFiltre[]
  disponibles: ReadonlySet<IdFiltre>
  actif: IdFiltre | null
  /** Le décompte déjà rédigé (« 12 fiches », « aucune »), ou `''`. */
  resultat: string
  tronque: boolean
  chargement: boolean
}>()

const emit = defineEmits<{
  filtre: [id: IdFiltre]
  tout: []
  /** `Entrée` dans le champ : cadrer la vue sur les résultats. */
  cadrer: []
}>()

const question = defineModel<string>({ required: true })

const champ = ref<HTMLInputElement | null>(null)

function focusRecherche(): void {
  champ.value?.focus()
  champ.value?.select()
}

function surTouche(ev: KeyboardEvent): void {
  if (ev.key === 'Escape') {
    question.value = ''
    champ.value?.blur()
  }
  if (ev.key === 'Enter') {
    ev.preventDefault()
    emit('cadrer')
  }
}

defineExpose({ focusRecherche })
</script>

<template>
  <div
    class="flex flex-wrap items-center gap-3 border-b border-ligne bg-panneau px-4 py-2 max-md:flex-col max-md:items-stretch max-md:gap-2 max-md:border-b-0 max-md:bg-transparent max-md:px-0 max-md:py-0"
    role="search"
  >
    <label class="lecteur-ecran" for="arbre-recherche">Chercher une personne</label>
    <input
      id="arbre-recherche"
      ref="champ"
      v-model="question"
      type="search"
      class="min-w-[150px] flex-[0_1_250px] rounded-champ border border-ligne bg-fond px-2.5 py-1 text-[13px] text-texte"
      placeholder="Chercher un nom, une date, un lieu…"
      autocomplete="off"
      spellcheck="false"
      @keydown="surTouche"
    />

    <button
      v-for="f in filtres"
      :key="f.id"
      type="button"
      class="cursor-pointer whitespace-nowrap rounded-pastille border px-2.5 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-45"
      :class="
        actif === f.id
          ? 'border-accent bg-accent-doux font-semibold text-texte'
          : 'border-ligne bg-fond text-attenue hover:border-accent'
      "
      :aria-pressed="actif === f.id"
      :disabled="!disponibles.has(f.id)"
      :title="
        disponibles.has(f.id)
          ? f.description
          : `${f.description} — donnée non fournie par le graphe pour l’instant`
      "
      @click="emit('filtre', f.id)"
    >
      {{ f.libelle }}
    </button>

    <button
      type="button"
      class="cursor-pointer whitespace-nowrap rounded-pastille border border-ligne bg-fond px-2.5 py-1 text-xs text-attenue hover:border-accent"
      title="Effacer la sélection, les filtres et la recherche, puis recadrer"
      @click="emit('tout')"
    >
      Tout afficher
    </button>

    <span class="min-w-[74px] text-xs font-semibold text-accent" aria-live="polite">
      <template v-if="chargement">…</template>
      <template v-else>{{ resultat }}</template>
      <span v-if="tronque" class="font-normal text-attenue"> (liste plafonnée)</span>
    </span>

    <span class="flex-auto max-md:hidden"></span>

    <p class="hidden whitespace-nowrap text-xs text-attenue lg:block">
      <b>clic sur une fiche</b> ·
      <kbd class="rounded border border-ligne px-1 text-[11px]">/</kbd> chercher ·
      <kbd class="rounded border border-ligne px-1 text-[11px]">Entrée</kbd> cadrer ·
      <kbd class="rounded border border-ligne px-1 text-[11px]">0</kbd> recadrer ·
      <kbd class="rounded border border-ligne px-1 text-[11px]">Échap</kbd> tout afficher
    </p>
  </div>
</template>
