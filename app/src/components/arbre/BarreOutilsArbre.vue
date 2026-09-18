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
import ChampRechercheArbre from './ChampRechercheArbre.vue'
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

/**
 * **Ascendance seule** — l'arbre réduit au lecteur et à ses ancêtres.
 *
 * Chacun a au plus deux parents : ce qui reste est donc un arbre binaire, et **un
 * arbre binaire dessiné par niveaux ne peut pas avoir de croisement**. C'est une
 * propriété de sa forme, pas un résultat d'algorithme — et c'est pourquoi les
 * éventails d'ascendance de tous les logiciels de généalogie sont lisibles là où
 * les vues complètes ne le sont pas.
 *
 * Une case et non une URL : elle se **combine** avec n'importe quelle disposition,
 * et une URL par combinaison en ferait une dizaine.
 */
const ascendanceSeule = defineModel<boolean>('ascendanceSeule', { required: true })

const champ = ref<InstanceType<typeof ChampRechercheArbre> | null>(null)

/**
 * Sur téléphone, le champ de recherche **n'est pas ici** : il est monté par la
 * vue, dans un pied de page fixe, hors du tiroir.
 *
 * Chercher est le geste qu'on répète et qu'on veut voir ; filtrer sur
 * « vérifiées » ou « sans source » se fait une fois, et peut attendre dans le
 * tiroir. Le champ en sort donc, et les pastilles y restent.
 *
 * **Deux `v-if` mutuellement exclusifs, et non un `<Teleport>`.** Le téléportage
 * déplaçait le champ après le montage, à travers la frontière `display: none`
 * du tiroir fermé ; en production, sur la largeur même qu'il visait, la bascule
 * cassait le rendu de Vue. Voir l'en-tête de {@link ChampRechercheArbre}. Un seul
 * exemplaire est monté à la fois — donc un seul `id`, un seul état — et rien
 * n'est déplacé.
 *
 * Un rendu SSR donne `false`, donc la forme large : celle où le champ est ici.
 */
const estMobile = useEstMobile()

function focusRecherche(): void {
  champ.value?.focus()
}

defineExpose({ focusRecherche })
</script>

<template>
  <div
    class="flex flex-wrap items-center gap-3 border-b border-ligne bg-panneau px-4 py-2 max-md:flex-col max-md:items-stretch max-md:gap-2 max-md:border-b-0 max-md:bg-transparent max-md:px-0 max-md:py-0"
  >
    <ChampRechercheArbre
      v-if="!estMobile"
      ref="champ"
      v-model="question"
      :resultat="resultat"
      :tronque="tronque"
      :chargement="chargement"
      :compact="false"
      @cadrer="emit('cadrer')"
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

    <label
      class="flex cursor-pointer items-center gap-1.5 whitespace-nowrap text-xs text-attenue hover:text-texte"
      title="Cadrer la vue sur les fiches trouvées dès que la recherche aboutit"
    >
      <input v-model="recadrageAuto" type="checkbox" class="cursor-pointer accent-accent" />
      Suivre la recherche
    </label>

    <label
      class="flex cursor-pointer items-center gap-1.5 whitespace-nowrap text-xs text-attenue hover:text-texte"
      title="N’afficher que vous et vos ancêtres — un arbre binaire, sans aucun croisement possible"
    >
      <input v-model="ascendanceSeule" type="checkbox" class="cursor-pointer accent-accent" />
      Ascendance seule
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
