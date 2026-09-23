<script setup lang="ts">
/**
 * La page d'une **fiche réduite** — personne vivante hors du cercle du lecteur.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ce n'est pas une erreur.** Le serveur rend `200` avec sept clés (§ 11.1),
 * parce que le nœud est visible dans le graphe et qu'il faut pouvoir cliquer
 * dessus. `MessageErreur` est réservé aux vraies erreurs ; ici, un nom, une
 * raison, une sortie.
 *
 * La carte rend, dans l'ordre de la maquette :
 *
 * 1. le nom (et une case de portrait vide, teintée du sexe) ;
 * 2. la phrase du dossier, **mot pour mot**, dans un bloc d'alerte ;
 * 3. Sexe et Génération, **seulement** ;
 * 4. « Voir dans l'arbre ».
 *
 * **Pas d'onglets, pas de barre d'action.** Il n'y a rien derrière un onglet —
 * ni dates, ni lieux, ni sources, ni photos ne sont arrivés — et aucun geste
 * n'est possible : `version` est absente, « et c'est ce qui rend matériellement
 * impossible de déposer une demande » (§ 11.1).
 *
 * **Le front ne filtre rien.** Les champs manquants ne sont pas cachés ici : ils
 * ne sont pas arrivés. Le filtre `seulement` d'`IdentiteFiche` ne fait que
 * choisir, parmi les trois lignes que les sept clés permettent (sexe, branche,
 * génération), les deux que la maquette garde.
 *
 * **Une exception au mot pour mot**, et une seule : un compte **en attente de
 * validation** n'a pas de cercle (§ 12). Lui dire « elle ne fait pas partie de
 * votre cercle » serait doublement inexact — *aucune* personne vivante ne lui
 * est accessible, pas même celle à laquelle il se rattache. La session n'est lue
 * que pour choisir la phrase ; la donnée affichée, elle, est identique.
 *
 * `FicheRestreinte.vue` reste la forme compacte de l'aperçu d'administration.
 * ────────────────────────────────────────────────────────────────────
 */
import type { FichePersonneRestreinte } from '@/lib/api'
import { useSession } from '@/composables/useSession'
import IdentiteFiche from './IdentiteFiche.vue'
import PortraitFiche from './PortraitFiche.vue'

defineProps<{ fiche: FichePersonneRestreinte }>()

const { regime } = useSession()

/** Les deux lignes que la maquette garde sur une fiche réduite. */
const LIGNES = ['sex', 'generation'] as const
</script>

<template>
  <div class="border-b border-ligne bg-panneau max-md:border-b-0">
    <div
      data-fiche-reduite
      class="mx-auto flex w-full max-w-[640px] flex-col gap-3.5 px-8 pt-[18px] pb-6 max-md:px-5 max-md:pt-2"
    >
      <div class="flex items-center gap-3.5">
        <PortraitFiche :portrait="null" :nom="fiche.displayName" :sexe="fiche.sex" />
        <h1
          class="m-0 text-[28px] leading-[1.1] font-bold tracking-[-.02em] break-words max-md:text-[22px]"
        >
          {{ fiche.displayName }}
        </h1>
      </div>

      <p
        class="m-0 rounded-[8px] border border-alerte-bord bg-alerte-doux px-3 py-2.5 text-[13px] leading-[1.55]"
      >
        <template v-if="regime === 'en_attente'">
          <b>Personne vivante.</b> Tant que votre rattachement n’est pas validé, aucune personne
          vivante ne vous est accessible — y compris celle à laquelle vous vous rattachez. Sa place
          dans l’arbre reste visible.
        </template>
        <template v-else>
          <b>Personne vivante.</b> Elle ne fait pas partie de votre cercle de visibilité : ni dates,
          ni lieux, ni sources, ni photos ne vous sont communiqués. Sa place dans l’arbre reste
          visible.
        </template>
      </p>

      <IdentiteFiche :fiche="fiche" :seulement="LIGNES" />

      <RouterLink
        :to="{ name: 'arbre', query: { personne: fiche.id } }"
        class="flex h-11 items-center justify-center rounded-champ border border-ligne font-semibold transition-colors hover:border-accent md:self-start md:px-5"
      >
        Voir dans l’arbre
      </RouterLink>
    </div>
  </div>
</template>
