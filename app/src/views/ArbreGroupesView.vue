<script setup lang="ts">
/**
 * **`/arbre` — l'arbre en rectangles de familles** (demande du 09/10/2026 ; né sur
 * `/arbre-2`, passé sur `/arbre` le même jour — l'arbre d'avant, `HistoireView`, est
 * désormais sur `/arbre-2`).
 *
 * Copie de `HistoireView.vue` (`/arbre`), dont seul le MOTEUR change : chaque famille
 * — le tronc, les quatre quarts du lecteur, les quatre de son conjoint, les familles par
 * alliance à part, les personnes sans lien — a son rectangle, séparé des autres de deux
 * cartes au moins (`arbre2/groupes.ts`). Tout le reste (zoom, niveaux de détail,
 * recherche, filtres, panneau de la fiche) est celui de `/arbre`, sans rien y toucher :
 * les préférences de cet écran ont leurs propres clés (`heritage.arbre2.*`), il ne lui
 * laisse aucune vue à reprendre, et ses dispositions se gardent dans la même mémoire
 * sous des clés à elles (`moteur: 'groupes'`, `histoire/memoire.ts`). Le graphe gardé
 * (même `ETag`, même contenu) est partagé.
 *
 * ── LE RENDU : l'ADR 0007, et sa révision du 2026-10-14 pour cet écran ──────────
 *  1. **Le zoom des traits passe par le `viewBox`** (`arbre2/TracesGroupes.vue`),
 *     jamais par `transform: scale()` : seul le visible est peint.
 *  2. **Jamais `opacity` sur le canevas** : `stroke-opacity`, `fill-opacity`, ou une
 *     couleur transparente dans la peinture (`arbre2/apparence.ts › attenuer`) — pas
 *     un contexte d'empilement par fiche.
 *  3. **Vue ne tient ni une fiche, ni un trait** (`TracesGroupes`, `FichesGroupes`,
 *     construits impérativement, un écouteur délégué), et **le cadrage vit hors de la
 *     réactivité** (`arbre2/useCadrage.ts`) : pendant un geste, rien ne se rend ; les
 *     textes visibles se recalculent d'après un instantané, au plus toutes les 160 ms.
 *  Les boîtes sont du HTML, sans `viewBox` : UNE transformation sur leur seul
 *  conteneur — c'est la révision. `arbre2/rendu-adr0007.test.ts` lit ce fichier et
 *  rougit si une règle est enfreinte.
 *
 * Ce qui suit est l'en-tête de `/arbre`, qui vaut ici aussi.
 *
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
 *    sauf les branches masquées (`histoire/branches.ts`, le tronc reste) et quatre
 *    bascules, qui RETIRENT avant la disposition pour que l'arbre se resserre :
 *    « En attente de validation », « Famille du conjoint », « Familles par
 *    alliance » et « Personnes sans lien connu » ;
 *  - les familles par alliance (l'ascendance du mari d'une tante, vue de la nièce)
 *    sont masquées par défaut ; affichées, chacune a son bloc, à droite des branches
 *    du lecteur (`histoire/alliances.ts`, décision du 2026-10-01) ;
 *  - les personnes sans lien connu (qu'aucun chemin ne relie au lecteur) sont
 *    masquées par défaut ; affichées, elles gardent leur zone à droite
 *    (`histoire/sansLien.ts`, décision du 2026-10-04) ;
 *  - une personne vivante hors du cercle s'écrit « Personne vivante » : le serveur
 *    n'envoie plus son nom, et l'écran ne relit pas celui qu'il recevrait ;
 *  - deux étiquettes ne se recouvrent jamais (`histoire/etiquettes.ts`) ;
 *  - deux formats de fiche, « Compact » et « Portrait » (`histoire/cartes.ts`), au
 *    choix du lecteur : une préférence de l'appareil, proposée d'elle-même sur
 *    téléphone à la première visite (`InvitationFormatDesCartes`) ;
 *  - aucun texte sous 10 px à l'écran ;
 *  - sur PC (dès `lg`), « Ajouter une personne » ouvre le formulaire de proposition
 *    dans un volet à gauche, l'arbre à côté (`VoletProposition`, demande du
 *    09/10/2026) : le volet ne quitte `/arbre` que par un lien qu'on choisit
 *    (« Voir la demande »), et la personne déposée y apparaît, choisie, sans
 *    rechargement. Les fiches qui ressemblent à la saisie (« Est-ce déjà… ? ») y
 *    sont cerclées en pointillé, et « Voir dans l'arbre » les choisit
 *    (`montrerPersonne`, `montrerNoeud`).
 */
import {
  computed,
  defineAsyncComponent,
  defineComponent,
  h,
  nextTick,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  shallowRef,
  watch,
} from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  CLE_ETAG_GRAPHE,
  NOM_D_UNE_PERSONNE_VIVANTE,
  analyserErreur,
  chargerGraphe,
  oublierEtag,
  retenirEtag,
  type Graphe,
  type NoeudPersonne,
  type ResultatDeRecherche,
} from '@/lib/api'
import { FILTRES, filtreDisponible, type IdFiltre, type NoeudPersonneEtendu } from '@/components/arbre/graphe'
import { useCorrespondance, useEstMobile, useTiroirs } from '@/composables/useTiroirs'
import { definirResume } from '@/composables/entete'
import { abonnerChoix, useRechercheGlobale } from '@/composables/rechercheGlobale'
import { LIMITE_MAXIMALE, LONGUEUR_MINIMALE, useRecherchePersonnes } from '@/composables/useRecherchePersonnes'
import ChampRechercheArbre from '@/components/arbre/ChampRechercheArbre.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import Segmente from '@/components/mise-en-page/Segmente.vue'
import PastilleFiltre from '@/components/evenements/PastilleFiltre.vue'
import { hauteurDuCadre } from '@/views/cadre'
import FichesGroupes from '@/arbre2/FichesGroupes.vue'
import TracesGroupes from '@/arbre2/TracesGroupes.vue'
import CalqueLibelles from '@/arbre2/CalqueLibelles.vue'
import BandeauFamilles, { type BrancheMonde } from '@/arbre2/BandeauFamilles.vue'
import GouttiereGenerations, { type EtiquetteDeGeneration } from '@/arbre2/GouttiereGenerations.vue'
import TitresDesRectangles, { type TitreDeRectangle } from '@/arbre2/TitresDesRectangles.vue'
import MiniCarte from '@/arbre2/MiniCarte.vue'
import { useCadrage } from '@/arbre2/useCadrage'
import { dansLaFenetre, transformationMonde, transformationRelative, viewBoxDe } from '@/arbre2/cadrage'
import { alphaDeFiche, couleurBranche, styleDeFiche } from '@/arbre2/apparence'
import { MARGE, anneauDe, creerMemoireDesTextes, libellesDesFiches, libellesDesFoyers, type Anneau } from '@/arbre2/libelles'
import { useLectureDeLaFiche } from '@/arbre2/lectureFiche'
import { useRelectureDeLArbre } from '@/arbre2/relecture'
import {
  LIBELLES_QUART,
  sansFamilleDuConjoint,
  type BrancheDeQuart,
  type DispositionHistoire,
  type FicheHistoire,
  type Quart,
} from '@/histoire/lignees'
import { apresLaPeinture, creerChronometre, premierChargement, publier, reseauDe, type Chronometre } from '@/histoire/chronometre'
import { cleDeDisposition, identiteDuLecteur, memoireDeLArbre } from '@/histoire/memoire'
import { disposerEnGroupes, type DispositionGroupes, type OptionsGroupes, type RectangleDeGroupe } from '@/arbre2/groupes'
import { auFormat } from '@/histoire/cartes'
import { useFormatDesCartes } from '@/composables/formatDesCartes'
import ReglageFormatDesCartes from '@/components/arbre/ReglageFormatDesCartes.vue'
import InvitationFormatDesCartes from '@/components/arbre/InvitationFormatDesCartes.vue'
import InterrupteurFiltre from '@/components/arbre/InterrupteurFiltre.vue'
import {
  REQUETE_DEUX_VOLETS,
  REQUETE_VOLET_PROPOSITION,
  cadrageApresVolet,
  toucheIgnoree,
  voletDroitVisible,
  type PlaceDansLArbre,
} from '@/components/arbre/voletProposition'
import type { PersonneDeposee } from '@/components/proposition/depot'
import type { DemandeDeCompletion } from '@/components/proposition/fusion'
import type { EnEvidence } from '@/components/proposition/ressemblances'
import { aDesFamillesParAlliance, classerParente, estParAlliance, sansFamillesParAlliance } from '@/histoire/alliances'
import { effectifSansLien, estSansLien, noeudsSansLien, sansPersonnesSansLien } from '@/histoire/sansLien'
import { effectifEnAttente, sansDemandesEnAttente } from '@/histoire/enAttente'
import { quartsPresents, sansBranches } from '@/histoire/branches'
import {
  PALIERS,
  SEUILS,
  ajusterLargeur,
  syDe,
  versMonde,
  borner as bornerEchelle,
  centrerSur,
  jaugeDuZoom,
  SEUIL_DES_NOMS,
  bornerSeuilDesNoms,
  type Cadrage,
  type Canevas,
} from '@/histoire/vue'
import type { VueMemorisee } from '@/histoire/retour'
import { ligneDe, seulementLaLigne } from '@/histoire/ligneDirecte'
import { disposerEnPedigree } from '@/histoire/pedigree'
import { basculerFamille, familleDuClic, familleEclairee } from '@/histoire/eclairage'
import PortraitFiche from '@/components/personne/PortraitFiche.vue'
import IdentiteFiche from '@/components/personne/IdentiteFiche.vue'
import ParenteFiche from '@/components/personne/ParenteFiche.vue'
import FaitsFiche from '@/components/personne/FaitsFiche.vue'
import { useSession } from '@/composables/useSession'
import LecteurVideo from '@/components/video/LecteurVideo.vue'
import { videoParId } from '@/lib/videos'

/*
 * LE MOTEUR, seul réglage de l'écran. `/arbre` prend les rectangles de `arbre2/groupes.ts`
 * (le défaut) ; `/arbre-review` (`arbre-review/ArbreReviewView.vue`, demande du
 * 22/10/2026) passe le sien — même forme de disposition, même écran autour, seules
 * changent les places et les lignes. `moteur` nomme ses dispositions dans la mémoire de
 * l'arbre : les deux pages partagent le graphe gardé, jamais une disposition. Le moteur est
 * PASSÉ plutôt qu'importé ici : le code de `/arbre-review` n'alourdit pas `/arbre`.
 */
const props = withDefaults(
  defineProps<{
    moteur?: 'groupes' | 'revue'
    disposerLesGroupes?: (graphe: Graphe, options: OptionsGroupes) => DispositionGroupes
  }>(),
  { moteur: 'groupes', disposerLesGroupes: disposerEnGroupes },
)

const estMobile = useEstMobile()
const { fermerTiroirs, unTiroirEstOuvert, ouvert: ouvertTiroir } = useTiroirs()

/* ------------------------------------------------------------------ */
/* Chargement                                                          */
/* ------------------------------------------------------------------ */

const disposition = shallowRef<DispositionHistoire | DispositionGroupes | null>(null)
const grapheCharge = shallowRef<Graphe | null>(null)
/** Les quarts que le graphe porte, masqués ou non : ceux qui ont un bouton. */
const quartsMasquables = shallowRef<Set<BrancheDeQuart>>(new Set())

/*
 * Quatre bascules RETIRENT des fiches avant la disposition, pour que l'arbre se
 * recompacte (les autres filtres estompent sans rien déplacer). Ce sont des
 * préférences de ce navigateur seulement.
 *
 *  - Les demandes EN ATTENTE DE VALIDATION (demande du 22/10/2026) : affichées par
 *    défaut, en pointillé. Le calque n'est servi qu'à l'auteur des demandes et aux
 *    administrateurs (§ 13 du contrat d'API) ; masqué, l'arbre ne montre que les
 *    données validées (`histoire/enAttente.ts`).
 *  - La famille du conjoint (son ascendance, ses collatéraux) : affichée par défaut.
 *  - Les familles PAR ALLIANCE (décision du 2026-10-01) : masquées par défaut. Vue
 *    de la nièce, l'ascendance du mari de sa tante n'a aucun lien de sang avec elle ;
 *    affichée, chacune a son propre bloc, à droite des branches du lecteur.
 *  - Les personnes SANS LIEN CONNU (décision du 2026-10-04) : masquées par défaut,
 *    retenues une fois affichées. Aucun chemin ne les relie au lecteur ; affichées,
 *    elles gardent leur zone « sans lien connu », au-delà du bord droit.
 */
const CLE_EN_ATTENTE = 'heritage.arbre2.enAttenteDeValidation'
const CLE_CONJOINT = 'heritage.arbre2.familleDuConjoint'
const CLE_ALLIANCES = 'heritage.arbre2.famillesParAlliance'
const CLE_SANS_LIEN = 'heritage.arbre2.sansLienConnu'
/** Affichées : chacune à part (défaut, la vue d'avant) ou rangée dans les branches. */
const CLE_ALLIANCES_DANS_L_ARBRE = 'heritage.arbre2.alliancesDansLArbre'
/** Les fratries dans l'ordre des naissances (l'aîné à gauche) ; décochée, la règle d'avant. */
const CLE_FRATRIES = 'heritage.arbre2.fratriesParNaissance'
/** La ligne directe : les autres fiches estompées (défaut), ou retirées de l'écran. */
const CLE_LIGNE_SEULE = 'heritage.arbre2.ligneDirecteSeule'
/** Les rectangles des familles : affichés (défaut) ou masqués ; la disposition ne change pas. */
const CLE_RECTANGLES = 'heritage.arbre2.rectangles'
/** Le zoom à partir duquel les noms s'écrivent (niveau Fiches), en fraction. */
const CLE_SEUIL_DES_NOMS = 'heritage.arbre2.seuilDesNoms'
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
const avecEnAttente = ref(lirePreference(CLE_EN_ATTENTE, true))
const avecConjoint = ref(lirePreference(CLE_CONJOINT, true))
const avecAlliances = ref(lirePreference(CLE_ALLIANCES, false))
const avecSansLien = ref(lirePreference(CLE_SANS_LIEN, false))
const alliancesDansLArbre = ref(lirePreference(CLE_ALLIANCES_DANS_L_ARBRE, false))
const fratriesParNaissance = ref(lirePreference(CLE_FRATRIES, false))
const ligneSeule = ref(lirePreference(CLE_LIGNE_SEULE, false))
const avecRectangles = ref(lirePreference(CLE_RECTANGLES, true))
watch(avecRectangles, (avec) => ecrirePreference(CLE_RECTANGLES, avec))
function lireSeuilDesNoms(): number {
  try {
    const v = localStorage.getItem(CLE_SEUIL_DES_NOMS)
    return v === null ? SEUIL_DES_NOMS.defaut : bornerSeuilDesNoms(Number(v))
  } catch {
    return SEUIL_DES_NOMS.defaut
  }
}
const seuilDesNoms = ref(lireSeuilDesNoms())
watch(seuilDesNoms, (v) => {
  try {
    if (v === SEUIL_DES_NOMS.defaut) localStorage.removeItem(CLE_SEUIL_DES_NOMS)
    else localStorage.setItem(CLE_SEUIL_DES_NOMS, String(v))
  } catch {
    /* navigation privée : le réglage vaut pour la visite */
  }
})
/** Le calque « en attente » que le graphe porte : rien, pas de bascule. */
const enAttente = computed(() => (grapheCharge.value ? effectifEnAttente(grapheCharge.value) : { personnes: 0, liens: 0 }))
const aDesDemandesEnAttente = computed(() => enAttente.value.personnes > 0 || enAttente.value.liens > 0)
/**
 * Le graphe que l'écran dessine : sans le calque quand il est masqué. Les autres
 * bascules et leurs compteurs se lisent sur lui — une personne qu'une demande seule
 * reliait est « sans lien connu » dans l'arbre validé.
 */
const grapheVisible = computed(() => {
  const g = grapheCharge.value
  return g && !avecEnAttente.value ? sansDemandesEnAttente(g) : g
})
const grapheSansConjoint = computed(() => (grapheVisible.value ? sansFamilleDuConjoint(grapheVisible.value) : null))
const aUneFamilleDeConjoint = computed(() => grapheSansConjoint.value !== grapheVisible.value)
const aDesAlliances = computed(() => (grapheVisible.value ? aDesFamillesParAlliance(grapheVisible.value) : false))
/** Le nombre de personnes par alliance, pour le libellé de la bascule. */
const effectifAlliances = computed(() => {
  const g = grapheVisible.value
  if (!g) return 0
  let n = 0
  for (const c of classerParente(g).values()) if (c.parente === 'alliance') n++
  return n
})
/** Le nombre de personnes sans lien connu : 0, pas de bascule. */
const effectifSansLienConnu = computed(() => (grapheVisible.value ? effectifSansLien(grapheVisible.value) : 0))
/*
 * Le format des fiches : `compact` (photo à gauche, la boîte du moteur) ou
 * `portrait` (photo en haut, plus grande ; boîte fixe, `histoire/cartes.ts`). Il
 * change la TAILLE des boîtes, donc la disposition se refait, comme pour une bascule.
 * La préférence est partagée avec le réglage des filtres et la fenêtre de choix.
 */
const { format: formatDesCartes } = useFormatDesCartes()
const enPortrait = computed(() => formatDesCartes.value === 'portrait')
/** Le chronomètre du premier affichage (`histoire/chronometre.ts`), `null` ensuite. */
let chrono: Chronometre | null = null
/**
 * Le graphe affiché tel qu'il est GARDÉ (`histoire/memoire.ts`) : pour quel lecteur,
 * sous quel `ETag`. `null` quand rien ne se garde (pas d'`ETag`, pas de session).
 */
let grapheGarde: { identite: string; etag: string } | null = null
/**
 * La clé de la disposition en groupes que `disposer()` ferait maintenant : TOUT ce qui
 * entre dans son calcul, hors le graphe (que l'`ETag` désigne), et le moteur — `/arbre-2`
 * garde les siennes dans la même mémoire. La ligne seule n'y est pas : sa disposition
 * en pedigree, petite, ne se garde pas. Les rectangles affichés ou masqués non plus :
 * ils ne changent pas la disposition.
 */
function cleDeLaDisposition(): string {
  return cleDeDisposition({
    moteur: props.moteur,
    avecEnAttente: avecEnAttente.value,
    avecAlliances: avecAlliances.value,
    avecConjoint: avecConjoint.value,
    avecSansLien: avecSansLien.value,
    masquees: filtres.masquees,
    format: formatDesCartes.value,
    alliancesDansLArbre: alliancesDansLArbre.value,
    fratriesParNaissance: fratriesParNaissance.value,
  })
}
function disposer(recu: Graphe): void {
  const mesure = <T>(etape: string, calcul: () => T): T => (chrono ? chrono.mesure(etape, calcul) : calcul())
  // Le calque « en attente » masqué : tout ce qui suit se lit sur l'arbre validé seul.
  const graphe = avecEnAttente.value ? recu : sansDemandesEnAttente(recu)
  // Les alliances d'abord, classées sur le graphe ENTIER : c'est lui que le serveur a classé.
  const sansAlliances = avecAlliances.value ? graphe : sansFamillesParAlliance(graphe)
  const sansConjoint = avecConjoint.value ? sansAlliances : sansFamilleDuConjoint(sansAlliances)
  // Les îlots sont repérés sur le graphe ENTIER : c'est lui qui dit ce qui est relié au lecteur.
  const avecLesLiens = avecSansLien.value ? sansConjoint : sansPersonnesSansLien(sansConjoint, noeudsSansLien(graphe))
  // Les quarts masqués quittent l'écran : l'arbre se refait sans eux, le tronc reste.
  quartsMasquables.value = quartsPresents(avecLesLiens, graphe)
  // (Calculé à la demande : une disposition gardée n'en a pas besoin.)
  let filtre: Graphe | null = null
  const leFiltre = (): Graphe => (filtre ??= sansBranches(avecLesLiens, filtres.masquees, graphe))
  // La ligne directe SEULE : tout le reste quitte l'écran, et l'arbre se refait sur elle.
  const racine = cleLigneSeule.value
  cleDisposee = racine
  // Elle a sa propre forme : un arbre binaire, chacun au milieu de ses deux parents.
  if (racine !== null && leFiltre().noeuds.some((n) => n.id === racine)) {
    disposition.value = mesure('dont placement', () =>
      disposerEnPedigree(auFormat(seulementLaLigne(leFiltre(), racine), formatDesCartes.value), racine, graphe.ego),
    )
    return
  }
  // Déjà calculée sur ce graphe, avec ces bascules (ce F5-ci ou un précédent) : on la reprend.
  const garde = grapheGarde
  const cle = garde ? cleDeLaDisposition() : null
  const deja = garde && cle !== null ? memoireDeLArbre.dispositionGardee(garde.identite, garde.etag, cle) : null
  if (deja && 'groupes' in deja) {
    disposition.value = mesure('dont placement (reprise de la mémoire)', () => deja as DispositionGroupes)
    return
  }
  // Les rectangles de familles (`arbre2/groupes.ts`).
  const calculee = mesure('dont placement', () =>
    props.disposerLesGroupes(auFormat(leFiltre(), formatDesCartes.value), {
      alliances: alliancesDansLArbre.value ? 'dans-l-arbre' : 'a-part',
      fratriesParNaissance: fratriesParNaissance.value,
    }),
  )
  disposition.value = calculee
  if (garde && cle !== null) void memoireDeLArbre.garderLaDisposition(garde.identite, garde.etag, cle, calculee)
}
/** Après une bascule : la disposition refaite, et ce qui n'existe plus oublié. */
function redisposer(): void {
  if (!grapheCharge.value) return
  disposer(grapheCharge.value)
  const d = disposition.value!
  if (selection.value !== null && !d.parId.has(selection.value)) selection.value = null
  if (!familleEclairee(d.traces.familles, familleChoisie.value)) familleChoisie.value = null
  filtres.masquees = filtres.masquees.filter((b) => quartsMasquables.value.has(b))
  cadrage.definir(ajusterLargeur(canevas.value, gouttiere.value))
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
 * Refaire la disposition — les coordonnées du monde changent toutes — sans perdre le
 * lecteur : on garde à l'écran la personne qu'il regardait, la sélection s'il y en a
 * une, sinon la fiche la plus proche du centre ; au même zoom, à la même place.
 *
 * `refaire` refait la disposition (`redisposer()`, qui recadre l'arbre ajusté).
 * Sans repère — pas d'arbre affiché, ou le niveau Ensemble sans sélection —,
 * `sansRepere` décide : `ajuster` garde l'arbre ajusté, comme après une bascule ;
 * `garder` remet le cadrage d'avant, pour un arbre qui ne change que d'une fiche.
 */
function garderLeRepere(refaire: () => void, sansRepere: 'ajuster' | 'garder' = 'ajuster'): void {
  const d = disposition.value
  if (!d || !grapheCharge.value) return
  const avant = cadrage.lire()
  let repere: { id: string; px: number; py: number } | null = null
  const choisie = selection.value !== null ? d.parId.get(selection.value) : undefined
  const ancre = choisie ?? (niveau.value > 0 ? ficheLaPlusProche(d, centreMonde()) : undefined)
  if (ancre) repere = { id: ancre.id, px: versEcranX(ancre.cx), py: versEcranY(ancre.y + ancre.h / 2) }
  cadrage.arreterVol()
  refaire()
  const f = repere ? disposition.value?.parId.get(repere.id) : undefined
  if (!f || !repere) {
    if (sansRepere === 'garder') cadrage.definir(avant)
    return
  }
  const c = canevas.value
  // Le même point d'écran, sauf s'il était sorti de la fenêtre : alors au centre.
  const dedans = repere.px > 0 && repere.px < taille.value.l && repere.py > 0 && repere.py < taille.value.h
  const [px, py] = dedans ? [repere.px, repere.py] : [taille.value.l / 2, taille.value.h / 2]
  cadrage.definir(centrerSur(bornerEchelle(avant.s, c), c, f.cx, f.y + f.h / 2, px, py))
}
/* Changer de format change la taille des boîtes : la disposition se refait autour du repère. */
watch(formatDesCartes, () => garderLeRepere(redisposer))
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
/** Même chose pour les personnes sans lien connu. */
function basculerSansLien(avec: boolean): void {
  if (avecSansLien.value === avec) return
  avecSansLien.value = avec
  ecrirePreference(CLE_SANS_LIEN, avec)
  redisposer()
}
/**
 * Et pour le calque « en attente » : une proposition qu'on vient de déposer, ou qu'on
 * demande à voir depuis le volet, l'allume s'il est masqué.
 */
function basculerEnAttente(avec: boolean): void {
  if (avecEnAttente.value === avec) return
  avecEnAttente.value = avec
  ecrirePreference(CLE_EN_ATTENTE, avec)
  redisposer()
}
/**
 * Une personne qu'on cherche alors que sa famille par alliance — ou la zone des
 * personnes sans lien connu — est masquée : la recherche la trouve (l'API ne sait rien
 * de l'affichage) ; la choisir allume la bascule, et la disposition refaite la contient.
 */
function afficherSonAlliance(personId: number): void {
  const g = grapheVisible.value
  if (!g || disposition.value?.parPersonId.has(personId)) return
  // Hors de la ligne directe affichée seule : l'arbre entier revient, puis on la cherche.
  if (cleLigneSeule.value !== null) {
    filtres.ligneDirecte = false
    racineFigee.value = null
    assurerLaDisposition()
    if (disposition.value?.parPersonId.has(personId)) return
  }
  if (!avecAlliances.value && estParAlliance(g, personId)) basculerAlliances(true)
  else if (!avecSansLien.value && estSansLien(g, personId)) basculerSansLien(true)
}
const chargement = ref(true)
const erreur = ref<string | null>(null)

/*
 * L'APPARITION, au premier affichage : un seul fondu de l'arbre entier — les fiches,
 * puis les traits un peu après. Une seule fois par chargement (pas à chaque filtre), et
 * jamais si le système demande moins d'animations.
 *
 * Jusqu'au 2026-10-07, elle était échelonnée fiche par fiche depuis la vôtre : une animation
 * CSS par fiche — 5 294 sur l'arbre servi —, et les styles des fiches calculés deux
 * fois, avec puis sans leur retard. La première image peinte prenait ~650 ms. Depuis le
 * 2026-10-14, un fondu par CALQUE et rien d'autre : celui des fiches et celui des textes
 * (`.calque-apparait`, sur leur seul conteneur), les traits par `stroke-opacity`
 * (`TracesGroupes`) — plus un fondu par nom ou photo écrit à l'écran, qui faisait de
 * chacun une couche de composition le temps de l'animation (ADR 0007, révision du
 * 2026-10-14).
 */
const DUREE_REVELATION = 600
const revelation = shallowRef(false)
let finRevelation = 0
/*
 * Les zones de clic des familles (un trait transparent par famille, des milliers) ne
 * se voient pas : elles attendent que la première image soit peinte, pour ne pas la
 * retarder. Un clic sur un trait dans cette image-là ne sélectionne pas sa famille.
 */
const zonesDeClic = shallowRef(false)
function lancerRevelation(): void {
  if (!disposition.value) return
  try {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
  } catch {
    /* environnement sans matchMedia : on anime */
  }
  revelation.value = true
  window.clearTimeout(finRevelation)
  finRevelation = window.setTimeout(() => (revelation.value = false), DUREE_REVELATION * 2)
}

/** Le graphe obtenu, et sous quelle clé il est gardé (`null` : rien ne se garde). */
interface GrapheObtenu {
  graphe: Graphe
  inchange: boolean
  garde: { identite: string; etag: string } | null
}

/**
 * Le graphe, REVALIDÉ contre celui qu'on a gardé d'un F5 à l'autre (`histoire/memoire.ts`).
 *
 * On présente en `If-None-Match` l'`ETag` du graphe gardé pour CE lecteur — celui-là et
 * aucun autre : sur `304`, c'est exactement lui que le serveur déclare à jour, et on le
 * reprend au lieu de le retélécharger. Rien ne s'affiche avant cette réponse. Sans
 * graphe gardé, aucun `If-None-Match` : un `200`, comme avant. Le graphe gardé se relit
 * pendant que la requête part.
 *
 * `signal` abandonne la requête (démontage, autre lecteur) ; c'est à l'appelant de
 * poser `grapheGarde`, s'il est toujours le dernier à avoir demandé.
 */
async function obtenirLeGraphe(c: Chronometre, signal: AbortSignal): Promise<GrapheObtenu> {
  const identite = identiteDuLecteur(session.value)
  const etag = identite ? await c.mesureAsync('mémoire locale : ETag', () => memoireDeLArbre.etagGarde(identite)) : null
  if (etag !== null) retenirEtag(CLE_ETAG_GRAPHE, etag)
  else oublierEtag(CLE_ETAG_GRAPHE)
  const enReserve = identite && etag !== null ? memoireDeLArbre.grapheGarde(identite, etag) : Promise.resolve(null)
  let reponse = await c.mesureAsync('requête + lecture JSON', () => chargerGraphe(signal))
  if (reponse.statut === 'inchange') {
    const garde = await c.mesureAsync('mémoire locale : graphe', () => enReserve)
    if (garde && identite && etag !== null) return { graphe: garde, inchange: true, garde: { identite, etag } }
    // Gardé, mais illisible entre-temps : on le redemande en entier, sans `If-None-Match`.
    oublierEtag(CLE_ETAG_GRAPHE)
    reponse = await c.mesureAsync('requête + lecture JSON', () => chargerGraphe(signal))
    if (reponse.statut === 'inchange') throw new Error("L'arbre n'a pas pu être chargé.")
  }
  const { donnees, etag: recu } = reponse
  const garde = identite && recu !== null && !recu.startsWith('W/') ? { identite, etag: recu } : null
  if (garde) void memoireDeLArbre.garderLeGraphe(garde.identite, garde.etag, donnees)
  return { graphe: donnees, inchange: false, garde }
}

/** Le dernier chargement lancé : un chargement abandonné (autre lecteur, démontage) n'écrit rien. */
let jetonChargement = 0

async function charger(): Promise<void> {
  const jeton = ++jetonChargement
  const signal = relecture.signal()
  chargement.value = true
  erreur.value = null
  const apresUnF5 = premierChargement()
  chrono = creerChronometre()
  const c = chrono
  try {
    const obtenu = await obtenirLeGraphe(c, signal)
    if (jeton !== jetonChargement) return
    const { graphe, inchange } = obtenu
    grapheGarde = obtenu.garde
    c.mesure('mise en mémoire', () => (grapheCharge.value = graphe))
    // « Voir dans l'arbre » vers une personne d'une famille par alliance masquée : on l'affiche.
    const demandee = Number(route.query['personne']) || null
    if (demandee !== null && !avecAlliances.value && estParAlliance(graphe, demandee)) {
      avecAlliances.value = true
      ecrirePreference(CLE_ALLIANCES, true)
    }
    // De même vers une personne sans lien connu, zone masquée.
    if (demandee !== null && !avecSansLien.value && estSansLien(graphe, demandee)) {
      avecSansLien.value = true
      ecrirePreference(CLE_SANS_LIEN, true)
    }
    // Graphe inchangé : la disposition de ces bascules est peut-être gardée — on la relit.
    const garde = grapheGarde
    if (inchange && garde && cleLigneSeule.value === null) {
      const cle = cleDeLaDisposition()
      await c.mesureAsync('mémoire locale : disposition', () =>
        memoireDeLArbre.prechargerLaDisposition(garde.identite, garde.etag, cle),
      )
      if (jeton !== jetonChargement) return
    }
    c.mesure('disposition (filtres + placement)', () => disposer(graphe))
    await c.mesureAsync('rendu Vue', () => nextTick())
    if (jeton !== jetonChargement) return
    // La vue quittée au départ vers une fiche est celle de `/arbre` (`histoire/retour.ts`) :
    // cet écran ne la reprend pas, et ne l'écrit pas — l'arbre ajusté, toujours.
    vueAReprendre = null
    // « Voir dans l'arbre » depuis une fiche : `?personne=<id>` l'emporte sur la vue mémorisée.
    personneDemandee = Number(route.query['personne']) || null
    if (personneDemandee !== null) vueAReprendre = null
    mesurer()
    ajuster()
    lancerRevelation()
    await c.mesureAsync('première image', () => apresLaPeinture())
    publier({
      depuisLaNavigation: apresUnF5 ? performance.now() : null,
      etapes: c.etapes(),
      reseau: reseauDe('/arbre/graphe'),
      personnes: graphe.noeuds.filter((n) => n.type === 'personne').length,
      noeuds: graphe.noeuds.length,
    })
  } catch (e) {
    // Abandonné (démontage, autre lecteur) : un chargement plus récent a la main, ou plus personne.
    if (jeton !== jetonChargement) return
    erreur.value = analyserErreur(e).message
  } finally {
    if (jeton === jetonChargement) {
      chrono = null
      chargement.value = false
      // Après la première image (ou l'échec) : les zones de clic des familles.
      zonesDeClic.value = true
    }
  }
}

/** Le dernier rafraîchissement lancé : une réponse plus ancienne n'écrit rien. */
let jetonRafraichir = 0
/** La dernière revalidation au retour sur l'onglet ; un dépôt, plus récent, l'emporte. */
let jetonRevalidation = 0

/** Redessine l'arbre sur un graphe relu, autour du repère du lecteur, sans repartir de zéro. */
function redessiner(obtenu: GrapheObtenu): void {
  grapheGarde = obtenu.garde
  garderLeRepere(() => {
    grapheCharge.value = obtenu.graphe
    redisposer()
  }, 'garder')
}

/**
 * Après une proposition déposée depuis le volet : l'arbre relu et redessiné **sans
 * repartir de zéro** — ni écran de chargement, ni recadrage d'ensemble, ni fondu
 * d'apparition —, puis la personne choisie et rejointe.
 *
 * Le graphe se revalide comme au chargement (`obtenirLeGraphe`, l'`ETag` gardé) et
 * revient en `200` : l'`ETag` du serveur porte une empreinte des demandes en attente
 * du lecteur, bâtie sur leur `updated_at` (`CalqueEnAttenteInterface::
 * empreinteDesDemandes`), qu'une demande de plus change. La disposition se refait
 * autour du repère du lecteur (`garderLeRepere`) ; sans repère, le cadrage reste.
 *
 * `noeudId` est celui que la réponse du dépôt a rendu (`PersonneDeposee`), jamais
 * recollé. Rend ce qu'on a trouvé, `echec` si l'arbre n'a pas pu se relire (le dépôt,
 * lui, est fait : on ne dit pas qu'elle est absente), ou `null` si un rafraîchissement
 * plus récent a pris la main.
 */
async function rafraichir(cible: { noeudId?: string | null; personId?: number | null }): Promise<PlaceDansLArbre | null> {
  const jeton = ++jetonRafraichir
  // Une revalidation en vol écrirait un graphe d'avant le dépôt : celle-ci l'emporte.
  jetonRevalidation++
  let obtenu: GrapheObtenu
  try {
    obtenu = await obtenirLeGraphe(creerChronometre(), relecture.signal())
  } catch {
    return jeton === jetonRafraichir ? 'echec' : null
  }
  if (jeton !== jetonRafraichir) return null
  // Pas d'arbre affiché (chargement en cours, ou échec) : rien à redessiner ici.
  if (!disposition.value || chargement.value) return 'absente'
  garderLeRepere(() => {
    grapheGarde = obtenu.garde
    grapheCharge.value = obtenu.graphe
    redisposer()
  }, 'garder')
  return localiser(cible, obtenu.graphe)
}

/**
 * Au retour sur l'onglet (`arbre2/relecture.ts`) : le graphe revalidé en silence. Sur
 * `304`, rien ne bouge ; sinon l'arbre se redessine autour du repère du lecteur, comme
 * après un dépôt. Une revalidation qui échoue laisse l'arbre tel qu'il est.
 */
async function revaliderEnSilence(): Promise<void> {
  if (chargement.value || !disposition.value) return
  const jeton = ++jetonRevalidation
  let obtenu: GrapheObtenu
  try {
    obtenu = await obtenirLeGraphe(creerChronometre(), relecture.signal())
  } catch {
    return
  }
  if (jeton !== jetonRevalidation || chargement.value || !disposition.value) return
  if (obtenu.graphe === grapheCharge.value) return
  redessiner(obtenu)
}

/**
 * Un AUTRE lecteur (« Quitter la vue empruntée », une incarnation, un autre compte) :
 * le graphe affiché a été filtré pour le précédent. Il quitte l'écran tout de suite —
 * graphe, disposition, sélection, noms des branches —, et celui du nouveau lecteur se
 * charge comme au premier affichage.
 */
function changerDeLecteur(): void {
  jetonRafraichir++
  jetonRevalidation++
  cadrage.arreterVol()
  selection.value = null
  familleChoisie.value = null
  grapheGarde = null
  grapheCharge.value = null
  disposition.value = null
  quartsVus.clear()
  zonesDeClic.value = false
  void charger()
}

/** Choisit et rejoint la personne déposée dans la disposition refaite, ou dit pourquoi on ne la voit pas. */
function localiser(cible: { noeudId?: string | null; personId?: number | null }, graphe: Graphe): PlaceDansLArbre {
  const { noeudId = null, personId = null } = cible
  const f = noeudId !== null ? disposition.value?.parId.get(noeudId) : undefined
  if (f) {
    selection.value = f.id
    // Au zoom où les noms s'écrivent : la fiche en pointillé se lit en arrivant.
    voler(Math.max(echelle(), seuilDesNoms.value), f.cx, f.y + f.h / 2)
    return 'placee'
  }
  if (personId !== null && montrerPersonne(personId)) return 'placee'
  // Dans le graphe mais pas à l'écran : un réglage de l'affichage la retire.
  const dansLeGraphe = graphe.noeuds.some(
    (n) => n.id === noeudId || (personId !== null && n.type === 'personne' && n.personId === personId),
  )
  return dansLeGraphe ? 'masquee' : 'absente'
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

/*
 * Les couches écrites à chaque image, SANS Vue (ADR 0007, règle 3) : le `viewBox` des
 * traits, la transformation des boîtes, celle de la couche des textes, et la place du
 * bandeau, de la gouttière, des titres des rectangles et du cadre de la mini-carte.
 * Jusqu'au 2026-10-14, un `ref` de cadrage écrit à chaque image d'un glisser rendait
 * l'écran entier à nouveau — les milliers de zones de clic, les rectangles, le volet,
 * le feuillet des filtres —, soixante fois par seconde.
 */
const traces = ref<InstanceType<typeof TracesGroupes> | null>(null)
const boites = ref<InstanceType<typeof FichesGroupes> | null>(null)
const calqueEcran = ref<HTMLElement | null>(null)
const titres = ref<InstanceType<typeof TitresDesRectangles> | null>(null)
const bandeau = ref<InstanceType<typeof BandeauFamilles> | null>(null)
const generations = ref<InstanceType<typeof GouttiereGenerations> | null>(null)
const miniCarte = ref<InstanceType<typeof MiniCarte> | null>(null)
/** L'instantané d'après lequel la couche des textes est rendue en ce moment. */
let renduApplique: Cadrage | null = null

function appliquer(c: Cadrage): void {
  const cv = canevas.value
  traces.value?.cadrer(viewBoxDe(c, cv))
  boites.value?.cadrer(transformationMonde(c, cv))
  if (calqueEcran.value) calqueEcran.value.style.transform = transformationRelative(renduApplique ?? c, c, cv)
  titres.value?.placer(c, cv)
  bandeau.value?.placer(c, cv)
  generations.value?.placer(c, cv)
  miniCarte.value?.placer(c, cv)
}

/** Le cadrage, hors de la réactivité (`arbre2/useCadrage.ts`). */
const cadrage = useCadrage({ fenetre, canevas: () => canevas.value, appliquer, seuilDesNoms: () => seuilDesNoms.value })
const niveau = cadrage.niveau
/** L'instantané publié à Vue : les textes, les anneaux, les noms de foyer s'en calculent. */
const rendu = cadrage.rendu
const { surPointeurBas, surPointeurDeplace, surPointeurHaut } = cadrage.gestes
const voler = cadrage.voler

/*
 * Après chaque rendu des couches (un nouvel instantané, une autre disposition, une autre
 * taille) : elles sont à jour, la transformation de la couche des textes repart de zéro
 * et tout se réécrit pour le cadrage courant.
 */
watch(
  [rendu, disposition, canevas, estMobile],
  () => {
    renduApplique = rendu.value
    cadrage.reappliquer()
  },
  { flush: 'post' },
)

/** La jauge de zoom, pour l'administration seulement : un repère chiffré sur
 *  les captures d'écran (« à 30 % les noms se chevauchent »), pas un réglage. */
const { administrateur, session, rattache, vueEmpruntee } = useSession()
const jauge = computed(() => (administrateur.value && disposition.value ? jaugeDuZoom(rendu.value.s, canevas.value, seuilDesNoms.value) : null))
const ensemble = computed(() => niveau.value === 0)

/*
 * Le graphe relu quand le lecteur change, et au retour sur l'onglet ; les requêtes en
 * vol abandonnées au démontage (`arbre2/relecture.ts`).
 */
const relecture = useRelectureDeLArbre({
  identite: () => identiteDuLecteur(session.value),
  nouveauLecteur: changerDeLecteur,
  revalider: () => void revaliderEnSilence(),
})

/** Monde → écran, pour le cadrage courant (celui d'un geste en cours compris). */
const versEcranX = (x: number): number => {
  const c = cadrage.lire()
  return c.tx + x * c.s
}
const versEcranY = (y: number): number => {
  const c = cadrage.lire()
  return c.ty + y * syDe(c.s, canevas.value)
}
/** L'échelle courante. */
const echelle = (): number => cadrage.lire().s

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
  cadrage.definir(ajusterLargeur(canevas.value, gouttiere.value))
  const cible = personneDemandee !== null ? disposition.value.parPersonId.get(personneDemandee) : undefined
  personneDemandee = null
  if (cible) {
    selection.value = cible.id
    voler(1, cible.cx, cible.y + cible.h / 2)
    // La requête a servi : on la retire, pour qu'un rechargement reparte de l'arbre entier.
    // Le routeur republie alors la page et vide le résumé : on le remet.
    void routeur.replace({ path: route.path }).then(() => definirResume(compteur.value))
  }
}

function reprendre(v: VueMemorisee): void {
  filtres.ligneDirecte = v.filtres.ligneDirecte
  racineFigee.value = v.filtres.racineLigne ?? null
  // Une vue mémorisée avant le 05/10/2026 : « Personnes vivantes » est devenu la pastille « Vivants ».
  if (v.filtres.vivants) filtres.qualite = 'vivants'
  // Les quarts masqués et la ligne seule refont la disposition : le point mémorisé est dans CELLE-LÀ.
  filtres.masquees = v.filtres.masquees.filter((b): b is BrancheDeQuart => quartsMasquables.value.has(b as BrancheDeQuart))
  if (filtres.masquees.length) redisposer()
  else assurerLaDisposition()
  const d = disposition.value!
  const c = canevas.value
  cadrage.definir(centrerSur(bornerEchelle(v.s, c), c, v.x, v.y, taille.value.l / 2, taille.value.h / 2))
  selection.value = v.selection !== null && d.parId.has(v.selection) ? v.selection : null
}

/** Au départ vers une fiche : ce qu'il faut pour revenir exactement ici. */
function memoriserLaVue(): void {
  /* Voir `charger()` : la mémoire du retour appartient à `/arbre`. */
}

const centreMonde = (): { x: number; y: number } => cadrage.centreMonde()
const zoomer = (facteur: number): void => cadrage.zoomer(facteur)

/**
 * Le palier Familles (30 %) tombe sous le seuil des noms quand le lecteur l'a abaissé
 * à moins de 30 % : il vise alors le milieu entre le seuil des familles et celui des noms,
 * pour rester un vrai palier Familles.
 */
function echelleDuPalier(i: number, echelle: number): number {
  const seuil = seuilDesNoms.value
  if (i !== 1 || seuil > echelle) return echelle
  return seuil > SEUILS[0] ? (SEUILS[0] + seuil) / 2 : echelle
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
    voler(echelleDuPalier(i, palier.echelle), c.x, c.y)
  }
}

/* ------------------------------------------------------------------ */
/* Sélection                                                           */
/* ------------------------------------------------------------------ */

const selection = ref<string | null>(null)
const ficheChoisie = computed(() => (selection.value ? disposition.value?.parId.get(selection.value) ?? null : null))
/**
 * La fiche entière de la personne choisie, pour le volet (PC) et la parenté du panneau
 * (téléphone) : chaque issue a son état, l'échec compris (`arbre2/lectureFiche.ts`).
 */
const lectureDeLaFiche = useLectureDeLaFiche(() => ficheChoisie.value?.noeud.personId ?? null)
const etatDeLaFiche = lectureDeLaFiche.etat
const ficheLue = computed(() => (etatDeLaFiche.value.etat === 'complete' ? etatDeLaFiche.value.fiche : null))
// La parenté est calculée par le serveur, relativement au lecteur : on ne la recalcule pas.
const parente = computed(() => ficheLue.value?.parente ?? null)

/*
 * DEUX VOLETS (PC) encadrent le canevas, et tous deux le POUSSENT — le canevas
 * rétrécit, rien n'est caché dessous :
 *  - à droite, la FICHE de la personne choisie (le téléphone garde le panneau du bas) ;
 *  - à gauche, « AJOUTER UNE PERSONNE » (`VoletProposition`, dès `lg`), ouvert par le
 *    bouton de la barre.
 * Ils ne tiennent ensemble qu'au-delà de 90 rem : en deçà, ouvrir la proposition masque
 * la fiche — la sélection et son anneau restent, la fiche revient à la fermeture
 * (`voletDroitVisible`, `components/arbre/voletProposition.ts`). Si un volet qui paraît
 * couvre la fiche choisie, l'arbre la ramène dans la partie visible.
 */
/** Proposer est une écriture : un membre rattaché, hors de la bascule « voir comme un membre ». */
const peutProposer = computed(() => rattache.value && vueEmpruntee.value === null)
const estLarge = useCorrespondance(REQUETE_VOLET_PROPOSITION)
const tresLarge = useCorrespondance(REQUETE_DEUX_VOLETS)

/** Monté au premier clic, puis masqué ou affiché : la saisie survit à la fermeture. */
const propositionMontee = ref(false)
const propositionOuverte = ref(false)
/** À l'écran : ouvert, assez large (sous `lg`, il se masque sans rien perdre), et permis. */
const propositionVisible = computed(() => propositionOuverte.value && estLarge.value && peutProposer.value)

const voletDroitOuvert = computed(() =>
  voletDroitVisible({
    estMobile: estMobile.value,
    fiche: ficheChoisie.value,
    propositionOuverte: propositionVisible.value,
    tresLarge: tresLarge.value,
  }),
)

/** Après qu'un volet a paru : le canevas remesuré, et la fiche choisie ramenée si elle est couverte. */
async function rejoindreLaChoisieCouverte(): Promise<void> {
  await nextTick()
  mesurer()
  const f = ficheChoisie.value
  if (!f) return
  const x = versEcranX(f.cx)
  if (x < 40 || x > taille.value.l - 40) voler(echelle(), f.cx, f.y + f.h / 2)
}
watch(voletDroitOuvert, (ouvert) => {
  if (ouvert) void rejoindreLaChoisieCouverte()
})

/*
 * Le volet de gauche se place AVANT le canevas : en paraissant, il fait glisser
 * l'origine du canevas de 440 px vers la droite, et l'arbre avec elle. Le cadrage
 * recule d'autant DANS LE MÊME TIC — l'observateur `pre` passe avant le rendu qui
 * montre le volet —, et l'arbre ne bouge pas à l'écran ; l'inverse quand il
 * s'efface, y compris quand la fenêtre repasse sous `lg`. Un vol en cours s'arrête :
 * sa trajectoire visait l'ancien canevas. L'observateur de taille remesure ensuite.
 */
watch(propositionVisible, (visible) => {
  const pret = disposition.value !== null && taille.value.l > 0
  // Écrit dans le DOM tout de suite, et appliqué aussi au glisser dont l'image attend encore.
  cadrage.decaler((c) => cadrageApresVolet(c, visible, pret))
  if (visible) void rejoindreLaChoisieCouverte()
})

function basculerProposition(): void {
  if (propositionVisible.value) {
    propositionOuverte.value = false
    return
  }
  propositionMontee.value = true
  propositionOuverte.value = true
}

/*
 * « COMPLÉTER » UNE PROPOSITION EN ATTENTE (ADR 0017). Sur un nœud en pointillé, le
 * volet droit offre « Compléter » à qui peut la compléter sur place : son auteur
 * (`demandesEnAttente` de la session), ou un administrateur. Le nœud porte déjà
 * de quoi la désigner — `demandeId` et `brouillonRef`, envoyés séparément, jamais
 * découpés dans l'identifiant `#12/p1`. Le volet gauche s'ouvre alors en mode
 * complétion ; le serveur reste juge (`403`, `409`), et le formulaire dit pourquoi.
 */
const demandeDeCompletion = shallowRef<DemandeDeCompletion | null>(null)
let jetonDeCompletion = 0

function peutCompleter(noeud: NoeudPersonne): boolean {
  if (!peutProposer.value || !estLarge.value) return false
  if (noeud.personId !== null || noeud.demandeId === undefined || noeud.brouillonRef === undefined) return false
  return administrateur.value || (session.value?.demandesEnAttente ?? []).includes(noeud.demandeId)
}

function completerLeNoeud(noeud: NoeudPersonne): void {
  if (noeud.demandeId === undefined || noeud.brouillonRef === undefined) return
  demandeDeCompletion.value = { demandeId: noeud.demandeId, ref: noeud.brouillonRef, jeton: ++jetonDeCompletion }
  propositionMontee.value = true
  propositionOuverte.value = true
}

/*
 * Le volet est chargé à la demande : son formulaire (liens, dates, photos…)
 * n'alourdit pas l'arbre de qui ne s'en sert pas. Pendant le chargement — ou s'il
 * échoue —, une coquille de même largeur tient sa place : sans elle, l'arbre déjà
 * reculé de 440 px sauterait le temps que le formulaire arrive.
 */
const coquilleDuVolet = (texte: string) =>
  defineComponent({
    setup: () => () =>
      h(
        'aside',
        {
          id: 'volet-proposition',
          'data-volet-proposition': '',
          'aria-label': 'Ajouter une personne',
          class: 'flex w-[440px] flex-none flex-col border-r border-ligne bg-panneau',
        },
        [h('p', { class: 'm-0 px-4 py-3.5 text-sm text-attenue', role: 'status' }, texte)],
      ),
  })
const VoletProposition = defineAsyncComponent({
  loader: () => import('@/components/arbre/VoletProposition.vue'),
  loadingComponent: coquilleDuVolet('Ouverture du formulaire…'),
  errorComponent: coquilleDuVolet('Le formulaire n’a pas pu se charger. Rechargez la page pour réessayer.'),
  delay: 0,
  // Un réseau qui hoquette : deux nouvelles tentatives avant d'abandonner.
  onError: (_erreur, reessayer, abandonner, tentatives) => (tentatives <= 2 ? reessayer() : abandonner()),
})

/** Ce que l'arbre redessiné a trouvé de la dernière personne déposée. */
const placeDuDepot = ref<PlaceDansLArbre>('recherche')

/**
 * Une personne vient d'être proposée dans le volet : l'arbre se relit, et la montre.
 *
 * Une fiche **complétée** (« C'est elle », `nature: 'fiche'`) suit le même chemin :
 * elle n'a pas de nœud à elle (`noeudId` nul), mais son `personId` — et ses
 * demandes en attente changent le calque, donc l'`ETag` du graphe.
 */
async function surDepot(depot: PersonneDeposee): Promise<void> {
  // Un brouillon n'est pas dans le calque : rien à relire, rien à montrer.
  if (!depot.soumise) {
    placeDuDepot.value = 'absente'
    return
  }
  placeDuDepot.value = 'recherche'
  // Le calque masqué : la personne proposée à l'instant ne s'y verrait pas.
  if (!avecEnAttente.value) garderLeRepere(() => basculerEnAttente(true), 'garder')
  const place = await rafraichir({ noeudId: depot.noeudId, personId: depot.personId })
  if (place !== null) placeDuDepot.value = place
}

/**
 * Montre une personne dans l'arbre : sa famille par alliance ou sa zone « sans lien »
 * s'allume s'il le faut, on la choisit, et l'écran la rejoint. **Ne quitte jamais
 * `/arbre`** : le volet « Ajouter une personne » y garde une saisie, que la page de la
 * fiche perdrait. Rend `false` quand la personne n'est pas dessinée — à l'appelant de
 * le dire.
 */
function montrerPersonne(personId: number): boolean {
  afficherSonAlliance(personId)
  const f = disposition.value?.parPersonId.get(personId)
  if (!f) return false
  selection.value = f.id
  voler(Math.max(echelle(), 0.6), f.cx, f.y + f.h / 2)
  return true
}

/**
 * Montre un nœud — une proposition en attente — dans l'arbre, comme
 * `montrerPersonne` : on le choisit et l'écran le rejoint, sans jamais quitter
 * `/arbre`. Le `noeudId` est celui que le serveur a rendu ; la projection pouvant
 * écarter un nœud qu'elle ne sait pas placer, ou un filtre le masquer, il n'est
 * peut-être pas dessiné : `false`, et c'est au volet de le dire.
 */
function montrerNoeud(noeudId: string): boolean {
  // Une proposition, le calque « en attente » masqué : il revient.
  if (!avecEnAttente.value && grapheCharge.value?.noeuds.some((n) => n.id === noeudId && n.enAttente === true))
    garderLeRepere(() => basculerEnAttente(true), 'garder')
  const f = disposition.value?.parId.get(noeudId)
  if (!f) return false
  selection.value = f.id
  voler(Math.max(echelle(), 0.6), f.cx, f.y + f.h / 2)
  return true
}

/**
 * Les ressemblances que le formulaire du volet affiche (« Est-ce déjà… ? ») :
 * l'arbre les cercle en pointillé, pour qu'on les trouve en le parcourant.
 */
const ressemblancesEnEvidence = shallowRef<EnEvidence>({ personIds: [], noeudIds: [] })

function mettreEnEvidence(cibles: EnEvidence): void {
  ressemblancesEnEvidence.value = cibles
}

/** « Voir dans l'arbre », depuis une ressemblance du volet : l'arbre répond s'il a pu. */
function voirLaPersonneDuVolet(personId: number, repondre: (dessinee: boolean) => void): void {
  repondre(montrerPersonne(personId))
}

function voirLaPropositionDuVolet(noeudId: string, repondre: (dessinee: boolean) => void): void {
  repondre(montrerNoeud(noeudId))
}

/** Un proche cliqué dans le volet de la fiche : montré dans l'arbre, sinon sa page. */
function choisirProche(personId: number): void {
  if (!montrerPersonne(personId)) void routeur.push({ name: 'personne.detail', params: { id: personId } })
}

function voirFiche(f: FicheHistoire): void {
  voler(Math.max(echelle(), 1), f.cx, f.y + f.h / 2)
}

function choisir(f: FicheHistoire): void {
  if (cadrage.vientDeGlisser()) return
  selection.value = f.id
  if (echelle() < seuilDesNoms.value) voler(Math.max(1, seuilDesNoms.value), f.cx, f.y + f.h / 2)
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
  // La ligne seule d'une autre personne, où vous n'êtes pas : on rend l'arbre entier.
  const ego = egoDuLecteur.value
  if (ego !== null && cleLigneSeule.value !== null && !disposition.value?.parId.has(ego)) {
    filtres.ligneDirecte = false
    racineFigee.value = null
    assurerLaDisposition()
  }
  const d = disposition.value
  const moi = ego !== null ? d?.parId.get(ego) : undefined
  if (!moi) return
  selection.value = moi.id
  voler(1, moi.cx, moi.y + moi.h / 2)
}

/* ------------------------------------------------------------------ */
/* Filtres : les branches retirent, les autres estompent               */
/* ------------------------------------------------------------------ */

const filtres = reactive({
  masquees: [] as BrancheDeQuart[],
  ligneDirecte: false,
  /**
   * Le filtre documentaire de la barre (Vérifiés, Sans source, Vivants…) : un seul à
   * la fois. « Vivants » y est ; la case « Personnes vivantes » qui le doublait plus
   * bas dans les filtres a été retirée (05/10/2026).
   */
  qualite: null as IdFiltre | null,
})
/** Le nombre de filtres actifs, pour « Filtres · n » et le bouton de l'en-tête. */
const nombreFiltresActifs = computed(
  () => filtres.masquees.length + (filtres.ligneDirecte ? 1 : 0) + (filtres.qualite ? 1 : 0),
)

function fermerFiltres(): void {
  fermerTiroirs()
}
function toutAfficher(): void {
  const avaitDesQuarts = filtres.masquees.length > 0
  filtres.masquees = []
  filtres.ligneDirecte = false
  racineFigee.value = null
  filtres.qualite = null
  if (avaitDesQuarts) redisposer()
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
/*
 * Masquer une branche la RETIRE (décision du 2026-10-07, `histoire/branches.ts`) :
 * ses fiches quittent l'écran et l'arbre se refait sans elle. Le lecteur, ses parents,
 * sa fratrie et ses descendants — le tronc — restent toujours.
 */
function basculerBranche(b: BrancheDeQuart): void {
  filtres.masquees = filtres.masquees.includes(b) ? filtres.masquees.filter((x) => x !== b) : [...filtres.masquees, b]
  redisposer()
}
/** Le dernier nom connu de chaque quart : un quart masqué n'est plus dans la disposition. */
const quartsVus = new Map<BrancheDeQuart, Quart>()
/** Les boutons « Branches » : les quarts affichés, et ceux qu'on a masqués, à leur place. */
const boutonsDeBranche = computed<{ branche: BrancheDeQuart; nom: string; libelle: string }[]>(() => {
  for (const q of disposition.value?.quarts ?? []) quartsVus.set(q.branche, q)
  const ordre: BrancheDeQuart[] = ['pp', 'pm', 'mp', 'mm', 'cpp', 'cpm', 'cmp', 'cmm']
  return ordre
    .filter((b) => quartsMasquables.value.has(b))
    .map((b) => ({ branche: b, nom: quartsVus.get(b)?.nom ?? '', libelle: LIBELLES_QUART[b] }))
})

/*
 * LE SEUIL DES NOMS : le zoom à partir duquel noms, prénoms et dates s'écrivent. Une
 * jauge des filtres, en pour cent, de 10 à 90 (45 par défaut). Sur un écran 2K, 4K ou une
 * télévision, les fiches restent grandes bien en deçà de 45 % : on l'abaisse pour lire
 * les noms de plus loin. Ce n'est pas un filtre : ni dans « Filtres · n », ni dans
 * « Réinitialiser » — une préférence de l'appareil, comme le format des fiches.
 */
const SEUIL_MIN = Math.round(SEUIL_DES_NOMS.min * 100)
const SEUIL_MAX = Math.round(SEUIL_DES_NOMS.max * 100)
const SEUIL_DEFAUT = Math.round(SEUIL_DES_NOMS.defaut * 100)
const seuilEnPourcent = computed({
  get: () => Math.round(seuilDesNoms.value * 100),
  set: (p: number) => (seuilDesNoms.value = bornerSeuilDesNoms(p / 100)),
})
/** Le zoom affiché en ce moment, en pour cent : le repère sous la jauge (d'après l'instantané). */
const zoomEnPourcent = computed(() => Math.round(rendu.value.s * 100))
/** La place du zoom actuel sur la jauge (0 à 1), `null` hors de ses bornes. */
const repereDuZoom = computed(() => {
  const p = (zoomEnPourcent.value - SEUIL_MIN) / (SEUIL_MAX - SEUIL_MIN)
  return p >= 0 && p <= 1 ? p : null
})
/** « Les noms dès maintenant » : le seuil pris au zoom affiché. */
function seuilAuZoomActuel(): void {
  seuilDesNoms.value = bornerSeuilDesNoms(Math.floor(echelle() * 20) / 20)
}

// `/arbre-review` pose chaque famille par alliance auprès de qui la relie ; « à part » n'y
// change que la teinte (le gris des fiches hors branche).
const OPTIONS_ALLIANCES = [
  { dans: false, libelle: 'À part', sous: props.moteur === 'revue' ? 'en gris, auprès de qui les relie' : 'chacune son bloc, à droite' },
  { dans: true, libelle: 'Dans mon arbre', sous: 'dans la branche qui les relie' },
] as const

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

/**
 * Les fiches qui passent les filtres (ligne directe, filtre documentaire), une fois par
 * changement de filtre ou de disposition — jamais par fiche et par image : les textes
 * visibles, les styles des boîtes et le compteur le lisent.
 */
const passent = computed<ReadonlySet<string>>(() => {
  const d = disposition.value
  if (!d) return new Set()
  const direct = filtres.ligneDirecte
  const def = filtres.qualite ? FILTRES.find((x) => x.id === filtres.qualite) : undefined
  const ids = new Set<string>()
  for (const f of d.fiches) {
    if (direct && enLigne(f) === null) continue
    if (def && !def.test(f.noeud as NoeudPersonneEtendu)) continue
    ids.add(f.id)
  }
  return ids
})

/*
 * LA LIGNE DIRECTE suit la personne choisie : ses ancêtres et ses descendants (les
 * vôtres quand personne n'est choisi). Une seule bascule, partagée par les Filtres, le
 * volet et le panneau du téléphone — elle reste cochée d'une personne à l'autre.
 *
 * DEUX MODES (05/10/2026) : « Estomper les autres », la vue d'avant — tout l'arbre
 * reste en place, les autres fiches pâlissent ; et « Seulement elle » — les autres
 * QUITTENT l'écran et la disposition se refait sur la ligne, resserrée. Dans ce
 * second mode, la racine est FIGÉE au moment où on l'allume : choisir une fiche pour
 * la lire ne refait pas tout l'arbre autour d'elle ; « Suivre sa ligne directe », dans
 * son panneau, l'y déplace.
 */
const racineFigee = ref<string | null>(null)
/** L'ego du lecteur, tel que le serveur l'a donné — pas celui d'une disposition réduite. */
const egoDuLecteur = computed(() => grapheCharge.value?.ego ?? disposition.value?.ego ?? null)
const racineDeLaLigne = computed<string | null>(() => {
  if (!filtres.ligneDirecte) return null
  if (ligneSeule.value) return racineFigee.value ?? egoDuLecteur.value
  return ficheChoisie.value?.id ?? egoDuLecteur.value
})
/** La racine de la ligne quand elle est SEULE à l'écran ; `null` sinon. C'est la clé de la disposition. */
const cleLigneSeule = computed<string | null>(() => (ligneSeule.value ? racineDeLaLigne.value : null))
/** La clé avec laquelle la disposition affichée a été faite. */
let cleDisposee: string | null = null
/** La disposition refaite si la ligne seule a changé depuis la dernière (sans attendre l'observateur). */
function assurerLaDisposition(): void {
  if (cleLigneSeule.value !== cleDisposee) redisposerSurLaLigne()
}
/**
 * Refait la disposition quand la ligne seule s'allume, s'éteint ou change de racine.
 * Allumée : l'arbre réduit est ajusté à l'écran. Éteinte : l'arbre entier revient,
 * centré sur la personne dont on suivait la ligne, au même zoom.
 */
function redisposerSurLaLigne(): void {
  const avant = cleDisposee
  const echelleAvant = echelle()
  redisposer()
  const d = disposition.value
  if (cleDisposee !== null || avant === null || !d) return
  const f = d.parId.get(avant)
  if (!f) return
  const c = canevas.value
  cadrage.definir(centrerSur(bornerEchelle(echelleAvant, c), c, f.cx, f.y + f.h / 2, taille.value.l / 2, taille.value.h / 2))
}
watch(cleLigneSeule, (cle) => {
  if (cle !== cleDisposee) redisposerSurLaLigne()
})
watch(ligneSeule, (seule) => ecrirePreference(CLE_LIGNE_SEULE, seule))

/** Allume ou éteint la ligne directe ; allumée, elle part de la personne choisie (ou de vous). */
function basculerLigneDirecte(): void {
  filtres.ligneDirecte = !filtres.ligneDirecte
  racineFigee.value = filtres.ligneDirecte ? (ficheChoisie.value?.id ?? null) : null
}
function choisirModeLigne(seule: boolean): void {
  // On fige la ligne qu'on regarde : celle de la personne choisie, sinon la vôtre.
  if (seule && !ligneSeule.value) racineFigee.value = ficheChoisie.value?.id ?? null
  ligneSeule.value = seule
}
/**
 * La case « Afficher sa ligne directe » du panneau d'une personne. Cochée si c'est SA
 * ligne qu'on voit ; en mode « Seulement elle », la cocher pour une autre personne y
 * déplace la ligne au lieu de l'éteindre.
 */
const ligneDeLaChoisie = computed(
  () => filtres.ligneDirecte && (!ligneSeule.value || racineDeLaLigne.value === ficheChoisie.value?.id),
)
function basculerLigneDeLaChoisie(): void {
  if (filtres.ligneDirecte && ligneSeule.value && !ligneDeLaChoisie.value) {
    racineFigee.value = ficheChoisie.value?.id ?? null
    return
  }
  basculerLigneDirecte()
}
const OPTIONS_MODE_LIGNE = [
  { valeur: 'estompee', libelle: 'Estomper les autres' },
  { valeur: 'seule', libelle: 'Seulement elle' },
] as const

const ligne = computed<{ ascendants: Set<string>; descendants: Set<string> } | null>(() => {
  const racine = racineDeLaLigne.value
  const g = grapheVisible.value
  if (racine === null || !g) return null
  return ligneDe(g, racine)
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
  const racine = filtres.ligneDirecte ? racineDeLaLigne.value : (ficheChoisie.value?.id ?? null)
  const f = racine !== null ? disposition.value?.parId.get(racine) : undefined
  if (!f || f.id === egoDuLecteur.value) return 'Ma ligne directe'
  const nom = f.noeud.visibilite === 'restreinte' ? 'cette personne' : f.noeud.displayName
  return `Ligne directe de ${nom}`
})

/*
 * L'état de la recherche, lu par le rendu : les cibles sont mises en évidence.
 * Deux sources, selon la largeur : le champ du bas sur téléphone (`rechercheMobile`,
 * qui interroge l'API), l'en-tête sur grand écran (ce qu'il publie, voir plus bas).
 */
const curseur = ref(0)
const listeOuverte = ref(false)
const rechercheMobile = useRecherchePersonnes({
  // Jusqu'au plafond de l'API : toutes les personnes trouvées sont mises en évidence.
  limite: () => LIMITE_MAXIMALE,
  delai: 120,
  surResultats(texte, liste) {
    if (texte.length < LONGUEUR_MINIMALE) return
    curseur.value = 0
    listeOuverte.value = true
    if (liste.length) cadrerCibles()
  },
})
const texteEntete = ref('')
const resultatsEntete = shallowRef<ResultatDeRecherche[]>([])
const recherche = computed(() => (estMobile.value ? rechercheMobile.texte.value : texteEntete.value))
const resultats = computed(() => (estMobile.value ? rechercheMobile.resultats.value : resultatsEntete.value))
const cibles = computed<FicheHistoire[]>(() => {
  const d = disposition.value
  if (!d || recherche.value.trim().length < LONGUEUR_MINIMALE) return []
  return resultats.value.map((r) => d.parPersonId.get(r.personId)).filter((f): f is FicheHistoire => f !== undefined)
})
const idsCibles = computed(() => new Set(cibles.value.map((f) => f.id)))

const effectifAffiche = computed(() => passent.value.size)

/* ------------------------------------------------------------------ */
/* Rendu : boîtes (monde), textes (écran)                               */
/* ------------------------------------------------------------------ */

const LIBELLES_BRANCHE: Record<string, string> = { ...LIBELLES_QUART, tr: 'Tronc' }
const libelleBranche = (b: string): string => LIBELLES_BRANCHE[b] ?? 'Hors branche'

/**
 * Les styles des boîtes (`arbre2/apparence.ts`), un `cssText` par fiche. Ils ne
 * dépendent que du niveau Ensemble, des filtres et de la recherche : déplacer la vue ne
 * les recalcule pas. Une fiche estompée l'est dans la couleur de sa peinture, jamais
 * par `opacity`.
 */
const stylesFiches = computed<string[]>(() => {
  const d = disposition.value
  if (!d) return []
  const loin = ensemble.value
  const direct = surligner.value
  const ciblees = idsCibles.value
  const portrait = enPortrait.value
  const ids = passent.value
  return d.fiches.map((f) =>
    styleDeFiche(f, {
      ensemble: loin,
      portrait,
      alpha: alphaDeFiche(ids.has(f.id), ciblees, f.id),
      place: direct ? enLigne(f) : null,
      branche: brancheDe(f),
    }),
  )
})

/**
 * Les RECTANGLES des familles (`arbre2/groupes.ts`) : un fond léger de la couleur de la
 * branche, sous les traits. Rien en ligne directe seule (pedigree) : il n'y a pas de groupes.
 */
const rectangles = computed<RectangleDeGroupe[]>(() => {
  const d = disposition.value
  // Masqués (réglage « Rectangles des familles ») : ni fond ni titre, les fiches ne bougent pas.
  return avecRectangles.value && d && 'groupes' in d ? d.groupes : []
})
const couleurDuGroupe = (cle: string): string =>
  cle === 'alliances' ? 'var(--h-attenue)' : cle === 'sansLien' ? 'var(--h-branche-hors)' : couleurBranche(cle)
/** Le titre de chaque rectangle, en coordonnées monde : `TitresDesRectangles` le place à chaque image. */
const titresDesRectangles = computed<TitreDeRectangle[]>(() =>
  // La clé d'un titre est unique même si un moteur rendait deux rectangles pour un groupe ;
  // la couleur, elle, reste celle du groupe.
  rectangles.value.map((r, i) => ({
    cle: `${r.cle}:${i}`,
    x: r.x,
    y: r.y,
    w: r.w,
    h: r.h,
    couleur: couleurDuGroupe(r.cle),
    texte: r.nom !== '' ? `${r.libelle} · ${r.nom}` : r.libelle,
    effectif: r.effectif,
  })),
)

/** Les textes déjà ajustés à l'échelle courante : un glisser n'en réajuste aucun. */
const memoireDesTextes = creerMemoireDesTextes()

/** Le texte des fiches visibles, en coordonnées écran, pour l'instantané du cadrage. */
const libelles = computed(() => {
  const d = disposition.value
  if (!d) return []
  const direct = surligner.value
  const ciblees = idsCibles.value
  return libellesDesFiches(
    {
      fiches: d.fiches,
      passent: passent.value,
      cadrage: rendu.value,
      canevas: canevas.value,
      niveau: niveau.value,
      portrait: enPortrait.value,
      directe: (f) => direct && enLigne(f) !== null,
      estompe: (f) => ciblees.size > 0 && !ciblees.has(f.id),
    },
    memoireDesTextes,
  )
})

/** Les noms de foyer du niveau Familles, qui ne se recouvrent jamais (`arbre2/libelles.ts`). */
const libellesFoyers = computed(() => {
  const d = disposition.value
  if (!d) return []
  return libellesDesFoyers(d.foyers, rendu.value, canevas.value, niveau.value, surlignerTraits.value)
})

/**
 * Le bandeau des familles, collé en haut à tous les niveaux : chaque branche
 * garde son nom visible tant qu'elle occupe l'écran (`histoire/bandeau.ts`).
 * Il remplace les grands titres de branche posés au-dessus de l'arbre, qui
 * disparaissaient dès qu'on zoomait ou qu'on descendait dans les générations.
 * En coordonnées monde : `BandeauFamilles` se place lui-même, à chaque image.
 */
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
/** Les libellés de la gouttière, en coordonnées monde : `GouttiereGenerations` les place à chaque image. */
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
          ? d.parId.has(egoDuLecteur.value ?? '') ? 'Vous' : 'Racine'
          : `${g.rangee > 0 ? '+' : ''}${g.rangee}`
        : compact && annee
          ? `${g.libelle} · ${annee}`
          : g.libelle,
      // Une rangée trop basse n'a la place que pour une ligne.
      annee: compact ? '' : mobile ? annee : (g.annees ?? ''),
    }
  })
})

/*
 * Les ANNEAUX — la fiche choisie, la famille en surbrillance, les cibles de la recherche,
 * les ressemblances du volet — en coordonnées écran, dans la couche des textes : d'après
 * l'instantané du cadrage, posés jusqu'à la marge au-delà du bord (`MARGE`).
 */
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
      return a ? { ...a, courante: resultats.value[curseur.value]?.personId === f.noeud.personId } : null
    })
    .filter((a): a is Anneau & { courante: boolean } => a !== null),
)

/**
 * Les ressemblances du volet « Ajouter une personne », cerclées **en pointillé** :
 * le trait plein dit « choisie » ou « trouvée », le pointillé « peut-être la même ».
 * Seulement tant que le volet est à l'écran — fermé, la saisie attend, et les
 * cercles n'expliqueraient plus rien.
 */
const anneauxRessemblances = computed(() => {
  const d = disposition.value
  if (!d || !propositionVisible.value) return []
  const v = rendu.value
  const cv = canevas.value
  const { personIds, noeudIds } = ressemblancesEnEvidence.value
  const fiches = [
    ...personIds.map((id) => d.parPersonId.get(id)),
    ...noeudIds.map((id) => d.parId.get(id)),
  ].filter((f): f is FicheHistoire => f !== undefined && dansLaFenetre(v, cv, f.x, f.y, f.w, f.h, MARGE))
  return [...new Map(fiches.map((f) => [f.id, f])).values()].map((f) => anneauDe(f, v, cv, 5)!)
})

/** Un clic sur la mini-carte (bureau) : la vue y vole, au même étage. */
function centrerDepuisMiniCarte(x: number): void {
  voler(Math.max(echelle(), 0.3), x, centreMonde().y)
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

const champ = ref<InstanceType<typeof ChampRechercheArbre> | null>(null)

/*
 * Sur téléphone, à chaque frappe : l'API rend jusqu'à 50 personnes (son plafond),
 * toutes sont mises en évidence sur le canevas et la vue se cadre sur elles ; la
 * liste reste affichée pour choisir (`rechercheMobile`, plus haut).
 *
 * Sur grand écran, le champ est dans l'en-tête (`RechercheEntete`) : l'arbre
 * s'abonne à ses résultats pour les mettre en évidence et cadrer la vue, suit
 * son curseur, et reçoit le choix final (voler vers la personne).
 */
const globale = useRechercheGlobale()
watch(
  () => globale.resultats.value,
  (liste) => {
    if (estMobile.value) return
    texteEntete.value = globale.requete.value
    resultatsEntete.value = [...liste]
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
    voler(Math.max(echelle(), 0.9), f.cx, f.y + f.h / 2)
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
  if (f) voler(Math.max(echelle(), 0.9), f.cx, f.y + f.h / 2)
  document.getElementById(`resultat-histoire-${curseur.value}`)?.scrollIntoView({ block: 'nearest' })
}

function allerA(r: ResultatDeRecherche): void {
  // Une personne d'une famille par alliance masquée : la choisir allume la bascule.
  afficherSonAlliance(r.personId)
  const f = disposition.value?.parPersonId.get(r.personId)
  rechercheMobile.vider()
  texteEntete.value = ''
  resultatsEntete.value = []
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
    // Entrée sur une panne relance ; ailleurs qu'une liste à jour, elle ne choisit rien.
    if (rechercheMobile.etat.value === 'erreur') {
      rechercheMobile.reessayer()
      return
    }
    if (rechercheMobile.etat.value !== 'resultats') return
    const r = resultats.value[curseur.value]
    if (r) allerA(r)
  } else if (e.key === 'Escape') {
    rechercheMobile.vider()
    listeOuverte.value = false
    champ.value?.blur()
  }
}

/* ------------------------------------------------------------------ */
/* Clavier                                                             */
/* ------------------------------------------------------------------ */

function surToucheGlobale(e: KeyboardEvent): void {
  // Une saisie (champ, liste déroulante) ou le volet « Ajouter une personne » : pas l'arbre.
  if (toucheIgnoree(e.target as HTMLElement | null)) return
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
  desabonner = abonnerChoix(allerA)
  mesurer()
  window.addEventListener('resize', surRedimension)
  window.addEventListener('keydown', surToucheGlobale)
  document.addEventListener('gesturestart', bloquerZoomDePage)
  const el = fenetre.value
  if (el) {
    // (La molette, non passive, est écoutée par `useCadrage`.)
    observateur = new ResizeObserver(() => {
      mesurer()
      if (premierAjustement) ajuster()
    })
    observateur.observe(el)
    // Un bandeau qu'on ferme au-dessus (« Plus tard ») remonte l'arbre sans changer sa
    // taille : c'est `<main>` qui grandit. On le surveille aussi, sinon un vide restait
    // sous la recherche du bas.
    const main = racine.value?.parentElement
    if (main) observateur.observe(main)
  }
  void charger()
})

onBeforeUnmount(() => {
  desabonner?.()
  window.removeEventListener('resize', surRedimension)
  window.removeEventListener('keydown', surToucheGlobale)
  document.removeEventListener('gesturestart', bloquerZoomDePage)
  observateur?.disconnect()
  // Les requêtes en vol sont abandonnées (`useRelectureDeLArbre`) ; leurs réponses n'écrivent plus rien.
  jetonChargement++
  jetonRafraichir++
  jetonRevalidation++
})
</script>

<template>
  <div ref="racine" class="histoire flex flex-col bg-fond text-texte" :style="{ height: hauteurCadre }">
    <!-- La barre d'écran commune : vue, pastilles, compteur, Filtres · n, actions. -->
    <BarreEcran
      ecran="Arbre"
      titre-pastilles="Afficher seulement"
      :filtres-actifs="nombreFiltresActifs"
      :voir="`Voir ${effectifAffiche} personne${effectifAffiche > 1 ? 's' : ''}`"
      @reinitialiser="toutAfficher"
    >
      <template #vue>
        <Segmente :options="OPTIONS_PALIERS" :valeur="niveau" etiquette="Niveau de détail" :colonnes="3" class="max-md:w-full" @choisir="allerAuPalier" />
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
        <!--
          Les filtres en quatre sections, toujours dans le même ordre (refonte du 05/10/2026) :
          ce qu'on SUIT (la ligne directe), ce qu'on RETIRE (les branches), ce qui ENTRE dans
          l'arbre (bascules qui le resserrent), puis l'AFFICHAGE (ni filtre, ni réinitialisé).
          Les pastilles de qualité sont au-dessus, dans la barre (bureau) ou en tête du feuillet.
        -->
        <section class="filtres-section" aria-labelledby="filtres-ligne">
          <h3 id="filtres-ligne" class="filtres-titre">Ligne directe</h3>
          <InterrupteurFiltre
            :libelle="libelleLigne"
            sous="ses ancêtres et ses descendants"
            :actif="filtres.ligneDirecte"
            @basculer="basculerLigneDirecte"
          />
          <Segmente
            v-if="filtres.ligneDirecte"
            :options="OPTIONS_MODE_LIGNE"
            :valeur="ligneSeule ? 'seule' : 'estompee'"
            etiquette="Les autres fiches"
            :colonnes="2"
            class="w-full"
            @choisir="(m) => choisirModeLigne(m === 'seule')"
          />
          <p v-if="filtres.ligneDirecte" class="m-0 text-xs text-attenue">
            {{ ligneSeule ? 'Les autres fiches quittent l’écran : l’arbre se refait sur la ligne.' : 'Les autres fiches restent à leur place, estompées.' }}
          </p>
        </section>

        <section v-if="boutonsDeBranche.length" class="filtres-section" aria-labelledby="filtres-branches">
          <div class="flex items-baseline justify-between gap-2">
            <h3 id="filtres-branches" class="filtres-titre">Branches</h3>
            <span class="text-[11px] text-attenue">touchez pour masquer</span>
          </div>
          <p class="-mt-1 mb-0 text-xs text-attenue">Masquée, la branche quitte l’arbre, qui se refait sans elle.</p>
          <div class="grid grid-cols-2 gap-2">
            <button
              v-for="q in boutonsDeBranche"
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
              <span class="flex min-w-0 flex-col leading-tight">
                <span class="truncate text-[13px] font-semibold">{{ q.nom || '—' }}</span>
                <span class="truncate text-[11px] text-attenue">{{ q.libelle }}</span>
              </span>
            </button>
          </div>
        </section>

        <section
          v-if="aDesDemandesEnAttente || aUneFamilleDeConjoint || aDesAlliances || effectifSansLienConnu > 0"
          class="filtres-section"
          aria-labelledby="filtres-qui"
        >
          <h3 id="filtres-qui" class="filtres-titre">Qui apparaît</h3>
          <p class="-mt-1 mb-0 text-xs text-attenue">Masquées, ces personnes quittent l’arbre, qui se resserre.</p>
          <!-- Le calque des demandes (§ 13 du contrat d'API), servi à leur auteur et aux administrateurs. -->
          <InterrupteurFiltre
            v-if="aDesDemandesEnAttente"
            :libelle="enAttente.personnes > 0 ? `En attente de validation · ${enAttente.personnes}` : 'En attente de validation'"
            sous="les personnes et les liens proposés, en pointillé"
            :actif="avecEnAttente"
            data-filtre-en-attente
            @basculer="basculerEnAttente(!avecEnAttente)"
          />
          <InterrupteurFiltre
            v-if="aUneFamilleDeConjoint"
            libelle="Famille du conjoint"
            sous="ses parents, ses frères et sœurs"
            :actif="avecConjoint"
            @basculer="avecConjoint = !avecConjoint"
          />
          <InterrupteurFiltre
            v-if="aDesAlliances"
            :libelle="`Familles par alliance · ${effectifAlliances}`"
            sous="les familles des conjoints de vos proches"
            :actif="avecAlliances"
            @basculer="basculerAlliances(!avecAlliances)"
          />
          <div v-if="aDesAlliances && avecAlliances" class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Où afficher les familles par alliance">
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
          <InterrupteurFiltre
            v-if="effectifSansLienConnu > 0"
            :libelle="`Sans lien connu · ${effectifSansLienConnu}`"
            sous="aucun lien ne les relie encore à vous"
            :actif="avecSansLien"
            @basculer="basculerSansLien(!avecSansLien)"
          />
        </section>

        <section class="filtres-section" aria-labelledby="filtres-affichage">
          <h3 id="filtres-affichage" class="filtres-titre">Affichage</h3>
          <!-- Pas des filtres : ni dans « Filtres · n », ni dans « Réinitialiser ». -->
          <ReglageFormatDesCartes />
          <InterrupteurFiltre
            libelle="Fratries par ordre de naissance"
            sous="l’aîné à gauche, le plus jeune à droite"
            :actif="fratriesParNaissance"
            @basculer="fratriesParNaissance = !fratriesParNaissance"
          />
          <InterrupteurFiltre
            libelle="Rectangles des familles"
            sous="le cadre et le titre de chaque groupe"
            :actif="avecRectangles"
            @basculer="avecRectangles = !avecRectangles"
          />
          <div class="flex flex-col gap-1.5" data-seuil-des-noms>
            <div class="flex items-baseline justify-between gap-2">
              <label for="seuil-des-noms" class="text-sm">Noms et dates dès</label>
              <span class="font-mono text-sm font-semibold tabular-nums">{{ seuilEnPourcent }} %</span>
            </div>
            <div class="relative">
              <input
                id="seuil-des-noms"
                v-model.number="seuilEnPourcent"
                type="range"
                :min="SEUIL_MIN"
                :max="SEUIL_MAX"
                step="5"
                class="seuil-des-noms h-8 w-full cursor-pointer"
                aria-describedby="seuil-des-noms-aide"
              />
              <!-- Le zoom affiché en ce moment, sous la jauge. -->
              <span
                v-if="repereDuZoom !== null"
                class="pointer-events-none absolute -bottom-1 h-0 w-0 -translate-x-1/2 border-x-[5px] border-b-[6px] border-x-transparent border-b-texte"
                :style="{ left: `calc(8px + ${repereDuZoom} * (100% - 16px))` }"
                aria-hidden="true"
              />
            </div>
            <div class="flex items-center justify-between gap-2 text-xs text-attenue">
              <span id="seuil-des-noms-aide">Zoom actuel : {{ zoomEnPourcent }} % · défaut {{ SEUIL_DEFAUT }} %</span>
              <button
                v-if="zoomEnPourcent >= SEUIL_MIN && zoomEnPourcent < seuilEnPourcent"
                type="button"
                class="min-h-8 font-semibold text-accent"
                @click="seuilAuZoomActuel"
              >
                Dès ce zoom
              </button>
              <button
                v-else-if="seuilEnPourcent !== SEUIL_DEFAUT"
                type="button"
                class="min-h-8 font-semibold text-accent"
                @click="seuilEnPourcent = SEUIL_DEFAUT"
              >
                Par défaut
              </button>
            </div>
            <p class="m-0 text-xs text-attenue">Plus bas, les noms restent écrits de plus loin : utile sur un grand écran ou une télévision.</p>
          </div>
        </section>

        <button
          type="button"
          data-aide-filtres
          class="flex min-h-11 items-center gap-2 self-start border-t border-ligne pt-3 text-left text-sm text-accent hover:underline"
          @click="aideFiltresOuverte = true"
        >
          <span class="flex size-5 flex-none items-center justify-center rounded-full border-[1.5px] border-current text-[11px] font-bold" aria-hidden="true">?</span>
          Voir la vidéo : comment marchent les filtres
        </button>
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
        <!-- Le formulaire « Ajouter une personne » dans un volet à gauche, l'arbre à
             côté. Dès `lg` (1 024 px) : plus étroit, le canevas ne serait plus un arbre. -->
        <button
          v-if="peutProposer"
          type="button"
          data-ajouter-une-personne
          class="inline-flex h-8 items-center gap-1.5 rounded-champ border border-accent bg-panneau px-3 text-[13px] font-semibold whitespace-nowrap text-accent hover:bg-accent-doux aria-expanded:bg-accent-doux max-lg:hidden"
          :aria-expanded="propositionVisible"
          aria-controls="volet-proposition"
          @click="basculerProposition"
        >
          <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="8" cy="6.5" r="3" /><path d="M2.5 17c0-3 2.5-5 5.5-5s5.5 2 5.5 5" /><path d="M16 5v6M13 8h6" />
          </svg>
          Ajouter une personne
        </button>
        <button type="button" class="h-8 rounded-champ bg-accent px-3 text-[13px] font-semibold whitespace-nowrap text-fond" @click="meTrouver">
          Me trouver
        </button>
      </template>
    </BarreEcran>

    <div class="flex min-h-0 flex-1">
    <!-- Le volet « Ajouter une personne » (PC, dès `lg`) : AVANT le canevas, qu'il
         pousse ; monté au premier clic, puis masqué sans être démonté — la saisie
         survit à la fermeture. -->
    <VoletProposition
      v-if="propositionMontee"
      v-show="propositionVisible"
      :ouvert="propositionOuverte"
      :place="placeDuDepot"
      :completer="demandeDeCompletion"
      @fermer="propositionOuverte = false"
      @deposee="surDepot"
      @voir-personne="voirLaPersonneDuVolet"
      @voir-proposition="voirLaPropositionDuVolet"
      @en-evidence="mettreEnEvidence"
    />
    <!-- Le canevas -->
    <div
      ref="fenetre"
      class="grille relative min-w-0 flex-1 cursor-grab touch-none overflow-hidden select-none active:cursor-grabbing"
      @pointerdown="surPointeurBas"
      @pointermove="surPointeurDeplace"
      @pointerup="surPointeurHaut"
      @pointercancel="surPointeurHaut"
      @pointerleave="surPointeurHaut"
      @click="surClicDuCanevas"
    >
      <template v-if="disposition">
        <!--
          Les traits, les rectangles des familles et les zones de clic : un seul `<svg>`,
          construit impérativement et zoomé par son `viewBox` (ADR 0007). Les zones de
          clic laissent passer le pointeur (le glisser du canevas continue de marcher) ;
          seuls leurs traits l'attrapent.
        -->
        <TracesGroupes
          ref="traces"
          :traces="disposition.traces"
          :rectangles="rectangles"
          :couleur="couleurDuGroupe"
          :niveau="niveau"
          :surligner="surligner"
          :surligner-traits="surlignerTraits"
          :eclairage="eclairage?.d ?? null"
          :zones="zonesDeClic"
          :revelation="revelation"
        />

        <!-- Les boîtes, en coordonnées monde : un seul conteneur transformé, sans Vue à chaque image. -->
        <FichesGroupes ref="boites" :fiches="disposition.fiches" :styles="stylesFiches" :revelation="revelation" @choisir="choisir" />

        <!-- Le titre de chaque rectangle de famille. -->
        <TitresDesRectangles ref="titres" :titres="titresDesRectangles" :gouttiere="gouttiere" />

        <!--
          Photos, textes, noms de foyer et anneaux, en coordonnées écran, d'après
          l'instantané du cadrage : entre deux rendus, UNE transformation sur ce conteneur
          les garde collés aux boîtes (`transformationRelative`).
        -->
        <div
          ref="calqueEcran"
          class="pointer-events-none absolute inset-0 origin-top-left"
          :class="revelation ? 'calque-apparait calque-apparait-apres' : ''"
        >
          <CalqueLibelles :libelles="libelles" :foyers="libellesFoyers" :surligner="surligner" :surligner-traits="surlignerTraits" />

          <div
            v-for="a in anneauxCibles"
            :key="`cible-${a.id}`"
            class="pointer-events-none absolute rounded-[9px] border-[var(--hi-libelle-accent)]"
            :class="a.courante ? 'border-2 shadow-[0_0_0_5px_rgba(217,160,102,.25)]' : 'border'"
            :style="{ left: `${a.x}px`, top: `${a.y}px`, width: `${a.w}px`, height: `${a.h}px` }"
          />
          <div
            v-for="a in anneauxRessemblances"
            :key="`ressemblance-${a.id}`"
            class="pointer-events-none absolute rounded-[10px] border-2 border-dashed border-[var(--h-accent)]"
            :style="{ left: `${a.x}px`, top: `${a.y}px`, width: `${a.w}px`, height: `${a.h}px` }"
            data-anneau-ressemblance
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
        <MiniCarte
          v-if="!estMobile"
          ref="miniCarte"
          :disposition="disposition"
          :palier="PALIERS[niveau]!.libelle"
          @centrer="centrerDepuisMiniCarte"
        />
        <div
          v-if="bulle"
          class="pointer-events-none absolute rounded-pastille border border-ligne bg-panneau/95 px-3.5 py-1.5 text-xs whitespace-nowrap text-[var(--hi-gouttiere-texte)]"
          :style="{ left: `${estMobile ? 12 : 180}px`, bottom: `${estMobile ? 12 : 22}px` }"
        >
          {{ bulle }}
        </div>

        <!-- La fiche sélectionnée -->
        <section
          v-if="ficheChoisie && estMobile"
          class="absolute inset-x-3 bottom-3 z-10 flex flex-col gap-2 rounded-panneau border border-ligne bg-panneau px-4 py-3.5 shadow-[0_10px_30px_rgba(0,0,0,.5)]"
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
          <button
            type="button"
            role="checkbox"
            :aria-checked="ligneDeLaChoisie"
            class="flex min-h-9 items-center gap-2.5 text-left text-sm"
            @click="basculerLigneDeLaChoisie"
          >
            <span
              class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
              :class="ligneDeLaChoisie ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
            >{{ ligneDeLaChoisie ? '✓' : '' }}</span>
            {{ filtres.ligneDirecte && ligneSeule && !ligneDeLaChoisie ? 'Suivre sa ligne directe' : 'Afficher sa ligne directe' }}
          </button>
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

    <!-- Le volet (PC) : la fiche de la personne choisie ; il pousse l'arbre. Sous
         90 rem, il cède la place au volet « Ajouter une personne ». -->
    <aside
      v-if="voletDroitOuvert && ficheChoisie && disposition"
      class="flex w-[380px] flex-none flex-col border-l border-ligne bg-panneau"
      :style="{ borderTop: `3px solid ${couleurBranche(brancheDe(ficheChoisie))}` }"
      aria-label="Fiche de la personne choisie"
    >
      <header class="flex flex-none flex-col gap-2 border-b border-ligne px-4 py-3.5">
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
            title="Fermer (Échap)"
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
            libelleBranche(ficheChoisie.branche)
          }}</span>
          <span
            v-if="ficheChoisie.detache"
            class="rounded-pastille border border-dashed border-ligne px-2.5 py-px text-xs text-attenue"
            title="Sans lien connu avec vous : génération estimée d’après les dates."
          >sans lien connu</span>
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
        <button
          type="button"
          role="checkbox"
          :aria-checked="ligneDeLaChoisie"
          class="flex min-h-9 items-center gap-2.5 text-left text-sm"
          @click="basculerLigneDeLaChoisie"
        >
          <span
            class="flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border-[1.5px] text-[13px] font-bold text-fond"
            :class="ligneDeLaChoisie ? 'border-accent bg-accent' : 'border-[var(--h-inconnu-bord)]'"
          >{{ ligneDeLaChoisie ? '✓' : '' }}</span>
          <span class="flex flex-col leading-tight">
            <span>{{ filtres.ligneDirecte && ligneSeule && !ligneDeLaChoisie ? 'Suivre sa ligne directe' : 'Afficher sa ligne directe' }}</span>
            <span class="text-xs text-attenue">{{
              ligneSeule ? 'ancêtres et descendants · les autres quittent l’écran' : 'ancêtres et descendants · reste cochée d’une personne à l’autre'
            }}</span>
          </span>
        </button>
      </header>

      <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-4 py-4">
        <template v-if="ficheLue && ficheLue.id === ficheChoisie.noeud.personId">
          <PortraitFiche
            v-if="ficheLue.portrait"
            class="self-center"
            :portrait="ficheLue.portrait"
            :nom="ficheLue.displayName"
            :sexe="ficheLue.sex"
          />
          <IdentiteFiche :fiche="ficheLue" :seulement="['birth', 'death']" />
          <ParenteFiche :fiche="ficheLue" vers-arbre @choisir="choisirProche" />
          <section v-if="ficheLue.faits.length">
            <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">Faits</h3>
            <FaitsFiche :fiche="ficheLue" />
          </section>
          <p class="m-0 text-xs text-attenue">
            {{ ficheLue.citationCount }} source{{ ficheLue.citationCount > 1 ? 's' : '' }} ·
            {{ ficheLue.notes.length }} note{{ ficheLue.notes.length > 1 ? 's' : '' }} —
            <RouterLink
              v-if="ficheChoisie.noeud.personId !== null"
              :to="{ name: 'personne.detail', params: { id: ficheChoisie.noeud.personId } }"
              class="text-accent underline"
              @click="memoriserLaVue"
            >tout voir sur la fiche</RouterLink>
          </p>
        </template>
        <!-- Un nœud EN ATTENTE (le calque des demandes) n'a pas de fiche à lire : la
             personne n'existe que dans sa demande. Sans cette branche, « Lecture de la
             fiche… » restait affiché sans fin. -->
        <section
          v-else-if="ficheChoisie.noeud.personId === null"
          class="flex flex-col gap-2 rounded-panneau border border-dashed border-accent px-3.5 py-3"
          data-noeud-propose
        >
          <h3 class="m-0 text-sm font-semibold text-accent">Proposée, en attente de validation</h3>
          <p class="m-0 text-sm text-attenue">
            Elle n’a pas encore de fiche : elle n’existe que dans sa demande, jusqu’à ce qu’un
            administrateur la valide.
          </p>
          <!-- Compléter sur place (ADR 0017) : son auteur, ou un administrateur. Le
               formulaire s'ouvre à gauche, sur cette fiche ; l'arbre reste. -->
          <button
            v-if="peutCompleter(ficheChoisie.noeud)"
            type="button"
            data-completer-le-noeud
            class="h-9 self-start rounded-champ border border-accent px-3 text-sm font-semibold text-accent hover:bg-accent-doux"
            aria-controls="volet-proposition"
            @click="completerLeNoeud(ficheChoisie.noeud)"
          >
            Compléter
          </button>
          <RouterLink
            v-if="ficheChoisie.noeud.demandeId !== undefined"
            :to="{ name: 'demande.detail', params: { id: ficheChoisie.noeud.demandeId } }"
            class="self-start text-sm font-semibold text-accent underline"
            @click="memoriserLaVue"
          >
            Voir la demande n° {{ ficheChoisie.noeud.demandeId }}
          </RouterLink>
        </section>
        <!-- Restreinte d'après le graphe, ou d'après le serveur (un cercle qui a changé depuis). -->
        <p
          v-else-if="ficheChoisie.noeud.visibilite === 'restreinte' || etatDeLaFiche.etat === 'restreinte'"
          class="m-0 text-sm text-attenue"
        >
          Personne vivante : sa fiche n’est visible que de ses proches.
        </p>
        <!-- L'échec se dit, et se relance : « Lecture de la fiche… » ne reste plus affiché sans fin. -->
        <div v-else-if="etatDeLaFiche.etat === 'erreur'" class="flex flex-col items-start gap-2" role="alert" data-fiche-en-erreur>
          <p class="m-0 text-sm text-attenue">La fiche n’a pas pu être lue. {{ etatDeLaFiche.message }}</p>
          <button
            type="button"
            class="h-9 rounded-champ border border-ligne px-3 text-sm font-semibold hover:bg-accent-doux"
            @click="lectureDeLaFiche.reessayer()"
          >
            Réessayer
          </button>
        </div>
        <p v-else class="m-0 text-sm text-attenue">Lecture de la fiche…</p>
      </div>
    </aside>
    </div>

    <!-- Téléphone : la recherche en bas -->
    <ChampRechercheArbre
      v-if="estMobile"
      ref="champ"
      v-model:texte="rechercheMobile.texte.value"
      :etat="rechercheMobile.etat.value"
      :resultats="rechercheMobile.resultats.value"
      :tronque="rechercheMobile.tronque.value"
      :message="rechercheMobile.message.value"
      :curseur="curseur"
      :ouverte="listeOuverte"
      @touche="surToucheRecherche"
      @choisir="allerA"
      @reessayer="rechercheMobile.reessayer()"
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

<style>
/*
 * L'APPARITION d'un calque entier (les boîtes, la couche des textes), au premier
 * affichage : un seul fondu, sur le seul conteneur du calque, une fois par chargement.
 * C'est l'unique `opacity` du canevas, et elle est délibérée (ADR 0007, révision du
 * 2026-10-14) : UN contexte d'empilement par calque, 600 ms, jamais un par fiche. Les
 * traits, eux, apparaissent par `stroke-opacity` (`TracesGroupes.vue`).
 */
@keyframes apparition-calque {
  from {
    opacity: 0;
  }
}
.calque-apparait {
  animation: apparition-calque 600ms ease-out backwards;
}
.calque-apparait-apres {
  animation-delay: 120ms;
}
</style>

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
.filtres-section {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.filtres-section + .filtres-section {
  border-top: 1px solid var(--color-ligne);
  padding-top: 0.875rem;
}
.filtres-titre {
  margin: 0;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-attenue);
}
.seuil-des-noms {
  accent-color: var(--color-accent);
}
</style>
