<script setup lang="ts">
/**
 * **`/arbre-review` — l'arbre en rectangles, redessiné en couches** (demande du
 * 22/10/2026).
 *
 * C'est EXACTEMENT l'écran de `/arbre` (`views/ArbreGroupesView.vue`) : mêmes données,
 * mêmes cartes, mêmes interactions, même barre, mêmes filtres, même volet — et les mêmes
 * règles de rendu (ADR 0007, que `arbre2/rendu-adr0007.test.ts` vérifie sur cet écran-là).
 * On ne lui passe qu'un autre moteur : `arbre-review/disposition.ts`, qui pose les cartes
 * et route les lignes autrement (des couloirs entre les générations, le moins de
 * croisements, chaque fratrie sous ses parents, aucune ligne sous une carte ; un
 * rectangle par quart, comme sur `/arbre`, depuis les retours du 22/10/2026). Les deux
 * pages coexistent pour qu'on les compare ; aucune entrée de menu n'y mène.
 */
import ArbreGroupesView from '@/views/ArbreGroupesView.vue'
import { disposerEnRevue } from './disposition'
</script>

<template>
  <ArbreGroupesView moteur="revue" :disposer-les-groupes="disposerEnRevue" />
</template>
