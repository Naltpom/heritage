<script setup lang="ts">
/**
 * La légende dépliable de la chronologie, en bas à gauche du canevas.
 *
 * Les échantillons portent **les classes du canevas** (`barre`, `corps b-pm`,
 * `hachure`…) et sont enveloppés dans un `<svg class="chrono">` pour que les
 * règles de `canevas.css` les atteignent. Une légende qui redéclare ses
 * couleurs finit par mentir dès la première retouche, et personne ne s'en
 * aperçoit.
 *
 * Seule exception : le motif de hachure est **redéfini ici**, sous un autre
 * identifiant. Celui du canevas ne vit que tant que le canevas est monté, et
 * une légende qui pointe vers un motif disparu ne montre rien — sans erreur.
 */
import { DESCRIPTIONS_BRANCHE, ORDRE_BRANCHES } from './disposition'

/**
 * **`enTiroir` : la même légende, à l'autre endroit.** Sur téléphone, la forme
 * flottante était simplement `hidden` — la légende n'existait pas, sans rien
 * pour la remplacer. Elle passe dans le tiroir des filtres, où il y a de la
 * place. À `false`, la chaîne de classes est celle d'avant, caractère pour
 * caractère.
 */
const { enTiroir = false } = defineProps<{
  /** Rendue dans le tiroir des filtres du mobile, et non en flottant. */
  enTiroir?: boolean
}>()
</script>

<template>
  <details
    :class="
      enTiroir
        ? 'w-full overflow-hidden rounded-panneau border border-ligne bg-panneau text-xs'
        : 'absolute bottom-3.5 left-3.5 z-10 hidden max-w-[300px] overflow-hidden rounded-panneau border border-ligne bg-panneau text-xs shadow-panneau md:block'
    "
  >
    <summary class="cursor-pointer select-none px-3 py-2 font-semibold text-attenue">
      Légende
    </summary>

    <div class="grid gap-2 border-t border-ligne px-3 pt-2.5 pb-3">
      <h4 class="mt-1 text-[10.5px] font-bold tracking-wider text-attenue uppercase">
        Colonnes — la branche
      </h4>
      <div v-for="b in ORDRE_BRANCHES" :key="b" class="flex items-center gap-2 leading-tight">
        <svg width="34" height="14" class="chrono shrink-0" aria-hidden="true">
          <g class="barre"><rect :class="['corps', `b-${b}`]" x="1" y="1" width="32" height="12" rx="3" /></g>
        </svg>
        <span class="text-attenue">{{ DESCRIPTIONS_BRANCHE[b] }}</span>
      </div>

      <h4 class="mt-1 text-[10.5px] font-bold tracking-wider text-attenue uppercase">
        Lecture d’une barre
      </h4>

      <div class="flex items-center gap-2 leading-tight">
        <svg width="34" height="14" class="chrono shrink-0" aria-hidden="true">
          <defs>
            <pattern id="chrono-hachure-legende" width="6" height="6" patternUnits="userSpaceOnUse">
              <path class="trait" d="M-1,1 L1,-1 M0,6 L6,0 M5,7 L7,5" />
            </pattern>
          </defs>
          <g class="barre">
            <rect class="corps b-tr" x="1" y="1" width="32" height="12" rx="3" />
            <rect class="hachure-legende" x="1" y="1" width="13" height="12" />
          </g>
        </svg>
        <span class="text-attenue">
          Date incertaine : la hachure couvre la fourchette
        </span>
      </div>

      <div class="flex items-center gap-2 leading-tight">
        <svg width="34" height="14" class="chrono shrink-0 overflow-visible" aria-hidden="true">
          <g class="barre ouverte">
            <rect class="corps b-tr" x="1" y="1" width="24" height="12" rx="3" />
            <polygon class="pointe b-tr" points="25,1 33,7 25,13" />
          </g>
        </svg>
        <span class="text-attenue">
          Personne vivante : la barre reste ouverte
        </span>
      </div>

      <div class="flex items-center gap-2 leading-tight">
        <svg width="34" height="14" class="chrono shrink-0 overflow-visible" aria-hidden="true">
          <g class="barre">
            <rect class="corps b-tr" x="9" y="1" width="24" height="12" rx="3" />
            <polygon class="pointe avant b-tr" points="9,1 1,7 9,13" />
          </g>
        </svg>
        <span class="text-attenue">
          Date « avant 1888 » : la barre file vers le passé
        </span>
      </div>

      <div class="flex items-center gap-2 leading-tight">
        <svg width="34" height="14" class="chrono shrink-0" aria-hidden="true">
          <g class="barre sans-fin">
            <rect class="corps b-tr" x="1" y="1" width="18" height="12" rx="3" />
          </g>
        </svg>
        <span class="text-attenue">
          Décès non daté : bord en tirets
        </span>
      </div>

      <div class="flex items-center gap-2 leading-tight">
        <svg width="34" height="14" class="chrono shrink-0" aria-hidden="true">
          <g class="barre">
            <rect class="corps b-tr" x="1" y="1" width="32" height="12" rx="3" />
          </g>
          <circle class="jalon" cx="17" cy="7" r="3.2" />
        </svg>
        <span class="text-attenue">
          Fait daté (recensement, résidence) : la barre va jusqu’au dernier fait connu
        </span>
      </div>

      <!--
        Dit **pourquoi** il manque des barres, sans jamais dire **combien** : un
        décompte trahirait par soustraction le nombre de personnes vivantes que
        le lecteur n'a pas le droit de voir (§ 9.1).
      -->
      <p class="mt-1 border-t border-ligne pt-2 text-[11.5px] leading-snug text-attenue">
        Les fiches confidentielles n’apparaissent pas ici.
      </p>
    </div>
  </details>
</template>
