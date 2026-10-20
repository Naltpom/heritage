<script setup lang="ts">
/**
 * Ajouts et vérifications, jour par jour — deux courbes en SVG sur une même
 * échelle (des personnes par jour), de la même facture que `Courbe.vue` :
 * `viewBox` étiré à la largeur, trait d'épaisseur constante, aucun texte dans le
 * SVG (les nombres sont du HTML, qu'aucun étirement ne déforme).
 *
 * ────────────────────────────────────────────────────────────────────
 * **Une courbe et non des colonnes.** La période va de 30 jours à un an : 365
 * paires de colonnes sur un téléphone feraient moins d'un pixel chacune, quand
 * un trait reste lisible à toutes les échelles. Le pic d'un import se voit comme
 * tel.
 *
 * **Lisible sans la couleur.** Les ajouts ont un lavis sous leur trait, les
 * vérifications un trait seul : les deux séries se distinguent aussi par la
 * forme — le brun de l'accent et le vert de « vérifié » se confondent pour un
 * œil protanope. Une légende écrite, le détail du jour sous le pointeur, et le
 * tableau pour les lecteurs d'écran.
 *
 * **Le pointeur trouve le jour, pas le trait.** Un filet vertical se cale sur le
 * jour le plus proche, et la ligne au-dessus de la courbe dit le jour et les deux
 * nombres — au-dessus et non dans une bulle : sur un téléphone, une bulle
 * tomberait sous le doigt. Au toucher, le doigt glisse de jour en jour (le
 * défilement vertical reste à la page) et le détail reste affiché après lui ; au
 * clavier, la courbe est un curseur (flèches, Page précédente / suivante pour une
 * semaine, Début, Fin).
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, ref, watch } from 'vue'
import { compte, jourLisible, nombre } from './format'
import { COULEUR_AJOUTEES, COULEUR_VERIFIEES, hautDeLEchelle, type JourAffiche } from './parJour'

const props = defineProps<{
  /** Ce que la courbe montre, en une phrase : le résumé du lecteur d'écran. */
  titre: string
  jours: JourAffiche[]
}>()

const LARGEUR = 300
const HAUTEUR = 100
/** La marge du `viewBox`, en haut et en bas : un trait posé sur 0 ou sur le haut de l'échelle n'est pas rogné. */
const MARGE = 4
/** Le lavis sous les ajouts : assez pour se voir sous un pic, pas assez pour faire un pavé. */
const OPACITE_DU_LAVIS = 0.18

const haut = computed(() => hautDeLEchelle(Math.max(0, ...props.jours.flatMap((j) => [j.ajoutees, j.verifiees]))))

function x(i: number): number {
  const n = props.jours.length
  return n > 1 ? (i / (n - 1)) * LARGEUR : LARGEUR / 2
}

function y(valeur: number): number {
  return HAUTEUR - (valeur / haut.value) * HAUTEUR
}

/** Une ordonnée du `viewBox`, en pourcentage de la hauteur de la boîte : pour poser du HTML dessus. */
function enHauteur(ordonnee: number): string {
  return `${(100 * (ordonnee + MARGE)) / (HAUTEUR + 2 * MARGE)}%`
}

function trace(cle: 'ajoutees' | 'verifiees'): string {
  return props.jours.map((j, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(2)},${y(j[cle]).toFixed(2)}`).join(' ')
}

const traceAjoutees = computed(() => trace('ajoutees'))
const traceVerifiees = computed(() => trace('verifiees'))
const lavis = computed(() => (props.jours.length > 1 ? `${traceAjoutees.value} L${LARGEUR},${HAUTEUR} L0,${HAUTEUR} Z` : ''))

const premier = computed(() => props.jours[0] ?? null)
const dernier = computed(() => props.jours[props.jours.length - 1] ?? null)

/** Les jours où quelque chose a bougé : le tableau du lecteur d'écran ne liste pas 300 lignes de zéros. */
const joursActifs = computed(() => props.jours.filter((j) => j.ajoutees + j.verifiees > 0))

function phrase(j: JourAffiche): string {
  return `${jourLisible(j.jour, true)} : ${compte(j.ajoutees, 'personne ajoutée', 'personnes ajoutées')}, ${nombre(j.verifiees)} ${j.verifiees > 1 ? 'vérifiées' : 'vérifiée'}`
}

/* ------------------------------------------------------------------ */
/* Le jour sous le pointeur                                            */
/* ------------------------------------------------------------------ */

const zone = ref<HTMLElement | null>(null)
const actif = ref<number | null>(null)

// Une autre branche ou une autre période : l'indice ne désigne plus le même jour.
watch(() => props.jours, () => (actif.value = null))

const detail = computed(() => {
  const i = actif.value
  const j = i === null ? undefined : props.jours[i]
  if (i === null || j === undefined) return null
  return { ...j, gauche: `${(100 * x(i)) / LARGEUR}%`, hautAjoutees: enHauteur(y(j.ajoutees)), hautVerifiees: enHauteur(y(j.verifiees)) }
})

function borner(i: number): number {
  return Math.min(props.jours.length - 1, Math.max(0, i))
}

function suivre(evenement: PointerEvent): void {
  const element = zone.value
  const n = props.jours.length
  if (element === null || n === 0) return
  const cadre = element.getBoundingClientRect()
  const ratio = cadre.width > 0 ? (evenement.clientX - cadre.left) / cadre.width : 1
  actif.value = borner(Math.round(ratio * (n - 1)))
}

function quitter(evenement: PointerEvent): void {
  // Au toucher, le détail reste après le doigt : il disparaîtrait au moment où on le lit.
  if (evenement.pointerType === 'mouse') actif.value = null
}

const PAS_DU_CLAVIER: Record<string, number> = { ArrowLeft: -1, ArrowDown: -1, ArrowRight: 1, ArrowUp: 1, PageDown: -7, PageUp: 7 }

function clavier(evenement: KeyboardEvent): void {
  const n = props.jours.length
  if (n === 0) return
  const courant = actif.value ?? n - 1
  const pas = PAS_DU_CLAVIER[evenement.key]
  let suivant: number | null = null
  if (pas !== undefined) suivant = courant + pas
  else if (evenement.key === 'Home') suivant = 0
  else if (evenement.key === 'End') suivant = n - 1
  else if (evenement.key === 'Escape') actif.value = null
  if (suivant === null) return
  evenement.preventDefault()
  actif.value = borner(suivant)
}

/** Au clavier, la courbe s'ouvre sur aujourd'hui. */
function entrer(): void {
  if (actif.value === null && props.jours.length > 0) actif.value = props.jours.length - 1
}
</script>

<template>
  <figure class="m-0">
    <!-- Le détail du jour : une ligne réservée, pour que la courbe ne saute pas quand il paraît. -->
    <p class="m-0 mb-1 flex min-h-5 flex-wrap items-baseline gap-x-3 text-xs" aria-hidden="true">
      <template v-if="detail">
        <span class="text-attenue">{{ jourLisible(detail.jour, true) }}</span>
        <span class="inline-flex items-baseline gap-1">
          <span class="inline-block h-0.5 w-3 self-center rounded-full" :style="{ background: COULEUR_AJOUTEES }" />
          <strong class="font-semibold tabular-nums">{{ nombre(detail.ajoutees) }}</strong>
          <span class="text-attenue">{{ detail.ajoutees > 1 ? 'ajoutées' : 'ajoutée' }}</span>
        </span>
        <span class="inline-flex items-baseline gap-1">
          <span class="inline-block h-0.5 w-3 self-center rounded-full" :style="{ background: COULEUR_VERIFIEES }" />
          <strong class="font-semibold tabular-nums">{{ nombre(detail.verifiees) }}</strong>
          <span class="text-attenue">{{ detail.verifiees > 1 ? 'vérifiées' : 'vérifiée' }}</span>
        </span>
      </template>
      <span v-else class="text-attenue">Survolez ou touchez la courbe pour lire un jour.</span>
    </p>

    <div class="flex gap-1.5">
      <!-- L'échelle, en HTML : 0, le milieu, le haut — trois filets, trois nombres. -->
      <div class="relative h-32 w-8 flex-none font-mono text-[10px] leading-none text-attenue tabular-nums" aria-hidden="true">
        <span v-for="v in [haut, haut / 2, 0]" :key="v" class="absolute right-0 -translate-y-1/2" :style="{ top: enHauteur(y(v)) }">{{ nombre(v) }}</span>
      </div>

      <div
        ref="zone"
        class="relative h-32 min-w-0 flex-1 cursor-crosshair touch-pan-y rounded-sm"
        tabindex="0"
        role="slider"
        :aria-label="`${titre} — choisir un jour`"
        :aria-valuemin="0"
        :aria-valuemax="Math.max(0, jours.length - 1)"
        :aria-valuenow="actif ?? Math.max(0, jours.length - 1)"
        :aria-valuetext="detail ? phrase(detail) : dernier ? phrase(dernier) : 'Aucun jour'"
        @pointerdown="suivre"
        @pointermove="suivre"
        @pointerleave="quitter"
        @keydown="clavier"
        @focus="entrer"
        @blur="actif = null"
      >
        <svg
          :viewBox="`0 ${-MARGE} ${LARGEUR} ${HAUTEUR + 2 * MARGE}`"
          preserveAspectRatio="none"
          class="block h-full w-full overflow-visible"
          aria-hidden="true"
        >
          <line v-for="v in [haut, haut / 2, 0]" :key="v" x1="0" :y1="y(v)" :x2="LARGEUR" :y2="y(v)" stroke="var(--h-ligne)" stroke-width="1" vector-effect="non-scaling-stroke" />
          <path v-if="lavis" :d="lavis" :fill="COULEUR_AJOUTEES" :fill-opacity="OPACITE_DU_LAVIS" />
          <template v-if="jours.length > 1">
            <path :d="traceAjoutees" fill="none" :stroke="COULEUR_AJOUTEES" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            <!-- Un liseré de la couleur du panneau sous le trait des vérifications : là où les deux courbes se croisent, il passe devant, net. -->
            <path :d="traceVerifiees" fill="none" stroke="var(--h-panneau)" stroke-width="4" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            <path :d="traceVerifiees" fill="none" :stroke="COULEUR_VERIFIEES" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
          </template>
        </svg>

        <!-- Le filet du jour et ses deux points, en HTML : dans un SVG étiré, un cercle deviendrait une ellipse. -->
        <template v-if="detail">
          <span class="pointer-events-none absolute top-0 bottom-0 block w-px -translate-x-1/2 bg-attenue/60" :style="{ left: detail.gauche }" aria-hidden="true" />
          <span
            v-for="[haute, couleur] in ([[detail.hautAjoutees, COULEUR_AJOUTEES], [detail.hautVerifiees, COULEUR_VERIFIEES]] as const)"
            :key="couleur"
            class="pointer-events-none absolute block h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full"
            :style="{ left: detail.gauche, top: haute, background: couleur, boxShadow: '0 0 0 2px var(--h-panneau)' }"
            aria-hidden="true"
          />
        </template>
        <!-- Un seul jour : une courbe d'un point n'a pas de trait, il se dessine en pastilles. -->
        <template v-else-if="jours.length === 1 && premier">
          <span
            v-for="[haute, couleur] in ([[enHauteur(y(premier.ajoutees)), COULEUR_AJOUTEES], [enHauteur(y(premier.verifiees)), COULEUR_VERIFIEES]] as const)"
            :key="couleur"
            class="pointer-events-none absolute left-1/2 block h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full"
            :style="{ top: haute, background: couleur }"
            aria-hidden="true"
          />
        </template>
      </div>
    </div>

    <figcaption class="mt-1 pl-9.5 text-[11px] text-attenue" aria-hidden="true">
      <span v-if="premier && dernier" class="flex gap-2" :class="jours.length > 1 ? 'justify-between' : 'justify-center'">
        <span>{{ jourLisible(premier.jour) }}</span>
        <span v-if="jours.length > 1">{{ jourLisible(dernier.jour) }}</span>
      </span>
      <span class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs">
        <span class="inline-flex items-center gap-1.5">
          <span class="inline-block h-2.5 w-3 rounded-sm border-t-2" :style="{ borderColor: COULEUR_AJOUTEES, background: `color-mix(in srgb, ${COULEUR_AJOUTEES} ${100 * OPACITE_DU_LAVIS}%, transparent)` }" />
          Personnes ajoutées
        </span>
        <span class="inline-flex items-center gap-1.5">
          <span class="inline-block h-0.5 w-3 rounded-full" :style="{ background: COULEUR_VERIFIEES }" />
          Personnes vérifiées
        </span>
      </span>
    </figcaption>

    <!-- Le tableau dans une boîte `sr-only`, comme celui de `Barres.vue` : un tableau ne se réduit pas à 1 px. -->
    <div class="sr-only">
    <table>
      <caption>{{ titre }}{{ joursActifs.length < jours.length ? ' — les jours sans ajout ni vérification ne sont pas listés' : '' }}</caption>
      <thead>
        <tr>
          <th scope="col">Jour</th>
          <th scope="col">Personnes ajoutées</th>
          <th scope="col">Personnes vérifiées</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="j in joursActifs" :key="j.jour">
          <th scope="row">{{ jourLisible(j.jour, true) }}</th>
          <td>{{ nombre(j.ajoutees) }}</td>
          <td>{{ nombre(j.verifiees) }}</td>
        </tr>
      </tbody>
    </table>
    </div>
  </figure>
</template>
