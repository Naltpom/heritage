<script setup lang="ts">
/**
 * La légende de la carte : ce que disent la taille et la couleur.
 *
 * **Les nombres affichés sont les vrais.** Les trois cercles portent des comptes
 * réellement présents sur la carte — calculés depuis le maximum en cours, et
 * non depuis une échelle figée. Une légende calculée sur autre chose que les
 * données affichées finit par mentir dès la première retouche, et personne ne
 * s'en aperçoit : c'est l'argument que porte déjà la légende de la chronologie.
 *
 * **Les échantillons de couleur reçoivent l'expression `color-mix()` telle
 * quelle**, la même que les bulles. Redéclarer les teintes ici en ferait une
 * seconde définition, qui divergerait.
 *
 * `enTiroir` : sur téléphone, la forme flottante est `hidden` et cette
 * légende-ci vit dans le tiroir des filtres. Même mécanique que les deux autres
 * légendes du produit.
 */
import { computed } from 'vue'
import { paliersDeCouleur, paliersDeTaille, type ModeCouleur } from './echelles'

const { mode, maximum, bornes, enTiroir = false } = defineProps<{
  mode: ModeCouleur
  maximum: number
  bornes: { min: number; max: number } | null
  enTiroir?: boolean
}>()

const tailles = computed(() => paliersDeTaille(maximum))
const couleurs = computed(() => paliersDeCouleur(mode, bornes, maximum))
const diametreMax = computed(() => {
  const dernier = tailles.value[tailles.value.length - 1]
  return dernier === undefined ? 0 : dernier.rayon * 2 + 4
})
</script>

<template>
  <details
    :class="
      enTiroir
        ? 'w-full overflow-hidden rounded-panneau border border-ligne bg-panneau text-xs'
        : 'absolute bottom-3.5 left-3.5 z-[500] hidden max-w-[280px] overflow-hidden rounded-panneau border border-ligne bg-panneau text-xs shadow-panneau md:block'
    "
  >
    <summary class="cursor-pointer select-none px-3 py-2 font-semibold text-attenue">
      Légende
    </summary>

    <div class="space-y-3 border-t border-ligne px-3 py-2">
      <div v-if="tailles.length > 0">
        <p class="mb-1 text-attenue">Taille : nombre d’événements au même lieu</p>
        <div class="flex items-end gap-3">
          <div v-for="palier in tailles" :key="palier.nombre" class="text-center">
            <svg
              class="carte block"
              :width="diametreMax"
              :height="diametreMax"
              aria-hidden="true"
            >
              <circle
                class="bulle"
                :cx="diametreMax / 2"
                :cy="diametreMax / 2"
                :r="palier.rayon"
                fill="var(--color-branche-pm)"
                fill-opacity="0.85"
                stroke="var(--color-branche-pm)"
                stroke-opacity="0.28"
                stroke-width="8"
              />
            </svg>
            <span class="tnum text-attenue">{{ palier.nombre }}</span>
          </div>
        </div>
      </div>

      <div v-if="couleurs.length > 0">
        <p class="mb-1 text-attenue">
          Couleur : {{ mode === 'date' ? 'ancienneté' : 'densité' }}
        </p>
        <div class="flex items-center gap-1">
          <span
            v-for="palier in couleurs"
            :key="palier.libelle"
            class="h-4 flex-1 rounded-[2px]"
            :style="{ background: palier.teinte }"
            aria-hidden="true"
          />
        </div>
        <div class="tnum mt-0.5 flex justify-between text-attenue">
          <span>{{ couleurs[0]?.libelle }}</span>
          <span>{{ couleurs[couleurs.length - 1]?.libelle }}</span>
        </div>
      </div>

      <p class="text-attenue">
        Une union n’apparaît que si ses deux époux sont visibles pour vous.
      </p>
    </div>
  </details>
</template>
