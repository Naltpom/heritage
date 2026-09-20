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
import {
  accueillir,
  annonce,
  effetDeLaReorganisation,
  phraseDuBouton,
  type Annonce,
} from '@/components/arbre/dispositions/insertionLocale'

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
 * Ce que l'écran garde d'une charge à la suivante.
 *
 * `cle` identifie la **transformation** — la disposition, ses réglages, le
 * filtre « ascendance seule ». Tant qu'elle ne change pas, les places affichées
 * sont conservées et l'on y insère ce qui arrive. Dès qu'elle change, elles sont
 * oubliées : ce n'est pas une fiche qui est arrivée, c'est la mise en page qu'on
 * a demandé de refaire.
 */
export interface EtatDeLArbre {
  graphe: Graphe | null
  annonce: Annonce | null
  cle: string | null
  /** Le dépliage rejoue-t-il ? Non sur une insertion locale — voir `lancerLArrivee`. */
  animer: boolean
}

export const ETAT_INITIAL: EtatDeLArbre = { graphe: null, annonce: null, cle: null, animer: true }

/**
 * **La décision de cet écran qui se prouve sans navigateur, la seconde.**
 *
 * Elle est ici, dans un `<script>` ordinaire, pour la raison déjà écrite en tête
 * de ce fichier : un `<script setup>` se compile en une fermeture que rien ne
 * peut appeler du dehors, et une règle qu'aucun test ne peut atteindre se casse
 * en silence. Celle-ci décide de **ce que le lecteur voit bouger** — le genre de
 * défaut qui ne se remarque qu'après coup, quand on ne retrouve plus une fiche.
 *
 * Sa suite est `arbre-insertion-locale.test.ts`.
 */
export function etatApresUneCharge(
  precedent: EtatDeLArbre,
  calcule: Graphe | null,
  cle: string,
): EtatDeLArbre {
  if (calcule === null) return { graphe: null, annonce: null, cle: null, animer: true }

  const memoire = precedent.cle === cle ? precedent.graphe : null
  const arrivee = accueillir(memoire, calcule)

  return {
    graphe: arrivee.graphe,
    annonce: annonce(arrivee),
    cle,
    // On déplie au premier affichage, et sur une réorganisation — là, le
    // mouvement EST l'information. Jamais sur une insertion locale : six cents
    // cartes traverseraient l'écran pour en annoncer deux.
    animer: memoire === null || arrivee.motif !== null,
  }
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
import { animerLArrivee, replie } from '@/components/arbre/animation'
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
  ascendanceSeule as reduireALAscendance,
  dispositionDeLaRoute,
  pedigree,
  compterLesCroisements,
  mesurerLisibilite,
  optionsDepuis,
  presentationDeLaRoute,
  type NomDeDisposition,
} from '@/components/arbre/dispositions'
/* `insertionLocale` n'est PAS une disposition et n'entre donc pas au registre :
   elle ne transforme pas la charge, elle décide de ce qu'on garde d'une mise en
   page à la suivante. La ranger dans `PRESENTATIONS` l'aurait fait apparaître
   comme une sixième maquette dans le bandeau, et il n'y a rien à comparer.
   Elle est importée par le bloc `<script>` ci-dessus, dont les liaisons sont
   dans la portée d'ici : la réimporter ferait un doublon d'identifiant. */
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

/**
 * Le filtre « ascendance seule », **avant** la disposition.
 *
 * L'ordre compte : réduire d'abord, placer ensuite. Dans l'autre sens, la
 * disposition travaillerait sur des nœuds qu'on s'apprête à retirer — elle
 * laisserait des trous, et la mesure annoncée porterait sur un arbre qui n'est
 * pas celui qu'on dessine.
 */
const ascendanceSeule = ref(false)

/**
 * La mise en page que la disposition calcule **de zéro**, sur la charge entière.
 *
 * C'est la meilleure qu'elle sache produire, et c'est exactement pour cela
 * qu'elle ne peut pas être affichée telle quelle à chaque charge : elle est
 * calculée sur *toutes* les fiches, donc six fiches de plus la changent partout.
 * Elle sert désormais de **destination** — ce que le bouton « réorganiser »
 * applique, et ce vers quoi l'insertion locale se rabat quand elle ne peut plus
 * rester locale.
 */
const grapheCalcule = computed<Graphe | null>(() => {
  const brut = grapheBrut.value
  if (brut === null) return null

  // Filtrer ne suffit pas : les ancêtres garderaient les places que leur
  // avaient values des gens qu'on ne dessine plus. L'éventail les replace
  // (`pedigree`), et le décocher rend l'arbre complet tel que le serveur le
  // place — `brut` n'est jamais modifié.
  const reduit = ascendanceSeule.value ? pedigree(reduireALAscendance(brut)) : brut

  return disposition.value(reduit, optionsDeDisposition.value)
})

/* ------------------------------------------------------------------ */
/* L'arrivée d'une fiche : locale par défaut                           */
/* ------------------------------------------------------------------ */

/**
 * **Ce qui est réellement à l'écran.**
 *
 * Mesuré le 20/09/2026 sur deux charges réelles successives : six fiches de plus,
 * et la mise en page recalculée déplaçait **109 fiches sur 447**. Le serveur, lui,
 * n'en déplaçait aucune. Ce qui cassait les repères du lecteur, c'était donc
 * nous — voir `dispositions/insertionLocale.ts` pour le tableau complet.
 *
 * On garde donc la mise en page qu'il regarde, et l'on y **insère** ce qui arrive.
 */
const grapheAffiche = shallowRef<Graphe | null>(null)
const graphe = computed<Graphe | null>(() => grapheAffiche.value)

/** Ce que l'écran a à dire de la dernière charge — `null` la plupart du temps. */
const annonceCourante = shallowRef<Annonce | null>(null)

/**
 * **La mémoire des places ne survit pas à un changement de MISE EN PAGE.**
 *
 * Changer de disposition, de profondeur (`?haut=&bas=`) ou cocher « ascendance
 * seule » ne fait pas *arriver* des fiches : cela change la transformation
 * elle-même. Insérer dans l'ancienne mise en page y répondrait par un arbre
 * inchangé — la maquette demandée ne s'afficherait jamais. On ne garde donc les
 * places que tant que la transformation est la même ; **seul un changement de
 * DONNÉES passe par l'insertion locale.**
 */
const cleDeTransformation = computed(() =>
  JSON.stringify([
    nomDeDisposition ?? null,
    optionsDeDisposition.value,
    ascendanceSeule.value,
  ]),
)

/**
 * L'animation de dépliage ne rejoue **pas** sur une insertion locale — voir
 * `etatApresUneCharge`, qui en décide.
 */
let etat: EtatDeLArbre = ETAT_INITIAL
let animerLaProchaineArrivee = true

watch(
  grapheCalcule,
  (calcule) => {
    etat = etatApresUneCharge(etat, calcule, cleDeTransformation.value)

    grapheAffiche.value = etat.graphe
    annonceCourante.value = etat.annonce
    animerLaProchaineArrivee = etat.animer
  },
  { immediate: true },
)

/**
 * **Ce que le bouton « réorganiser » promet, calculé sur les deux mises en page.**
 *
 * `null` quand il n'y a rien à replacer : un bouton qui ne ferait rien n'a pas à
 * occuper une ligne sur un écran de 390 px. Il apparaît donc **exactement quand
 * la mise en page affichée s'est écartée de la meilleure**, ce qui est aussi le
 * seul moment où le forcer a un sens.
 */
const effetDeLaReorganisationCourante = computed(() => {
  const affiche = grapheAffiche.value
  const calcule = grapheCalcule.value
  if (affiche === null || calcule === null || affiche === calcule) return null

  const effet = effetDeLaReorganisation(affiche, calcule)

  return effet.deplacees === 0 ? null : effet
})

const phraseDuBoutonReorganiser = computed(() => {
  const effet = effetDeLaReorganisationCourante.value
  return effet === null ? '' : phraseDuBouton(effet)
})

/**
 * Réorganiser sur demande : on adopte la mise en page calculée, et l'annonce
 * s'efface — le lecteur vient de la provoquer, on n'a pas à la lui apprendre.
 */
function reorganiser(): void {
  const calcule = grapheCalcule.value
  if (calcule === null) return

  // L'animation n'est pas lancée d'ici : le canevas se reconstruit sur le
  // changement de `graphe` et émet « construit ». La déclencher ici la ferait
  // tourner sur le canevas d'AVANT, que la reconstruction effacerait aussitôt —
  // le défaut exact qui rendait l'animation invisible le 20/09/2026.
  etat = { graphe: calcule, annonce: null, cle: cleDeTransformation.value, animer: true }
  grapheAffiche.value = calcule
  annonceCourante.value = null
  animerLaProchaineArrivee = true
}

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

  const avant = mesurerLisibilite(brut)
  const apres = mesurerLisibilite(affiche)

  // **Les croisements sont comptés ici, en vrai, et non recopiés dans le résumé.**
  // Un nombre écrit en dur dans une phrase se périme sans prévenir — c'est arrivé
  // le 20/09/2026 : le correctif du serveur a changé les croisements, et le
  // bandeau a continué d'annoncer les anciens. 0,7 ms sur 644 nœuds, mesuré.
  const croisementsAvant = compterLesCroisements(brut)
  const croisementsApres = compterLesCroisements(affiche)

  // Les nombres sont **mis en forme ici, pas dans le gabarit** : une expression
  // dans un gabarit n'est éprouvée par aucun test de ce dépôt, et se découvre
  // à l'écran quand elle est fausse.
  const millions = (valeur: number): string => String(Math.round(valeur / 1e6))
  const pixels = (valeur: number): string => String(Math.round(valeur))

  return {
    avant,
    apres,
    croisementsAvant: String(croisementsAvant),
    croisementsApres: String(croisementsApres),
    carresAvant: millions(avant.carres),
    carresApres: millions(apres.carres),
    traitAvant: pixels(avant.traitLePlusLong),
    traitApres: pixels(apres.traitLePlusLong),
  }
})
const canevas = ref<InstanceType<typeof CanevasArbre> | null>(null)
const barre = ref<InstanceType<typeof BarreOutilsArbre> | null>(null)
/** Le champ du pied de page. Exactement un des deux exemplaires est monté —
 *  celui-ci sur téléphone, celui de la barre d'outils au large —, et c'est ce
 *  qui garantit un seul `id="arbre-recherche"` dans le document. */
const champMobile = ref<InstanceType<typeof ChampRechercheArbre> | null>(null)
const estMobile = useEstMobile()

/**
 * **Le bandeau de maquette se replie, et il est fermé d'office sur téléphone.**
 *
 * Déplié, il porte cinq mesures et deux phrases : sur un écran de 390 px il
 * occupait la page entière et l'arbre passait sous la ligne de flottaison — c'est
 * le premier retour du commanditaire après la mise en ligne. **Le supprimer n'est
 * pas une option** : sans lui, deux onglets ouverts sur deux maquettes sont
 * indiscernables, et une disposition qui ne réordonne pas se présenterait comme ne
 * faisant rien. Il se cache, il ne se tue pas.
 *
 * `null` tant que le lecteur n'a pas tranché : le bandeau **suit alors la
 * largeur**, donc il s'ouvre au large et reste fermé sur téléphone, y compris
 * après la relecture de `matchMedia` au montage. Dès que le lecteur touche le
 * bouton, c'est son choix qui vaut et la largeur ne le reprend plus — une
 * rotation d'écran ne doit pas refermer ce qu'il vient d'ouvrir.
 */
const bandeauChoisi = ref<boolean | null>(null)
const bandeauOuvert = computed(() => bandeauChoisi.value ?? !estMobile.value)

function basculerLeBandeau(): void {
  bandeauChoisi.value = !bandeauOuvert.value
}
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

/**
 * Le graphe porte-t-il des personnes sans branche ? La légende ne l'annonce
 * qu'alors — voir `LegendeArbre.vue`. Lu sur le graphe **affiché**, donc après
 * la disposition et après le filtre « ascendance seule », qui peut justement
 * retirer les derniers.
 */
const avecHorsBranche = computed(
  () =>
    graphe.value?.noeuds.some(
      (n) => n.type === 'personne' && (n as { branch?: string | null }).branch == null,
    ) === true,
)
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

/* ------------------------------------------------------------------ */
/* L'arrivée animée                                                     */
/* ------------------------------------------------------------------ */

/**
 * **Les cartes glissent de la place que le serveur leur donnait vers celle que
 * la disposition a calculée, puis s'immobilisent.**
 *
 * Elle ne tourne que sur `ressorts` : c'est la seule disposition dont le
 * mouvement raconte quelque chose — les autres réordonnent, et voir une fiche
 * traverser l'écran ne dit rien de plus que son arrivée. Un `if` plutôt qu'un
 * réglage : une maquette qui s'anime « parfois » serait indéchiffrable.
 *
 * Le canevas est bâti avec les positions FINALES ; l'animation les ramène au
 * départ puis les rend. La position finale est donc celle de la construction,
 * pas celle du dernier pas — voir `animation.ts`.
 */
const DISPOSITION_ANIMEE = 'ressorts'
let arreterLArrivee: (() => void) | null = null

function lancerLArrivee(): void {
  arreterLArrivee?.()
  arreterLArrivee = null

  if (nomDeDisposition !== DISPOSITION_ANIMEE) return
  // Une insertion locale ne se déplie pas : voir `animerLaProchaineArrivee`.
  if (!animerLaProchaineArrivee) return

  const pilote = canevas.value?.piloteCourant() ?? null
  const arrivee = graphe.value
  if (pilote === null || arrivee === null) return

  // **L'arbre part replié sur le lecteur et se déplie.** Partir de la mise en page
  // du serveur était invisible : dans le cadre que le téléphone ouvre, la moitié
  // des nœuds bougeaient de moins de vingt pixels et le tronc, figé, pas du tout.
  // Voir `replie()` pour les chiffres.
  arreterLArrivee = animerLArrivee(pilote, replie(arrivee), arrivee)
}

onBeforeUnmount(() => {
  // Arrêter AVANT que le canevas ne soit détruit : une image de plus écrirait
  // dans des éléments retirés du document.
  arreterLArrivee?.()
  arreterLArrivee = null
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
        v-model:ascendance-seule="ascendanceSeule"
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
        <LegendeArbre
          v-if="graphe !== null"
          :calque-actif="calqueActif"
          :avec-hors-branche="avecHorsBranche"
          en-tiroir
        />
      </template>
    </TiroirOutils>

    <!--
      **Le bandeau de la maquette.** Deux onglets ouverts sur deux dispositions
      sont indiscernables dès qu'on a fait défiler l'arbre : sans ce bandeau, on
      compare deux images dont on ne sait plus laquelle est laquelle.

      Il annonce aussi la **mesure** — couples accolés, et longueur des traits.
      Une disposition se juge sur des nombres, pas sur une impression : on peut
      rendre un arbre plus joli localement en le dégradant ailleurs, et l'œil ne le
      voit pas sur 377 nœuds.

      **Replié d'office sur téléphone**, où il mangeait la page entière. Ce qui reste
      visible est le TITRE, parce que c'est lui qui répond à « laquelle est-ce ? » ;
      le reste se déplie à la demande.

      **Deux familles de nombres, parce qu'une seule ne suffit pas.** Les couples
      accolés ne disent rien d'une disposition qui ne réordonne pas — elle
      afficherait « 163 → 163 » en ayant raccourci tous les traits. Et la somme des
      carrés ne dit rien d'une disposition qui réordonne. Chacune a besoin de
      l'autre pour ne pas se faire juger sur ce qu'elle ne touche pas.
    -->
    <div
      v-if="presentation !== null"
      class="border-b border-ligne bg-panneau px-4 py-2 text-xs text-texte"
    >
      <p class="flex items-baseline gap-2">
        <strong class="font-semibold">Maquette « {{ presentation.titre }} ».</strong>
        <button
          type="button"
          class="ml-auto shrink-0 text-accent underline"
          :aria-expanded="bandeauOuvert"
          aria-controls="bandeau-maquette"
          @click="basculerLeBandeau"
        >
          {{ bandeauOuvert ? 'Masquer' : 'Ce qu’elle change' }}
        </button>
      </p>
      <p v-if="bandeauOuvert" class="mt-1">
        {{ presentation.resume }}
      </p>
      <p
        v-if="bandeauOuvert && effetDeLaDisposition !== null"
        id="bandeau-maquette"
        class="mt-1 text-attenue tnum"
      >
        Fiches affichées : {{ effetDeLaDisposition.apres.personnes }} sur
        {{ effetDeLaDisposition.avant.personnes }}.

        Couples accolés : {{ effetDeLaDisposition.avant.accoles }} →
        {{ effetDeLaDisposition.apres.accoles }} sur {{ effetDeLaDisposition.apres.couples }}.
        Personnes assises entre deux époux :
        {{ effetDeLaDisposition.avant.intercales }} → {{ effetDeLaDisposition.apres.intercales }}.

        Croisements de traits : {{ effetDeLaDisposition.croisementsAvant }} →
        {{ effetDeLaDisposition.croisementsApres }}.

        Longueur des traits, somme des carrés :
        {{ effetDeLaDisposition.carresAvant }} →
        {{ effetDeLaDisposition.carresApres }} millions ; le plus long :
        {{ effetDeLaDisposition.traitAvant }} → {{ effetDeLaDisposition.traitApres }} px.
        <RouterLink :to="{ name: 'arbre' }" class="text-accent underline">
          Comparer à l’arbre d’origine
        </RouterLink>
      </p>
    </div>

    <!--
      **L'arbre a bougé : on le dit, on ne le fait pas subir.**

      Ce n'est **pas une boîte de dialogue**, et c'est une décision : l'arbre se
      regarde sur téléphone, une modale y prend l'écran entier, vole le focus et
      interrompt un geste en cours pour annoncer quelque chose qui est déjà fait.
      Un bandeau se lit en passant, se ferme d'un doigt, et laisse l'arbre visible
      pendant qu'on le lit — c'est-à-dire qu'on peut vérifier ce qu'il annonce.

      `role="status"` avec `aria-live="polite"` : la phrase est lue par un lecteur
      d'écran **à la fin de ce qu'il était en train de dire**, sans couper. Une
      alerte (`assertive`) interromprait la lecture d'une fiche pour annoncer un
      déplacement de cartes, ce qui serait une fausse urgence.

      Il **ne disparaît pas tout seul** : une annonce qui s'efface après trois
      secondes n'est pas lue par qui lisait autre chose, et l'information serait
      perdue au moment précis où elle compte.
    -->
    <p
      v-if="annonceCourante !== null"
      class="flex flex-wrap items-center gap-x-2 border-b px-4 py-1.5 text-xs text-texte"
      :class="
        annonceCourante.ton === 'reorganisation'
          ? 'border-alerte-bord bg-alerte-doux'
          : 'border-ligne bg-accent-doux'
      "
      role="status"
      aria-live="polite"
    >
      <strong v-if="annonceCourante.ton === 'reorganisation'" class="font-semibold">
        {{ annonceCourante.texte }}
      </strong>
      <span v-else>{{ annonceCourante.texte }}</span>
      <button
        type="button"
        class="ml-auto shrink-0 cursor-pointer border-0 bg-transparent p-0 text-accent underline"
        @click="annonceCourante = null"
      >
        Masquer
      </button>
    </p>

    <!--
      **Le bouton « réorganiser », et ce qu'il promet avant qu'on le presse.**

      Il n'apparaît que lorsqu'il a quelque chose à faire, et la phrase qui le
      suit est **calculée sur les deux mises en page**, pas écrite à la main : un
      nombre en dur dans une phrase se périme au premier correctif, et c'est déjà
      arrivé sur le bandeau de maquette le 20/09/2026.
    -->
    <p
      v-if="effetDeLaReorganisationCourante !== null"
      class="flex flex-wrap items-center gap-x-2 border-b border-ligne bg-panneau px-4 py-1.5 text-xs text-attenue"
    >
      <button
        type="button"
        class="cursor-pointer border-0 bg-transparent p-0 font-semibold text-accent underline"
        @click="reorganiser"
      >
        Réorganiser l’arbre
      </button>
      <span class="tnum">{{ phraseDuBoutonReorganiser }}</span>
    </p>

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
        :cadrage-initial="estMobile ? 'tronc' : 'tout'"
        @construit="lancerLArrivee"
        @selection="ouvrirNoeud"
        @fond="effacerSelection"
      />

      <LegendeArbre
        v-if="graphe !== null"
        :calque-actif="calqueActif"
        :avec-hors-branche="avecHorsBranche"
      />

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
