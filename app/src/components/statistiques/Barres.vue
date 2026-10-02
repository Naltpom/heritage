<script setup lang="ts">
/**
 * Un histogramme en colonnes — une ou deux séries (hommes et femmes, pères et
 * mères), une colonne par catégorie (un siècle, un mois, une génération).
 *
 * ────────────────────────────────────────────────────────────────────
 * **En HTML et non en SVG**, et c'est voulu : une colonne est une boîte dont la
 * hauteur est un pourcentage. Le texte (valeurs, libellés) reste du texte, à la
 * taille du texte, sans `viewBox` qui le déformerait sur un téléphone ; les
 * couleurs sont des jetons CSS, donc justes en clair comme en sombre sans rien
 * recalculer. Aucune bibliothèque de graphiques : le projet n'en a pas, et ce
 * qu'il dessine ici ne la justifie pas.
 *
 * **Lisible sans la couleur.** Deux séries ont une légende (le texte dit qui est
 * qui), et le graphique entier a son **tableau** pour les lecteurs d'écran —
 * `role="img"` porte un résumé, le tableau en `sr-only` porte les nombres.
 * Chaque colonne a son `title` : le survol dit la valeur exacte.
 *
 * **Une valeur `null` n'est pas un zéro.** La colonne est absente, et son
 * emplacement reste : « pas de donnée pour ce siècle » ne doit pas se lire
 * « zéro an ».
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import type { Serie } from './series'

const props = withDefaults(
  defineProps<{
    /** Ce que le graphique montre, en une phrase : le résumé du lecteur d'écran. */
    titre: string
    categories: string[]
    series: Serie[]
    /** Mise en forme d'une valeur (« 27,4 ans »). */
    format?: (valeur: number) => string
    /** Les valeurs inscrites au-dessus des colonnes : seulement si elles tiennent. */
    etiquettes?: boolean
    hauteurPx?: number
  }>(),
  { format: (v: number) => String(v), etiquettes: true, hauteurPx: 140 },
)

const maximum = computed(() => {
  const toutes = props.series.flatMap((s) => s.valeurs.filter((v): v is number => v !== null))
  return Math.max(1, ...toutes)
})

/** Une colonne : sa hauteur relative, ou `null` pour « pas de donnée ». La plus
 *  haute s'arrête à 85 % : la valeur inscrite au-dessus doit tenir dans le cadre. */
function hauteur(valeur: number | null): number | null {
  return valeur === null ? null : Math.max(0, (85 * valeur) / maximum.value)
}
</script>

<template>
  <figure class="m-0">
    <div
      role="img"
      :aria-label="titre"
      class="flex items-end gap-1.5"
      :style="{ height: `${hauteurPx + 22}px` }"
    >
      <div
        v-for="(categorie, i) in categories"
        :key="categorie + i"
        class="flex h-full min-w-0 flex-1 flex-col items-stretch justify-end"
      >
        <div class="flex min-h-0 flex-1 items-end justify-center gap-0.5">
          <div
            v-for="serie in series"
            :key="serie.nom"
            class="flex h-full min-w-0 flex-1 flex-col justify-end"
            :style="{ maxWidth: series.length > 1 ? '22px' : '36px' }"
          >
            <template v-if="hauteur(serie.valeurs[i] ?? null) !== null">
              <span
                v-if="etiquettes && serie.valeurs[i] !== 0"
                class="mb-0.5 block truncate text-center font-mono text-[10px] leading-none text-attenue tabular-nums"
              >{{ format(serie.valeurs[i]!) }}</span>
              <span
                class="block rounded-t-[4px]"
                :style="{
                  height: `${hauteur(serie.valeurs[i] ?? null)}%`,
                  minHeight: (serie.valeurs[i] ?? 0) > 0 ? '2px' : '0',
                  background: serie.couleur,
                }"
                :title="`${categorie} — ${serie.nom} : ${format(serie.valeurs[i]!)}`"
              />
            </template>
          </div>
        </div>
        <span class="mt-1 block truncate text-center text-[10px] leading-tight text-attenue" aria-hidden="true">{{ categorie }}</span>
      </div>
    </div>

    <figcaption v-if="series.length > 1" class="mt-2 flex flex-wrap gap-3 text-xs text-attenue" aria-hidden="true">
      <span v-for="serie in series" :key="serie.nom" class="inline-flex items-center gap-1.5">
        <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: serie.couleur }" />
        {{ serie.nom }}
      </span>
    </figcaption>

    <!-- Le tableau dans une boîte `sr-only`, et non `sr-only` lui-même : un tableau ne
         se réduit pas à 1 px, et il débordait de l’écran du téléphone. -->
    <div class="sr-only">
    <table>
      <caption>{{ titre }}</caption>
      <thead>
        <tr>
          <th scope="col"></th>
          <th v-for="serie in series" :key="serie.nom" scope="col">{{ serie.nom }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(categorie, i) in categories" :key="categorie + i">
          <th scope="row">{{ categorie }}</th>
          <td v-for="serie in series" :key="serie.nom">
            {{ serie.valeurs[i] == null ? '—' : format(serie.valeurs[i]!) }}
          </td>
        </tr>
      </tbody>
    </table>
    </div>
  </figure>
</template>
