<script setup lang="ts">
/**
 * La vignette d'un PDF : **sa première page**, dessinée dans le navigateur.
 *
 * Le serveur ne produit pas de vignette pour un PDF (voir `GenerateurDeVignette`),
 * et son `vignetteUrl` rend `404` : une `<img>` y montrait une image cassée. On
 * dessine donc la première page, à la taille de la case — ce qui distingue deux
 * actes l'un de l'autre, là où une icône « PDF » les rendrait identiques.
 *
 * Le dessin attend que la case soit **visible** : une galerie de trente actes ne
 * télécharge pas trente PDF pour les cinq qu'on voit. Tant qu'il n'est pas fait,
 * ou s'il échoue, la case porte un simple « PDF » — jamais une image cassée.
 */
import { onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue'
import { dessinerPage, ouvrirPdf } from './pdf'

const { url, pages = null } = defineProps<{ url: string; pages?: number | null }>()

const cadre = useTemplateRef<HTMLDivElement>('cadre')
const toile = useTemplateRef<HTMLCanvasElement>('toile')
const dessinee = ref(false)

let observateur: IntersectionObserver | null = null
let abandonne = false

async function dessiner(): Promise<void> {
  const element = toile.value
  if (element === null) return
  try {
    const pdf = await ouvrirPdf(url)
    try {
      if (abandonne) return
      await dessinerPage(await pdf.document.getPage(1), element, cadre.value?.clientWidth ?? 320)
      if (!abandonne) dessinee.value = true
    } finally {
      await pdf.fermer()
    }
  } catch {
    // La case garde son « PDF » : la loupe, elle, dira l'échec en clair.
  }
}

onMounted(() => {
  if (cadre.value === null) return
  observateur = new IntersectionObserver((entrees) => {
    if (!entrees.some((entree) => entree.isIntersecting)) return
    observateur?.disconnect()
    observateur = null
    void dessiner()
  })
  observateur.observe(cadre.value)
})

onBeforeUnmount(() => {
  abandonne = true
  observateur?.disconnect()
})
</script>

<template>
  <div
    ref="cadre"
    class="relative aspect-[4/3] w-full overflow-hidden rounded-champ border border-ligne bg-white"
  >
    <canvas ref="toile" class="block h-auto w-full" :class="{ invisible: !dessinee }" aria-hidden="true" />
    <span
      v-if="!dessinee"
      class="absolute inset-0 flex flex-col items-center justify-center gap-0.5 text-attenue"
    >
      <span class="text-sm font-semibold tracking-wide">PDF</span>
      <span v-if="pages !== null" class="text-[11px]">
        {{ pages }} page<template v-if="pages > 1">s</template>
      </span>
    </span>
  </div>
</template>
