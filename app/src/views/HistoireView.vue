<script setup lang="ts">
/**
 * `/histoire` — tout l'arbre d'un coup, lisible à tous les zooms.
 *
 * Recréation de la maquette « Arbre complet à niveaux de détail (3a) ». **Plus
 * on dézoome, moins on écrit** : trois niveaux (Ensemble, Familles, Fiches —
 * chaque fiche écrivant ce qui tient à l'écran), un bandeau des familles
 * toujours visible en haut, un zoom continu, une recherche qui vole jusqu'à la personne, des
 * filtres par branche et une mini-carte.
 *
 * Écran **neuf et autonome** : il ne modifie ni `/arbre` ni `/tree`. Il
 * réutilise les briques pures du dépôt — l'API (`/arbre/graphe`,
 * `rechercherPersonnes`, `lireFichePersonne`), `indexer()` et `nomDeFiche()` —
 * et l'en-tête applicatif : sur téléphone, la barre du bas porte la
 * navigation et le bouton filtres de l'en-tête ouvre le feuillet de cet écran.
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
import {
  NOM_D_UNE_PERSONNE_VIVANTE,
  lireFichePersonne,
  rechercherPersonnes,
  requeteApi,
  type Branche,
  type Graphe,
  type NoeudNonPlace,
  type ResultatDeRecherche,
} from '@/lib/api'
import { FILTRES, filtreDisponible, nomDeFiche, type IdFiltre, type NoeudPersonneEtendu } from '@/components/arbre/graphe'
import { useEstMobile, useTiroirs } from '@/composables/useTiroirs'
import { definirResume } from '@/composables/entete'
import { abonnerChoix, useRechercheGlobale } from '@/composables/rechercheGlobale'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import Segmente from '@/components/mise-en-page/Segmente.vue'
import PastilleFiltre from '@/components/evenements/PastilleFiltre.vue'
import { hauteurDuCadre } from '@/views/cadre'
import FichesMonde from '@/histoire/FichesMonde.vue'
import {
  LIBELLES_QUART,
  disposerLignees,
  nomEcrit,
  prenomDe,
  sansFamilleDuConjoint,
  type BrancheDeQuart,
  type DispositionHistoire,
  type FicheHistoire,
} from '@/histoire/lignees'
import { auFormat, entreePortrait, geometriePortrait, texteDePortrait } from '@/histoire/cartes'
import { useFormatDesCartes } from '@/composables/formatDesCartes'
import ReglageFormatDesCartes from '@/components/arbre/ReglageFormatDesCartes.vue'
import InvitationFormatDesCartes from '@/components/arbre/InvitationFormatDesCartes.vue'
import { bandeauDesFamilles, type BrancheDuBandeau } from '@/histoire/bandeau'
import { aDesFamillesParAlliance, classerParente, estParAlliance, sansFamillesParAlliance } from '@/histoire/alliances'
import { poserEtiquettes } from '@/histoire/etiquettes'
import {
  DUREE_VOL,
  PALIERS,
  ajusterLargeur,
  niveau as niveauDe,
  syDe,
  texteDeFiche,
  trajectoire,
  versMonde,
  zoomAutour,
  borner as bornerEchelle,
  centrerSur,
  jaugeDuZoom,
  reductionDuTexte,
  type Cadrage,
  type Canevas,
} from '@/histoire/vue'
import { memoriserVue, reprendreVue, type VueMemorisee } from '@/histoire/retour'
import { useSession } from '@/composables/useSession'

const estMobile = useEstMobile()
const { fermerTiroirs, unTiroirEstOuvert, ouvert: ouvertTiroir } = useTiroirs()

/* ------------------------------------------------------------------ */
/* Chargement                                                          */
/* ------------------------------------------------------------------ */

const disposition = shallowRef<DispositionHistoire | null>(null)
const grapheCharge = shallowRef<Graphe | null>(null)

/*
 * Deux bascules RETIRENT des fiches avant la disposition, pour que l'arbre se
 * recompacte (les autres filtres estompent sans rien déplacer). Ce sont des
 * préférences de ce navigateur seulement.
 *
 *  - La famille du conjoint (son ascendance, ses collatéraux) : affichée par défaut.
 *  - Les familles PAR ALLIANCE (décision du 2026-10-01) : masquées par défaut. Vue
 *    de la nièce, l'ascendance du mari de sa tante n'a aucun lien de sang avec elle ;
 *    affichée, chacune a son propre bloc, à droite des branches du lecteur.
 */
const CLE_CONJOINT = 'heritage.arbre.familleDuConjoint'
const CLE_ALLIANCES = 'heritage.arbre.famillesParAlliance'
/** Affichées : chacune à part (défaut, la vue d'avant) ou rangée dans les branches. */
const CLE_ALLIANCES_DANS_L_ARBRE = 'heritage.arbre.alliancesDansLArbre'
/** Les fratries dans l'ordre des naissances (l'aîné à gauche) ; décochée, la règle d'avant. */
const CLE_FRATRIES = 'heritage.arbre.fratriesParNaissance'
function lirePreference(cle: string, parDefaut: boolean): boolean {
  try {
    const v = localStorage.getItem(cle)
    return v === null ? parDefaut : v !== 'masquee'
  } catch {
    return parDefaut
  }
}
function ecrirePreference(cle: string, affichee: boolean): void {
  try {
    localStorage.setItem(cle, affichee ? 'affichee' : 'masquee')
  } catch {
    /* navigation privée : la bascule vaut pour la visite */
  }
}
const avecConjoint = ref(lirePreference(CLE_CONJOINT, true))
const avecAlliances = ref(lirePreference(CLE_ALLIANCES, false))
const alliancesDansLArbre = ref(lirePreference(CLE_ALLIANCES_DANS_L_ARBRE, false))
const fratriesParNaissance = ref(lirePreference(CLE_FRATRIES, false))
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
/*
 * Le format des fiches : `compact` (photo à gauche, la boîte du moteur) ou
 * `portrait` (photo en haut, plus grande ; boîte fixe, `histoire/cartes.ts`). Il
 * change la TAILLE des boîtes, donc la disposition se refait, comme pour une bascule.
 * La préférence est partagée avec le réglage des filtres et la fenêtre de choix.
 */
const { format: formatDesCartes } = useFormatDesCartes()
const enPortrait = computed(() => formatDesCartes.value === 'portrait')
function disposer(graphe: Graphe): void {
  // Les alliances d'abord, classées sur le graphe ENTIER : c'est lui que le serveur a classé.
  const sansAlliances = avecAlliances.value ? graphe : sansFamillesParAlliance(graphe)
  const filtre = avecConjoint.value ? sansAlliances : sansFamilleDuConjoint(sansAlliances)
  // Les boîtes en dernier : le tri des familles ne lit que les liens, jamais `w` ni `h`.
  disposition.value = disposerLignees(auFormat(filtre, formatDesCartes.value), {
    alliances: alliancesDansLArbre.value ? 'dans-l-arbre' : 'a-part',
    fratriesParNaissance: fratriesParNaissance.value,
  })
}
/** Après une bascule : la disposition refaite, et ce qui n'existe plus oublié. */
function redisposer(): void {
  if (!grapheCharge.value) return
  disposer(grapheCharge.value)
  const d = disposition.value!
  if (selection.value !== null && !d.parId.has(selection.value)) selection.value = null
  const quarts = new Set<string>(d.quarts.map((q) => q.branche))
  filtres.masquees = filtres.masquees.filter((b) => quarts.has(b))
  cadrage.value = ajusterLargeur(canevas.value, gouttiere.value)
}
watch(avecConjoint, (avec) => {
  ecrirePreference(CLE_CONJOINT, avec)
  redisposer()
})
watch(alliancesDansLArbre, (dans) => {
  ecrirePreference(CLE_ALLIANCES_DANS_L_ARBRE, dans)
  redisposer()
})
watch(fratriesParNaissance, (oui) => {
  ecrirePreference(CLE_FRATRIES, oui)
  redisposer()
})
/*
 * Changer de format refait la disposition — les coordonnées du monde changent
 * toutes. Pour ne pas perdre le lecteur, on garde à l'écran la personne qu'il
 * regardait : la sélection s'il y en a une, sinon la fiche la plus proche du
 * centre ; au même zoom, à la même place. Sans arbre affiché (ou au niveau
 * Ensemble), on repart de l'arbre ajusté, comme après une bascule.
 */
watch(formatDesCartes, () => {
  const d = disposition.value
  if (!d || !grapheCharge.value) return
  const avant = cadrage.value
  let repere: { id: string; px: number; py: number } | null = null
  const choisie = selection.value !== null ? d.parId.get(selection.value) : undefined
  const ancre = choisie ?? (niveau.value > 0 ? ficheLaPlusProche(d, centreMonde()) : undefined)
  if (ancre) repere = { id: ancre.id, px: versEcranX(ancre.cx), py: versEcranY(ancre.y + ancre.h / 2) }
  arreterVol()
  redisposer()
  const f = repere ? disposition.value?.parId.get(repere.id) : undefined
  if (!f || !repere) return
  const c = canevas.value
  // Le même point d'écran, sauf s'il était sorti de la fenêtre : alors au centre.
  const dedans = repere.px > 0 && repere.px < taille.value.l && repere.py > 0 && repere.py < taille.value.h
  const [px, py] = dedans ? [repere.px, repere.py] : [taille.value.l / 2, taille.value.h / 2]
  cadrage.value = centrerSur(bornerEchelle(avant.s, c), c, f.cx, f.y + f.h / 2, px, py)
})
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
const nonPlaces = ref<NoeudNonPlace[]>([])
const chargement = ref(true)
const erreur = ref<string | null>(null)

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    const graphe = await requeteApi<Graphe>('/arbre/graphe')
    grapheCharge.value = graphe
    // « Voir dans l'arbre » vers une personne d'une famille par alliance masquée : on l'affiche.
    const demandee = Number(route.query['personne']) || null
    if (demandee !== null && !avecAlliances.value && estParAlliance(graphe, demandee)) {
      avecAlliances.value = true
      ecrirePreference(CLE_ALLIANCES, true)
    }
    disposer(graphe)
    nonPlaces.value = graphe.calque.nonPlaces
    await nextTick()
    // Retour d'une fiche : la vue quittée, exactement ; sinon l'arbre ajusté.
    vueAReprendre = reprendreVue()
    // « Voir dans l'arbre » depuis une fiche : `?personne=<id>` l'emporte sur la vue mémorisée.
    personneDemandee = Number(route.query['personne']) || null
    if (personneDemandee !== null) vueAReprendre = null
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

/** La jauge de zoom, pour l'administration seulement : un repère chiffré sur
 *  les captures d'écran (« à 30 % les noms se chevauchent »), pas un réglage. */
const { administrateur } = useSession()
const jauge = computed(() => (administrateur.value && disposition.value ? jaugeDuZoom(cadrage.value.s, canevas.value) : null))
const ensemble = computed(() => niveau.value === 0)

const versEcranX = (x: number): number => cadrage.value.tx + x * s.value
const versEcranY = (y: number): number => cadrage.value.ty + y * sy.value

function mesurer(): void {
  if (racine.value) hauteurCadre.value = hauteurDuCadre(racine.value.getBoundingClientRect().top)
  const el = fenetre.value
  if (el) taille.value = { l: el.clientWidth, h: el.clientHeight }
}

let vueAReprendre: VueMemorisee | null = null
let personneDemandee: number | null = null
const route = useRoute()
const routeur = useRouter()

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
  cadrage.value = ajusterLargeur(canevas.value, gouttiere.value)
  const cible = personneDemandee !== null ? disposition.value.parPersonId.get(personneDemandee) : undefined
  personneDemandee = null
  if (cible) {
    selection.value = cible.id
    voler(1, cible.cx, cible.y + cible.h / 2)
    // La requête a servi : on la retire, pour qu'un rechargement reparte de l'arbre entier.
    // Le routeur republie alors la page et vide le résumé : on le remet.
    void routeur.replace({ name: 'arbre' }).then(() => definirResume(compteur.value))
  }
}

function reprendre(v: VueMemorisee): void {
  const d = disposition.value!
  const c = canevas.value
  cadrage.value = centrerSur(bornerEchelle(v.s, c), c, v.x, v.y, taille.value.l / 2, taille.value.h / 2)
  selection.value = v.selection !== null && d.parId.has(v.selection) ? v.selection : null
  const quarts = new Set<string>(d.quarts.map((q) => q.branche))
  filtres.masquees = v.filtres.masquees.filter((b): b is BrancheDeQuart => quarts.has(b))
  filtres.ligneDirecte = v.filtres.ligneDirecte
  filtres.vivants = v.filtres.vivants
}

/** Au départ vers une fiche : ce qu'il faut pour revenir exactement ici. */
function memoriserLaVue(): void {
  const c = centreMonde()
  memoriserVue({
    x: c.x,
    y: c.y,
    s: cadrage.value.s,
    selection: selection.value,
    filtres: { masquees: [...filtres.masquees], ligneDirecte: filtres.ligneDirecte, vivants: filtres.vivants },
  })
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

/** Un doigt : sa position dans le canevas (`x`, `y`) et à l'écran (`cx`, `cy`). */
interface Pointeur {
  x: number
  y: number
  cx: number
  cy: number
}
const pointeurs = new Map<number, Pointeur>()
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
  pointeurs.set(e.pointerId, { ...local(e), cx: e.clientX, cy: e.clientY })
  if (pointeurs.size === 1) glisser = { x: e.clientX, y: e.clientY, tx: cadrage.value.tx, ty: cadrage.value.ty, bouge: false }
  if (pointeurs.size === 2) {
    const [a, b] = [...pointeurs.values()] as [Pointeur, Pointeur]
    pincement = Math.hypot(a.x - b.x, a.y - b.y)
    // Le glissement à un doigt s'arrête : son point de départ date d'avant le zoom.
    glisser = null
    finDeGlisser = Date.now()
  }
}

/**
 * Le cadrage le plus récent, y compris celui qui attend la prochaine image.
 * C'est de lui qu'un geste doit repartir, jamais du dernier dessiné.
 */
const cadrageCourant = (): Cadrage => enAttente ?? cadrage.value

function surPointeurDeplace(e: PointerEvent): void {
  if (!pointeurs.has(e.pointerId)) return
  pointeurs.set(e.pointerId, { ...local(e), cx: e.clientX, cy: e.clientY })
  if (pointeurs.size >= 2) {
    if (!pincement) return
    const [a, b] = [...pointeurs.values()] as [Pointeur, Pointeur]
    const d = Math.hypot(a.x - b.x, a.y - b.y)
    planifier(zoomAutour(cadrageCourant(), canevas.value, (a.x + b.x) / 2, (a.y + b.y) / 2, d / pincement))
    pincement = d
    return
  }
  if (!glisser) return
  const dx = e.clientX - glisser.x
  const dy = e.clientY - glisser.y
  if (!glisser.bouge && Math.abs(dx) + Math.abs(dy) > 5) {
    glisser.bouge = true
    fenetre.value?.setPointerCapture(e.pointerId)
  }
  if (glisser.bouge) planifier({ s: cadrageCourant().s, tx: glisser.tx + dx, ty: glisser.ty + dy })
}

function surPointeurHaut(e: PointerEvent): void {
  if (!pointeurs.delete(e.pointerId)) return
  if (pointeurs.size < 2) pincement = null
  if (pointeurs.size === 1) {
    // Fin d'un pincement, un doigt reste posé : le glissement REPART d'ici, de ce
    // doigt et du cadrage d'après le zoom. Reprendre l'ancien point de départ
    // faisait sauter la vue loin de l'arbre.
    const [reste] = [...pointeurs.values()] as [Pointeur]
    const c = cadrageCourant()
    glisser = { x: reste.cx, y: reste.cy, tx: c.tx, ty: c.ty, bouge: true }
    finDeGlisser = Date.now()
  }
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
/** Un filtre documentaire sans donnée est désactivé, et sa pastille dit pourquoi. */
const qualitesDisponibles = computed(() => {
  const personnes = (disposition.value?.fiches ?? []).map((f) => f.noeud as NoeudPersonneEtendu)
  return new Set(FILTRES.filter((f) => filtreDisponible(f, personnes)).map((f) => f.id))
})
const OPTIONS_PALIERS = PALIERS.map((p, i) => ({ valeur: i, libelle: p.libelle }))
function basculerBranche(b: BrancheDeQuart): void {
  filtres.masquees = filtres.masquees.includes(b) ? filtres.masquees.filter((x) => x !== b) : [...filtres.masquees, b]
}

const OPTIONS_ALLIANCES = [
  { dans: false, libelle: 'À part', sous: 'chacune son bloc, à droite' },
  { dans: true, libelle: 'Dans mon arbre', sous: 'dans la branche qui les relie' },
] as const

const BASCULES = [
  { cle: 'ligneDirecte', libelle: 'Ma ligne directe', sous: 'vous et vos ancêtres' },
  { cle: 'vivants', libelle: 'Personnes vivantes', sous: '' },
] as const

/**
 * La branche d'une fiche pour la TEINTE et les filtres : une fiche par alliance n'est
 * d'aucune branche du lecteur, quelle que soit celle que le serveur lui donne.
 */
const brancheDe = (f: FicheHistoire): string =>
  f.parente === 'alliance' && !disposition.value?.alliancesDansLArbre ? 'hors' : f.branche

function passe(f: FicheHistoire): boolean {
  if (brancheDe(f) !== 'hors' && (filtres.masquees as Branche[]).includes(f.branche)) return false
  if (filtres.ligneDirecte && !f.directe) return false
  if (filtres.vivants && !f.noeud.vivant) return false
  if (filtres.qualite) {
    const def = FILTRES.find((d) => d.id === filtres.qualite)
    if (def && !def.test(f.noeud as NoeudPersonneEtendu)) return false
  }
  return true
}

/** La lignée directe n'est mise en valeur que lorsqu'on la demande. */
const surligner = computed(() => filtres.ligneDirecte)

/* L'état de la recherche, lu par le rendu : les cibles sont mises en évidence. */
const recherche = ref('')
const resultats = ref<ResultatDeRecherche[]>([])
const tronque = ref(false)
const curseur = ref(0)
const listeOuverte = ref(false)
const cibles = computed<FicheHistoire[]>(() => {
  const d = disposition.value
  if (!d || recherche.value.trim().length < 2) return []
  return resultats.value.map((r) => d.parPersonId.get(r.personId)).filter((f): f is FicheHistoire => f !== undefined)
})
const idsCibles = computed(() => new Set(cibles.value.map((f) => f.id)))

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
  const direct = surligner.value
  const ciblees = idsCibles.value
  const portrait = enPortrait.value
  return d.fiches.map((f) => {
    const n = f.noeud
    const sexe = n.sex === 'masculin' ? 'homme' : n.sex === 'feminin' ? 'femme' : 'inconnu'
    const base: Record<string, string> = {
      left: `${f.x}px`,
      top: `${f.y}px`,
      width: `${f.w}px`,
      height: `${f.h}px`,
      opacity: !passe(f) ? '0.1' : ciblees.size && !ciblees.has(f.id) ? '0.25' : '1',
    }
    if (loin) {
      base.background = f.directe && direct ? 'var(--hi-direct-plein)' : `color-mix(in srgb, ${couleurBranche(brancheDe(f))} 70%, transparent)`
      return base
    }
    base.background = `var(--h-${sexe})`
    base.border = f.directe && direct
      ? '1px solid var(--h-accent)'
      : n.enAttente
        ? '1px dashed var(--h-accent)'
        : `1px ${n.vivant ? 'dashed' : 'solid'} var(--h-${sexe}-bord)`
    // Le liseré de branche : à gauche d'une fiche couchée, en haut d'une fiche debout.
    base[portrait ? 'borderTop' : 'borderLeft'] = `4px solid ${couleurBranche(brancheDe(f))}`
    base.borderRadius = portrait ? '10px' : '7px'
    return base
  })
})

/** Sans lignée surlignée, le niveau Ensemble garde tous les liens, en discret. */
const opaciteOrdinaire = computed(() =>
  niveau.value === 0 ? (surligner.value ? 0 : 0.35) : niveau.value === 1 ? 0.55 : 0.9,
)

const transformationMonde = computed(
  () => `translate(${cadrage.value.tx}px, ${cadrage.value.ty}px) scale(${s.value}, ${sy.value})`,
)
const transformationTraits = computed(() => `translate(${cadrage.value.tx} ${cadrage.value.ty}) scale(${s.value} ${sy.value})`)

function dansLaFenetre(x: number, y: number, w: number, h: number): boolean {
  const X = versEcranX(x)
  const Y = versEcranY(y)
  return X + w * s.value > 0 && X < taille.value.l && Y + h * sy.value > 0 && Y < taille.value.h
}

/** L'emplacement photo mesuré par le moteur (`LARGEUR_PHOTO`, `ESPACE_PHOTO`) : 40 px, 8 px de marge. */
const LARGEUR_PHOTO = 40
const ESPACE_PHOTO = 8

interface LibelleCommun {
  id: string
  /** `src` : le portrait s'il y en a un (clé absente sur une fiche restreinte), la silhouette sinon. */
  photo: { x: number; y: number; d: number; src: string | null }
  x: number
  y: number
  w: number
  h: number
  directe: boolean
  estompe: boolean
  /** Le texte est posé à la taille qu'il aurait à 80 % (`w`, `h` en
   *  coordonnées de ce zoom-là) puis réduit de ce facteur (`reductionDuTexte`). */
  reduction: number
}
/** Compact : la photo à gauche, une ou deux lignes à côté (`texteDeFiche`). */
interface LibelleCompact extends LibelleCommun {
  forme: 'compact'
  t1: string
  t2: string
  taille: number
}
/** Portrait : la photo en haut, NOM, prénom et dates dessous (`texteDePortrait`). */
interface LibellePortrait extends LibelleCommun {
  forme: 'portrait'
  nom: string
  prenom: string
  dates: string[]
  /** « Personne vivante » : ni capitales, ni rien d'autre. */
  restreinte: boolean
}
type Libelle = LibelleCompact | LibellePortrait

/** Le texte des fiches visibles, en coordonnées écran (culling). */
const libelles = computed<Libelle[]>(() => {
  const d = disposition.value
  // Familles : les photos seules (si elles existent), sans texte ; Fiches : tout.
  if (!d || niveau.value < 1) return []
  const avecTexte = niveau.value >= 2
  const r = reductionDuTexte(s.value)
  const direct = surligner.value
  const ciblees = idsCibles.value
  const visibles = d.fiches.filter((f) => passe(f) && dansLaFenetre(f.x, f.y, f.w, f.h))
  const commun = (f: FicheHistoire) => ({
    id: f.id,
    directe: f.directe && direct,
    estompe: ciblees.size > 0 && !ciblees.has(f.id),
    reduction: r,
  })
  const src = (f: FicheHistoire): string | null => (typeof f.noeud.portrait === 'string' ? f.noeud.portrait : null)

  if (enPortrait.value) {
    return visibles.map((f): LibellePortrait => {
      const g = geometriePortrait(versEcranX(f.x), versEcranY(f.y), s.value, sy.value)
      const entree = entreePortrait(f.noeud)
      const w = g.texte.w / r
      const h = g.texte.h / r
      const texte = avecTexte ? texteDePortrait(entree, w, h) : { nom: '', prenom: '', dates: [] }
      return {
        ...commun(f),
        forme: 'portrait',
        ...texte,
        restreinte: entree.restreinte,
        photo: { ...g.photo, src: src(f) },
        ...g.texte,
        w,
        h,
      }
    })
  }

  const d0 = LARGEUR_PHOTO * Math.min(s.value, sy.value)
  const texte = (ESPACE_PHOTO * 2 + LARGEUR_PHOTO) * s.value
  return visibles.map((f): LibelleCompact => {
    const w = Math.max(0, f.w * s.value - texte - 4) / r
    const h = (f.h * sy.value) / r
    return {
      ...commun(f),
      forme: 'compact',
      ...(avecTexte ? texteDeFiche(
        {
          // Un nœud restreint s'écrit « Personne vivante », quoi que porte `etiquetteNom`.
          nom: f.noeud.visibilite === 'restreinte' ? nomEcrit(f.noeud) : nomDeFiche(f.noeud).texte,
          prenom: prenomDe(f.noeud),
          // Absente sur un nœud restreint : ni tiret, ni case vide.
          dates: typeof f.noeud.etiquetteDates === 'string' ? f.noeud.etiquetteDates : '',
        },
        w,
        h,
      ) : { t1: '', t2: '', taille: 12 }),
      photo: {
        x: versEcranX(f.x) + ESPACE_PHOTO * s.value,
        y: versEcranY(f.y) + (f.h * sy.value - d0) / 2,
        d: d0,
        src: src(f),
      },
      x: versEcranX(f.x) + texte,
      y: versEcranY(f.y),
      w,
      h,
    }
  })
})

/**
 * Les noms de foyer du niveau Familles. Ils ne se recouvrent jamais : posés par
 * priorité — les couples d'ancêtres, puis les plus larges —, chacun dans la largeur
 * de son couple et seulement là où rien n'est déjà écrit ; un nom qui ne tient pas
 * se tronque (« Ducat… ») ou se tait (`histoire/etiquettes.ts`).
 */
const libellesFoyers = computed(() => {
  const d = disposition.value
  if (!d || niveau.value !== 1) return []
  const fort = surligner.value
  const demandes = d.foyers
    .filter((c) => (c.ancetres || s.value > 0.25) && c.envergure * s.value > 44 && dansLaFenetre(c.x - 40, c.y - 20, 80, 20))
    .map((c, i) => {
      const x = versEcranX(c.x)
      // Un peu plus large que le couple : un nom court déborde volontiers de deux fiches serrées.
      const demi = (c.envergure * s.value) / 2 + 12
      return {
        cle: String(i),
        x,
        y: versEcranY(c.y) - 15,
        nom: c.nom,
        taille: c.ancetres && fort ? 11.5 : 10.5,
        min: x - demi,
        max: x + demi,
        priorite: (c.ancetres ? 1e9 : 0) + c.envergure,
        ancetres: c.ancetres,
      }
    })
  const ancetres = new Map(demandes.map((x) => [x.cle, x.ancetres]))
  return poserEtiquettes(demandes, { marge: 6, hauteur: 14 }).map((e) => ({
    cle: e.cle,
    x: e.x,
    y: e.y,
    nom: e.nom,
    ancetres: ancetres.get(e.cle) ?? false,
  }))
})

/**
 * Le bandeau des familles, collé en haut à tous les niveaux : chaque branche
 * garde son nom visible tant qu'elle occupe l'écran (`histoire/bandeau.ts`).
 * Il remplace les grands titres de branche posés au-dessus de l'arbre, qui
 * disparaissaient dès qu'on zoomait ou qu'on descendait dans les générations.
 */
const bandeauFamilles = computed(() => {
  const d = disposition.value
  if (!d) return []
  const mobile = estMobile.value
  const branches: BrancheDuBandeau[] = d.quarts.map((q) => ({
    cle: q.branche as string,
    couleur: couleurBranche(q.branche),
    nom: q.nom !== '' ? q.nom : q.libelle,
    sous: mobile ? '' : `${q.libelle} · ${q.effectif}`,
    x0: versEcranX(q.x0),
    x1: versEcranX(q.x1),
  }))
  for (const a of d.alliances) {
    branches.push({
      cle: `alliance-${a.pont}`,
      couleur: 'var(--h-attenue)',
      nom: a.nom !== '' ? a.nom : 'Par alliance',
      sous: mobile ? '' : `famille par alliance · ${a.effectif}`,
      x0: versEcranX(a.x0),
      x1: versEcranX(a.x1),
    })
  }
  const z = d.zoneDetachee
  if (z) {
    branches.push({
      cle: 'detaches',
      couleur: 'var(--h-attenue)',
      nom: 'Sans lien connu',
      sous: mobile ? '' : `position estimée · ${z.effectif}`,
      x0: versEcranX(z.x0),
      x1: versEcranX(z.x1),
    })
  }
  return bandeauDesFamilles(branches, gouttiere.value, taille.value.l, mobile ? 12 : 13)
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

/** Les cibles de la recherche visibles à l'écran ; celle du curseur est plus marquée. */
const anneauxCibles = computed(() =>
  cibles.value
    .filter((f) => dansLaFenetre(f.x, f.y, f.w, f.h))
    .map((f) => ({
      id: f.id,
      courante: resultats.value[curseur.value]?.personId === f.noeud.personId,
      x: versEcranX(f.x) - 3,
      y: versEcranY(f.y) - 3,
      w: f.w * s.value + 6,
      h: f.h * sy.value + 6,
    })),
)

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
      .map((q) => ({ cle: q.branche as string, branche: q.branche as string, x: q.x0 * k, w: Math.max(2, (q.x1 - q.x0) * k) }))
      .concat(
        d.alliances.map((a) => ({ cle: `alliance-${a.pont}`, branche: 'hors', x: a.x0 * k, w: Math.max(2, (a.x1 - a.x0) * k) })),
        d.zoneDetachee
          ? [{ cle: 'detaches', branche: 'hors', x: d.zoneDetachee.x0 * k, w: Math.max(2, (d.zoneDetachee.x1 - d.zoneDetachee.x0) * k) }]
          : [],
      ),
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
/* Recherche : par l'API, jamais par un index local                     */
/* ------------------------------------------------------------------ */

const champ = ref<HTMLInputElement | null>(null)
let minuterie = 0
let requete: AbortController | null = null

/**
 * À chaque frappe : l'API rend jusqu'à 50 personnes (son plafond), toutes sont
 * mises en évidence sur le canevas et la vue se cadre sur elles ; la liste
 * reste affichée pour choisir.
 */
watch(recherche, (q) => {
  // Sur grand écran, la recherche est celle de l'en-tête (voir plus bas).
  if (!estMobile.value) return
  window.clearTimeout(minuterie)
  requete?.abort()
  const texte = q.trim()
  if (texte.length < 2) {
    resultats.value = []
    tronque.value = false
    return
  }
  minuterie = window.setTimeout(() => {
    requete = new AbortController()
    rechercherPersonnes(texte, 50, requete.signal)
      .then((r) => {
        resultats.value = r.elements
        tronque.value = r.tronque
        curseur.value = 0
        listeOuverte.value = true
        cadrerCibles()
      })
      .catch(() => {})
  }, 120)
})

/*
 * Sur grand écran, le champ est dans l'en-tête (`RechercheEntete`) : l'arbre
 * s'abonne à ses résultats pour les mettre en évidence et cadrer la vue, suit
 * son curseur, et reçoit le choix final (voler vers la personne).
 */
const globale = useRechercheGlobale()
watch(
  () => globale.resultats.value,
  (liste) => {
    if (estMobile.value) return
    recherche.value = globale.requete.value
    resultats.value = [...liste]
    curseur.value = 0
    if (liste.length) cadrerCibles()
  },
)
watch(
  () => globale.curseur.value,
  (i) => {
    if (!estMobile.value && resultats.value.length) surCurseur(i)
  },
)

/** Cadre la vue sur toutes les cibles ; une seule : on vole vers elle. */
function cadrerCibles(): void {
  const fs = cibles.value
  if (!fs.length) return
  if (fs.length === 1) {
    const f = fs[0]!
    voler(Math.max(s.value, 0.9), f.cx, f.y + f.h / 2)
    return
  }
  const x0 = Math.min(...fs.map((f) => f.x))
  const x1 = Math.max(...fs.map((f) => f.x + f.w))
  const y0 = Math.min(...fs.map((f) => f.y))
  const y1 = Math.max(...fs.map((f) => f.y + f.h))
  const utile = Math.max(100, taille.value.l - gouttiere.value - 80)
  voler(Math.min(1, utile / Math.max(1, x1 - x0)), (x0 + x1) / 2, (y0 + y1) / 2)
}

function surCurseur(i: number): void {
  const n = resultats.value.length
  if (!n) return
  curseur.value = (i + n) % n
  const f = disposition.value?.parPersonId.get(resultats.value[curseur.value]!.personId)
  if (f) voler(Math.max(s.value, 0.9), f.cx, f.y + f.h / 2)
  document.getElementById(`resultat-histoire-${curseur.value}`)?.scrollIntoView({ block: 'nearest' })
}

function allerA(r: ResultatDeRecherche): void {
  // Une personne d'une famille par alliance masquée : la choisir allume la bascule.
  afficherSonAlliance(r.personId)
  const f = disposition.value?.parPersonId.get(r.personId)
  recherche.value = ''
  resultats.value = []
  listeOuverte.value = false
  champ.value?.blur()
  if (!f) return
  selection.value = f.id
  voler(1, f.cx, f.y + f.h / 2)
}

function surToucheRecherche(e: KeyboardEvent): void {
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    listeOuverte.value = true
    surCurseur(curseur.value + 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    listeOuverte.value = true
    surCurseur(curseur.value - 1)
  } else if (e.key === 'Enter') {
    const r = resultats.value[curseur.value]
    if (r) allerA(r)
  } else if (e.key === 'Escape') {
    recherche.value = ''
    listeOuverte.value = false
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
    if (unTiroirEstOuvert()) fermerFiltres()
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
  desabonner = abonnerChoix(allerA)
  mesurer()
  window.addEventListener('resize', surRedimension)
  window.addEventListener('keydown', surToucheGlobale)
  document.addEventListener('gesturestart', bloquerZoomDePage)
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
  desabonner?.()
  window.removeEventListener('resize', surRedimension)
  window.removeEventListener('keydown', surToucheGlobale)
  document.removeEventListener('gesturestart', bloquerZoomDePage)
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
        <!-- Pas un filtre : la forme des fiches. Ni dans « Filtres · n », ni dans « Réinitialiser ». -->
        <ReglageFormatDesCartes />
        <div v-if="disposition.quarts.length" class="mt-1 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Branches</div>
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
        <template v-if="aUneFamilleDeConjoint">
          <div class="mt-1 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Famille du conjoint</div>
          <button
            type="button"
            role="checkbox"
            :aria-checked="avecConjoint"
            class="flex min-h-11 items-center gap-3 border-b border-ligne/60 px-0.5 text-left"
            @click="avecConjoint = !avecConjoint"
          >
            <span
              class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
              :class="avecConjoint ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
            >{{ avecConjoint ? '✓' : '' }}</span>
            <span class="flex-1 text-sm">Afficher sa famille</span>
            <span class="text-xs text-attenue">masquée, l’arbre se resserre</span>
          </button>
        </template>
        <template v-if="aDesAlliances">
          <div class="mt-1 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Familles par alliance</div>
          <button
            type="button"
            role="checkbox"
            :aria-checked="avecAlliances"
            class="flex min-h-11 items-center gap-3 border-b border-ligne/60 px-0.5 text-left"
            @click="basculerAlliances(!avecAlliances)"
          >
            <span
              class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
              :class="avecAlliances ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
            >{{ avecAlliances ? '✓' : '' }}</span>
            <span class="flex flex-1 flex-col leading-tight">
              <span class="text-sm">Les afficher · {{ effectifAlliances }}</span>
              <span class="text-xs text-attenue">les familles des conjoints de vos proches</span>
            </span>
          </button>
          <div v-if="avecAlliances" class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Où afficher les familles par alliance">
            <button
              v-for="o in OPTIONS_ALLIANCES"
              :key="o.libelle"
              type="button"
              role="radio"
              :aria-checked="alliancesDansLArbre === o.dans"
              class="flex min-h-11 flex-col justify-center rounded-champ border px-2.5 py-1.5 text-left leading-tight"
              :class="alliancesDansLArbre === o.dans ? 'border-accent bg-accent-doux' : 'border-ligne'"
              @click="alliancesDansLArbre = o.dans"
            >
              <span class="text-[13px] font-semibold">{{ o.libelle }}</span>
              <span class="text-[11px] text-attenue">{{ o.sous }}</span>
            </button>
          </div>
        </template>
        <div class="mt-1 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Fratries</div>
        <button
          type="button"
          role="checkbox"
          :aria-checked="fratriesParNaissance"
          class="flex min-h-11 items-center gap-3 border-b border-ligne/60 px-0.5 text-left"
          @click="fratriesParNaissance = !fratriesParNaissance"
        >
          <span
            class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
            :class="fratriesParNaissance ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
          >{{ fratriesParNaissance ? '✓' : '' }}</span>
          <span class="flex flex-1 flex-col leading-tight">
            <span class="text-sm">Par ordre de naissance</span>
            <span class="text-xs text-attenue">l’aîné à gauche, le plus jeune à droite</span>
          </span>
        </button>
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
        <p class="m-0 text-xs text-attenue">
          Les autres fiches restent visibles, estompées.
        </p>
      </template>
      <template #actions>
        <div class="flex overflow-hidden rounded-lg border border-ligne">
          <button type="button" aria-label="Dézoomer" class="h-8 w-8 text-lg hover:bg-accent-doux" @click="zoomer(1 / 1.6)">−</button>
          <button type="button" aria-label="Zoomer" class="h-8 w-8 border-l border-ligne text-lg hover:bg-accent-doux" @click="zoomer(1.6)">+</button>
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
              :opacity="opaciteOrdinaire"
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
              :stroke="surligner ? 'var(--h-accent)' : 'var(--hi-arete)'"
              :stroke-width="surligner ? (niveau === 0 ? 2.5 : 2) : 1.2"
              vector-effect="non-scaling-stroke"
              :opacity="surligner ? 1 : opaciteOrdinaire"
            />
          </g>
        </svg>

        <div class="absolute top-0 left-0 origin-top-left" :style="{ transform: transformationMonde }">
          <FichesMonde :fiches="disposition.fiches" :styles="stylesFiches" @choisir="choisir" />
        </div>

        <!-- Photos et textes, en coordonnées écran : seules les fiches visibles en reçoivent -->
        <template v-for="t in libelles" :key="`photo-${t.id}`">
          <!-- Compact : un rond ; Portrait : un carré aux coins arrondis, le cadre d'une photo. -->
          <img
            v-if="t.photo.src"
            :src="t.photo.src"
            alt=""
            loading="lazy"
            decoding="async"
            class="pointer-events-none absolute object-cover"
            :class="t.forme === 'portrait' ? 'rounded-[14%]' : 'rounded-full'"
            :style="{ left: `${t.photo.x}px`, top: `${t.photo.y}px`, width: `${t.photo.d}px`, height: `${t.photo.d}px`, opacity: t.estompe ? 0.3 : 1 }"
          />
          <svg
            v-else
            class="pointer-events-none absolute"
            :class="t.forme === 'portrait' ? 'cadre-portrait rounded-[14%]' : ''"
            viewBox="0 0 40 40"
            aria-hidden="true"
            :style="{ left: `${t.photo.x}px`, top: `${t.photo.y}px`, width: `${t.photo.d}px`, height: `${t.photo.d}px`, opacity: t.estompe ? 0.3 : 1 }"
          >
            <circle cx="20" cy="13.6" r="6.8" class="silhouette" />
            <path d="M6.8,36.8 a13.2,13.2 0 0 1 26.4,0 Z" class="silhouette" />
          </svg>
        </template>
        <template v-for="t in libelles" :key="t.id">
          <div
            v-if="t.forme === 'compact' && t.t1 !== ''"
            class="pointer-events-none absolute flex origin-top-left flex-col justify-center overflow-hidden leading-tight"
            :style="{ left: `${t.x}px`, top: `${t.y}px`, width: `${t.w}px`, height: `${t.h}px`, transform: t.reduction < 1 ? `scale(${t.reduction})` : undefined, opacity: t.estompe ? 0.35 : 1 }"
          >
            <span
              class="truncate font-semibold"
              :style="{ fontSize: `${t.taille}px`, color: t.directe ? 'var(--hi-direct-texte)' : 'var(--h-texte)' }"
            >{{ t.t1 }}</span>
            <span v-if="t.t2" class="truncate font-mono text-[10.5px] text-attenue">{{ t.t2 }}</span>
          </div>
          <!-- Portrait : des hauteurs de ligne fixes (15, 15, 13), celles de `LIGNES_PORTRAIT`. -->
          <div
            v-else-if="t.forme === 'portrait' && t.nom !== ''"
            class="pointer-events-none absolute flex origin-top-left flex-col items-center overflow-hidden text-center"
            :style="{ left: `${t.x}px`, top: `${t.y}px`, width: `${t.w}px`, height: `${t.h}px`, transform: t.reduction < 1 ? `scale(${t.reduction})` : undefined, opacity: t.estompe ? 0.35 : 1 }"
          >
            <span
              v-if="t.nom"
              class="block h-[15px] max-w-full truncate text-xs leading-[15px] font-semibold"
              :class="t.restreinte ? 'text-attenue' : 'tracking-[.02em] uppercase'"
              :style="t.restreinte ? undefined : { color: t.directe ? 'var(--hi-direct-texte)' : 'var(--h-texte)' }"
            >{{ t.nom }}</span>
            <span
              v-if="t.prenom"
              class="block h-[15px] max-w-full truncate text-[11.5px] leading-[15px]"
              :class="t.restreinte ? 'font-semibold text-attenue' : ''"
              :style="t.restreinte ? undefined : { color: t.directe ? 'var(--hi-direct-texte)' : 'var(--h-texte)' }"
            >{{ t.prenom }}</span>
            <span
              v-for="(ligne, i) in t.dates"
              :key="i"
              class="block h-[13px] max-w-full truncate font-mono text-[10.5px] leading-[13px] text-attenue"
            >{{ ligne }}</span>
          </div>
        </template>

        <div
          v-for="c in libellesFoyers"
          :key="c.cle"
          class="pointer-events-none absolute -translate-x-1/2 font-bold whitespace-nowrap [text-shadow:0_1px_3px_var(--h-fond)]"
          :style="{
            left: `${c.x}px`,
            top: `${c.y}px`,
            fontSize: c.ancetres && surligner ? '11.5px' : '10.5px',
            color: c.ancetres && surligner ? 'var(--hi-libelle-accent)' : 'var(--h-attenue)',
          }"
        >
          {{ c.nom }}
        </div>

        <div
          v-for="a in anneauxCibles"
          :key="`cible-${a.id}`"
          class="pointer-events-none absolute rounded-[9px] border-[var(--hi-libelle-accent)]"
          :class="a.courante ? 'border-2 shadow-[0_0_0_5px_rgba(217,160,102,.25)]' : 'border'"
          :style="{ left: `${a.x}px`, top: `${a.y}px`, width: `${a.w}px`, height: `${a.h}px` }"
        />
        <div
          v-if="anneau"
          class="pointer-events-none absolute rounded-[9px] border-2 border-[var(--hi-libelle-accent)] shadow-[0_0_0_5px_rgba(217,160,102,.2)]"
          :style="{ left: `${anneau.x}px`, top: `${anneau.y}px`, width: `${anneau.w}px`, height: `${anneau.h}px` }"
        />

        <!-- Bandeau des familles : collé en haut, à tous les zooms -->
        <div
          class="bandeau-familles pointer-events-none absolute inset-x-0 top-0 z-[1]"
          :style="{ height: `${estMobile ? 30 : 40}px` }"
          aria-hidden="true"
        >
          <template v-for="b in bandeauFamilles" :key="b.cle">
            <div
              class="absolute bottom-0 h-[3px] rounded-full"
              :style="{ left: `${b.gauche}px`, width: `${b.largeur}px`, background: b.couleur }"
            />
            <div
              v-if="b.etiquette"
              class="absolute top-1 flex -translate-x-1/2 flex-col items-center leading-tight whitespace-nowrap"
              :style="{ left: `${b.etiquette.x}px` }"
            >
              <span class="font-bold tracking-tight" :class="estMobile ? 'text-xs' : 'text-[13px]'" :style="{ color: b.couleur }">{{
                b.etiquette.nom
              }}</span>
              <span v-if="b.etiquette.sous" class="text-[11px] text-attenue">{{ b.etiquette.sous }}</span>
            </div>
          </template>
        </div>

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
              :key="b.cle"
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
          :style="{ borderTop: `3px solid ${couleurBranche(brancheDe(ficheChoisie))}` }"
          aria-live="polite"
          @pointerdown.stop
        >
          <div class="flex items-start gap-2">
            <div class="flex flex-1 flex-col leading-snug">
              <span class="text-[17px] font-semibold">{{
                ficheChoisie.noeud.visibilite === 'restreinte' ? NOM_D_UNE_PERSONNE_VIVANTE : ficheChoisie.noeud.displayName
              }}</span>
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
            <span
              v-if="ficheChoisie.parente === 'alliance'"
              class="rounded-pastille border border-dashed border-ligne px-2.5 py-px text-xs text-[var(--hi-gouttiere-texte)]"
              title="Sa famille est liée à la vôtre par un mariage."
            >famille par alliance</span>
            <span v-else class="rounded-pastille border border-ligne px-2.5 py-px text-xs text-[var(--hi-gouttiere-texte)]">{{
              libelleBranche(ficheChoisie.noeud.branch)
            }}</span>
            <span
              v-if="ficheChoisie.detache"
              class="rounded-pastille border border-dashed border-ligne px-2.5 py-px text-xs text-attenue"
              title="Sans lien connu avec vous : génération estimée d’après les dates."
            >sans lien connu · position estimée</span>
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
              @click="memoriserLaVue"
            >
              Fiche
            </RouterLink>
            <RouterLink
              v-else-if="ficheChoisie.noeud.demandeId !== undefined"
              :to="{ name: 'demande.detail', params: { id: ficheChoisie.noeud.demandeId } }"
              class="flex h-9 flex-1 items-center justify-center rounded-champ bg-accent font-semibold text-fond"
              @click="memoriserLaVue"
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
          @focus="listeOuverte = true"
          @blur="listeOuverte = false"
        />
        <div
          v-if="listeOuverte && recherche.trim().length >= 2"
          class="absolute bottom-[52px] left-0 -right-[52px] z-20 rounded-panneau border border-ligne bg-panneau py-1 shadow-[0_-10px_30px_rgba(0,0,0,.5)] max-h-[60vh] overflow-y-auto"
          role="listbox"
          @pointerdown.prevent
        >
          <div class="px-3 pt-1 pb-1.5 text-[11px] text-attenue">
            <template v-if="resultats.length">{{ resultats.length }}{{ tronque ? '+' : '' }} personne{{ resultats.length > 1 ? 's' : '' }} · ↑ ↓ pour parcourir</template>
            <template v-else>Personne ne correspond.</template>
          </div>
          <button
            v-for="(r, i) in resultats"
            :id="`resultat-histoire-${i}`"
            :key="r.personId"
            type="button"
            role="option"
            :aria-selected="i === curseur"
            class="flex min-h-11 w-full flex-col items-start justify-center px-3 py-1 text-left leading-snug"
            :class="i === curseur ? 'bg-accent-doux' : ''"
            @click="allerA(r)"
          >
            <span class="text-sm font-semibold">{{ r.displayName }}</span>
            <span class="text-xs text-attenue">{{ libelleBranche(r.branch) }}<template v-if="r.ligneDates"> · {{ r.ligneDates }}</template></span>
          </button>
        </div>
      </div>
      <button type="button" class="h-11 rounded-pastille bg-accent px-3.5 font-semibold text-fond" @click="meTrouver">Moi</button>
    </div>

    <!-- Téléphone, première visite : Compact ou Portrait ? Une fois, jamais avec une autre fenêtre. -->
    <InvitationFormatDesCartes :pret="disposition !== null && !chargement && ouvertTiroir === 'aucun'" />
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
.silhouette {
  fill: var(--h-attenue);
  fill-opacity: 0.35;
}
/* L'emplacement d'une photo absente, en Portrait : le cadre se voit, à la taille d'une vraie. */
.cadre-portrait {
  background: color-mix(in srgb, var(--h-attenue) 14%, transparent);
}
.gouttiere {
  background: linear-gradient(90deg, var(--h-fond) 62%, transparent);
}
.bandeau-familles {
  background: linear-gradient(180deg, var(--h-fond) 70%, transparent);
}
</style>
