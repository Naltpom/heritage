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
 * Tout est réactif ici : une poignée de contrôles ne coûte rien à Vue. La
 * frontière est celle de l'ADR 0007 — Vue gère le cadre et les contrôles, il ne
 * gère pas les barres.
 */
import { onBeforeUnmount, ref, watch } from 'vue'
import type { Branche, OrientationChronologie } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
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

/** Les cinq valeurs proposées par l'interface (§ 9.1), la saisie libre en plus. */
const PRESELECTIONS = [1, 5, 10, 25, 50] as const

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

onBeforeUnmount(() => clearTimeout(minuterie))
</script>

<template>
  <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-2 border-b border-ligne bg-panneau px-4 py-2 max-md:flex-col max-md:items-stretch max-md:gap-2 max-md:border-b-0 max-md:bg-transparent max-md:px-0 max-md:py-0">
    <!-- Bande ------------------------------------------------------- -->
    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
      <span class="text-xs font-semibold text-attenue">Une bande par</span>
      <button
        v-for="valeur in PRESELECTIONS"
        :key="valeur"
        type="button"
        class="cursor-pointer rounded-pastille border px-2.5 py-1 text-xs tnum"
        :class="
          bande === valeur
            ? 'border-accent bg-accent-doux font-semibold text-texte'
            : 'border-ligne bg-fond text-attenue hover:border-accent'
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
        class="w-[4.5rem] min-w-0 rounded-champ border border-ligne bg-fond px-2 py-1 text-[13px] tnum text-texte"
        title="De 1 à 500 années par bande"
        @input="surSaisie"
      />
      <span class="text-xs text-attenue">années</span>
    </div>

    <span class="hidden h-5 w-px bg-ligne md:block" aria-hidden="true" />

    <!-- Orientation ------------------------------------------------- -->
    <div class="flex min-w-0 flex-wrap items-center gap-1.5" role="group" aria-label="Sens de lecture">
      <button
        type="button"
        class="cursor-pointer rounded-pastille border px-2.5 py-1 text-xs"
        :class="
          orientation === 'lignes'
            ? 'border-accent bg-accent-doux font-semibold text-texte'
            : 'border-ligne bg-fond text-attenue hover:border-accent'
        "
        :aria-pressed="orientation === 'lignes'"
        title="Une ligne par bande — le temps descend, du plus ancien en haut"
        @click="emit('orientation', 'lignes')"
      >
        Lignes
      </button>
      <button
        type="button"
        class="cursor-pointer rounded-pastille border px-2.5 py-1 text-xs"
        :class="
          orientation === 'colonnes'
            ? 'border-accent bg-accent-doux font-semibold text-texte'
            : 'border-ligne bg-fond text-attenue hover:border-accent'
        "
        :aria-pressed="orientation === 'colonnes'"
        title="Une colonne par bande — le temps va vers la droite"
        @click="emit('orientation', 'colonnes')"
      >
        Colonnes
      </button>
    </div>

    <span class="hidden h-5 w-px bg-ligne md:block" aria-hidden="true" />

    <!-- Branches ---------------------------------------------------- -->
    <div class="flex min-w-0 flex-wrap items-center gap-1.5" role="group" aria-label="Branches affichées">
      <button
        v-for="b in ORDRE_BRANCHES"
        :key="b"
        type="button"
        class="flex cursor-pointer items-center gap-1.5 rounded-pastille border px-2.5 py-1 text-xs"
        :class="
          branches.includes(b)
            ? 'border-accent bg-accent-doux font-semibold text-texte'
            : 'border-ligne bg-fond text-attenue hover:border-accent'
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

    <span class="hidden h-5 w-px bg-ligne md:block" aria-hidden="true" />

    <!-- Incertitude ------------------------------------------------- -->
    <label class="flex cursor-pointer items-center gap-1.5 text-xs text-attenue">
      <input
        type="checkbox"
        :checked="montrerIncertitude"
        class="accent-accent"
        @change="emit('incertitude', ($event.target as HTMLInputElement).checked)"
      />
      Hachurer les dates incertaines
    </label>

    <!-- Vivants uniquement ------------------------------------------ -->
    <!--
      Même forme que l'incertitude : une case, une propriété, un événement. Le
      filtre se pose sur le booléen `vivant` de chaque barre — jamais sur
      `deceased`, ni sur l'étiquette « vivant·e » affichée ailleurs.

      L'infobulle dit les deux choses qu'on découvrirait autrement de travers :
      que ce réglage n'est pas retenu, et qu'il montre les vivants **du cercle
      du lecteur**, les seuls dont l'API expose une date.
    -->
    <label class="flex cursor-pointer items-center gap-1.5 text-xs text-attenue">
      <input
        type="checkbox"
        :checked="vivantsSeuls"
        class="accent-accent"
        title="N’afficher que les personnes vivantes de votre cercle de visibilité. Ce réglage n’est pas enregistré dans votre préférence."
        @change="emit('vivantsSeuls', ($event.target as HTMLInputElement).checked)"
      />
      Vivants uniquement
    </label>

    <span class="flex-auto max-md:hidden"></span>

    <span v-if="chargement" class="text-xs font-semibold text-accent" aria-live="polite">…</span>

    <button
      type="button"
      class="cursor-pointer whitespace-nowrap rounded-pastille border border-ligne bg-fond px-2.5 py-1 text-xs text-attenue hover:border-accent"
      title="Revenir au cadrage de départ (touche 0) et effacer la sélection"
      @click="emit('recadrer')"
    >
      Recadrer
    </button>

    <BoutonBase
      variante="secondaire"
      :desactive="preferenceAJour"
      :chargement="enregistrement"
      :title="
        preferenceAJour
          ? 'Ces réglages sont déjà votre préférence'
          : 'Retenir la bande, le sens, les branches et l’incertitude'
      "
      @click="emit('enregistrer')"
    >
      {{ preferenceAJour ? 'Réglages enregistrés' : 'Enregistrer ces réglages' }}
    </BoutonBase>
  </div>
</template>
