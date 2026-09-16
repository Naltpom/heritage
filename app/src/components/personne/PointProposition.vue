<script setup lang="ts">
/**
 * Le point d'entrée « proposer une modification ».
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Un lien, et rien d'autre — surtout pas un état transmis
 *
 * `proposition.modification` (`/personnes/:id/proposer`) **relit la fiche à son
 * ouverture**, et la `version` qu'elle en tire est par construction celle que
 * l'auteur a sous les yeux au moment où il commence à écrire. C'est exactement
 * ce que `baseVersion` doit valoir (§ 6.2) : la version sur laquelle la
 * proposition est réellement fondée.
 *
 * Lui pousser la `version` d'ici serait donc **pire que rien**. Cette fiche a pu
 * être chargée il y a vingt minutes ; la transmettre ferait partir la demande
 * sur un état périmé, et le verrou optimiste la rejetterait en
 * `409 verrou_concurrent` — ou, plus vicieux, la laisserait passer sur une fiche
 * qui a bougé entre-temps. Le formulaire est mieux placé que nous pour savoir ce
 * qu'il propose : on lui donne l'identifiant, il va chercher le reste.
 *
 * ## Pourquoi un `v-if` sur le rôle est légitime ici, et seulement ici
 *
 * > **Le rôle du lecteur choisit la route, jamais l'affichage** (§ 10.4).
 *
 * La nuance tient à ce qui est caché : un **champ** s'affiche parce que la charge
 * utile le porte — le masquer par rôle serait la version front du
 * `if ($estAdmin) { return $tout; }` retiré du serveur. Une **action**, elle, se
 * cache par rôle, comme le panneau de décision de `DemandeView.vue`.
 *
 * Deux conditions, et `rattache` plutôt que `connecte` est la première :
 *
 * 1. **un compte en attente de validation ne dépose pas.** Il recevrait
 *    `403 regime_en_attente`, et « aucun geste du lecteur ne l'active, c'est
 *    l'administrateur qui valide » (§ 12). La garde de route le renverrait de
 *    toute façon vers l'écran d'attente, ce qui ressemblerait à une panne ;
 * 2. **pas sous la bascule « voir comme un membre »** : toute écriture y rend
 *    `409 vue_comme_membre_active` (§ 10.4).
 *
 * Masquer le lien n'est pas une mesure de sécurité — la garde de route le double
 * et l'API double les deux. C'est éviter de proposer un geste voué au refus.
 *
 * ## Une fiche réduite n'arrive jamais ici
 *
 * Le type l'impose : `FichePersonneComplete`. Sur une fiche réduite, `version`
 * est **absente**, « et c'est ce qui rend matériellement impossible de déposer
 * une demande — le contrôle d'écriture est redondant avec le contrôle de
 * lecture » (§ 11.1). Le composant n'y est pas monté.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import { useSession } from '@/composables/useSession'

const { fiche } = defineProps<{ fiche: FichePersonneComplete }>()

const { rattache, vueEmpruntee } = useSession()

const ouvert = computed(() => rattache.value && vueEmpruntee.value === null)
</script>

<template>
  <div v-if="ouvert" id="proposition" class="flex flex-col gap-2">
    <p class="m-0 text-sm text-attenue">
      Une information vous paraît fausse ou incomplète&nbsp;? Personne ne modifie l’arbre
      directement&nbsp;: votre proposition part en demande, qu’un administrateur valide ou
      refuse. En attendant, vous seul la voyez appliquée sur cette fiche.
    </p>

    <p class="m-0">
      <RouterLink
        :to="{ name: 'proposition.modification', params: { id: fiche.id } }"
        class="inline-flex items-center rounded-champ border border-accent bg-accent px-3.5 py-1.5 text-sm font-semibold text-fond transition-opacity hover:opacity-90"
      >
        Proposer une modification
      </RouterLink>
    </p>
  </div>
</template>
