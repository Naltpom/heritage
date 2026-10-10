<script setup lang="ts">
/**
 * **Le volet « Ajouter une personne » de `/arbre`** — à gauche, sur PC (demande du
 * 09/10/2026 : « un bouton qui ouvre ce formulaire dans un volet à gauche, pour
 * continuer à naviguer dans l'arbre »).
 *
 * C'est le formulaire de la page `/personnes/proposer`, le même composant
 * (`FormulaireCreationPersonne`, `contexte="volet"`), posé dans 440 px à côté du
 * canevas. On y saisit la personne pendant qu'on cherche ses parents dans l'arbre.
 *
 * ────────────────────────────────────────────────────────────────────
 * **La saisie survit à la fermeture.** L'arbre monte le volet au premier clic
 * (`v-if`) puis le masque ou l'affiche (`v-show`) : fermer ne démonte rien, et la
 * croix le dit (« votre saisie est gardée »). « Effacer », discret, repart de zéro
 * après confirmation — une saisie de vingt champs ne se perd pas d'un clic.
 *
 * **Après le dépôt**, le volet ne s'en va pas : il confirme sur place (« Proposition
 * envoyée — demande n° X »), et l'arbre, redessiné, choisit la nouvelle personne.
 * Ce que l'arbre en a trouvé lui revient par `place`. Le fermer à ce moment-là
 * (`ouvert` repasse à `false`), ou « Ajouter une autre personne », rend un
 * formulaire vierge.
 *
 * **Il ne navigue pas.** « Refaire cette demande » recharge le formulaire ici, sur
 * la demande morte, au lieu de partir vers la page ; seuls « Voir la demande » et
 * les liens qu'on choisit de suivre quittent l'arbre.
 *
 * **Les ressemblances passent par lui** (« Est-ce déjà… ? ») : « Voir dans
 * l'arbre » remonte en `voir-personne` / `voir-proposition`, et l'arbre répond s'il
 * a pu la montrer ; `en-evidence` lui dit quoi cercler en pointillé. Une fiche
 * complétée (« C'est elle ») se confirme ici comme une proposition, un lien par
 * demande déposée.
 * ────────────────────────────────────────────────────────────────────
 *
 * L'`aside` porte `data-volet-proposition` : les raccourcis clavier de l'arbre se
 * taisent dans tout le volet (`toucheIgnoree`, `voletProposition.ts`).
 *
 * ────────────────────────────────────────────────────────────────────
 * **« Compléter » une proposition en attente** (ADR 0017). Le volet droit de
 * l'arbre, sur un nœud en pointillé dont le lecteur est l'auteur — ou s'il est
 * administrateur —, offre « Compléter » : l'arbre ouvre ce volet avec `completer`,
 * et le formulaire passe en mode complétion sur cette fiche. Une saisie en cours y
 * est mise de côté, pas perdue : « Revenir à une nouvelle personne » la rend, et
 * la confirmation offre de la reprendre. Le titre suit le mode.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, ref, shallowRef, useTemplateRef, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import FormulaireCreationPersonne from '@/components/proposition/FormulaireCreationPersonne.vue'
import type { PersonneDeposee } from '@/components/proposition/depot'
import type { DemandeDeCompletion } from '@/components/proposition/fusion'
import type { EnEvidence } from '@/components/proposition/ressemblances'
import { confirmationDuDepot, type PlaceDansLArbre } from './voletProposition'

const { ouvert = true, place = 'recherche', completer = null } = defineProps<{
  /** Ouvert par le lecteur — masqué ou non par la largeur de la fenêtre. */
  ouvert?: boolean
  /** Où l'arbre redessiné a trouvé la dernière personne déposée. */
  place?: PlaceDansLArbre
  /** La proposition à compléter, demandée par l'arbre — un jeton par clic. */
  completer?: DemandeDeCompletion | null
}>()

const emit = defineEmits<{
  /** La croix, ou « Fermer » après un dépôt. */
  fermer: []
  /** Le dépôt est fait, photos comprises : à l'arbre de se redessiner. Une fiche
   *  complétée (« C'est elle ») arrive aussi par là, `nature: 'fiche'`. */
  deposee: [depot: PersonneDeposee]
  /** Relayés du formulaire : montrer une ressemblance dans l'arbre, sans quitter
   *  `/arbre` — l'arbre répond si elle y est dessinée. */
  'voir-personne': [personId: number, repondre: (dessinee: boolean) => void]
  'voir-proposition': [noeudId: string, repondre: (dessinee: boolean) => void]
  /** Relayé du formulaire : les ressemblances que l'arbre cercle en pointillé. */
  'en-evidence': [cibles: EnEvidence]
}>()

const formulaire = useTemplateRef<InstanceType<typeof FormulaireCreationPersonne>>('formulaire')

/** La dernière demande déposée, tant qu'on n'est pas reparti d'une saisie vierge. */
const confirmation = shallowRef<PersonneDeposee | null>(null)
const texte = computed(() => (confirmation.value === null ? null : confirmationDuDepot(confirmation.value, place)))

/** Il y a quelque chose à effacer : le formulaire le dit (`sale`). */
const sale = ref(false)
const confirmerEffacement = ref(false)

/**
 * La demande morte à reprendre, et la clé qui remonte le formulaire : il ne lit
 * `refaire` qu'à sa création, comme la page lisait `?refaire=`.
 */
const repriseDe = ref<number | null>(null)
const cle = ref(0)

/** Le formulaire complète une proposition : le titre le dit. */
const enCompletion = ref(false)

function surDepot(depot: PersonneDeposee): void {
  confirmation.value = depot
  confirmerEffacement.value = false
  emit('deposee', depot)
}

/**
 * Après une **complétion**, le formulaire a déjà rendu la saisie mise de côté (ou
 * repris à zéro si elle avait été versée dans la proposition) : on ne l'efface
 * pas, on la remontre. Après une création, on repart d'une saisie vierge.
 */
const apresCompletion = computed(() => confirmation.value?.nature === 'completion')

function continuer(): void {
  if (apresCompletion.value) {
    confirmation.value = null
    confirmerEffacement.value = false
    return
  }
  repartirDeZero()
}

/**
 * La complétion que le formulaire doit ouvrir — **une fois** : elle tombe quand il
 * en sort, et avant qu'il soit remonté sur une demande à refaire, sans quoi il la
 * rouvrirait à son montage.
 */
const aCompleter = shallowRef<DemandeDeCompletion | null>(null)

/*
 * L'arbre demande une complétion (« Compléter » sur un nœud) : une confirmation
 * encore affichée s'efface d'abord — et le formulaire d'une création faite repart
 * d'une saisie neuve, sans quoi il resterait occupé par ses photos envoyées.
 */
watch(
  () => completer?.jeton ?? null,
  (jeton) => {
    if (jeton === null || completer === null) return
    if (confirmation.value !== null) continuer()
    aCompleter.value = completer
  },
  { immediate: true },
)

function surCompletion(cible: DemandeDeCompletion | null): void {
  enCompletion.value = cible !== null
  if (cible === null) aCompleter.value = null
}

function surRefaire(demandeId: number): void {
  repriseDe.value = demandeId
  sale.value = false
  aCompleter.value = null
  enCompletion.value = false
  cle.value++
}

/** Une saisie vierge : après un dépôt, ou sur « Effacer ». */
function repartirDeZero(): void {
  formulaire.value?.reinitialiser()
  confirmation.value = null
  confirmerEffacement.value = false
  repriseDe.value = null
}

function fermer(): void {
  confirmerEffacement.value = false
  emit('fermer')
}

/*
 * Fermé — par la croix, « Fermer » ou le bouton de la barre : après un dépôt, la
 * prochaine ouverture repart d'une saisie vierge (le bouton dit « Ajouter une
 * personne », pas « revoir la dernière ») ; sinon la saisie reste telle quelle,
 * masquée avec le volet. Une fenêtre rétrécie sous `lg` masque le volet sans le
 * fermer : rien ne change alors.
 */
watch(
  () => ouvert,
  (estOuvert) => {
    if (estOuvert) return
    confirmerEffacement.value = false
    if (confirmation.value !== null) continuer()
  },
)
</script>

<template>
  <aside
    id="volet-proposition"
    data-volet-proposition
    aria-label="Ajouter une personne"
    class="flex w-[440px] flex-none flex-col border-r border-ligne bg-panneau"
  >
    <header class="flex flex-none flex-col gap-2 border-b border-ligne px-4 py-3.5">
      <div class="flex items-start gap-2">
        <div class="flex flex-1 flex-col leading-snug">
          <h2 class="text-[17px] font-semibold">{{ enCompletion ? 'Compléter une proposition' : 'Ajouter une personne' }}</h2>
          <span class="text-xs text-attenue">
            {{
              enCompletion
                ? 'Vos compléments s’ajoutent à la proposition en attente. L’arbre reste à côté : cherchez-y ses proches.'
                : 'Votre proposition part en validation. L’arbre reste à côté : cherchez-y ses proches pendant la saisie.'
            }}
          </span>
        </div>
        <button
          v-if="sale && confirmation === null && !confirmerEffacement"
          type="button"
          data-effacer
          class="h-8 px-1.5 text-xs text-attenue hover:text-texte hover:underline"
          title="Effacer toute la saisie et repartir de zéro"
          @click="confirmerEffacement = true"
        >
          Effacer
        </button>
        <button
          type="button"
          aria-label="Fermer"
          :title="confirmation === null ? 'Fermer — votre saisie est gardée' : 'Fermer'"
          class="-mt-1.5 -mr-2 h-8 w-8 text-lg text-attenue hover:text-texte"
          @click="fermer"
        >
          ×
        </button>
      </div>
      <div v-if="confirmerEffacement" class="flex flex-wrap items-center gap-2 text-sm" role="alert">
        <span class="mr-auto">Effacer toute la saisie ?</span>
        <BoutonBase variante="danger" @click="repartirDeZero">Effacer</BoutonBase>
        <BoutonBase variante="discret" @click="confirmerEffacement = false">Garder</BoutonBase>
      </div>
    </header>

    <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-4 py-4">
      <section v-if="confirmation !== null && texte !== null" class="flex flex-col gap-3" data-confirmation-depot>
        <!-- `role="status"` : la phrase change quand l'arbre a trouvé la personne. -->
        <div class="flex flex-col gap-1.5 rounded-panneau border border-accent bg-accent-doux px-3.5 py-3" role="status">
          <h3 class="m-0 text-[15px] font-semibold">{{ texte.titre }}</h3>
          <p class="m-0 text-sm">{{ texte.phrase }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <!-- Une fiche complétée en dépose plusieurs : un lien par demande. -->
          <RouterLink
            v-for="id in confirmation.demandeIds"
            :key="id"
            :to="{ name: 'demande.detail', params: { id } }"
            class="inline-flex items-center justify-center rounded-champ border border-ligne bg-panneau px-3.5 py-1.5 text-sm font-semibold text-texte transition-colors hover:border-accent"
          >
            {{ confirmation.demandeIds.length > 1 ? `Voir la demande n° ${id}` : 'Voir la demande' }}
          </RouterLink>
          <!-- Après une complétion, la saisie mise de côté revient telle quelle. -->
          <BoutonBase @click="continuer">
            {{ apresCompletion && sale ? 'Reprendre votre saisie' : 'Ajouter une autre personne' }}
          </BoutonBase>
          <BoutonBase variante="discret" @click="fermer">Fermer</BoutonBase>
        </div>
      </section>

      <!-- Masqué, pas démonté, pendant la confirmation : `reinitialiser()` le
           rendra vierge à la demande suivante. -->
      <div v-show="confirmation === null" class="flex flex-col">
        <FormulaireCreationPersonne
          :key="cle"
          ref="formulaire"
          contexte="volet"
          :refaire="repriseDe"
          :completer="aCompleter"
          @deposee="surDepot"
          @refaire="surRefaire"
          @completion="surCompletion"
          @sale="(valeur) => (sale = valeur)"
          @voir-personne="(personId, repondre) => emit('voir-personne', personId, repondre)"
          @voir-proposition="(noeudId, repondre) => emit('voir-proposition', noeudId, repondre)"
          @en-evidence="(cibles) => emit('en-evidence', cibles)"
        />
      </div>
    </div>
  </aside>
</template>
