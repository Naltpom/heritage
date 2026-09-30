<script setup lang="ts">
/**
 * Fiche d'une personne — § 5.3, § 11.1, § 13 du contrat.
 *
 * Trois règles qui décident de tout l'écran :
 *
 * - **Absent contre `null`** (§ 1.3). `'birth' in fiche === false` veut dire
 *   *interdit* : on n'affiche **pas la ligne du tout**, jamais un « — ». Un
 *   `fiche.birth === null` veut dire *inconnu* : la ligne existe, la valeur est
 *   vide. Le premier est une règle de confidentialité, le second une lacune de
 *   la donnée, et les confondre efface la distinction.
 * - **Fiche réduite, pas erreur.** Une personne vivante hors cercle rend `200`
 *   avec sept clés — son nœud est visible dans le graphe, cliquer dessus doit
 *   donner un nom et une impasse claire, pas une panne apparente.
 * - **Le calque « en attente »** (§ 13.2) : si `enAttente[champ]` existe,
 *   afficher `propose` avec la pastille « en attente de validation » et rendre
 *   `actuel` accessible ; sinon afficher `fiche[champ]`. La valeur validée reste
 *   dans le corps principal — **le calque est à côté, jamais à la place**.
 *   `actuel` peut différer de `fiche[champ]` : c'est ce qui annonce une caducité
 *   à venir, il ne faut pas supposer qu'ils sont égaux.
 *
 * Et les fourchettes de dates ne s'aplatissent pas : « entre 1901 et 1909 »
 * s'affiche tel quel, jamais « 1901 ».
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Ce que cette vue fait, et ce qu'elle délègue
 *
 * Elle charge **une** route — `GET /api/arbre/personnes/{id}` —, discrimine la
 * charge utile **une fois**, lit l'onglet dans la requête et tient la loupe.
 * Tout le reste est délégué :
 *
 * - `PageFiche` dessine une fiche complète : bandeau, onglets (Identité et
 *   famille · Sources et documents · Historique), contenu qui coulisse d'un
 *   onglet à l'autre, colonne « Dans l'arbre », barre d'action mobile ;
 * - `CarteRestreinte` dessine une fiche réduite : un nom, une raison, une
 *   sortie — ni onglets, ni barre d'action ;
 * - les trois règles ci-dessus vivent dans `components/personne/champs.ts`, un
 *   module pur que `champs.test.ts` éprouve sans monter de composant.
 *
 * ## L'onglet voyage dans la requête
 *
 * `?onglet=sources | historique`, Identité par défaut
 * (`components/personne/onglets.ts`) ; les anciennes clés — `famille`,
 * `documents`, `demandes` — ouvrent l'onglet qui les a absorbées. **Aucun
 * nouveau nom de route** : celui de la fiche, `personne.detail`, est un contrat
 * avec les notifications. Changer d'onglet ne recharge pas la fiche — seul
 * l'identifiant est surveillé.
 *
 * `#depot` (« Joindre un document ») amène la zone de dépôt de la galerie à
 * l'écran : c'est la vue qui lit la route, c'est donc elle qui y défile.
 *
 * La galerie de preuves charge ses propres médias (§ 7.3) : la fiche ne porte
 * que la vue **validée**, et la moitié de ce qu'il faut montrer est justement ce
 * qui attend une décision. Voir `GalerieMedias.vue`.
 *
 * ## Le rôle du lecteur choisit la route, jamais l'affichage (§ 10.4)
 *
 * Une fois la charge reçue, **rien ici ne teste le rôle pour décider quoi
 * dessiner** : chaque bloc s'affiche parce que la charge le porte. Un
 * `v-if="administrateur"` devant un champ que la réponse n'a pas envoyé serait
 * la version front du `if ($estAdmin) { return $tout; }` qu'il a fallu retirer
 * du serveur — et il n'afficherait de toute façon qu'un `undefined`.
 *
 * Seules les **actions réservées** se cachent, et aucune n'est décidée ici :
 * chacune porte sa condition dans `components/personne/gestes.ts`, et la
 * suppression reste un geste séparé, derrière un filet du menu « Proposer ».
 *
 * ## Ce qui n'est volontairement pas branché
 *
 * `GET /api/demandes/historique/personne/{id}` (§ 6.11) : c'est une route du
 * module `Proposal`, dont la fonction d'appel appartient à
 * `lib/api/propositions.ts`. À la place, `demandesEnAttente` — que la fiche
 * porte déjà — renvoie vers `demande.detail`, qui existe.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { lireFichePersonne, type FichePersonne, type FichePersonneComplete } from '@/lib/api'
import { useAppelApi } from '@/composables/useAppelApi'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import CarteRestreinte from '@/components/personne/CarteRestreinte.vue'
import PageFiche from '@/components/personne/PageFiche.vue'
import { ongletDepuisRequete, type OngletFiche } from '@/components/personne/onglets'
import LoupeMedia from '@/components/medias/LoupeMedia.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import { vueARetrouver } from '@/histoire/retour'

const route = useRoute()

/** Venu de l'arbre : « Retour à l'arbre » y ramène, sur la vue exacte qu'on
 *  avait quittée. Le lien est là dans tous les cas ; ceci n'en change que
 *  l'infobulle. */
const retourArbre = vueARetrouver()

/** Le routeur contraint déjà `:id(\d+)` : un identifiant provisoire (`#12/p1`)
 *  ne correspond à aucune route côté API et n'en a pas ici non plus (§ 13.4). */
const identifiant = computed(() => Number(route.params['id']))

const {
  donnees: fiche,
  erreur,
  chargement,
  executer,
} = useAppelApi<FichePersonne>(() => lireFichePersonne(identifiant.value))

/**
 * La discrimination, faite **une fois** pour tout le gabarit.
 *
 * `FichePersonne` est une union discriminée sur `visibilite`, et le typeur
 * refuse `fiche.birth` tant qu'on n'a pas discriminé. C'est délibéré : sans
 * cela, `fiche.birth ?? '—'` compilerait et afficherait un tiret — « on ne sait
 * pas » — là où la vérité est « vous n'avez pas le droit de savoir ».
 */
const complete = computed<FichePersonneComplete | null>(() =>
  fiche.value !== null && fiche.value.visibilite === 'complete' ? fiche.value : null,
)

const restreinte = computed(() =>
  fiche.value !== null && fiche.value.visibilite === 'restreinte' ? fiche.value : null,
)

/** L'onglet ouvert, lu dans `?onglet=`. Identité par défaut, et aussi pour
 *  `?onglet=historique` sur une fiche qui n'en porte plus. */
const onglet = computed<OngletFiche>(() =>
  complete.value === null ? 'identite' : ongletDepuisRequete(route.query['onglet'], complete.value),
)

/** Une seule image d'origine décodée à la fois, quel que soit le nombre de
 *  vignettes à l'écran. La loupe est tenue ici pour cette raison. */
const loupe = ref<CibleLoupe | null>(null)

/*
 * « Joindre un document » mène à `?onglet=sources#depot`. Le routeur remonte en
 * haut de page à chaque navigation (`router/index.ts`), et la galerie n'existe
 * qu'une fois la fiche chargée et le nouvel onglet entré : on attend donc la
 * fin du coulissement (260 ms) avant d'amener la zone de dépôt à l'écran — le
 * bas du bloc, là où est le formulaire.
 */
let minuterieDepot: ReturnType<typeof setTimeout> | undefined
watch(
  () => [route.hash, onglet.value, complete.value !== null] as const,
  ([ancre, ouvert, chargee]) => {
    if (typeof document === 'undefined' || ancre !== '#depot' || ouvert !== 'sources' || !chargee) return
    clearTimeout(minuterieDepot)
    minuterieDepot = setTimeout(() => {
      document.getElementById('depot')?.scrollIntoView({
        behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'end',
      })
    }, 300)
  },
  { immediate: true, flush: 'post' },
)
onBeforeUnmount(() => clearTimeout(minuterieDepot))

// Changer de fiche pendant qu'une loupe est ouverte laisserait à l'écran
// l'image de la fiche précédente.
watch(identifiant, () => {
  loupe.value = null
  void executer()
}, { immediate: true })
</script>

<template>
  <div class="w-full">
    <!-- Chargement : la place du contenu à venir, pas une page blanche. -->
    <div v-if="chargement && fiche === null" class="mx-auto w-full max-w-[1040px] px-8 py-6 max-md:px-4">
      <PanneauBase>
        <p class="mb-3 text-sm text-attenue" role="status">Chargement de la fiche…</p>
        <SqueletteChargement :lignes="6" />
      </PanneauBase>
    </div>

    <!-- Une vraie erreur : `404` sur un identifiant qui n'existe pas, réseau,
         session perdue. Une fiche réduite n'arrive **jamais** ici — elle est un
         `200`, et c'est tout le point du § 5.1. -->
    <div v-else-if="erreur !== null" class="mx-auto w-full max-w-[1040px] px-8 py-6 max-md:px-4">
      <MessageErreur :erreur="erreur" @action="executer" />
    </div>

    <!-- L'impasse assumée : sept clés, un nom, une raison, une sortie. -->
    <CarteRestreinte v-else-if="restreinte !== null" :fiche="restreinte" />

    <PageFiche
      v-else-if="complete !== null"
      :fiche="complete"
      :onglet="onglet"
      :retour-memorise="retourArbre"
      @agrandir="loupe = $event"
    />
  </div>

  <LoupeMedia :cible="loupe" @fermer="loupe = null" />
</template>
