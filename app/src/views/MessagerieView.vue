<script setup lang="ts">
/**
 * « Écrire à l'administration » (`/messages`, `/messages/<id>`) — une question,
 * une idée, un bug, en conversation avec les administrateurs (module
 * `Messagerie`).
 *
 * Deux états, une seule route :
 *
 * - **sans identifiant**, la porte d'entrée : *poursuivre* une conversation
 *   déjà ouverte (les non lues d'abord), ou en *créer* une — la nature choisie
 *   d'un geste, le message, un sujet facultatif. Sans conversation, le
 *   formulaire est ouvert d'emblée. `?nouveau=bug` (le bouton de l'accueil)
 *   l'ouvre avec la nature déjà choisie ;
 * - **avec identifiant**, la conversation elle-même (`FilDeDiscussion`) :
 *   l'ouvrir pose l'accusé de lecture sur les réponses de l'administration, et
 *   chaque message envoyé dit « Envoyé », puis « Vu à 14:32 ».
 *
 * Le nom `messagerie` est un contrat : la notification
 * `message_de_l_administration` y mène, avec `{ id }`.
 *
 * `attente` et non `membre` : un compte dont l'inscription n'est pas validée
 * peut écrire — c'est souvent là qu'on a une question —, et rien ici ne lit
 * l'arbre. Sous la bascule « voir comme », on lit sans écrire.
 */
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  analyserErreur,
  ecrireDansMonFil,
  lireMonFil,
  listerMesFils,
  ouvrirUnFil,
  type DetailDeFil,
  type NatureDuFil,
  type ResumeDeFil,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import FilDeDiscussion from '@/components/messagerie/FilDeDiscussion.vue'
import ListeDesFils from '@/components/messagerie/ListeDesFils.vue'
import { NATURES, estNature } from '@/components/messagerie/messagerie'
import { useRelecture } from '@/components/messagerie/useRelecture'
import { motifSuspension } from '@/components/administration/ecriture'

const route = useRoute()
const routeur = useRouter()
const { vueEmpruntee } = useSession()

const suspendu = computed(() => vueEmpruntee.value !== null)
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))

const idOuvert = computed<number | null>(() => {
  const brut = route.params['id']
  const id = Number(Array.isArray(brut) ? brut[0] : brut)
  return Number.isInteger(id) && id > 0 ? id : null
})

/* ───────────────── La liste : poursuivre ───────────────── */

const fils = shallowRef<ResumeDeFil[] | null>(null)
const erreurListe = ref<unknown>(null)

/* ───────────────── La conversation ouverte ───────────────── */

const fil = shallowRef<DetailDeFil | null>(null)
const erreurFil = ref<unknown>(null)
const brouillon = ref('')
const envoiEnCours = ref(false)
const erreurDEnvoi = ref<unknown>(null)

let avorteur: AbortController | null = null

/** `silencieux` : une relecture d'arrière-plan ne remplace pas l'écran par une erreur. */
async function charger(silencieux = false): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  const { signal } = avorteur
  const id = idOuvert.value

  try {
    if (id === null) {
      if (!silencieux) erreurListe.value = null
      fils.value = (await listerMesFils(signal)).elements
      if (fils.value.length === 0 && !silencieux) formulaireOuvert.value = true
    } else {
      if (!silencieux) {
        erreurFil.value = null
        if (fil.value?.id !== id) fil.value = null
      }
      fil.value = await lireMonFil(id, signal)
    }
  } catch (e: unknown) {
    if (signal.aborted || silencieux) return
    if (id === null) erreurListe.value = e
    else erreurFil.value = e
  }
}

watch(idOuvert, () => void charger(), { immediate: true })
onBeforeUnmount(() => avorteur?.abort())
useRelecture(() => {
  if (!envoiEnCours.value) void charger(true)
})

async function envoyer(texte: string): Promise<void> {
  const courant = fil.value
  if (courant === null || envoiEnCours.value) return
  envoiEnCours.value = true
  erreurDEnvoi.value = null
  try {
    fil.value = await ecrireDansMonFil(courant.id, texte)
    brouillon.value = ''
  } catch (e: unknown) {
    erreurDEnvoi.value = e
  } finally {
    envoiEnCours.value = false
  }
}

/* ───────────────── Créer ───────────────── */

const formulaireOuvert = ref(estNature(route.query['nouveau']))
const nature = ref<NatureDuFil>(estNature(route.query['nouveau']) ? route.query['nouveau'] : 'question')
const sujet = ref('')
const texte = ref('')
const creationEnCours = ref(false)
const erreurDeCreation = ref<unknown>(null)
const champs = computed(() => (erreurDeCreation.value === null ? {} : analyserErreur(erreurDeCreation.value).champs))
const invite = computed(() => NATURES.find((n) => n.valeur === nature.value)?.invite ?? '')

watch(
  () => route.query['nouveau'],
  (valeur) => {
    if (estNature(valeur)) {
      nature.value = valeur
      formulaireOuvert.value = true
    }
  },
)

async function creer(): Promise<void> {
  if (creationEnCours.value || texte.value.trim() === '' || suspendu.value) return
  creationEnCours.value = true
  erreurDeCreation.value = null
  try {
    const cree = await ouvrirUnFil({ nature: nature.value, texte: texte.value.trim(), sujet: sujet.value })
    fil.value = cree
    texte.value = ''
    sujet.value = ''
    formulaireOuvert.value = false
    await routeur.replace({ name: 'messagerie', params: { id: cree.id } })
  } catch (e: unknown) {
    erreurDeCreation.value = e
  } finally {
    creationEnCours.value = false
  }
}
</script>

<template>
  <div class="mx-auto flex w-full max-w-2xl flex-col gap-4 px-4 py-6">
    <!-- ───────── Une conversation ───────── -->
    <template v-if="idOuvert !== null">
      <RouterLink :to="{ name: 'messagerie' }" class="text-sm text-accent underline">‹ Mes conversations</RouterLink>

      <MessageErreur v-if="erreurFil !== null" :erreur="erreurFil" titre="La conversation n’a pas pu être ouverte" @action="charger()" />
      <SqueletteChargement v-else-if="fil === null" :lignes="5" />

      <section v-else class="flex flex-col rounded-panneau border border-ligne bg-fond px-3 pb-3">
        <header class="flex flex-wrap items-center gap-2 border-b border-ligne py-3">
          <span class="rounded-pastille bg-accent-doux px-2 py-0.5 text-xs font-semibold text-accent">{{ fil.natureLibelle }}</span>
          <h1 class="min-w-0 flex-1 truncate text-base font-semibold text-texte">{{ fil.sujet }}</h1>
          <span v-if="fil.statut === 'clos'" class="text-xs text-attenue">{{ fil.statutLibelle }}</span>
        </header>
        <FilDeDiscussion
          v-model:brouillon="brouillon"
          :fil="fil"
          :envoi-en-cours="envoiEnCours"
          :erreur-d-envoi="erreurDEnvoi"
          :desactive="suspendu"
          :motif-desactivation="raisonSuspension"
          @envoyer="envoyer"
        />
      </section>
    </template>

    <!-- ───────── Poursuivre, ou créer ───────── -->
    <template v-else>
      <header>
        <h1 class="text-xl font-semibold text-texte">Écrire à l’administration</h1>
        <p class="mt-1 text-sm text-attenue">
          Une question, une idée, un bug ? Les administrateurs vous répondent ici, et vous voyez quand ils ont lu votre message.
        </p>
      </header>

      <p v-if="raisonSuspension !== null" class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent" role="status">
        {{ raisonSuspension }}
      </p>

      <BoutonBase v-if="!formulaireOuvert && !suspendu" variante="principal" pleine-largeur @click="formulaireOuvert = true">
        Nouvelle conversation
      </BoutonBase>

      <form
        v-if="formulaireOuvert && !suspendu"
        class="flex flex-col gap-3 rounded-panneau border border-ligne bg-panneau p-4"
        aria-labelledby="titre-nouvelle-conversation"
        @submit.prevent="creer"
      >
        <h2 id="titre-nouvelle-conversation" class="text-sm font-semibold">Nouvelle conversation</h2>

        <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="De quoi s’agit-il ?">
          <button
            v-for="n in NATURES"
            :key="n.valeur"
            type="button"
            role="radio"
            :aria-checked="nature === n.valeur"
            class="rounded-champ border px-1 py-2.5 text-[13px] font-semibold transition-colors"
            :class="nature === n.valeur ? 'border-accent bg-accent-doux text-accent' : 'border-ligne text-attenue hover:text-texte'"
            @click="nature = n.valeur"
          >
            {{ n.libelle }}
          </button>
        </div>
        <p v-if="champs['nature']" class="m-0 text-xs text-alerte">{{ champs['nature'] }}</p>

        <label class="flex flex-col gap-1 text-sm">
          <span class="text-attenue">Sujet <span class="text-xs">(facultatif)</span></span>
          <input
            v-model="sujet"
            type="text"
            maxlength="160"
            class="rounded-champ border border-ligne bg-fond px-3 py-2 text-[15px] focus:border-accent focus:outline-none"
            placeholder="En quelques mots"
          />
          <span v-if="champs['sujet']" class="text-xs text-alerte">{{ champs['sujet'] }}</span>
        </label>

        <label class="flex flex-col gap-1 text-sm">
          <span class="text-attenue">Message</span>
          <textarea
            v-model="texte"
            rows="5"
            maxlength="5000"
            required
            class="resize-y rounded-champ border border-ligne bg-fond px-3 py-2 text-[15px] focus:border-accent focus:outline-none"
            :placeholder="invite"
          />
          <span v-if="champs['texte']" class="text-xs text-alerte">{{ champs['texte'] }}</span>
        </label>

        <MessageErreur
          v-if="erreurDeCreation !== null && Object.keys(champs).length === 0"
          :erreur="erreurDeCreation"
          titre="Le message n’est pas parti"
        />

        <div class="flex justify-end gap-2">
          <BoutonBase v-if="(fils?.length ?? 0) > 0" variante="discret" @click="formulaireOuvert = false">Annuler</BoutonBase>
          <BoutonBase type="submit" :chargement="creationEnCours" :desactive="texte.trim() === ''">Envoyer</BoutonBase>
        </div>
      </form>

      <section v-if="(fils?.length ?? 0) > 0 || erreurListe !== null" aria-labelledby="titre-poursuivre" class="flex flex-col gap-2">
        <h2 id="titre-poursuivre" class="text-sm font-semibold">Poursuivre une conversation</h2>
        <MessageErreur v-if="erreurListe !== null" :erreur="erreurListe" @action="charger()" />
        <ListeDesFils v-else-if="fils !== null" :fils="fils" route="messagerie" />
      </section>
      <SqueletteChargement v-else-if="fils === null" :lignes="3" />
    </template>
  </div>
</template>
