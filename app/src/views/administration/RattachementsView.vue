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
 * ── Rien d'automatique à l'ouverture (3 octobre 2026) ───────────────────────
 *
 * Jusqu'au 2 octobre, l'ouverture lançait, carte par carte, la transcription du
 * message vocal puis la recherche. Le propriétaire a demandé l'inverse :
 * relire, **corriger la transcription**, puis lancer la recherche lui-même.
 * L'ouverture ne fait donc que **lire** :
 *
 *  - la liste (`GET /api/administration/rattachements`) ;
 *  - pour les cartes qui en ont une (`suggestionCalculeeLe`), la suggestion
 *    déjà calculée (`GET …/suggestion`, gratuit — `204` s'il n'y en a plus),
 *    l'une après l'autre ;
 *  - le quota global (`GET /api/administration/quotas`), pour dire ce qu'il
 *    reste à côté du bouton. S'il ne se lit pas, la carte n'en dit rien.
 *
 * Puis tout part d'un clic :
 *
 *  - « Transcrire le message vocal » (`POST …/transcription`, 10 à 60 s) ;
 *  - « Enregistrer » une transcription corrigée (`PATCH …/transcription` — la
 *    carte l'envoie elle-même, et l'écran relit la suggestion, désormais
 *    périmée) ;
 *  - « Lancer la recherche » (`POST …/suggestion`, 20 à 60 s), **une à la
 *    fois** dans l'ordre des clics : voir `FileDAnalyses`. Le quota est global,
 *    40 par 24 heures pour tous les administrateurs.
 *
 * Sortir de l'écran vide la file et coupe les appels en cours.
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
  listerQuotasGlobaux,
  listerRattachements,
  quitterVueComme,
  relancerSuggestionDeRattachement,
  transcrireMessageVocal,
  type CompteARattacher,
  type SuggestionDeRattachement,
  type TranscriptionARattacher,
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
  etatDeTranscription,
  quotaDesRattachements,
  transcriptionApresErreur,
  type EtatDAnalyse,
  type EtatDeTranscription,
  type QuotaRestant,
} from '@/components/administration/rattachements'

const { vueEmpruntee } = useSession()

const comptes = ref<CompteARattacher[]>([])
/** L'état de la recherche de chaque carte, par `memberId`. */
const analyses = ref<Record<number, EtatDAnalyse>>({})
/** L'état de la transcription de chaque carte qui a un vocal, par `memberId`. */
const transcriptions = ref<Record<number, EtatDeTranscription>>({})
/** Ce qu'il reste du quota global ; `null` tant qu'il n'a pas été lu (ou s'il ne se lit pas). */
const quota = ref<QuotaRestant | null>(null)
const chargement = ref(false)
const erreur = ref<unknown>(null)

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))

const file = new FileDAnalyses()
/** Coupe les lectures et les transcriptions en vol quand on recharge ou qu'on quitte l'écran. */
let sortie = new AbortController()

function analyseDe(memberId: number): EtatDAnalyse {
  return analyses.value[memberId] ?? { etat: 'aucune' }
}

function poserAnalyse(memberId: number, etat: EtatDAnalyse): void {
  // Une carte retirée entre-temps ne revient pas par une réponse tardive.
  if (!comptes.value.some((compte) => compte.memberId === memberId)) return
  analyses.value = { ...analyses.value, [memberId]: etat }
}

function poserTranscription(memberId: number, etat: EtatDeTranscription): void {
  if (!comptes.value.some((compte) => compte.memberId === memberId)) return
  transcriptions.value = { ...transcriptions.value, [memberId]: etat }
}

function retirerLaCarte(memberId: number): void {
  file.retirer(memberId)
  comptes.value = comptes.value.filter((compte) => compte.memberId !== memberId)
  const reste = { ...analyses.value }
  delete reste[memberId]
  analyses.value = reste
  const resteT = { ...transcriptions.value }
  delete resteT[memberId]
  transcriptions.value = resteT
}

/**
 * Relit le quota global — à l'ouverture, et après chaque appel au modèle. Un
 * échec de lecture efface l'indication plutôt que d'en garder une fausse.
 */
async function lireLeQuota(): Promise<void> {
  try {
    quota.value = quotaDesRattachements((await listerQuotasGlobaux()).quotas)
  } catch {
    quota.value = null
  }
}

/**
 * Relit la suggestion **déjà calculée** d'une carte (`GET`, gratuit) : `null`
 * (`204`) la remet à « Lancer la recherche ». Jamais un calcul.
 */
async function relireLaSuggestion(memberId: number, signal: AbortSignal): Promise<void> {
  try {
    const gardee = await lireSuggestionDeRattachement(memberId, signal)
    if (signal.aborted) return
    poserAnalyse(memberId, gardee === null ? { etat: 'aucune' } : { etat: 'prete', suggestion: gardee })
  } catch (cause) {
    if (signal.aborted) return
    if (compteDisparu(cause)) {
      retirerLaCarte(memberId)
      return
    }
    poserAnalyse(memberId, etatApresErreur(cause))
  }
}

/**
 * « Transcrire le message vocal », « Réessayer », « Retranscrire ». Un appel
 * au modèle, au clic — pas en file : une transcription ne tient pas la file des
 * recherches. La suggestion déjà affichée est relue ensuite : elle a pu devenir
 * périmée.
 */
async function transcrire(memberId: number): Promise<void> {
  if (suspendu.value || transcriptions.value[memberId]?.etat === 'en_cours') return
  const signal = sortie.signal
  poserTranscription(memberId, { etat: 'en_cours' })
  try {
    const transcription = await transcrireMessageVocal(memberId, signal)
    if (signal.aborted) return
    poserTranscription(memberId, etatDeTranscription(transcription))
  } catch (cause) {
    if (signal.aborted) return
    if (compteDisparu(cause)) {
      retirerLaCarte(memberId)
      return
    }
    poserTranscription(memberId, transcriptionApresErreur(cause))
  } finally {
    if (!signal.aborted) void lireLeQuota()
  }
  if (analyses.value[memberId]?.etat === 'prete') await relireLaSuggestion(memberId, signal)
}

/**
 * La carte a enregistré une transcription corrigée. La suggestion affichée est
 * marquée périmée **tout de suite** — c'est ce que le serveur dira —, puis
 * relue pour en avoir le dernier mot (un retour au texte d'origine la rend
 * de nouveau valable).
 */
function transcriptionCorrigee(memberId: number, transcription: TranscriptionARattacher): void {
  poserTranscription(memberId, etatDeTranscription(transcription))
  const analyse = analyses.value[memberId]
  if (analyse?.etat !== 'prete') return
  poserAnalyse(memberId, { etat: 'prete', suggestion: { ...analyse.suggestion, perimee: true } })
  void relireLaSuggestion(memberId, sortie.signal)
}

/**
 * « Lancer la recherche » / « Relancer la recherche » (`POST`, consomme le
 * quota), en file : une seule à la fois.
 */
function lancerLaRecherche(memberId: number): void {
  if (suspendu.value) return
  const actuel = analyses.value[memberId]?.etat
  if (actuel === 'attente' || actuel === 'en_cours') return
  poserAnalyse(memberId, { etat: 'attente' })
  void file.ajouter(memberId, async (signal) => {
    poserAnalyse(memberId, { etat: 'en_cours' })
    let suggestion: SuggestionDeRattachement
    try {
      suggestion = await relancerSuggestionDeRattachement(memberId, signal)
    } catch (cause) {
      if (signal.aborted) return
      // Traité par un autre administrateur entre-temps : la carte s'en va.
      if (compteDisparu(cause)) {
        retirerLaCarte(memberId)
        return
      }
      poserAnalyse(memberId, etatApresErreur(cause))
      return
    } finally {
      if (!signal.aborted) void lireLeQuota()
    }
    if (signal.aborted) return
    poserAnalyse(memberId, { etat: 'prete', suggestion })
  })
}

async function charger(): Promise<void> {
  file.vider()
  sortie.abort()
  sortie = new AbortController()
  const signal = sortie.signal
  if (suspendu.value) {
    comptes.value = []
    analyses.value = {}
    transcriptions.value = {}
    return
  }
  chargement.value = true
  erreur.value = null
  try {
    comptes.value = (await listerRattachements()).elements
    if (signal.aborted) return
    // Rien ne se calcule ici : une carte sans suggestion gardée attend le clic.
    analyses.value = Object.fromEntries(
      comptes.value.map((compte) => [
        compte.memberId,
        compte.suggestionCalculeeLe === null ? { etat: 'aucune' } : { etat: 'lecture' },
      ]),
    )
    transcriptions.value = Object.fromEntries(
      comptes.value.flatMap((compte) =>
        compte.messageVocal === null ? [] : [[compte.memberId, etatDeTranscription(compte.messageVocal.transcription)]],
      ),
    )
  } catch (cause) {
    erreur.value = cause
    return
  } finally {
    chargement.value = false
  }
  void lireLeQuota()
  // Les suggestions gardées, l'une après l'autre : des lectures, mais chacune
  // tient un processus PHP le temps de rafraîchir les fiches citées.
  for (const compte of comptes.value.filter((c) => c.suggestionCalculeeLe !== null)) {
    if (signal.aborted) return
    await relireLaSuggestion(compte.memberId, signal)
  }
}

onMounted(charger)
onBeforeUnmount(() => {
  file.vider()
  sortie.abort()
})
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
      Rien n’est envoyé à l’IA sans votre clic.
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
      :transcription="transcriptions[compte.memberId]"
      :quota="quota"
      :suspendu="suspendu"
      :infobulle="infobulle"
      @traite="retirerLaCarte"
      @lancer="lancerLaRecherche"
      @transcrire="transcrire"
      @corrigee="transcriptionCorrigee"
    />
  </div>
</template>
