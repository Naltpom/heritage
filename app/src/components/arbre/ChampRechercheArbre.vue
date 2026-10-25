<script setup lang="ts">
/**
 * « Aller à une personne », en bas de l'arbre, sur téléphone — le même champ pour
 * `/arbre` (`ArbreGroupesView`) et `/arbre-2` (`HistoireView`).
 *
 * Le composant ne cherche rien : l'écran lui passe l'état de sa
 * `useRecherchePersonnes` (par l'API, jamais par un index local) et garde le
 * curseur, qu'il partage avec la recherche de l'en-tête sur grand écran.
 *
 * Le motif « combobox » de WAI-ARIA, comme dans l'en-tête : `role="combobox"`,
 * `aria-expanded`, `aria-controls` vers la liste (toujours dans le document),
 * `aria-activedescendant` vers l'option du curseur ; le focus reste dans le
 * champ. Les cinq états se disent : « Recherche… », la panne avec « Réessayer »,
 * « Personne ne correspond. » après la réponse seulement, puis la liste.
 */
import { computed, ref } from 'vue'
import type { ResultatDeRecherche } from '@/lib/api'
import type { EtatRecherche } from '@/composables/useRecherchePersonnes'
import { libelleDeBranche } from '@/histoire/lignees'

const props = defineProps<{
  etat: EtatRecherche
  resultats: readonly ResultatDeRecherche[]
  tronque: boolean
  message: string
  curseur: number
  ouverte: boolean
  /** Le bouton qui ramène à l'ego : « Moi », ou le prénom de la personne dont c'est l'arbre (`/arbre?depuis=`). */
  libelleMoi?: string | null
}>()

const texte = defineModel<string>('texte', { required: true })

const emit = defineEmits<{
  touche: [e: KeyboardEvent]
  choisir: [r: ResultatDeRecherche]
  reessayer: []
  ouvrir: []
  fermer: []
  moi: []
}>()

const ID_LISTE = 'recherche-arbre-liste'
/** L'écran fait défiler l'option du curseur par cet identifiant (`surCurseur`). */
const idOption = (i: number): string => `resultat-histoire-${i}`

const deplie = computed(() => props.ouverte && props.etat !== 'inactif')
const optionActive = computed(() =>
  deplie.value && props.etat === 'resultats' && props.curseur < props.resultats.length ? idOption(props.curseur) : undefined,
)

const champ = ref<HTMLInputElement | null>(null)
defineExpose({
  focus: () => champ.value?.focus(),
  blur: () => champ.value?.blur(),
})
</script>

<template>
  <div class="relative z-10 flex flex-none items-center gap-2 border-t border-ligne bg-panneau px-3 pt-2.5 pb-[max(18px,env(safe-area-inset-bottom))]">
    <div class="relative flex-1">
      <input
        ref="champ"
        v-model="texte"
        type="search"
        role="combobox"
        enterkeyhint="search"
        autocomplete="off"
        aria-autocomplete="list"
        :aria-expanded="deplie"
        :aria-controls="ID_LISTE"
        :aria-activedescendant="optionActive"
        placeholder="Aller à une personne…"
        aria-label="Aller à une personne"
        class="h-11 w-full rounded-pastille border border-ligne bg-fond px-4 text-[15px] outline-none focus:border-accent"
        @keydown="emit('touche', $event)"
        @focus="emit('ouvrir')"
        @blur="emit('fermer')"
      />
      <!-- Toujours dans le document (`v-show`) : `aria-controls` doit désigner un élément qui existe. -->
      <div
        v-show="deplie"
        class="absolute bottom-[52px] left-0 -right-[52px] z-20 max-h-[60vh] overflow-y-auto rounded-panneau border border-ligne bg-panneau py-1 shadow-[0_-10px_30px_rgba(0,0,0,.5)]"
        @pointerdown.prevent
      >
        <div class="px-3 pt-1 pb-1.5 text-[11px] text-attenue" role="status" aria-live="polite">
          <template v-if="etat === 'chargement'">Recherche…</template>
          <template v-else-if="etat === 'erreur'">
            <span class="text-alerte" data-erreur-recherche>{{ message }}</span>
            <button type="button" class="ml-2 min-h-8 font-semibold text-accent" @click="emit('reessayer')">Réessayer</button>
          </template>
          <template v-else-if="etat === 'vide'">Personne ne correspond.</template>
          <template v-else-if="etat === 'resultats'">{{ resultats.length }}{{ tronque ? '+' : '' }} personne{{ resultats.length > 1 ? 's' : '' }} · ↑ ↓ pour parcourir</template>
        </div>
        <ul :id="ID_LISTE" role="listbox" aria-label="Personnes trouvées" class="m-0 list-none p-0">
          <template v-if="etat === 'resultats'">
            <li
              v-for="(r, i) in resultats"
              :id="idOption(i)"
              :key="r.personId"
              role="option"
              :aria-selected="i === curseur"
              class="flex min-h-11 w-full cursor-pointer flex-col items-start justify-center px-3 py-1 text-left leading-snug"
              :class="i === curseur ? 'bg-accent-doux' : ''"
              @click="emit('choisir', r)"
            >
              <span class="text-sm font-semibold">{{ r.displayName }}</span>
              <span class="text-xs text-attenue">{{ libelleDeBranche(r.branch) }}<template v-if="r.ligneDates"> · {{ r.ligneDates }}</template></span>
            </li>
          </template>
        </ul>
      </div>
    </div>
    <button type="button" class="h-11 rounded-pastille bg-accent px-3.5 font-semibold text-fond" @click="emit('moi')">{{ libelleMoi ?? 'Moi' }}</button>
  </div>
</template>
