<script setup lang="ts">
/**
 * Le petit éditeur des limites d'usage — d'un compte, ou des quotas globaux.
 *
 * Un `<dialog>` modal natif, comme `DialogueConfirmation` : focus piégé,
 * Échap qui ferme, reste de la page inerte. Sur téléphone, il tient dans la
 * largeur de l'écran au lieu de s'ouvrir dans le tableau qui défile à
 * l'horizontale, où un champ serait coupé hors de vue.
 *
 * Un champ numérique par quota. **Vide = défaut**, et le défaut est rappelé
 * sous le champ et en indication grisée ; la règle et sa raison sont dans
 * `quotas.ts`. Seules les valeurs qui changent partent au serveur ; ses
 * messages par champ (`limites.<clé>`) s'affichent sous le champ concerné.
 */
import { computed, nextTick, ref, useId, watch } from 'vue'
import type { LimitesDeQuotas, QuotaDeCompte, QuotaGlobal } from '@/lib/api'
import { ErreurApi } from '@/lib/api/erreurs'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import { brouillonDesQuotas, erreursDuServeur, lireLeBrouillon } from './quotas'

export interface EditionDeQuotas {
  titre: string
  /** Le compte concerné, ou ce que « global » veut dire. */
  precision: string
  quotas: ReadonlyArray<QuotaGlobal | QuotaDeCompte>
}

const {
  edition,
  chargement = false,
  erreur = null,
} = defineProps<{
  edition: EditionDeQuotas | null
  chargement?: boolean
  /** L'échec du dernier enregistrement, rendu par le parent. */
  erreur?: unknown
}>()

const emit = defineEmits<{ enregistrer: [limites: LimitesDeQuotas]; annuler: [] }>()

const dialogue = ref<HTMLDialogElement | null>(null)
const brouillon = ref<Record<string, string>>({})
const erreursLocales = ref<Record<string, string>>({})
const id = useId()

const erreursParQuota = computed<Record<string, string>>(() => ({
  ...(erreur instanceof ErreurApi ? erreursDuServeur(erreur.champs) : {}),
  ...erreursLocales.value,
}))

/** L'erreur d'ensemble, quand le serveur n'en a pas fait une affaire de champ. */
const erreurGenerale = computed(() =>
  erreur !== null && !(erreur instanceof ErreurApi && Object.keys(erreursDuServeur(erreur.champs)).length > 0)
    ? erreur
    : null,
)

watch(
  () => edition,
  async (actuelle) => {
    const element = dialogue.value
    if (actuelle !== null) {
      brouillon.value = brouillonDesQuotas(actuelle.quotas)
      erreursLocales.value = {}
    }
    if (element === null) return

    if (actuelle !== null && !element.open) {
      element.showModal()
      await nextTick()
      element.querySelector<HTMLInputElement>('input:not([disabled])')?.focus()
    } else if (actuelle === null && element.open) {
      element.close()
    }
  },
  { flush: 'post' },
)

function exempte(quota: QuotaGlobal | QuotaDeCompte): boolean {
  return 'exempte' in quota && quota.exempte
}

function aide(quota: QuotaGlobal | QuotaDeCompte): string {
  if (exempte(quota)) return 'Non compté pour un administrateur : aucune limite ne s’applique.'
  return `Vide = défaut (${quota.limiteParDefaut} ${quota.fenetre}). 0 ferme l’usage. Consommé : ${quota.consomme}.`
}

function enregistrer(): void {
  if (edition === null) return
  const lecture = lireLeBrouillon(edition.quotas, brouillon.value)
  erreursLocales.value = lecture.erreurs
  if (Object.keys(lecture.erreurs).length > 0) return
  // Rien ne change : on ferme sans écrire.
  if (Object.keys(lecture.limites).length === 0) {
    emit('annuler')
    return
  }
  emit('enregistrer', lecture.limites)
}

/** Échap ferme le `<dialog>` de lui-même : on le rapporte comme un abandon. */
function surFermeture(): void {
  if (edition !== null) emit('annuler')
}
</script>

<template>
  <dialog
    ref="dialogue"
    class="m-auto w-[min(26rem,calc(100vw-2rem))] rounded-panneau border border-ligne bg-panneau p-0 text-texte shadow-panneau backdrop:bg-black/50"
    :aria-labelledby="`${id}-titre`"
    data-editeur-de-quotas
    @close="surFermeture"
  >
    <form v-if="edition !== null" class="flex flex-col gap-4 p-5" novalidate @submit.prevent="enregistrer">
      <div>
        <h2 :id="`${id}-titre`" class="text-base font-semibold">{{ edition.titre }}</h2>
        <p class="text-sm text-attenue [overflow-wrap:anywhere]">{{ edition.precision }}</p>
      </div>

      <MessageErreur v-if="erreurGenerale !== null" :erreur="erreurGenerale" />

      <ChampTexte
        v-for="quota in edition.quotas"
        :key="quota.cle"
        v-model="brouillon[quota.cle]"
        :etiquette="`${quota.libelle} (${quota.fenetre})`"
        type="number"
        chasse-fixe
        :placeholder="`${quota.limiteParDefaut}`"
        :aide="aide(quota)"
        :erreur="erreursParQuota[quota.cle] ?? ''"
        :desactive="chargement || exempte(quota)"
        :data-champ-quota="quota.cle"
      />

      <div class="flex flex-wrap justify-end gap-2">
        <BoutonBase variante="secondaire" :desactive="chargement" @click="emit('annuler')">Annuler</BoutonBase>
        <BoutonBase type="submit" :chargement="chargement">Enregistrer</BoutonBase>
      </div>
    </form>
  </dialog>
</template>
