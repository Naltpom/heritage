<script setup lang="ts">
/**
 * **Proposer l'ajout d'une personne** — § 6.3, § 6.4 du dossier.
 *
 * Dans les mots du commanditaire : *« permettre aux gens de proposer de
 * nouvelles personnes à ajouter, on a l'edit et **sur ce même principe**
 * permettre d'ajouter une personne ⇒ on sélectionne les parents et/ou enfant
 * et/ou conjoints (date de mariage si on a, ou de rupture, etc.) »*.
 *
 * **« Sur ce même principe » est l'instruction centrale**, et elle décide de
 * l'architecture bien plus que de l'écran : ce formulaire ne crée rien. Il
 * dépose une `ChangeRequest` de nature `creation_personne`, sur la même route,
 * dans la même file, avec le même message obligatoire au refus. « Un seul
 * mécanisme, pas deux » (ADR 0004) — et c'est pourquoi l'identité se saisit ici
 * avec le **même composant** que sur l'écran de modification.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le formulaire lui-même vit dans `FormulaireCreationPersonne.vue`** (extrait
 * le 09/10/2026, sans changer de comportement), pour servir aussi au volet
 * « Ajouter une personne » de `/arbre`. Ce qu'il fait et ne fait pas — aucune
 * famille décrite, aucun identifiant donné, aucun brouillon bloqué — est écrit
 * là-bas. Cette page garde ce qui tient à l'adresse : l'en-tête, le motif d'une
 * écriture suspendue, `?refaire=`, le mode (assistant, question, formulaire) et
 * la navigation vers le suivi de la demande.
 * ────────────────────────────────────────────────────────────────────
 *
 * Après le dépôt, l'auteur est renvoyé vers le suivi de sa demande : c'est là
 * que se lira la décision, et **le motif s'il y a refus** — le commanditaire l'a
 * demandé explicitement, et le § 6.9 rend ce message obligatoire côté
 * administrateur.
 *
 * ────────────────────────────────────────────────────────────────────
 * **L'assistant IA est la porte d'entrée, le formulaire est caché.** La
 * famille propose surtout depuis un téléphone, et raconter « mon arrière-
 * grand-père s'appelait… » en joignant une photo d'acte y est plus naturel
 * qu'un formulaire à vingt champs (décision du 30/09/2026). L'assistant laisse
 * écrire librement, puis fait relire les demandes qu'il prépare
 * (`components/proposition/assistant/`).
 *
 * Le formulaire reste servi dans trois cas :
 *  - `?mode=formulaire` : c'est le bouton « Ajouter une personne » de l'accueil
 *    (05/10/2026 — « met moi plutôt ajouter une personne, et c'est une page
 *    formulaire ») ;
 *  - une reprise (`?refaire=`) : c'est lui qui sait reprendre une demande ;
 *  - la bascule « voir comme un membre » : l'assistant n'écrit rien lui-même,
 *    mais son analyse est une écriture pour l'API (quota, journal), qui la
 *    refuserait en `409`.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, nextTick, shallowRef, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSession } from '@/composables/useSession'
import AssistantProposition from '@/components/proposition/assistant/AssistantProposition.vue'
import QuestionsAssistant from '@/components/proposition/assistant/QuestionsAssistant.vue'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import FormulaireCreationPersonne from '@/components/proposition/FormulaireCreationPersonne.vue'
import { annonceDesDemandes, type PersonneDeposee } from '@/components/proposition/depot'
import { prefereMoinsDAnimations } from '@/lib/videos'

const route = useRoute()
const routeur = useRouter()
const { vueEmpruntee } = useSession()

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

/* --- Le mode : l'assistant, sauf reprise ou vue empruntée ------------ */

/**
 * `question` : « Poser une question » — l'assistant en **lecture seule**, une
 * conversation sur l'arbre (`?mode=question`). Il vit ici, à côté de la
 * proposition, parce que c'est le même assistant et la même porte d'entrée : on
 * demande « qui était le père de Juliette ? », on apprend qu'il manque, on le
 * propose — un onglet plus loin.
 *
 * Sous la bascule « voir comme un membre », il disparaît avec l'assistant : une
 * question écrit une conversation et consomme le quota, et l'API la refuserait
 * en `409`.
 */
type Mode = 'formulaire' | 'assistant' | 'question'

function premiereValeur(brut: unknown): string | undefined {
  const valeur = Array.isArray(brut) ? brut[0] : brut
  return typeof valeur === 'string' ? valeur : undefined
}

const mode = computed<Mode>(() => {
  if (suspendu.value || repriseDemandee.value !== null) return 'formulaire'
  const demande = premiereValeur(route.query['mode'])
  if (demande === 'formulaire') return 'formulaire'
  return demande === 'question' ? 'question' : 'assistant'
})

/** La fiche d'où l'on vient, passée à l'assistant comme contexte. */
const personneDeContexte = computed(() => {
  const valeur = Number(premiereValeur(route.query['personne']))
  return Number.isInteger(valeur) && valeur > 0 ? valeur : null
})

/** Le lien de l'onglet « Proposer » garde la fiche d'où l'on venait. */
const modeAssistant = computed(() =>
  personneDeContexte.value === null ? {} : { personne: String(personneDeContexte.value) },
)

const repriseDemandee = computed(() => {
  const brut = route.query['refaire']
  const valeur = Number(Array.isArray(brut) ? brut[0] : brut)
  return Number.isInteger(valeur) && valeur > 0 ? valeur : null
})

const formulaire = useTemplateRef<InstanceType<typeof FormulaireCreationPersonne>>('formulaire')
const bandeau = useTemplateRef<HTMLElement>('bandeau')

/** La dernière fiche complétée par « C'est elle », annoncée en tête de page. */
const ficheCompletee = shallowRef<PersonneDeposee | null>(null)

/**
 * Le dépôt est fait, photos comprises : l'auteur est renvoyé vers le suivi de sa
 * demande — c'est là que se lira la décision, et le motif s'il y a refus.
 *
 * **Sauf pour une fiche complétée** (« C'est elle ») : plusieurs demandes sont
 * parties sur une personne qui existait déjà, et aucune n'est « la » demande à
 * suivre. On reste : le bandeau les annonce, et le formulaire repart d'une saisie
 * vierge pour la personne suivante — on remonte jusqu'au bandeau.
 */
function suivre(depot: PersonneDeposee): void {
  if (depot.nature === 'fiche') {
    ficheCompletee.value = depot
    formulaire.value?.reinitialiser()
    void nextTick(() => {
      const element = bandeau.value
      if (element !== null && typeof element.scrollIntoView === 'function') {
        element.scrollIntoView({ behavior: prefereMoinsDAnimations() ? 'auto' : 'smooth', block: 'center' })
      }
    })
    return
  }
  void routeur.push({ name: 'demande.detail', params: { id: depot.demandeIds[0] } })
}

/** « Refaire cette demande » : on repart de la demande morte, pré-remplie (§ 6.10). */
function refaire(demandeId: number): void {
  void routeur.push({ name: 'proposition.creation', query: { refaire: String(demandeId) } })
}
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-8">
    <template v-if="mode === 'assistant' || mode === 'question'">
      <!-- Deux modes du même assistant. Des liens et non des boutons : chaque mode
           a son adresse, qu'on met en favori ou qu'on partage. -->
      <nav aria-label="Assistant" class="mb-4 flex gap-1 rounded-panneau border border-ligne bg-panneau p-1">
        <RouterLink
          :to="{ query: modeAssistant }"
          class="flex min-h-11 flex-1 items-center justify-center rounded-champ px-3 text-sm font-semibold md:min-h-9"
          :class="mode === 'assistant' ? 'bg-accent text-fond' : 'text-attenue hover:text-texte'"
          :aria-current="mode === 'assistant' ? 'page' : undefined"
        >
          Proposer
        </RouterLink>
        <RouterLink
          :to="{ query: { mode: 'question' } }"
          class="flex min-h-11 flex-1 items-center justify-center rounded-champ px-3 text-sm font-semibold md:min-h-9"
          :class="mode === 'question' ? 'bg-accent text-fond' : 'text-attenue hover:text-texte'"
          :aria-current="mode === 'question' ? 'page' : undefined"
        >
          Poser une question
        </RouterLink>
      </nav>

      <template v-if="mode === 'assistant'">
        <header class="mb-5">
          <h1 class="text-xl font-semibold tracking-tight">Proposer avec l’assistant</h1>
          <p class="mt-1.5 text-sm text-attenue">
            L’assistant prépare les demandes, vous relisez, un administrateur valide.
          </p>
        </header>

        <AssistantProposition :person-id="personneDeContexte" />
      </template>

      <template v-else>
        <header class="mb-5">
          <h1 class="text-xl font-semibold tracking-tight">Poser une question</h1>
          <p class="mt-1.5 text-sm text-attenue">
            L’assistant répond à partir des fiches, en les citant, et garde le fil.
          </p>
        </header>

        <QuestionsAssistant />
      </template>
    </template>

    <template v-else>
    <header class="mb-5">
      <h1 class="text-xl font-semibold tracking-tight">Ajouter une personne</h1>
      <p class="mt-1.5 text-sm text-attenue">
        Votre proposition part en validation, comme une correction de fiche. La personne
        apparaîtra dans votre arbre en attendant, signalée comme non validée — et pour vous seul.
        Vous pouvez la relier aux personnes de l’arbre comme à celles que vous avez déjà
        proposées, même pas encore validées.
      </p>
    </header>

    <p
      v-if="motifSuspendu !== null"
      class="mb-4 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
      role="status"
    >
      {{ motifSuspendu }}
    </p>

    <div
      v-if="ficheCompletee !== null"
      ref="bandeau"
      class="mb-4 flex flex-col gap-1.5 rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm"
      role="status"
      data-fiche-completee
    >
      <strong class="font-semibold">{{ annonceDesDemandes(ficheCompletee.demandeIds) }}</strong>
      <span>
        Vos compléments à la fiche de {{ ficheCompletee.libelle }} attendent la validation d’un
        administrateur. Le formulaire est prêt pour une autre personne.
      </span>
      <span class="flex flex-wrap gap-x-4 gap-y-1">
        <RouterLink
          v-if="ficheCompletee.personId !== null"
          :to="{ name: 'personne.detail', params: { id: ficheCompletee.personId } }"
          class="font-semibold text-accent underline"
        >
          Voir sa fiche
        </RouterLink>
        <RouterLink :to="{ name: 'demandes.miennes' }" class="font-semibold text-accent underline">
          Suivre mes demandes
        </RouterLink>
      </span>
    </div>

    <FormulaireCreationPersonne
      ref="formulaire"
      contexte="page"
      :refaire="repriseDemandee"
      @deposee="suivre"
      @refaire="refaire"
    >
      <template #actions>
        <RouterLink :to="{ name: 'arbre' }" class="text-sm text-attenue underline">
          Revenir à l’arbre
        </RouterLink>
      </template>
    </FormulaireCreationPersonne>
    </template>
  </div>
</template>
