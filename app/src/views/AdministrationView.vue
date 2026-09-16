<script setup lang="ts">
/**
 * Administration — le cadre, et rien d'autre.
 *
 * Les sous-écrans sont des **routes enfants** (`app/README.md`, « Ajouter une
 * route ») : la file de validation, les invitations, les utilisateurs. Chacun a
 * son chemin, donc son lien profond — un administrateur qui met la file en
 * favori doit retomber sur la file, pas sur un onglet par défaut.
 *
 * Cette vue ne charge rien et ne décide rien. Elle porte le titre, la barre de
 * navigation, et le point de rendu. Tout ce qui écrit vit dans les enfants, et
 * ce qui suspend les écritures — la bascule « voir comme un membre » — est dit
 * par le bandeau permanent du socle, au-dessus de tout l'écran.
 */
</script>

<template>
  <div class="mx-auto w-full max-w-5xl px-4 py-8">
    <h1 class="text-xl font-semibold tracking-tight">Administration</h1>

    <nav class="mt-4 mb-6 border-b border-ligne" aria-label="Sections de l’administration">
      <ul class="flex flex-wrap gap-x-1 gap-y-1">
        <li v-for="lien in [
          { nom: 'administration.demandes', libelle: 'Demandes' },
          { nom: 'administration.invitations', libelle: 'Invitations' },
          { nom: 'administration.utilisateurs', libelle: 'Utilisateurs' },
        ]" :key="lien.nom">
          <RouterLink
            :to="{ name: lien.nom }"
            class="-mb-px inline-block border-b-2 px-3 py-2 text-sm font-semibold transition-colors"
            active-class="border-accent text-accent"
            exact-active-class="border-accent text-accent"
          >
            {{ lien.libelle }}
          </RouterLink>
        </li>
      </ul>
    </nav>

    <RouterView />
  </div>
</template>

<style scoped>
/* L'onglet inactif : filet transparent pour que l'actif ne décale rien. */
a:not(.border-accent) {
  border-color: transparent;
  color: var(--color-attenue);
}
a:not(.border-accent):hover {
  color: var(--color-texte);
}
</style>
