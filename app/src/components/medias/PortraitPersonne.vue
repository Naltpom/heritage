<script setup lang="ts">
/**
 * Le portrait, en tête de fiche.
 *
 * **C'est la seconde moitié de la demande n° 3 du commanditaire** : « possibilité
 * d'ajouter des preuves sur les personnes : photo, et photo de portrait de la
 * personne aussi ». Deux choses distinctes sur le même écran — le portrait, ici,
 * et les preuves, dans la galerie.
 *
 * **Ce composant n'affiche que le portrait *validé*.** Il lit `fiche.portrait`,
 * que le serveur ne remplit qu'avec le portrait actif — « un seul actif par
 * personne, les précédents sont conservés » (§ 6.5 du dossier). Un portrait
 * fraîchement téléversé est `en_attente` : il apparaît dans la galerie, avec sa
 * pastille, et **pas ici**. C'est voulu, et c'est la règle « un média téléversé
 * n'est pas un média validé » appliquée à l'endroit le plus visible de l'écran.
 *
 * **On affiche la vignette, pas l'original.** L'image d'archive pèse jusqu'à
 * 3 000 pixels de large : la décoder pour remplir 128 pixels coûterait à chaque
 * ouverture de fiche. L'original n'est décodé qu'à la loupe, une seule à la fois.
 *
 * Les deux URL viennent du serveur (`url`, `vignetteUrl`) et ne se fabriquent
 * pas : voir l'en-tête de `@/lib/api/medias`.
 */
import type { PortraitDeFiche } from '@/lib/api'
import type { CibleLoupe } from './medias'

const { portrait, nom } = defineProps<{ portrait: PortraitDeFiche; nom: string }>()

const emit = defineEmits<{ agrandir: [cible: CibleLoupe] }>()

function agrandir(): void {
  emit('agrandir', {
    mode: 'direct',
    url: portrait.url,
    legende: portrait.caption ?? `Portrait de ${nom}`,
    // Un portrait est une image par nature — la nature `portrait` n'accepte pas
    // un PDF. C'est la seule cible de loupe dont on peut l'affirmer sans lire
    // `mimeType`, que la charge de fiche ne porte pas.
    estImage: true,
  })
}
</script>

<template>
  <button
    type="button"
    class="shrink-0 cursor-zoom-in rounded-panneau border border-ligne bg-panneau p-0"
    :aria-label="`Agrandir le portrait de ${nom}`"
    @click="agrandir"
  >
    <img
      :src="portrait.vignetteUrl"
      :alt="portrait.caption ?? `Portrait de ${nom}`"
      class="block h-32 w-32 rounded-panneau object-cover"
      loading="lazy"
      decoding="async"
    />
  </button>
</template>
