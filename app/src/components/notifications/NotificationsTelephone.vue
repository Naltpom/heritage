<script setup lang="ts">
/**
 * « Notifications sur ce téléphone » — le réglage du push, **par appareil**.
 *
 * Deux formes, un seul état (`usePush`) :
 *
 * - **`ligne`**, dans « Moi » : l'état en clair et le seul geste qui a un sens.
 *   Activer ; Désactiver ; et quand aucun bouton ne peut rien — permission
 *   refusée, iPhone hors écran d'accueil —, la phrase qui dit quoi faire à la
 *   place. Un bouton « Activer » qui ne ferait rien serait pire que pas de
 *   bouton : le navigateur ne redemande **jamais** une permission refusée.
 * - **`lien`**, dans la cloche : une invitation discrète, montrée **seulement**
 *   quand un clic suffit (`desactive`). Qui a refusé ou désactivé n'a pas à se
 *   la voir reproposer ailleurs que dans « Moi ».
 *
 * L'état est relu au montage : la permission a pu changer dans les réglages du
 * navigateur pendant que l'application dormait.
 */
import { computed, onMounted } from 'vue'
import { usePush } from '@/composables/usePush'
import BoutonBase from '@/components/base/BoutonBase.vue'

const { forme = 'ligne' } = defineProps<{ forme?: 'ligne' | 'lien' }>()

const { etat, enCours, erreur, refuse, evaluer, activer, desactiver } = usePush()

onMounted(() => void evaluer())

const LIBELLES: Record<string, string> = {
  inconnu: 'Vérification…',
  indisponible: 'Non disponibles sur ce navigateur',
  a_installer: 'Installez d’abord l’application',
  desactive: 'Désactivées',
  active: 'Activées',
  bloque: 'Bloquées par le navigateur',
}
const libelle = computed(() => LIBELLES[etat.value] ?? '')
</script>

<template>
  <div v-if="forme === 'ligne'" class="flex flex-col gap-2 px-4 py-3" data-push-etat :data-etat="etat">
    <div class="flex min-h-9 flex-wrap items-center justify-between gap-3">
      <div class="flex min-w-0 flex-col leading-snug">
        <span class="text-[15px]">Notifications sur ce téléphone</span>
        <span class="text-sm" :class="etat === 'active' ? 'text-ok' : etat === 'bloque' ? 'text-alerte' : 'text-attenue'">{{ libelle }}</span>
      </div>
      <BoutonBase v-if="etat === 'desactive'" variante="principal" :chargement="enCours" @click="activer()">Activer</BoutonBase>
      <BoutonBase v-else-if="etat === 'active'" variante="secondaire" :chargement="enCours" @click="desactiver()">
        Désactiver
      </BoutonBase>
    </div>
    <p v-if="etat === 'bloque'" class="m-0 text-sm text-attenue">
      Réautorisez-les dans les réglages du navigateur (le cadenas près de l’adresse), puis revenez ici.
    </p>
    <p v-else-if="etat === 'a_installer'" class="m-0 text-sm text-attenue">
      Sur iPhone, installez d’abord Heritage : dans Safari, touchez
      <strong class="text-texte">Partager</strong> puis <strong class="text-texte">Sur l’écran d’accueil</strong>.
    </p>
    <p v-if="erreur" role="alert" class="m-0 text-sm text-alerte">{{ erreur }}</p>
  </div>

  <!-- Le lien de la cloche : seulement à qui n'a rien refusé, et seulement sur
       téléphone, où son libellé dit vrai. Qui a désactivé le retrouve dans Moi. -->
  <div v-else-if="(etat === 'desactive' && !refuse) || erreur" class="flex-none px-4 pt-2.5 md:hidden">
    <button
      v-if="etat === 'desactive' && !refuse"
      type="button"
      class="text-[13px] text-accent underline-offset-2 hover:underline disabled:opacity-60"
      :disabled="enCours"
      @click="activer()"
    >
      Recevoir aussi sur ce téléphone
    </button>
    <p v-if="erreur" role="alert" class="m-0 text-[13px] text-alerte">{{ erreur }}</p>
  </div>
</template>
