<script setup lang="ts">
/**
 * L'interrupteur Membre / Admin (handoff « mode Administration », 1c).
 *
 * **Deux formes, un comportement** : il navigue, vers la dernière route de
 * l'autre mode (`composables/modeAdmin.ts`). Le mode lui-même se lit dans la
 * route ; l'interrupteur ne retient rien.
 *
 * - `barre` — le 5ᵉ emplacement de la barre du bas, sur téléphone : une piste
 *   de 38 × 22 px dont la pastille passe à droite en mode admin, au-dessus du
 *   libellé « Admin ». Un **`role="switch"`** et `aria-checked` : c'est un
 *   interrupteur pour qui ne voit pas la piste.
 * - `segmente` — dans l'en-tête du bureau, juste avant l'avatar : deux boutons
 *   « Membre » / « Admin », l'actif sur fond panneau.
 *
 * Ce sont des **liens** (`RouterLink`) : le mode est une adresse, et un clic du
 * milieu ouvre l'autre mode dans un onglet. La forme `barre` répond aussi à la
 * barre d'espace, comme un interrupteur.
 *
 * Le parent ne le rend qu'à un administrateur (`ROLE_ADMIN`).
 */
import { useModeAdmin } from '@/composables/modeAdmin'

const { forme } = defineProps<{ forme: 'barre' | 'segmente' }>()

const { estAdmin, cibleDeBascule } = useModeAdmin()

function activer(evenement: KeyboardEvent): void {
  ;(evenement.currentTarget as HTMLElement | null)?.click()
}
</script>

<template>
  <RouterLink
    v-if="forme === 'barre'"
    :to="cibleDeBascule"
    role="switch"
    :aria-checked="estAdmin ? 'true' : 'false'"
    aria-label="Mode administration"
    class="interrupteur-mode flex min-h-[52px] min-w-0 flex-1 flex-col items-center justify-center gap-[5px] text-[11px] leading-none font-semibold"
    :class="estAdmin ? 'text-accent' : 'text-attenue'"
    data-interrupteur="barre"
    @keydown.space.prevent="activer"
  >
    <span
      class="flex h-[22px] w-[38px] rounded-pastille p-0.5"
      :class="estAdmin ? 'justify-end bg-accent' : 'piste-membre justify-start'"
      aria-hidden="true"
    >
      <span class="size-[18px] rounded-full bg-panneau shadow-[0_1px_2px_rgb(0_0_0/.25)]" />
    </span>
    <span>Admin</span>
  </RouterLink>

  <div
    v-else
    class="flex gap-0.5 rounded-pastille p-[3px]"
    :class="estAdmin ? 'bg-[rgb(255_254_252/.18)]' : 'segment-membre'"
    role="group"
    aria-label="Mode"
    data-interrupteur="segmente"
  >
    <component
      :is="estAdmin ? 'RouterLink' : 'span'"
      v-bind="estAdmin ? { to: cibleDeBascule } : {}"
      class="flex h-[30px] items-center rounded-pastille px-3.5 text-[13px] font-semibold whitespace-nowrap"
      :class="estAdmin ? 'text-fond hover:bg-[rgb(255_254_252/.12)]' : 'bg-panneau text-texte'"
      :aria-current="estAdmin ? undefined : 'true'"
    >
      Membre
    </component>
    <component
      :is="estAdmin ? 'span' : 'RouterLink'"
      v-bind="estAdmin ? {} : { to: cibleDeBascule }"
      class="flex h-[30px] items-center rounded-pastille px-3.5 text-[13px] font-semibold whitespace-nowrap"
      :class="estAdmin ? 'bg-panneau text-accent' : 'text-attenue hover:text-texte'"
      :aria-current="estAdmin ? 'true' : undefined"
    >
      Admin
    </component>
  </div>
</template>

<style scoped>
/* La piste en mode membre : une ligne plus foncée que le filet ordinaire
   (prototype : #cfc9bf), dérivée pour suivre le thème sombre. */
.piste-membre {
  background: color-mix(in srgb, var(--h-ligne) 92%, var(--h-texte));
}
/* Le fond du segmenté en mode membre (prototype : #ebe7e1). */
.segment-membre {
  background: color-mix(in srgb, var(--h-ligne) 50%, var(--h-fond));
}
</style>
