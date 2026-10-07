<script setup lang="ts">
/**
 * Les conversations, une ligne chacune : la nature, le sujet, le dernier
 * message en extrait, et la pastille des non-lus. Les non lues d'abord.
 *
 * Partagée par « Mes messages » et par l'administration : `route` dit où mène
 * une ligne, `avecMembre` ajoute qui a écrit — l'administration en a besoin, le
 * membre sait que c'est lui.
 */
import { computed } from 'vue'
import type { ResumeDeFil } from '@/lib/api'
import { horodatageCourt, libelleNonLus, rangerLesFils } from './messagerie'

const { fils, route, avecMembre = false } = defineProps<{
  fils: readonly ResumeDeFil[]
  route: 'messagerie' | 'administration.messagerie'
  avecMembre?: boolean
}>()

const maintenant = new Date()
const ranges = computed(() => rangerLesFils(fils))

/** « Vous : … » devant l'extrait quand le dernier mot est de mon côté. */
function prefixe(f: ResumeDeFil): string {
  const dernier = f.dernierMessage
  if (dernier === null) return ''
  const deMonCote = dernier.deLAdministration === (f.cote === 'administration')
  return deMonCote ? (f.cote === 'administration' ? 'Administration : ' : 'Vous : ') : ''
}
</script>

<template>
  <ul class="flex flex-col divide-y divide-ligne overflow-hidden rounded-panneau border border-ligne bg-panneau">
    <li v-for="f in ranges" :key="f.id">
      <RouterLink
        :to="{ name: route, params: { id: f.id } }"
        class="flex items-start gap-3 px-4 py-3 hover:bg-accent-doux"
        :data-fil="f.id"
      >
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2">
            <span class="flex-none rounded-pastille bg-accent-doux px-1.5 py-px text-[11px] font-semibold text-accent">
              {{ f.natureLibelle }}
            </span>
            <span class="truncate text-sm" :class="f.nonLus > 0 ? 'font-semibold text-texte' : 'text-texte'">{{ f.sujet }}</span>
          </div>
          <p v-if="avecMembre" class="mt-0.5 truncate text-xs text-attenue">{{ f.membre.libelle }}</p>
          <p v-if="f.dernierMessage !== null" class="mt-0.5 truncate text-xs text-attenue">
            {{ prefixe(f) }}{{ f.dernierMessage.extrait }}
          </p>
        </div>
        <div class="flex flex-none flex-col items-end gap-1">
          <span class="text-[11px] text-attenue tnum">{{ horodatageCourt(f.modifieLe, maintenant) }}</span>
          <span
            v-if="f.nonLus > 0"
            class="flex h-[18px] min-w-[18px] items-center justify-center rounded-pastille bg-accent px-1 text-[11px] font-bold text-fond tnum"
            :aria-label="libelleNonLus(f.nonLus)"
          >{{ f.nonLus }}</span>
          <span v-else-if="f.statut === 'clos'" class="text-[11px] text-attenue">{{ f.statutLibelle }}</span>
        </div>
      </RouterLink>
    </li>
  </ul>
</template>
