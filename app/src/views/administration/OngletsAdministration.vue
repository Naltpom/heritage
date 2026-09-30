<script setup lang="ts">
/**
 * Les quatre sous-sections de l'administration — Demandes · Invitations ·
 * Utilisateurs · Sources —, en onglets à filet **en tête de la barre d'écran**
 * (handoff « En-tête, barres d'écran et fiche », § 2, « File de validation »).
 *
 * Ils remplacent la barre de navigation que portait `AdministrationView`.
 * Chaque sous-écran les pose dans l'emplacement `#vue` de sa `BarreEcran` :
 * c'est ce qui les garde visibles sur téléphone (un rang de 42 px, en quatre
 * colonnes), là où le reste de la barre part dans le feuillet.
 *
 * **Ce sont des liens, pas des onglets ARIA.** Chaque section est une route
 * enfant, avec son chemin et donc son lien profond ; les noms de route
 * (`administration.demandes`, `.invitations`, `.utilisateurs`, `.sources`)
 * sont un contrat, et rien ici ne construit d'URL. Un `role="tablist"`
 * promettrait une navigation aux flèches qui n'existe pas ;
 * `aria-current="page"`, posé par `RouterLink`, dit déjà lequel est ouvert.
 */
const SECTIONS = [
  { nom: 'administration.demandes', libelle: 'Demandes' },
  { nom: 'administration.invitations', libelle: 'Invitations' },
  { nom: 'administration.utilisateurs', libelle: 'Utilisateurs' },
  { nom: 'administration.sources', libelle: 'Sources' },
] as const
</script>

<template>
  <nav
    class="flex gap-0.5 max-md:grid max-md:w-full max-md:grid-cols-4 max-md:border-t max-md:border-ligne"
    aria-label="Sections de l’administration"
  >
    <RouterLink
      v-for="section in SECTIONS"
      :key="section.nom"
      :to="{ name: section.nom }"
      class="box-border flex h-8 items-center justify-center border-b-2 border-transparent px-3 text-[13.5px] text-attenue hover:text-texte max-md:h-[42px] max-md:px-1"
      active-class="onglet-actif"
    >
      {{ section.libelle }}
    </RouterLink>
  </nav>
</template>

<style scoped>
/* L'onglet actif : texte accent, filet de 2 px. L'inactif garde un filet
   transparent, pour que l'actif ne décale rien. */
.onglet-actif {
  border-bottom-color: var(--color-accent);
  color: var(--color-accent);
  font-weight: 600;
}
</style>
