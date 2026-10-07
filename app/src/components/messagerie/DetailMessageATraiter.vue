<script setup lang="ts">
/**
 * Le détail d'un **message de membre** dans « À traiter » (module `Messagerie`,
 * ADR 0017) : la conversation entière, la réponse, et « Marquer réglé ».
 *
 * Ouvrir le détail relit le fil côté administration — c'est ce qui pose
 * l'accusé de lecture (« Vu par Nathan à 14:32 ») chez le membre. Répondre, ou
 * régler le fil, le fait quitter la file : le dernier mot n'est plus au membre.
 * Le parent annonce, et ouvre l'élément suivant. « Ouvrir la conversation »
 * mène à l'écran du fil, pour qui veut écrire deux messages d'affilée.
 */
import { ref, shallowRef, watch } from 'vue'
import {
  changerStatutDuFil,
  lireFilAdministration,
  repondreAuFil,
  type DetailDeFil,
  type ResumeDeFil,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import FilDeDiscussion from './FilDeDiscussion.vue'

const { fil: resume, suspendu = false, infobulle } = defineProps<{
  fil: ResumeDeFil
  suspendu?: boolean
  infobulle?: string | undefined
}>()

const emit = defineEmits<{
  repondu: []
  regle: []
}>()

const fil = shallowRef<DetailDeFil | null>(null)
const erreur = ref<unknown>(null)
const brouillon = ref('')
const envoi = ref<'reponse' | 'reglement' | null>(null)
const erreurDEnvoi = ref<unknown>(null)

let avorteur: AbortController | null = null

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  const { signal } = avorteur
  erreur.value = null
  fil.value = null
  try {
    fil.value = await lireFilAdministration(resume.id, signal)
  } catch (cause) {
    if (!signal.aborted) erreur.value = cause
  }
}

watch(
  () => resume.id,
  () => {
    brouillon.value = ''
    erreurDEnvoi.value = null
    void charger()
  },
  { immediate: true },
)

async function repondre(texte: string): Promise<void> {
  if (suspendu || envoi.value !== null) return
  envoi.value = 'reponse'
  erreurDEnvoi.value = null
  try {
    await repondreAuFil(resume.id, texte)
    brouillon.value = ''
    emit('repondu')
  } catch (cause) {
    erreurDEnvoi.value = cause
  } finally {
    envoi.value = null
  }
}

async function regler(): Promise<void> {
  if (suspendu || envoi.value !== null) return
  envoi.value = 'reglement'
  erreurDEnvoi.value = null
  try {
    await changerStatutDuFil(resume.id, 'clos')
    emit('regle')
  } catch (cause) {
    erreurDEnvoi.value = cause
  } finally {
    envoi.value = null
  }
}
</script>

<template>
  <MessageErreur v-if="erreur !== null" :erreur="erreur" titre="La conversation n’a pas pu être ouverte" @action="charger" />
  <SqueletteChargement v-else-if="fil === null" :lignes="4" />
  <template v-else>
    <RouterLink
      :to="{ name: 'administration.messagerie', params: { id: resume.id } }"
      class="-mt-1 self-start text-[13px] text-attenue underline hover:text-texte"
    >
      Ouvrir la conversation
    </RouterLink>
    <FilDeDiscussion
      v-model:brouillon="brouillon"
      :fil="fil"
      collante
      :envoi-en-cours="envoi === 'reponse'"
      :erreur-d-envoi="erreurDEnvoi"
      :desactive="suspendu"
      :motif-desactivation="infobulle ?? null"
      @envoyer="repondre"
    >
      <template #actions>
        <BoutonBase
          variante="secondaire"
          class="min-h-11 flex-auto md:min-h-9"
          :title="infobulle"
          :chargement="envoi === 'reglement'"
          :desactive="suspendu || envoi !== null"
          data-regler
          @click="regler"
        >
          Marquer réglé, sans répondre
        </BoutonBase>
      </template>
    </FilDeDiscussion>
  </template>
</template>
