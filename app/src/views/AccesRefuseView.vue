<script setup lang="ts">
/**
 * Accès refusé.
 *
 * Destination des refus de niveau « administration » pour un membre rattaché :
 * il est bien connecté, sa session est valide, l'écran ne lui est simplement pas
 * destiné. Le renvoyer à la connexion lui ferait croire à une session tombée.
 *
 * Un compte **en attente** ne passe pas par ici : il est renvoyé vers l'écran
 * d'attente, qui lui explique ce qu'il attend (`acces.ts`).
 */
import PanneauBase from '@/components/base/PanneauBase.vue'
</script>

<template>
  <div class="mx-auto w-full max-w-xl p-6">
    <PanneauBase titre="Accès refusé">
      <p class="text-sm">Cet écran est réservé à l'administration de l'arbre.</p>
      <p class="mt-3 text-sm">
        <RouterLink :to="{ name: 'arbre' }" class="font-semibold text-accent underline">
          Revenir à l'arbre
        </RouterLink>
      </p>
    </PanneauBase>
  </div>
</template>
