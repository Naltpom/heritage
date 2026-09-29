<script setup lang="ts">
/**
 * Le point d'entrée « proposer » d'une fiche. La parenté se modifie sur le même
 * écran que les champs (`PropositionModificationView.vue`).
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
 * ## Deux dispositions, une seule condition
 *
 * Le lien vit dans le **bandeau** desktop et dans la **barre d'action fixe** du
 * mobile. La condition est la même partout : elle vit dans `gestes.ts`.
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
 * ## Deux liens, parce qu'une demande porte **une** nature
 *
 * « Quand je demande une modification je peux pas ajouter les parents les frères
 * et sœur ni les enfants » : c'était exact, et la cause n'est pas dans cet
 * écran. `modification_personne` ne porte que des **ops de champ**, et la
 * parenté n'est pas un champ de `tree_person` — c'est une ligne de `tree_child`
 * ou de `tree_family`. Elle se propose par la nature `ajout_lien`, donc par une
 * autre demande, donc par un autre formulaire.
 *
 * Les deux liens sont donc côte à côte, et le second dit ce qu'il fait : un
 * contributeur qui cherche « ajouter le père » ne doit pas avoir à deviner qu'il
 * le trouvera ailleurs que derrière « proposer une modification ».
 *
 * ## Une fiche réduite n'arrive jamais ici
 *
 * Le type l'impose : `FichePersonneComplete`. Sur une fiche réduite, `version`
 * est **absente**, « et c'est ce qui rend matériellement impossible de déposer
 * une demande — le contrôle d'écriture est redondant avec le contrôle de
 * lecture » (§ 11.1). Le composant n'y est pas monté.
 * ────────────────────────────────────────────────────────────────────
 */
import type { FichePersonneComplete } from '@/lib/api'
import { useGestesFiche } from './gestes'

const { fiche, disposition = 'bandeau' } = defineProps<{
  fiche: FichePersonneComplete
  /** `bandeau` : les deux liens côte à côte (desktop). `barre` : le seul
   *  « Proposer une modification », flexible, 44 px (barre mobile). */
  disposition?: 'bandeau' | 'barre'
}>()

const { peutProposer } = useGestesFiche()

/** Ce que disait le panneau d'avant, gardé en infobulle : la proposition part en
 *  demande, personne ne modifie l'arbre directement. */
const EXPLICATION =
  'Votre proposition part en demande, qu’un administrateur valide ou refuse. En attendant, vous seul la voyez appliquée sur cette fiche.'
</script>

<template>
  <template v-if="peutProposer">
    <RouterLink
      :to="{ name: 'proposition.modification', params: { id: fiche.id } }"
      :title="EXPLICATION"
      data-geste="proposer-modification"
      class="inline-flex items-center justify-center rounded-champ border border-accent bg-accent px-3.5 font-semibold whitespace-nowrap text-fond transition-opacity hover:opacity-90"
      :class="disposition === 'barre' ? 'h-11 min-w-0 flex-1 text-[15px]' : 'h-9 text-[13.5px]'"
    >
      Proposer une modification
    </RouterLink>

  </template>
</template>
