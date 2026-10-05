<script setup lang="ts">
/**
 * **Importer une photo ou un fichier** et **Prendre une photo** — deux gros boutons, côte à
 * côte, partout où l'application reçoit une image.
 *
 * Le défaut réparé, dans les mots du commanditaire (05/10/2026) : « deux CTA bien
 * compréhensibles — jusque-là, partout sur l'app, ce n'est pas explicite ». Le
 * `<input type="file">` nu affichait « Parcourir… » ou « Choisir un fichier »
 * selon le navigateur, et rien ne disait qu'on pouvait photographier directement.
 * Un seul composant, pour que le geste se lise de la même façon sur chaque écran.
 *
 * Deux champs de fichier cachés, deux vrais boutons. « Prendre une photo » pose
 * `capture` : sur téléphone, l'appareil photo s'ouvre directement ; sur
 * ordinateur, l'attribut est ignoré et le sélecteur de fichiers s'ouvre — rien
 * n'est cassé, rien n'est à cacher. `capture` n'accepte que `environment`
 * (caméra arrière : un acte, une photo posée sur la table) et `user` (caméra
 * avant) ; une autre valeur resterait du HTML valide et laisserait le téléphone
 * choisir, souvent mal — d'où le type fermé.
 *
 * Les champs s'ouvrent par `click()` **dans le gestionnaire d'un vrai clic** : un
 * navigateur refuse d'ouvrir un sélecteur hors d'un geste de l'utilisateur. Ils
 * sont vidés après chaque choix, pour qu'un même fichier puisse être repris.
 *
 * Le composant ne garde rien : il émet les fichiers, le parent décide.
 */
import { useTemplateRef } from 'vue'

const {
  importer = 'Importer une photo ou un fichier',
  importerAide = 'Galerie, fichiers ou PDF',
  prendre = 'Prendre une photo',
  prendreAide = 'Avec l’appareil photo',
  accept = 'image/*,application/pdf',
  multiple = true,
  capture = 'environment',
  desactive = false,
} = defineProps<{
  importer?: string
  importerAide?: string
  prendre?: string
  prendreAide?: string
  /** Les types proposés par « Importer ». « Prendre » ne produit qu'une image. */
  accept?: string
  /** Plusieurs fichiers à la fois depuis « Importer ». */
  multiple?: boolean
  capture?: 'environment' | 'user'
  desactive?: boolean
}>()

const emit = defineEmits<{ fichiers: [fichiers: File[]] }>()

const champImport = useTemplateRef<HTMLInputElement>('champImport')
const champPhoto = useTemplateRef<HTMLInputElement>('champPhoto')

function recevoir(evenement: Event): void {
  const cible = evenement.target as HTMLInputElement
  const fichiers = Array.from(cible.files ?? [])
  cible.value = ''
  if (fichiers.length > 0) emit('fichiers', fichiers)
}
</script>

<template>
  <div class="grid grid-cols-2 gap-2">
    <button
      type="button"
      class="flex min-h-20 flex-col items-center justify-center gap-1 rounded-panneau border border-accent bg-panneau px-2 py-2.5 text-center text-accent transition-colors hover:bg-accent-doux disabled:cursor-not-allowed disabled:opacity-55"
      :disabled="desactive"
      @click="champImport?.click()"
    >
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="3" y="4" width="18" height="16" rx="2" />
        <circle cx="9" cy="9.5" r="1.8" />
        <path d="M21 16l-5-5-8 9" />
      </svg>
      <span class="text-sm font-semibold">{{ importer }}</span>
      <span class="text-xs text-attenue">{{ importerAide }}</span>
    </button>

    <button
      type="button"
      class="flex min-h-20 flex-col items-center justify-center gap-1 rounded-panneau border border-accent bg-panneau px-2 py-2.5 text-center text-accent transition-colors hover:bg-accent-doux disabled:cursor-not-allowed disabled:opacity-55"
      :disabled="desactive"
      @click="champPhoto?.click()"
    >
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
        <path d="M4 8h3l2-3h6l2 3h3v11H4z" />
        <circle cx="12" cy="13" r="3.5" />
      </svg>
      <span class="text-sm font-semibold">{{ prendre }}</span>
      <span class="text-xs text-attenue">{{ prendreAide }}</span>
    </button>

    <input
      ref="champImport"
      type="file"
      :multiple="multiple"
      :accept="accept"
      class="sr-only"
      tabindex="-1"
      aria-hidden="true"
      @change="recevoir"
    />
    <input
      ref="champPhoto"
      type="file"
      accept="image/*"
      :capture="capture"
      class="sr-only"
      tabindex="-1"
      aria-hidden="true"
      @change="recevoir"
    />
  </div>
</template>
