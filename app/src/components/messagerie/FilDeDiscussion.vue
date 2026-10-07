<script setup lang="ts">
/**
 * Une conversation avec l'administration, en bulles — la même pour le membre
 * (« Mes messages ») et pour l'administration : seul `cote` change, c'est-à-dire
 * quelles bulles sont « les miennes », à droite.
 *
 * Sous chaque bulle de mon côté, une coche : ✓ envoyé, ✓✓ vu. Sous la
 * **dernière** seulement, l'accusé en toutes lettres — « Envoyé », « Vu à
 * 14:32 », « Vu par Nathan à 14:32 » : le répéter sous chaque message noierait
 * la conversation.
 *
 * La zone de saisie envoie à « Envoyer », ou à Ctrl/⌘ + Entrée : Entrée seule
 * va à la ligne, sur un téléphone c'est ce qu'on attend d'elle.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import type { DetailDeFil } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import {
  accuseDeLecture,
  cleDuJour,
  dernierDeMonCote,
  estDeMonCote,
  heure,
  libelleDuJour,
} from './messagerie'

const {
  fil,
  envoiEnCours = false,
  erreurDEnvoi = null,
  desactive = false,
  motifDesactivation = null,
} = defineProps<{
  fil: DetailDeFil
  envoiEnCours?: boolean
  erreurDEnvoi?: unknown
  desactive?: boolean
  motifDesactivation?: string | null
}>()

const emit = defineEmits<{ envoyer: [texte: string] }>()

/** Le texte en cours. Il n'est vidé qu'à la réponse — voir `vider()`. */
const brouillon = defineModel<string>('brouillon', { default: '' })

const maintenant = ref(new Date())
const liste = ref<HTMLElement | null>(null)

const dernierMien = computed(() => dernierDeMonCote(fil.messages, fil.cote))

/** Les messages, avec un séparateur de jour devant le premier de chaque jour. */
const lignes = computed(() =>
  fil.messages.map((message, index) => {
    const precedent = fil.messages[index - 1]
    return {
      message,
      mien: estDeMonCote(message, fil.cote),
      jour: precedent === undefined || cleDuJour(precedent.envoyeLe) !== cleDuJour(message.envoyeLe)
        ? libelleDuJour(message.envoyeLe, maintenant.value)
        : null,
      accuse: index === dernierMien.value ? accuseDeLecture(message, maintenant.value) : null,
    }
  }),
)

/** L'auteur, au-dessus d'une bulle de l'autre côté — et des miennes écrites par un collègue. */
function signature(message: DetailDeFil['messages'][number], mien: boolean): string | null {
  if (mien && message.estMoi) return null
  if (message.deLAdministration) return fil.cote === 'membre' ? `Administration · ${message.auteur}` : message.auteur
  return message.auteur
}

async function descendre(): Promise<void> {
  await nextTick()
  const el = liste.value
  if (el !== null) el.scrollTop = el.scrollHeight
}

onMounted(() => void descendre())
watch(
  () => fil.messages.length,
  () => {
    maintenant.value = new Date()
    void descendre()
  },
)

const peutEnvoyer = computed(() => !desactive && !envoiEnCours && brouillon.value.trim() !== '')

function envoyer(): void {
  if (!peutEnvoyer.value) return
  emit('envoyer', brouillon.value.trim())
}

function surTouche(e: KeyboardEvent): void {
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
    e.preventDefault()
    envoyer()
  }
}
</script>

<template>
  <div class="flex flex-col">
    <ol
      ref="liste"
      class="flex max-h-[60dvh] min-h-48 flex-col gap-1 overflow-y-auto px-1 py-3"
      aria-label="Messages"
      aria-live="polite"
    >
      <li v-for="ligne in lignes" :key="ligne.message.id" class="flex flex-col">
        <p v-if="ligne.jour !== null" class="my-3 text-center text-[11px] font-semibold uppercase tracking-wide text-attenue">
          {{ ligne.jour }}
        </p>
        <div class="flex flex-col" :class="ligne.mien ? 'items-end' : 'items-start'">
          <span v-if="signature(ligne.message, ligne.mien) !== null" class="mb-0.5 px-1 text-[11px] text-attenue">
            {{ signature(ligne.message, ligne.mien) }}
          </span>
          <div
            class="max-w-[85%] whitespace-pre-wrap break-words rounded-panneau px-3.5 py-2 text-[14.5px] leading-snug"
            :class="ligne.mien ? 'rounded-br-md bg-accent text-fond' : 'rounded-bl-md border border-ligne bg-panneau text-texte'"
            :data-message="ligne.message.id"
          >
            {{ ligne.message.texte }}
          </div>
          <span class="mt-0.5 flex items-center gap-1 px-1 text-[11px] text-attenue">
            <time :datetime="ligne.message.envoyeLe">{{ heure(new Date(ligne.message.envoyeLe)) }}</time>
            <template v-if="ligne.mien">
              <span
                :class="ligne.message.vuLe !== null ? 'text-accent' : ''"
                :aria-label="ligne.message.vuLe !== null ? 'vu' : 'envoyé'"
                :title="accuseDeLecture(ligne.message, maintenant)"
              >{{ ligne.message.vuLe !== null ? '✓✓' : '✓' }}</span>
              <span v-if="ligne.accuse !== null" data-accuse>· {{ ligne.accuse }}</span>
            </template>
          </span>
        </div>
      </li>
    </ol>

    <form class="flex flex-col gap-2 border-t border-ligne pt-3" @submit.prevent="envoyer">
      <p v-if="desactive && motifDesactivation" class="m-0 text-xs text-attenue" role="status">{{ motifDesactivation }}</p>
      <MessageErreur v-if="erreurDEnvoi !== null" :erreur="erreurDEnvoi" titre="Le message n’est pas parti" />
      <div class="flex items-end gap-2">
        <label class="sr-only" for="message-du-fil">Votre message</label>
        <textarea
          id="message-du-fil"
          v-model="brouillon"
          rows="2"
          maxlength="5000"
          class="min-h-11 flex-1 resize-y rounded-champ border border-ligne bg-panneau px-3 py-2 text-[15px] text-texte placeholder:text-attenue focus:border-accent focus:outline-none"
          :placeholder="fil.statut === 'clos' && fil.cote === 'membre' ? 'Écrire de nouveau rouvre la conversation…' : 'Votre message…'"
          :disabled="desactive"
          @keydown="surTouche"
        />
        <BoutonBase type="submit" :chargement="envoiEnCours" :desactive="!peutEnvoyer">Envoyer</BoutonBase>
      </div>
    </form>
  </div>
</template>
