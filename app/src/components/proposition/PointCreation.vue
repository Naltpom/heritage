<script setup lang="ts">
/**
 * Le point d'entrée « proposer une personne ».
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Pourquoi ce composant vit ici, et pas dans l'écran qui l'affiche
 *
 * Contrairement à « proposer une modification », qui a sa place évidente sur la
 * fiche d'une personne, l'ajout d'une personne ne part d'aucun écran en
 * particulier : la personne à ajouter est précisément celle qui n'a pas de
 * fiche. Son point d'entrée appartient donc à un cadre — en-tête applicatif,
 * barre d'outils de l'arbre — que ce module ne possède pas.
 *
 * Il est écrit ici pour que le greffer **ne demande qu'un import** : les deux
 * conditions d'affichage, le libellé et la route restent avec le formulaire
 * qu'ils ouvrent, plutôt que d'être réécrits dans un fichier qui ne sait pas
 * pourquoi. Aucune prop, aucun événement.
 *
 * ## Deux conditions, et `rattache` plutôt que `connecte` est la première
 *
 * 1. **Un compte en attente de validation ne dépose pas.** Il recevrait
 *    `403 regime_en_attente` (§ 12.2), et la garde de route l'enverrait vers
 *    l'écran d'attente — ce qui, depuis un bouton, ressemble à une panne.
 * 2. **Pas sous la bascule « voir comme un membre »** : toute écriture y rend
 *    `409 vue_comme_membre_active` (§ 10.4). On ne dépose pas une demande sous
 *    une identité empruntée.
 *
 * Masquer le lien **n'est pas une mesure de sécurité** — la garde de route le
 * double, et l'API double les deux. C'est éviter de proposer un geste voué au
 * refus. Le rôle choisit ici une **action**, jamais un champ : c'est la nuance
 * du § 10.4, et c'est ce qui la rend légitime.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import { useSession } from '@/composables/useSession'

const { libelle = 'Proposer une personne', discret = false } = defineProps<{
  libelle?: string
  /** Rendu en lien sobre plutôt qu'en bouton : pour une barre d'outils déjà
   *  chargée, où un second bouton plein se disputerait l'attention. */
  discret?: boolean
}>()

const { rattache, vueEmpruntee } = useSession()

const ouvert = computed(() => rattache.value && vueEmpruntee.value === null)
</script>

<template>
  <RouterLink
    v-if="ouvert"
    :to="{ name: 'proposition.creation' }"
    class="inline-flex items-center rounded-champ border px-3.5 py-1.5 text-sm font-semibold transition-colors"
    :class="
      discret
        ? 'border-ligne bg-panneau text-texte hover:border-accent'
        : 'border-accent bg-accent text-fond hover:opacity-90'
    "
  >
    {{ libelle }}
  </RouterLink>
</template>
