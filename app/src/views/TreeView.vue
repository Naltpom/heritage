<script setup lang="ts">
/**
 * `/tree` — l'arbre total, écrit de zéro.
 *
 * Tout le graphe (ascendants, descendants, collatéraux, conjoints), placé par
 * `tree/layout.ts`. Au chargement, la vue part de l'arbre entier et vole vers
 * moi. La recherche en pied de page vole vers la personne visée à mesure qu'on
 * tape.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { requeteApi, type Graphe } from '@/lib/api'
import { CARD_H, CARD_W, layoutTree, type PlacedPerson, type TreeLayout } from '@/tree/layout'
import { usePanZoom } from '@/tree/usePanZoom'

const svg = ref<SVGSVGElement | null>(null)
const footer = ref<HTMLElement | null>(null)
const input = ref<HTMLInputElement | null>(null)
const { view, zoomAt, flyTo, wasDrag, handlers } = usePanZoom(svg)

const layout = shallowRef<TreeLayout | null>(null)
const egoId = ref<string | null>(null)
const error = ref<string | null>(null)
const loading = ref(true)
const size = ref({ w: 0, h: 0 })

/* Chargement ------------------------------------------------------------ */
async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const graph = await requeteApi<Graphe>('/arbre/graphe')
    egoId.value = graph.ego
    layout.value = layoutTree(graph)
    await nextTick()
    measure()
    fitAll(0)
    window.setTimeout(() => focusMe(1100), 350)
  } catch (e) {
    error.value = e instanceof Error ? e.message : "L'arbre n'a pas pu être chargé."
  } finally {
    loading.value = false
  }
}

/* Cadrage ---------------------------------------------------------------- */
const isPhone = (): boolean => size.value.w < 768
function measure(): void {
  const r = svg.value?.getBoundingClientRect()
  if (r) size.value = { w: r.width, h: r.height }
}
/** Le centre de la zone visible, au-dessus du pied de page. */
function screenCenter(): { sx: number; sy: number } {
  const fh = footer.value?.getBoundingClientRect().height ?? 0
  return { sx: size.value.w / 2, sy: Math.max(80, (size.value.h - fh) / 2 + 20) }
}
function fitAll(ms = 700): void {
  const l = layout.value
  if (!l) return
  const fh = footer.value?.getBoundingClientRect().height ?? 0
  const k = Math.min(size.value.w / (l.bounds.w + 80), (size.value.h - fh - 60) / (l.bounds.h + 80))
  const { sx, sy } = screenCenter()
  void flyTo(l.bounds.x + l.bounds.w / 2, l.bounds.y + l.bounds.h / 2, k, sx, sy, ms)
}
function focusOn(p: PlacedPerson, ms = 650, k?: number): void {
  const { sx, sy } = screenCenter()
  const kk = k ?? Math.max(view.value.k, isPhone() ? 1 : 0.9)
  void flyTo(p.x + CARD_W / 2, p.y + CARD_H / 2, kk, sx, sy, ms)
}
function focusMe(ms = 650): void {
  const me = egoId.value ? layout.value?.byId.get(egoId.value) : undefined
  if (me) focusOn(me, ms, isPhone() ? 1.05 : 1)
}
function zoomBy(f: number): void {
  const { sx, sy } = screenCenter()
  zoomAt(sx, sy, f)
}

/* Sélection ---------------------------------------------------------------- */
const selectedId = ref<string | null>(null)
const selected = computed(() => (selectedId.value ? layout.value?.byId.get(selectedId.value) ?? null : null))

function select(p: PlacedPerson, fly = true): void {
  selectedId.value = p.id
  if (fly) focusOn(p)
}
function onCardClick(p: PlacedPerson): void {
  if (wasDrag()) return
  // Un renvoi mène à la personne, là où elle est posée.
  const target = p.ref ? layout.value?.byId.get(p.ref) : undefined
  if (target) select(target)
  else select(p, false)
}
function onBackgroundClick(): void {
  if (!wasDrag()) selectedId.value = null
}

interface Rel {
  label: string
  people: PlacedPerson[]
}
const relations = computed<Rel[]>(() => {
  const l = layout.value
  const p = selected.value
  if (!l || !p) return []
  const fams = (ids: string[] | undefined) => (ids ?? []).map((f) => l.families.get(f)!)
  const get = (ids: string[]): PlacedPerson[] =>
    [...new Set(ids)].filter((id) => id !== p.id).map((id) => l.byId.get(id)!).filter(Boolean)
  const up = fams(l.asChild.get(p.id))
  const down = fams(l.asParent.get(p.id))
  return [
    { label: 'Parents', people: get(up.flatMap((f) => f.parents)) },
    { label: 'Conjoints', people: get(down.flatMap((f) => f.parents)) },
    { label: 'Enfants', people: get(down.flatMap((f) => f.children)) },
    { label: 'Fratrie', people: get(up.flatMap((f) => f.children)) },
  ].filter((r) => r.people.length)
})

/* Recherche -------------------------------------------------------------- */
const query = ref('')
const searchOpen = ref(false)
const cursor = ref(0)
const norm = (s: string): string =>
  s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()

const index = computed(() =>
  (layout.value?.persons ?? []).filter((p) => !p.ref).map((p) => ({ p, text: norm(p.node.displayName) })),
)
const matches = computed<PlacedPerson[]>(() => {
  const tokens = norm(query.value).split(/\s+/).filter(Boolean)
  if (!tokens.length) return []
  const scored: { p: PlacedPerson; s: number }[] = []
  for (const { p, text } of index.value) {
    if (!tokens.every((t) => text.includes(t))) continue
    const words = text.split(/[\s-]+/)
    const s = tokens.reduce((a, t) => a + (words.some((w) => w.startsWith(t)) ? 0 : 1), 0)
    scored.push({ p, s })
  }
  // Même pertinence : le plus proche de moi d'abord (génération).
  scored.sort((a, b) => a.s - b.s || Math.abs(a.p.level) - Math.abs(b.p.level) || a.p.node.displayName.localeCompare(b.p.node.displayName))
  return scored.map((e) => e.p)
})
const matchSet = computed(() => new Set(matches.value.map((p) => p.id)))

let typing = 0
watch(query, () => {
  cursor.value = 0
  searchOpen.value = true
  window.clearTimeout(typing)
  typing = window.setTimeout(() => {
    const first = matches.value[0]
    if (first) select(first)
  }, 280)
})
function go(i: number): void {
  const n = matches.value.length
  if (!n) return
  cursor.value = (i + n) % n
  select(matches.value[cursor.value]!)
}
function pick(p: PlacedPerson): void {
  select(p)
  searchOpen.value = false
  input.value?.blur()
}
function onSearchKey(e: KeyboardEvent): void {
  if (e.key === 'ArrowDown') (e.preventDefault(), go(cursor.value + 1))
  else if (e.key === 'ArrowUp') (e.preventDefault(), go(cursor.value - 1))
  else if (e.key === 'Enter') {
    const p = matches.value[cursor.value]
    if (p) pick(p)
  } else if (e.key === 'Escape') clearSearch()
}
function clearSearch(): void {
  query.value = ''
  searchOpen.value = false
}

/* Rendu ------------------------------------------------------------------ */
const transform = computed(() => `translate(${view.value.x} ${view.value.y}) scale(${view.value.k})`)
const lit = computed(() => {
  // Ce qui reste net : la personne choisie et ses liens, ou les résultats.
  if (matchSet.value.size) return matchSet.value
  return null
})
function clip(s: string, n: number): string {
  return s.length > n ? `${s.slice(0, n - 1).trimEnd()}…` : s
}
const nameOf = (p: PlacedPerson): string =>
  (p.ref ? '↪ ' : '') + clip(p.node.etiquetteNom || p.node.displayName, p.ref ? 19 : 21)
function datesOf(p: PlacedPerson): string {
  const d = p.node.etiquetteDates
  if (!d || d === '—') return ''
  return d === 'vivant·e' ? '' : d
}
function linkLit(people: string[]): boolean {
  return selectedId.value !== null && people.includes(selectedId.value)
}
// Seules les fiches proches de l'écran sont dessinées.
const visible = computed(() => {
  const l = layout.value
  if (!l) return []
  const { x, y, k } = view.value
  const pad = 200 / k
  const x0 = -x / k - pad
  const y0 = -y / k - pad
  const x1 = (size.value.w - x) / k + pad
  const y1 = (size.value.h - y) / k + pad
  return l.persons.filter((p) => p.x + CARD_W > x0 && p.x < x1 && p.y + CARD_H > y0 && p.y < y1)
})
const detail = computed(() => (view.value.k > 0.35 ? 'full' : view.value.k > 0.16 ? 'name' : 'box'))

function onResize(): void {
  measure()
}
const stopGesture = (e: Event): void => e.preventDefault()
onMounted(() => {
  measure()
  window.addEventListener('resize', onResize)
  document.addEventListener('gesturestart', stopGesture)
  void load()
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', onResize)
  document.removeEventListener('gesturestart', stopGesture)
  window.clearTimeout(typing)
})
</script>

<template>
  <div class="tree">
    <svg
      ref="svg"
      class="canvas"
      :class="{ dim: lit || selectedId }"
      @pointerdown="handlers.onPointerDown"
      @pointermove="handlers.onPointerMove"
      @pointerup="handlers.onPointerUp"
      @pointercancel="handlers.onPointerUp"
      @wheel="handlers.onWheel"
      @click.self="onBackgroundClick"
    >
      <g v-if="layout" :transform="transform">
        <path
          v-for="l in layout.links"
          :key="l.key"
          :d="l.d"
          class="link"
          :class="[l.kind, { divorced: l.divorced, pending: l.pending, on: linkLit(l.people) }]"
          :stroke-width="Math.max(1.5, 1.5 / Math.max(view.k, 0.3))"
        />
        <g
          v-for="p in visible"
          :key="p.id"
          :transform="`translate(${p.x} ${p.y})`"
          class="card"
          :class="[
            p.node.sex,
            {
              me: p.id === egoId,
              selected: p.id === selectedId,
              match: matchSet.has(p.id),
              current: matches[cursor] && matches[cursor]!.id === p.id,
              faded: lit ? !lit.has(p.id) && p.id !== selectedId : false,
              pending: p.node.enAttente,
              ref: p.ref,
            },
          ]"
          @click.stop="onCardClick(p)"
        >
          <title>{{ p.ref ? `${p.node.displayName} — voir sa place dans l'arbre` : p.node.displayName }}</title>
          <rect class="box" :width="CARD_W" :height="CARD_H" rx="10" />
          <rect class="sexbar" x="0" y="0" width="5" :height="CARD_H" rx="2.5" />
          <template v-if="detail !== 'box'">
            <text class="name" x="16" :y="detail === 'full' && (datesOf(p) || p.ref) ? 25 : 34">{{ nameOf(p) }}</text>
            <text v-if="detail === 'full' && p.ref" class="dates" x="16" y="44">voir dans l'arbre</text>
            <text v-else-if="detail === 'full' && datesOf(p)" class="dates" x="16" y="44">{{ datesOf(p) }}</text>
          </template>
          <text v-if="p.id === egoId && detail !== 'box'" class="tag" :x="CARD_W - 12" y="17" text-anchor="end">
            moi
          </text>
        </g>
      </g>
    </svg>

    <header class="top">
      <RouterLink to="/" class="chip" aria-label="Retour à l'accueil">←</RouterLink>
      <span v-if="layout" class="count">{{ layout.persons.length }} personnes</span>
    </header>

    <div class="zoom" role="group" aria-label="Zoom">
      <button type="button" aria-label="Zoomer" @click="zoomBy(1.4)">+</button>
      <button type="button" aria-label="Dézoomer" @click="zoomBy(1 / 1.4)">−</button>
      <button type="button" aria-label="Voir tout l'arbre" @click="fitAll()">⤢</button>
      <button type="button" class="me-btn" aria-label="Revenir sur moi" @click="focusMe()">◎</button>
    </div>

    <p v-if="loading" class="state">Chargement de l'arbre…</p>
    <p v-else-if="error" class="state error">
      {{ error }}
      <button type="button" @click="load">Réessayer</button>
    </p>

    <footer ref="footer" class="foot">
      <section v-if="selected && !searchOpen" class="sheet" aria-live="polite">
        <div class="sheet-head">
          <div>
            <h2>{{ selected.node.displayName }}</h2>
            <p v-if="selected.node.etiquetteDates" class="sheet-dates">{{ selected.node.etiquetteDates }}</p>
          </div>
          <button type="button" class="x" aria-label="Fermer" @click="selectedId = null">×</button>
        </div>
        <div v-for="r in relations" :key="r.label" class="rel">
          <span class="rel-label">{{ r.label }}</span>
          <button v-for="q in r.people" :key="q.id" type="button" class="pill" @click="select(q)">
            {{ q.node.etiquetteNom || q.node.displayName }}
          </button>
        </div>
        <RouterLink
          v-if="selected.node.personId !== null"
          class="more"
          :to="{ name: 'personne.detail', params: { id: selected.node.personId } }"
        >
          Voir la fiche →
        </RouterLink>
      </section>

      <ul v-if="searchOpen && query.trim()" class="results" role="listbox">
        <li v-if="!matches.length" class="empty">Personne ne correspond.</li>
        <li
          v-for="(p, i) in matches.slice(0, 8)"
          :key="p.id"
          role="option"
          :aria-selected="i === cursor"
          :class="{ active: i === cursor }"
          @pointerdown.prevent
          @click="pick(p)"
        >
          <span>{{ p.node.displayName }}</span>
          <small>{{ p.node.etiquetteDates && p.node.etiquetteDates !== '—' ? p.node.etiquetteDates : '' }}</small>
        </li>
      </ul>

      <div class="bar">
        <input
          ref="input"
          v-model="query"
          type="search"
          enterkeyhint="search"
          autocomplete="off"
          placeholder="Rechercher une personne…"
          aria-label="Rechercher une personne"
          @focus="searchOpen = true"
          @blur="searchOpen = false"
          @keydown="onSearchKey"
        />
        <template v-if="query.trim()">
          <span class="n">{{ matches.length ? `${cursor + 1}/${matches.length}` : '0' }}</span>
          <button type="button" aria-label="Résultat précédent" :disabled="!matches.length" @pointerdown.prevent @click="go(cursor - 1)">‹</button>
          <button type="button" aria-label="Résultat suivant" :disabled="!matches.length" @pointerdown.prevent @click="go(cursor + 1)">›</button>
          <button type="button" aria-label="Effacer" @pointerdown.prevent @click="clearSearch">×</button>
        </template>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.tree {
  --bg: #f6f3ee;
  --ink: #1f1c18;
  --muted: #6f675d;
  --card: #fffdf9;
  --line: #b9ada0;
  --edge: #8f8274;
  --accent: #b4532a;
  --m: #4f7cac;
  --f: #c0607f;
  --u: #9a9a9a;
  --panel: rgba(255, 253, 249, 0.96);
  position: fixed;
  inset: 0;
  background: var(--bg);
  color: var(--ink);
  overflow: hidden;
  font-family: 'Inter Variable', system-ui, sans-serif;
}
@media (prefers-color-scheme: dark) {
  .tree {
    --bg: #16140f;
    --ink: #f1ebe2;
    --muted: #a39a8e;
    --card: #221f19;
    --line: #4a443b;
    --edge: #7b7165;
    --accent: #e58a5c;
    --m: #7ea6d4;
    --f: #e08aa6;
    --panel: rgba(34, 31, 25, 0.96);
  }
}

.canvas {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  touch-action: none;
  user-select: none;
  -webkit-user-select: none;
  cursor: grab;
}
.canvas:active {
  cursor: grabbing;
}

/* Liens */
.link {
  fill: none;
  stroke: var(--edge);
  stroke-linejoin: round;
  transition: opacity 0.2s;
}
.link.couple {
  stroke: var(--accent);
  stroke-opacity: 0.7;
}
.link.divorced {
  stroke-dasharray: 6 5;
}
.link.pending {
  stroke-dasharray: 3 4;
}
.canvas.dim .link {
  opacity: 0.35;
}
.canvas.dim .link.on {
  opacity: 1;
  stroke: var(--accent);
}

/* Fiches */
.card {
  cursor: pointer;
  transition: opacity 0.2s;
}
.card .box {
  fill: var(--card);
  stroke: var(--line);
  stroke-width: 1.2;
}
.card .sexbar {
  fill: var(--u);
}
.card.masculin .sexbar {
  fill: var(--m);
}
.card.feminin .sexbar {
  fill: var(--f);
}
.card.ref .box {
  fill: transparent;
  stroke-dasharray: 5 4;
}
.card.ref .name {
  font-weight: 500;
  fill: var(--muted);
}
.card.pending .box {
  stroke-dasharray: 4 4;
}
.card .name {
  font-size: 14px;
  font-weight: 600;
  fill: var(--ink);
}
.card .dates {
  font-size: 12px;
  fill: var(--muted);
  font-variant-numeric: tabular-nums;
}
.card .tag {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  fill: var(--accent);
}
.card.me .box {
  stroke: var(--accent);
  stroke-width: 3;
}
.card.match .box {
  stroke: var(--accent);
  stroke-width: 2;
}
.card.selected .box,
.card.current .box {
  stroke: var(--accent);
  stroke-width: 3.5;
  fill: color-mix(in srgb, var(--accent) 10%, var(--card));
}
.card.faded {
  opacity: 0.28;
}

/* Surcouches */
.top {
  position: absolute;
  top: max(12px, env(safe-area-inset-top));
  left: 12px;
  display: flex;
  gap: 8px;
  align-items: center;
  pointer-events: none;
}
.chip,
.zoom button {
  pointer-events: auto;
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--panel);
  border: 1px solid var(--line);
  color: var(--ink);
  font-size: 18px;
  text-decoration: none;
  box-shadow: 0 2px 10px rgb(0 0 0 / 0.08);
}
.count {
  font-size: 13px;
  color: var(--muted);
  background: var(--panel);
  border-radius: 999px;
  padding: 4px 10px;
  border: 1px solid var(--line);
}
.zoom {
  position: absolute;
  top: max(12px, env(safe-area-inset-top));
  right: 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.zoom .me-btn {
  color: var(--accent);
}
.state {
  position: absolute;
  top: 40%;
  left: 50%;
  transform: translateX(-50%);
  color: var(--muted);
  text-align: center;
}
.state.error {
  color: var(--accent);
}

/* Pied de page */
.foot {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 0 12px max(12px, env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: center;
  pointer-events: none;
}
.foot > * {
  pointer-events: auto;
  width: 100%;
  max-width: 560px;
}
.bar {
  display: flex;
  align-items: center;
  gap: 4px;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 16px;
  padding: 6px 6px 6px 14px;
  box-shadow: 0 6px 24px rgb(0 0 0 / 0.12);
  backdrop-filter: blur(8px);
}
.bar input {
  flex: 1;
  min-width: 0;
  border: 0;
  outline: 0;
  background: transparent;
  color: var(--ink);
  font-size: 16px; /* pas de zoom automatique d'iOS au focus */
  padding: 8px 0;
}
.bar input::-webkit-search-cancel-button {
  display: none;
}
.bar .n {
  font-size: 12px;
  color: var(--muted);
  font-variant-numeric: tabular-nums;
  padding: 0 4px;
}
.bar button {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 0;
  background: transparent;
  color: var(--ink);
  font-size: 20px;
}
.bar button:disabled {
  opacity: 0.3;
}
.results {
  list-style: none;
  margin: 0;
  padding: 6px;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 16px;
  box-shadow: 0 6px 24px rgb(0 0 0 / 0.12);
  max-height: 45dvh;
  overflow: auto;
}
.results li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 10px;
  cursor: pointer;
}
.results li.active {
  background: color-mix(in srgb, var(--accent) 14%, transparent);
}
.results small {
  color: var(--muted);
  white-space: nowrap;
}
.results .empty {
  color: var(--muted);
  cursor: default;
}
.sheet {
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 16px;
  padding: 14px 16px;
  box-shadow: 0 6px 24px rgb(0 0 0 / 0.12);
  max-height: 40dvh;
  overflow: auto;
}
.sheet-head {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}
.sheet h2 {
  font-size: 17px;
  font-weight: 700;
  margin: 0;
}
.sheet-dates {
  margin: 2px 0 0;
  color: var(--muted);
  font-size: 13px;
}
.x {
  border: 0;
  background: transparent;
  color: var(--muted);
  font-size: 22px;
  width: 32px;
  height: 32px;
}
.rel {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  margin-top: 10px;
}
.rel-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted);
  width: 100%;
}
.pill {
  border: 1px solid var(--line);
  background: var(--card);
  color: var(--ink);
  border-radius: 999px;
  padding: 5px 11px;
  font-size: 13px;
}
.more {
  display: inline-block;
  margin-top: 12px;
  color: var(--accent);
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
}
</style>
