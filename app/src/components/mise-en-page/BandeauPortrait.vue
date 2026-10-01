<script setup lang="ts">
/**
 * Le rappel à l'ouverture : « ajoutez votre photo », ou « c'est bien vous ? ».
 *
 * Deux états, décidés par la charge de session (§ 2.2, `portrait`) — jamais par
 * un appel de plus :
 *
 * - **aucun portrait** sur la fiche du lecteur → proposer d'en déposer un ;
 * - **un portrait déposé par quelqu'un d'autre** (`deposeParMoi === false`) —
 *   typiquement avant que le titulaire ne rejoigne l'arbre → le montrer et
 *   demander de le garder ou de le remplacer.
 *
 * Rien pour un compte en attente : il n'a pas de fiche à lui, et `portrait` est
 * `null` à la source. Rien non plus une fois qu'un portrait déposé par le
 * titulaire existe — la question n'a plus d'objet.
 *
 * « Plus tard » et « Garder » sont mémorisés **par appareil** (`localStorage`,
 * par identifiant de compte). C'est une commodité de rappel, pas une donnée :
 * le serveur sait s'il y a un portrait, et c'est cela qui compte. Le stockage
 * peut être vide ou refusé — navigation privée — et le bandeau revient alors ;
 * c'est le mauvais côté de l'erreur, pas une panne.
 */
import { computed, ref } from 'vue'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChoixPortrait from '@/components/medias/ChoixPortrait.vue'

const { session, rattache } = useSession()

const cle = computed(() => {
  const id = session.value?.utilisateur.id
  return id === undefined ? null : `heritage.portrait.rappel.${id}`
})

function lireLeRefus(): boolean {
  try {
    return cle.value !== null && globalThis.localStorage?.getItem(cle.value) === '1'
  } catch {
    return false
  }
}

const refuse = ref(lireLeRefus())
/** La fenêtre « Ma photo » s'ouvre ici même : importer ou prendre, puis cadrer. */
const choixOuvert = ref(false)

const portrait = computed(() => session.value?.portrait ?? null)

const etat = computed<'aucun' | 'a_confirmer' | null>(() => {
  if (!rattache.value || refuse.value) return null
  if (portrait.value === null) return 'aucun'
  return portrait.value.deposeParMoi ? null : 'a_confirmer'
})

function fermer(): void {
  refuse.value = true
  try {
    if (cle.value !== null) globalThis.localStorage?.setItem(cle.value, '1')
  } catch {
    // Stockage indisponible : le bandeau reviendra à la prochaine ouverture, et
    // c'est acceptable.
  }
}
</script>

<template>
  <div
    v-if="etat !== null"
    class="flex flex-wrap items-center gap-3 border-b border-ligne bg-accent-doux px-4 py-2 text-sm"
    role="status"
  >
    <template v-if="etat === 'aucun'">
      <span>Ajoutez une photo de vous : elle apparaîtra sur votre carte dans l’arbre.</span>
      <BoutonBase variante="principal" @click="choixOuvert = true">Ajouter ma photo</BoutonBase>
      <BoutonBase variante="discret" @click="fermer">Plus tard</BoutonBase>
    </template>

    <template v-else>
      <img
        v-if="portrait !== null"
        :src="portrait.vignetteUrl"
        alt=""
        class="h-10 w-10 rounded-full object-cover"
      />
      <span>Quelqu’un a déposé cette photo de vous. La garder ou la remplacer ?</span>
      <BoutonBase variante="secondaire" @click="fermer">Garder</BoutonBase>
      <BoutonBase variante="principal" @click="choixOuvert = true">Remplacer</BoutonBase>
    </template>
  </div>
  <ChoixPortrait :ouvert="choixOuvert" @fermer="choixOuvert = false" />
</template>
