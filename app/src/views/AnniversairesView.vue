<script lang="ts">
/**
 * La décision de cet écran qui se prouve **sans navigateur**.
 *
 * Elle est dans un bloc `<script>` ordinaire, et non dans le `<script setup>`
 * ci-dessous, pour la raison mécanique qu'explique `ArbreView` : un
 * `<script setup>` se compile en une fermeture sur l'état du composant, que rien
 * ne peut appeler du dehors. Or ce que cette fonction décide — remplacer
 * l'écran, ou poser un bandeau au-dessus de lui — ne se voit pas en lisant le
 * gabarit, et se casse sans bruit. Sa suite est `anniversaires-rendu.test.ts`.
 */
import type { VueAnniversaires } from '@/lib/api'

export interface EtatDeLEcran {
  /** Ce que le corps de l'écran montre. */
  corps: 'squelette' | 'panne' | 'contenu'
  /** Un bandeau d'erreur d'une ligne, **au-dessus** d'un contenu qui reste. */
  bandeau: boolean
}

/**
 * Ce que l'écran montre, selon ce qu'il a et ce qui a échoué.
 *
 * ────────────────────────────────────────────────────────────────────
 * **UN CALENDRIER JUSTE N'EST JAMAIS REMPLACÉ PAR UN MESSAGE D'ERREUR.**
 *
 * Une charge en main et un appel qui échoue ensuite — un « Réessayer » sur un
 * réseau coupé, un retour d'onglet — ne rendent pas faux ce qui est déjà peint :
 * l'année entière est arrivée, les filtres et les deux flèches sont du rendu
 * pur, et le mois affiché reste exact. Effacer la grille pour y mettre une
 * alerte ferait perdre au lecteur une donnée correcte à cause d'un appel qui ne
 * lui apportait rien de neuf.
 *
 * L'erreur est donc dite sur une ligne, au-dessus, et le calendrier reste. C'est
 * la règle de `ChronologieView`, et c'est la même ici.
 *
 * Symétriquement, une erreur **sans** rien en main remplace bien l'écran : il
 * n'y a rien à préserver, et un squelette qui tourne pour toujours serait pire
 * qu'un message.
 * ────────────────────────────────────────────────────────────────────
 */
export function etatDeLEcran(vue: VueAnniversaires | null, erreur: unknown): EtatDeLEcran {
  const enPanne = erreur !== null && erreur !== undefined
  if (vue !== null) return { corps: 'contenu', bandeau: enPanne }
  return { corps: enPanne ? 'panne' : 'squelette', bandeau: false }
}
</script>

<script setup lang="ts">
/**
 * Anniversaires — le calendrier du mois (§ 20 du contrat).
 *
 * ────────────────────────────────────────────────────────────────────
 * CE QUI SE DÉCIDE ICI, ET NULLE PART AILLEURS :
 *
 * 1. **Un seul appel, au montage, et plus jamais.** `GET /api/anniversaires`
 *    rend l'**année entière** — voir l'en-tête de `lib/api/evenements.ts`. Les
 *    cinq pastilles et les deux flèches sont des réglages de **rendu** : elles
 *    ne redemandent rien, et le geste est instantané. C'est la différence avec
 *    la chronologie, dont `bande` et `branches` changent ce que le serveur
 *    calcule ; cet écran n'a donc pas de `cleDeDemande()`, et n'en veut pas.
 *
 * 2. **L'année affichée est celle du calendrier, pas celle des événements.** On
 *    parcourt les mois d'une année civile, et chaque événement garde la sienne.
 *    « il y a 123 ans » est un écart calculé entre les deux, jamais une date.
 *
 * 3. **Le mois de départ est le mois courant, lu au montage.** `moisCourant()`
 *    ne touche l'horloge qu'à l'appel — un `new Date()` de portée module ferait
 *    dépendre toute la suite de tests du jour où elle tourne.
 *
 * 4. **Changer de mois referme le panneau.** Les clés de jour portent le mois
 *    (`03-14`), donc une sélection ne survit jamais à un changement ; la
 *    refermer explicitement évite qu'elle réapparaisse au retour, ce qui se
 *    lirait comme un panneau surgi de nulle part.
 *
 * 5. **Les touches n'acceptent aucun modificateur.** `Alt`+flèche est le
 *    « Précédent » du navigateur, `Ctrl`+Origine le haut du document : les
 *    détourner ferait perdre au lecteur deux gestes qui ne nous appartiennent
 *    pas. `ChronologieView` n'a pas ce garde-fou parce que ses touches — `0` et
 *    `Échap` — ne sont l'ombre d'aucun raccourci.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Ce qu'il ne faut pas prendre pour un bogue.** Une personne vivante hors du
 * cercle du lecteur ne produit **aucun** événement : ni case, ni nom, ni
 * compteur. Elle n'est pas réduite, elle est absente — la décision du § 9.1,
 * reprise mot pour mot par la charge. Un total « sur N » n'existe donc nulle
 * part : il dirait par soustraction combien de personnes le lecteur ne voit pas.
 *
 * **Ce que l'écran ne tait pas.** Les événements dont personne n'a noté le jour
 * ne peuvent tenir dans aucune case ; ils sont comptés et détaillés par la barre
 * d'écran (« {n} sans jour connu ▾ »). Les 29 février d'une année ordinaire n'ont pas de case non plus ;
 * ils sont listés sous la grille de février, et jamais déplacés au 28 ou au 1er
 * mars. Un calendrier qui laisse tomber en silence ce qu'il ne sait pas placer
 * se lit comme un inventaire, ce qu'il n'est pas.
 *
 * **La hauteur du cadre vient de `views/cadre.ts`**, et cet écran ne la refait
 * pas dans son coin. Les trois défauts que cette fonction corrige — mesurer la
 * fenêtre au lieu de la mise en page, arrondir au plus proche au lieu du
 * plancher, et imposer un minimum qui garantit le débordement dès qu'il reste
 * moins que lui — ont déjà été payés deux fois dans ce dépôt. `cadre.test.ts`
 * lit le source de ce fichier pour qu'il n'y ait pas de troisième fois.
 *
 * **La barre d'écran** (`BarreOutilsAnniversaires`, posée dans `BarreEcran`)
 * porte désormais aussi ‹ {mois} › et « Aujourd’hui » : les flèches ont quitté
 * le haut de la grille. Elle ne retient rien — le mois, les genres et le nombre
 * de filtres actifs sont calculés ici, et le résumé d'une ligne de l'en-tête
 * mobile (« 31 événements datés ») est publié ici aussi.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { usePorteeExplorer } from '@/composables/usePorteeExplorer'
import { hauteurDuCadre } from '@/views/cadre'
import { useTiroirs } from '@/composables/useTiroirs'
import { definirResume } from '@/composables/entete'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { GENRES, basculerGenre, compterRetenus, genresParDefaut } from '@/components/evenements/filtres'
import BarreOutilsAnniversaires from '@/components/anniversaires/BarreOutilsAnniversaires.vue'
import GrilleMois from '@/components/anniversaires/GrilleMois.vue'
import LigneEvenement from '@/components/anniversaires/LigneEvenement.vue'
import PanneauJour from '@/components/anniversaires/PanneauJour.vue'
import { grilleDuMois } from '@/components/anniversaires/calendrier'
import {
  moisCourant,
  moisPrecedent,
  moisSuivant,
  titreDuMois,
  type MoisAffiche,
} from '@/components/anniversaires/mois'
import { horsGrille, parJour } from '@/components/anniversaires/regroupement'
/* `VueAnniversaires` est déjà importé par le bloc `<script>` ci-dessus, dont les
   liaisons sont dans la portée de ce `<script setup>` : le réimporter ici ferait
   un doublon que le typeur refuse. Même situation que dans `ArbreView`. */
import { chargerAnniversaires, type GenreEvenement } from '@/lib/api'

const vue = shallowRef<VueAnniversaires | null>(null)
const erreur = shallowRef<unknown>(null)
const chargement = ref(false)

/** Remplacé en bloc, jamais muté — `basculerGenre()` rend un nouvel ensemble, et
 *  c'est ce que `shallowRef` attend pour se déclencher. */
const actifs = shallowRef(genresParDefaut())
const affiche = ref<MoisAffiche>(moisCourant())
const selection = ref<string | null>(null)

const racine = ref<HTMLElement | null>(null)

/* ------------------------------------------------------------------ */
/* Chargement — une fois, au montage                                   */
/* ------------------------------------------------------------------ */

// La bascule « Mon arbre / Toute la base » d'Explorer (administration) : la
// changer recharge l'écran.
const { portee } = usePorteeExplorer()
let abandon: AbortController | null = null

async function charger(): Promise<void> {
  abandon?.abort()
  const controleur = new AbortController()
  abandon = controleur

  chargement.value = true
  erreur.value = null

  try {
    const reponse = await chargerAnniversaires(controleur.signal, portee.value)
    if (controleur.signal.aborted) return
    vue.value = reponse
  } catch (cause) {
    if (!controleur.signal.aborted) erreur.value = cause
  } finally {
    if (!controleur.signal.aborted) chargement.value = false
  }
}

watch(portee, () => void charger())

const etat = computed(() => etatDeLEcran(vue.value, erreur.value))

/* ------------------------------------------------------------------ */
/* Ce que le mois affiché devient                                      */
/* ------------------------------------------------------------------ */

const grille = computed(() => grilleDuMois(affiche.value.annee, affiche.value.mois))
const jours = computed(() => parJour(vue.value?.jours ?? [], actifs.value))
const titre = computed(() => titreDuMois(affiche.value))

/**
 * Les 29 février sans case, listés **sous le mois de février** et nulle part
 * ailleurs. `horsGrille()` ne sait pas quel mois est affiché ; énumérés sous
 * juillet, ces événements seraient une énigme.
 */
const sansCase = computed(() =>
  affiche.value.mois === 2
    ? horsGrille(vue.value?.jours ?? [], affiche.value.annee, actifs.value)
    : [],
)

const celluleSelectionnee = computed(
  () =>
    grille.value.find((cellule) => cellule.dansLeMois && cellule.cle === selection.value) ?? null,
)

const evenementsDuJour = computed(() =>
  selection.value === null ? [] : (jours.value.get(selection.value) ?? []),
)

/* ------------------------------------------------------------------ */
/* Gestes                                                              */
/* ------------------------------------------------------------------ */

function surGenre(genre: GenreEvenement): void {
  actifs.value = basculerGenre(actifs.value, genre)
}

/** « Réinitialiser » du feuillet : les genres reviennent au défaut. Le mois,
 *  lui, n'est pas un filtre — il ne bouge pas. */
function surReinitialiser(): void {
  actifs.value = genresParDefaut()
}

/**
 * Le nombre de filtres actifs : les genres dont l'état **s'écarte du défaut**
 * (allumé alors qu'il est éteint par défaut, ou l'inverse). Le défaut lui-même
 * ne compte pas pour un filtre — sinon la pastille du bouton filtres dirait
 * « 1 » à quelqu'un qui n'a encore rien touché.
 */
const filtresActifs = computed(() => {
  const defaut = genresParDefaut()
  return GENRES.filter((genre) => actifs.value.has(genre.id) !== defaut.has(genre.id)).length
})

/** Le résumé d'une ligne sous le titre, dans l'en-tête du téléphone — le même
 *  compte que la barre, qui n'y est pas visible. */
const resumeEntete = computed(() => {
  if (vue.value === null) return ''
  const n = compterRetenus(vue.value.resume.parGenre, actifs.value)
  return `${n} événement${n > 1 ? 's' : ''} daté${n > 1 ? 's' : ''}`
})
watch(resumeEntete, (texte) => definirResume(texte), { immediate: true })

function surPrecedent(): void {
  affiche.value = moisPrecedent(affiche.value)
}

function surSuivant(): void {
  affiche.value = moisSuivant(affiche.value)
}

function surAujourdhui(): void {
  affiche.value = moisCourant()
}

function surJour(cle: string): void {
  selection.value = selection.value === cle ? null : cle
}

// Décision 4 de l'en-tête : une sélection ne traverse pas un changement de mois.
watch(affiche, () => {
  selection.value = null
})

/* ------------------------------------------------------------------ */
/* Cadre et clavier                                                    */
/* ------------------------------------------------------------------ */

const hauteurCadre = ref('70vh')

function mesurerHauteur(): void {
  const element = racine.value
  if (element === null) return
  hauteurCadre.value = hauteurDuCadre(element.getBoundingClientRect().top)
}

const { unTiroirEstOuvert } = useTiroirs()

function surToucheGlobale(ev: KeyboardEvent): void {
  const cible = ev.target
  if (cible instanceof HTMLInputElement || cible instanceof HTMLTextAreaElement) return
  // Un tiroir ouvert prend la main : il se ferme lui-même, et Échap ne doit pas
  // faire deux choses d'un seul geste. Même raison que dans `ArbreView`.
  if (unTiroirEstOuvert()) return
  // Décision 5 : aucun modificateur. `Alt`+flèche appartient au navigateur.
  if (ev.altKey || ev.ctrlKey || ev.metaKey || ev.shiftKey) return

  if (ev.key === 'ArrowLeft') surPrecedent()
  else if (ev.key === 'ArrowRight') surSuivant()
  else if (ev.key === 'Home') surAujourdhui()
  else if (ev.key === 'Escape') selection.value = null
  else return

  ev.preventDefault()
}

/**
 * Explorer (`ExplorerView`) appelle `remesurer()` quand cet écran a fini de
 * glisser en place. La mesure du montage est déjà juste — l'écran sortant a
 * quitté le flux —, mais une remesure une fois posé ne coûte rien et rattrape
 * ce que le mouvement aurait pu fausser.
 */
defineExpose({ remesurer: mesurerHauteur })

onMounted(() => {
  mesurerHauteur()
  window.addEventListener('resize', mesurerHauteur)
  window.addEventListener('keydown', surToucheGlobale)
  void charger()
})

onBeforeUnmount(() => {
  abandon?.abort()
  window.removeEventListener('resize', mesurerHauteur)
  window.removeEventListener('keydown', surToucheGlobale)
})
</script>

<template>
  <div ref="racine" class="flex flex-col" :style="{ height: hauteurCadre }">
    <BarreOutilsAnniversaires
      :actifs="actifs"
      :resume="vue?.resume ?? null"
      :chargement="chargement"
      :annee="affiche.annee"
      :mois="affiche.mois"
      :filtres-actifs="filtresActifs"
      @genre="surGenre"
      @precedent="surPrecedent"
      @suivant="surSuivant"
      @aujourdhui="surAujourdhui"
      @reinitialiser="surReinitialiser"
    />

    <div v-if="etat.bandeau" class="border-b border-ligne px-4 py-2">
      <MessageErreur
        :erreur="erreur"
        titre="Le calendrier affiché reste juste, mais la mise à jour a échoué"
        @action="charger"
      />
    </div>

    <div class="relative min-h-0 flex-1 overflow-y-auto">
      <div v-if="etat.corps === 'squelette'" class="p-6">
        <SqueletteChargement :lignes="8" />
      </div>

      <div v-else-if="etat.corps === 'panne'" class="p-6">
        <MessageErreur
          :erreur="erreur"
          titre="Les anniversaires n’ont pas pu être chargés"
          @action="charger"
        />
      </div>

      <div v-else class="p-3 md:p-4">
        <GrilleMois
          :grille="grille"
          :jours="jours"
          :titre="titre"
          :selection="selection"
          @choisir="surJour"
        />

        <section v-if="sansCase.length > 0" class="mt-3">
          <PanneauBase titre="Le 29 février">
            <p class="text-xs text-attenue">
              {{ affiche.annee }} n’a pas de 29 février : ces anniversaires ne sont pas déplacés.
            </p>

            <ul class="mt-3 flex list-none flex-col gap-3">
              <li v-for="evenement in sansCase" :key="evenement.cle">
                <LigneEvenement :evenement="evenement" :annee-affichee="affiche.annee" />
              </li>
            </ul>
          </PanneauBase>
        </section>

        <PanneauJour
          v-if="celluleSelectionnee !== null && evenementsDuJour.length > 0"
          :cellule="celluleSelectionnee"
          :evenements="evenementsDuJour"
          :annee-affichee="affiche.annee"
          @fermer="selection = null"
        />
      </div>
    </div>
  </div>
</template>
