<script lang="ts">
/**
 * La décision de cet écran qui se prouve **sans navigateur**.
 *
 * Elle est ici, dans un bloc `<script>` ordinaire, et non dans le
 * `<script setup>` ci-dessous, pour une raison mécanique : le `<script setup>`
 * se compile en une fermeture sur l'état du composant, que rien ne peut appeler
 * du dehors. Or cette règle-là s'est cassée **en silence** — un écran vide, sans
 * message — et une règle qui se casse en silence a besoin d'un test qui la
 * tient. Elle est exportée ; sa suite est `arbre-revalidation.test.ts`.
 *
 * La seconde décision qui vivait ici, `hauteurDuCadre()`, a déménagé dans
 * `views/cadre.ts` : la chronologie en gardait une copie qui portait encore les
 * trois défauts que celle-ci corrige, et deux écrans de plus allaient en faire
 * une troisième. Sa suite est `cadre.test.ts`.
 */
import { invaliderGraphe, type Graphe, type ResultatRevalide } from '@/lib/api'

/**
 * Ce que devient le graphe **affiché** après un appel revalidé.
 *
 * `inchange` ⇒ on garde ce qu'on a : « `304` → le client garde son SVG en
 * place. Il ne le reconstruit pas, il ne le vide pas » (§ 5.2, étape 3). `null`
 * ⇒ l'appel a échoué (l'erreur est dans `erreurGraphe`) ou un appel plus récent
 * l'a supplanté ; dans les deux cas le canevas reste en place lui aussi.
 *
 * ────────────────────────────────────────────────────────────────────
 * **La ligne du dessus, et pourquoi elle n'est pas dans `revalidation.ts`.**
 *
 * La mémoire des `ETag` est de portée **module** : elle vit aussi longtemps que
 * l'onglet. Le graphe affiché est de portée **composant** : il meurt avec la
 * vue et renaît `null` au montage suivant. Les deux ne sont donc pas d'accord
 * dès le second montage, et la vue opposait alors un `If-None-Match` au nom
 * d'un SVG qu'elle n'avait plus : le serveur rendait `304`, `affiche` restait
 * `null`, et `v-show` posait `display: none` sur un cadre vide. Ni squelette,
 * ni message d'erreur — l'arbre disparaissait au premier aller-retour, et pour
 * de bon.
 *
 * La règle du contrat n'est pas en cause ; c'est sa **précondition** — « le
 * client a un SVG » — qui était fausse. On la rétablit du seul côté qui la
 * connaisse : quand la vue n'a rien à garder en place, elle oublie l'`ETag` et
 * repart d'un `200`.
 *
 * Ce n'est pas `revalidation.ts` qui doit porter cela. `{statut:'inchange'}`
 * sans données est délibéré — l'union discriminée oblige l'appelant à traiter
 * le cas, et c'est ce qui a rendu ce défaut réparable en une ligne au lieu
 * d'un `if (!donnees) vider()` disséminé. Faire porter au transport une
 * précondition qui appartient à la vue la casserait pour tous les autres
 * appelants.
 *
 * **Ce que cela coûte** : un `200` complet à chaque entrée dans la vue arbre,
 * c'est-à-dire ce que faisait le produit avant que l'`ETag` n'existe. L'`ETag`
 * garde ses trois emplois réels — le retour d'onglet, la revalidation après
 * écriture, le bouton « Réessayer » — où le SVG est bien en place.
 * ────────────────────────────────────────────────────────────────────
 */
export async function grapheApresRevalidation(
  affiche: Graphe | null,
  appeler: () => Promise<ResultatRevalide<Graphe> | null>,
): Promise<Graphe | null> {
  if (affiche === null) invaliderGraphe()

  const resultat = await appeler()
  if (resultat === null || resultat.statut === 'inchange') return affiche
  return resultat.donnees
}

</script>

<script setup lang="ts">
/**
 * L'arbre — § 5.2 du contrat, § 7.2 du dossier de mission.
 *
 * L'écran le plus contraint du produit. Cette vue **orchestre** ; elle ne
 * dessine pas. Le canevas est dans `components/arbre/`, et il est piloté
 * impérativement.
 *
 * ────────────────────────────────────────────────────────────────────
 * TROIS RÈGLES DE RENDU, DÉJÀ PAYÉES :
 *
 * 1. **Le zoom passe par le `viewBox`, jamais par `transform: scale()`.**
 *    `boite` alimente le `viewBox` initial et n'est jamais recalculée ici.
 * 2. **Jamais `opacity` sur un élément SVG** pour mettre en retrait : une
 *    couche de composition par arête. `canevas.css` n'emploie que
 *    `fill-opacity` / `stroke-opacity`.
 * 3. **Le canevas vit hors de la réactivité de Vue.** Les 377 nœuds ne
 *    figurent dans aucun `v-for`. Vue gère ce cadre, la barre d'outils, la
 *    légende et le panneau latéral.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Revalidation (§ 5.2, étape 4).** `chargerGraphe()` rend `inchange` ou
 * `nouveau`. Sur `inchange`, le SVG **reste en place** : on ne le reconstruit
 * pas, on ne le vide pas. Sur `nouveau`, reconstruction complète, sans
 * réconciliation maison. On revalide au montage et au retour d'onglet ; cet
 * écran n'écrit pas, il n'a donc rien d'autre à déclencher. Jamais de sondage
 * en boucle : à cette échelle, le graphe change quelques fois par semaine.
 *
 * **La recherche passe par l'API, et c'est une règle de confidentialité.**
 * `GET /api/arbre/recherche` exclut déjà les personnes vivantes hors du cercle
 * du lecteur — « elle n'est pas rendue en version réduite : elle est absente »
 * (§ 5.5). Reconstruire un index local à partir des `displayName` des nœuds les
 * ferait toutes réapparaître dans les résultats : on contournerait la règle
 * sans l'avoir voulu. Le graphe ne sert qu'à **retrouver le nœud** d'un
 * `personId` que le serveur a déjà accepté de nommer.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { hauteurDuCadre } from '@/views/cadre'
import { useEstMobile, useTiroirs } from '@/composables/useTiroirs'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreOutilsArbre from '@/components/arbre/BarreOutilsArbre.vue'
import ChampRechercheArbre from '@/components/arbre/ChampRechercheArbre.vue'
import CanevasArbre from '@/components/arbre/CanevasArbre.vue'
import LegendeArbre from '@/components/arbre/LegendeArbre.vue'
import TiroirOutils from '@/components/mise-en-page/TiroirOutils.vue'
import PanneauFiche from '@/components/arbre/PanneauFiche.vue'
import { avertissementDuCalque, ponctuationApres } from '@/components/arbre/calque'
import {
  dispositionDeLaRoute,
  mesurerLisibilite,
  optionsDepuis,
  presentationDeLaRoute,
  type NomDeDisposition,
} from '@/components/arbre/dispositions'
import {
  FILTRES,
  ensembleAEclairer,
  filtresDisponibles,
  noeudsFiltres,
  type IdFiltre,
  type NoeudPersonneEtendu,
} from '@/components/arbre/graphe'
import { useAppelApi } from '@/composables/useAppelApi'
import {
  chargerGraphe,
  lireBrouillonPersonne,
  lireFichePersonne,
  rechercherPersonnes,
  type FicheBrouillon,
  type FichePersonne,
  type NoeudGraphe,
} from '@/lib/api'
/* `Graphe` est déjà importé par le bloc `<script>` ci-dessus, dont les liaisons
   sont dans la portée de ce `<script setup>` : le réimporter ici ferait un
   doublon d'identifiant (TS2300). */

/* ------------------------------------------------------------------ */
/* Le graphe                                                           */
/* ------------------------------------------------------------------ */

/**
 * **Deux graphes, et il faut les distinguer.**
 *
 * `grapheBrut` est ce que le serveur a envoyé. `graphe` est ce qu'on dessine :
 * le même, passé par la **disposition** de la route.
 *
 * Les garder séparés n'est pas une élégance. La revalidation rend `inchange`
 * quand rien n'a bougé, auquel cas on garde ce qu'on a — et si ce qu'on gardait
 * était déjà transformé, la transformation se rejouerait par-dessus elle-même à
 * chaque retour d'onglet. Une disposition idempotente y survivrait ; une qui
 * duplique des nœuds, non, et elle en ajouterait à l'infini.
 */
const grapheBrut = shallowRef<Graphe | null>(null)

/**
 * La disposition de cette route — absente sur `/arbre`, qui rend la mise en page
 * du moteur telle quelle.
 *
 * **Une prop, et non `useRoute()`.** La table de routage la tire de `meta` et la
 * passe ; la vue, elle, ne connaît pas le routeur. C'est ce qui la garde montable
 * seule dans un test de rendu — la suite de ce produit rend les composants côté
 * serveur, sans routeur, et un `useRoute()` y vaut `undefined`. Le même piège a
 * déjà été payé sur l'en-tête.
 */
const { disposition: nomDeDisposition, haut, bas } = defineProps<{
  disposition?: NomDeDisposition
  /** Réglages de profondeur, bruts : `optionsDepuis()` les borne. */
  haut?: unknown
  bas?: unknown
}>()

const disposition = computed(() => dispositionDeLaRoute(nomDeDisposition))
const presentation = computed(() => presentationDeLaRoute(nomDeDisposition))
const optionsDeDisposition = computed(() => optionsDepuis({ haut, bas }))

const graphe = computed<Graphe | null>(() =>
  grapheBrut.value === null ? null : disposition.value(grapheBrut.value, optionsDeDisposition.value),
)

/**
 * Ce que la disposition change, **en nombres**.
 *
 * Une maquette se juge sur une mesure, pas sur une impression : on peut très bien
 * rendre un arbre plus joli localement en le dégradant ailleurs, et l'œil ne le
 * voit pas sur 377 nœuds. Le bandeau annonce donc l'avant et l'après.
 */
const effetDeLaDisposition = computed(() => {
  const brut = grapheBrut.value
  const affiche = graphe.value

  if (brut === null || affiche === null || presentation.value === null) return null

  return { avant: mesurerLisibilite(brut), apres: mesurerLisibilite(affiche) }
})
const canevas = ref<InstanceType<typeof CanevasArbre> | null>(null)
const barre = ref<InstanceType<typeof BarreOutilsArbre> | null>(null)
/** Le champ du pied de page. Exactement un des deux exemplaires est monté —
 *  celui-ci sur téléphone, celui de la barre d'outils au large —, et c'est ce
 *  qui garantit un seul `id="arbre-recherche"` dans le document. */
const champMobile = ref<InstanceType<typeof ChampRechercheArbre> | null>(null)
const estMobile = useEstMobile()
const { ouvert: tiroirOuvert, unTiroirEstOuvert } = useTiroirs()
const racine = ref<HTMLElement | null>(null)

const {
  erreur: erreurGraphe,
  chargement: chargementGraphe,
  executer: appelerGraphe,
} = useAppelApi(chargerGraphe)

async function revaliderGraphe(): Promise<void> {
  grapheBrut.value = await grapheApresRevalidation(grapheBrut.value, appelerGraphe)
}

const calqueActif = computed(() => graphe.value?.calque.actif === true)
const demandesDuCalque = computed(() => graphe.value?.calque.demandes ?? [])

/**
 * **Ce que le calque n'a pas pu dessiner.**
 *
 * `actif` dit qu'il y a quelque chose en attente ; il ne dit pas que tout est
 * là. Mesuré sur un cas réel : cinq demandes portant vingt-deux personnes ont
 * rendu un calque actif avec **cinq nœuds sur vingt-deux**, sans que rien ne le
 * signale. Le lecteur croyait voir la branche entière.
 *
 * L'omission est la bonne réponse du serveur — une fiche sans ancre serait posée
 * sur l'ego — mais elle doit se lire. On nomme les trois premières et on compte
 * le reste : la liste entière d'une branche de dix-sept fiches ferait un bandeau
 * plus haut que l'arbre.
 */
const ecartDuCalque = computed(() =>
  avertissementDuCalque(graphe.value?.calque.nonPlaces ?? []),
)

/** La ponctuation qui suit chaque nom, composée par `calque.ts` où elle est éprouvée. */
function ponctuation(rang: number): string {
  const avertissement = ecartDuCalque.value

  return avertissement === null ? '' : ponctuationApres(avertissement, rang)
}

/* ------------------------------------------------------------------ */
/* Filtres                                                             */
/* ------------------------------------------------------------------ */

const filtreActif = ref<IdFiltre | null>(null)

/**
 * Un filtre dont la donnée n'est pas envoyée est **désactivé**, pas faux (voir
 * `graphe.ts`). La disponibilité se lit sur le graphe lui-même et non sur le
 * pilote : le pilote n'est pas réactif, le graphe l'est.
 */
const disponibles = computed(() => {
  const g = graphe.value
  if (g === null) return new Set<IdFiltre>()
  const personnes = g.noeuds.filter(
    (n): n is NoeudPersonneEtendu => n.type === 'personne',
  )
  return filtresDisponibles(personnes)
})

function basculerFiltre(id: IdFiltre): void {
  const pilote = canevas.value?.piloteCourant() ?? null
  if (pilote === null) return
  fermerPanneau()
  // Un filtre et une recherche superposeraient deux mises en retrait : plus rien
  // ne serait lisible. Le dernier geste gagne.
  if (question.value !== '') {
    question.value = ''
    pilote.effacerRecherche()
  }

  if (filtreActif.value === id) {
    filtreActif.value = null
    pilote.toutAfficher()
    return
  }
  filtreActif.value = id
  pilote.eclairer(new Set(noeudsFiltres(pilote.index.personnes, id)), null, null)
}

/* ------------------------------------------------------------------ */
/* Recherche                                                           */
/* ------------------------------------------------------------------ */

const question = ref('')
const resultats = shallowRef<Set<string>>(new Set())
const texteResultat = ref('')
const tronque = ref(false)

/** Recadrer la vue sur les fiches trouvées dès qu'une recherche aboutit.
 *  Cochée par défaut : sans cela, la recherche marque des fiches que le lecteur
 *  ne voit pas — sur 377 nœuds étalés sur 15 824 unités, elles sont presque
 *  toujours hors écran — et il faut un second geste pour les atteindre. */
const recadrageAuto = ref(true)
const chargementRecherche = ref(false)

/** Deux caractères minimum, sinon le serveur rend `422` (§ 5.5). On ne
 *  l'appelle donc pas : une erreur affichée à la première lettre serait un
 *  bogue d'interface, pas une information. */
const LONGUEUR_MINIMALE = 2
/** Le temps de finir un mot. Assez court pour paraître immédiat, assez long
 *  pour ne pas lancer une requête par caractère. */
const DELAI_FRAPPE = 220

let minuterie: ReturnType<typeof setTimeout> | undefined
let abandonRecherche: AbortController | null = null

function effacerRecherche(): void {
  abandonRecherche?.abort()
  abandonRecherche = null
  resultats.value = new Set()
  texteResultat.value = ''
  tronque.value = false
  chargementRecherche.value = false
  canevas.value?.piloteCourant()?.effacerRecherche()
}

async function lancerRecherche(q: string): Promise<void> {
  const pilote = canevas.value?.piloteCourant() ?? null
  if (pilote === null) return

  abandonRecherche?.abort()
  const controleur = new AbortController()
  abandonRecherche = controleur
  chargementRecherche.value = true

  try {
    const reponse = await rechercherPersonnes(q, 50, controleur.signal)
    if (controleur.signal.aborted) return

    const ids = new Set<string>()
    for (const element of reponse.elements) {
      const noeud = pilote.index.parPersonId.get(element.personId)
      if (noeud !== undefined) ids.add(noeud.id)
    }
    resultats.value = ids
    tronque.value = reponse.tronque
    texteResultat.value = ids.size > 0 ? `${ids.size} fiche${ids.size > 1 ? 's' : ''}` : 'aucune'

    // Une recherche annule la sélection en cours : les deux mises en retrait se
    // superposeraient et plus rien ne serait lisible.
    fermerPanneau()
    filtreActif.value = null
    pilote.toutAfficher()
    pilote.marquerTrouves(ids)

    // ── LE RECADRAGE AUTOMATIQUE ───────────────────────────────────────────
    //
    // Marquer les fiches trouvées ne sert à rien si elles sont hors de l'écran,
    // et sur 377 nœuds étalés sur 15 824 unités, elles le sont presque toujours.
    // Il fallait donc chercher, puis penser à appuyer sur « Cadrer » — deux
    // gestes pour une intention.
    //
    // La case reste **offerte et cochée** plutôt que le geste supprimé : un
    // recadrage déplace la vue, et quelqu'un qui explore une branche en cherchant
    // des noms au passage veut parfois garder son cadrage. Le bouton « Cadrer »
    // demeure pour ceux qui décochent.
    if (recadrageAuto.value) cadrerSurResultats()
  } catch {
    if (controleur.signal.aborted) return
    resultats.value = new Set()
    texteResultat.value = 'recherche indisponible'
  } finally {
    if (!controleur.signal.aborted) chargementRecherche.value = false
  }
}

watch(question, (valeur) => {
  clearTimeout(minuterie)
  const q = valeur.trim()
  if (q.length < LONGUEUR_MINIMALE) {
    effacerRecherche()
    return
  }
  minuterie = setTimeout(() => void lancerRecherche(q), DELAI_FRAPPE)
})

function cadrerSurResultats(): void {
  if (resultats.value.size === 0) return
  canevas.value?.cadrerSur(resultats.value)
}

/* ------------------------------------------------------------------ */
/* Panneau latéral                                                     */
/* ------------------------------------------------------------------ */

const panneauOuvert = ref(false)
const fiche = shallowRef<FichePersonne | null>(null)
const brouillon = shallowRef<FicheBrouillon | null>(null)
const chargementFiche = ref(false)
const erreurFiche = shallowRef<unknown>(null)
/** De quoi relancer après une erreur, sans avoir à recliquer sur le nœud. */
let dernierNoeud: NoeudGraphe | null = null

let abandonFiche: AbortController | null = null

function fermerPanneau(): void {
  panneauOuvert.value = false
  abandonFiche?.abort()
  abandonFiche = null
}

/*
 * Sur téléphone, la fiche est en plein écran et le tiroir des filtres l'est
 * presque : les deux ouverts en même temps, on ne voit plus l'arbre du tout et
 * il faut deux gestes pour y revenir. Ouvrir les filtres referme donc la fiche
 * — « le dernier geste gagne », la règle que la recherche et les filtres
 * appliquent déjà entre eux plus haut dans ce fichier.
 *
 * Aucune garde de largeur ici : sur grand écran, ouvrir un tiroir est
 * impossible, son bouton étant `md:hidden`. Une condition de plus serait une
 * condition de plus à maintenir vraie.
 */
watch(tiroirOuvert, (etat) => {
  if (etat === 'outils') fermerPanneau()
})

async function chargerDetail(noeud: NoeudGraphe): Promise<void> {
  if (noeud.type !== 'personne') return

  abandonFiche?.abort()
  const controleur = new AbortController()
  abandonFiche = controleur

  fiche.value = null
  brouillon.value = null
  erreurFiche.value = null
  chargementFiche.value = true

  try {
    if (noeud.enAttente === true) {
      // **Jamais de découpage de `#12/p1`.** L'API envoie les deux morceaux
      // séparément précisément pour cela (§ 5.2, piège n° 2).
      if (noeud.demandeId === undefined || noeud.brouillonRef === undefined) return
      brouillon.value = await lireBrouillonPersonne(
        noeud.demandeId,
        noeud.brouillonRef,
        controleur.signal,
      )
    } else {
      if (noeud.personId === null) return
      fiche.value = await lireFichePersonne(noeud.personId, controleur.signal)
    }
  } catch (cause) {
    if (!controleur.signal.aborted) erreurFiche.value = cause
  } finally {
    if (!controleur.signal.aborted) chargementFiche.value = false
  }
}

/** Ouvrir un nœud : éclairer la chaîne jusqu'à l'ego, centrer, charger. */
async function ouvrirNoeud(noeud: NoeudGraphe): Promise<void> {
  const pilote = canevas.value?.piloteCourant() ?? null
  if (pilote === null) return

  dernierNoeud = noeud
  filtreActif.value = null
  // Une sélection annule la recherche en cours. On retire la classe tout de
  // suite, sans attendre que la surveillance de `question` s'exécute : les deux
  // mises en retrait coexisteraient le temps d'une image, et cela se voit.
  if (question.value !== '') {
    question.value = ''
    pilote.effacerRecherche()
  }

  const { garder, chaine } = ensembleAEclairer(pilote.index, noeud.id)
  pilote.eclairer(garder, noeud.id, new Set(chaine))
  canevas.value?.centrer(noeud.id)

  // Un losange de famille s'éclaire mais n'ouvre rien : c'est une jonction,
  // pas une fiche.
  if (noeud.type !== 'personne') {
    fermerPanneau()
    return
  }
  panneauOuvert.value = true
  await chargerDetail(noeud)
}

function allerVersPersonne(personId: number): void {
  const pilote = canevas.value?.piloteCourant() ?? null
  const noeud = pilote?.index.parPersonId.get(personId)
  if (noeud === undefined) return
  void ouvrirNoeud(noeud)
}

function reessayerFiche(): void {
  if (dernierNoeud !== null) void chargerDetail(dernierNoeud)
}

/* ------------------------------------------------------------------ */
/* Remise à zéro et raccourcis                                         */
/* ------------------------------------------------------------------ */

/** Efface la sélection, le filtre et la recherche — **sans toucher au cadrage**.
 *  Le viewer d'origine faisait la même distinction : `Échap` rend la peinture
 *  pleine, il ne ramène pas la vue d'ensemble sous le nez du lecteur. */
function effacerSelection(): void {
  question.value = ''
  filtreActif.value = null
  fermerPanneau()
  const pilote = canevas.value?.piloteCourant() ?? null
  pilote?.toutAfficher()
  pilote?.effacerRecherche()
}

/** Le bouton « Tout afficher » : comme ci-dessus, **plus** le recadrage. */
function toutAfficher(): void {
  effacerSelection()
  canevas.value?.recadrer()
}

function surToucheGlobale(ev: KeyboardEvent): void {
  const cible = ev.target
  // Le champ de recherche gère ses propres touches (Échap, Entrée).
  if (cible instanceof HTMLInputElement || cible instanceof HTMLTextAreaElement) return
  // Un tiroir ouvert prend la main sur toutes ces touches — et il se ferme
  // lui-même, par le `keydown.escape` de l'en-tête et du tiroir. Sans cette
  // ligne, Échap fermerait le tiroir ET désélectionnerait derrière lui, c'est-
  // à-dire deux choses pour un geste. L'ordre est explicite ici plutôt que
  // remis à deux écouteurs de `window` qui se disputeraient la priorité selon
  // leur ordre d'enregistrement.
  if (unTiroirEstOuvert()) return
  if (ev.key === '/') {
    ev.preventDefault()
    // `/` vise celui des deux qui est monté. L'ordre n'a pas d'importance :
    // l'autre est `null`.
    champMobile.value?.focus()
    barre.value?.focusRecherche()
  } else if (ev.key === '0') {
    canevas.value?.recadrer()
  } else if (ev.key === 'Escape') {
    effacerSelection()
  }
}

/* ------------------------------------------------------------------ */
/* Hauteur du cadre                                                    */
/* ------------------------------------------------------------------ */

/**
 * Le canevas occupe tout ce qui reste sous l'en-tête applicatif.
 *
 * La hauteur est **mesurée**, pas déduite d'une chaîne de `height: 100%` : le
 * cadre de l'application peut porter un bandeau de régime ou de bascule
 * « voir comme un membre » dont la hauteur n'est pas connue d'ici, et un
 * canevas qui s'effondre à zéro ne se voit qu'à l'exécution.
 */
const hauteurCadre = ref('70vh')

function mesurerHauteur(): void {
  const element = racine.value
  if (element === null) return
  hauteurCadre.value = hauteurDuCadre(element.getBoundingClientRect().top)
}

/* ------------------------------------------------------------------ */
/* Cycle de vie                                                        */
/* ------------------------------------------------------------------ */

function surRetourDOnglet(): void {
  if (document.visibilityState === 'visible') void revaliderGraphe()
}

onMounted(() => {
  mesurerHauteur()
  window.addEventListener('resize', mesurerHauteur)
  window.addEventListener('keydown', surToucheGlobale)
  document.addEventListener('visibilitychange', surRetourDOnglet)
  void revaliderGraphe()
})

onBeforeUnmount(() => {
  clearTimeout(minuterie)
  abandonRecherche?.abort()
  abandonFiche?.abort()
  window.removeEventListener('resize', mesurerHauteur)
  window.removeEventListener('keydown', surToucheGlobale)
  document.removeEventListener('visibilitychange', surRetourDOnglet)
})
</script>

<template>
  <div ref="racine" class="flex flex-col" :style="{ height: hauteurCadre }">
    <!--
      Le champ de recherche sur téléphone : un pied de page **fixe**, visible en
      permanence, hors du tiroir.

      Il est monté ici, et non déplacé depuis la barre d'outils. Le téléportage —
      la première version — devait faire sortir un sous-arbre vivant d'un ancêtre
      en `display: none` après le montage, et cassait le rendu de Vue en
      production sur la largeur même qu'il visait. Voir l'en-tête de
      `ChampRechercheArbre`. Un seul exemplaire du champ est monté à la fois,
      celui-ci ou celui de la barre, jamais les deux.

      Déclaré **avant le bloc du canevas**, pour que la fiche en plein écran, qui
      porte le même `z-index`, passe par-dessus lui en étant plus loin dans le
      document.

      `fixed` et non un élément de la colonne : dans le flux, cette barre prenait de
      la hauteur au canevas, qui est mesuré — c'est-à-dire qu'elle rétrécissait
      l'arbre au lieu de flotter au-dessus.
    -->
    <div
      v-if="estMobile"
      id="recherche-arbre-mobile"
      class="fixed inset-x-0 bottom-0 z-30 flex border-t border-ligne bg-panneau shadow-panneau md:hidden"
    >
      <ChampRechercheArbre
        ref="champMobile"
        v-model="question"
        :resultat="texteResultat"
        :tronque="tronque"
        :chargement="chargementRecherche"
        :compact="true"
        @cadrer="cadrerSurResultats"
      />
    </div>

    <TiroirOutils titre="Filtres de l’arbre">
      <BarreOutilsArbre
        ref="barre"
        v-model="question"
        v-model:recadrage-auto="recadrageAuto"
        :filtres="FILTRES"
        :disponibles="disponibles"
        :actif="filtreActif"
        :resultat="texteResultat"
        :tronque="tronque"
        :chargement="chargementRecherche"
        @filtre="basculerFiltre"
        @tout="toutAfficher"
        @cadrer="cadrerSurResultats"
      />
      <template #legende>
        <LegendeArbre v-if="graphe !== null" :calque-actif="calqueActif" en-tiroir />
      </template>
    </TiroirOutils>

    <!--
      **Le bandeau de la maquette.** Deux onglets ouverts sur deux dispositions
      sont indiscernables dès qu'on a fait défiler l'arbre : sans ce bandeau, on
      compare deux images dont on ne sait plus laquelle est laquelle.

      Il annonce aussi la **mesure** — couples accolés avant et après. Une
      disposition se juge sur des nombres, pas sur une impression : on peut rendre
      un arbre plus joli localement en le dégradant ailleurs, et l'œil ne le voit
      pas sur 377 nœuds.
    -->
    <div
      v-if="presentation !== null"
      class="border-b border-ligne bg-panneau px-4 py-2 text-xs text-texte"
    >
      <p>
        <strong class="font-semibold">Maquette « {{ presentation.titre }} ».</strong>
        {{ presentation.resume }}
      </p>
      <p v-if="effetDeLaDisposition !== null" class="mt-1 text-attenue tnum">
        Fiches affichées : {{ effetDeLaDisposition.apres.personnes }} sur
        {{ effetDeLaDisposition.avant.personnes }}.

        Couples accolés : {{ effetDeLaDisposition.avant.accoles }} →
        {{ effetDeLaDisposition.apres.accoles }} sur {{ effetDeLaDisposition.apres.couples }}.
        Personnes assises entre deux époux :
        {{ effetDeLaDisposition.avant.intercales }} → {{ effetDeLaDisposition.apres.intercales }}.
        <RouterLink :to="{ name: 'arbre' }" class="text-accent underline">
          Comparer à l’arbre d’origine
        </RouterLink>
      </p>
    </div>

    <p
      v-if="calqueActif"
      class="border-b border-ligne bg-accent-doux px-4 py-1.5 text-xs text-texte"
    >
      Votre arbre affiche
      {{ demandesDuCalque.length }} demande<template v-if="demandesDuCalque.length > 1">s</template>
      en attente de validation. Les fiches proposées sont entourées de tirets : elles ne sont
      visibles que de vous et des administrateurs.
    </p>

    <!--
      **Le bandeau qui manquait.** Un calque qui ne peut pas tout montrer et qui
      ne le dit pas laisse croire que la branche affichée est complète — c'est
      arrivé sur vingt-deux fiches proposées dont cinq seulement étaient
      dessinées. On le dit ici, avec des noms, et on renvoie à la file où les
      fiches restent lisibles une par une.
    -->
    <p
      v-if="ecartDuCalque !== null"
      class="flex flex-wrap items-center gap-x-1.5 border-b border-alerte-bord bg-alerte-doux px-4 py-1.5 text-xs text-texte"
      role="status"
    >
      <strong class="font-semibold">{{ ecartDuCalque.phrase }}</strong>
      <!--
        Chaque nom mène à sa demande : la fiche reste lisible une par une même
        quand le canevas ne sait pas la dessiner. Sans ce lien, le bandeau
        annoncerait un manque sans offrir le moindre geste.
      -->
      <!--
        La ponctuation est **dans le même élément** que le nom qu'elle suit : le
        bandeau est une rangée `flex` à gouttière, et une virgule posée à côté
        reçoit la gouttière comme les autres — « Morille Chanterelle , Cèpe
        Chanterelle . Elles se rattachent… ». Elle appartient au nom, pas à la
        rangée.
      -->
      <span v-for="(absent, rang) in ecartDuCalque.nommes" :key="absent.noeudId">
        <RouterLink
          :to="{ name: 'demande.detail', params: { id: absent.demandeId } }"
          class="text-accent underline"
          >{{ absent.displayName }}</RouterLink
        >{{ ponctuation(rang) }}
      </span>
      <span v-if="ecartDuCalque.enPlus > 0">
        et {{ ecartDuCalque.enPlus }} autre<template v-if="ecartDuCalque.enPlus > 1">s</template>.
      </span>
      <span>
        Elles se rattachent à une fiche elle-même proposée, qui n’est pas encore dans l’arbre
        validé : elles resteront invisibles ici jusqu’à la validation.
      </span>
    </p>

    <!--
      Une revalidation qui échoue ne vide pas le canevas — l'arbre affiché reste
      juste, il peut seulement être daté. On le dit sur une ligne plutôt que de
      remplacer l'écran par une erreur, ce qui ferait perdre le cadrage et la
      sélection pour une panne de réseau passagère.
    -->
    <p
      v-if="erreurGraphe && graphe !== null"
      class="flex flex-wrap items-center gap-2 border-b border-alerte-bord bg-alerte-doux px-4 py-1.5 text-xs"
    >
      L’arbre affiché n’a pas pu être revalidé : il peut être daté.
      <button
        type="button"
        class="cursor-pointer border-0 bg-transparent p-0 text-accent underline"
        @click="revaliderGraphe"
      >
        Réessayer
      </button>
    </p>

    <!--
      `overflow-hidden` n'est pas un ajustement d'apparence : c'est ce bloc, et
      lui seul, qui borne le panneau de fiche. `PanneauFiche` est `absolute
      right-0 w-[340px]`, sorti du cadre par `translate-x-[102%]` — fermé, il
      reste peint et **mesuré** : `inert` et `aria-hidden` le retirent du clavier
      et du lecteur d'écran, pas de la mise en page. Sans rognage ici, aucun
      ancêtre jusqu'à `<html>` ne le masquait (celui de `CanevasArbre` est sur
      son FRÈRE) et la page portait 347 px de défilement horizontal à toute
      largeur — +89 % sur un écran de téléphone — plus les 12 px de hauteur que
      la barre horizontale prenait au cadre.

      C'est bien ici et pas ailleurs : `relative` fait de ce bloc la référence de
      `right-0`, donc la frontière où le panneau ouvert vient s'arrêter pile. Le
      rogner plus haut le recadrerait sur un ancêtre plus large ; le rogner plus
      bas l'amputerait à l'ouverture.

      `overflow-clip` et non `overflow-hidden`, et c'est mesuré : les deux
      suppriment le débordement de la page, mais `hidden` fait de ce bloc un
      **conteneur de défilement**. Il garde alors 347 px de course cachée, sans
      barre pour la montrer : un `scrollIntoView`, un saut d'ancre ou une prise
      de focus dans la partie rognée y feraient glisser tout l'arbre de côté,
      sans que le lecteur puisse le ramener. Relevé sur le bloc, à 1524 px :
      `scrollLeft = 300` est honoré sous `hidden`, refusé (reste 0) sous `clip`.
      `clip` ne rogne pas moins ; il rogne sans ouvrir de porte.
    -->
    <div class="relative min-h-0 flex-1 overflow-clip">
      <div v-if="chargementGraphe && graphe === null" class="p-6">
        <SqueletteChargement :lignes="8" />
      </div>

      <div v-else-if="erreurGraphe && graphe === null" class="p-6">
        <MessageErreur
          :erreur="erreurGraphe"
          titre="L’arbre n’a pas pu être chargé"
          @action="revaliderGraphe"
        />
      </div>

      <CanevasArbre
        v-show="graphe !== null"
        ref="canevas"
        :graphe="graphe"
        @selection="ouvrirNoeud"
        @fond="effacerSelection"
      />

      <LegendeArbre v-if="graphe !== null" :calque-actif="calqueActif" />

      <PanneauFiche
        :fiche="fiche"
        :brouillon="brouillon"
        :chargement="chargementFiche"
        :erreur="erreurFiche"
        :ouvert="panneauOuvert"
        @fermer="fermerPanneau"
        @aller="allerVersPersonne"
        @reessayer="reessayerFiche"
      />
    </div>
  </div>
</template>
