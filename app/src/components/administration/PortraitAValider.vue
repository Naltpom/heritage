<script setup lang="ts">
/**
 * La photo d'inscription d'un compte en attente, sur sa ligne de l'écran
 * « Utilisateurs » — ADR 0013.
 *
 * ── Pourquoi elle est là ────────────────────────────────────────────────────
 *
 * À la validation, le serveur **transfère** cette photo sur la fiche, et elle
 * en devient le portrait actif — visible de tout le cercle de la personne.
 * Pour un compte qui s'est trouvé (`recherche_confirmee`), la validation se
 * fait ici, d'un clic sur « Valider le rattachement » : sans cette vignette,
 * l'administrateur publiait une photo qu'il n'avait jamais vue. Le transfert
 * reste automatique ; c'est l'aveuglement qui est corrigé.
 *
 * D'où la mention, écrite en toutes lettres plutôt que suggérée par une
 * infobulle : « deviendra le portrait de sa fiche à la validation ». Celui qui
 * clique doit savoir ce qu'il publie.
 *
 * Les URL viennent du serveur (`portraitEnAttente`), jamais reconstruites ici —
 * la même règle que la carte « À rattacher ». `data-portrait-en-attente` porte
 * l'identifiant du média : c'est la prise stable des tests de rendu.
 */
import type { PhotoARattacher } from '@/lib/api'

const { photo, nom } = defineProps<{
  /** `null` : pas de photo, ou plus rien à valider — le composant ne rend rien. */
  photo: PhotoARattacher | null
  /** Le nom affiché du compte, pour le texte alternatif. */
  nom: string
}>()
</script>

<template>
  <div v-if="photo !== null" class="mt-2 flex items-center gap-3" :data-portrait-en-attente="photo.mediaId">
    <a
      :href="photo.url"
      target="_blank"
      rel="noopener"
      class="shrink-0"
      title="Voir la photo en grand"
    >
      <img
        :src="photo.vignetteUrl"
        :alt="`Photo déposée par ${nom} à l’inscription`"
        class="size-12 rounded-full border border-ligne object-cover"
      />
    </a>
    <p class="text-xs text-attenue">
      Photo de l’inscription : <strong class="font-semibold text-texte">deviendra le portrait de sa fiche à la validation</strong>.
    </p>
  </div>
</template>
