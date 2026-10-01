<script setup lang="ts">
/**
 * « À rattacher » — les inscriptions **sans fiche** (ADR 0013).
 *
 * Le lien d'invitation illimité circule dans un groupe familial ; une personne
 * qui ne se trouve pas dans l'arbre (ou pas avec ses données exactes) décrit sa
 * famille en texte libre et crée quand même son compte. Il attend ici, en
 * attente et sans fiche, qu'un administrateur dise qui elle est.
 *
 * ── Pourquoi un onglet, et pas une ligne de « Utilisateurs » ────────────────
 *
 * La décision demande de **lire** : une description de plusieurs lignes, une
 * photo, une analyse. La glisser dans la liste des comptes en ferait la seule
 * ligne de trente centimètres d'un écran de lignes courtes, et l'action
 * « Valider le rattachement » de cet écran n'a pas de sens pour un compte qui
 * n'a pas de fiche. L'écran des utilisateurs renvoie donc ici.
 *
 * ── Les analyses, l'une après l'autre ───────────────────────────────────────
 *
 * Chaque carte demande sa suggestion à l'ouverture (`GET`, calculée si elle
 * n'est pas en cache — 20 à 60 s), **dans l'ordre des cartes et une seule à la
 * fois** : voir `FileDAnalyses`. Le quota est global, 40 par 24 heures pour
 * tous les administrateurs. Sortir de l'écran vide la file et coupe l'appel en
 * cours.
 *
 * ── Sous la bascule « voir comme » ──────────────────────────────────────────
 *
 * La liste rend `409 vue_comme_membre_active` : une description ne se filtre
 * pas par un cercle, elle n'a pas de version « vue par un membre ». L'écran ne
 * l'appelle donc pas du tout et le dit, avec le moyen de sortir de la vue.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  lireSuggestionDeRattachement,
  listerRattachements,
  quitterVueComme,
  relancerSuggestionDeRattachement,
  type CompteARattacher,
  type SuggestionDeRattachement,
} from '@/lib/api'
import { rafraichirSession, useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import CarteARattacher from '@/components/administration/CarteARattacher.vue'
import OngletsAdministration from './OngletsAdministration.vue'
import {
  ecrituresSuspendues,
  infobulleSuspension,
  motifSuspension,
} from '@/components/administration/ecriture'
import {
  FileDAnalyses,
  compteDisparu,
  etatApresErreur,
  type EtatDAnalyse,
} from '@/components/administration/rattachements'

const { vueEmpruntee } = useSession()

const comptes = ref<CompteARattacher[]>([])
/** L'état de l'analyse de chaque carte, par `memberId`. */
const analyses = ref<Record<number, EtatDAnalyse>>({})
const chargement = ref(false)
const erreur = ref<unknown>(null)

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))

const file = new FileDAnalyses()

function analyseDe(memberId: number): EtatDAnalyse {
  return analyses.value[memberId] ?? { etat: 'attente' }
}

function retirerLaCarte(memberId: number): void {
  file.retirer(memberId)
  comptes.value = comptes.value.filter((compte) => compte.memberId !== memberId)
  const reste = { ...analyses.value }
  delete reste[memberId]
  analyses.value = reste
}

/**
 * Met une analyse en file. `forcer` : « Relancer l'analyse » (`POST`, consomme
 * le quota) ; sinon, lecture du cache ou premier calcul (`GET`).
 */
function mettreEnFile(memberId: number, forcer: boolean): void {
  analyses.value = { ...analyses.value, [memberId]: { etat: 'attente' } }
  void file.ajouter(memberId, async (signal) => {
    analyses.value = { ...analyses.value, [memberId]: { etat: 'en_cours' } }
    let suggestion: SuggestionDeRattachement
    try {
      suggestion = forcer
        ? await relancerSuggestionDeRattachement(memberId, signal)
        : await lireSuggestionDeRattachement(memberId, signal)
    } catch (cause) {
      if (signal.aborted) return
      // Traité par un autre administrateur entre-temps : la carte s'en va.
      if (compteDisparu(cause)) {
        retirerLaCarte(memberId)
        return
      }
      analyses.value = { ...analyses.value, [memberId]: etatApresErreur(cause) }
      return
    }
    if (signal.aborted) return
    analyses.value = { ...analyses.value, [memberId]: { etat: 'prete', suggestion } }
  })
}

async function charger(): Promise<void> {
  file.vider()
  if (suspendu.value) {
    comptes.value = []
    analyses.value = {}
    return
  }
  chargement.value = true
  erreur.value = null
  try {
    comptes.value = (await listerRattachements()).elements
    analyses.value = {}
    for (const compte of comptes.value) mettreEnFile(compte.memberId, false)
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

onMounted(charger)
onBeforeUnmount(() => file.vider())
// Entrer ou sortir de la vue empruntée change ce que l'écran a le droit de lire.
watch(suspendu, () => void charger())

async function quitter(): Promise<void> {
  erreur.value = null
  try {
    await quitterVueComme()
    await rafraichirSession()
  } catch (cause) {
    erreur.value = cause
  }
}
</script>

<template>
  <BarreEcran ecran="Administration" :reinitialisable="false">
    <template #vue><OngletsAdministration /></template>
  </BarreEcran>

  <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 px-4 py-6">
    <div
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      <p>{{ raisonSuspension }}</p>
      <p class="mt-1">
        Les inscriptions ne s’affichent pas sous une vue empruntée.
      </p>
      <div class="mt-2">
        <BoutonBase variante="secondaire" @click="quitter">Quitter la vue empruntée</BoutonBase>
      </div>
    </div>

    <p v-else class="text-sm text-attenue">
      Ces personnes ne se sont pas trouvées dans l’arbre : rattachez-les à une fiche, créez la leur, ou refusez.
    </p>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="charger" />

    <SqueletteChargement v-if="chargement && comptes.length === 0" :lignes="4" />

    <p
      v-else-if="!chargement && erreur === null && raisonSuspension === null && comptes.length === 0"
      class="rounded-panneau border border-ligne bg-panneau px-4 py-6 text-center text-sm text-attenue"
      role="status"
    >
      Aucune inscription n’attend de rattachement.
    </p>

    <CarteARattacher
      v-for="compte in comptes"
      :key="compte.memberId"
      :compte="compte"
      :analyse="analyseDe(compte.memberId)"
      :suspendu="suspendu"
      :infobulle="infobulle"
      @traite="retirerLaCarte"
      @relancer="(memberId) => mettreEnFile(memberId, true)"
    />
  </div>
</template>
