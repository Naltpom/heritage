<script setup lang="ts">
/**
 * L'avis global (`composables/avisGlobal.ts`) : une carte en bas d'écran, au-
 * dessus de la barre de navigation du téléphone (`--hauteur-barre-bas`).
 *
 * **Non bloquant** : pas de voile, pas de piège du focus — l'écran dessous
 * reste utilisable, et la carte ne capte que ses propres clics.
 *
 * La région `aria-live` est **toujours** dans le document, vide au repos : une
 * région qui apparaît en même temps que son texte n'est pas annoncée par tous
 * les lecteurs d'écran. Monté hors du bloc du cadre dans `App.vue` : un écran
 * sans cadre (connexion) peut aussi échouer à se charger.
 */
import BoutonBase from '@/components/base/BoutonBase.vue'
import { useAvisGlobal } from '@/composables/avisGlobal'

const { avis, fermer } = useAvisGlobal()

function agir(): void {
  const action = avis.value?.action
  fermer()
  action?.executer()
}
</script>

<template>
  <div
    class="pointer-events-none fixed inset-x-0 bottom-[calc(var(--hauteur-barre-bas,0px)+1rem)] z-50 flex justify-center px-4"
    role="status"
    aria-live="polite"
    data-avis-global
  >
    <div
      v-if="avis !== null"
      :key="avis.id"
      class="pointer-events-auto flex w-full max-w-[32rem] flex-wrap items-center gap-x-3 gap-y-2 rounded-panneau border bg-panneau px-4 py-3 text-sm shadow-panneau"
      :class="avis.ton === 'erreur' ? 'border-alerte-bord' : 'border-ligne'"
      :data-ton="avis.ton"
    >
      <p class="m-0 min-w-0 flex-1 basis-60" :class="avis.ton === 'erreur' ? 'text-alerte' : 'text-texte'">
        {{ avis.message }}
      </p>
      <div class="flex flex-none items-center gap-2">
        <BoutonBase v-if="avis.action !== null" variante="secondaire" @click="agir">
          {{ avis.action.libelle }}
        </BoutonBase>
        <BoutonBase variante="discret" @click="fermer">Fermer</BoutonBase>
      </div>
    </div>
  </div>
</template>
