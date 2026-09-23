<script setup lang="ts">
/**
 * « Rechercher une personne », dans l'en-tête, sur grand écran.
 *
 * **Par l'API, jamais par un index local** (`rechercherPersonnes`) : le serveur
 * exclut les vivants hors cercle, un index bâti sur les nœuds les ferait
 * réapparaître.
 *
 * Choisir un résultat : si l'arbre est affiché, il le prend en charge et vole
 * vers la personne (`rechercheGlobale`) ; sinon, la fiche s'ouvre. Quand l'arbre
 * écoute, la recherche va jusqu'à 50 résultats (le plafond de l'API) : il les
 * met tous en évidence à mesure qu'on tape.
 *
 * `/` donne le focus au champ, depuis n'importe quel écran.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { rechercherPersonnes, type ResultatDeRecherche } from '@/lib/api'
import { aUnAbonne, publierCurseur, publierResultats, transmettreChoix } from '@/composables/rechercheGlobale'

const routeur = useRouter()
const champ = ref<HTMLInputElement | null>(null)
const texte = ref('')
const resultats = ref<ResultatDeRecherche[]>([])
const tronque = ref(false)
const curseur = ref(0)
const ouvert = ref(false)
let minuterie = 0
let requete: AbortController | null = null

watch(texte, (q) => {
  window.clearTimeout(minuterie)
  requete?.abort()
  const t = q.trim()
  if (t.length < 2) {
    resultats.value = []
    publierResultats(t, [])
    return
  }
  minuterie = window.setTimeout(() => {
    requete = new AbortController()
    rechercherPersonnes(t, aUnAbonne() ? 50 : 8, requete.signal)
      .then((r) => {
        resultats.value = r.elements
        tronque.value = r.tronque
        curseur.value = 0
        ouvert.value = true
        publierResultats(t, r.elements)
      })
      .catch(() => {})
  }, 140)
})

function deplacer(i: number): void {
  const n = resultats.value.length
  if (!n) return
  curseur.value = (i + n) % n
  publierCurseur(curseur.value)
  document.getElementById(`resultat-entete-${curseur.value}`)?.scrollIntoView({ block: 'nearest' })
}

function choisir(r: ResultatDeRecherche): void {
  texte.value = ''
  resultats.value = []
  ouvert.value = false
  champ.value?.blur()
  if (!transmettreChoix(r)) void routeur.push({ name: 'personne.detail', params: { id: r.personId } })
}

function surTouche(e: KeyboardEvent): void {
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    ouvert.value = true
    deplacer(curseur.value + 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    ouvert.value = true
    deplacer(curseur.value - 1)
  } else if (e.key === 'Enter') {
    const r = resultats.value[curseur.value]
    if (r) choisir(r)
  } else if (e.key === 'Escape') {
    e.stopPropagation()
    texte.value = ''
    ouvert.value = false
    champ.value?.blur()
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
onBeforeUnmount(() => {
  window.removeEventListener('keydown', surToucheGlobale, true)
  window.clearTimeout(minuterie)
  requete?.abort()
})
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
      autocomplete="off"
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
    <div
      v-if="ouvert && texte.trim().length >= 2"
      class="absolute inset-x-0 top-10 z-50 max-h-[60vh] overflow-y-auto rounded-panneau border border-ligne bg-panneau py-1 shadow-panneau"
      role="listbox"
      @pointerdown.prevent
    >
      <div class="px-3 pt-1 pb-1.5 text-[11px] text-attenue">
        <template v-if="resultats.length">{{ resultats.length }}{{ tronque ? '+' : '' }} personne{{ resultats.length > 1 ? 's' : '' }} · ↑ ↓ pour parcourir</template>
        <template v-else>Personne ne correspond.</template>
      </div>
      <button
        v-for="(r, i) in resultats"
        :id="`resultat-entete-${i}`"
        :key="r.personId"
        type="button"
        role="option"
        :aria-selected="i === curseur"
        class="flex w-full flex-col items-start px-3 py-1.5 text-left leading-snug hover:bg-accent-doux"
        :class="i === curseur ? 'bg-accent-doux' : ''"
        @click="choisir(r)"
      >
        <span class="text-[13px] font-semibold">{{ r.displayName }}</span>
        <span v-if="r.ligneDates" class="font-mono text-[11.5px] text-attenue">{{ r.ligneDates }}</span>
      </button>
    </div>
  </div>
</template>
