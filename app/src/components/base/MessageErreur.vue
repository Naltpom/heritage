<script setup lang="ts">
/**
 * L'affichage d'une erreur d'API — **le seul**.
 *
 * On lui passe ce qu'un `catch` a attrapé, sans le trier : `analyserErreur()`
 * accepte `unknown`. Le composant en tire le message, les erreurs de champ et
 * la **conduite à tenir**, puis émet `action` si le lecteur clique sur le bouton.
 *
 * La raison d'être de ce composant tient dans les deux `409` de la validation
 * (§ 15.4) :
 *
 * | | `verrou_concurrent` | `demande_caduque` |
 * |---|---|---|
 * | Écrit dans l'arbre | non | non |
 * | La demande reste ouverte | **oui**, `en_attente` | non, elle passe `caduque` |
 * | `details.rejouable` | `true` | `false` |
 * | Bouton proposé | **« Réessayer »** | **« Refaire cette demande »** |
 *
 * Un écran qui afficherait « Réessayer » sur les deux ferait boucler
 * l'administrateur sur une demande morte. Comme le libellé vient d'ici et non
 * de l'écran, aucun écran ne peut se tromper — et le jour où le contrat ajoute
 * un code, il y a un seul endroit à modifier.
 */
import { computed } from 'vue'
import { analyserErreur } from '@/lib/api'
import BoutonBase from './BoutonBase.vue'

const { erreur, titre = '' } = defineProps<{
  /** Ce que le `catch` a attrapé. Aucun tri préalable n'est attendu. */
  erreur: unknown
  titre?: string
}>()

const emit = defineEmits<{
  /** Le lecteur a cliqué sur le bouton d'action. La conduite est transmise pour
   *  que l'appelant sache s'il doit rejouer le même appel (`reessayer`), repartir
   *  d'une nouvelle demande (`refaire`) ou recharger l'écran (`recharger`). */
  action: [conduite: ReturnType<typeof analyserErreur>['conduite']]
}>()

const analyse = computed(() => analyserErreur(erreur))
const champs = computed(() => Object.entries(analyse.value.champs))
</script>

<template>
  <div
    class="rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
    role="alert"
  >
    <p v-if="titre.length > 0" class="mb-1 font-semibold">{{ titre }}</p>

    <!-- Le message vient du serveur : « français, une phrase, affichable telle
         quelle » (§ 15.1). Le front ne le réécrit pas. -->
    <p>{{ analyse.message }}</p>

    <!-- `champs` n'est rempli que sur les 422. Affiché ici en secours : le cas
         nominal est que chaque `ChampTexte` reçoive son propre message. -->
    <ul v-if="champs.length > 0" class="mt-2 list-disc space-y-0.5 pl-5 text-xs">
      <li v-for="[nom, message] in champs" :key="nom">
        <span class="font-mono">{{ nom }}</span> — {{ message }}
      </li>
    </ul>

    <p v-if="analyse.attenteSecondes !== null" class="mt-2 text-xs text-attenue">
      Nouvel essai possible dans {{ analyse.attenteSecondes }} secondes.
    </p>

    <div v-if="analyse.libelleAction !== null" class="mt-3">
      <BoutonBase variante="secondaire" @click="emit('action', analyse.conduite)">
        {{ analyse.libelleAction }}
      </BoutonBase>
    </div>

    <!-- Le code est affiché en petit : il est stable, c'est lui qu'on cite dans
         un rapport de bogue, et le message peut changer sans lui. -->
    <p class="mt-2 font-mono text-[11px] text-attenue">{{ analyse.code }}</p>
  </div>
</template>
