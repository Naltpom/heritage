<script setup lang="ts">
/**
 * Les photos et les textes des fiches visibles de `/arbre`, et les noms de foyer, en
 * coordonnées **écran** (`libelles.ts`).
 *
 * Ce calque est rendu par Vue — c'est du texte qui change de contenu selon la place
 * —, mais **jamais à chaque image** : la vue ne lui passe que ce qu'elle a calculé
 * d'après l'instantané du cadrage (`useCadrage`), publié à la fin d'un geste, à un
 * changement de niveau, ou au plus toutes les 160 ms. Entre deux, une transformation
 * posée sur son conteneur le garde collé aux boîtes (révision du 2026-10-14 de
 * l'ADR 0007).
 *
 * **Aucune `opacity`.** Un texte estompé prend une couleur partiellement transparente
 * (`attenuer`) ; une photo estompée reçoit un voile de la couleur du fond par-dessus ;
 * une silhouette, `fill-opacity` — c'est de la peinture, pas une couche de
 * composition par fiche (c'était `opacity: 0.3` et `0.35`, une couche chacune).
 */
import { attenuer } from './apparence'
import type { Libelle, LibelleFoyer } from './libelles'

defineProps<{
  libelles: Libelle[]
  foyers: LibelleFoyer[]
  /** La ligne directe est demandée : les noms d'ancêtres grossissent. */
  surligner: boolean
  /** C'est la vôtre : les noms d'ancêtres se dorent. */
  surlignerTraits: boolean
}>()

/** Ce qu'un texte estompé garde de sa couleur (c'était une opacité de 0,35). */
const ALPHA_TEXTE = 0.35
/** Le voile d'une photo estompée : il en laisse voir 30 % (c'était une opacité de 0,3). */
const VOILE = 'color-mix(in srgb, var(--h-fond) 70%, transparent)'

const couleurTexte = (t: Libelle): string =>
  attenuer(t.directe ? 'var(--hi-direct-texte)' : 'var(--h-texte)', t.estompe ? ALPHA_TEXTE : 1)
const couleurAttenue = (t: Libelle): string => attenuer('var(--h-attenue)', t.estompe ? ALPHA_TEXTE : 1)
const cadrePhoto = (t: Libelle): Record<string, string> => ({
  left: `${t.photo.x}px`,
  top: `${t.photo.y}px`,
  width: `${t.photo.d}px`,
  height: `${t.photo.d}px`,
})
</script>

<template>
  <!-- Compact : un rond ; Portrait : un carré aux coins arrondis, le cadre d'une photo. -->
  <template v-for="t in libelles" :key="`photo-${t.id}`">
    <img
      v-if="t.photo.src"
      :src="t.photo.src"
      alt=""
      loading="lazy"
      decoding="async"
      class="pointer-events-none absolute object-cover"
      :class="t.forme === 'portrait' ? 'rounded-[14%]' : 'rounded-full'"
      :style="cadrePhoto(t)"
    />
    <svg
      v-else
      class="pointer-events-none absolute"
      :class="t.forme === 'portrait' ? (t.estompe ? 'cadre-portrait estompe rounded-[14%]' : 'cadre-portrait rounded-[14%]') : ''"
      viewBox="0 0 40 40"
      aria-hidden="true"
      :style="cadrePhoto(t)"
    >
      <circle cx="20" cy="13.6" r="6.8" class="silhouette" :class="t.estompe ? 'estompee' : ''" />
      <path d="M6.8,36.8 a13.2,13.2 0 0 1 26.4,0 Z" class="silhouette" :class="t.estompe ? 'estompee' : ''" />
    </svg>
    <span
      v-if="t.photo.src && t.estompe"
      class="pointer-events-none absolute"
      :class="t.forme === 'portrait' ? 'rounded-[14%]' : 'rounded-full'"
      :style="{ ...cadrePhoto(t), background: VOILE }"
    />
  </template>
  <template v-for="t in libelles" :key="t.id">
    <div
      v-if="t.forme === 'compact' && t.t1 !== ''"
      class="pointer-events-none absolute flex origin-top-left flex-col justify-center overflow-hidden leading-tight"
      :style="{ left: `${t.x}px`, top: `${t.y}px`, width: `${t.w}px`, height: `${t.h}px`, transform: t.reduction < 1 ? `scale(${t.reduction})` : undefined }"
    >
      <span class="truncate font-semibold" :style="{ fontSize: `${t.taille}px`, color: couleurTexte(t) }">{{ t.t1 }}</span>
      <span v-if="t.t2" class="truncate font-mono text-[10.5px]" :style="{ color: couleurAttenue(t) }">{{ t.t2 }}</span>
    </div>
    <!-- Portrait : des hauteurs de ligne fixes (15, 15, 13), celles de `LIGNES_PORTRAIT`. -->
    <div
      v-else-if="t.forme === 'portrait' && t.nom !== ''"
      class="pointer-events-none absolute flex origin-top-left flex-col items-center overflow-hidden text-center"
      :style="{ left: `${t.x}px`, top: `${t.y}px`, width: `${t.w}px`, height: `${t.h}px`, transform: t.reduction < 1 ? `scale(${t.reduction})` : undefined }"
    >
      <span
        v-if="t.nom"
        class="block h-[15px] max-w-full truncate text-xs leading-[15px] font-semibold"
        :class="t.restreinte ? '' : 'tracking-[.02em] uppercase'"
        :style="{ color: t.restreinte ? couleurAttenue(t) : couleurTexte(t) }"
      >{{ t.nom }}</span>
      <span
        v-if="t.prenom"
        class="block h-[15px] max-w-full truncate text-[11.5px] leading-[15px]"
        :class="t.restreinte ? 'font-semibold' : ''"
        :style="{ color: t.restreinte ? couleurAttenue(t) : couleurTexte(t) }"
      >{{ t.prenom }}</span>
      <span
        v-for="(ligne, i) in t.dates"
        :key="i"
        class="block h-[13px] max-w-full truncate font-mono text-[10.5px] leading-[13px]"
        :style="{ color: couleurAttenue(t) }"
      >{{ ligne }}</span>
    </div>
  </template>

  <div
    v-for="c in foyers"
    :key="c.cle"
    class="pointer-events-none absolute -translate-x-1/2 font-bold whitespace-nowrap [text-shadow:0_1px_3px_var(--h-fond)]"
    :style="{
      left: `${c.x}px`,
      top: `${c.y}px`,
      fontSize: c.ancetres && surligner ? '11.5px' : '10.5px',
      color: c.ancetres && surlignerTraits ? 'var(--hi-libelle-accent)' : 'var(--h-attenue)',
    }"
  >
    {{ c.nom }}
  </div>
</template>

<style scoped>
.silhouette {
  fill: var(--h-attenue);
  fill-opacity: 0.35;
}
/* Estompée : 30 % de la silhouette, dans la peinture même (`fill-opacity`). */
.silhouette.estompee {
  fill-opacity: 0.105;
}
/* L'emplacement d'une photo absente, en Portrait : le cadre se voit, à la taille d'une vraie. */
.cadre-portrait {
  background: color-mix(in srgb, var(--h-attenue) 14%, transparent);
}
.cadre-portrait.estompe {
  background: color-mix(in srgb, var(--h-attenue) 4.2%, transparent);
}
</style>
