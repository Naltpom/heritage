<script setup lang="ts">
/**
 * La réserve du filtre « vivants uniquement ».
 *
 * ────────────────────────────────────────────────────────────────────
 * **POURQUOI CETTE PHRASE EST À L'ÉCRAN ET PAS SEULEMENT DANS LE CODE.**
 *
 * Deux comptes qui allument ce filtre peuvent voir deux ensembles différents, et
 * c'est **légitime** : une barre est faite de dates, et « les personnes vivantes
 * hors cercle sont absentes de `barres` » (§ 9.1). Depuis la révision du
 * 2026-10-01 de l'ADR 0003, pour un membre validé, ce ne sont plus que les fiches
 * à confidentialité forcée hors de son cercle — la phrase à l'écran le dit ainsi. Elles manquent donc *avant*
 * que ce filtre s'applique — il ne peut pas les faire revenir, et aucun réglage
 * de cet écran ne le pourra.
 *
 * Sans cette phrase, l'écart entre deux comptes se lit comme un bogue, et c'est
 * le filtre qu'on vient accuser. Un commentaire dans le source ne protège que
 * celui qui l'ouvre ; celui qui regarde l'écran n'a que ce qui est écrit dessus.
 *
 * **Second aveu : `vivant` est calculé, pas relevé.** Il sort de la règle de
 * confidentialité appliquée à l'état stocké de la fiche. Une fiche dont le décès
 * n'a jamais été renseigné se retrouve donc du côté des vivants — ce n'est pas
 * le filtre qui se trompe, c'est la fiche qui est muette, et le filtre est
 * fidèle à ce qu'elle dit. On le dit plutôt que de le corriger ici : compenser
 * dans le rendu masquerait une donnée à reprendre.
 *
 * **AUCUN DÉCOMPTE.** Ni barres affichées, ni barres masquées, ni personnes
 * vivantes. Un compteur trahirait par soustraction le nombre de personnes que le
 * cercle protège — c'est la raison pour laquelle `Disposition.ignorees` est
 * calculé sans jamais être montré, et cette phrase ne doit pas rouvrir la porte
 * que ce compte-là garde fermée.
 *
 * Composant séparé, et non vingt lignes de prose de plus dans la vue : rendu par
 * ses seules propriétés — ici aucune —, il se rend hors navigateur et sa
 * présence se **prouve**. Un bloc noyé dans `ChronologieView.vue` ne serait
 * atteignable par aucun test de ce projet, qui n'a ni `jsdom` ni
 * `@vue/test-utils` : la réserve exigée à l'écran aurait alors tenu à ce que
 * personne ne la supprime par mégarde.
 * ────────────────────────────────────────────────────────────────────
 */
</script>

<template>
  <div role="note" class="border-b border-ligne bg-panneau px-4 py-2">
    <p class="text-xs text-attenue">
      <strong class="font-semibold text-texte">Les vivants.</strong>
      Une fiche confidentielle — fermée par décision d’administration — n’expose aucune
      date, donc aucune barre : elle ne peut pas s’afficher ici, même vivante. Un autre
      compte ne verra donc pas forcément les mêmes personnes, et cet écart n’est pas une
      anomalie.
      « Vivant » est par ailleurs <em>calculé</em> à partir de la fiche : une fiche dont
      le décès n’a jamais été renseigné reste du côté des vivants.
    </p>
  </div>
</template>
