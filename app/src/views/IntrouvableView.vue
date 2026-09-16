<script setup lang="ts">
/**
 * Page introuvable.
 *
 * Accessible sans session : une URL fausse ne doit pas rebondir sur la
 * connexion, qui ferait croire à une page existante mais protégée. Le front
 * suit ici la même logique que l'API, où « `404` plutôt que `403` » est la règle
 * générale (§ 15.3).
 */
import PanneauBase from '@/components/base/PanneauBase.vue'
import { useSession } from '@/composables/useSession'

const { connecte } = useSession()
</script>

<template>
  <div class="mx-auto w-full max-w-xl p-6">
    <PanneauBase titre="Page introuvable">
      <p class="text-sm">Cette adresse ne correspond à aucun écran de Heritage.</p>
      <p class="mt-3 text-sm">
        <RouterLink
          :to="connecte ? { name: 'arbre' } : { name: 'connexion' }"
          class="font-semibold text-accent underline"
        >
          {{ connecte ? "Revenir à l'arbre" : 'Aller à la connexion' }}
        </RouterLink>
      </p>
    </PanneauBase>
  </div>
</template>
