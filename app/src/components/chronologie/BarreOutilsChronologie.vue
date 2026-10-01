<script setup lang="ts">
/**
 * La barre d'outils de la chronologie : la bande, l'orientation, les branches,
 * l'incertitude, le filtre « vivants uniquement », et l'enregistrement de la
 * préférence.
 *
 * **Quatre de ces six réglages s'enregistrent, pas cinq.** « Vivants
 * uniquement » n'a pas de champ dans `PreferenceChronologie` (§ 9.2) et ne part
 * donc jamais dans le `PUT` — voir le point 5 de l'en-tête de
 * `ChronologieView.vue`. L'infobulle du bouton « Enregistrer » énumère
 * volontairement ce qu'il retient, et ce filtre n'y figure pas.
 *
 * ────────────────────────────────────────────────────────────────────
 * **EXPLORER UN RÉGLAGE N'ÉCRIT PAS LA PRÉFÉRENCE.** Aucun contrôle de cette
 * barre ne déclenche de `PUT`. Le seul qui écrit est le bouton « Enregistrer
 * ces réglages », et il remplace les **quatre** champs d'un coup (§ 9.2). Sans
 * cette séparation, « chaque exploration d'un réglage devient une écriture, et
 * la préférence ne veut plus rien dire » (§ 9.1).
 * ────────────────────────────────────────────────────────────────────
 *
 * **La saisie libre n'est pas écrêtée.** 1 à 500 est la contrainte du serveur
 * (`ck_tml_preference_bande`) ; c'est lui qui répond `422`, et l'écran affiche
 * sa réponse. Un front qui ramènerait 600 à 500 en silence ferait diverger ce
 * qu'on demande de ce qu'on croit avoir demandé. Ce qui n'est **pas** un nombre
 * de bandes — vide, zéro, négatif — n'est pas envoyé du tout : il n'y a rien à
 * demander.
 *
 * **La mise en page est celle de `BarreEcran`** (handoff « En-tête, barres
 * d'écran et fiche », § 2) : Lignes · Colonnes en segmenté, puis « Une bande
 * par », et à droite Filtres · Recadrer · Enregistrer. Le menu « Filtres »
 * porte les branches, l'incertitude et « Vivants uniquement ». Sur téléphone,
 * seul le segmenté reste sous l'en-tête ; la bande, les filtres, la légende et
 * « Enregistrer ces réglages » passent dans le feuillet. La bande n'est rendue
 * qu'**une fois** — dans la barre sur bureau, dans le feuillet sur téléphone
 * (`useEstMobile`) — pour que l'identifiant `chrono-bande` reste unique.
 *
 * « Réinitialiser » ne crée aucun événement nouveau : il rejoue ceux qui
 * existent (`branche`, `incertitude`, `vivantsSeuls`) jusqu'à l'état sans
 * filtre. Il ne touche ni la bande, ni le sens, ni la préférence.
 *
 * Tout est réactif ici : une poignée de contrôles ne coûte rien à Vue. La
 * frontière est celle de l'ADR 0007 — Vue gère le cadre et les contrôles, il ne
 * gère pas les barres.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import type { Branche, OrientationChronologie } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import Segmente from '@/components/mise-en-page/Segmente.vue'
import { useEstMobile } from '@/composables/useTiroirs'
import { DESCRIPTIONS_BRANCHE, LIBELLES_BRANCHE, ORDRE_BRANCHES } from './disposition'

const { bande, orientation, branches, montrerIncertitude, vivantsSeuls, chargement, preferenceAJour, enregistrement } =
  defineProps<{
    bande: number
    orientation: OrientationChronologie
    branches: readonly Branche[]
    montrerIncertitude: boolean
    /**
     * N'affiche que les personnes vivantes. **Ne fait pas partie de la
     * préférence** : les quatre champs du `PUT` sont ceux du § 9.2, et le
     * bouton « Enregistrer » n'emporte pas celui-ci. L'infobulle du contrôle le
     * dit au lecteur plutôt que de le lui laisser découvrir au rechargement.
     */
    vivantsSeuls: boolean
    chargement: boolean
    /** Les réglages courants sont déjà ceux de la préférence enregistrée : il
     *  n'y a rien à écrire. */
    preferenceAJour: boolean
    enregistrement: boolean
  }>()

const emit = defineEmits<{
  bande: [annees: number]
  orientation: [orientation: OrientationChronologie]
  branche: [branche: Branche]
  incertitude: [montrer: boolean]
  vivantsSeuls: [actif: boolean]
  enregistrer: []
  recadrer: []
}>()

defineSlots<{
  /** La légende du canevas, rendue dans le feuillet sur téléphone (elle y est
   *  masquée en flottant). */
  legende?: () => unknown
}>()

const estMobile = useEstMobile()

/** Les cinq valeurs proposées par l'interface (§ 9.1), la saisie libre en plus. */
const PRESELECTIONS = [1, 5, 10, 25, 50] as const

const ORIENTATIONS: readonly { valeur: OrientationChronologie; libelle: string }[] = [
  { valeur: 'lignes', libelle: 'Lignes' },
  { valeur: 'colonnes', libelle: 'Colonnes' },
]

const saisie = ref(String(bande))

/** Le temps de finir de taper un nombre. Sans ce délai, « 25 » part d'abord
 *  comme « 2 » et le serveur découpe l'arbre en deux cents bandes pour rien. */
const DELAI_SAISIE = 420
let minuterie: ReturnType<typeof setTimeout> | undefined

watch(
  () => bande,
  (valeur) => {
    // Le réglage peut changer par un bouton : on remet la saisie d'accord avec
    // lui, sans redéclencher d'appel.
    if (Number.parseInt(saisie.value, 10) !== valeur) saisie.value = String(valeur)
  },
)

function surSaisie(): void {
  clearTimeout(minuterie)
  const valeur = Number.parseInt(saisie.value, 10)
  if (!Number.isFinite(valeur) || valeur < 1) return
  if (valeur === bande) return
  minuterie = setTimeout(() => emit('bande', valeur), DELAI_SAISIE)
}

function choisir(valeur: number): void {
  clearTimeout(minuterie)
  saisie.value = String(valeur)
  if (valeur !== bande) emit('bande', valeur)
}

function basculerBranche(branche: Branche): void {
  // On ne retire pas la dernière branche : un filtre qui ne laisse rien montre
  // un écran vide qu'on prend pour une panne, et le serveur n'a pas de sens à
  // donner à une liste vide.
  if (branches.length === 1 && branches.includes(branche)) return
  emit('branche', branche)
}

/** Des branches manquent, ou seuls les vivants sont montrés. L'incertitude
 *  n'est pas un filtre : elle change le dessin, elle ne cache rien. */
const filtresActifs = computed(
  () => (ORDRE_BRANCHES.some((b) => !branches.includes(b)) ? 1 : 0) + (vivantsSeuls ? 1 : 0),
)

function reinitialiser(): void {
  // On n'ajoute que ce qui manque : `branche` est une bascule, et rejouer une
  // branche déjà là la retirerait.
  for (const b of ORDRE_BRANCHES) if (!branches.includes(b)) emit('branche', b)
  if (!montrerIncertitude) emit('incertitude', true)
  if (vivantsSeuls) emit('vivantsSeuls', false)
}

const titreEnregistrer = computed(() =>
  preferenceAJour
    ? 'Ces réglages sont déjà votre préférence'
    : 'Retenir la bande, le sens, les branches et l’incertitude',
)

onBeforeUnmount(() => clearTimeout(minuterie))
</script>

<template>
  <BarreEcran
    ecran="Chronologie"
    :filtres-actifs="filtresActifs"
    voir="Voir la frise"
    @reinitialiser="reinitialiser"
  >
    <!-- Vue : le sens de lecture ------------------------------------- -->
    <template #vue>
      <div
        class="max-md:w-full"
        title="Lignes : le temps descend. Colonnes : le temps va vers la droite."
      >
        <Segmente
          :options="ORIENTATIONS"
          :valeur="orientation"
          etiquette="Sens de lecture"
          :colonnes="2"
          @choisir="(o) => emit('orientation', o)"
        />
      </div>
    </template>

    <!-- Bande, sur bureau : dans la barre ------------------------------ -->
    <template v-if="!estMobile" #pastilles>
      <span class="text-[12.5px] font-semibold text-attenue">Une bande par</span>
      <button
        v-for="valeur in PRESELECTIONS"
        :key="valeur"
        type="button"
        class="flex h-7 min-w-7 flex-none cursor-pointer items-center justify-center rounded-pastille border px-[9px] text-[12.5px] tnum"
        :class="
          bande === valeur
            ? 'border-accent bg-accent-doux font-semibold text-texte'
            : 'border-ligne bg-panneau text-attenue hover:border-accent'
        "
        :aria-pressed="bande === valeur"
        @click="choisir(valeur)"
      >
        {{ valeur }}
      </button>
      <label class="lecteur-ecran" for="chrono-bande">Nombre d’années par bande</label>
      <input
        id="chrono-bande"
        v-model="saisie"
        type="number"
        inputmode="numeric"
        min="1"
        max="500"
        step="1"
        class="h-[30px] w-16 flex-none rounded-champ border border-ligne bg-panneau px-2 text-[13px] tnum text-texte"
        title="De 1 à 500 années par bande"
        @input="surSaisie"
      />
      <span class="text-[12.5px] text-attenue">années</span>
    </template>

    <template v-if="chargement" #compteur>
      <span class="font-semibold text-accent" aria-live="polite">…</span>
    </template>

    <!-- Menu Filtres (bureau) et feuillet (téléphone) : un seul bloc ----- -->
    <template #filtres>
      <!-- Bande, sur téléphone : en tête du feuillet. -->
      <section v-if="estMobile" class="flex flex-col gap-2">
        <h3 class="text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Une bande par</h3>
        <div class="flex flex-wrap items-center gap-2">
          <button
            v-for="valeur in PRESELECTIONS"
            :key="valeur"
            type="button"
            class="flex h-10 w-11 cursor-pointer items-center justify-center rounded-pastille border tnum"
            :class="
              bande === valeur
                ? 'border-accent bg-accent-doux font-semibold text-texte'
                : 'border-ligne text-attenue'
            "
            :aria-pressed="bande === valeur"
            @click="choisir(valeur)"
          >
            {{ valeur }}
          </button>
          <label class="lecteur-ecran" for="chrono-bande">Nombre d’années par bande</label>
          <input
            id="chrono-bande"
            v-model="saisie"
            type="number"
            inputmode="numeric"
            min="1"
            max="500"
            step="1"
            class="h-10 w-16 rounded-champ border border-ligne bg-fond px-2 text-[15px] tnum text-texte"
            title="De 1 à 500 années par bande"
            @input="surSaisie"
          />
          <span class="text-[13px] text-attenue">ans</span>
        </div>
      </section>

      <!-- Branches -->
      <section class="flex flex-col gap-2">
        <h3 class="text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Branches</h3>
        <div class="flex flex-wrap gap-1.5 max-md:gap-2" role="group" aria-label="Branches affichées">
          <button
            v-for="b in ORDRE_BRANCHES"
            :key="b"
            type="button"
            class="flex h-7 cursor-pointer items-center gap-1.5 rounded-pastille border px-2.5 text-[12.5px] max-md:h-10 max-md:px-[13px] max-md:text-[13.5px]"
            :class="
              branches.includes(b)
                ? 'border-accent bg-accent-doux font-semibold text-texte'
                : 'border-ligne bg-panneau text-attenue hover:border-accent'
            "
            :aria-pressed="branches.includes(b)"
            :title="DESCRIPTIONS_BRANCHE[b]"
            @click="basculerBranche(b)"
          >
            <!-- L'échantillon porte les classes du canevas : une pastille qui
                 redéclarerait sa couleur mentirait à la première retouche. -->
            <svg width="9" height="9" class="shrink-0" aria-hidden="true">
              <rect :class="['br', `b-${b}`]" x="0" y="0" width="9" height="9" rx="2" />
            </svg>
            {{ LIBELLES_BRANCHE[b] }}
          </button>
        </div>
      </section>

      <!-- Incertitude et vivants : deux cases, sur des lignes de 44 px au doigt. -->
      <div class="flex flex-col">
        <label
          class="flex min-h-9 cursor-pointer items-center gap-2.5 text-[13px] max-md:min-h-11 max-md:border-b max-md:border-ligne max-md:text-[15px]"
        >
          <input
            type="checkbox"
            :checked="montrerIncertitude"
            class="size-4 accent-accent max-md:size-5"
            @change="emit('incertitude', ($event.target as HTMLInputElement).checked)"
          />
          <span class="flex-1">Hachurer les dates incertaines</span>
        </label>

        <!--
          Même forme que l'incertitude : une case, une propriété, un événement. Le
          filtre se pose sur le booléen `vivant` de chaque barre — jamais sur
          `deceased`, ni sur l'étiquette « vivant·e » affichée ailleurs.

          L'infobulle dit les deux choses qu'on découvrirait autrement de travers :
          que ce réglage n'est pas retenu. Il ne dit plus « de votre cercle » depuis
          le 2026-10-01 : un membre voit tous les vivants, hors fiches confidentielles
          (ADR 0003). La mention « non
          enregistré » le dit aussi à qui ne survole rien — sur téléphone, personne.
        -->
        <label class="flex min-h-9 cursor-pointer items-center gap-2.5 text-[13px] max-md:min-h-11 max-md:text-[15px]">
          <input
            type="checkbox"
            :checked="vivantsSeuls"
            class="size-4 accent-accent max-md:size-5"
            title="N’afficher que les personnes vivantes. Ce réglage n’est pas enregistré."
            @change="emit('vivantsSeuls', ($event.target as HTMLInputElement).checked)"
          />
          <span class="flex-1">Vivants uniquement</span>
          <span class="text-[11.5px] text-attenue">non enregistré</span>
        </label>
      </div>

      <div v-if="estMobile && $slots.legende"><slot name="legende" /></div>
    </template>

    <!-- Actions (bureau) ---------------------------------------------- -->
    <template #actions>
      <BoutonBase
        variante="secondaire"
        class="h-8"
        title="Revenir au cadrage de départ (touche 0) et effacer la sélection"
        @click="emit('recadrer')"
      >
        Recadrer
      </BoutonBase>
      <BoutonBase
        variante="secondaire"
        class="h-8"
        :desactive="preferenceAJour"
        :chargement="enregistrement"
        :title="titreEnregistrer"
        @click="emit('enregistrer')"
      >
        {{ preferenceAJour ? 'Réglages enregistrés' : 'Enregistrer ces réglages' }}
      </BoutonBase>
    </template>

    <!-- Actions (téléphone, dans le feuillet) --------------------------- -->
    <template #actions-mobile>
      <BoutonBase
        variante="secondaire"
        pleine-largeur
        class="h-11"
        :desactive="preferenceAJour"
        :chargement="enregistrement"
        :title="titreEnregistrer"
        @click="emit('enregistrer')"
      >
        {{ preferenceAJour ? 'Réglages enregistrés' : 'Enregistrer ces réglages' }}
      </BoutonBase>
      <BoutonBase variante="discret" pleine-largeur class="h-11" @click="emit('recadrer')">
        Recadrer
      </BoutonBase>
    </template>
  </BarreEcran>
</template>
