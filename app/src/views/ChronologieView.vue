<script setup lang="ts">
/**
 * Chronologie — § 9 du contrat, § 7.4 du dossier de mission.
 *
 * **Onglet distinct, pas une variante de l'arbre.** Cette vue n'utilise pas le
 * moteur de placement : elle place sur un axe de temps (ADR 0005). Elle
 * **orchestre** ; elle ne dessine pas — le canevas est dans
 * `components/chronologie/`, piloté impérativement.
 *
 * ────────────────────────────────────────────────────────────────────
 * CE QUI SE DÉCIDE ICI, ET NULLE PART AILLEURS :
 *
 * 1. **`bande` et `branches` sont des paramètres du serveur** : les changer
 *    redemande la vue, parce qu'ils changent la donnée (le découpage en bandes,
 *    les bornes). `orientation` et l'incertitude sont des réglages de **rendu** :
 *    ils s'appliquent sans rien redemander, et le dessin ne clignote pas.
 * 2. **Explorer un réglage n'écrit pas la préférence** (§ 9.1). Le seul `PUT`
 *    part du bouton « Enregistrer ces réglages », sur geste explicite, et
 *    remplace les quatre champs d'un coup (§ 9.2).
 * 3. **`showUncertainty` vient du serveur et vaut `true` par défaut.** Le
 *    premier rendu ne l'éteint jamais : « masquer l'incertitude est précisément
 *    ce que le § 4 du dossier interdit ». Seul un geste du lecteur l'éteint.
 * 4. **Après la première réponse, les réglages appartiennent au lecteur.** Les
 *    réponses suivantes n'apportent que de la donnée : sinon l'écho du serveur
 *    reprendrait la main sur ce que le lecteur vient de choisir.
 * 5. **« Vivants uniquement » n'est pas dans `Reglages`, et c'est voulu.**
 *    `Reglages` est le miroir des **quatre** champs de `PreferenceChronologie`,
 *    que le bouton « Enregistrer » envoie d'un bloc : le `PUT` est un
 *    remplacement complet, pas un `PATCH` (§ 9.2). Un cinquième champ posé dans
 *    cet objet partirait un jour dans ce corps — par un `...r` distrait — et le
 *    serveur rendrait `422` sur un réglage d'affichage. Le filtre est donc une
 *    `ref` à part : il ne peut pas entrer dans la préférence, il n'entre pas
 *    dans `cleDeDemande()` (il ne change pas la donnée, il la cache), et il
 *    **ne survit pas au rechargement**. Le rendre durable demanderait un champ
 *    de plus au contrat du serveur, ce que ce chantier ne touche pas.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Ce qu'il ne faut pas prendre pour un bogue.** Un membre voit **moins de
 * barres que de nœuds dans l'arbre** : les personnes vivantes hors de son
 * cercle sont absentes de `barres`, par décision du § 9.1 — une barre *est*
 * faite de dates, et une personne vivante hors cercle n'en expose aucune. Un
 * compte `en_attente` ne voit que des personnes décédées. C'est voulu, et cet
 * écran ne le « corrige » pas. Il n'affiche non plus **aucun décompte** de
 * barres : un compteur trahirait, par soustraction, le nombre de personnes
 * qu'on n'a pas le droit de voir.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch, watchEffect } from 'vue'
import { hauteurDuCadre } from '@/views/cadre'
import { useTiroirs } from '@/composables/useTiroirs'
import { definirResume } from '@/composables/entete'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreOutilsChronologie from '@/components/chronologie/BarreOutilsChronologie.vue'
import CanevasChronologie from '@/components/chronologie/CanevasChronologie.vue'
import LegendeChronologie from '@/components/chronologie/LegendeChronologie.vue'
import ReserveVivants from '@/components/chronologie/ReserveVivants.vue'
import { DESCRIPTIONS_BRANCHE, estVide } from '@/components/chronologie/disposition'
import { filtrerVivants } from '@/components/chronologie/vivants'
import {
  chargerChronologie,
  enregistrerPreferencesChronologie,
  lirePreferencesChronologie,
  type BarreChronologie,
  type Branche,
  type OrientationChronologie,
  type PreferenceChronologie,
  type VueChronologie,
} from '@/lib/api'

/** Les quatre réglages, tels que le lecteur les manipule. Ils reprennent les
 *  valeurs du serveur au premier chargement, puis lui appartiennent. */
interface Reglages {
  bande: number
  orientation: OrientationChronologie
  branches: Branche[]
  montrerIncertitude: boolean
}

const vue = shallowRef<VueChronologie | null>(null)
const reglages = ref<Reglages | null>(null)
const preference = shallowRef<PreferenceChronologie | null>(null)
const erreur = shallowRef<unknown>(null)
const chargement = ref(false)

const canevas = ref<InstanceType<typeof CanevasChronologie> | null>(null)
const racine = ref<HTMLElement | null>(null)

/* ------------------------------------------------------------------ */
/* Chargement                                                          */
/* ------------------------------------------------------------------ */

/** Ce qui identifie une **demande au serveur** : la bande et les branches. Ni
 *  l'orientation ni l'incertitude n'y figurent — elles ne changent pas la
 *  donnée, seulement son dessin. */
function cleDeDemande(r: Reglages | null): string {
  return r === null ? '' : `${r.bande}|${[...r.branches].sort().join(',')}`
}

/** La dernière clé effectivement demandée, pour ne pas rappeler le serveur
 *  quand c'est lui qui vient de nous donner ces réglages. */
let cleDemandee = ''
let abandon: AbortController | null = null

async function charger(): Promise<void> {
  abandon?.abort()
  const controleur = new AbortController()
  abandon = controleur

  const r = reglages.value
  cleDemandee = cleDeDemande(r)
  chargement.value = true
  erreur.value = null

  try {
    const reponse = await chargerChronologie(
      r === null
        ? {}
        : { bande: r.bande, orientation: r.orientation, branches: r.branches },
      controleur.signal,
    )
    if (controleur.signal.aborted) return
    vue.value = reponse

    if (reglages.value === null) {
      // Premier chargement : la préférence enregistrée du compte fait foi,
      // `showUncertainty` compris — on ne l'éteint pas au premier rendu.
      reglages.value = {
        bande: reponse.bande,
        orientation: reponse.orientation,
        branches: [...reponse.branches],
        montrerIncertitude: reponse.showUncertainty,
      }
      cleDemandee = cleDeDemande(reglages.value)
    }
  } catch (cause) {
    if (!controleur.signal.aborted) erreur.value = cause
  } finally {
    if (!controleur.signal.aborted) chargement.value = false
  }
}

watch(
  () => cleDeDemande(reglages.value),
  (cle) => {
    if (cle !== cleDemandee) void charger()
  },
)

/* ------------------------------------------------------------------ */
/* Réglages                                                            */
/* ------------------------------------------------------------------ */

function changerBande(annees: number): void {
  if (reglages.value === null) return
  reglages.value = { ...reglages.value, bande: annees }
}

function changerOrientation(orientation: OrientationChronologie): void {
  if (reglages.value === null) return
  reglages.value = { ...reglages.value, orientation }
}

function basculerBranche(branche: Branche): void {
  const r = reglages.value
  if (r === null) return
  const branches = r.branches.includes(branche)
    ? r.branches.filter((b) => b !== branche)
    : [...r.branches, branche]
  if (branches.length === 0) return
  reglages.value = { ...r, branches }
}

function changerIncertitude(montrer: boolean): void {
  if (reglages.value === null) return
  reglages.value = { ...reglages.value, montrerIncertitude: montrer }
}

/**
 * Le résumé de l'en-tête mobile, sous le titre : « Une bande par 25 ans ».
 * **Aucun décompte de barres** — même raison que plus haut : un compteur
 * trahirait, par soustraction, les personnes qu'on n'a pas le droit de voir.
 */
watchEffect(() => {
  const bande = reglages.value?.bande
  definirResume(bande === undefined ? '' : `Une bande par ${bande} ${bande > 1 ? 'ans' : 'an'}`)
})

/* ------------------------------------------------------------------ */
/* Vivants uniquement                                                  */
/* ------------------------------------------------------------------ */

/**
 * Éteint au premier rendu, toujours.
 *
 * Un écran qui s'ouvrirait déjà filtré montrerait une fraction de l'arbre en
 * ayant l'air de tout montrer — le même défaut que `showUncertainty`, qu'on
 * « n'éteint jamais de soi-même » (§ 9.2). Seul un geste du lecteur l'allume.
 */
const vivantsSeuls = ref(false)

function changerVivantsSeuls(actif: boolean): void {
  vivantsSeuls.value = actif
}

/**
 * Ce que l'écran dessine. Le filtre s'applique **ici**, entre la donnée du
 * serveur et le canevas, et pas dans `disposer()` : ce module « ne redérive
 * aucune donnée, il ne fait que placer », et lui confier un prédicat brouillerait
 * la seule frontière qui rende sa suite de tests lisible.
 *
 * `vue` reste intacte : c'est elle qu'on réenverra au serveur, et c'est sur
 * elle que se lit ce que le compte a le droit de voir.
 */
const vueAffichee = computed(() =>
  vue.value === null ? null : filtrerVivants(vue.value, vivantsSeuls.value),
)

/* ------------------------------------------------------------------ */
/* Préférence — la seule écriture de cet écran                          */
/* ------------------------------------------------------------------ */

const enregistrement = ref(false)
const erreurPreference = shallowRef<unknown>(null)

/**
 * La préférence est lue **à part**, et son échec est silencieux : elle ne sert
 * qu'à savoir s'il reste quelque chose à enregistrer. Une chronologie qui
 * refuserait de s'afficher parce qu'on n'a pas pu lire un réglage d'affichage
 * serait une panne inventée.
 */
async function lirePreference(): Promise<void> {
  try {
    preference.value = await lirePreferencesChronologie()
  } catch {
    preference.value = null
  }
}

const preferenceAJour = computed(() => {
  const r = reglages.value
  const p = preference.value
  if (r === null || p === null) return false
  return (
    p.bandYears === r.bande &&
    p.orientation === r.orientation &&
    p.showUncertainty === r.montrerIncertitude &&
    [...p.branches].sort().join(',') === [...r.branches].sort().join(',')
  )
})

async function enregistrer(): Promise<void> {
  const r = reglages.value
  if (r === null) return
  enregistrement.value = true
  erreurPreference.value = null
  const envoyee: PreferenceChronologie = {
    bandYears: r.bande,
    orientation: r.orientation,
    branches: [...r.branches],
    showUncertainty: r.montrerIncertitude,
  }
  try {
    await enregistrerPreferencesChronologie(envoyee)
    // On retient ce qu'on a envoyé, et non ce que la réponse porte : le serveur
    // l'a accepté, c'est donc la préférence. Cela évite qu'une divergence de
    // forme sur le corps de réponse laisse le bouton actif pour toujours.
    preference.value = envoyee
  } catch (cause) {
    erreurPreference.value = cause
  } finally {
    enregistrement.value = false
  }
}

/* ------------------------------------------------------------------ */
/* Sélection                                                           */
/* ------------------------------------------------------------------ */

const selection = shallowRef<BarreChronologie | null>(null)
const personneSelectionnee = computed(() => selection.value?.personId ?? null)

function surBarre(barre: BarreChronologie | null): void {
  selection.value = barre
}

/**
 * La fiche ouverte suit le filtre.
 *
 * Sans cela, masquer une barre laisse son panneau à l'écran : le nom, les dates
 * et le lien « Ouvrir la fiche » d'une personne dont plus aucun trait n'est
 * visible. Le lecteur cherche la barre qui correspond, ne la trouve pas, et le
 * panneau donne raison à sa recherche — une information juste au mauvais
 * moment se lit comme une panne.
 */
watch(vueAffichee, (affichee) => {
  const choisie = selection.value
  if (choisie === null || affichee === null) return
  if (!affichee.barres.some((b) => b.personId === choisie.personId)) selection.value = null
})

/** Le cadrage de départ, et plus rien de sélectionné : les deux gestes vont
 *  ensemble, comme `Échap` et le bouton de l'arbre. */
function recadrer(): void {
  selection.value = null
  canevas.value?.recadrer()
}

/* ------------------------------------------------------------------ */
/* Cadre                                                               */
/* ------------------------------------------------------------------ */

/** Sur la vue **affichée** : un filtre qui ne laisse aucune barre doit donner
 *  l'état vide explicite, et non un canevas blanc qu'on prend pour une panne. */
const vide = computed(() => vueAffichee.value !== null && estVide(vueAffichee.value))

/**
 * Le canevas occupe tout ce qui reste sous l'en-tête applicatif. La hauteur est
 * **mesurée** et non déduite d'une chaîne de `height: 100%` : le cadre peut
 * porter un bandeau de régime dont la hauteur n'est pas connue d'ici, et un
 * canevas qui s'effondre à zéro ne se voit qu'à l'exécution.
 *
 * La formule est dans `views/cadre.ts`, et cet écran en portait une copie qui
 * réunissait les trois défauts que `hauteurDuCadre()` documente : la hauteur de
 * la **fenêtre** au lieu de celle de la **mise en page**, un arrondi au plus
 * proche au lieu du plancher, et un minimum de 320 px qui garantit le
 * débordement dès qu'il reste moins que cela. Sur un téléphone, cette page
 * débordait donc, six mois après que l'arbre eut corrigé et documenté exactement
 * ces trois-là. Personne ne l'a vu parce qu'aucun test ne lisait ce fichier ;
 * `cadre.test.ts` en lit désormais le source — et il refuserait ce commentaire
 * s'il recopiait la formule fautive au lieu de la décrire.
 */
const hauteurCadre = ref('70vh')

function mesurerHauteur(): void {
  const element = racine.value
  if (element === null) return
  hauteurCadre.value = hauteurDuCadre(element.getBoundingClientRect().top)
}

const { unTiroirEstOuvert } = useTiroirs()

function surToucheGlobale(ev: KeyboardEvent): void {
  const cible = ev.target
  if (cible instanceof HTMLInputElement || cible instanceof HTMLTextAreaElement) return
  // Un tiroir ouvert prend la main : il se ferme lui-même, et Échap ne doit pas
  // faire deux choses d'un seul geste. Même raison que dans `ArbreView`.
  if (unTiroirEstOuvert()) return
  if (ev.key === '0') recadrer()
  else if (ev.key === 'Escape') selection.value = null
}

onMounted(() => {
  mesurerHauteur()
  window.addEventListener('resize', mesurerHauteur)
  window.addEventListener('keydown', surToucheGlobale)
  void charger()
  void lirePreference()
})

onBeforeUnmount(() => {
  abandon?.abort()
  window.removeEventListener('resize', mesurerHauteur)
  window.removeEventListener('keydown', surToucheGlobale)
})
</script>

<template>
  <div ref="racine" class="flex flex-col" :style="{ height: hauteurCadre }">
    <!--
      La barre d'écran commune (`BarreEcran`, portée par la barre d'outils) :
      sur bureau, une ligne de 50 px ; sur téléphone, le segmenté sous l'en-tête
      et tout le reste dans le feuillet — la légende comprise, puisque sa forme
      flottante y est masquée.
    -->
    <BarreOutilsChronologie
      v-if="reglages !== null"
      :bande="reglages.bande"
      :orientation="reglages.orientation"
      :branches="reglages.branches"
      :montrer-incertitude="reglages.montrerIncertitude"
      :vivants-seuls="vivantsSeuls"
      :chargement="chargement"
      :preference-a-jour="preferenceAJour"
      :enregistrement="enregistrement"
      @bande="changerBande"
      @orientation="changerOrientation"
      @branche="basculerBranche"
      @incertitude="changerIncertitude"
      @vivants-seuls="changerVivantsSeuls"
      @enregistrer="enregistrer"
      @recadrer="recadrer"
    >
      <template v-if="vueAffichee !== null && !vide" #legende>
        <LegendeChronologie en-tiroir />
      </template>
    </BarreOutilsChronologie>

    <!--
      La réserve ne paraît qu'une fois le filtre allumé : une mise en garde
      permanente sur un écran qui ne filtre rien s'apprend à ne plus être lue, et
      ne dit plus rien le jour où elle compte. Ce qu'elle dit, et pourquoi elle
      est à l'écran plutôt qu'en commentaire, est dans `ReserveVivants.vue`.
    -->
    <ReserveVivants v-if="vivantsSeuls" />

    <!--
      Une demande refusée ne vide pas le canevas : la chronologie affichée reste
      juste, c'est le réglage demandé qui n'a pas été accepté. On le dit sur une
      ligne plutôt que de remplacer l'écran — c'est le cas d'une bande hors de
      1–500, qui rend `422`.
    -->
    <div v-if="erreur && vue !== null" class="border-b border-ligne px-4 py-2">
      <MessageErreur
        :erreur="erreur"
        titre="Ce réglage n’a pas été accepté"
        @action="charger"
      />
    </div>

    <div v-if="erreurPreference" class="border-b border-ligne px-4 py-2">
      <MessageErreur
        :erreur="erreurPreference"
        titre="La préférence n’a pas pu être enregistrée"
        @action="enregistrer"
      />
    </div>

    <div class="relative min-h-0 flex-1">
      <div v-if="chargement && vue === null" class="p-6">
        <SqueletteChargement :lignes="8" />
      </div>

      <div v-else-if="erreur && vue === null" class="p-6">
        <MessageErreur
          :erreur="erreur"
          titre="La chronologie n’a pas pu être chargée"
          @action="charger"
        />
      </div>

      <!-- État vide explicite : bornes vides, ou aucune barre visible. Jamais
           un canevas blanc, qu'on prendrait pour une panne. -->
      <div v-else-if="vide" class="mx-auto w-full max-w-2xl p-6">
        <!-- L'état vide ne dit pas la même chose selon la cause. Garder le
             texte d'origine sous le filtre conseillerait d'élargir les branches
             ou les bandes — deux gestes qui ne peuvent rien y faire, et le
             lecteur les essaierait avant de penser au filtre qu'il vient
             d'allumer. -->
        <PanneauBase titre="Aucune barre à afficher">
          <template v-if="vivantsSeuls">
            <p class="text-sm">
              Aucune personne vivante de votre cercle de visibilité ne porte de date sur
              cette période.
            </p>
            <p class="mt-2 text-xs text-attenue">
              Le filtre « Vivants uniquement » est actif. L’éteindre rendra les barres des
              personnes décédées ; il ne fera pas apparaître d’autres vivants — ceux qui
              sont hors de votre cercle n’exposent aucune date, donc aucune barre.
            </p>
          </template>
          <template v-else>
            <p class="text-sm">
              Aucune des personnes que vous pouvez voir ne porte de date sur cette période.
            </p>
            <p class="mt-2 text-xs text-attenue">
              Une barre est faite de dates : une personne vivante hors de votre cercle de
              visibilité n’en a pas. Essayez d’élargir les branches affichées ou de changer la
              largeur des bandes.
            </p>
          </template>
        </PanneauBase>
      </div>

      <CanevasChronologie
        v-show="vueAffichee !== null && !vide"
        ref="canevas"
        :vue="vueAffichee"
        :orientation="reglages?.orientation ?? 'lignes'"
        :montrer-incertitude="reglages?.montrerIncertitude ?? true"
        :selection="personneSelectionnee"
        @barre="surBarre"
      />

      <LegendeChronologie v-if="vueAffichee !== null && !vide" />

      <!-- La fiche d'une barre sélectionnée. Elle ne charge rien : tout ce
           qu'elle montre est déjà dans la barre. -->
      <div
        v-if="selection !== null"
        class="absolute top-3.5 right-3.5 z-10 w-[min(22rem,calc(100%-1.75rem))]"
      >
        <PanneauBase ombre>
          <template #entete>
            <div class="flex items-start justify-between gap-3">
              <h2 class="text-sm font-semibold tracking-tight">{{ selection.displayName }}</h2>
              <button
                type="button"
                class="cursor-pointer border-0 bg-transparent p-0 text-lg leading-none text-attenue hover:text-texte"
                aria-label="Fermer"
                @click="selection = null"
              >
                ×
              </button>
            </div>
          </template>

          <!-- La ligne de dates vient du serveur : « entre 1901 et 1909 – 1987 »
               s'affiche telle quelle, jamais aplatie (§ 14). -->
          <p class="tnum text-sm">{{ selection.ligneDates }}</p>
          <p class="mt-1 text-xs text-attenue">
            {{ DESCRIPTIONS_BRANCHE[selection.branch] }} · génération
            {{ selection.generation }}
          </p>
          <p v-if="selection.ouverte" class="mt-2 text-xs text-attenue">
            Barre ouverte : la personne est vivante, le trait s’arrête à l’année courante.
          </p>

          <template #pied>
            <RouterLink
              :to="{ name: 'personne.detail', params: { id: selection.personId } }"
              class="text-sm text-accent underline"
            >
              Ouvrir la fiche
            </RouterLink>
          </template>
        </PanneauBase>
      </div>
    </div>
  </div>
</template>
