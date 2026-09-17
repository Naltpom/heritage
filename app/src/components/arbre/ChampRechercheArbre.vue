<script setup lang="ts">
/**
 * Le champ de recherche de l'arbre — **une définition, deux emplacements**.
 *
 * ────────────────────────────────────────────────────────────────────
 * **POURQUOI CE COMPOSANT EXISTE, ET CE QU'IL REMPLACE.**
 *
 * Sur téléphone, la barre d'outils part dans un tiroir, et le champ de recherche
 * n'a rien à y faire : chercher est le geste qu'on répète, filtrer sur
 * « vérifiées » se fait une fois. Il doit donc vivre en bas de l'écran, hors du
 * tiroir.
 *
 * La première version employait `<Teleport>` : le champ restait déclaré dans la
 * barre, et Vue le **déplaçait** vers un pied de page fixe quand la largeur
 * passait sous le seuil. Le raisonnement tenait — un tiroir fermé est en
 * `display: none`, et le CSS seul ne fait pas sortir un enfant d'un ancêtre qui
 * ne s'affiche pas. Le résultat, lui, ne tenait pas : en production, sur la
 * largeur même que le dispositif visait, la bascule levait
 * `Cannot read properties of null (reading 'emitsOptions')` — une erreur interne
 * de Vue pendant le patch. Le rendu de cette branche mourait là, le champ
 * restait dans le tiroir, et le bouton « Filtres » n'ouvrait plus rien : deux
 * symptômes, une seule cause.
 *
 * Ce que le téléportage avait de fragile se dit en une phrase : il faut
 * **déplacer un sous-arbre vivant, après le montage, à travers une frontière
 * `display: none`**. Rien de tout cela n'est nécessaire. Deux `v-if` mutuellement
 * exclusifs montent le champ là où il doit être et nulle part ailleurs — un seul
 * est monté à la fois, donc un seul `id="arbre-recherche"`, un seul état, et
 * aucun déplacement.
 * ────────────────────────────────────────────────────────────────────
 *
 * `aria-live="polite"` sur le décompte : une recherche qui n'annonce rien laisse
 * un lecteur d'écran devant un canevas qui a changé sans le dire.
 */
import { ref } from 'vue'

const { resultat, tronque, chargement, compact } = defineProps<{
  /** Le décompte déjà rédigé (« 12 fiches », « aucune »), ou `''`. */
  resultat: string
  tronque: boolean
  chargement: boolean
  /** Vrai dans le pied de page du téléphone : pleine largeur, décompte court. */
  compact: boolean
}>()

const emit = defineEmits<{
  /** `Entrée` dans le champ : cadrer la vue sur les résultats. */
  cadrer: []
}>()

const question = defineModel<string>({ required: true })

const champ = ref<HTMLInputElement | null>(null)

function focus(): void {
  champ.value?.focus()
  champ.value?.select()
}

function surTouche(ev: KeyboardEvent): void {
  if (ev.key === 'Escape') {
    question.value = ''
    champ.value?.blur()
  }
  if (ev.key === 'Enter') {
    ev.preventDefault()
    emit('cadrer')
  }
}

defineExpose({ focus })
</script>

<template>
  <!--
    `display` est décidé d'un seul côté de la condition, jamais par deux classes
    concurrentes : `flex` et `contents` sont des utilitaires de même spécificité,
    et c'est l'ordre de la feuille de style — pas celui de l'attribut — qui
    trancherait entre eux.

    Au large, l'enveloppe ne génère aucune boîte : le champ et son décompte
    deviennent des enfants directs de la barre d'outils, et gardent donc ses
    `gap-3` et son retour à la ligne, exactement comme avant.
  -->
  <div :class="compact ? 'flex w-full items-center gap-2 px-4 py-2' : 'contents'" role="search">
    <label class="lecteur-ecran" for="arbre-recherche">Chercher une personne</label>
    <input
      id="arbre-recherche"
      ref="champ"
      v-model="question"
      type="search"
      class="rounded-champ border border-ligne bg-fond text-texte"
      :class="
        compact
          ? 'min-w-0 flex-1 px-2.5 py-2 text-[13px]'
          : 'min-w-[150px] flex-[0_1_250px] px-2.5 py-1 text-[13px]'
      "
      placeholder="Chercher un nom, une date, un lieu…"
      autocomplete="off"
      spellcheck="false"
      @keydown="surTouche"
    />
    <span
      v-if="compact"
      class="min-w-[74px] text-right text-xs font-semibold text-accent"
      aria-live="polite"
    >
      <template v-if="chargement">…</template>
      <template v-else>{{ resultat }}</template>
    </span>
    <span v-else class="min-w-[74px] text-xs font-semibold text-accent" aria-live="polite">
      <template v-if="chargement">…</template>
      <template v-else>{{ resultat }}</template>
      <span v-if="tronque" class="font-normal text-attenue"> (liste plafonnée)</span>
    </span>
  </div>
</template>
