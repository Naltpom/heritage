<script setup lang="ts">
/**
 * Les deux bandeaux permanents du cadre. Aucun n'est décoratif : chacun évite
 * qu'un lecteur conclue à une panne devant un arbre incomplet.
 *
 * **1. « Voir comme un membre » (§ 10.4).** Le contrat l'exige en toutes
 * lettres : « Le front affiche un bandeau permanent : sans lui, un
 * administrateur conclura à un bug en ne voyant plus la moitié des fiches. »
 * Tant que la bascule est active, **toute route d'écriture rend
 * `409 vue_comme_membre_active`** — le front désactive ses boutons, et l'API ne
 * compte pas dessus.
 *
 * **2. Le régime « en attente de validation » (§ 12).** Le compte ne voit
 * **rien de l'arbre**, ni vivants ni défunts (ADR 0003, révision du
 * 01/10/2026) : ses seuls écrans sont son inscription, son compte et Moi. Le
 * bandeau dit pourquoi l'application lui paraît si vide, et quand cela
 * changera — sans quoi il conclurait à un compte cassé.
 */
import { useSession } from '@/composables/useSession'

const { regime, vueEmpruntee, incarnation, nomAffiche } = useSession()
</script>

<template>
  <div>
    <!-- L'incarnation passe avant tout : l'administrateur **agit** au nom de
         quelqu'un, et chaque geste sera enregistré sous ce nom (ADR 0010). -->
    <div
      v-if="incarnation !== null"
      class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-alerte-bord bg-alerte-doux px-4 py-2 text-sm text-alerte"
      role="status"
    >
      <strong class="font-semibold">Connecté en tant que {{ nomAffiche }}</strong>
      <span>
        Vous voyez et faites tout comme ce membre. Chaque action est tracée à votre nom,
        {{ incarnation.par.displayName }}.
      </span>
      <slot name="quitter-incarnation" />
    </div>

    <div
      v-else-if="vueEmpruntee !== null"
      class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-accent bg-accent-doux px-4 py-2 text-sm text-accent"
      role="status"
    >
      <strong class="font-semibold">Vue empruntée</strong>
      <span>
        Vous voyez l'arbre avec le cercle de visibilité de
        <span class="font-semibold">{{ vueEmpruntee.memberLabel }}</span
        >. Toute écriture est refusée tant que cette bascule est active.
      </span>
      <slot name="quitter-vue" />
    </div>

    <div
      v-else-if="regime === 'en_attente'"
      class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-ligne bg-panneau px-4 py-2 text-sm"
      role="status"
    >
      <strong class="font-semibold">Inscription en cours de vérification</strong>
      <span class="text-attenue">
        L’arbre vous sera ouvert dès qu’elle sera validée. Vous serez prévenu.
      </span>
      <RouterLink :to="{ name: 'inscription.etat' }" class="font-semibold text-accent underline">
        Voir l'état de mon inscription
      </RouterLink>
    </div>
  </div>
</template>
