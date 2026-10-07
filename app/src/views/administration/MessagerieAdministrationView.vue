<script setup lang="ts">
/**
 * « Conversations » — tout ce que les membres écrivent à l'administration :
 * questions, suggestions, bugs (module `Messagerie`).
 *
 * Les messages qui **attendent une réponse** sont d'abord dans « À traiter »,
 * avec le reste de ce qui attend une décision ; cet écran-ci est leur
 * historique, comme `administration.demandes` l'est des demandes — c'est donc
 * « À traiter » qui s'allume dans la navigation (`entreeAdminActive`).
 *
 * Sans identifiant, la liste — les non lus d'abord, filtrable « En cours » /
 * « Réglés ». Avec identifiant, la conversation : l'ouvrir pose l'accusé de
 * lecture sur les messages du membre (« Vu par Nathan à 14:32 », de son côté),
 * et l'administration y répond **d'une seule voix** — ce qu'un administrateur a
 * lu est lu pour tous. « Marquer réglé » range le fil ; le membre qui y
 * réécrit le rouvre.
 *
 * Le nom `administration.messagerie` est un contrat : la notification
 * `message_d_un_membre` y mène, avec `{ id }`.
 *
 * Sous la bascule « voir comme », l'écran ne lit rien et le dit : lire un fil
 * poserait un accusé au nom de l'administration.
 */
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  changerStatutDuFil,
  lireFilAdministration,
  listerFilsAdministration,
  quitterVueComme,
  repondreAuFil,
  type DetailDeFil,
  type ResumeDeFil,
  type StatutDuFil,
} from '@/lib/api'
import { rafraichirSession, useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import FilDeDiscussion from '@/components/messagerie/FilDeDiscussion.vue'
import ListeDesFils from '@/components/messagerie/ListeDesFils.vue'
import { horodatageCourt } from '@/components/messagerie/messagerie'
import { useRelecture } from '@/components/messagerie/useRelecture'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'

const route = useRoute()
const { vueEmpruntee } = useSession()

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))

const idOuvert = computed<number | null>(() => {
  const brut = route.params['id']
  const id = Number(Array.isArray(brut) ? brut[0] : brut)
  return Number.isInteger(id) && id > 0 ? id : null
})

type Filtre = 'tous' | StatutDuFil
const FILTRES = [
  { valeur: 'ouvert', libelle: 'En cours' },
  { valeur: 'clos', libelle: 'Réglés' },
  { valeur: 'tous', libelle: 'Tous' },
] as const
const filtre = ref<Filtre>('ouvert')

const fils = shallowRef<ResumeDeFil[] | null>(null)
const erreurListe = ref<unknown>(null)
const fil = shallowRef<DetailDeFil | null>(null)
const erreurFil = ref<unknown>(null)

const brouillon = ref('')
const envoiEnCours = ref(false)
const erreurDEnvoi = ref<unknown>(null)
const statutEnCours = ref(false)
const erreurDeStatut = ref<unknown>(null)

let avorteur: AbortController | null = null

async function charger(silencieux = false): Promise<void> {
  if (suspendu.value) {
    fils.value = []
    fil.value = null
    return
  }
  avorteur?.abort()
  avorteur = new AbortController()
  const { signal } = avorteur
  const id = idOuvert.value

  try {
    if (id === null) {
      if (!silencieux) erreurListe.value = null
      const statut = filtre.value === 'tous' ? undefined : filtre.value
      fils.value = (await listerFilsAdministration(statut === undefined ? {} : { statut }, signal)).elements
    } else {
      if (!silencieux) {
        erreurFil.value = null
        if (fil.value?.id !== id) fil.value = null
      }
      fil.value = await lireFilAdministration(id, signal)
    }
  } catch (e: unknown) {
    if (signal.aborted || silencieux) return
    if (id === null) erreurListe.value = e
    else erreurFil.value = e
  }
}

watch([idOuvert, filtre, suspendu], () => void charger(), { immediate: true })
onBeforeUnmount(() => avorteur?.abort())
useRelecture(() => {
  if (!envoiEnCours.value && !statutEnCours.value) void charger(true)
})

async function repondre(texte: string): Promise<void> {
  const courant = fil.value
  if (courant === null || envoiEnCours.value || suspendu.value) return
  envoiEnCours.value = true
  erreurDEnvoi.value = null
  try {
    fil.value = await repondreAuFil(courant.id, texte)
    brouillon.value = ''
  } catch (e: unknown) {
    erreurDEnvoi.value = e
  } finally {
    envoiEnCours.value = false
  }
}

async function basculerStatut(): Promise<void> {
  const courant = fil.value
  if (courant === null || statutEnCours.value || suspendu.value) return
  statutEnCours.value = true
  erreurDeStatut.value = null
  try {
    fil.value = await changerStatutDuFil(courant.id, courant.statut === 'clos' ? 'ouvert' : 'clos')
  } catch (e: unknown) {
    erreurDeStatut.value = e
  } finally {
    statutEnCours.value = false
  }
}

async function quitter(): Promise<void> {
  await quitterVueComme()
  await rafraichirSession()
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      <p>{{ raisonSuspension }}</p>
      <p class="mt-1">Les messages des membres ne s’affichent pas sous une vue empruntée.</p>
      <div class="mt-2">
        <BoutonBase variante="secondaire" @click="quitter">Quitter la vue empruntée</BoutonBase>
      </div>
    </div>

    <!-- ───────── Une conversation ───────── -->
    <template v-else-if="idOuvert !== null">
      <RouterLink :to="{ name: 'administration.messagerie' }" class="min-h-11 self-start text-[15px] font-semibold text-accent md:min-h-0 md:text-sm">
        ‹ Toutes les conversations
      </RouterLink>

      <MessageErreur v-if="erreurFil !== null" :erreur="erreurFil" titre="La conversation n’a pas pu être ouverte" @action="charger()" />
      <SqueletteChargement v-else-if="fil === null" :lignes="5" />

      <section v-else class="flex flex-col rounded-panneau border border-ligne bg-panneau px-[18px]">
        <header class="flex flex-wrap items-center gap-2 border-b border-ligne py-3">
          <span class="rounded-pastille bg-accent-doux px-2 py-0.5 text-xs font-semibold text-accent">{{ fil.natureLibelle }}</span>
          <div class="min-w-0 flex-1">
            <h1 class="truncate text-base font-semibold text-texte">{{ fil.sujet }}</h1>
            <p class="m-0 truncate text-xs text-attenue">
              {{ fil.membre.libelle }} · ouvert {{ horodatageCourt(fil.creeLe, new Date()) }}
            </p>
          </div>
          <BoutonBase variante="secondaire" :chargement="statutEnCours" @click="basculerStatut">
            {{ fil.statut === 'clos' ? 'Rouvrir' : 'Marquer réglé' }}
          </BoutonBase>
        </header>
        <MessageErreur v-if="erreurDeStatut !== null" :erreur="erreurDeStatut" />
        <FilDeDiscussion
          v-model:brouillon="brouillon"
          :fil="fil"
          :envoi-en-cours="envoiEnCours"
          :erreur-d-envoi="erreurDEnvoi"
          collante
          @envoyer="repondre"
        />
      </section>
    </template>

    <!-- ───────── La liste ───────── -->
    <template v-else>
      <RouterLink
        :to="{ name: 'administration.a-traiter', query: { type: 'message' } }"
        class="min-h-11 self-start text-[15px] font-semibold text-accent md:min-h-0 md:text-sm"
      >
        ‹ À traiter
      </RouterLink>
      <div class="puces-defilantes flex gap-2 overflow-x-auto overflow-y-hidden py-0.5" role="group" aria-label="Conversations affichées">
        <button
          v-for="f in FILTRES"
          :key="f.valeur"
          type="button"
          class="flex min-h-11 flex-none items-center rounded-pastille border px-[13px] text-[13px] whitespace-nowrap md:min-h-9"
          :class="f.valeur === filtre ? 'border-accent bg-accent-doux font-semibold text-texte' : 'border-ligne bg-panneau text-attenue'"
          :aria-pressed="f.valeur === filtre"
          @click="filtre = f.valeur"
        >
          {{ f.libelle }}
        </button>
      </div>

      <MessageErreur v-if="erreurListe !== null" :erreur="erreurListe" @action="charger()" />
      <SqueletteChargement v-else-if="fils === null" :lignes="4" />
      <p
        v-else-if="fils.length === 0"
        class="rounded-panneau border border-ligne bg-panneau px-4 py-7 text-center text-attenue"
        role="status"
      >
        {{ filtre === 'ouvert' ? 'Aucune conversation en cours.' : 'Aucune conversation.' }}
      </p>
      <ListeDesFils v-else :fils="fils" route="administration.messagerie" avec-membre />
    </template>
  </div>
</template>
