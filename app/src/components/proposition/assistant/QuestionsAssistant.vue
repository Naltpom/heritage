<script setup lang="ts">
/**
 * **Poser une question** à l'assistant, sur l'arbre — une conversation suivie.
 *
 * Trois zones, un seul composant :
 *
 * 1. **Mes conversations** — la liste, la plus récente d'abord, et « Nouvelle
 *    conversation ». Sur téléphone, elle se replie derrière un bouton : à 390 px,
 *    le fil prime.
 * 2. **Le fil** — chaque question, puis sa réponse, où les fiches citées sont
 *    des liens vers `/personnes/{id}`. Les mentions sont découpées en texte et
 *    en liens (`questions.ts`), jamais rendues comme du HTML.
 * 3. **La question** — un champ, « Envoyer », le quota. Pendant l'attente (20 à
 *    60 secondes), la question est affichée tout de suite dans le fil, avec le
 *    temps écoulé et « Annuler » : une attente qu'on ne peut ni mesurer ni
 *    interrompre ressemble à une panne dès la quinzième seconde.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Rien n'est modifié.** Ce mode ne dépose aucune demande ; l'écran le dit, et
 * dit aussi que les questions partent chez Google Gemini — un membre qui le
 * découvrirait par hasard le vivrait autrement (ADR 0008).
 *
 * **Une conversation ne se crée pas vide** : la première question la crée au
 * retour de la réponse. Si l'appel échoue, la question revient dans le champ.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  analyserErreur,
  lireConversation,
  lireMesQuotas,
  listerConversations,
  poserQuestion,
  resteDuQuota,
  supprimerConversation,
  type ConversationAssistant,
  type ResumeConversation,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import BoutonBase from '@/components/base/BoutonBase.vue'
import DialogueConfirmation, { type DemandeDeConfirmation } from '@/components/base/DialogueConfirmation.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { estAnnulation } from './assistant'
import EchangeQuestion from './EchangeQuestion.vue'
import {
  EXEMPLES_DE_QUESTIONS,
  QUESTION_MAX,
  dateCourte,
  mentionQuotaQuestions,
  peutPoser,
  titreErreurQuestion,
} from './questions'

const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

/* --- Mes conversations ------------------------------------------- */

const conversations = ref<ResumeConversation[]>([])
const chargementListe = ref(true)
const erreurListe = ref<unknown>(null)
/** Sur téléphone, la liste est repliée tant qu'on ne la demande pas. */
const listeOuverte = ref(false)

async function chargerListe(): Promise<void> {
  erreurListe.value = null
  try {
    conversations.value = await listerConversations()
  } catch (cause) {
    erreurListe.value = cause
  } finally {
    chargementListe.value = false
  }
}

/* --- La conversation ouverte -------------------------------------- */

const courante = ref<ConversationAssistant | null>(null)
const chargementConversation = ref(false)
const erreurConversation = ref<unknown>(null)

async function ouvrir(id: number): Promise<void> {
  if (envoi.value) return
  listeOuverte.value = false
  erreur.value = null
  annulee.value = false
  if (courante.value?.id === id) return
  chargementConversation.value = true
  erreurConversation.value = null
  try {
    courante.value = await lireConversation(id)
    await defilerVersLaFin()
  } catch (cause) {
    erreurConversation.value = cause
  } finally {
    chargementConversation.value = false
  }
}

function nouvelle(): void {
  if (envoi.value) return
  courante.value = null
  erreur.value = null
  erreurConversation.value = null
  annulee.value = false
  listeOuverte.value = false
}

/* --- Poser une question ------------------------------------------- */

const question = ref('')
const enAttente = ref<string | null>(null)
const envoi = ref(false)
const erreur = ref<unknown>(null)
const annulee = ref(false)
const restant = ref<number | null>(null)
const secondes = ref(0)

let controleur: AbortController | null = null
let minuterie: ReturnType<typeof setInterval> | null = null

function arreterMinuterie(): void {
  if (minuterie !== null) clearInterval(minuterie)
  minuterie = null
}

const posable = computed(
  () => !suspendu.value && !envoi.value && restant.value !== 0 && peutPoser(question.value),
)

async function envoyer(): Promise<void> {
  if (!posable.value) return
  const texte = question.value.trim()
  erreur.value = null
  annulee.value = false
  enAttente.value = texte
  question.value = ''
  envoi.value = true
  secondes.value = 0
  minuterie = setInterval(() => secondes.value++, 1000)
  controleur = new AbortController()
  await defilerVersLaFin()

  try {
    const reponse = await poserQuestion(texte, courante.value?.id ?? null, controleur.signal)
    courante.value = reponse.conversation
    restant.value = reponse.restant
    enAttente.value = null
    void chargerListe()
    await defilerVersLaFin()
  } catch (cause) {
    // La question revient dans le champ : on la repose sans la retaper.
    question.value = texte
    enAttente.value = null
    if (estAnnulation(cause)) {
      annulee.value = true
    } else {
      erreur.value = cause
      if (analyserErreur(cause).statut === 429) restant.value = 0
    }
  } finally {
    envoi.value = false
    arreterMinuterie()
    controleur = null
  }
}

function annuler(): void {
  controleur?.abort()
}

/** Entrée envoie, Maj+Entrée va à la ligne — sauf sur un clavier tactile, où
 *  « Entrée » est le seul retour à la ligne : on y garde le bouton. */
function surTouche(evenement: KeyboardEvent): void {
  if (evenement.key !== 'Enter' || evenement.shiftKey || evenement.isComposing) return
  if (typeof window !== 'undefined' && window.matchMedia?.('(pointer: coarse)').matches) return
  evenement.preventDefault()
  void envoyer()
}

function surActionErreur(conduite: string): void {
  if (conduite === 'reessayer') void envoyer()
  else if (conduite === 'recharger' && typeof window !== 'undefined') window.location.reload()
}

const titreErreur = computed(() => titreErreurQuestion(erreur.value) ?? '')

function proposerExemple(exemple: string): void {
  question.value = exemple
  champ.value?.focus()
}

/* --- Supprimer ------------------------------------------------------ */

const confirmation = ref<DemandeDeConfirmation | null>(null)
const suppression = ref(false)

function demanderSuppression(): void {
  confirmation.value = {
    titre: 'Supprimer cette conversation ?',
    message: 'Les questions et les réponses seront effacées. L’arbre, lui, ne change pas.',
    confirmer: 'Supprimer',
    danger: true,
  }
}

async function supprimer(): Promise<void> {
  const id = courante.value?.id
  if (id === undefined) return
  suppression.value = true
  try {
    await supprimerConversation(id)
    conversations.value = conversations.value.filter((c) => c.id !== id)
    courante.value = null
  } catch (cause) {
    erreurConversation.value = cause
  } finally {
    suppression.value = false
    confirmation.value = null
  }
}

/* --- Le défilement ------------------------------------------------- */

const champ = ref<HTMLTextAreaElement | null>(null)
const fin = ref<HTMLElement | null>(null)

async function defilerVersLaFin(): Promise<void> {
  await nextTick()
  fin.value?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' })
}

onMounted(() => {
  void chargerListe()
  // Ce qui reste, lu sur le serveur : la limite est réglable compte par compte.
  lireMesQuotas()
    .then(({ quotas }) => {
      if (restant.value === null) restant.value = resteDuQuota(quotas, 'assistant.questions')
    })
    .catch(() => undefined)
})

onBeforeUnmount(() => {
  controleur?.abort()
  arreterMinuterie()
})
</script>

<template>
  <div class="flex flex-col gap-4">
    <p
      v-if="motifSuspendu !== null"
      class="m-0 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
      role="status"
    >
      {{ motifSuspendu }}
    </p>

    <div class="flex flex-wrap items-center gap-2">
      <BoutonBase
        variante="secondaire"
        class="min-h-11 md:hidden"
        :aria-expanded="listeOuverte ? 'true' : 'false'"
        aria-controls="liste-conversations"
        @click="listeOuverte = !listeOuverte"
      >
        Mes conversations<template v-if="conversations.length > 0"> ({{ conversations.length }})</template>
      </BoutonBase>
      <BoutonBase
        variante="secondaire"
        class="max-md:min-h-11"
        :desactive="envoi || (courante === null && enAttente === null)"
        @click="nouvelle"
      >
        Nouvelle conversation
      </BoutonBase>
    </div>

    <div class="grid items-start gap-4 md:grid-cols-[minmax(0,15rem)_minmax(0,1fr)]">
      <!-- 1 : mes conversations -->
      <nav
        id="liste-conversations"
        aria-label="Mes conversations"
        class="flex flex-col gap-1.5"
        :class="listeOuverte ? '' : 'max-md:hidden'"
      >
        <h2 class="m-0 text-sm font-semibold max-md:hidden">Mes conversations</h2>
        <SqueletteChargement v-if="chargementListe" :lignes="3" />
        <MessageErreur v-else-if="erreurListe !== null" :erreur="erreurListe" />
        <p v-else-if="conversations.length === 0" class="m-0 text-sm text-attenue">
          Aucune conversation pour l’instant.
        </p>
        <ul v-else class="m-0 flex list-none flex-col gap-1 p-0">
          <li v-for="conversation in conversations" :key="conversation.id">
            <button
              type="button"
              class="flex min-h-11 w-full flex-col items-start gap-0.5 rounded-champ border px-3 py-2 text-left text-sm hover:border-accent"
              :class="
                courante?.id === conversation.id ? 'border-accent bg-accent-doux' : 'border-ligne bg-panneau'
              "
              :aria-current="courante?.id === conversation.id ? 'true' : undefined"
              @click="ouvrir(conversation.id)"
            >
              <span class="line-clamp-2 font-medium">{{ conversation.titre }}</span>
              <span class="text-xs text-attenue">
                {{ dateCourte(conversation.updatedAt) }} ·
                {{ conversation.nombreDEchanges }} question<template v-if="conversation.nombreDEchanges > 1">s</template>
              </span>
            </button>
          </li>
        </ul>
      </nav>

      <!-- 2 et 3 : le fil, puis la question -->
      <section class="flex min-w-0 flex-col gap-3" aria-label="Conversation">
        <SqueletteChargement v-if="chargementConversation" :lignes="5" />
        <MessageErreur v-else-if="erreurConversation !== null" :erreur="erreurConversation" />

        <PanneauBase v-else-if="courante === null && enAttente === null" titre="Que voulez-vous savoir ?">
          <ul class="m-0 flex list-none flex-col gap-1.5 p-0">
            <li v-for="exemple in EXEMPLES_DE_QUESTIONS" :key="exemple">
              <button
                type="button"
                class="min-h-11 w-full rounded-champ border border-ligne bg-fond px-3 py-2 text-left text-sm hover:border-accent md:min-h-9"
                :disabled="suspendu"
                @click="proposerExemple(exemple)"
              >
                « {{ exemple }} »
              </button>
            </li>
          </ul>
        </PanneauBase>

        <template v-if="!chargementConversation && erreurConversation === null">
          <div v-if="courante !== null" class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="m-0 min-w-0 text-base font-semibold">{{ courante.titre }}</h2>
            <BoutonBase
              variante="discret"
              class="max-md:min-h-11"
              :desactive="envoi || suspendu"
              @click="demanderSuppression"
            >
              Supprimer
            </BoutonBase>
          </div>

          <ol class="m-0 flex list-none flex-col gap-3 p-0" aria-live="polite">
            <li v-for="echange in courante?.echanges ?? []" :key="echange.id">
              <EchangeQuestion :echange="echange" />
            </li>

            <li v-if="enAttente !== null" class="flex flex-col gap-2">
              <p
                class="m-0 ml-auto max-w-[88%] whitespace-pre-wrap rounded-panneau bg-accent-doux px-3.5 py-2.5 text-[15px]"
              >
                <span class="sr-only">Vous : </span>{{ enAttente }}
              </p>
              <div
                class="flex flex-col gap-3 rounded-panneau border border-accent bg-accent-doux px-4 py-3.5"
                role="status"
              >
                <div class="flex items-start gap-3">
                  <span
                    class="mt-0.5 size-4 flex-none animate-spin rounded-full border-2 border-accent border-t-transparent"
                    aria-hidden="true"
                  />
                  <div class="min-w-0">
                    <p class="m-0 text-sm font-semibold">L’assistant cherche dans l’arbre…</p>
                    <p class="m-0 mt-0.5 text-xs text-attenue">
                      Cela prend en général 20 à 60 secondes. Vous pouvez rester sur cette page.
                      <span class="tabular-nums">({{ secondes }} s)</span>
                    </p>
                  </div>
                </div>
                <div>
                  <BoutonBase variante="secondaire" class="max-md:min-h-11 max-md:w-full" @click="annuler">
                    Annuler
                  </BoutonBase>
                </div>
              </div>
            </li>
          </ol>
          <div ref="fin" />

          <MessageErreur
            v-if="erreur !== null"
            :erreur="erreur"
            :titre="titreErreur"
            @action="surActionErreur"
          />
          <p v-if="annulee" class="m-0 text-sm text-attenue" role="status">
            Question annulée. Si l’assistant avait déjà fini, sa réponse apparaîtra en rouvrant la
            conversation.
          </p>

          <form class="flex flex-col gap-2" @submit.prevent="envoyer">
            <label class="flex flex-col gap-1">
              <span class="text-sm font-medium">
                {{ courante === null ? 'Votre question' : 'Une autre question' }}
              </span>
              <textarea
                ref="champ"
                v-model="question"
                rows="3"
                :maxlength="QUESTION_MAX"
                :disabled="envoi || suspendu"
                :placeholder="courante === null ? '« Qui est né à Étaples ? »' : '« Et son père ? »'"
                class="rounded-champ border border-ligne bg-fond px-3 py-2 text-[15px] leading-relaxed text-texte disabled:opacity-60"
                @keydown="surTouche"
              />
              <span class="self-end text-[11px] text-attenue tabular-nums">
                {{ question.length }} / {{ QUESTION_MAX }}
              </span>
            </label>
            <div class="flex flex-wrap items-center gap-3 max-md:flex-col max-md:items-stretch">
              <BoutonBase type="submit" :desactive="!posable" :chargement="envoi" class="max-md:min-h-11">
                Envoyer
              </BoutonBase>
              <span class="text-xs text-attenue max-md:text-center">{{ mentionQuotaQuestions(restant) }}</span>
            </div>
          </form>
        </template>

        <p class="m-0 text-xs text-attenue">
          L’assistant ne modifie rien. Vos questions passent par Google Gemini ; vos conversations
          ne sont visibles que par vous.
        </p>
      </section>
    </div>

    <DialogueConfirmation
      :demande="confirmation"
      :chargement="suppression"
      @confirmer="supprimer"
      @annuler="confirmation = null"
    />
  </div>
</template>
