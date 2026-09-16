<script setup lang="ts">
/**
 * Le bouton « Quitter la vue empruntée », posé dans le bandeau permanent.
 *
 * Il vit dans le créneau `quitter-vue` de `BandeauRegime`, prévu pour lui, parce
 * que la sortie doit être **partout où le bandeau est** : un administrateur qui
 * a activé la bascule depuis l'écran des utilisateurs va ensuite parcourir
 * l'arbre, les fiches, la chronologie. L'obliger à revenir dans l'administration
 * pour en sortir, c'est le laisser explorer longtemps sous une identité qu'il a
 * oubliée — et tirer de fausses conclusions sur ce qui manque.
 *
 * Quitter appelle `rafraichirSession()`, et cet appel n'est pas une précaution :
 * il relit la session — qui reprend son cercle et perd `voirComme` — **et** il
 * oublie l'`ETag` du graphe. Sans le second, la revalidation suivante rendrait
 * `304` et l'administrateur continuerait de regarder le graphe filtré du membre
 * qu'il vient de quitter (§ 5.2, § 10.4).
 *
 * Le composant ne s'affiche que si la bascule est active : c'est le bandeau qui
 * décide de le rendre, et il ne le rend que dans cette branche.
 */
import { ref } from 'vue'
import { quitterVueComme } from '@/lib/api'
import { rafraichirSession } from '@/composables/useSession'

const enCours = ref(false)

async function quitter(): Promise<void> {
  if (enCours.value) return
  enCours.value = true
  try {
    await quitterVueComme()
  } finally {
    // La session est relue **quoi qu'il arrive**. Si l'appel a échoué alors que
    // le serveur avait déjà quitté la bascule, garder le bandeau afficherait
    // l'inverse de la vérité ; la relecture tranche dans les deux sens.
    await rafraichirSession()
    enCours.value = false
  }
}
</script>

<template>
  <button
    type="button"
    :disabled="enCours"
    class="rounded-champ border border-accent px-2.5 py-0.5 text-sm font-semibold transition-colors hover:bg-accent hover:text-fond disabled:opacity-60"
    @click="quitter"
  >
    Quitter la vue empruntée
  </button>
</template>
