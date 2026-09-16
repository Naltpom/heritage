<script lang="ts">
/**
 * Les deux décisions de cet écran qui se prouvent **sans navigateur**.
 *
 * Elles sont ici, dans un bloc `<script>` ordinaire, et non dans le
 * `<script setup>` ci-dessous, pour une raison mécanique : le `<script setup>`
 * se compile en une fermeture sur l'état du composant, que rien ne peut appeler
 * du dehors. Or ces deux règles-là se sont cassées **en silence** — un écran
 * vide sans message, une page qui déborde — et une règle qui se casse en
 * silence a besoin d'un test qui la tient. Elles sont exportées ; leur suite est
 * `arbre-revalidation.test.ts`.
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

/**
 * La hauteur à poser sur le cadre de l'arbre, sachant à quelle hauteur son haut
 * se trouve — c'est-à-dire ce que l'en-tête applicatif lui a déjà pris.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Trois décisions, et chacune a coûté un pixel de défilement.**
 *
 * 1. **`document.documentElement.clientHeight`, pas `window.innerHeight`.**
 *    `innerHeight` compte la bande d'une barre de défilement horizontale ;
 *    `clientHeight` est la hauteur de **mise en page**, qui l'exclut. Douze
 *    pixels d'écart, mesurés. Prendre `innerHeight` posait le cadre 12 px trop
 *    bas et faisait naître une barre verticale sur une page qui n'avait rien de
 *    plus à montrer — une barre causée par la mesure qui la subissait.
 *
 * 2. **Arrondi vers le bas.** Le haut du cadre est fractionnaire — 54,40 px
 *    pour un en-tête à 1,25 dpr. `Math.round(617,6)` rend 618 : le bas du cadre
 *    dépasse de 0,40 px, et 0,40 px de trop font un pixel de défilement.
 *
 * 3. **Aucun plancher.** Un `Math.max(320, …)` garantissait mathématiquement le
 *    débordement dès que la place restante passait sous 320 px — 164 px de trop
 *    sur un écran de 400 px, où l'en-tête s'enroule sur quatre rangs. Un
 *    plancher ne peut pas coexister avec « la page ne défile pas ». Et la
 *    garantie qu'il prétendait donner — « un canevas qui s'effondre à zéro ne
 *    se voit qu'à l'exécution » — est déjà portée par la chaîne flex
 *    (`min-h-0` / `flex-1`) du gabarit : le nombre magique n'y ajoutait qu'un
 *    débordement.
 *
 * Reste `Math.max(0, …)`, qui n'est pas un plancher de confort : une hauteur
 * négative n'est pas une longueur CSS, et le cadre garderait alors la dernière
 * valeur valide.
 * ────────────────────────────────────────────────────────────────────
 */
export function hauteurDuCadre(hautDuCadre: number): string {
  const disponible = document.documentElement.clientHeight - hautDuCadre
  return `${Math.max(0, Math.floor(disponible))}px`
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
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreOutilsArbre from '@/components/arbre/BarreOutilsArbre.vue'
import CanevasArbre from '@/components/arbre/CanevasArbre.vue'
import LegendeArbre from '@/components/arbre/LegendeArbre.vue'
import PanneauFiche from '@/components/arbre/PanneauFiche.vue'
import { avertissementDuCalque, ponctuationApres } from '@/components/arbre/calque'
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

const graphe = shallowRef<Graphe | null>(null)
const canevas = ref<InstanceType<typeof CanevasArbre> | null>(null)
const barre = ref<InstanceType<typeof BarreOutilsArbre> | null>(null)
const racine = ref<HTMLElement | null>(null)

const {
  erreur: erreurGraphe,
  chargement: chargementGraphe,
  executer: appelerGraphe,
} = useAppelApi(chargerGraphe)

async function revaliderGraphe(): Promise<void> {
  graphe.value = await grapheApresRevalidation(graphe.value, appelerGraphe)
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
  if (ev.key === '/') {
    ev.preventDefault()
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
    <BarreOutilsArbre
      ref="barre"
      v-model="question"
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
