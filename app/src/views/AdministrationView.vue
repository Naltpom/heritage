<script setup lang="ts">
/**
 * Administration — le cadre, et rien d'autre.
 *
 * Les sous-écrans sont des **routes enfants** (`app/README.md`, « Ajouter une
 * route ») : la file de validation, les invitations, les utilisateurs. Chacun a
 * son chemin, donc son lien profond — un administrateur qui met la file en
 * favori doit retomber sur la file, pas sur un onglet par défaut.
 *
 * Cette vue ne charge rien et ne décide rien. Depuis la refonte de l'en-tête
 * (handoff § 2), elle ne porte plus ni titre ni barre de navigation : le titre
 * de l'écran est dans l'en-tête de l'application, et les trois sections sont
 * des onglets à filet **en tête de la barre d'écran** de chaque enfant
 * (`administration/OngletsAdministration.vue`) — parce que c'est l'enfant qui
 * tient les filtres que cette barre porte (la file), et qu'une barre à deux
 * propriétaires serait une barre à deux états. Chaque enfant pose donc sa
 * barre, pleine largeur, puis son contenu dans la colonne habituelle.
 *
 * Tout ce qui écrit vit dans les enfants, et ce qui suspend les écritures — la
 * bascule « voir comme un membre » — est dit par le bandeau permanent du socle,
 * au-dessus de tout l'écran.
 */
</script>

<template>
  <RouterView />
</template>
