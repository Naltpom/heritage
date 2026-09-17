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
import { useEstMobile } from '@/composables/useTiroirs'
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

/**
 * Le recadrage automatique sur les résultats.
 *
 * `defineModel` nommé plutôt qu'un couple prop/emit : c'est une case à cocher,
 * et l'état vit dans la vue comme tout le reste de cette barre — elle émet, elle
 * ne retient rien.
 *
 * **Pourquoi elle est offerte et non imposée.** Un recadrage déplace la vue.
 * Quelqu'un qui explore une branche et cherche des noms au passage veut parfois
 * garder son cadrage ; le bouton « Cadrer » et la touche `Entrée` restent là
 * pour lui. Mais le défaut est coché, parce que marquer des fiches qu'on ne voit
 * pas n'apprend rien.
 */
const recadrageAuto = defineModel<boolean>('recadrageAuto', { required: true })

const champ = ref<HTMLInputElement | null>(null)

/**
 * Sur téléphone, le champ de recherche **sort du tiroir**.
 *
 * Un tiroir fermé est en `display: none` : le CSS seul ne peut donc pas en faire
 * sortir quoi que ce soit, il faut déplacer l'élément dans le DOM, et c'est
 * exactement ce que fait `<Teleport>`. Le champ est **rendu une seule fois** —
 * deux champs auraient deux valeurs, deux fois le même `id`, et
 * `focusRecherche()` ne saurait plus lequel viser.
 *
 * Pourquoi lui et pas les pastilles : chercher est le geste qu'on répète, et
 * qu'on veut voir. Filtrer sur « vérifiées » ou « sans source » se fait une
 * fois, et peut attendre dans le tiroir.
 *
 * `disabled` à l'inverse d'`estMobile` : hors téléphone le téléportage ne
 * s'applique pas et le champ reste à sa place d'origine, dans la barre. Un rendu
 * SSR donne `false`, donc la forme large — celle qui ne déplace rien.
 */
const estMobile = useEstMobile()

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
    role="group"
    aria-label="Filtres de l’arbre"
  >
    <Teleport to="#recherche-arbre-mobile" :disabled="!estMobile">
      <div
        class="flex items-center gap-2 max-md:w-full max-md:border-t max-md:border-ligne max-md:bg-panneau max-md:px-4 max-md:py-2 md:contents"
        role="search"
      >
        <label class="lecteur-ecran" for="arbre-recherche">Chercher une personne</label>
        <input
          id="arbre-recherche"
          ref="champ"
          v-model="question"
          type="search"
          class="min-w-[150px] flex-[0_1_250px] rounded-champ border border-ligne bg-fond px-2.5 py-1 text-[13px] text-texte max-md:min-w-0 max-md:flex-1 max-md:py-2"
          placeholder="Chercher un nom, une date, un lieu…"
          autocomplete="off"
          spellcheck="false"
          @keydown="surTouche"
        />
        <span
          v-if="estMobile"
          class="min-w-[74px] text-right text-xs font-semibold text-accent"
          aria-live="polite"
        >
          <template v-if="chargement">…</template>
          <template v-else>{{ resultat }}</template>
        </span>
      </div>
    </Teleport>

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

    <span class="min-w-[74px] text-xs font-semibold text-accent max-md:hidden" aria-live="polite">
      <template v-if="chargement">…</template>
      <template v-else>{{ resultat }}</template>
      <span v-if="tronque" class="font-normal text-attenue"> (liste plafonnée)</span>
    </span>

    <label
      class="flex cursor-pointer items-center gap-1.5 whitespace-nowrap text-xs text-attenue hover:text-texte"
      title="Cadrer la vue sur les fiches trouvées dès que la recherche aboutit"
    >
      <input v-model="recadrageAuto" type="checkbox" class="cursor-pointer accent-accent" />
      Suivre la recherche
    </label>

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
