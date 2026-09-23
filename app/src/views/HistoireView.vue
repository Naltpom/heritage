<script setup lang="ts">
/**
 * `/histoire` — tout l'arbre d'un coup, lisible à tous les zooms.
 *
 * Recréation de la maquette « Arbre complet à niveaux de détail (3a) ». **Plus
 * on dézoome, moins on écrit** : quatre niveaux (Ensemble, Familles, Prénoms,
 * Fiches), un zoom continu, une recherche qui vole jusqu'à la personne, des
 * filtres par branche et une mini-carte.
 *
 * Écran **neuf et autonome** : il ne modifie ni `/arbre` ni `/tree`. Il
 * réutilise les briques pures du dépôt — l'API (`/arbre/graphe`,
 * `rechercherPersonnes`, `lireFichePersonne`), `indexer()` et `nomDeFiche()` —
 * et l'en-tête applicatif : sur téléphone, le burger ouvre la navigation
 * existante et le bouton filtres de l'en-tête ouvre le feuillet de cet écran.
 *
 * Les règles de traitement tenues ici :
 *  - le front ne filtre rien pour la confidentialité : « Personnes vivantes »
 *    lit `vivant` tel qu'il est reçu ;
 *  - un nœud restreint n'a pas de ligne de dates, et on ne la reconstitue pas ;
 *  - la recherche passe par l'API, jamais par un index local ;
 *  - un filtre **estompe**, il ne retire rien : la forme de l'arbre ne bouge pas ;
 *  - aucun texte sous 10 px à l'écran.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue'
import {
  lireFichePersonne,
  rechercherPersonnes,
  requeteApi,
  type Branche,
  type Graphe,
  type NoeudNonPlace,
  type ResultatDeRecherche,
} from '@/lib/api'
import { nomDeFiche } from '@/components/arbre/graphe'
import { annoncerBarreDOutils, useEstMobile, useTiroirs } from '@/composables/useTiroirs'
import { hauteurDuCadre } from '@/views/cadre'
import FichesMonde from '@/histoire/FichesMonde.vue'
import {
  LIBELLES_QUART,
  disposerLignees,
  prenomDe,
  type BrancheDeQuart,
  type DispositionHistoire,
  type FicheHistoire,
} from '@/histoire/lignees'
import {
  DUREE_VOL,
  PALIERS,
  ajusterLargeur,
  niveau as niveauDe,
  syDe,
  trajectoire,
  versMonde,
  zoomAutour,
  type Cadrage,
  type Canevas,
} from '@/histoire/vue'

const estMobile = useEstMobile()
const { ouvert: tiroirOuvert, fermerTiroirs, unTiroirEstOuvert } = useTiroirs()

/* ------------------------------------------------------------------ */
/* Chargement                                                          */
/* ------------------------------------------------------------------ */

const disposition = shallowRef<DispositionHistoire | null>(null)
const nonPlaces = ref<NoeudNonPlace[]>([])
const chargement = ref(true)
const erreur = ref<string | null>(null)

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    const graphe = await requeteApi<Graphe>('/arbre/graphe')
    disposition.value = disposerLignees(graphe)
    nonPlaces.value = graphe.calque.nonPlaces
    await nextTick()
    mesurer()
    ajuster()
  } catch (e) {
    erreur.value = e instanceof Error ? e.message : "L'arbre n'a pas pu être chargé."
  } finally {
    chargement.value = false
  }
}

/* ------------------------------------------------------------------ */
/* Cadrage                                                             */
/* ------------------------------------------------------------------ */

const racine = ref<HTMLElement | null>(null)
const fenetre = ref<HTMLElement | null>(null)
const hauteurCadre = ref('70vh')
const taille = ref({ l: 0, h: 0 })
const cadrage = ref<Cadrage>({ s: 0.05, tx: 0, ty: 0 })

let premierAjustement = true
const gouttiere = computed(() => (estMobile.value ? 64 : 170))

const canevas = computed<Canevas>(() => ({
  largeur: taille.value.l,
  hauteur: taille.value.h,
  largeurMonde: disposition.value?.largeur ?? 1,
  hauteurMonde: disposition.value?.hauteur ?? 1,
}))

const s = computed(() => cadrage.value.s)
const sy = computed(() => syDe(cadrage.value.s, canevas.value))
const niveau = computed(() => niveauDe(cadrage.value.s))
const ensemble = computed(() => niveau.value === 0)

const versEcranX = (x: number): number => cadrage.value.tx + x * s.value
const versEcranY = (y: number): number => cadrage.value.ty + y * sy.value

function mesurer(): void {
  if (racine.value) hauteurCadre.value = hauteurDuCadre(racine.value.getBoundingClientRect().top)
  const el = fenetre.value
  if (el) taille.value = { l: el.clientWidth, h: el.clientHeight }
}

function ajuster(): void {
  if (!disposition.value || taille.value.l === 0) return
  premierAjustement = false
  cadrage.value = ajusterLargeur(canevas.value, gouttiere.value)
}

/* Le vol animé. Tout geste l'interrompt. */
let animation = 0
function arreterVol(): void {
  cancelAnimationFrame(animation)
  animation = 0
}
function voler(sCible: number, x: number, y: number): void {
  arreterVol()
  const vol = trajectoire(cadrage.value, canevas.value, sCible, x, y)
  const debut = performance.now()
  const pas = (maintenant: number): void => {
    const t = Math.min(1, (maintenant - debut) / DUREE_VOL)
    cadrage.value = vol(t)
    animation = t < 1 ? requestAnimationFrame(pas) : 0
  }
  animation = requestAnimationFrame(pas)
}

function centreMonde(): { x: number; y: number } {
  return versMonde(cadrage.value, canevas.value, taille.value.l / 2, taille.value.h / 2)
}

function zoomer(facteur: number): void {
  arreterVol()
  cadrage.value = zoomAutour(cadrage.value, canevas.value, taille.value.l / 2, taille.value.h / 2, facteur)
}

function allerAuPalier(i: number): void {
  const d = disposition.value
  if (!d) return
  const palier = PALIERS[i]!
  if (palier.echelle === null) {
    const cible = ajusterLargeur(canevas.value, gouttiere.value)
    const centre = versMonde(cible, canevas.value, taille.value.l / 2, taille.value.h / 2)
    voler(cible.s, centre.x, centre.y)
  } else {
    const c = centreMonde()
    voler(palier.echelle, c.x, c.y)
  }
}

/* ------------------------------------------------------------------ */
/* Gestes : glisser, pincer, molette                                   */
/* ------------------------------------------------------------------ */

const pointeurs = new Map<number, { x: number; y: number }>()
let glisser: { x: number; y: number; tx: number; ty: number; bouge: boolean } | null = null
let pincement: number | null = null
let finDeGlisser = 0
let enAttente: Cadrage | null = null
let image = 0

/** Un seul recalcul par image pendant un glisser. */
function planifier(c: Cadrage): void {
  enAttente = c
  if (image) return
  image = requestAnimationFrame(() => {
    image = 0
    if (enAttente) cadrage.value = enAttente
    enAttente = null
  })
}

function local(e: PointerEvent | WheelEvent): { x: number; y: number } {
  const r = fenetre.value!.getBoundingClientRect()
  return { x: e.clientX - r.left, y: e.clientY - r.top }
}

function surPointeurBas(e: PointerEvent): void {
  if (e.button !== 0 && e.pointerType === 'mouse') return
  arreterVol()
  pointeurs.set(e.pointerId, local(e))
  if (pointeurs.size === 1) glisser = { x: e.clientX, y: e.clientY, tx: cadrage.value.tx, ty: cadrage.value.ty, bouge: false }
  if (pointeurs.size === 2) {
    const [a, b] = [...pointeurs.values()] as [{ x: number; y: number }, { x: number; y: number }]
    pincement = Math.hypot(a.x - b.x, a.y - b.y)
  }
}

function surPointeurDeplace(e: PointerEvent): void {
  if (!pointeurs.has(e.pointerId)) return
  pointeurs.set(e.pointerId, local(e))
  if (pointeurs.size === 2 && pincement) {
    const [a, b] = [...pointeurs.values()] as [{ x: number; y: number }, { x: number; y: number }]
    const d = Math.hypot(a.x - b.x, a.y - b.y)
    planifier(zoomAutour(enAttente ?? cadrage.value, canevas.value, (a.x + b.x) / 2, (a.y + b.y) / 2, d / pincement))
    pincement = d
    if (glisser) glisser.bouge = true
    return
  }
  if (!glisser) return
  const dx = e.clientX - glisser.x
  const dy = e.clientY - glisser.y
  if (!glisser.bouge && Math.abs(dx) + Math.abs(dy) > 5) {
    glisser.bouge = true
    fenetre.value?.setPointerCapture(e.pointerId)
  }
  if (glisser.bouge) planifier({ s: cadrage.value.s, tx: glisser.tx + dx, ty: glisser.ty + dy })
}

function surPointeurHaut(e: PointerEvent): void {
  pointeurs.delete(e.pointerId)
  if (pointeurs.size < 2) pincement = null
  if (pointeurs.size === 0) {
    if (glisser?.bouge) finDeGlisser = Date.now()
    glisser = null
  }
}

function surMolette(e: WheelEvent): void {
  e.preventDefault()
  arreterVol()
  const { x, y } = local(e)
  planifier(zoomAutour(enAttente ?? cadrage.value, canevas.value, x, y, Math.exp(-e.deltaY * 0.0016)))
}

/* ------------------------------------------------------------------ */
/* Sélection                                                           */
/* ------------------------------------------------------------------ */

const selection = ref<string | null>(null)
const ficheChoisie = computed(() => (selection.value ? disposition.value?.parId.get(selection.value) ?? null : null))
const parente = ref<string | null>(null)
let lecture: AbortController | null = null

watch(ficheChoisie, (f) => {
  lecture?.abort()
  parente.value = null
  const personId = f?.noeud.personId
  if (personId === null || personId === undefined) return
  lecture = new AbortController()
  // La parenté est calculée par le serveur, relativement au lecteur : on ne la recalcule pas.
  lireFichePersonne(personId, lecture.signal)
    .then((fiche) => {
      if (fiche.visibilite === 'complete') parente.value = fiche.parente
    })
    .catch(() => {})
})

function voirFiche(f: FicheHistoire): void {
  voler(Math.max(s.value, 1), f.cx, f.y + f.h / 2)
}

function choisir(f: FicheHistoire): void {
  if (Date.now() - finDeGlisser < 80) return
  selection.value = f.id
  if (s.value < 0.42) voler(1, f.cx, f.y + f.h / 2)
}

function meTrouver(): void {
  const d = disposition.value
  const moi = d?.parId.get(d.ego)
  if (!moi) return
  selection.value = moi.id
  voler(1, moi.cx, moi.y + moi.h / 2)
}

/* ------------------------------------------------------------------ */
/* Filtres : ils estompent, ils ne retirent rien                        */
/* ------------------------------------------------------------------ */

const filtres = reactive({ masquees: [] as BrancheDeQuart[], ligneDirecte: false, vivants: false })
const filtreActif = computed(() => filtres.masquees.length > 0 || filtres.ligneDirecte || filtres.vivants)
const filtresBureau = ref(false)
const filtresOuverts = computed(() => (estMobile.value ? tiroirOuvert.value === 'outils' : filtresBureau.value))

function basculerFiltres(): void {
  filtresBureau.value = !filtresBureau.value
}
function fermerFiltres(): void {
  filtresBureau.value = false
  fermerTiroirs()
}
function toutAfficher(): void {
  filtres.masquees = []
  filtres.ligneDirecte = false
  filtres.vivants = false
}
function basculerBranche(b: BrancheDeQuart): void {
  filtres.masquees = filtres.masquees.includes(b) ? filtres.masquees.filter((x) => x !== b) : [...filtres.masquees, b]
}

const BASCULES = [
  { cle: 'ligneDirecte', libelle: 'Ma ligne directe', sous: 'vous et vos ancêtres' },
  { cle: 'vivants', libelle: 'Personnes vivantes', sous: '' },
] as const

function passe(f: FicheHistoire): boolean {
  if ((filtres.masquees as Branche[]).includes(f.noeud.branch)) return false
  if (filtres.ligneDirecte && !f.directe) return false
  if (filtres.vivants && !f.noeud.vivant) return false
  return true
}

const effectifAffiche = computed(() => disposition.value?.fiches.filter(passe).length ?? 0)

/* ------------------------------------------------------------------ */
/* Rendu : boîtes (monde), textes (écran)                               */
/* ------------------------------------------------------------------ */

const BRANCHES_CONNUES = new Set(['pp', 'pm', 'mp', 'mm', 'tr', 'cpp', 'cpm', 'cmp', 'cmm'])
const couleurBranche = (b: string): string => `var(--h-branche-${BRANCHES_CONNUES.has(b) ? b : 'hors'})`

const LIBELLES_BRANCHE: Record<string, string> = { ...LIBELLES_QUART, tr: 'Tronc' }
const libelleBranche = (b: string): string => LIBELLES_BRANCHE[b] ?? 'Hors branche'

/**
 * Les styles des boîtes. Ils ne dépendent que du niveau Ensemble et des
 * filtres : déplacer la vue ne les recalcule pas.
 */
const stylesFiches = computed(() => {
  const d = disposition.value
  if (!d) return []
  const loin = ensemble.value
  return d.fiches.map((f) => {
    const n = f.noeud
    const sexe = n.sex === 'masculin' ? 'homme' : n.sex === 'feminin' ? 'femme' : 'inconnu'
    const base: Record<string, string> = {
      left: `${f.x}px`,
      top: `${f.y}px`,
      width: `${f.w}px`,
      height: `${f.h}px`,
      opacity: passe(f) ? '1' : '0.1',
    }
    if (loin) {
      base.background = f.directe ? 'var(--hi-direct-plein)' : `color-mix(in srgb, ${couleurBranche(n.branch)} 70%, transparent)`
      return base
    }
    base.background = `var(--h-${sexe})`
    base.border = f.directe
      ? '1px solid var(--h-accent)'
      : n.enAttente
        ? '1px dashed var(--h-accent)'
        : `1px ${n.vivant ? 'dashed' : 'solid'} var(--h-${sexe}-bord)`
    base.borderLeft = `4px solid ${couleurBranche(n.branch)}`
    base.borderRadius = '7px'
    return base
  })
})

const transformationMonde = computed(
  () => `translate(${cadrage.value.tx}px, ${cadrage.value.ty}px) scale(${s.value}, ${sy.value})`,
)
const transformationTraits = computed(() => `translate(${cadrage.value.tx} ${cadrage.value.ty}) scale(${s.value} ${sy.value})`)

function dansLaFenetre(x: number, y: number, w: number, h: number): boolean {
  const X = versEcranX(x)
  const Y = versEcranY(y)
  return X + w * s.value > 0 && X < taille.value.l && Y + h * sy.value > 0 && Y < taille.value.h
}

interface Libelle {
  id: string
  x: number
  y: number
  w: number
  h: number
  t1: string
  t2: string
  taille: number
  directe: boolean
}

/** Le texte des fiches visibles, en coordonnées écran (culling). */
const libelles = computed<Libelle[]>(() => {
  const d = disposition.value
  if (!d || niveau.value < 2) return []
  const nv = niveau.value
  return d.fiches
    .filter((f) => passe(f) && dansLaFenetre(f.x, f.y, f.w, f.h))
    .map((f) => ({
      id: f.id,
      x: versEcranX(f.x) + 4 * s.value + 5,
      y: versEcranY(f.y),
      w: Math.max(0, f.w * s.value - 4 * s.value - 9),
      h: f.h * sy.value,
      t1: nv === 2 ? prenomDe(f.noeud) || nomDeFiche(f.noeud).texte : nomDeFiche(f.noeud).texte,
      // Absente sur un nœud restreint : ni tiret, ni case vide.
      t2: nv === 3 && typeof f.noeud.etiquetteDates === 'string' ? f.noeud.etiquetteDates : '',
      taille: nv === 2 ? 10.5 : 12,
      directe: f.directe,
    }))
})

const libellesFoyers = computed(() => {
  const d = disposition.value
  if (!d || niveau.value !== 1) return []
  return d.foyers
    .filter((c) => (c.ancetres || s.value > 0.25) && c.envergure * s.value > 44 && dansLaFenetre(c.x - 40, c.y - 20, 80, 20))
    .map((c, i) => ({ cle: i, x: versEcranX(c.x), y: versEcranY(c.y) - 15, nom: c.nom, ancetres: c.ancetres }))
})

const libellesQuarts = computed(() => {
  const d = disposition.value
  if (!d || niveau.value > 1) return []
  const mobile = estMobile.value
  const haut = cadrage.value.ty - (mobile ? 40 : 52)
  if (haut < 0) return []
  const ecart = mobile ? 64 : 190
  const demi = mobile ? 34 : 95
  let dernier = -Infinity
  return d.quarts
    .filter((q) => q.nom !== '')
    .map((q) => ({ q, x: versEcranX(q.cx) }))
    .sort((a, b) => a.x - b.x)
    .map(({ q, x }) => {
      const px = Math.max(dernier + ecart, Math.max(gouttiere.value + demi, x))
      dernier = px
      return {
        branche: q.branche,
        x: px,
        y: Math.max(6, haut),
        nom: q.nom,
        sous: mobile ? `${q.effectif}` : `${q.libelle} · ${q.effectif}`,
      }
    })
})

const etiquettesGouttiere = computed(() => {
  const d = disposition.value
  if (!d) return []
  const compact = d.pasRangee * sy.value < 38
  const mobile = estMobile.value
  return d.generations
    .map((g) => {
      const annee = g.annees?.replace('vers ', '') ?? ''
      return {
        rangee: g.rangee,
        y: versEcranY(g.y) - (compact ? 8 : 14),
        libelle: mobile
          ? g.rangee === 0
            ? 'Vous'
            : `${g.rangee > 0 ? '+' : ''}${g.rangee}`
          : compact && annee
            ? `${g.libelle} · ${annee}`
            : g.libelle,
        // Une rangée trop basse n'a la place que pour une ligne.
        annee: compact ? '' : mobile ? annee : (g.annees ?? ''),
      }
    })
    .filter((g) => g.y > -20 && g.y < taille.value.h)
})

const anneau = computed(() => {
  const f = ficheChoisie.value
  if (!f || !dansLaFenetre(f.x, f.y, f.w, f.h)) return null
  return { x: versEcranX(f.x) - 4, y: versEcranY(f.y) - 4, w: f.w * s.value + 8, h: f.h * sy.value + 8 }
})

/* Mini-carte (bureau). */
const LARGEUR_MINI = 236
const miniCarte = computed(() => {
  const d = disposition.value
  if (!d) return null
  const k = LARGEUR_MINI / d.largeur
  const x0 = Math.max(0, -cadrage.value.tx / s.value)
  const x1 = Math.min(d.largeur, (taille.value.l - cadrage.value.tx) / s.value)
  return {
    bandes: d.quarts
      .filter((q) => !q.branche.startsWith('c'))
      .map((q) => ({ branche: q.branche, x: q.x0 * k, w: Math.max(2, (q.x1 - q.x0) * k) })),
    vue: { x: x0 * k, w: Math.max(6, (x1 - x0) * k) },
    k,
  }
})

function surMiniCarte(e: MouseEvent): void {
  const m = miniCarte.value
  if (!m) return
  const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
  const x = (e.clientX - r.left - 12) / m.k
  voler(Math.max(s.value, 0.3), x, centreMonde().y)
}

const bulle = computed(() => {
  if (selection.value) return ''
  if (estMobile.value) return niveau.value === 0 ? 'Pincez pour zoomer' : ''
  return [
    'Molette ou pincer pour zoomer · glisser pour se déplacer',
    'Les noms de famille apparaissent · zoomez encore pour les prénoms',
    '',
    '',
  ][niveau.value]!
})

const compteur = computed(() => {
  const d = disposition.value
  if (!d) return ''
  return `${d.fiches.length} personnes · ${d.generations.length} générations`
})

/* ------------------------------------------------------------------ */
/* Recherche : par l'API, jamais par un index local                     */
/* ------------------------------------------------------------------ */

const recherche = ref('')
const resultats = ref<ResultatDeRecherche[]>([])
const champ = ref<HTMLInputElement | null>(null)
let minuterie = 0
let requete: AbortController | null = null

watch(recherche, (q) => {
  window.clearTimeout(minuterie)
  requete?.abort()
  const texte = q.trim()
  if (texte.length < 2) {
    resultats.value = []
    return
  }
  minuterie = window.setTimeout(() => {
    requete = new AbortController()
    rechercherPersonnes(texte, 6, requete.signal)
      .then((r) => {
        resultats.value = r.elements
      })
      .catch(() => {})
  }, 200)
})

function allerA(r: ResultatDeRecherche): void {
  const f = disposition.value?.parPersonId.get(r.personId)
  recherche.value = ''
  resultats.value = []
  champ.value?.blur()
  if (!f) return
  selection.value = f.id
  voler(1, f.cx, f.y + f.h / 2)
}

function surToucheRecherche(e: KeyboardEvent): void {
  if (e.key === 'Enter' && resultats.value[0]) allerA(resultats.value[0])
  else if (e.key === 'Escape') {
    recherche.value = ''
    champ.value?.blur()
  }
}

/* ------------------------------------------------------------------ */
/* Clavier                                                             */
/* ------------------------------------------------------------------ */

function surToucheGlobale(e: KeyboardEvent): void {
  const cible = e.target as HTMLElement | null
  if (cible && (cible.tagName === 'INPUT' || cible.tagName === 'TEXTAREA' || cible.isContentEditable)) return
  if (e.ctrlKey || e.metaKey || e.altKey) return
  if (e.key === '/') {
    e.preventDefault()
    champ.value?.focus()
  } else if (e.key === '0') allerAuPalier(0)
  else if (e.key === '+' || e.key === '=') zoomer(1.6)
  else if (e.key === '-') zoomer(1 / 1.6)
  else if (e.key === 'Escape') {
    // Un tiroir ouvert garde la priorité : il se referme avant qu'on désélectionne.
    if (filtresOuverts.value || unTiroirEstOuvert()) fermerFiltres()
    else selection.value = null
  }
}

/* ------------------------------------------------------------------ */
/* Cycle de vie                                                        */
/* ------------------------------------------------------------------ */

let observateur: ResizeObserver | null = null
let retirerOutils: (() => void) | null = null

function surRedimension(): void {
  mesurer()
}

onMounted(() => {
  // L'en-tête fait paraître son bouton filtres (téléphone) quand un écran s'annonce.
  retirerOutils = annoncerBarreDOutils()
  mesurer()
  window.addEventListener('resize', surRedimension)
  window.addEventListener('keydown', surToucheGlobale)
  const el = fenetre.value
  if (el) {
    // Non passif, pour pouvoir empêcher le défilement de la page.
    el.addEventListener('wheel', surMolette, { passive: false })
    observateur = new ResizeObserver(() => {
      mesurer()
      if (premierAjustement) ajuster()
    })
    observateur.observe(el)
  }
  void charger()
})

onBeforeUnmount(() => {
  retirerOutils?.()
  window.removeEventListener('resize', surRedimension)
  window.removeEventListener('keydown', surToucheGlobale)
  fenetre.value?.removeEventListener('wheel', surMolette)
  observateur?.disconnect()
  arreterVol()
  cancelAnimationFrame(image)
  window.clearTimeout(minuterie)
  requete?.abort()
  lecture?.abort()
})
</script>

<template>
  <div ref="racine" class="histoire flex flex-col bg-fond text-texte" :style="{ height: hauteurCadre }">
    <!-- Bureau : la barre d'outils -->
    <div
      v-if="!estMobile"
      class="relative z-10 flex h-14 flex-none items-center gap-3 border-b border-ligne bg-panneau px-4"
    >
      <div class="mr-1 flex flex-col leading-tight whitespace-nowrap">
        <span class="text-sm font-semibold">Tout l’arbre</span>
        <span class="text-xs text-attenue">{{ compteur }}</span>
      </div>

      <div class="relative w-[260px]">
        <input
          ref="champ"
          v-model="recherche"
          type="search"
          autocomplete="off"
          placeholder="Aller à une personne…"
          aria-label="Aller à une personne"
          class="h-9 w-full rounded-champ border border-ligne bg-fond px-3 text-[13px] outline-none focus:border-accent"
          @keydown="surToucheRecherche"
        />
        <div
          v-if="resultats.length"
          class="absolute inset-x-0 top-10 z-20 rounded-panneau border border-ligne bg-panneau py-1 shadow-[0_10px_30px_rgba(0,0,0,.5)]"
          role="listbox"
        >
          <button
            v-for="r in resultats"
            :key="r.personId"
            type="button"
            role="option"
            class="flex w-full flex-col items-start px-3 py-1.5 text-left leading-snug hover:bg-accent-doux"
            @click="allerA(r)"
          >
            <span class="text-[13px] font-semibold">{{ r.displayName }}</span>
            <span class="text-[11.5px] text-attenue">{{ libelleBranche(r.branch) }}<template v-if="r.ligneDates"> · {{ r.ligneDates }}</template></span>
          </button>
        </div>
      </div>

      <span class="flex-1" />

      <div class="flex gap-0.5 rounded-lg border border-ligne bg-fond p-[3px]" role="group" aria-label="Niveau de détail">
        <button
          v-for="(p, i) in PALIERS"
          :key="p.libelle"
          type="button"
          class="h-[30px] rounded-md px-3 text-[13px]"
          :class="niveau === i ? 'bg-accent-doux font-semibold text-[var(--hi-libelle-accent)]' : 'text-attenue hover:text-texte'"
          :aria-pressed="niveau === i"
          @click="allerAuPalier(i)"
        >
          {{ p.libelle }}
        </button>
      </div>

      <div class="flex overflow-hidden rounded-lg border border-ligne">
        <button type="button" aria-label="Dézoomer" class="h-[34px] w-[34px] text-lg hover:bg-accent-doux" @click="zoomer(1 / 1.6)">−</button>
        <button type="button" aria-label="Zoomer" class="h-[34px] w-[34px] border-l border-ligne text-lg hover:bg-accent-doux" @click="zoomer(1.6)">+</button>
      </div>

      <div class="relative">
        <button
          type="button"
          class="flex h-[34px] items-center gap-1.5 rounded-champ border px-3 text-[13px] whitespace-nowrap"
          :class="filtreActif ? 'border-accent' : 'border-ligne'"
          :aria-expanded="filtresOuverts"
          aria-controls="filtres-histoire"
          @click="basculerFiltres"
        >
          <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true">
            <path d="M3 5h14M6 10h8M9 15h2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
          </svg>
          {{ filtreActif ? 'Filtres · actifs' : 'Filtres' }}
        </button>
      </div>

      <button
        type="button"
        class="h-[34px] rounded-champ bg-accent px-3 font-semibold whitespace-nowrap text-fond"
        @click="meTrouver"
      >
        Me trouver
      </button>
    </div>

    <!-- Téléphone : les paliers en haut -->
    <div v-else class="flex-none border-b border-ligne bg-panneau px-3 pt-2 pb-2.5">
      <div class="mb-1.5 text-[11.5px] text-attenue">{{ compteur }}</div>
      <div class="grid grid-cols-4 gap-0.5 rounded-lg border border-ligne bg-fond p-[3px]" role="group" aria-label="Niveau de détail">
        <button
          v-for="(p, i) in PALIERS"
          :key="p.libelle"
          type="button"
          class="h-9 rounded-md p-0 text-[11.5px]"
          :class="niveau === i ? 'bg-accent-doux font-semibold text-[var(--hi-libelle-accent)]' : 'text-attenue'"
          :aria-pressed="niveau === i"
          @click="allerAuPalier(i)"
        >
          {{ p.libelle }}
        </button>
      </div>
    </div>

    <!-- Les fiches proposées que le serveur n'a pas su placer : toujours les dire. -->
    <div
      v-if="nonPlaces.length"
      class="flex flex-none flex-wrap items-center gap-x-2 gap-y-1 border-b border-ligne bg-panneau px-4 py-1.5 text-xs text-attenue"
      role="status"
    >
      <span>{{ nonPlaces.length }} fiche{{ nonPlaces.length > 1 ? 's' : '' }} proposée{{ nonPlaces.length > 1 ? 's' : '' }} hors de l’arbre :</span>
      <RouterLink
        v-for="n in nonPlaces"
        :key="n.noeudId"
        :to="{ name: 'demande.detail', params: { id: n.demandeId } }"
        class="text-accent hover:underline"
      >
        {{ n.displayName }}
      </RouterLink>
    </div>

    <!-- Le canevas -->
    <div
      ref="fenetre"
      class="grille relative flex-1 cursor-grab touch-none overflow-hidden select-none active:cursor-grabbing"
      @pointerdown="surPointeurBas"
      @pointermove="surPointeurDeplace"
      @pointerup="surPointeurHaut"
      @pointercancel="surPointeurHaut"
      @pointerleave="surPointeurHaut"
    >
      <template v-if="disposition">
        <svg class="pointer-events-none absolute inset-0 h-full w-full overflow-visible" aria-hidden="true">
          <g :transform="transformationTraits">
            <path
              :d="disposition.traces.ordinaires"
              fill="none"
              stroke="var(--hi-arete)"
              stroke-width="1.2"
              vector-effect="non-scaling-stroke"
              :opacity="niveau === 0 ? 0 : niveau === 1 ? 0.55 : 0.9"
            />
            <path
              :d="disposition.traces.divorces"
              fill="none"
              stroke="var(--h-alerte)"
              stroke-width="1.2"
              stroke-dasharray="5 4"
              vector-effect="non-scaling-stroke"
              :opacity="niveau === 0 ? 0 : 0.7"
            />
            <path
              :d="disposition.traces.attente"
              fill="none"
              stroke="var(--h-accent)"
              stroke-width="1.2"
              stroke-dasharray="7 4"
              vector-effect="non-scaling-stroke"
              :opacity="niveau === 0 ? 0 : 0.7"
            />
            <path
              :d="disposition.traces.directs"
              fill="none"
              stroke="var(--h-accent)"
              :stroke-width="niveau === 0 ? 2.5 : 2"
              vector-effect="non-scaling-stroke"
            />
          </g>
        </svg>

        <div class="absolute top-0 left-0 origin-top-left" :style="{ transform: transformationMonde }">
          <FichesMonde :fiches="disposition.fiches" :styles="stylesFiches" @choisir="choisir" />
        </div>

        <!-- Textes, en coordonnées écran, à taille fixe -->
        <div
          v-for="t in libelles"
          :key="t.id"
          class="pointer-events-none absolute flex flex-col justify-center overflow-hidden leading-tight"
          :style="{ left: `${t.x}px`, top: `${t.y}px`, width: `${t.w}px`, height: `${t.h}px` }"
        >
          <span
            class="truncate font-semibold"
            :style="{ fontSize: `${t.taille}px`, color: t.directe ? 'var(--hi-direct-texte)' : 'var(--h-texte)' }"
          >{{ t.t1 }}</span>
          <span v-if="t.t2" class="truncate font-mono text-[10.5px] text-attenue">{{ t.t2 }}</span>
        </div>

        <div
          v-for="c in libellesFoyers"
          :key="c.cle"
          class="pointer-events-none absolute -translate-x-1/2 font-bold whitespace-nowrap [text-shadow:0_1px_3px_var(--h-fond)]"
          :style="{
            left: `${c.x}px`,
            top: `${c.y}px`,
            fontSize: c.ancetres ? '11.5px' : '10.5px',
            color: c.ancetres ? 'var(--hi-libelle-accent)' : 'var(--h-attenue)',
          }"
        >
          {{ c.nom }}
        </div>

        <div
          v-for="q in libellesQuarts"
          :key="q.branche"
          class="pointer-events-none absolute flex -translate-x-1/2 flex-col items-center text-center leading-tight"
          :style="{ left: `${q.x}px`, top: `${q.y}px` }"
        >
          <span
            class="font-bold tracking-tight whitespace-nowrap"
            :class="estMobile ? 'text-xs' : 'text-[17px]'"
            :style="{ color: couleurBranche(q.branche) }"
          >{{ q.nom }}</span>
          <span class="whitespace-nowrap text-attenue" :class="estMobile ? 'text-[10px]' : 'text-xs'">{{ q.sous }}</span>
        </div>

        <div
          v-if="anneau"
          class="pointer-events-none absolute rounded-[9px] border-2 border-[var(--hi-libelle-accent)] shadow-[0_0_0_5px_rgba(217,160,102,.2)]"
          :style="{ left: `${anneau.x}px`, top: `${anneau.y}px`, width: `${anneau.w}px`, height: `${anneau.h}px` }"
        />

        <!-- Gouttière des générations -->
        <div class="gouttiere pointer-events-none absolute inset-y-0 left-0" :style="{ width: `${gouttiere}px` }">
          <div
            v-for="g in etiquettesGouttiere"
            :key="g.rangee"
            class="absolute left-3 flex flex-col leading-tight"
            :style="{ top: `${g.y}px` }"
          >
            <span class="font-semibold whitespace-nowrap text-[var(--hi-gouttiere-texte)]" :class="estMobile ? 'text-[11px]' : 'text-xs'">{{ g.libelle }}</span>
            <span v-if="g.annee" class="font-mono text-[10.5px] whitespace-nowrap text-[var(--hi-gouttiere-annee)]">{{ g.annee }}</span>
          </div>
        </div>

        <!-- Mini-carte -->
        <div
          v-if="!estMobile && miniCarte"
          class="absolute right-4 bottom-4 flex w-[260px] cursor-pointer flex-col gap-1.5 rounded-panneau border border-ligne bg-panneau/95 px-3 py-2.5"
          role="button"
          aria-label="Mini-carte : cliquer pour centrer la vue"
          @pointerdown.stop
          @click="surMiniCarte"
        >
          <div class="relative h-[18px] overflow-hidden rounded bg-ligne/60">
            <div
              v-for="b in miniCarte.bandes"
              :key="b.branche"
              class="absolute inset-y-0 opacity-75"
              :style="{ left: `${b.x}px`, width: `${b.w}px`, background: couleurBranche(b.branche) }"
            />
            <div
              class="absolute -inset-y-px box-border rounded-[3px] border-2 border-texte"
              :style="{ left: `${miniCarte.vue.x}px`, width: `${miniCarte.vue.w}px` }"
            />
          </div>
          <div class="flex justify-between text-[10.5px] text-attenue">
            <span>Côté père</span><span>{{ PALIERS[niveau]!.libelle }}</span><span>Côté mère</span>
          </div>
        </div>

        <div
          v-if="bulle"
          class="pointer-events-none absolute rounded-pastille border border-ligne bg-panneau/95 px-3.5 py-1.5 text-xs whitespace-nowrap text-[var(--hi-gouttiere-texte)]"
          :style="{ left: `${estMobile ? 12 : 180}px`, bottom: `${estMobile ? 12 : 22}px` }"
        >
          {{ bulle }}
        </div>

        <!-- La fiche sélectionnée -->
        <section
          v-if="ficheChoisie"
          class="absolute z-10 flex flex-col gap-2 rounded-panneau border border-ligne bg-panneau px-4 py-3.5 shadow-[0_10px_30px_rgba(0,0,0,.5)]"
          :class="estMobile ? 'inset-x-3 bottom-3' : 'top-4 right-4 w-[300px]'"
          :style="{ borderTop: `3px solid ${couleurBranche(ficheChoisie.noeud.branch)}` }"
          aria-live="polite"
          @pointerdown.stop
        >
          <div class="flex items-start gap-2">
            <div class="flex flex-1 flex-col leading-snug">
              <span class="text-[17px] font-semibold">{{ ficheChoisie.noeud.displayName }}</span>
              <span v-if="typeof ficheChoisie.noeud.etiquetteDates === 'string'" class="font-mono text-xs text-attenue">{{
                ficheChoisie.noeud.etiquetteDates
              }}</span>
            </div>
            <button
              type="button"
              aria-label="Fermer"
              class="-mt-1.5 -mr-2 h-8 w-8 text-lg text-attenue hover:text-texte"
              @click="selection = null"
            >
              ×
            </button>
          </div>
          <div class="flex flex-wrap gap-1.5">
            <span v-if="ficheChoisie.id === disposition.ego" class="rounded-pastille bg-accent-doux px-2.5 py-0.5 text-xs font-semibold text-accent">vous</span>
            <span v-else-if="parente" class="rounded-pastille bg-accent-doux px-2.5 py-0.5 text-xs font-semibold text-accent">{{ parente }}</span>
            <span class="rounded-pastille border border-ligne px-2.5 py-px text-xs text-[var(--hi-gouttiere-texte)]">{{
              libelleBranche(ficheChoisie.noeud.branch)
            }}</span>
            <span v-if="ficheChoisie.noeud.enAttente" class="rounded-pastille border border-accent px-2.5 py-px text-xs text-accent">en attente</span>
          </div>
          <div class="flex gap-2">
            <button
              type="button"
              class="h-9 flex-1 rounded-champ border border-ligne font-semibold hover:bg-accent-doux"
              @click="voirFiche(ficheChoisie)"
            >
              Centrer
            </button>
            <RouterLink
              v-if="ficheChoisie.noeud.personId !== null"
              :to="{ name: 'personne.detail', params: { id: ficheChoisie.noeud.personId } }"
              class="flex h-9 flex-1 items-center justify-center rounded-champ bg-accent font-semibold text-fond"
            >
              Fiche
            </RouterLink>
            <RouterLink
              v-else-if="ficheChoisie.noeud.demandeId !== undefined"
              :to="{ name: 'demande.detail', params: { id: ficheChoisie.noeud.demandeId } }"
              class="flex h-9 flex-1 items-center justify-center rounded-champ bg-accent font-semibold text-fond"
            >
              Demande
            </RouterLink>
          </div>
        </section>
      </template>

      <div v-if="chargement" class="absolute inset-0 flex items-center justify-center text-attenue">Placement de l’arbre…</div>
      <div v-else-if="erreur" class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-attenue">
        <span>{{ erreur }}</span>
        <button type="button" class="rounded-champ border border-ligne px-3 py-1.5 text-texte" @click="charger">Réessayer</button>
      </div>
    </div>

    <!-- Téléphone : la recherche en bas -->
    <div v-if="estMobile" class="relative z-10 flex flex-none items-center gap-2 border-t border-ligne bg-panneau px-3 pt-2.5 pb-[max(18px,env(safe-area-inset-bottom))]">
      <div class="relative flex-1">
        <input
          ref="champ"
          v-model="recherche"
          type="search"
          enterkeyhint="search"
          autocomplete="off"
          placeholder="Aller à une personne…"
          aria-label="Aller à une personne"
          class="h-11 w-full rounded-pastille border border-ligne bg-fond px-4 text-[15px] outline-none focus:border-accent"
          @keydown="surToucheRecherche"
        />
        <div
          v-if="resultats.length"
          class="absolute bottom-[52px] left-0 -right-[52px] z-20 rounded-panneau border border-ligne bg-panneau py-1 shadow-[0_-10px_30px_rgba(0,0,0,.5)]"
          role="listbox"
        >
          <button
            v-for="r in resultats"
            :key="r.personId"
            type="button"
            role="option"
            class="flex min-h-11 w-full flex-col items-start justify-center px-3 py-1 text-left leading-snug"
            @click="allerA(r)"
          >
            <span class="text-sm font-semibold">{{ r.displayName }}</span>
            <span class="text-xs text-attenue">{{ libelleBranche(r.branch) }}<template v-if="r.ligneDates"> · {{ r.ligneDates }}</template></span>
          </button>
        </div>
      </div>
      <button type="button" class="h-11 rounded-pastille bg-accent px-3.5 font-semibold text-fond" @click="meTrouver">Moi</button>
    </div>

    <!-- Filtres : menu déroulant sur bureau, feuillet qui monte du bas sur téléphone -->
    <template v-if="filtresOuverts && disposition">
      <div
        class="fixed inset-0 z-30"
        :class="estMobile ? 'bg-black/50' : ''"
        aria-hidden="true"
        @click="fermerFiltres"
      />
      <div
        id="filtres-histoire"
        class="fixed z-40 flex flex-col gap-3 border border-ligne bg-panneau px-4 pt-3.5 pb-5 shadow-[0_10px_30px_rgba(0,0,0,.5)]"
        :class="estMobile ? 'inset-x-0 bottom-0 max-h-[85dvh] overflow-y-auto rounded-t-2xl' : 'right-32 w-[340px] rounded-panneau'"
        :style="estMobile ? {} : { top: `${(racine?.getBoundingClientRect().top ?? 0) + 62}px` }"
        role="dialog"
        aria-label="Filtres"
      >
        <div class="flex items-center justify-between">
          <span class="text-base font-semibold">Filtres</span>
          <button type="button" class="h-9 font-semibold text-accent" @click="toutAfficher">Tout afficher</button>
        </div>
        <div v-if="disposition.quarts.length" class="text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Branches</div>
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="q in disposition.quarts"
            :key="q.branche"
            type="button"
            class="flex min-h-11 items-center gap-2 rounded-champ border px-2.5 py-1.5 text-left"
            :style="
              filtres.masquees.includes(q.branche)
                ? { opacity: '0.55' }
                : { borderColor: couleurBranche(q.branche), background: `color-mix(in srgb, ${couleurBranche(q.branche)} 12%, transparent)` }
            "
            :class="filtres.masquees.includes(q.branche) ? 'border-ligne' : ''"
            :aria-pressed="!filtres.masquees.includes(q.branche)"
            @click="basculerBranche(q.branche)"
          >
            <span class="h-2.5 w-2.5 flex-none rounded-[3px]" :style="{ background: couleurBranche(q.branche) }" />
            <span class="flex flex-col leading-tight">
              <span class="text-[13px] font-semibold">{{ q.nom || '—' }}</span>
              <span class="text-[11px] text-attenue">{{ q.libelle }}</span>
            </span>
          </button>
        </div>
        <div class="mt-1 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Afficher seulement</div>
        <button
          v-for="t in BASCULES"
          :key="t.cle"
          type="button"
          role="checkbox"
          :aria-checked="filtres[t.cle]"
          class="flex min-h-11 items-center gap-3 border-b border-ligne/60 px-0.5 text-left"
          @click="filtres[t.cle] = !filtres[t.cle]"
        >
          <span
            class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
            :class="filtres[t.cle] ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
          >{{ filtres[t.cle] ? '✓' : '' }}</span>
          <span class="flex-1 text-sm">{{ t.libelle }}</span>
          <span class="text-xs text-attenue">{{ t.sous }}</span>
        </button>
        <div class="text-xs text-attenue">
          {{ effectifAffiche }} personne{{ effectifAffiche > 1 ? 's' : '' }} affichée{{ effectifAffiche > 1 ? 's' : '' }} sur
          {{ disposition.fiches.length }}
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.histoire {
  /* Teintes propres à cet écran, déclinées pour les deux thèmes. */
  --hi-direct-plein: #c98f52;
  --hi-direct-texte: #6e4418;
  --hi-libelle-accent: #8a5a2b;
  --hi-arete: #b3ada4;
  --hi-grille: #e4e0d9;
  --hi-gouttiere-texte: #4a4640;
  --hi-gouttiere-annee: #8a847c;
}
@media (prefers-color-scheme: dark) {
  .histoire {
    --hi-direct-plein: #f3d3a8;
    --hi-direct-texte: #f7dcb8;
    --hi-libelle-accent: #f0c89a;
    --hi-arete: #4d5057;
    --hi-grille: #202227;
    --hi-gouttiere-texte: #c9c5bf;
    --hi-gouttiere-annee: #6b6763;
  }
}
.grille {
  background-image: radial-gradient(var(--hi-grille) 1px, transparent 1px);
  background-size: 22px 22px;
}
.gouttiere {
  background: linear-gradient(90deg, var(--h-fond) 62%, transparent);
}
</style>
