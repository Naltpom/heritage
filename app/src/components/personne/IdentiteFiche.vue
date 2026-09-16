<script setup lang="ts">
/**
 * Le tableau d'identité — **rien d'autre que ce que la charge utile porte.**
 *
 * Ce composant ne décide de rien : il dessine `construireLignes(fiche)`, et
 * cette fonction ne rend une ligne que si la clé **est dans la charge utile**
 * (§ 1.3). Il n'y a donc, à dessein, aucun `v-if` sur un nom de champ ici, et
 * aucun sur le rôle du lecteur.
 *
 * > **Le rôle du lecteur choisit la route, jamais l'affichage** (§ 10.4).
 *
 * Un `v-if="administrateur"` devant une ligne que la réponse n'a pas envoyée
 * serait la version front du `if ($estAdmin) { return $tout; }` qu'il a fallu
 * retirer du serveur — et il n'afficherait de toute façon qu'un `undefined`.
 * L'administrateur voit davantage **parce que le serveur lui envoie davantage**,
 * et il traverse le cercle comme tout le monde : le sien est simplement complet.
 *
 * Conséquence pratique : ce composant marche tel quel sur une fiche réduite. Il
 * y rend trois lignes — sexe, branche, génération —, ce qui est exactement ce
 * que les sept clés du § 11.1 permettent d'écrire.
 */
import { computed } from 'vue'
import type { FichePersonne } from '@/lib/api'
import { construireLignes } from './champs'
import LigneChamp from './LigneChamp.vue'

const { fiche } = defineProps<{ fiche: FichePersonne }>()

const lignes = computed(() => construireLignes(fiche))
</script>

<template>
  <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-2 text-sm">
    <LigneChamp v-for="ligne in lignes" :key="ligne.cle" :ligne="ligne" />
  </dl>
</template>
