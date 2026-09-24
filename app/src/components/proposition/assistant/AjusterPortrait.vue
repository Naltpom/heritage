<script setup lang="ts">
/**
 * « Ajuster » un portrait repéré : le recadrage sous un cercle de « Ma photo »
 * (`RecadrageSousCercle`), **ouvert sur le cadre** proposé par l'assistant — ou
 * sur le dernier ajustement.
 *
 * Rien n'est découpé ici : « Utiliser ce cadrage » rend le **cadre** choisi (en
 * millièmes de l'image), que le parent garde pour l'aperçu et découpe au dépôt.
 * « Cadre proposé » revient à celui de l'assistant.
 *
 * Même boîte de dialogue que `ChoixPortrait` : `<dialog>` modal, fermé par
 * Échap ou la croix, sans rien changer.
 */
import { nextTick, ref, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import RecadrageSousCercle from '@/components/medias/RecadrageSousCercle.vue'
import type { CadreVisage } from '@/components/medias/cadrage'

const { ouvert, image, cadre, cadrePropose, nom } = defineProps<{
  ouvert: boolean
  image: HTMLImageElement | null
  /** Le cadre sur lequel ouvrir : l'ajustement courant, ou celui de l'assistant. */
  cadre: CadreVisage | null
  /** Le cadre de l'assistant, pour y revenir. */
  cadrePropose: CadreVisage | null
  nom: string
}>()

const emit = defineEmits<{ fermer: []; valider: [cadre: CadreVisage] }>()

const dialogue = ref<HTMLDialogElement | null>(null)
const recadrage = ref<InstanceType<typeof RecadrageSousCercle> | null>(null)
/** Le cadre de départ de la scène ; changer de clé la remonte dessus. */
const depart = ref<CadreVisage | null>(null)
const cleScene = ref(0)

watch(
  () => ouvert,
  async (o) => {
    await nextTick()
    const d = dialogue.value
    if (!d) return
    if (o && !d.open) {
      depart.value = cadre
      cleScene.value++
      d.showModal()
    } else if (!o && d.open) d.close()
  },
  { immediate: true },
)

function revenirAuCadrePropose(): void {
  depart.value = cadrePropose
  cleScene.value++
}

function valider(): void {
  const choisi = recadrage.value?.cadreActuel()
  if (choisi) emit('valider', choisi)
  emit('fermer')
}
</script>

<template>
  <dialog
    ref="dialogue"
    class="m-auto w-[min(26rem,calc(100vw-1.5rem))] rounded-panneau border border-ligne bg-panneau p-0 text-texte shadow-panneau backdrop:bg-black/60"
    aria-labelledby="titre-ajuster-portrait"
    @close="emit('fermer')"
  >
    <div v-if="ouvert" class="flex flex-col gap-4 p-4">
      <div class="flex items-center justify-between gap-2">
        <h2 id="titre-ajuster-portrait" class="m-0 text-base font-semibold">Portrait de {{ nom }}</h2>
        <button
          type="button"
          class="h-9 w-9 flex-none text-lg text-attenue hover:text-texte"
          aria-label="Fermer"
          @click="emit('fermer')"
        >
          ×
        </button>
      </div>

      <template v-if="image">
        <RecadrageSousCercle :key="cleScene" ref="recadrage" :source="image" :cadre="depart" />
        <p class="m-0 text-center text-xs text-attenue">
          Glissez la photo pour placer le visage dans le cercle.
        </p>
        <div class="flex flex-wrap justify-end gap-2">
          <BoutonBase variante="discret" :desactive="cadrePropose === null" @click="revenirAuCadrePropose">
            Cadre proposé
          </BoutonBase>
          <BoutonBase @click="valider">Utiliser ce cadrage</BoutonBase>
        </div>
      </template>
    </div>
  </dialog>
</template>
