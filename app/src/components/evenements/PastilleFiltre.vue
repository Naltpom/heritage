<script setup lang="ts">
/**
 * Une pastille de filtre : le bouton `aria-pressed` de la charte.
 *
 * La même forme existe déjà quatre fois dans le produit — la barre de l'arbre,
 * et trois groupes de celle de la chronologie. La carte et les anniversaires en
 * auraient fait une cinquième et une sixième ; elle est donc extraite ici, pour
 * ces deux écrans-là.
 *
 * **Les barres existantes ne sont pas reprises dans le même lot.** Elles
 * marchent, elles sont testées, et la refonte mobile touche déjà ces
 * fichiers-là : deux chantiers dans un même fichier au même moment, c'est un
 * conflit qu'on se prépare pour un gain purement cosmétique.
 *
 * **`multiple` n'est pas décoratif.** Un groupe où plusieurs pastilles
 * coexistent est une case à cocher, pas un bouton radio ; `aria-pressed`
 * convient aux deux, mais le lecteur d'écran n'annonce correctement le second
 * que si une seule est enfoncée. Ici, plusieurs genres s'allument ensemble.
 */
const {
  libelle,
  actif,
  disponible = true,
  titre,
} = defineProps<{
  libelle: string
  actif: boolean
  /** À `false`, la pastille est désactivée **et dit pourquoi** par son `title` —
   *  jamais silencieusement vide. */
  disponible?: boolean
  titre: string
}>()

const emit = defineEmits<{ basculer: [] }>()
</script>

<template>
  <button
    type="button"
    class="cursor-pointer whitespace-nowrap rounded-pastille border px-2.5 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-45 max-md:w-full max-md:py-2"
    :class="
      actif
        ? 'border-accent bg-accent-doux font-semibold text-texte'
        : 'border-ligne bg-fond text-attenue hover:border-accent'
    "
    :aria-pressed="actif"
    :disabled="!disponible"
    :title="titre"
    @click="emit('basculer')"
  >
    {{ libelle }}
  </button>
</template>
