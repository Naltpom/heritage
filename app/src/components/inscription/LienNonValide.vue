<script setup lang="ts">
/**
 * « Ce lien n'est pas valide. » — et pas un mot de plus.
 *
 * Les trois routes publiques du § 4 rendent `404` sur un jeton inconnu, mal
 * formé, expiré, révoqué ou déjà consommé, avec **le même corps dans les cinq
 * cas**. L'écran n'en sait donc pas davantage, et c'est délibéré : un `403`
 * confirmerait que la route existe et que le jeton a une forme valide ; un
 * « votre invitation a expiré » distinguerait un jeton ayant existé d'un jeton
 * inventé. On ne révèle pas l'existence du point d'entrée (§ 6.1 du dossier).
 *
 * Trois formulations sont donc interdites ici, et `lienInvitation.test.ts` les
 * interdit pour de bon :
 *
 * - **« expiré », « déjà utilisé », « révoqué »** — chacune dit que le lien a
 *   existé ;
 * - **« demandez un nouveau lien »** — la phrase suppose qu'il y avait quelque
 *   chose à demander, donc que celui-ci était bien une invitation ;
 * - **« Réessayer »** — le même jeton donnera le même `404`, et le bouton ferait
 *   tourner en rond quelqu'un dont le seul recours est humain.
 *
 * Ce qu'il reste à dire tient en une phrase : écrivez à la personne qui vous a
 * envoyé l'invitation. C'est vrai dans les cinq cas, et c'est la seule chose qui
 * débloque réellement la situation.
 */
import PanneauBase from '@/components/base/PanneauBase.vue'
</script>

<template>
  <PanneauBase>
    <!-- `role="status"` plutôt que `alert` : ce n'est pas une panne, c'est une
         impasse. Le ton de l'annonce vocale suit celui du texte. -->
    <div role="status">
      <h2 class="text-base font-semibold">Ce lien n’est pas valide</h2>

      <!-- La cause suggérée porte sur le **transport** du lien, jamais sur son
           sort côté serveur : « coupé en chemin » n'apprend rien à personne,
           « il n'est plus actif » dirait qu'il l'a été. -->
      <p class="mt-2 text-sm text-attenue">
        Nous ne pouvons pas ouvrir d’inscription avec ce lien. Il a peut-être été coupé
        en chemin : certains logiciels de courriel tronquent les liens longs, et un
        lien recopié à la main perd facilement un caractère.
      </p>

      <p class="mt-2 text-sm text-attenue">
        Le plus simple est d’écrire à la personne qui vous a envoyé cette invitation :
        elle pourra vérifier, et vous en adresser une autre si besoin.
      </p>
    </div>
  </PanneauBase>
</template>
