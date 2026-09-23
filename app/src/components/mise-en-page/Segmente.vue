<script setup lang="ts" generic="T extends string | number">
/**
 * Le segmenté de la refonte (barre d'écran, « vue ») : un groupe de boutons dont
 * un seul est actif. Il **émet sans retenir** : la valeur vit dans la vue.
 *
 * `colonnes` : sur téléphone, les boutons se partagent la largeur en grille
 * (l'arbre en a 4, la chronologie et la carte 2).
 */
defineProps<{
  options: readonly { valeur: T; libelle: string; compte?: number | undefined }[]
  valeur: T
  etiquette: string
  colonnes?: number | undefined
}>()
const emit = defineEmits<{ choisir: [valeur: T] }>()
</script>

<template>
  <div
    class="gap-[2px] rounded-[8px] border border-ligne bg-panneau p-[3px] md:inline-flex max-md:grid"
    :style="colonnes ? { gridTemplateColumns: `repeat(${colonnes}, minmax(0, 1fr))` } : { display: 'inline-flex' }"
    role="group"
    :aria-label="etiquette"
  >
    <button
      v-for="o in options"
      :key="String(o.valeur)"
      type="button"
      class="inline-flex h-7 items-center justify-center gap-1.5 rounded-md px-[11px] text-[13px] whitespace-nowrap max-md:h-9 max-md:px-1.5 max-md:text-[11.5px]"
      :class="o.valeur === valeur ? 'bg-accent-doux font-semibold text-texte' : 'text-attenue hover:text-texte'"
      :aria-pressed="o.valeur === valeur"
      @click="emit('choisir', o.valeur)"
    >
      {{ o.libelle }}
      <span v-if="o.compte !== undefined" class="text-[11px] text-attenue tabular-nums">{{ o.compte }}</span>
    </button>
  </div>
</template>
