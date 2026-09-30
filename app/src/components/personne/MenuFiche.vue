<script setup lang="ts">
/**
 * Le bouton **« Proposer »** de la fiche, et le petit menu qu'il ouvre.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Un seul bouton (refonte du 30/09/2026)
 *
 * Avant, la fiche portait « Proposer une modification », un menu ⋯ avec
 * « Ajouter un document » et « Proposer avec l'assistant », et, derrière un
 * filet, « Demander la suppression » : trois portes, dont deux menaient au même
 * endroit sous deux noms. Chaque chose a désormais **une seule porte** : ce
 * bouton, sur le bandeau desktop comme dans la barre d'action mobile. Il ouvre,
 * dans cet ordre :
 *
 * 1. « Corriger ou ajouter un proche » — une seule entrée vers
 *    **l'assistant** (`proposition.creation`, qui l'ouvre par défaut), avec
 *    cette fiche pour contexte (`?personne=<id>`). C'est l'assistant qui sait
 *    faire d'une phrase une demande bien formée — une correction de champ, un
 *    lien de parenté (nature `ajout_lien`, une autre demande qu'une correction),
 *    une personne nouvelle. Deux libellés pour une même porte, parce que le
 *    lecteur cherche l'un **ou** l'autre, et qu'un contributeur qui veut
 *    « ajouter le père » ne doit pas avoir à deviner qu'il le trouvera derrière
 *    « corriger » ;
 * 3. « Joindre un document » — l'onglet Sources et documents, à la zone de dépôt
 *    de la galerie (`#depot`) ;
 * 4. **un filet**, puis « Demander la suppression », en couleur d'alerte
 *    (`PointSuppression.vue`) : deux gestes, dont un seul détruit.
 *
 * ## Un lien, et rien d'autre — surtout pas un état transmis
 *
 * Aucune entrée ne transmet la `version` de la fiche. L'écran d'arrivée **relit
 * la fiche à son ouverture**, et la version qu'il en tire est par construction
 * celle que l'auteur a sous les yeux quand il commence à écrire — ce que
 * `baseVersion` doit valoir (§ 6.2). Cette fiche-ci a pu être chargée il y a
 * vingt minutes : lui pousser sa version ferait partir la demande sur un état
 * périmé, et le verrou optimiste la rejetterait en `409 verrou_concurrent`.
 *
 * ## Les conditions : celles d'aujourd'hui, chacune à sa place
 *
 * Chaque entrée porte sa propre condition (`gestes.ts`) — rattaché, et pas sous
 * la bascule « voir comme un membre » —, et **le bouton n'existe que s'il a au
 * moins une entrée** : un « Proposer » qui s'ouvre sur du vide est un bouton qui
 * ment. Masquer n'est pas une mesure de sécurité — la garde de route le double,
 * l'API double les deux. C'est éviter de proposer ce qui sera refusé.
 *
 * ## Le menu est dans le HTML, fermé
 *
 * `v-show` et non `v-if` : le menu est rendu, caché (`display: none`, donc hors
 * de l'arbre d'accessibilité aussi), ce qui permet au rendu serveur des tests de
 * vérifier ses entrées et leurs cibles sans simuler un clic.
 *
 * Fermeture : `Échap` (le focus revient sur le bouton), un clic ailleurs, ou le
 * choix d'une entrée. Les écouteurs du document ne sont posés qu'**après le
 * montage**, et seulement tant que le menu est ouvert : le rendu serveur ne
 * touche aucune interface de navigateur.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import { useGestesFiche } from './gestes'
import { requeteDOnglet } from './onglets'
import PointSuppression from './PointSuppression.vue'

const { fiche, sens = 'bas', disposition = 'bandeau' } = defineProps<{
  fiche: FichePersonneComplete
  /** `haut` : le menu s'ouvre au-dessus du bouton (barre fixée en bas). */
  sens?: 'bas' | 'haut'
  /** `bandeau` : bouton compact (desktop). `barre` : pleine largeur, 44 px. */
  disposition?: 'bandeau' | 'barre'
}>()

const { peutProposer, peutDeposer, peutDemanderSuppression } = useGestesFiche()

const aDesEntrees = computed(
  () => peutProposer.value || peutDeposer.value || peutDemanderSuppression.value,
)

/** L'assistant, avec cette fiche pour contexte. */
const versAssistant = computed(() => ({
  name: 'proposition.creation',
  query: { personne: String(fiche.id) },
}))

/** L'onglet Sources et documents, à la zone de dépôt de la galerie. */
const versDepot = computed(() => ({
  name: 'personne.detail',
  params: { id: fiche.id },
  query: requeteDOnglet('sources'),
  hash: '#depot',
}))

/** Ce que disait le panneau d'avant, gardé en infobulle : la proposition part en
 *  demande, personne ne modifie l'arbre directement. */
const EXPLICATION =
  'Votre proposition part en demande, qu’un administrateur valide ou refuse. En attendant, vous seul la voyez appliquée sur cette fiche.'

const ouvert = ref(false)
const racine = useTemplateRef<HTMLElement>('racine')
const bouton = useTemplateRef<HTMLButtonElement>('bouton')

function fermer(rendreFocus = false): void {
  ouvert.value = false
  if (rendreFocus) bouton.value?.focus()
}

function surTouche(evenement: KeyboardEvent): void {
  if (evenement.key === 'Escape') fermer(true)
}

function surClicDocument(evenement: MouseEvent): void {
  const cible = evenement.target
  if (cible instanceof Node && racine.value?.contains(cible)) return
  fermer()
}

watch(ouvert, (estOuvert) => {
  if (typeof document === 'undefined') return
  if (estOuvert) {
    document.addEventListener('keydown', surTouche)
    document.addEventListener('mousedown', surClicDocument)
  } else {
    document.removeEventListener('keydown', surTouche)
    document.removeEventListener('mousedown', surClicDocument)
  }
})

onBeforeUnmount(() => {
  if (typeof document === 'undefined') return
  document.removeEventListener('keydown', surTouche)
  document.removeEventListener('mousedown', surClicDocument)
})
</script>

<template>
  <div
    v-if="aDesEntrees"
    ref="racine"
    class="relative"
    :class="disposition === 'barre' ? 'min-w-0 flex-1' : 'flex-none'"
  >
    <button
      ref="bouton"
      type="button"
      data-geste="proposer"
      class="inline-flex w-full items-center justify-center gap-1.5 rounded-champ border border-accent bg-accent px-3.5 font-semibold whitespace-nowrap text-fond transition-opacity hover:opacity-90"
      :class="disposition === 'barre' ? 'h-11 text-[15px]' : 'h-9 text-[13.5px]'"
      aria-haspopup="menu"
      :aria-expanded="ouvert"
      :title="EXPLICATION"
      @click="ouvert = !ouvert"
    >
      Proposer
      <svg
        aria-hidden="true"
        viewBox="0 0 12 12"
        class="size-3 transition-transform"
        :class="ouvert !== (sens === 'haut') ? 'rotate-180' : ''"
      >
        <path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" />
      </svg>
    </button>

    <div
      v-show="ouvert"
      role="menu"
      aria-label="Proposer sur cette fiche"
      class="absolute z-40 w-72 overflow-hidden rounded-panneau border border-ligne bg-panneau py-1 shadow-panneau"
      :class="[
        sens === 'haut' ? 'bottom-[calc(100%+8px)]' : 'top-[calc(100%+6px)]',
        disposition === 'barre' ? 'inset-x-0 w-auto' : 'right-0',
      ]"
      @click="fermer()"
    >
      <template v-if="peutProposer">
        <RouterLink
          :to="versAssistant"
          role="menuitem"
          data-geste="corriger"
          class="flex min-h-11 items-center px-3.5 text-sm hover:bg-accent-doux md:min-h-10"
        >
          Corriger ou ajouter un proche
        </RouterLink>
      </template>

      <RouterLink
        v-if="peutDeposer"
        :to="versDepot"
        role="menuitem"
        data-geste="joindre-document"
        class="flex min-h-11 items-center px-3.5 text-sm hover:bg-accent-doux md:min-h-10"
      >
        Joindre un document
      </RouterLink>

      <!-- Le filet est porté par `PointSuppression` : il n'existe que si la
           suppression est offerte, et la sépare de tout ce qui précède. -->
      <PointSuppression :fiche="fiche" />
    </div>
  </div>
</template>
