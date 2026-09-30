<script setup lang="ts">
/**
 * Un PDF lu dans la loupe, **toutes ses pages l'une sous l'autre**, comme une image.
 *
 * Chaque page est dessinée à la largeur réelle du cadre, à la densité de l'écran :
 * une copie intégrale d'état civil se lit donc sans zoomer sur un téléphone comme
 * sur un poste. Les pages sont dessinées **dans l'ordre et une à la fois** — un
 * acte notarié de quarante pages ne décode pas quarante toiles d'un coup.
 *
 * Le lien « ouvrir dans un nouvel onglet » reste sous le lecteur, et pas seulement
 * en cas d'échec : c'est par là qu'on imprime ou qu'on enregistre la pièce, et
 * c'est le recours pour un PDF que pdf.js ne sait pas lire sous la CSP du site
 * (voir `pdf.ts`).
 */
import { nextTick, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { dessinerPage, ouvrirPdf, type PdfOuvert } from './pdf'

const { url, legende } = defineProps<{ url: string; legende: string }>()

const cadre = useTemplateRef<HTMLDivElement>('cadre')
/** Rangées par numéro de page : un `ref` de `v-for` ne garantit pas l'ordre. */
const toiles: HTMLCanvasElement[] = []
const nombreDePages = ref(0)
const chargement = ref(false)
const echec = ref(false)

let pdf: PdfOuvert | null = null
/** Incrémenté à chaque chargement : un rendu parti pour l'ancien PDF s'arrête. */
let generation = 0

async function charger(): Promise<void> {
  const courante = ++generation
  await fermer()
  nombreDePages.value = 0
  echec.value = false
  chargement.value = true

  try {
    const lu = await ouvrirPdf(url)
    if (courante !== generation) {
      await lu.fermer()
      return
    }
    pdf = lu
    nombreDePages.value = lu.document.numPages
    chargement.value = false
    await nextTick()

    const largeur = cadre.value?.clientWidth ?? 800
    for (let numero = 1; numero <= lu.document.numPages; numero++) {
      if (courante !== generation) return
      const toile = toiles[numero - 1]
      if (toile === undefined) continue
      await dessinerPage(await lu.document.getPage(numero), toile, largeur)
    }
  } catch {
    if (courante === generation) {
      chargement.value = false
      echec.value = true
    }
  }
}

async function fermer(): Promise<void> {
  const ancien = pdf
  pdf = null
  toiles.length = 0
  await ancien?.fermer()
}

watch(() => url, charger, { immediate: true })

onBeforeUnmount(() => {
  generation++
  void fermer()
})
</script>

<template>
  <div ref="cadre" class="w-full">
    <SqueletteChargement v-if="chargement" :lignes="1" hauteur="h-64" />

    <p v-else-if="echec" class="rounded-panneau bg-panneau px-4 py-6 text-center text-sm">
      Ce PDF n’a pas pu être affiché ici.
    </p>

    <div v-else class="flex flex-col gap-3">
      <canvas
        v-for="numero in nombreDePages"
        :key="numero"
        :ref="(el) => { if (el) toiles[numero - 1] = el as HTMLCanvasElement }"
        class="mx-auto block h-auto w-full rounded-panneau bg-white"
        role="img"
        :aria-label="nombreDePages > 1 ? `${legende} — page ${numero} sur ${nombreDePages}` : legende"
      />
    </div>

    <p class="mt-2 text-center text-sm">
      <a :href="url" target="_blank" rel="noopener" class="font-semibold text-white underline">
        Ouvrir le PDF dans un nouvel onglet ↗
      </a>
    </p>
  </div>
</template>
