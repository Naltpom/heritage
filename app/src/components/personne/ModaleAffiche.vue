<script setup lang="ts">
/**
 * « Générer l'affiche » : l'affiche généalogique d'un couple, calculée et dessinée dans le
 * navigateur, puis téléchargée en SVG, en PNG ou en PDF.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Ce que la modale fait, et ce qu'elle ne fait pas
 *
 * Elle lit l'arbre du couple (`GET /api/arbre/familles/{id}/affiche`) et les polices
 * (`affiche/chargement.ts`), puis confie tout au module `affiche/` : le placement (pur,
 * testé sans DOM) produit une scène, le rendu en fait un SVG. Ici, on ne place rien et on
 * ne filtre rien — le cercle et la réciprocité sont appliqués par le serveur ; l'option
 * « masquer les dates des vivants » est une mise en page, cochée par défaut.
 *
 * Les réglages, et ce que chacun déclenche :
 *
 * - **le conjoint**, quand la personne en a eu plusieurs : une affiche est un couple —
 *   une nouvelle lecture (gardée en mémoire le temps de la modale) ;
 * - **la vue** : l'éventail, le sablier, le sablier dense ;
 * - **les générations d'ascendants** (toutes, par défaut), **le masque des vivants** :
 *   un nouveau placement ;
 * - **le format** : rien qu'un nouveau cadrage — le dessin, agrandi pour remplir la page,
 *   est centré dessus ; la taille naturelle est celle qui découle de l'arbre.
 *
 * Le `<dialog>` natif, ouvert en modal, piège le focus et se ferme à Échap, comme
 * `ModaleCorrection`.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, nextTick, onMounted, ref, shallowRef, useId, watch } from 'vue'
import { lireArbreDAffiche, type ArbreDAffiche, type FichePersonneComplete } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import { chargerLesPolices, type PolicesChargees } from '@/affiche/chargement'
import { ArbreAffiche } from '@/affiche/donnees'
import { imprimerPdf, nomDeFichier, svgEnNoeud, telechargerPng, telechargerSvg } from '@/affiche/exports'
import { afficheEventail, afficheSablier, type ChiffresDuPied } from '@/affiche/fabriquer'
import { cotesLisibles, FORMATS, formatOriente, page, pixels, pngPossible, RESOLUTIONS, type CleDeFormat } from '@/affiche/formats'
import type { Scene } from '@/affiche/scene'
import { ascendance } from '@/affiche/structure'
import { versSvg } from '@/affiche/svg'

const {
  fiche,
  ouverte,
  lire = lireArbreDAffiche,
} = defineProps<{
  fiche: FichePersonneComplete
  ouverte: boolean
  /** La lecture de l'arbre ; remplaçable pour une vérification hors serveur. */
  lire?: (familleId: number, signal?: AbortSignal) => Promise<ArbreDAffiche>
}>()

const emit = defineEmits<{ fermer: [] }>()

const id = useId()
const dialogue = ref<HTMLDialogElement | null>(null)
const fermerBouton = ref<HTMLButtonElement | null>(null)
const apercu = ref<HTMLDivElement | null>(null)

/* --- Les réglages ---------------------------------------------------- */

const unions = computed(() => fiche.unions.filter((u) => u.conjoint !== null))
const familleId = ref<number | null>(unions.value[0]?.familyId ?? null)
type Vue = 'eventail' | 'sablier' | 'dense'
const VUES: { cle: Vue; libelle: string; aide: string }[] = [
  { cle: 'eventail', libelle: 'Éventail', aide: 'les ascendants en demi-cercle' },
  { cle: 'sablier', libelle: 'Sablier', aide: 'une rangée par génération' },
  { cle: 'dense', libelle: 'Sablier dense', aide: 'plus serré, pour les grands arbres' },
]
const vue = ref<Vue>('eventail')
/** `null` : toutes les générations connues. */
const profondeur = ref<number | null>(null)
const format = ref<CleDeFormat>('naturelle')
const masque = ref(true)
const dpi = ref<number>(150)

function libelleUnion(u: (typeof unions.value)[number]): string {
  const annee = u.marriage?.min?.slice(0, 4) ?? u.marriage?.max?.slice(0, 4)
  return `${u.conjoint!.displayName}${annee ? ` (${u.marriage?.qualifier === 'environ' ? 'vers ' : ''}${annee})` : ''}`
}

/* --- La lecture ------------------------------------------------------ */

type Etat =
  | { etat: 'repos' }
  | { etat: 'lecture' }
  | { etat: 'pret'; charge: ArbreDAffiche; polices: PolicesChargees }
  | { etat: 'erreur'; erreur: unknown }

const etat = shallowRef<Etat>({ etat: 'repos' })
const lues = new Map<number, ArbreDAffiche>()
let lecture: AbortController | null = null

async function charger(famille: number): Promise<void> {
  lecture?.abort()
  const controleur = new AbortController()
  lecture = controleur
  etat.value = { etat: 'lecture' }
  try {
    const [charge, polices] = await Promise.all([lues.get(famille) ?? lire(famille, controleur.signal), chargerLesPolices()])
    if (lecture !== controleur) return
    lues.set(famille, charge)
    etat.value = { etat: 'pret', charge, polices }
  } catch (e: unknown) {
    if (lecture !== controleur || controleur.signal.aborted) return
    etat.value = { etat: 'erreur', erreur: e }
  }
}

// Une autre fiche (navigation de fiche en fiche, modale déjà montée) : un autre couple.
watch(
  () => fiche.id,
  () => {
    familleId.value = unions.value[0]?.familyId ?? null
    profondeur.value = null
  },
)

watch(
  [() => ouverte, familleId],
  ([o, famille]) => {
    if (o && famille !== null) void charger(famille)
  },
  { immediate: true },
)

/* --- Le placement ---------------------------------------------------- */

/** Les deux époux de l'affiche : le mari à gauche, la femme à droite. */
const epoux = computed<{ h: number; f: number } | null>(() => {
  if (etat.value.etat !== 'pret') return null
  const { charge } = etat.value
  const couple = charge.familles.find((f) => f.id === charge.famille)
  return couple && couple.husband !== null && couple.wife !== null ? { h: couple.husband, f: couple.wife } : null
})

/** Le nombre de générations d'ascendants que l'arbre connaît. */
const generationsConnues = computed(() => {
  if (etat.value.etat !== 'pret' || epoux.value === null) return 0
  const A = new ArbreAffiche(etat.value.charge)
  return Math.max(...ascendance(A, A.P.get(epoux.value.h)!, A.P.get(epoux.value.f)!).noeuds.map((n) => n.niveau))
})

const production = computed<{ affiche: { scene: Scene; chiffres: ChiffresDuPied } } | { erreur: unknown } | null>(() => {
  if (etat.value.etat !== 'pret' || epoux.value === null) return null
  try {
    const A = new ArbreAffiche(etat.value.charge)
    if (masque.value) A.masquerVivants()
    const { h, f } = epoux.value
    const options = { profondeur: profondeur.value ?? Infinity }
    return { affiche: vue.value === 'eventail' ? afficheEventail(A, h, f, options) : afficheSablier(A, h, f, vue.value === 'dense' ? 'dense' : 'normal', options) }
  } catch (e: unknown) {
    return { erreur: e }
  }
})

const affiche = computed(() => (production.value && 'affiche' in production.value ? production.value.affiche : null))
const formatChoisi = computed(() => (affiche.value ? formatOriente(format.value, affiche.value.scene.largeur, affiche.value.scene.hauteur) : null))
const pageSortie = computed(() => (affiche.value ? page(format.value, affiche.value.scene.largeur, affiche.value.scene.hauteur) : null))

watch(
  [affiche, formatChoisi, apercu],
  () => {
    const cadre = apercu.value
    if (!cadre) return
    if (!affiche.value) {
      cadre.replaceChildren()
      return
    }
    cadre.replaceChildren(svgEnNoeud(document, versSvg(affiche.value.scene, { format: formatChoisi.value })))
  },
  { flush: 'post' },
)

/* --- Les fichiers ----------------------------------------------------- */

const nomBase = computed(() => {
  if (etat.value.etat !== 'pret' || epoux.value === null) return 'affiche'
  const personnes = etat.value.charge.personnes
  const nom = (pid: number): string => {
    const p = personnes.find((x) => x.id === pid)
    return p?.visibilite === 'complete' ? p.surname : ''
  }
  return nomDeFichier('affiche', nom(epoux.value.h), nom(epoux.value.f), vue.value) || 'affiche'
})

const occupe = ref<'svg' | 'png' | 'pdf' | null>(null)
const erreurExport = ref<string | null>(null)

async function exporter(type: 'svg' | 'png' | 'pdf'): Promise<void> {
  if (etat.value.etat !== 'pret' || !affiche.value || !pageSortie.value || occupe.value) return
  const { polices } = etat.value
  const { scene } = affiche.value
  occupe.value = type
  erreurExport.value = null
  // Laisser le bouton afficher son attente avant un calcul qui peut tenir le fil.
  await nextTick()
  await new Promise((r) => setTimeout(r, 30))
  try {
    if (type === 'svg') telechargerSvg(scene, formatChoisi.value, polices, nomBase.value)
    else if (type === 'png') await telechargerPng(scene, pageSortie.value, formatChoisi.value, dpi.value, polices, nomBase.value)
    else await imprimerPdf(scene, pageSortie.value, formatChoisi.value, polices, nomBase.value)
  } catch (e: unknown) {
    erreurExport.value = e instanceof Error ? e.message : 'Le fichier n’a pas pu être produit.'
  } finally {
    occupe.value = null
  }
}

const resolutions = computed(() =>
  RESOLUTIONS.map((r) => {
    const p = pageSortie.value
    const [l, h] = p ? pixels(p, r) : [0, 0]
    return { dpi: r, libelle: `${r} ppp — ${l.toLocaleString('fr-FR')} × ${h.toLocaleString('fr-FR')} px`, possible: p ? pngPossible(p, r) : false }
  }),
)
watch(resolutions, (rs) => {
  // Une résolution devenue impossible (format agrandi) retombe sur la plus haute possible.
  if (!rs.find((r) => r.dpi === dpi.value)?.possible) {
    const possible = rs.filter((r) => r.possible)
    if (possible.length > 0) dpi.value = possible[possible.length - 1]!.dpi
  }
})

/* --- Le dialogue ------------------------------------------------------ */

async function suivre(o: boolean): Promise<void> {
  const element = dialogue.value
  if (!element) return
  if (o && !element.open) {
    element.showModal()
    await nextTick()
    fermerBouton.value?.focus()
  } else if (!o && element.open) {
    element.close()
  }
}
watch(() => ouverte, suivre, { flush: 'post' })
// La modale est montée au premier clic, déjà ouverte : le `<dialog>` n'existe qu'ici.
onMounted(() => void suivre(ouverte))

function surFermeture(): void {
  lecture?.abort()
  if (ouverte) emit('fermer')
}

const classeChamp = 'w-full rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte'
</script>

<template>
  <dialog
    ref="dialogue"
    class="m-auto w-[min(76rem,calc(100vw-1.5rem))] rounded-panneau border border-ligne bg-panneau p-0 text-texte shadow-panneau backdrop:bg-black/50"
    :aria-labelledby="`${id}-titre`"
    data-modale-affiche
    @close="surFermeture"
  >
    <div class="flex max-h-[92dvh] flex-col">
      <header class="flex items-center justify-between gap-3 border-b border-ligne px-5 py-3 max-md:px-4">
        <h2 :id="`${id}-titre`" class="m-0 text-base font-semibold">Affiche généalogique</h2>
        <button
          ref="fermerBouton"
          type="button"
          class="inline-flex size-9 items-center justify-center rounded-champ border border-ligne text-attenue hover:border-accent hover:text-texte"
          aria-label="Fermer"
          title="Fermer"
          @click="emit('fermer')"
        >
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M6 6l12 12M18 6 6 18" />
          </svg>
        </button>
      </header>

      <div class="grid min-h-0 flex-1 grid-cols-[17.5rem_minmax(0,1fr)] overflow-hidden max-md:grid-cols-1 max-md:overflow-y-auto">
        <!-- Les réglages -->
        <form class="flex flex-col gap-4 overflow-y-auto border-r border-ligne px-5 py-4 max-md:border-r-0 max-md:border-b max-md:px-4" @submit.prevent>
          <p class="m-0 text-[13px] leading-relaxed text-attenue">
            Le couple au centre, ses ascendants au-dessus, tous ses descendants en dessous.
          </p>

          <label v-if="unions.length > 1" class="flex flex-col gap-1">
            <span class="text-xs font-semibold text-attenue">Le couple</span>
            <select v-model="familleId" :class="classeChamp" data-reglage="conjoint">
              <option v-for="u in unions" :key="u.familyId" :value="u.familyId">{{ fiche.displayName }} et {{ libelleUnion(u) }}</option>
            </select>
          </label>
          <p v-else-if="unions[0]" class="m-0 text-sm">
            <span class="block text-xs font-semibold text-attenue">Le couple</span>
            {{ fiche.displayName }} et {{ libelleUnion(unions[0]) }}
          </p>

          <fieldset class="m-0 flex flex-col gap-1.5 border-0 p-0">
            <legend class="mb-1 p-0 text-xs font-semibold text-attenue">La vue</legend>
            <label v-for="v in VUES" :key="v.cle" class="flex items-baseline gap-2 text-sm" :data-vue="v.cle">
              <input v-model="vue" type="radio" name="vue" :value="v.cle" />
              <span>{{ v.libelle }} <span class="text-xs text-attenue">— {{ v.aide }}</span></span>
            </label>
          </fieldset>

          <label class="flex flex-col gap-1">
            <span class="text-xs font-semibold text-attenue">Générations d’ascendants</span>
            <select v-model="profondeur" :class="classeChamp" :disabled="generationsConnues === 0" data-reglage="generations">
              <option :value="null">Toutes ({{ generationsConnues }})</option>
              <option v-for="g in Math.max(generationsConnues - 1, 0)" :key="g" :value="g">{{ g }}</option>
            </select>
          </label>

          <label class="flex flex-col gap-1">
            <span class="text-xs font-semibold text-attenue">Format</span>
            <select v-model="format" :class="classeChamp" data-reglage="format">
              <option v-for="f in FORMATS" :key="f.cle" :value="f.cle">{{ f.libelle }}</option>
            </select>
            <span v-if="affiche && pageSortie" class="text-xs text-attenue">
              <template v-if="formatChoisi">Page {{ cotesLisibles(pageSortie) }}, dessin agrandi et centré</template>
              <template v-else>{{ cotesLisibles(pageSortie) }}, la taille que donne l’arbre</template>
            </span>
          </label>

          <label class="flex items-start gap-2 text-sm">
            <input v-model="masque" type="checkbox" class="mt-0.5" data-reglage="masque" />
            <span>
              Masquer les dates des vivants
              <span class="block text-xs text-attenue">Les vivants gardent leur nom ; leurs dates, et le jour et le lieu de leur mariage, ne sont pas écrits.</span>
            </span>
          </label>

          <div class="flex flex-col gap-2 border-t border-ligne pt-4">
            <span class="text-xs font-semibold text-attenue">Télécharger</span>
            <BoutonBase variante="secondaire" :desactive="!affiche || occupe !== null" :chargement="occupe === 'svg'" data-export="svg" @click="exporter('svg')">
              SVG <span class="font-normal text-attenue">— vectoriel</span>
            </BoutonBase>
            <div class="flex flex-col gap-1">
              <select v-model="dpi" :class="classeChamp" :disabled="!affiche" aria-label="Résolution du PNG" data-reglage="resolution">
                <option v-for="r in resolutions" :key="r.dpi" :value="r.dpi" :disabled="!r.possible">{{ r.libelle }}{{ r.possible ? '' : ' (trop grand)' }}</option>
              </select>
              <BoutonBase variante="secondaire" :desactive="!affiche || occupe !== null" :chargement="occupe === 'png'" data-export="png" @click="exporter('png')">
                PNG <span class="font-normal text-attenue">— image</span>
              </BoutonBase>
            </div>
            <BoutonBase variante="secondaire" :desactive="!affiche || occupe !== null" :chargement="occupe === 'pdf'" data-export="pdf" @click="exporter('pdf')">
              PDF <span class="font-normal text-attenue">— impression</span>
            </BoutonBase>
            <p class="m-0 text-xs leading-relaxed text-attenue">
              Le SVG embarque ses polices. Le PDF passe par la fenêtre d’impression du navigateur : choisissez « Enregistrer au format PDF » ; la page a déjà la taille de l’affiche.
            </p>
            <p v-if="erreurExport" class="m-0 text-xs text-alerte" role="alert">{{ erreurExport }}</p>
          </div>
        </form>

        <!-- L'aperçu -->
        <section class="flex min-h-[18rem] flex-col gap-2 overflow-auto bg-fond p-4" aria-label="Aperçu de l’affiche">
          <p v-if="etat.etat === 'lecture'" class="m-auto text-sm text-attenue" role="status">Lecture de l’arbre et des polices…</p>
          <MessageErreur v-else-if="etat.etat === 'erreur'" :erreur="etat.erreur" titre="L’affiche n’a pas pu être préparée." @action="familleId !== null && charger(familleId)" />
          <p v-else-if="etat.etat === 'pret' && epoux === null" class="m-auto text-sm text-attenue">
            Une affiche a besoin des deux conjoints : l’un d’eux n’est pas dans l’arbre que vous voyez.
          </p>
          <p v-else-if="production && 'erreur' in production" class="m-auto text-sm text-alerte" role="alert">
            L’affiche n’a pas pu être dessinée : {{ production.erreur instanceof Error ? production.erreur.message : 'erreur inattendue' }}
          </p>
          <template v-if="affiche">
            <p class="m-0 text-xs text-attenue" data-resume>
              {{ affiche.chiffres.ancetres }} ancêtres · taille naturelle {{ cotesLisibles({ largeur: affiche.scene.largeur, hauteur: affiche.scene.hauteur }) }}<template v-if="formatChoisi && pageSortie"> · page {{ cotesLisibles(pageSortie) }}</template>
            </p>
            <!-- Le dessin tient dans le cadre dans les deux sens : un sablier dense est plus
                 haut que large, un éventail plus large que haut. -->
            <div
              ref="apercu"
              class="w-full [&>svg]:mx-auto [&>svg]:block [&>svg]:h-auto [&>svg]:max-h-[70dvh] [&>svg]:w-auto [&>svg]:max-w-full [&>svg]:rounded-champ [&>svg]:border [&>svg]:border-ligne [&>svg]:shadow-panneau"
              data-apercu
            />
          </template>
        </section>
      </div>
    </div>
  </dialog>
</template>
