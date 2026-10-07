<script setup lang="ts">
/**
 * Le détail d'une **demande RGPD** dans « À traiter » (handoff « mode
 * Administration », § 4.1 ; ADR 0016) : ce qu'il faut retirer, le message du
 * membre, la réponse, et les deux décisions.
 *
 * « Appliquer » part sans réponse — appliquer un retrait de date de naissance
 * le fait tout de suite, même avant la fin des 30 jours. « Refuser » exige une
 * réponse : c'est tout ce que le membre en saura. La garde passe avant l'appel.
 *
 * `409 demande_deja_traitee` : un autre administrateur est passé avant. Le
 * parent relit la file et le dit.
 */
import { ref, useId, watch } from 'vue'
import {
  estErreurApi,
  traiterDemandeRgpd,
  type DecisionDemandeRgpd,
  type DemandeRgpdAdministration,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import { composantesARetirer } from '@/components/compte/demandesRgpd'

/** Le code d'une demande qu'un autre administrateur a traitée entre-temps. */
const CODE_DEJA_TRAITEE = 'demande_deja_traitee'

const { rgpd, suspendu = false, infobulle } = defineProps<{
  rgpd: DemandeRgpdAdministration
  suspendu?: boolean
  infobulle?: string | undefined
}>()

const emit = defineEmits<{
  decidee: [decision: DecisionDemandeRgpd]
  /** Déjà traitée ailleurs : relire la file. */
  dejaTraitee: []
}>()

const reponse = ref('')
const reponseManquante = ref(false)
const envoi = ref<DecisionDemandeRgpd | null>(null)
const erreur = ref<unknown>(null)
const idReponse = useId()

watch(
  () => rgpd.id,
  () => {
    reponse.value = ''
    reponseManquante.value = false
    erreur.value = null
  },
)

function saisir(valeur: string): void {
  reponse.value = valeur
  if (valeur.trim() !== '') reponseManquante.value = false
}

async function traiter(decision: DecisionDemandeRgpd): Promise<void> {
  if (suspendu || envoi.value !== null) return
  const texte = reponse.value.trim()
  erreur.value = null
  if (decision === 'refusee' && texte === '') {
    reponseManquante.value = true
    return
  }
  envoi.value = decision
  try {
    await traiterDemandeRgpd(rgpd.id, decision, texte === '' ? undefined : texte)
    emit('decidee', decision)
  } catch (cause) {
    if (estErreurApi(cause) && cause.code === CODE_DEJA_TRAITEE) emit('dejaTraitee')
    else erreur.value = cause
  } finally {
    envoi.value = null
  }
}
</script>

<template>
  <p v-if="composantesARetirer(rgpd) !== null">
    À retirer : <strong>{{ composantesARetirer(rgpd) }}</strong>
  </p>
  <p v-if="rgpd.message" class="text-attenue whitespace-pre-line">« {{ rgpd.message }} »</p>

  <label class="flex flex-col gap-1">
    <span class="text-[12.5px] font-medium">Réponse au membre (exigée pour refuser)</span>
    <textarea
      :id="idReponse"
      :value="reponse"
      rows="2"
      class="resize-y rounded-champ border bg-fond px-2.5 py-2 text-sm text-texte"
      :class="reponseManquante ? 'border-alerte' : 'border-ligne'"
      :aria-invalid="reponseManquante ? 'true' : undefined"
      :disabled="suspendu"
      data-reponse-rgpd
      @input="saisir(($event.target as HTMLTextAreaElement).value)"
    />
  </label>
  <p v-if="reponseManquante" class="text-[12.5px] text-alerte" role="alert" data-reponse-manquante>
    Un message est exigé pour refuser.
  </p>

  <MessageErreur v-if="erreur !== null" :erreur="erreur" />

  <div class="barre-actions-a-traiter" :title="infobulle">
    <BoutonBase
      class="min-h-11 flex-auto md:min-h-9"
      :chargement="envoi === 'appliquee'"
      :desactive="suspendu || envoi !== null"
      @click="traiter('appliquee')"
    >
      Appliquer
    </BoutonBase>
    <BoutonBase
      variante="danger"
      class="min-h-11 flex-auto md:min-h-9"
      :chargement="envoi === 'refusee'"
      :desactive="suspendu || envoi !== null"
      @click="traiter('refusee')"
    >
      Refuser
    </BoutonBase>
  </div>
</template>
