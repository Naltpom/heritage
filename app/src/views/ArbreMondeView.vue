<script setup lang="ts">
/**
 * **Le Monde de l'arbre** — `/arbre/monde`, pour tout membre rattaché.
 *
 * L'arbre familial en maquette 3D, dans le carnet d'archiviste du Monde du
 * suivi : le lecteur au sommet du tronc, ses ancêtres en couronne (un étage par
 * génération), ses descendants en racines sous l'île, un médaillon par personne
 * coloré par sa lignée. Voir `components/arbre-monde/disposition.ts`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Mêmes données que `/arbre`, même confidentialité.** Le graphe est celui de
 * `GET /api/arbre/graphe`, déjà passé par le cercle de visibilité côté serveur
 * (ADR 0003) : une personne vivante hors cercle arrive anonyme et le reste ici.
 * Le graphe est revalidé contre celui que `/arbre` garde d'un F5 à l'autre
 * (`histoire/memoire.ts`) : passer de l'un à l'autre ne le retélécharge pas.
 *
 * **Ce qui vit ici** : tout ce qui se lit au clavier et au lecteur d'écran —
 * recherche, cadrages, ligne directe, panneau de la personne, légende. La 3D
 * (`arbre-monde/scene.ts`) et Three.js sont chargés à part, au montage.
 *
 * **Paramètres d'adresse** : `#theme=dark|light`, `#personne=<id>` (ouvre la
 * fiche de cette personne), `#ligne=1` (la ligne directe allumée),
 * `#vue=couronne|racines`.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { chargerGraphe, oublierEtag, retenirEtag, type Graphe } from '@/lib/api'
import { CLE_ETAG_GRAPHE } from '@/lib/api/routes'
import { useSession } from '@/composables/useSession'
import { identiteDuLecteur, memoireDeLArbre } from '@/histoire/memoire'
import { ligneDe } from '@/histoire/ligneDirecte'
import { libelleGeneration } from '@/histoire/lignees'
import { chercher, disposerArbre, type DispositionDeLArbre, type Feuille, type Teinte } from '@/components/arbre-monde/disposition'
import { LIBELLES_TEINTE, cssDeTeinte } from '@/components/arbre-monde/apparence'
import type { SceneDeLArbre } from '@/components/arbre-monde/scene'

const { session } = useSession()

/* ───────────────── Le graphe ───────────────── */

const graphe = shallowRef<Graphe | null>(null)
const erreur = ref<string | null>(null)
const disposition = computed<DispositionDeLArbre | null>(() => (graphe.value ? disposerArbre(graphe.value) : null))

/** Comme `/arbre` : l'`ETag` gardé pour ce lecteur, et le graphe gardé sur un `304`. */
async function obtenirLeGraphe(signal: AbortSignal): Promise<Graphe> {
  const identite = identiteDuLecteur(session.value)
  const etag = identite ? await memoireDeLArbre.etagGarde(identite) : null
  if (etag !== null) retenirEtag(CLE_ETAG_GRAPHE, etag)
  else oublierEtag(CLE_ETAG_GRAPHE)
  let reponse = await chargerGraphe(signal)
  if (reponse.statut === 'inchange') {
    const garde = identite && etag !== null ? await memoireDeLArbre.grapheGarde(identite, etag) : null
    if (garde) return garde
    oublierEtag(CLE_ETAG_GRAPHE)
    reponse = await chargerGraphe(signal)
    if (reponse.statut === 'inchange') throw new Error('L’arbre n’a pas pu être chargé.')
  }
  const recu = reponse.etag
  if (identite && recu !== null && !recu.startsWith('W/')) void memoireDeLArbre.garderLeGraphe(identite, recu, reponse.donnees)
  return reponse.donnees
}

/* ───────────────── Thème ───────────────── */

type ModeTheme = 'auto' | 'clair' | 'nuit'
const CLE_THEME = 'heritage.monde.theme'
const modeTheme = ref<ModeTheme>('auto')
const systemeSombre = ref(false)
const nuit = computed(() => modeTheme.value === 'nuit' || (modeTheme.value === 'auto' && systemeSombre.value))
const LIBELLES_THEME: Record<ModeTheme, string> = { auto: 'Auto', clair: 'Clair', nuit: 'Nuit' }
function basculerTheme(): void {
  const ordre: ModeTheme[] = ['auto', 'clair', 'nuit']
  modeTheme.value = ordre[(ordre.indexOf(modeTheme.value) + 1) % 3]!
  try {
    localStorage.setItem(CLE_THEME, modeTheme.value)
  } catch {
    // Stockage refusé : le choix vaut pour cette visite.
  }
}

/* ───────────────── Choix, ligne directe, recherche ───────────────── */

const choisie = ref<Feuille | null>(null)
const survolee = ref<Feuille | null>(null)
/** La ligne directe allumée : celle du lecteur, ou celle de la personne choisie. */
const ligneDe_ = ref<string | null>(null)
const largeEcran = ref(true)

const ego = computed(() => disposition.value?.feuilles.find((f) => f.ego) ?? null)

function choisir(f: Feuille | null, voler = true): void {
  choisie.value = f
  scene.value?.choisir(f?.id ?? null, voler && f !== null)
}

function basculerLigne(id: string | null): void {
  ligneDe_.value = ligneDe_.value === id ? null : id
}

watch([ligneDe_, graphe], () => {
  if (!graphe.value || ligneDe_.value === null) return scene.value?.surligner(null)
  const { ascendants, descendants } = ligneDe(graphe.value, ligneDe_.value)
  scene.value?.surligner(new Set([...ascendants, ...descendants]), true)
})

watch([choisie, largeEcran], () => {
  const ouvert = choisie.value !== null
  scene.value?.decaler(ouvert && largeEcran.value ? 210 : 0, ouvert && !largeEcran.value ? window.innerHeight * 0.24 : 0)
})

const recherche = ref('')
const resultats = computed(() => (disposition.value ? chercher(disposition.value.feuilles, recherche.value) : []))
function allerA(f: Feuille): void {
  recherche.value = ''
  choisir(f)
}

/* ───────────────── La scène ───────────────── */

const toile = ref<HTMLElement | null>(null)
const etiquettes = ref<HTMLElement[]>([])
const etages = new Map<number, HTMLElement>()
const scene = shallowRef<SceneDeLArbre | null>(null)
const sansWebGL = ref(false)
const ETIQUETTES = 14

function poserEtiquette(i: number, el: unknown): void {
  if (el instanceof HTMLElement) etiquettes.value[i] = el
}

function poserEtage(g: number, el: unknown): void {
  if (el instanceof HTMLElement) etages.set(g, el)
  else etages.delete(g)
}

const generations = computed(() => (disposition.value ? [...disposition.value.parGeneration.keys()].sort((a, b) => a - b) : []))
const aDesRacines = computed(() => generations.value.some((g) => g > 0))

let mqSombre: MediaQueryList | null = null
let mqCalme: MediaQueryList | null = null
const surSombre = (e: MediaQueryListEvent): void => void (systemeSombre.value = e.matches)
const surCalme = (e: MediaQueryListEvent): void => scene.value?.mouvementReduit(e.matches)
const surLargeur = (): void => void (largeEcran.value = window.innerWidth > 900)
function surTouche(e: KeyboardEvent): void {
  if (e.key === 'Escape' && choisie.value !== null) choisir(null)
}
const abandon = new AbortController()

onMounted(async () => {
  const p = new URLSearchParams(window.location.hash.replace(/^#/, ''))
  try {
    const garde = localStorage.getItem(CLE_THEME)
    if (garde === 'auto' || garde === 'clair' || garde === 'nuit') modeTheme.value = garde
  } catch {
    // Stockage illisible : thème automatique.
  }
  if (p.get('theme') === 'dark') modeTheme.value = 'nuit'
  if (p.get('theme') === 'light') modeTheme.value = 'clair'
  mqSombre = window.matchMedia('(prefers-color-scheme: dark)')
  systemeSombre.value = mqSombre.matches
  mqSombre.addEventListener('change', surSombre)
  mqCalme = window.matchMedia('(prefers-reduced-motion: reduce)')
  mqCalme.addEventListener('change', surCalme)
  surLargeur()
  window.addEventListener('resize', surLargeur)
  window.addEventListener('keydown', surTouche)

  const [g, module] = await Promise.allSettled([obtenirLeGraphe(abandon.signal), import('@/components/arbre-monde/scene')])
  if (g.status === 'rejected') {
    erreur.value = 'L’arbre n’a pas pu être chargé. Vérifiez la connexion, puis rechargez la page.'
    return
  }
  graphe.value = g.value
  if (module.status === 'fulfilled' && toile.value !== null) {
    try {
      scene.value = module.value.creerSceneDeLArbre(toile.value, {
        etiquettes: etiquettes.value,
        etages,
        surSurvol: (f) => (survolee.value = f),
        surClic: (f) => choisir(f, false),
        surGeste: () => undefined,
        nuit: nuit.value,
        mouvementReduit: mqCalme.matches,
      })
      if (disposition.value) scene.value.charger(disposition.value)
    } catch {
      sansWebGL.value = true
    }
  } else sansWebGL.value = true

  const vue = p.get('vue')
  if (vue === 'couronne' || vue === 'racines') scene.value?.cadrer(vue)
  if (p.get('ligne') === '1' && ego.value) ligneDe_.value = ego.value.id
  const demandee = Number(p.get('personne'))
  if (demandee) {
    const f = disposition.value?.feuilles.find((x) => x.personId === demandee)
    if (f) choisir(f)
  }
})

onBeforeUnmount(() => {
  abandon.abort()
  mqSombre?.removeEventListener('change', surSombre)
  mqCalme?.removeEventListener('change', surCalme)
  window.removeEventListener('resize', surLargeur)
  window.removeEventListener('keydown', surTouche)
  scene.value?.detruire()
  scene.value = null
})

watch(disposition, (d) => {
  if (d) scene.value?.charger(d)
})
watch(nuit, (n) => scene.value?.theme(n))

/* ───────────────── Ce que l'écran dit ───────────────── */

const nombrePersonnes = computed(() => disposition.value?.feuilles.length ?? 0)
const profondeur = computed(() => {
  const g = generations.value
  return g.length === 0 ? 0 : -Math.min(0, g[0]!)
})
const LEGENDE: Teinte[] = ['pp', 'pm', 'mp', 'mm', 'tr', 'hors']
const enCadre = (f: Feuille): string => (f.restreinte ? 'Personne vivante hors de votre cercle' : f.vivant ? 'Vivant·e' : f.dates || 'Dates inconnues')
</script>

<template>
  <div class="carnet" :data-theme="nuit ? 'dark' : 'light'" data-arbre-monde>
    <div class="carnet__ciel" aria-hidden="true" />
    <div ref="toile" class="carnet__toile" />

    <!-- Étiquettes : un réservoir que la scène remplit et place. -->
    <div class="carnet__etiquettes" aria-hidden="true">
      <span v-for="i in ETIQUETTES" :key="i" :ref="(el) => poserEtiquette(i - 1, el)" class="etiquette">
        <b data-nom /><i data-dates />
      </span>
      <span v-for="g in generations" :key="`g${g}`" :ref="(el) => poserEtage(g, el)" class="etage">
        {{ libelleGeneration(g) }} · {{ disposition?.parGeneration.get(g) }}
      </span>
    </div>

    <p v-if="erreur" class="feuille centre" role="alert">
      {{ erreur }} <RouterLink :to="{ name: 'arbre' }">Revenir à l’arbre</RouterLink>
    </p>
    <p v-else-if="!graphe" class="carnet__attente">L’arbre pousse…</p>
    <p v-if="sansWebGL" class="feuille centre" role="status">
      La 3D n’est pas disponible sur cet appareil. La recherche et les fiches restent utilisables, et
      <RouterLink :to="{ name: 'arbre' }">l’arbre classique</RouterLink> dit la même chose.
    </p>

    <!-- En-tête -->
    <header class="haut">
      <div class="feuille titre">
        <span class="cachet" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 21v-7M12 14c-3 0-5-2-5-4.5S9.5 5 12 3c2.5 2 5 4 5 6.5S15 14 12 14z" />
            <path d="M8 21h8" />
          </svg>
        </span>
        <div>
          <h1>L’arbre</h1>
          <p class="titre__sous">
            <template v-if="graphe">{{ nombrePersonnes }} personnes · {{ profondeur }} générations au-dessus de vous</template>
            <template v-else>chargement…</template>
          </p>
        </div>
      </div>

      <div class="recherche" role="search">
        <label class="sr-only" for="recherche-arbre">Chercher une personne</label>
        <input id="recherche-arbre" v-model="recherche" class="feuille" type="search" placeholder="Chercher une personne…" autocomplete="off" />
        <ul v-if="resultats.length > 0" class="feuille resultats" role="listbox" aria-label="Personnes trouvées">
          <li v-for="f in resultats" :key="f.id">
            <button type="button" @click="allerA(f)">
              <span class="pastille" :style="{ background: cssDeTeinte(f.teinte) }" aria-hidden="true" />
              <b>{{ f.nomComplet }}</b><i>{{ libelleGeneration(f.generation) }}</i>
            </button>
          </li>
        </ul>
      </div>

      <div class="outils">
        <button type="button" class="feuille bouton" @click="scene?.cadrer('ensemble')">Tout l’arbre</button>
        <button type="button" class="feuille bouton" @click="scene?.cadrer('couronne')">Ancêtres</button>
        <button v-if="aDesRacines" type="button" class="feuille bouton" @click="scene?.cadrer('racines')">Descendants</button>
        <button
          type="button"
          class="feuille bouton"
          :aria-pressed="ligneDe_ !== null && ligneDe_ === ego?.id"
          :disabled="!ego"
          @click="basculerLigne(ego?.id ?? null)"
        >
          Ma lignée directe
        </button>
        <button type="button" class="feuille bouton" :aria-label="`Thème : ${LIBELLES_THEME[modeTheme]}`" @click="basculerTheme">
          {{ LIBELLES_THEME[modeTheme] }}
        </button>
        <RouterLink :to="{ name: 'arbre' }" class="feuille bouton">Arbre classique</RouterLink>
      </div>
    </header>

    <!-- La fiche de la personne choisie : l'équivalent texte de sa feuille. -->
    <aside class="feuille panneau" :class="{ 'panneau--ouvert': choisie !== null }" :aria-hidden="choisie === null" :inert="choisie === null" aria-live="polite" data-panneau>
      <template v-if="choisie !== null">
        <div class="panneau__tete">
          <img v-if="choisie.portrait" :src="choisie.portrait" alt="" class="portrait" />
          <span v-else class="medaillon" :style="{ background: cssDeTeinte(choisie.teinte) }" aria-hidden="true" />
          <div class="panneau__nom">
            <h2>{{ choisie.nomComplet }}</h2>
            <p>{{ enCadre(choisie) }}</p>
          </div>
          <button type="button" class="fermer" aria-label="Fermer la fiche" @click="choisir(null)">×</button>
        </div>
        <dl class="details">
          <div><dt>Génération</dt><dd>{{ choisie.ego ? 'Vous' : libelleGeneration(choisie.generation) }}</dd></div>
          <div><dt>Lignée</dt><dd>{{ LIBELLES_TEINTE[choisie.teinte] }}</dd></div>
          <div v-if="choisie.ligne"><dt>Ligne directe</dt><dd>{{ choisie.ligne === 'ascendant' ? 'Votre ascendance' : 'Votre descendance' }}</dd></div>
        </dl>
        <p v-if="choisie.enAttente" class="note">Une demande de modification attend sur cette fiche : sa feuille porte un bourgeon.</p>
        <div class="actions">
          <RouterLink v-if="choisie.personId !== null && !choisie.restreinte" :to="{ name: 'personne.detail', params: { id: choisie.personId } }" class="bouton bouton--plein">
            Ouvrir la fiche
          </RouterLink>
          <button type="button" class="bouton" :aria-pressed="ligneDe_ === choisie.id" @click="basculerLigne(choisie.id)">
            {{ ligneDe_ === choisie.id ? 'Éteindre sa lignée' : 'Allumer sa lignée' }}
          </button>
        </div>
      </template>
    </aside>

    <!-- Légende -->
    <footer class="feuille legende" aria-label="Légende">
      <span v-for="t in LEGENDE" :key="t"><i :style="{ background: cssDeTeinte(t) }" />{{ LIBELLES_TEINTE[t] }}</span>
      <span class="legende__note">Feuille d’automne : personne décédée · parchemin : vivant hors de votre cercle · halo : demande en attente</span>
    </footer>

    <p v-if="survolee && survolee !== choisie" class="feuille survol" aria-hidden="true">
      <b>{{ survolee.nom }}</b> {{ survolee.dates }}
    </p>
  </div>
</template>

<style scoped>
/* Le carnet d'archiviste : la même palette que le Monde du suivi (MondeView.vue), locale à l'écran. */
.carnet {
  --papier: #f4ecdc;
  --papier-2: #ebdfc6;
  --encre: #3b2a1e;
  --doux: rgba(59, 42, 30, 0.74);
  --trait: rgba(59, 42, 30, 0.62);
  --trait-2: rgba(59, 42, 30, 0.16);
  --accent: #8a5a2b;
  --ombre-papier: 3px 4px 0 rgba(59, 42, 30, 0.13), 0 10px 26px rgba(59, 42, 30, 0.12);
  --grain: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='g'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/%3E%3CfeColorMatrix values='0 0 0 0 .23 0 0 0 0 .16 0 0 0 0 .1 0 0 0 .09 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23g)'/%3E%3C/svg%3E");
  --serif: 'Iowan Old Style', 'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif;
  position: fixed;
  inset: 0;
  overflow: hidden;
  background: var(--papier);
  color: var(--encre);
  color-scheme: light;
}
.carnet[data-theme='dark'] {
  --papier: #1b2235;
  --papier-2: #232c44;
  --encre: #efe6d2;
  --doux: rgba(239, 230, 210, 0.76);
  --trait: rgba(217, 181, 106, 0.72);
  --trait-2: rgba(217, 181, 106, 0.2);
  --accent: #d9b56a;
  --ombre-papier: 3px 4px 0 rgba(0, 0, 0, 0.35), 0 10px 26px rgba(0, 0, 0, 0.35);
  --grain: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='g'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/%3E%3CfeColorMatrix values='0 0 0 0 .95 0 0 0 0 .9 0 0 0 0 .8 0 0 0 .06 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23g)'/%3E%3C/svg%3E");
  color-scheme: dark;
}
.carnet__ciel,
.carnet__toile,
.carnet__etiquettes {
  position: absolute;
  inset: 0;
}
.carnet__ciel {
  background: var(--grain), radial-gradient(120% 90% at 50% 40%, transparent 55%, rgba(120, 84, 44, 0.22) 100%), var(--papier);
}
.carnet[data-theme='dark'] .carnet__ciel {
  background: var(--grain), radial-gradient(120% 90% at 50% 30%, #222b44 0%, #161c2d 60%, #0f1320 100%);
}
.carnet__etiquettes {
  pointer-events: none;
}
.feuille {
  border: 1.5px solid var(--trait);
  background: var(--grain), var(--papier);
  box-shadow: var(--ombre-papier);
}
.centre {
  position: absolute;
  top: 50%;
  left: 50%;
  z-index: 6;
  max-width: 440px;
  padding: 16px 20px;
  border-radius: 4px;
  text-align: center;
  transform: translate(-50%, -50%);
}
.centre a {
  text-decoration: underline;
}
.carnet__attente {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  font-family: var(--serif);
  font-size: 20px;
  font-style: italic;
}

/* ───── Étiquettes et repères d'étage ───── */
.etiquette {
  position: absolute;
  top: 0;
  left: 0;
  display: flex;
  align-items: baseline;
  gap: 6px;
  margin-top: -6px;
  padding: 2px 7px 3px;
  border: 1px solid var(--trait);
  border-radius: 2px;
  background: var(--papier);
  box-shadow: 1px 1px 0 var(--trait-2);
  white-space: nowrap;
  opacity: 0;
  will-change: transform;
}
.etiquette b {
  font-family: var(--serif);
  font-size: 12.5px;
  font-weight: 700;
}
.etiquette i {
  color: var(--doux);
  font-family: var(--serif);
  font-size: 11.5px;
}
.etiquette i:empty {
  display: none;
}
.etiquette[data-etat='ego'],
.etiquette[data-etat='choisie'] {
  border-color: var(--accent);
  box-shadow: 2px 2px 0 var(--accent);
}
.etiquette[data-etat='ego'] b {
  font-size: 14px;
}
.etage {
  position: absolute;
  top: 0;
  left: 0;
  color: var(--accent);
  font-family: var(--serif);
  font-size: 12px;
  font-style: italic;
  letter-spacing: 0.04em;
  white-space: nowrap;
  opacity: 0;
}

/* ───── En-tête ───── */
.haut {
  position: absolute;
  top: 14px;
  left: 14px;
  right: 14px;
  z-index: 4;
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 10px;
  pointer-events: none;
}
.haut > * {
  pointer-events: auto;
}
.titre {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 16px 8px 10px;
  border-radius: 4px;
}
.cachet {
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  flex: none;
  border: 2px solid var(--accent);
  border-radius: 50%;
  color: var(--accent);
  box-shadow: inset 0 0 0 3px var(--papier), inset 0 0 0 4px var(--accent);
  transform: rotate(-8deg);
}
.cachet svg {
  width: 22px;
  height: 22px;
}
.titre h1 {
  margin: 0;
  font-family: var(--serif);
  font-size: 25px;
  font-style: italic;
  font-weight: 700;
  line-height: 1.05;
}
.titre__sous {
  margin-top: 2px;
  color: var(--doux);
  font-family: var(--serif);
  font-size: 13.5px;
  font-style: italic;
}
.recherche {
  position: relative;
  flex: 1 1 220px;
  max-width: 340px;
}
.recherche input {
  width: 100%;
  min-height: 44px;
  padding: 0 14px;
  border-radius: 4px;
  color: var(--encre);
  font-family: var(--serif);
  font-size: 15px;
}
.recherche input::placeholder {
  color: var(--doux);
  font-style: italic;
}
.resultats {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  z-index: 8;
  margin: 0;
  padding: 4px;
  border-radius: 4px;
  list-style: none;
}
.resultats button {
  display: flex;
  align-items: baseline;
  gap: 8px;
  width: 100%;
  min-height: 40px;
  padding: 6px 8px;
  text-align: left;
  cursor: pointer;
}
.resultats button:hover,
.resultats button:focus-visible {
  background: var(--papier-2);
}
.resultats i {
  margin-left: auto;
  color: var(--doux);
  font-family: var(--serif);
  font-size: 12.5px;
}
.pastille {
  width: 10px;
  height: 10px;
  flex: none;
  border: 1px solid var(--trait);
  border-radius: 50%;
}
.outils {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-left: auto;
}
.bouton {
  display: inline-flex;
  align-items: center;
  min-height: 42px;
  padding: 0 13px;
  border-radius: 4px;
  color: var(--encre);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}
.bouton:hover {
  background: var(--grain), var(--papier-2);
}
.bouton[aria-pressed='true'] {
  border-color: var(--accent);
  box-shadow: inset 0 -3px 0 var(--accent), var(--ombre-papier);
}
.bouton:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}
.bouton:focus-visible,
.fermer:focus-visible,
.resultats button:focus-visible,
.recherche input:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 3px;
}

/* ───── Panneau ───── */
.panneau {
  position: absolute;
  top: 110px;
  right: 14px;
  z-index: 5;
  width: 380px;
  padding: 20px;
  border-radius: 4px;
  opacity: 0;
  transform: translateX(calc(100% + 40px));
  transition: transform 0.55s cubic-bezier(0.2, 0.9, 0.25, 1), opacity 0.35s;
}
.panneau--ouvert {
  opacity: 1;
  transform: none;
}
.panneau__tete {
  display: flex;
  align-items: center;
  gap: 14px;
  padding-bottom: 12px;
  border-bottom: 1.5px solid var(--trait);
}
.portrait,
.medaillon {
  width: 56px;
  height: 56px;
  flex: none;
  border: 2px solid var(--trait);
  border-radius: 50%;
  object-fit: cover;
}
.panneau__nom {
  min-width: 0;
}
.panneau h2 {
  margin: 0;
  font-family: var(--serif);
  font-size: 21px;
  font-weight: 700;
  line-height: 1.15;
}
.panneau__nom p {
  margin-top: 2px;
  color: var(--doux);
  font-family: var(--serif);
  font-style: italic;
}
.fermer {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  flex: none;
  margin-left: auto;
  border: 1px solid var(--trait);
  border-radius: 4px;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
}
.details {
  margin: 12px 0 4px;
}
.details div {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 7px 0;
  border-bottom: 1px dotted var(--trait);
}
.details dt {
  color: var(--doux);
  font-size: 13px;
}
.details dd {
  margin: 0;
  font-family: var(--serif);
  font-weight: 700;
  text-align: right;
}
.note {
  margin-top: 10px;
  color: var(--doux);
  font-family: var(--serif);
  font-size: 14px;
  font-style: italic;
}
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 14px;
}
.actions .bouton {
  border: 1.5px solid var(--trait);
}
.bouton--plein {
  border-color: var(--accent) !important;
  background: var(--accent);
  color: var(--papier);
}
.bouton--plein:hover {
  background: var(--accent);
  filter: brightness(1.08);
}

/* ───── Légende, survol ───── */
.legende {
  position: absolute;
  bottom: 14px;
  left: 50%;
  z-index: 4;
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 4px 14px;
  max-width: calc(100% - 28px);
  padding: 8px 14px;
  border-radius: 4px;
  font-family: var(--serif);
  font-size: 13px;
  transform: translateX(-50%);
}
.legende span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.legende i {
  width: 11px;
  height: 11px;
  border: 1px solid var(--trait);
  border-radius: 50%;
}
.legende__note {
  width: 100%;
  justify-content: center;
  color: var(--doux);
  font-size: 12px;
  font-style: italic;
}
.survol {
  position: absolute;
  bottom: 86px;
  left: 50%;
  z-index: 4;
  padding: 5px 12px;
  border-radius: 4px;
  font-family: var(--serif);
  font-size: 14px;
  transform: translateX(-50%);
  pointer-events: none;
}

@media (prefers-reduced-motion: reduce) {
  .panneau {
    transition: none;
  }
}
@media (max-width: 900px) {
  .panneau {
    top: auto;
    right: 10px;
    bottom: 10px;
    left: 10px;
    width: auto;
    max-height: 48vh;
    overflow-y: auto;
  }
  .legende,
  .etage {
    display: none;
  }
  .recherche {
    order: 3;
    max-width: none;
    flex-basis: 100%;
  }
  .titre h1 {
    font-size: 20px;
  }
}
</style>
