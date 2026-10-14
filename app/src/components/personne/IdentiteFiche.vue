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
 *
 * `seulement` restreint les lignes à une liste de clés — pour la carte réduite
 * de la page, qui n'écrit que Sexe et Génération. Ce filtre ne peut **que
 * retirer** : une clé listée mais absente de la charge utile n'a toujours pas de
 * ligne. Ce n'est pas un filtre de confidentialité (le serveur l'a déjà fait),
 * c'est un choix de mise en page.
 */
import { computed } from 'vue'
import type { FichePersonne } from '@/lib/api'
import { useSession } from '@/composables/useSession'
import { construireLignes } from './champs'
import LigneChamp from './LigneChamp.vue'

const { fiche, seulement } = defineProps<{ fiche: FichePersonne; seulement?: readonly string[] }>()

const { administrateur } = useSession()

const lignes = computed(() => {
  // Seule exception au rôle du lecteur, et elle ne fait que retirer : la
  // référence GEDCOM, technique, ne parle qu'à l'administrateur.
  const toutes = construireLignes(fiche).filter(
    (ligne) => ligne.cle !== 'gedcomXref' || administrateur.value,
  )
  return seulement === undefined ? toutes : toutes.filter((ligne) => seulement.includes(ligne.cle))
})
</script>

<template>
  <!-- Grille `140px 1fr`, gap `10px 16px` (maquette 4c) ; sur mobile, la
       colonne des étiquettes prend sa largeur naturelle. -->
  <dl
    class="m-0 grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-4 gap-y-2.5 text-sm md:grid-cols-[140px_minmax(0,1fr)]"
  >
    <LigneChamp v-for="ligne in lignes" :key="ligne.cle" :ligne="ligne" />
  </dl>
</template>
