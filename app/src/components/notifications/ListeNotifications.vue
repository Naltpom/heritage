<script setup lang="ts">
/**
 * Le corps de la cloche : chargement, erreur, état vide ou liste.
 *
 * Séparé de `ClocheNotifications` pour une raison de preuve, pas de goût : la
 * cloche ne rend son panneau qu'**ouverte**, et un rendu serveur (l'idiome de
 * test du dépôt, sans navigateur) la rend fermée. Ici, tout ce que le lecteur
 * lit est une fonction des props — donc se rend et se vérifie.
 *
 * Une notification qui mène quelque part est un **lien** (`RouterLink`) : il
 * s'ouvre dans un nouvel onglet au clic du milieu, comme tout lien. Une dont
 * la route a disparu (`lienDeNotification` → `null`) reste lisible, en bouton
 * qui la marque lue sans naviguer.
 */
import { useRouter, type RouteLocationRaw } from 'vue-router'
import type { Notification } from '@/lib/api'
import type { EtatListe } from '@/composables/useNotifications'
import { dateRelative, libelleDeType, lienDeNotification, tonDeType } from '@/lib/notifications'

const { notifications, etat, maintenant } = defineProps<{
  notifications: readonly Notification[]
  etat: EtatListe
  /** Heure de référence des dates relatives, figée par la cloche à l'ouverture. */
  maintenant: Date
}>()
const emit = defineEmits<{
  /** Clic sur une notification ; `navigue` dit si un lien emmène ailleurs. */
  choisir: [notification: Notification, navigue: boolean]
  reessayer: []
}>()

const routeur = useRouter()

/** Le `try` couvre un routeur incomplet (les bouchons de test de l'en-tête). */
function lien(n: Notification): RouteLocationRaw | null {
  try {
    return lienDeNotification(routeur, n)
  } catch {
    return null
  }
}

const CLASSES_TON: Record<'alerte' | 'ok' | 'neutre', string> = {
  alerte: 'text-alerte',
  ok: 'text-ok',
  neutre: 'text-attenue',
}
</script>

<template>
  <p v-if="etat === 'chargement' && notifications.length === 0" class="px-4 py-8 text-center text-[13px] text-attenue">
    Chargement…
  </p>

  <div v-else-if="etat === 'erreur'" role="alert" class="flex flex-col items-center gap-3 px-4 py-8 text-center">
    <p class="text-[13px] text-attenue">Les notifications n’ont pas pu être chargées.</p>
    <button
      type="button"
      class="h-9 rounded-champ border border-ligne px-4 text-[13px] font-semibold hover:border-accent"
      @click="emit('reessayer')"
    >
      Réessayer
    </button>
  </div>

  <div v-else-if="notifications.length === 0" data-vide class="flex flex-col items-center gap-2 px-6 py-10 text-center">
    <span class="text-[15px] font-semibold">Rien de nouveau</span>
    <span class="text-[13px] text-attenue">Vous serez prévenu ici quand une de vos demandes sera acceptée ou refusée.</span>
  </div>

  <ul v-else class="divide-y divide-ligne">
    <li v-for="n in notifications" :key="n.id">
      <component
        :is="lien(n) ? 'RouterLink' : 'button'"
        v-bind="lien(n) ? { to: lien(n) } : { type: 'button' }"
        class="flex w-full gap-3 px-4 py-3 text-left hover:bg-fond max-md:min-h-16"
        :class="n.readAt === null ? 'bg-accent-doux/40' : ''"
        :data-non-lue="n.readAt === null ? '' : undefined"
        @click="emit('choisir', n, lien(n) !== null)"
      >
        <span class="mt-1.5 h-2 w-2 flex-none rounded-pastille" :class="n.readAt === null ? 'bg-accent' : 'bg-transparent'" aria-hidden="true" />
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
          <span class="flex items-baseline gap-2 text-[11.5px]">
            <span class="font-semibold" :class="CLASSES_TON[tonDeType(n.kind)]">{{ libelleDeType(n.kind) }}</span>
            <span class="ml-auto flex-none text-attenue tnum">{{ dateRelative(n.createdAt, maintenant) }}</span>
          </span>
          <span class="text-[14px] leading-snug" :class="n.readAt === null ? 'font-semibold' : ''">{{ n.title }}</span>
          <span v-if="n.body" class="line-clamp-3 text-[13px] leading-snug text-attenue">{{ n.body }}</span>
          <span v-if="n.readAt === null" class="sr-only">(non lue)</span>
        </span>
      </component>
    </li>
  </ul>
</template>
