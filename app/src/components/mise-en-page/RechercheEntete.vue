<script setup lang="ts">
/**
 * « Rechercher une personne », dans l'en-tête, sur grand écran.
 *
 * **Par l'API, jamais par un index local** : la mécanique — délai de frappe,
 * annulation, états — est celle de `useRecherchePersonnes`, partagée avec la
 * barre de recherche de l'arbre et la page des résultats. Une panne se dit (avec
 * « Réessayer »), et « Personne ne correspond. » n'apparaît qu'après la réponse.
 *
 * Choisir un résultat : si l'arbre est affiché, il le prend en charge et vole
 * vers la personne (`rechercheGlobale`) ; sinon, la fiche s'ouvre. Quand l'arbre
 * écoute, la recherche va jusqu'à 50 résultats (le plafond de l'API) : il les
 * met tous en évidence à mesure qu'on tape. Sinon elle s'arrête à 8, et
 * « Voir tous les résultats » ouvre la page `recherche`, qui en montre 50.
 *
 * **Le motif « combobox » de WAI-ARIA** : le champ porte `role="combobox"`,
 * `aria-expanded`, `aria-controls` (la liste, toujours dans le document) et
 * `aria-activedescendant` (l'option du curseur) ; le focus ne quitte jamais le
 * champ, `↑` `↓` parcourent, Entrée choisit, Échap ferme.
 *
 * `/` donne le focus au champ, depuis n'importe quel écran.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import type { ResultatDeRecherche } from '@/lib/api'
import { aUnAbonne, publierCurseur, publierResultats, transmettreChoix } from '@/composables/rechercheGlobale'
import { LIMITE_MAXIMALE, curseurSuivant, useRecherchePersonnes } from '@/composables/useRecherchePersonnes'

/** Ce que l'en-tête montre sans l'arbre : de quoi choisir, pas une énumération. */
const LIMITE_ENTETE = 8

const ID_LISTE = 'recherche-entete-liste'
const ID_TOUS = 'resultat-entete-tous'
const idOption = (i: number): string => `resultat-entete-${i}`

const routeur = useRouter()
const champ = ref<HTMLInputElement | null>(null)
const curseur = ref(0)
const ouvert = ref(false)
/** Le plafond de la dernière requête : 50 quand l'arbre écoute, 8 sinon. */
const limiteDemandee = ref(LIMITE_ENTETE)

const recherche = useRecherchePersonnes({
  limite: () => (limiteDemandee.value = aUnAbonne() ? LIMITE_MAXIMALE : LIMITE_ENTETE),
  delai: 140,
  surResultats(texte, liste) {
    curseur.value = 0
    if (texte.length >= 2) ouvert.value = true
    publierResultats(texte, liste)
  },
})
const { texte, etat, resultats, tronque, message } = recherche

/** « Voir tous les résultats » : seulement s'il y en a d'autres, et une page pour les montrer. */
const lienTous = computed(() => etat.value === 'resultats' && tronque.value && limiteDemandee.value < LIMITE_MAXIMALE)
const nombreOptions = computed(() => resultats.value.length + (lienTous.value ? 1 : 0))
const deplie = computed(() => ouvert.value && etat.value !== 'inactif')
const optionActive = computed(() => {
  if (!deplie.value || etat.value !== 'resultats') return undefined
  if (curseur.value < resultats.value.length) return idOption(curseur.value)
  return lienTous.value ? ID_TOUS : undefined
})

function deplacer(pas: number): void {
  const n = nombreOptions.value
  if (!n) return
  curseur.value = curseurSuivant(curseur.value, pas, n)
  // L'arbre ne suit que les personnes, pas le lien vers la page.
  if (curseur.value < resultats.value.length) publierCurseur(curseur.value)
  const id = optionActive.value
  if (id) document.getElementById(id)?.scrollIntoView({ block: 'nearest' })
}

function fermer(): void {
  ouvert.value = false
  champ.value?.blur()
}

function choisir(r: ResultatDeRecherche): void {
  recherche.vider()
  fermer()
  if (!transmettreChoix(r)) void routeur.push({ name: 'personne.detail', params: { id: r.personId } })
}

function voirTous(): void {
  const q = texte.value.trim()
  recherche.vider()
  fermer()
  void routeur.push({ name: 'recherche', query: { q } })
}

function surTouche(e: KeyboardEvent): void {
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    ouvert.value = true
    deplacer(1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    ouvert.value = true
    deplacer(-1)
  } else if (e.key === 'Enter') {
    if (etat.value === 'erreur') {
      recherche.reessayer()
      return
    }
    if (etat.value !== 'resultats') return
    const r = resultats.value[curseur.value]
    if (r) choisir(r)
    else if (lienTous.value) voirTous()
  } else if (e.key === 'Escape') {
    e.stopPropagation()
    recherche.vider()
    fermer()
  }
}

function surToucheGlobale(e: KeyboardEvent): void {
  if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return
  const cible = e.target as HTMLElement | null
  if (cible && (cible.tagName === 'INPUT' || cible.tagName === 'TEXTAREA' || cible.isContentEditable)) return
  // Le champ n'est visible qu'au-delà de `md` ; sur téléphone, l'écran garde le sien.
  if (!champ.value || champ.value.offsetParent === null) return
  e.preventDefault()
  e.stopImmediatePropagation()
  champ.value.focus()
}

onMounted(() => window.addEventListener('keydown', surToucheGlobale, true))
onBeforeUnmount(() => window.removeEventListener('keydown', surToucheGlobale, true))
</script>

<template>
  <div class="relative w-[280px]">
    <svg class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-attenue" width="15" height="15" viewBox="0 0 20 20" aria-hidden="true">
      <circle cx="8.5" cy="8.5" r="5.5" fill="none" stroke="currentColor" stroke-width="1.7" />
      <path d="M13 13l4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
    </svg>
    <input
      ref="champ"
      v-model="texte"
      type="search"
      role="combobox"
      autocomplete="off"
      aria-autocomplete="list"
      :aria-expanded="deplie"
      :aria-controls="ID_LISTE"
      :aria-activedescendant="optionActive"
      placeholder="Rechercher une personne"
      aria-label="Rechercher une personne"
      class="h-9 w-full rounded-champ border border-ligne bg-fond pr-9 pl-9 text-[13px] outline-none placeholder:text-attenue focus:border-accent"
      @keydown="surTouche"
      @focus="ouvert = true"
      @blur="ouvert = false"
    />
    <kbd
      class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 rounded border border-ligne px-1.5 font-sans text-[11px] text-attenue"
      aria-hidden="true"
    >/</kbd>
    <!-- Toujours dans le document (`v-show`) : `aria-controls` doit désigner un élément qui existe. -->
    <div
      v-show="deplie"
      class="absolute inset-x-0 top-10 z-50 max-h-[60vh] overflow-y-auto rounded-panneau border border-ligne bg-panneau py-1 shadow-panneau"
      @pointerdown.prevent
    >
      <div class="px-3 pt-1 pb-1.5 text-[11px] text-attenue" role="status" aria-live="polite">
        <template v-if="etat === 'chargement'">Recherche…</template>
        <template v-else-if="etat === 'erreur'">
          <span class="text-alerte" data-erreur-recherche>{{ message }}</span>
          <button type="button" class="ml-2 font-semibold text-accent hover:underline" @click="recherche.reessayer()">Réessayer</button>
        </template>
        <template v-else-if="etat === 'vide'">Personne ne correspond.</template>
        <template v-else-if="etat === 'resultats'">{{ resultats.length }}{{ tronque ? '+' : '' }} personne{{ resultats.length > 1 ? 's' : '' }} · ↑ ↓ pour parcourir</template>
      </div>
      <ul :id="ID_LISTE" role="listbox" aria-label="Personnes trouvées" class="m-0 list-none p-0">
        <template v-if="etat === 'resultats'">
          <li
            v-for="(r, i) in resultats"
            :id="idOption(i)"
            :key="r.personId"
            role="option"
            :aria-selected="i === curseur"
            class="flex w-full cursor-pointer flex-col items-start px-3 py-1.5 text-left leading-snug hover:bg-accent-doux"
            :class="i === curseur ? 'bg-accent-doux' : ''"
            @click="choisir(r)"
          >
            <span class="text-[13px] font-semibold">{{ r.displayName }}</span>
            <span v-if="r.ligneDates" class="font-mono text-[11.5px] text-attenue">{{ r.ligneDates }}</span>
          </li>
          <li
            v-if="lienTous"
            :id="ID_TOUS"
            role="option"
            :aria-selected="curseur === resultats.length"
            data-voir-tous
            class="cursor-pointer border-t border-ligne px-3 pt-2 pb-1.5 text-[13px] font-semibold text-accent hover:bg-accent-doux"
            :class="curseur === resultats.length ? 'bg-accent-doux' : ''"
            @click="voirTous"
          >
            Voir tous les résultats
          </li>
        </template>
      </ul>
    </div>
  </div>
</template>
