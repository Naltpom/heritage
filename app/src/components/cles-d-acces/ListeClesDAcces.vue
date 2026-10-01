<script setup lang="ts">
/**
 * La liste des clés d'accès du compte — l'**affichage** seul, pour la section
 * « Clés d'accès » de Mon compte (`SectionClesDAcces`), qui charge, renomme et
 * supprime.
 *
 * Une ligne par clé : son nom, quand elle a été créée, quand elle a servi pour
 * la dernière fois (« jamais » est une information : une clé jamais utilisée
 * est souvent celle d'un appareil perdu ou d'un essai), et la mention « cet
 * appareil » quand la mémoire locale la reconnaît.
 *
 * Le renommage se fait **sur place** : le nom devient un champ, Entrée
 * enregistre, Échap renonce. Pas de fenêtre pour un seul mot.
 */
import { nextTick, ref } from 'vue'
import type { CleDAcces } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'

const {
  cles,
  deCetAppareil = [],
  enRenommage = null,
  desactive = false,
} = defineProps<{
  cles: CleDAcces[]
  /** Les identifiants que la mémoire locale rattache à cet appareil. */
  deCetAppareil?: number[]
  /** La clé dont le nouveau nom part au serveur. */
  enRenommage?: number | null
  /** Pendant une incarnation : on montre, on ne touche pas. */
  desactive?: boolean
}>()

const emit = defineEmits<{ renommer: [id: number, nom: string]; supprimer: [cle: CleDAcces] }>()

const editee = ref<number | null>(null)
const brouillon = ref('')
const champ = ref<HTMLInputElement[] | HTMLInputElement | null>(null)

/** Longueur maximale d'un nom — au-delà, il ne tient plus sur une ligne. */
const LONGUEUR_NOM = 60

async function commencer(cle: CleDAcces): Promise<void> {
  editee.value = cle.id
  brouillon.value = cle.nom
  await nextTick()
  const element = Array.isArray(champ.value) ? champ.value[0] : champ.value
  element?.focus()
  element?.select()
}

function renoncer(): void {
  editee.value = null
}

function valider(cle: CleDAcces): void {
  const nom = brouillon.value.trim()
  editee.value = null
  if (nom === '' || nom === cle.nom) return
  emit('renommer', cle.id, nom)
}

/** « 12 mars 2026 ». L'heure n'apprend rien ici. */
function dateCourte(iso: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso
  return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <ul class="m-0 flex list-none flex-col divide-y divide-ligne p-0" data-liste-cles-d-acces>
    <li v-for="cle in cles" :key="cle.id" class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2 py-3">
      <div class="flex min-w-0 flex-1 flex-col gap-0.5">
        <form v-if="editee === cle.id" class="flex flex-wrap items-center gap-2" @submit.prevent="valider(cle)">
          <label :for="`cle-${cle.id}-nom`" class="sr-only">Nouveau nom de la clé</label>
          <input
            :id="`cle-${cle.id}-nom`"
            ref="champ"
            v-model="brouillon"
            type="text"
            :maxlength="LONGUEUR_NOM"
            class="min-w-0 flex-1 rounded-champ border border-ligne bg-fond px-2.5 py-1 text-sm focus:border-accent focus:outline-none"
            @keydown.esc.prevent="renoncer"
          />
          <BoutonBase type="submit" variante="principal">Enregistrer</BoutonBase>
          <BoutonBase variante="discret" @click="renoncer">Annuler</BoutonBase>
        </form>
        <p v-else class="m-0 flex flex-wrap items-center gap-2 text-sm font-semibold">
          <span class="break-words">{{ cle.nom }}</span>
          <span
            v-if="deCetAppareil.includes(cle.id)"
            class="rounded-pastille bg-accent-doux px-2 py-0.5 text-xs font-normal text-accent"
          >
            cet appareil
          </span>
        </p>
        <p class="m-0 text-xs text-attenue">
          Créée le {{ dateCourte(cle.creeeLe) }}
          <template v-if="cle.appareil"> · {{ cle.appareil }}</template>
        </p>
        <p class="m-0 text-xs text-attenue">
          <template v-if="cle.derniereUtilisation !== null">
            Dernière utilisation le {{ dateCourte(cle.derniereUtilisation) }}
          </template>
          <template v-else>Jamais utilisée pour se connecter</template>
        </p>
      </div>

      <div v-if="editee !== cle.id && !desactive" class="flex flex-none gap-2">
        <BoutonBase variante="secondaire" :chargement="enRenommage === cle.id" @click="commencer(cle)">
          Renommer
        </BoutonBase>
        <BoutonBase variante="danger" @click="emit('supprimer', cle)">Supprimer</BoutonBase>
      </div>
    </li>
  </ul>
</template>
