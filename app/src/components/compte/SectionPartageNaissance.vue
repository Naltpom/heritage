<script setup lang="ts">
/**
 * « Mon compte » › Confidentialité : le partage de ma date de naissance
 * (ADR 0016).
 *
 * Le choix part de la session (`partageNaissance`), se modifie ici sur une
 * copie, et ne part qu'à « Enregistrer » : trois cases qui partiraient au clic
 * changeraient trois fois de suite ce que le lecteur voit de l'arbre. Après
 * l'appel, la session est **relue** : elle porte le choix et sa date, et la
 * relire périme l'`ETag` du graphe — les dates des autres changent avec.
 *
 * Sous la bascule « voir comme », le serveur refuse (`409
 * vue_comme_membre_active`) : on ne choisit pas pour le membre qu'on regarde.
 * Les cases s'éteignent et la raison est dite.
 *
 * La vue la masque quand `partageNaissance` vaut `null` : un compte sans ligne
 * membre n'a pas de date de naissance à partager.
 */
import { computed, ref, watch } from 'vue'
import { analyserErreur, modifierPartageNaissance, type PartageNaissanceEnregistre } from '@/lib/api'
import { rafraichirSession, useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PartageNaissance from './PartageNaissance.vue'
import { erreursDePartage, memePartage, partageSeul } from './partageNaissance'

const { partage: enregistre } = defineProps<{ partage: PartageNaissanceEnregistre }>()

const { session, vueEmpruntee } = useSession()

const choix = ref(partageSeul(enregistre))
// Une session relue (ici ou ailleurs) remet la copie sur le choix enregistré.
watch(
  () => enregistre,
  (nouveau) => {
    choix.value = partageSeul(nouveau)
  },
)

const envoi = ref(false)
const erreur = ref<unknown>(null)
const enregistrement = ref(false)

const modifie = computed(() => !memePartage(choix.value, enregistre))
const suspendu = computed(() => vueEmpruntee.value !== null)
const refus = computed(() => erreursDePartage(analyserErreur(erreur.value)?.champs))
const restrictionIgnoree = computed(() => session.value?.reciprocite?.restrictionIgnoree === true)

async function enregistrer(): Promise<void> {
  if (!modifie.value || envoi.value || suspendu.value) return
  envoi.value = true
  erreur.value = null
  enregistrement.value = false
  try {
    await modifierPartageNaissance(choix.value)
    await rafraichirSession()
    enregistrement.value = true
  } catch (cause) {
    erreur.value = cause
  } finally {
    envoi.value = false
  }
}

function dateLisible(iso: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso
  return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <form class="flex flex-col gap-3 p-4" novalidate data-section-partage-naissance @submit.prevent="enregistrer">
    <h2 class="text-base font-semibold">Confidentialité</h2>

    <PartageNaissance v-model="choix" :erreurs="refus" :desactive="suspendu || envoi" />

    <p v-if="enregistre.modifieLe !== null" class="m-0 text-xs text-attenue">
      Choix modifié le {{ dateLisible(enregistre.modifieLe) }}.
    </p>
    <p v-if="restrictionIgnoree" class="m-0 text-xs text-attenue">
      Un administrateur vous permet de voir les dates de naissance des autres sans cette réciprocité.
    </p>
    <p v-if="suspendu" class="m-0 text-xs text-attenue">
      Vous voyez l’arbre comme un autre membre : ce choix ne se modifie pas pendant ce temps.
    </p>

    <MessageErreur v-if="erreur !== null && Object.keys(refus).length === 0" :erreur="erreur" />
    <p v-if="enregistrement && !modifie" class="m-0 text-sm" role="status">Choix enregistré.</p>

    <BoutonBase
      type="submit"
      variante="principal"
      class="self-start"
      :chargement="envoi"
      :desactive="!modifie || suspendu"
    >
      Enregistrer
    </BoutonBase>
  </form>
</template>
