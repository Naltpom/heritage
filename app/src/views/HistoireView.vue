<script setup lang="ts">
/**
 * `/arbre` (anciennement `/histoire`) — tout l'arbre d'un coup, lisible à tous
 * les zooms.
 *
 * Recréation de la maquette « Arbre complet à niveaux de détail (3a) ». **Plus
 * on dézoome, moins on écrit** : trois niveaux (Ensemble, Familles, Fiches —
 * chaque fiche écrivant ce qui tient à l'écran), un bandeau des familles
 * toujours visible en haut, un zoom continu, une recherche qui vole jusqu'à la personne, des
 * filtres par branche et une mini-carte.
 *
 * Cet écran ASSEMBLE ; les pièces vivent dans `histoire/` :
 *  - `magasinGraphe.ts` — le graphe et sa disposition, gardés d'une visite à
 *    l'autre, revalidés par `ETag` (§ 5.2) au montage et au retour d'onglet,
 *    oubliés à tout changement de session ;
 *  - `useCadrage.ts` — pan, zoom, gestes, vol, **hors de la réactivité** ;
 *  - `TracesArbre.vue` (le `<svg>` des traits, en `viewBox`), `FichesMonde.vue`
 *    (les boîtes), tous deux pilotés impérativement ; `CalqueLibelles.vue` (les
 *    textes, recalculés à la fin d'un geste, pas à chaque image) ;
 *  - `BandeauFamilles`, `GouttiereGenerations`, `MiniCarte` (placés à chaque
 *    image, sans rendu), `FeuilletFiltres`, `VoletFiche`, `PanneauFicheMobile`,
 *    `ChampRechercheArbre` ;
 *  - `usePreferencesArbre.ts`, `useRechercheArbre.ts`, `useFicheChoisie.ts`.
 *
 * ADR 0007 — les trois règles, et leur note du 2026-10-06 sur ce qui est du HTML :
 *  1. le zoom du `<svg>` passe par son `viewBox`, jamais par `scale()` ;
 *  2. jamais `opacity` sur un élément du canevas : `stroke-opacity`, et pour le
 *     HTML une couleur partiellement transparente ;
 *  3. pendant un geste, aucun rendu Vue par image : le cadrage s'écrit dans le DOM.
 *
 * Les règles de traitement tenues ici :
 *  - le front ne filtre rien pour la confidentialité : « Personnes vivantes »
 *    lit `vivant` tel qu'il est reçu ;
 *  - un nœud restreint n'a pas de ligne de dates, et on ne la reconstitue pas ;
 *  - la recherche passe par l'API, jamais par un index local ;
 *  - un filtre **estompe**, il ne retire rien : la forme de l'arbre ne bouge pas —
 *    sauf deux bascules qui RETIRENT avant la disposition, pour que l'arbre se
 *    resserre : « Famille du conjoint » et « Familles par alliance » ;
 *  - les familles par alliance (l'ascendance du mari d'une tante, vue de la nièce)
 *    sont masquées par défaut ; affichées, chacune a son bloc, à droite des branches
 *    du lecteur (`histoire/alliances.ts`, décision du 2026-10-01) ;
 *  - une personne vivante hors du cercle s'écrit « Personne vivante » : le serveur
 *    n'envoie plus son nom, et l'écran ne relit pas celui qu'il recevrait ;
 *  - deux étiquettes ne se recouvrent jamais (`histoire/etiquettes.ts`) ;
 *  - deux formats de fiche, « Compact » et « Portrait » (`histoire/cartes.ts`), au
 *    choix du lecteur : une préférence de l'appareil, proposée d'elle-même sur
 *    téléphone à la première visite (`InvitationFormatDesCartes`) ;
 *  - aucun texte sous 10 px à l'écran.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { analyserErreur, type Branche, type Graphe, type ResultatDeRecherche } from '@/lib/api'
import { FILTRES, filtreDisponible, type IdFiltre, type NoeudPersonneEtendu } from '@/components/arbre/graphe'
import { useEstMobile, useTiroirs } from '@/composables/useTiroirs'
import { definirResume } from '@/composables/entete'
import { abonnerChoix } from '@/composables/rechercheGlobale'
import { useSession } from '@/composables/useSession'
import { useFormatDesCartes } from '@/composables/formatDesCartes'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import Segmente from '@/components/mise-en-page/Segmente.vue'
import PastilleFiltre from '@/components/evenements/PastilleFiltre.vue'
import InvitationFormatDesCartes from '@/components/arbre/InvitationFormatDesCartes.vue'
import LecteurVideo from '@/components/video/LecteurVideo.vue'
import { videoParId } from '@/lib/videos'
import { hauteurDuCadre } from '@/views/cadre'
import FichesMonde from '@/histoire/FichesMonde.vue'
import TracesArbre from '@/histoire/TracesArbre.vue'
import CalqueLibelles from '@/histoire/CalqueLibelles.vue'
import BandeauFamilles, { type BrancheMonde } from '@/histoire/BandeauFamilles.vue'
import GouttiereGenerations, { type EtiquetteDeGeneration } from '@/histoire/GouttiereGenerations.vue'
import MiniCarte from '@/histoire/MiniCarte.vue'
import FeuilletFiltres, { type BasculeAffichage } from '@/histoire/FeuilletFiltres.vue'
import VoletFiche from '@/histoire/VoletFiche.vue'
import PanneauFicheMobile from '@/histoire/PanneauFicheMobile.vue'
import ChampRechercheArbre from '@/histoire/ChampRechercheArbre.vue'
import { sansFamilleDuConjoint, type BrancheDeQuart, type DispositionHistoire, type FicheHistoire } from '@/histoire/lignees'
import { aDesFamillesParAlliance, classerParente, estParAlliance } from '@/histoire/alliances'
import { couleurBranche } from '@/histoire/branches'
import { alphaDeFiche, styleDeFiche } from '@/histoire/apparence'
import { anneauDe, libellesDesFiches, libellesDesFoyers, type Anneau } from '@/histoire/libelles'
import { disposer as disposerDuMagasin, grapheEnMemoire, revaliderGraphe, type ReglagesDeDisposition } from '@/histoire/magasinGraphe'
import { usePreferencesArbre, ecrirePreference, CLE_ALLIANCES } from '@/histoire/usePreferencesArbre'
import { useCadrage } from '@/histoire/useCadrage'
import { useRechercheArbre } from '@/histoire/useRechercheArbre'
import { useFicheChoisie } from '@/histoire/useFicheChoisie'
import {
  PALIERS,
  ajusterLargeur,
  borner as bornerEchelle,
  centrerSur,
  jaugeDuZoom,
  syDe,
  transformationMonde,
  transformationRelative,
  versMonde,
  viewBoxDe,
  type Cadrage,
  type Canevas,
} from '@/histoire/vue'
import { memoriserVue, reprendreVue, type VueMemorisee } from '@/histoire/retour'
import { ligneDe } from '@/histoire/ligneDirecte'
import { basculerFamille, familleDuClic, familleEclairee } from '@/histoire/eclairage'

const estMobile = useEstMobile()
const { fermerTiroirs, unTiroirEstOuvert, ouvert: ouvertTiroir } = useTiroirs()
const route = useRoute()
const routeur = useRouter()

/* ------------------------------------------------------------------ */
/* Chargement                                                          */
/* ------------------------------------------------------------------ */

const disposition = shallowRef<DispositionHistoire | null>(null)
const grapheCharge = shallowRef<Graphe | null>(null)
const nonPlaces = computed(() => grapheCharge.value?.calque.nonPlaces ?? [])
const chargement = ref(true)
/** L'erreur brute du chargement ; son message vient d'`analyserErreur` (`messageDErreur`). */
const erreur = shallowRef<unknown>(null)
const messageErreur = computed(() => (erreur.value === null ? '' : analyserErreur(erreur.value).message))

/*
 * Le format des fiches : `compact` (photo à gauche, la boîte du moteur) ou
 * `portrait` (photo en haut, plus grande ; boîte fixe, `histoire/cartes.ts`). Il
 * change la TAILLE des boîtes, donc la disposition se refait, comme pour une bascule.
 * La préférence est partagée avec le réglage des filtres et la fenêtre de choix.
 */
const { format: formatDesCartes } = useFormatDesCartes()
const enPortrait = computed(() => formatDesCartes.value === 'portrait')
const { avecConjoint, avecAlliances, alliancesDansLArbre, fratriesParNaissance } = usePreferencesArbre(redisposer)

const grapheSansConjoint = computed(() => (grapheCharge.value ? sansFamilleDuConjoint(grapheCharge.value) : null))
const aUneFamilleDeConjoint = computed(() => grapheSansConjoint.value !== grapheCharge.value)
const aDesAlliances = computed(() => (grapheCharge.value ? aDesFamillesParAlliance(grapheCharge.value) : false))
/** Le nombre de personnes par alliance, pour le libellé de la bascule. */
const effectifAlliances = computed(() => {
  const g = grapheCharge.value
  if (!g) return 0
  let n = 0
  for (const c of classerParente(g).values()) if (c.parente === 'alliance') n++
  return n
})

const reglages = (): ReglagesDeDisposition => ({
  avecConjoint: avecConjoint.value,
  avecAlliances: avecAlliances.value,
  alliancesDansLArbre: alliancesDansLArbre.value,
  fratriesParNaissance: fratriesParNaissance.value,
  format: formatDesCartes.value,
})

/** La disposition, reprise du magasin si rien n'a changé depuis la dernière visite. */
function disposer(graphe: Graphe): void {
  disposition.value = disposerDuMagasin(graphe, reglages())
}

/** Après une bascule : la disposition refaite, et ce qui n'existe plus oublié. */
function redisposer(): void {
  if (!grapheCharge.value) return
  disposer(grapheCharge.value)
  const d = disposition.value!
  if (selection.value !== null && !d.parId.has(selection.value)) selection.value = null
  if (!familleEclairee(d.traces.familles, familleChoisie.value)) familleChoisie.value = null
  const quarts = new Set<string>(d.quarts.map((q) => q.branche))
  filtres.masquees = filtres.masquees.filter((b) => quarts.has(b))
  cadrage.definir(ajusterLargeur(canevas.value, gouttiere.value))
}

/*
 * Refaire la disposition SANS perdre le lecteur — au changement de format, et
 * quand un graphe neuf arrive pendant qu'on regarde (retour d'onglet) : les
 * coordonnées du monde changent toutes, on garde à l'écran la personne qu'il
 * regardait (la sélection s'il y en a une, sinon la fiche la plus proche du
 * centre), au même zoom, à la même place. Sans arbre affiché (ou au niveau
 * Ensemble), on repart de l'arbre ajusté, comme après une bascule.
 */
function redisposerSurPlace(): void {
  const d = disposition.value
  if (!d || !grapheCharge.value) return
  const avant = cadrage.lire()
  let repere: { id: string; px: number; py: number } | null = null
  const choisie = selection.value !== null ? d.parId.get(selection.value) : undefined
  const ancre = choisie ?? (niveau.value > 0 ? ficheLaPlusProche(d, cadrage.centreMonde()) : undefined)
  if (ancre) repere = { id: ancre.id, px: versEcranX(ancre.cx), py: versEcranY(ancre.y + ancre.h / 2) }
  cadrage.arreterVol()
  redisposer()
  const f = repere ? disposition.value?.parId.get(repere.id) : undefined
  if (!f || !repere) return
  const c = canevas.value
  // Le même point d'écran, sauf s'il était sorti de la fenêtre : alors au centre.
  const dedans = repere.px > 0 && repere.px < taille.value.l && repere.py > 0 && repere.py < taille.value.h
  const [px, py] = dedans ? [repere.px, repere.py] : [taille.value.l / 2, taille.value.h / 2]
  cadrage.definir(centrerSur(bornerEchelle(avant.s, c), c, f.cx, f.y + f.h / 2, px, py))
}
watch(formatDesCartes, redisposerSurPlace)

/** La fiche dont le centre est le plus près d'un point du monde. */
function ficheLaPlusProche(d: DispositionHistoire, p: { x: number; y: number }): FicheHistoire | undefined {
  let meilleure: FicheHistoire | undefined
  let distance = Infinity
  for (const f of d.fiches) {
    const e = Math.hypot(f.cx - p.x, f.y + f.h / 2 - p.y)
    if (e < distance) [meilleure, distance] = [f, e]
  }
  return meilleure
}

/*
 * La bascule des alliances se refait tout de suite (pas d'observateur) : la recherche
 * l'allume puis cherche la fiche dans la disposition, dans la même foulée.
 */
function basculerAlliances(avec: boolean): void {
  if (avecAlliances.value === avec) return
  avecAlliances.value = avec
  ecrirePreference(CLE_ALLIANCES, avec)
  redisposer()
}
/**
 * Une personne qu'on cherche alors que sa famille par alliance est masquée : la
 * recherche la trouve (l'API ne sait rien de l'affichage) ; la choisir allume la
 * bascule, et la disposition refaite la contient.
 */
function afficherSonAlliance(personId: number): void {
  const g = grapheCharge.value
  if (!g || avecAlliances.value || disposition.value?.parPersonId.has(personId)) return
  if (estParAlliance(g, personId)) basculerAlliances(true)
}

/*
 * L'APPARITION, au premier affichage : l'arbre éclot depuis vous, en étoile. Chaque
 * fiche apparaît avec un retard qui croît avec sa distance à la vôtre — les proches
 * d'abord, les lointains ensuite —, puis les traits et les noms. Rien n'est attendu :
 * la disposition est entière, seul le dessin s'échelonne. Une seule fois par affichage
 * (pas à chaque filtre), et jamais si le système demande moins d'animations.
 */
const DUREE_REVELATION = 1600
const revelation = shallowRef<Map<string, number> | null>(null)
let finRevelation = 0
function lancerRevelation(): void {
  const d = disposition.value
  const moi = d?.parId.get(d.ego)
  if (!d || !moi) return
  try {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
  } catch {
    /* environnement sans matchMedia : on anime */
  }
  const distance = (f: FicheHistoire): number => Math.hypot(f.cx - moi.cx, (f.y - moi.y) * 1.6)
  let loin = 1
  for (const f of d.fiches) loin = Math.max(loin, distance(f))
  revelation.value = new Map(d.fiches.map((f) => [f.id, Math.round(DUREE_REVELATION * Math.pow(distance(f) / loin, 0.75))]))
  window.clearTimeout(finRevelation)
  finRevelation = window.setTimeout(() => (revelation.value = null), DUREE_REVELATION + 900)
}
/** L'animation d'apparition d'un élément rattaché à une fiche, décalée de `apres` ms. */
function apparition(id: string, nom: string, apres = 0): Record<string, string> {
  const delai = revelation.value?.get(id)
  return delai === undefined ? {} : { animation: `${nom} 420ms cubic-bezier(.2,.7,.3,1) ${delai + apres}ms backwards` }
}

/** Affiche un graphe : disposition, vue reprise ou demandée, apparition. */
async function afficher(graphe: Graphe): Promise<void> {
  grapheCharge.value = graphe
  // « Voir dans l'arbre » vers une personne d'une famille par alliance masquée : on l'affiche.
  const demandee = Number(route.query['personne']) || null
  if (demandee !== null && !avecAlliances.value && estParAlliance(graphe, demandee)) {
    avecAlliances.value = true
    ecrirePreference(CLE_ALLIANCES, true)
  }
  disposer(graphe)
  await nextTick()
  // Retour d'une fiche : la vue quittée, exactement ; sinon l'arbre ajusté.
  vueAReprendre = reprendreVue()
  // « Voir dans l'arbre » depuis une fiche : `?personne=<id>` l'emporte sur la vue mémorisée.
  personneDemandee = demandee
  if (personneDemandee !== null) vueAReprendre = null
  mesurer()
  ajuster()
  lancerRevelation()
}

/**
 * Au montage : le graphe gardé s'affiche tout de suite, puis se revalide (un
 * `304` le laisse en place, sans rien recalculer) ; sans graphe gardé, on attend
 * le `200`.
 */
async function charger(): Promise<void> {
  const enMemoire = grapheEnMemoire()
  if (enMemoire) {
    chargement.value = false
    erreur.value = null
    await afficher(enMemoire)
    void revalider()
    return
  }
  chargement.value = true
  erreur.value = null
  try {
    let r = await revaliderGraphe()
    // La session a changé pendant l'appel : on redemande, pour le lecteur d'aujourd'hui.
    if (r.statut === 'perime') r = await revaliderGraphe()
    if (r.statut === 'perime') throw new Error("L'arbre n'a pas pu être chargé.")
    await afficher(r.graphe)
  } catch (e) {
    erreur.value = e
  } finally {
    chargement.value = false
  }
}

/**
 * Revalide en arrière-plan (montage avec un graphe gardé, retour d'onglet). Un
 * `304` ne touche à rien ; un graphe neuf se redispose sans perdre le lecteur.
 * Un échec laisse l'arbre affiché : il n'est pas faux, il est peut-être en retard.
 */
async function revalider(): Promise<void> {
  if (!grapheCharge.value) {
    if (!chargement.value) await charger()
    return
  }
  try {
    const r = await revaliderGraphe()
    if (r.statut === 'nouveau' && r.graphe !== grapheCharge.value) {
      grapheCharge.value = r.graphe
      redisposerSurPlace()
    }
  } catch {
    /* l'arbre affiché reste en place ; le prochain retour d'onglet réessaiera */
  }
}

function surVisibilite(): void {
  if (document.visibilityState === 'visible') void revalider()
}

/* ------------------------------------------------------------------ */
/* Cadrage                                                             */
/* ------------------------------------------------------------------ */

const racine = ref<HTMLElement | null>(null)
const fenetre = ref<HTMLElement | null>(null)
const hauteurCadre = ref('70vh')
const taille = ref({ l: 0, h: 0 })

let premierAjustement = true
const gouttiere = computed(() => (estMobile.value ? 64 : 170))

const canevas = computed<Canevas>(() => ({
  largeur: taille.value.l,
  hauteur: taille.value.h,
  largeurMonde: disposition.value?.largeur ?? 1,
  hauteurMonde: disposition.value?.hauteur ?? 1,
}))

/* Les couches écrites à chaque image, sans Vue (ADR 0007). */
const traces = ref<InstanceType<typeof TracesArbre> | null>(null)
const boites = ref<InstanceType<typeof FichesMonde> | null>(null)
const calqueEcran = ref<HTMLElement | null>(null)
const bandeau = ref<InstanceType<typeof BandeauFamilles> | null>(null)
const generations = ref<InstanceType<typeof GouttiereGenerations> | null>(null)
const miniCarte = ref<InstanceType<typeof MiniCarte> | null>(null)
/** L'instantané d'après lequel la couche des textes est actuellement rendue. */
let renduApplique: Cadrage | null = null

function appliquer(c: Cadrage): void {
  const cv = canevas.value
  const sy = syDe(c.s, cv)
  traces.value?.cadrer(viewBoxDe(c, cv))
  boites.value?.cadrer(transformationMonde(c, cv))
  if (calqueEcran.value) calqueEcran.value.style.transform = transformationRelative(renduApplique ?? c, c, cv)
  bandeau.value?.placer((x) => c.tx + x * c.s, cv.largeur)
  generations.value?.placer((y) => c.ty + y * sy, cv.hauteur)
  miniCarte.value?.placer(-c.tx / c.s, (cv.largeur - c.tx) / c.s)
}

const cadrage = useCadrage({ fenetre, canevas: () => canevas.value, appliquer })
const niveau = cadrage.niveau
/** L'instantané publié à Vue : les textes, anneaux et noms de foyers s'en calculent. */
const rendu = cadrage.rendu

// Après chaque rendu des couches de texte : elles sont à jour, leur transformation repart de zéro.
watch(
  [rendu, disposition, estMobile],
  () => {
    renduApplique = rendu.value
    cadrage.reappliquer()
  },
  { flush: 'post' },
)

/** La jauge de zoom, pour l'administration seulement : un repère chiffré sur
 *  les captures d'écran (« à 30 % les noms se chevauchent »), pas un réglage. */
const { administrateur } = useSession()
const jauge = computed(() => (administrateur.value && disposition.value ? jaugeDuZoom(rendu.value.s, canevas.value) : null))
const ensemble = computed(() => niveau.value === 0)

const versEcranX = (x: number): number => {
  const c = cadrage.lire()
  return c.tx + x * c.s
}
const versEcranY = (y: number): number => {
  const c = cadrage.lire()
  return c.ty + y * syDe(c.s, canevas.value)
}

function mesurer(): void {
  if (racine.value) hauteurCadre.value = hauteurDuCadre(racine.value.getBoundingClientRect().top)
  const el = fenetre.value
  if (el) taille.value = { l: el.clientWidth, h: el.clientHeight }
}

let vueAReprendre: VueMemorisee | null = null
let personneDemandee: number | null = null

/** Le premier cadrage : la vue reprise au retour d'une fiche, sinon ajusté à la largeur. */
function ajuster(): void {
  if (!disposition.value || taille.value.l === 0) return
  premierAjustement = false
  const v = vueAReprendre
  vueAReprendre = null
  if (v) {
    reprendre(v)
    return
  }
  cadrage.definir(ajusterLargeur(canevas.value, gouttiere.value))
  const cible = personneDemandee !== null ? disposition.value.parPersonId.get(personneDemandee) : undefined
  personneDemandee = null
  if (cible) {
    selection.value = cible.id
    cadrage.voler(1, cible.cx, cible.y + cible.h / 2)
    // La requête a servi : on la retire, pour qu'un rechargement reparte de l'arbre entier.
    // Le routeur republie alors la page et vide le résumé : on le remet.
    void routeur.replace({ name: 'arbre' }).then(() => definirResume(compteur.value))
  }
}

function reprendre(v: VueMemorisee): void {
  const d = disposition.value!
  const c = canevas.value
  cadrage.definir(centrerSur(bornerEchelle(v.s, c), c, v.x, v.y, taille.value.l / 2, taille.value.h / 2))
  selection.value = v.selection !== null && d.parId.has(v.selection) ? v.selection : null
  const quarts = new Set<string>(d.quarts.map((q) => q.branche))
  filtres.masquees = v.filtres.masquees.filter((b): b is BrancheDeQuart => quarts.has(b))
  filtres.ligneDirecte = v.filtres.ligneDirecte
  filtres.vivants = v.filtres.vivants
}

/** Au départ vers une fiche : ce qu'il faut pour revenir exactement ici. */
function memoriserLaVue(): void {
  const c = cadrage.centreMonde()
  memoriserVue({
    x: c.x,
    y: c.y,
    s: cadrage.lire().s,
    selection: selection.value,
    filtres: { masquees: [...filtres.masquees], ligneDirecte: filtres.ligneDirecte, vivants: filtres.vivants },
  })
}

function allerAuPalier(i: number): void {
  const d = disposition.value
  if (!d) return
  const palier = PALIERS[i]!
  if (palier.echelle === null) {
    const cible = ajusterLargeur(canevas.value, gouttiere.value)
    const centre = versMonde(cible, canevas.value, taille.value.l / 2, taille.value.h / 2)
    cadrage.voler(cible.s, centre.x, centre.y)
  } else {
    const c = cadrage.centreMonde()
    cadrage.voler(palier.echelle, c.x, c.y)
  }
}

/* ------------------------------------------------------------------ */
/* Sélection                                                           */
/* ------------------------------------------------------------------ */

const selection = ref<string | null>(null)
const ficheChoisie = computed(() => (selection.value ? disposition.value?.parId.get(selection.value) ?? null : null))
/** La fiche entière de la personne choisie (volet, parenté) — et sa panne éventuelle. */
const lecture = useFicheChoisie(computed(() => ficheChoisie.value?.noeud.personId ?? null))
const { parente, ficheLue } = lecture

/*
 * Le VOLET (PC) : la fiche de la personne choisie, sur toute la droite. Il POUSSE
 * l'arbre — le canevas rétrécit, rien n'est caché dessous ; s'il venait couvrir la
 * fiche choisie, l'arbre la ramène dans la partie visible. Le téléphone garde le
 * panneau du bas.
 */
const voletOuvert = computed(() => !estMobile.value && ficheChoisie.value !== null)
watch(voletOuvert, async (ouvert) => {
  if (!ouvert) return
  await nextTick()
  mesurer()
  const f = ficheChoisie.value
  if (!f) return
  const x = versEcranX(f.cx)
  if (x < 40 || x > taille.value.l - 40) cadrage.voler(cadrage.lire().s, f.cx, f.y + f.h / 2)
})

/** Un proche cliqué dans le volet : on le choisit dans l'arbre, et l'écran le rejoint. */
function choisirProche(personId: number): void {
  afficherSonAlliance(personId)
  const f = disposition.value?.parPersonId.get(personId)
  if (!f) {
    void routeur.push({ name: 'personne.detail', params: { id: personId } })
    return
  }
  selection.value = f.id
  cadrage.voler(Math.max(cadrage.lire().s, 0.6), f.cx, f.y + f.h / 2)
}

function voirFiche(f: FicheHistoire): void {
  cadrage.voler(Math.max(cadrage.lire().s, 1), f.cx, f.y + f.h / 2)
}

function choisir(f: FicheHistoire): void {
  if (cadrage.vientDeGlisser()) return
  selection.value = f.id
  if (cadrage.lire().s < 0.42) cadrage.voler(1, f.cx, f.y + f.h / 2)
}

/*
 * La SURBRILLANCE D'UNE FAMILLE : un clic sur l'un de ses traits allume tous ses traits et
 * les fiches qu'ils relient (époux, enfants) ; un second clic, un clic dans le vide ou
 * Échap l'éteignent. Le reste de l'arbre ne bouge pas, et le clic sur une fiche garde son
 * sens (il ouvre la fiche).
 */
const familleChoisie = ref<string | null>(null)
const eclairage = computed(() => (disposition.value ? familleEclairee(disposition.value.traces.familles, familleChoisie.value) : null))

/**
 * Un seul écouteur, sur le canevas (ADR 0007, règle 3) : un trait cliqué bascule sa
 * famille ; un clic dans le vide l'éteint. Un glisser qui se termine sur une ligne n'est
 * pas un clic — même garde que pour les fiches.
 */
function surClicDuCanevas(e: MouseEvent): void {
  if (cadrage.vientDeGlisser()) return
  const cliquee = familleDuClic(e.target)
  if (cliquee !== null) {
    familleChoisie.value = basculerFamille(familleChoisie.value, cliquee)
    return
  }
  // Une fiche, un bouton, une étiquette cliquable : ce n'est pas le vide.
  const element = e.target as HTMLElement | null
  if (element?.closest?.('[role="button"], button, a, input')) return
  familleChoisie.value = null
}

function meTrouver(): void {
  const d = disposition.value
  const moi = d?.parId.get(d.ego)
  if (!moi) return
  selection.value = moi.id
  cadrage.voler(1, moi.cx, moi.y + moi.h / 2)
}

/* ------------------------------------------------------------------ */
/* Filtres : ils estompent, ils ne retirent rien                        */
/* ------------------------------------------------------------------ */

const filtres = reactive({
  masquees: [] as BrancheDeQuart[],
  ligneDirecte: false,
  vivants: false,
  /** Le filtre documentaire de la barre (Vérifiés, Sans source…) : un seul à la fois. */
  qualite: null as IdFiltre | null,
})
/** Le nombre de filtres actifs, pour « Filtres · n » et le bouton de l'en-tête. */
const nombreFiltresActifs = computed(
  () => filtres.masquees.length + (filtres.ligneDirecte ? 1 : 0) + (filtres.vivants ? 1 : 0) + (filtres.qualite ? 1 : 0),
)

function fermerFiltres(): void {
  fermerTiroirs()
}
function toutAfficher(): void {
  filtres.masquees = []
  filtres.ligneDirecte = false
  filtres.vivants = false
  filtres.qualite = null
}
function basculerQualite(id: IdFiltre): void {
  filtres.qualite = filtres.qualite === id ? null : id
}
function basculer(cle: BasculeAffichage): void {
  filtres[cle] = !filtres[cle]
}
/** Un filtre documentaire sans donnée est désactivé, et sa pastille dit pourquoi. */
const qualitesDisponibles = computed(() => {
  const personnes = (disposition.value?.fiches ?? []).map((f) => f.noeud as NoeudPersonneEtendu)
  return new Set(FILTRES.filter((f) => filtreDisponible(f, personnes)).map((f) => f.id))
})
const OPTIONS_PALIERS = PALIERS.map((p, i) => ({ valeur: i, libelle: p.libelle }))
function basculerBranche(b: BrancheDeQuart): void {
  filtres.masquees = filtres.masquees.includes(b) ? filtres.masquees.filter((x) => x !== b) : [...filtres.masquees, b]
}

/*
 * L'aide en vidéo, sur place : « Voir la vidéo », au pied des filtres, ouvre le
 * chapitre qui les explique PAR-DESSUS l'arbre, sans quitter l'écran — les
 * filtres restent tels qu'on les a laissés, feuillet compris, à la fermeture.
 */
const VIDEO_FILTRES = videoParId('filtres-de-l-arbre')
const aideFiltresOuverte = ref(false)

/**
 * La branche d'une fiche pour la TEINTE et les filtres : une fiche par alliance n'est
 * d'aucune branche du lecteur, quelle que soit celle que le serveur lui donne.
 */
const brancheDe = (f: FicheHistoire): string =>
  f.parente === 'alliance' && !disposition.value?.alliancesDansLArbre ? 'hors' : f.branche

function passe(f: FicheHistoire): boolean {
  if (brancheDe(f) !== 'hors' && (filtres.masquees as Branche[]).includes(f.branche)) return false
  if (filtres.ligneDirecte && enLigne(f) === null) return false
  if (filtres.vivants && !f.noeud.vivant) return false
  if (filtres.qualite) {
    const def = FILTRES.find((d) => d.id === filtres.qualite)
    if (def && !def.test(f.noeud as NoeudPersonneEtendu)) return false
  }
  return true
}

/*
 * LA LIGNE DIRECTE suit la personne choisie : ses ancêtres et ses descendants (les
 * vôtres quand personne n'est choisi). Une seule bascule, partagée par les Filtres, le
 * volet et le panneau du téléphone — elle reste cochée d'une personne à l'autre.
 */
const racineDeLaLigne = computed<string | null>(() =>
  filtres.ligneDirecte ? (ficheChoisie.value?.id ?? disposition.value?.ego ?? null) : null,
)
const ligne = computed<{ ascendants: Set<string>; descendants: Set<string> } | null>(() => {
  const r = racineDeLaLigne.value
  const g = grapheCharge.value
  if (r === null || !g) return null
  return ligneDe(g, r)
})
/** Où se trouve une fiche dans la ligne affichée : un ancêtre (ou la racine), un descendant, ni l'un ni l'autre. */
const enLigne = (f: FicheHistoire): 'ascendant' | 'descendant' | null => {
  const l = ligne.value
  if (!l) return null
  return l.ascendants.has(f.id) ? 'ascendant' : l.descendants.has(f.id) ? 'descendant' : null
}
/** La ligne directe n'est mise en valeur que lorsqu'on la demande. */
const surligner = computed(() => filtres.ligneDirecte)
/** Les traits dorés ne dessinent que VOTRE ligne directe : pour une autre personne, les fiches parlent. */
const surlignerTraits = computed(() => surligner.value && racineDeLaLigne.value === disposition.value?.ego)
const libelleLigne = computed(() => {
  const f = ficheChoisie.value
  if (!f || f.id === disposition.value?.ego) return 'Ma ligne directe'
  const nom = f.noeud.visibilite === 'restreinte' ? 'cette personne' : f.noeud.displayName
  return `Ligne directe de ${nom}`
})

/* ------------------------------------------------------------------ */
/* Recherche : par l'API, jamais par un index local                     */
/* ------------------------------------------------------------------ */

const champRecherche = ref<InstanceType<typeof ChampRechercheArbre> | null>(null)

const recherche = useRechercheArbre({
  disposition,
  estMobile,
  cadrage,
  largeurUtile: () => taille.value.l - gouttiere.value - 80,
  aller(r: ResultatDeRecherche): void {
    // Une personne d'une famille par alliance masquée : la choisir allume la bascule.
    afficherSonAlliance(r.personId)
    const f = disposition.value?.parPersonId.get(r.personId)
    champRecherche.value?.blur()
    if (!f) return
    selection.value = f.id
    cadrage.voler(1, f.cx, f.y + f.h / 2)
  },
})
const { idsCibles, cibles, curseur: curseurRecherche, listeOuverte, personneCourante } = recherche
const texteRecherche = recherche.mobile.texte
const { etat: etatRecherche, resultats: resultatsRecherche, tronque: rechercheTronquee, message: messageRecherche } = recherche.mobile

function surToucheRecherche(e: KeyboardEvent): void {
  if (recherche.surTouche(e)) champRecherche.value?.blur()
}

const effectifAffiche = computed(() => disposition.value?.fiches.filter(passe).length ?? 0)

/* ------------------------------------------------------------------ */
/* Rendu : boîtes (monde), textes (écran)                               */
/* ------------------------------------------------------------------ */

/**
 * Les styles des boîtes. Ils ne dépendent que du niveau Ensemble et des
 * filtres : déplacer la vue ne les recalcule pas. Estomper passe par la couleur,
 * jamais par `opacity` (`apparence.ts`).
 */
const stylesFiches = computed(() => {
  const d = disposition.value
  if (!d) return []
  const loin = ensemble.value
  const direct = surligner.value
  const ciblees = idsCibles.value
  const portrait = enPortrait.value
  return d.fiches.map((f) =>
    styleDeFiche(f, {
      ensemble: loin,
      portrait,
      alpha: alphaDeFiche(passe(f), ciblees, f.id),
      place: direct ? enLigne(f) : null,
      branche: brancheDe(f),
      apparition: apparition(f.id, 'apparition-fiche'),
    }),
  )
})

/** Le texte des fiches visibles, pour l'instantané du cadrage (jamais à chaque image). */
const libelles = computed(() => {
  const d = disposition.value
  if (!d) return []
  const direct = surligner.value
  const ciblees = idsCibles.value
  return libellesDesFiches({
    fiches: d.fiches.filter(passe),
    cadrage: rendu.value,
    canevas: canevas.value,
    niveau: niveau.value,
    portrait: enPortrait.value,
    directe: (f) => direct && enLigne(f) !== null,
    estompe: (f) => ciblees.size > 0 && !ciblees.has(f.id),
  })
})

const libellesFoyers = computed(() => {
  const d = disposition.value
  if (!d) return []
  return libellesDesFoyers(d.foyers, rendu.value, canevas.value, niveau.value, surlignerTraits.value)
})

/** Le bandeau des familles : les branches en coordonnées monde ; il se place lui-même. */
const branchesBandeau = computed<BrancheMonde[]>(() => {
  const d = disposition.value
  if (!d) return []
  const mobile = estMobile.value
  const branches: BrancheMonde[] = d.quarts.map((q) => ({
    cle: q.branche as string,
    couleur: couleurBranche(q.branche),
    nom: q.nom !== '' ? q.nom : q.libelle,
    sous: mobile ? '' : `${q.libelle} · ${q.effectif}`,
    x0: q.x0,
    x1: q.x1,
  }))
  for (const a of d.alliances) {
    branches.push({
      cle: `alliance-${a.pont}`,
      couleur: 'var(--h-attenue)',
      nom: a.nom !== '' ? a.nom : 'Par alliance',
      sous: mobile ? '' : `famille par alliance · ${a.effectif}`,
      x0: a.x0,
      x1: a.x1,
    })
  }
  const z = d.zoneDetachee
  if (z) {
    branches.push({
      cle: 'detaches',
      couleur: 'var(--h-attenue)',
      nom: 'Sans lien connu',
      sous: mobile ? '' : `position estimée · ${z.effectif}`,
      x0: z.x0,
      x1: z.x1,
    })
  }
  return branches
})

/** Une rangée trop basse n'a la place que pour une ligne (d'après l'instantané). */
const gouttiereCompacte = computed(() => (disposition.value?.pasRangee ?? 0) * syDe(rendu.value.s, canevas.value) < 38)
const etiquettesGouttiere = computed<EtiquetteDeGeneration[]>(() => {
  const d = disposition.value
  if (!d) return []
  const compact = gouttiereCompacte.value
  const mobile = estMobile.value
  return d.generations.map((g) => {
    const annee = g.annees?.replace('vers ', '') ?? ''
    return {
      rangee: g.rangee,
      y: g.y,
      libelle: mobile
        ? g.rangee === 0
          ? 'Vous'
          : `${g.rangee > 0 ? '+' : ''}${g.rangee}`
        : compact && annee
          ? `${g.libelle} · ${annee}`
          : g.libelle,
      annee: compact ? '' : mobile ? annee : (g.annees ?? ''),
    }
  })
})

const anneau = computed(() => (ficheChoisie.value ? anneauDe(ficheChoisie.value, rendu.value, canevas.value, 4) : null))

/** Les fiches de la famille en surbrillance, visibles à l'écran. */
const anneauxFamille = computed(() => {
  const d = disposition.value
  const f = eclairage.value
  if (!d || !f) return []
  return f.personnes
    .map((id) => d.parId.get(id))
    .map((p) => (p ? anneauDe(p, rendu.value, canevas.value, 3) : null))
    .filter((a): a is Anneau => a !== null)
})

/** Les cibles de la recherche visibles à l'écran ; celle du curseur est plus marquée. */
const anneauxCibles = computed(() =>
  cibles.value
    .map((f) => {
      const a = anneauDe(f, rendu.value, canevas.value, 3)
      return a ? { ...a, courante: personneCourante.value === f.noeud.personId } : null
    })
    .filter((a): a is Anneau & { courante: boolean } => a !== null),
)

function centrerDepuisMiniCarte(x: number): void {
  cadrage.voler(Math.max(cadrage.lire().s, 0.3), x, cadrage.centreMonde().y)
}

const bulle = computed(() => {
  if (selection.value) return ''
  if (estMobile.value) return niveau.value === 0 ? 'Pincez pour zoomer' : ''
  return [
    'Molette ou pincer pour zoomer · glisser pour se déplacer',
    'Les noms de famille apparaissent · zoomez encore pour les fiches',
    '',
  ][niveau.value]!
})

const compteur = computed(() => {
  const d = disposition.value
  if (!d) return ''
  return `${d.fiches.length} personnes · ${d.generations.length} générations`
})

/* ------------------------------------------------------------------ */
/* Clavier                                                             */
/* ------------------------------------------------------------------ */

function surToucheGlobale(e: KeyboardEvent): void {
  const cible = e.target as HTMLElement | null
  if (cible && (cible.tagName === 'INPUT' || cible.tagName === 'TEXTAREA' || cible.isContentEditable)) return
  if (e.ctrlKey || e.metaKey || e.altKey) return
  if (e.key === '/') {
    e.preventDefault()
    champRecherche.value?.focus()
  } else if (e.key === '0') allerAuPalier(0)
  else if (e.key === '+' || e.key === '=') cadrage.zoomer(1.6)
  else if (e.key === '-') cadrage.zoomer(1 / 1.6)
  else if (e.key === 'Escape') {
    // Un tiroir ouvert garde la priorité : il se referme avant qu'on désélectionne.
    if (unTiroirEstOuvert()) fermerFiltres()
    else if (familleChoisie.value !== null) familleChoisie.value = null
    else selection.value = null
  }
}

/* ------------------------------------------------------------------ */
/* Cycle de vie                                                        */
/* ------------------------------------------------------------------ */

let observateur: ResizeObserver | null = null
let desabonner: (() => void) | null = null

// Le résumé sous le titre, dans l'en-tête du téléphone.
watch(compteur, (t) => definirResume(t), { immediate: true })

function surRedimension(): void {
  mesurer()
}

/** Safari (iPhone) zoome toute la page sur un pincement : le canevas s'en charge seul. */
const bloquerZoomDePage = (e: Event): void => e.preventDefault()

onMounted(() => {
  // Le choix d'un résultat de la recherche de l'en-tête : on vole vers la personne.
  desabonner = abonnerChoix(recherche.allerA)
  mesurer()
  window.addEventListener('resize', surRedimension)
  window.addEventListener('keydown', surToucheGlobale)
  document.addEventListener('gesturestart', bloquerZoomDePage)
  // Le contrat (§ 5.2, étape 4) : revalider au montage ET au retour d'onglet.
  document.addEventListener('visibilitychange', surVisibilite)
  const el = fenetre.value
  if (el) {
    observateur = new ResizeObserver(() => {
      mesurer()
      if (premierAjustement) ajuster()
    })
    observateur.observe(el)
  }
  void charger()
})

onBeforeUnmount(() => {
  desabonner?.()
  window.removeEventListener('resize', surRedimension)
  window.removeEventListener('keydown', surToucheGlobale)
  document.removeEventListener('gesturestart', bloquerZoomDePage)
  document.removeEventListener('visibilitychange', surVisibilite)
  observateur?.disconnect()
  window.clearTimeout(finRevelation)
  lecture.annuler()
})
</script>

<template>
  <div ref="racine" class="histoire flex flex-col bg-fond text-texte" :style="{ height: hauteurCadre }">
    <!-- La barre d'écran commune : vue, pastilles, compteur, Filtres · n, actions. -->
    <BarreEcran
      ecran="Arbre"
      :filtres-actifs="nombreFiltresActifs"
      :voir="`Voir ${effectifAffiche} personne${effectifAffiche > 1 ? 's' : ''}`"
      @reinitialiser="toutAfficher"
    >
      <template #vue>
        <Segmente :options="OPTIONS_PALIERS" :valeur="niveau" etiquette="Niveau de détail" :colonnes="3" @choisir="allerAuPalier" />
      </template>
      <template #pastilles>
        <PastilleFiltre
          v-for="f in FILTRES"
          :key="f.id"
          :libelle="f.libelle"
          :titre="qualitesDisponibles.has(f.id) ? f.description : 'Rien de tel dans l’arbre'"
          :actif="filtres.qualite === f.id"
          :disponible="qualitesDisponibles.has(f.id)"
          @basculer="basculerQualite(f.id)"
        />
      </template>
      <template #compteur>{{ effectifAffiche }} fiches sur {{ disposition?.fiches.length ?? 0 }}</template>
      <template v-if="disposition" #filtres>
        <FeuilletFiltres
          :quarts="disposition.quarts"
          :masquees="filtres.masquees"
          :a-une-famille-de-conjoint="aUneFamilleDeConjoint"
          :avec-conjoint="avecConjoint"
          :a-des-alliances="aDesAlliances"
          :avec-alliances="avecAlliances"
          :effectif-alliances="effectifAlliances"
          :alliances-dans-l-arbre="alliancesDansLArbre"
          :fratries-par-naissance="fratriesParNaissance"
          :ligne-directe="filtres.ligneDirecte"
          :vivants="filtres.vivants"
          :libelle-ligne="libelleLigne"
          @basculer-branche="basculerBranche"
          @basculer-conjoint="avecConjoint = !avecConjoint"
          @basculer-alliances="basculerAlliances(!avecAlliances)"
          @ranger-alliances="alliancesDansLArbre = $event"
          @basculer-fratries="fratriesParNaissance = !fratriesParNaissance"
          @basculer="basculer"
          @aide="aideFiltresOuverte = true"
        />
      </template>
      <template #actions>
        <div class="flex overflow-hidden rounded-lg border border-ligne">
          <button type="button" aria-label="Dézoomer" class="h-8 w-8 text-lg hover:bg-accent-doux" @click="cadrage.zoomer(1 / 1.6)">−</button>
          <button type="button" aria-label="Zoomer" class="h-8 w-8 border-l border-ligne text-lg hover:bg-accent-doux" @click="cadrage.zoomer(1.6)">+</button>
        </div>
        <button
          type="button"
          class="h-8 w-8 rounded-champ border border-ligne text-sm text-attenue hover:text-texte"
          aria-label="Raccourcis clavier"
          title="Raccourcis : / rechercher · Entrée ouvrir · 0 tout l’arbre · + / − zoomer · Échap désélectionner"
        >?</button>
        <button type="button" class="h-8 rounded-champ bg-accent px-3 text-[13px] font-semibold whitespace-nowrap text-fond" @click="meTrouver">
          Me trouver
        </button>
      </template>
    </BarreEcran>

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

    <div class="flex min-h-0 flex-1">
    <!-- Le canevas -->
    <div
      ref="fenetre"
      class="grille relative min-w-0 flex-1 cursor-grab touch-none overflow-hidden select-none active:cursor-grabbing"
      @pointerdown="cadrage.gestes.surPointeurBas"
      @pointermove="cadrage.gestes.surPointeurDeplace"
      @pointerup="cadrage.gestes.surPointeurHaut"
      @pointercancel="cadrage.gestes.surPointeurHaut"
      @pointerleave="cadrage.gestes.surPointeurHaut"
      @click="surClicDuCanevas"
    >
      <template v-if="disposition">
        <!-- Les traits et leurs zones de clic : un `<svg>` en `viewBox`, piloté impérativement. -->
        <TracesArbre
          ref="traces"
          :traces="disposition.traces"
          :niveau="niveau"
          :surligner="surligner"
          :surligner-traits="surlignerTraits"
          :eclairage="eclairage?.d ?? null"
          :revelation="revelation !== null"
        />

        <!-- Les boîtes, en coordonnées monde : construites et mutées impérativement. -->
        <FichesMonde ref="boites" :fiches="disposition.fiches" :styles="stylesFiches" @choisir="choisir" />

        <!-- Photos, textes et anneaux, en coordonnées écran : seules les fiches visibles en reçoivent.
             Rendus pour l'instantané du cadrage ; entre deux, la transformation du conteneur les suit. -->
        <div ref="calqueEcran" class="pointer-events-none absolute inset-0 origin-top-left">
          <CalqueLibelles
            :libelles="libelles"
            :foyers="libellesFoyers"
            :surligner="surligner"
            :surligner-traits="surlignerTraits"
            :apparition="apparition"
          />
          <div
            v-for="a in anneauxCibles"
            :key="`cible-${a.id}`"
            class="pointer-events-none absolute rounded-[9px] border-[var(--hi-libelle-accent)]"
            :class="a.courante ? 'border-2 shadow-[0_0_0_5px_rgba(217,160,102,.25)]' : 'border'"
            :style="{ left: `${a.x}px`, top: `${a.y}px`, width: `${a.w}px`, height: `${a.h}px` }"
          />
          <div
            v-for="a in anneauxFamille"
            :key="`famille-${a.id}`"
            class="pointer-events-none absolute rounded-[8px] border-2 border-[var(--h-accent)]"
            :style="{ left: `${a.x}px`, top: `${a.y}px`, width: `${a.w}px`, height: `${a.h}px` }"
          />
          <div
            v-if="anneau"
            class="pointer-events-none absolute rounded-[9px] border-2 border-[var(--hi-libelle-accent)] shadow-[0_0_0_5px_rgba(217,160,102,.2)]"
            :style="{ left: `${anneau.x}px`, top: `${anneau.y}px`, width: `${anneau.w}px`, height: `${anneau.h}px` }"
          />
        </div>

        <!-- Bandeau des familles : collé en haut, à tous les zooms -->
        <BandeauFamilles ref="bandeau" :branches="branchesBandeau" :gouttiere="gouttiere" :mobile="estMobile" />

        <!-- Jauge de zoom (administration) : le pourcentage et sa place entre
             le zoom minimal et le maximal, avec les seuils Familles et Fiches. -->
        <div
          v-if="jauge"
          class="pointer-events-none absolute z-[2] flex items-center gap-2 rounded-pastille border border-ligne bg-panneau/95 px-2.5 py-1 font-mono text-[11px] text-texte"
          :style="{ left: `${gouttiere + 8}px`, top: `${(estMobile ? 30 : 40) + 6}px` }"
          data-jauge-zoom
          aria-hidden="true"
        >
          <span class="tabular-nums">{{ jauge.pourcent }} %</span>
          <span class="relative h-1.5 w-24 rounded-full bg-ligne">
            <span class="absolute inset-y-0 left-0 rounded-full bg-accent" :style="{ width: `${jauge.position * 100}%` }" />
            <span
              v-for="(seuil, i) in jauge.seuils"
              :key="i"
              class="absolute -inset-y-0.5 w-px bg-attenue"
              :style="{ left: `${seuil * 100}%` }"
            />
          </span>
          <span class="text-attenue">{{ PALIERS[niveau]!.libelle }}</span>
        </div>

        <!-- Gouttière des générations -->
        <GouttiereGenerations
          ref="generations"
          :generations="etiquettesGouttiere"
          :largeur="gouttiere"
          :mobile="estMobile"
          :decalage="gouttiereCompacte ? 8 : 14"
        />

        <!-- Mini-carte -->
        <MiniCarte v-if="!estMobile" ref="miniCarte" :disposition="disposition" :palier="PALIERS[niveau]!.libelle" @centrer="centrerDepuisMiniCarte" />

        <div
          v-if="bulle"
          class="pointer-events-none absolute rounded-pastille border border-ligne bg-panneau/95 px-3.5 py-1.5 text-xs whitespace-nowrap text-[var(--hi-gouttiere-texte)]"
          :style="{ left: `${estMobile ? 12 : 180}px`, bottom: `${estMobile ? 12 : 22}px` }"
        >
          {{ bulle }}
        </div>

        <!-- La fiche sélectionnée -->
        <PanneauFicheMobile
          v-if="ficheChoisie && estMobile"
          :fiche="ficheChoisie"
          :moi="ficheChoisie.id === disposition.ego"
          :couleur="couleurBranche(brancheDe(ficheChoisie))"
          :parente="parente"
          :ligne-directe="filtres.ligneDirecte"
          @fermer="selection = null"
          @centrer="voirFiche(ficheChoisie)"
          @partir="memoriserLaVue"
          @basculer-ligne="filtres.ligneDirecte = !filtres.ligneDirecte"
        />
      </template>

      <div v-if="chargement" class="absolute inset-0 flex items-center justify-center text-attenue">Placement de l’arbre…</div>
      <div v-else-if="erreur !== null" class="absolute inset-0 flex flex-col items-center justify-center gap-3 px-6 text-center text-attenue" role="alert">
        <span>{{ messageErreur }}</span>
        <button type="button" class="rounded-champ border border-ligne px-3 py-1.5 text-texte" @click="charger">Réessayer</button>
      </div>
    </div>

    <!-- Le volet (PC) : la fiche de la personne choisie ; il pousse l'arbre. -->
    <VoletFiche
      v-if="voletOuvert && ficheChoisie && disposition"
      :fiche="ficheChoisie"
      :moi="ficheChoisie.id === disposition.ego"
      :couleur="couleurBranche(brancheDe(ficheChoisie))"
      :parente="parente"
      :fiche-lue="ficheLue"
      :etat="lecture.etat.value"
      :erreur="lecture.erreur.value"
      :ligne-directe="filtres.ligneDirecte"
      @fermer="selection = null"
      @centrer="voirFiche(ficheChoisie)"
      @partir="memoriserLaVue"
      @basculer-ligne="filtres.ligneDirecte = !filtres.ligneDirecte"
      @choisir-proche="choisirProche"
      @relire="lecture.relire"
    />
    </div>

    <!-- Téléphone : la recherche en bas -->
    <ChampRechercheArbre
      v-if="estMobile"
      ref="champRecherche"
      v-model:texte="texteRecherche"
      :etat="etatRecherche"
      :resultats="resultatsRecherche"
      :tronque="rechercheTronquee"
      :message="messageRecherche"
      :curseur="curseurRecherche"
      :ouverte="listeOuverte"
      @touche="surToucheRecherche"
      @choisir="recherche.allerA"
      @reessayer="recherche.mobile.reessayer()"
      @ouvrir="listeOuverte = true"
      @fermer="listeOuverte = false"
      @moi="meTrouver"
    />

    <!-- Téléphone, première visite : Compact ou Portrait ? Une fois, jamais avec une autre fenêtre. -->
    <InvitationFormatDesCartes :pret="disposition !== null && !chargement && ouvertTiroir === 'aucun'" />
    <!-- L'aide des filtres : téléportée dans <body>, au-dessus du feuillet. -->
    <LecteurVideo
      v-if="aideFiltresOuverte"
      :video="VIDEO_FILTRES"
      @fin="aideFiltresOuverte = false"
      @fermer="aideFiltresOuverte = false"
    />
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
</style>
