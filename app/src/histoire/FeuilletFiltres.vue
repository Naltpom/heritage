<script setup lang="ts">
/**
 * Le feuillet « Filtres » de l'arbre, dans la barre d'écran.
 *
 * Quatre sections, toujours dans le même ordre (refonte du 05/10/2026) : ce qu'on
 * SUIT (la ligne directe), ce qu'on ESTOMPE (les branches), ce qui ENTRE dans
 * l'arbre (bascules qui le resserrent), puis l'AFFICHAGE (ni filtre, ni
 * réinitialisé). Les pastilles de qualité sont au-dessus, dans la barre (bureau)
 * ou en tête du feuillet.
 *
 * Le feuillet ne décide rien : il montre l'état que l'écran lui donne et lui
 * remonte chaque geste.
 */
import { computed } from 'vue'
import ReglageFormatDesCartes from '@/components/arbre/ReglageFormatDesCartes.vue'
import InterrupteurFiltre from '@/components/arbre/InterrupteurFiltre.vue'
import Segmente from '@/components/mise-en-page/Segmente.vue'
import { attenuer, couleurBranche } from './branches'
import type { BrancheDeQuart, Quart } from './lignees'
import { SEUIL_DES_NOMS } from './vue'

const props = defineProps<{
  quarts: Quart[]
  masquees: BrancheDeQuart[]
  libelleLigne: string
  ligneDirecte: boolean
  ligneSeule: boolean
  aUneFamilleDeConjoint: boolean
  avecConjoint: boolean
  aDesAlliances: boolean
  avecAlliances: boolean
  effectifAlliances: number
  alliancesDansLArbre: boolean
  effectifSansLien: number
  avecSansLien: boolean
  fratriesParNaissance: boolean
  /** Le zoom affiché, en pour cent (d'après l'instantané du cadrage). */
  zoomEnPourcent: number
}>()

/** Le seuil des noms, en pour cent. */
const seuil = defineModel<number>('seuil', { required: true })

const emit = defineEmits<{
  basculerLigne: []
  modeLigne: [seule: boolean]
  basculerBranche: [branche: BrancheDeQuart]
  basculerConjoint: []
  basculerAlliances: []
  rangerAlliances: [dansLArbre: boolean]
  basculerSansLien: []
  basculerFratries: []
  /** « Dès ce zoom » : le seuil pris au zoom affiché. */
  seuilAuZoom: []
  aide: []
}>()

const OPTIONS_ALLIANCES = [
  { dans: false, libelle: 'À part', sous: 'chacune son bloc, à droite' },
  { dans: true, libelle: 'Dans mon arbre', sous: 'dans la branche qui les relie' },
] as const

const OPTIONS_MODE_LIGNE = [
  { valeur: 'estompee', libelle: 'Estomper les autres' },
  { valeur: 'seule', libelle: 'Seulement elle' },
] as const

/*
 * LE SEUIL DES NOMS : le zoom à partir duquel noms, prénoms et dates s'écrivent. Une
 * jauge en pour cent, de 10 à 90 (45 par défaut). Sur un écran 2K, 4K ou une
 * télévision, les fiches restent grandes bien en deçà de 45 % : on l'abaisse pour lire
 * les noms de plus loin. Ce n'est pas un filtre : ni dans « Filtres · n », ni dans
 * « Réinitialiser » — une préférence de l'appareil, comme le format des fiches.
 */
const SEUIL_MIN = Math.round(SEUIL_DES_NOMS.min * 100)
const SEUIL_MAX = Math.round(SEUIL_DES_NOMS.max * 100)
const SEUIL_DEFAUT = Math.round(SEUIL_DES_NOMS.defaut * 100)
/** La place du zoom actuel sur la jauge (0 à 1), `null` hors de ses bornes. */
const repereDuZoom = computed(() => {
  const p = (props.zoomEnPourcent - SEUIL_MIN) / (SEUIL_MAX - SEUIL_MIN)
  return p >= 0 && p <= 1 ? p : null
})
</script>

<template>
  <section class="filtres-section" aria-labelledby="filtres-ligne">
    <h3 id="filtres-ligne" class="filtres-titre">Ligne directe</h3>
    <InterrupteurFiltre :libelle="libelleLigne" sous="ses ancêtres et ses descendants" :actif="ligneDirecte" @basculer="emit('basculerLigne')" />
    <Segmente
      v-if="ligneDirecte"
      :options="OPTIONS_MODE_LIGNE"
      :valeur="ligneSeule ? 'seule' : 'estompee'"
      etiquette="Les autres fiches"
      :colonnes="2"
      class="w-full"
      @choisir="(m) => emit('modeLigne', m === 'seule')"
    />
    <p v-if="ligneDirecte" class="m-0 text-xs text-attenue">
      {{ ligneSeule ? 'Les autres fiches quittent l’écran : l’arbre se refait sur la ligne.' : 'Les autres fiches restent à leur place, estompées.' }}
    </p>
  </section>

  <section v-if="quarts.length" class="filtres-section" aria-labelledby="filtres-branches">
    <div class="flex items-baseline justify-between gap-2">
      <h3 id="filtres-branches" class="filtres-titre">Branches</h3>
      <span class="text-[11px] text-attenue">touchez pour estomper</span>
    </div>
    <div class="grid grid-cols-2 gap-2">
      <button
        v-for="q in quarts"
        :key="q.branche"
        type="button"
        class="flex min-h-11 items-center gap-2 rounded-champ border px-2.5 py-1.5 text-left"
        :style="
          masquees.includes(q.branche)
            ? undefined
            : { borderColor: couleurBranche(q.branche), background: `color-mix(in srgb, ${couleurBranche(q.branche)} 12%, transparent)` }
        "
        :class="masquees.includes(q.branche) ? 'border-ligne text-attenue' : ''"
        :aria-pressed="!masquees.includes(q.branche)"
        @click="emit('basculerBranche', q.branche)"
      >
        <!-- Estompée : la pastille pâlit dans sa couleur même, et le texte passe en atténué —
             plus d'opacité sur le bouton (ADR 0007, note du 2026-10-06). -->
        <span
          class="h-2.5 w-2.5 flex-none rounded-[3px]"
          :style="{ background: attenuer(couleurBranche(q.branche), masquees.includes(q.branche) ? 0.55 : 1) }"
        />
        <span class="flex min-w-0 flex-col leading-tight">
          <span class="truncate text-[13px] font-semibold">{{ q.nom || '—' }}</span>
          <span class="truncate text-[11px] text-attenue">{{ q.libelle }}</span>
        </span>
      </button>
    </div>
  </section>

  <section v-if="aUneFamilleDeConjoint || aDesAlliances || effectifSansLien > 0" class="filtres-section" aria-labelledby="filtres-qui">
    <h3 id="filtres-qui" class="filtres-titre">Qui apparaît</h3>
    <p class="-mt-1 mb-0 text-xs text-attenue">Masquées, ces personnes quittent l’arbre, qui se resserre.</p>
    <InterrupteurFiltre
      v-if="aUneFamilleDeConjoint"
      libelle="Famille du conjoint"
      sous="ses parents, ses frères et sœurs"
      :actif="avecConjoint"
      @basculer="emit('basculerConjoint')"
    />
    <InterrupteurFiltre
      v-if="aDesAlliances"
      :libelle="`Familles par alliance · ${effectifAlliances}`"
      sous="les familles des conjoints de vos proches"
      :actif="avecAlliances"
      @basculer="emit('basculerAlliances')"
    />
    <div v-if="aDesAlliances && avecAlliances" class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Où afficher les familles par alliance">
      <button
        v-for="o in OPTIONS_ALLIANCES"
        :key="o.libelle"
        type="button"
        role="radio"
        :aria-checked="alliancesDansLArbre === o.dans"
        class="flex min-h-11 flex-col justify-center rounded-champ border px-2.5 py-1.5 text-left leading-tight"
        :class="alliancesDansLArbre === o.dans ? 'border-accent bg-accent-doux' : 'border-ligne'"
        @click="emit('rangerAlliances', o.dans)"
      >
        <span class="text-[13px] font-semibold">{{ o.libelle }}</span>
        <span class="text-[11px] text-attenue">{{ o.sous }}</span>
      </button>
    </div>
    <InterrupteurFiltre
      v-if="effectifSansLien > 0"
      :libelle="`Sans lien connu · ${effectifSansLien}`"
      sous="aucun lien ne les relie encore à vous"
      :actif="avecSansLien"
      @basculer="emit('basculerSansLien')"
    />
  </section>

  <section class="filtres-section" aria-labelledby="filtres-affichage">
    <h3 id="filtres-affichage" class="filtres-titre">Affichage</h3>
    <!-- Pas des filtres : ni dans « Filtres · n », ni dans « Réinitialiser ». -->
    <ReglageFormatDesCartes />
    <InterrupteurFiltre
      libelle="Fratries par ordre de naissance"
      sous="l’aîné à gauche, le plus jeune à droite"
      :actif="fratriesParNaissance"
      @basculer="emit('basculerFratries')"
    />
    <div class="flex flex-col gap-1.5" data-seuil-des-noms>
      <div class="flex items-baseline justify-between gap-2">
        <label for="seuil-des-noms" class="text-sm">Noms et dates dès</label>
        <span class="font-mono text-sm font-semibold tabular-nums">{{ seuil }} %</span>
      </div>
      <div class="relative">
        <input
          id="seuil-des-noms"
          v-model.number="seuil"
          type="range"
          :min="SEUIL_MIN"
          :max="SEUIL_MAX"
          step="5"
          class="seuil-des-noms h-8 w-full cursor-pointer"
          aria-describedby="seuil-des-noms-aide"
        />
        <!-- Le zoom affiché en ce moment, sous la jauge. -->
        <span
          v-if="repereDuZoom !== null"
          class="pointer-events-none absolute -bottom-1 h-0 w-0 -translate-x-1/2 border-x-[5px] border-b-[6px] border-x-transparent border-b-texte"
          :style="{ left: `calc(8px + ${repereDuZoom} * (100% - 16px))` }"
          aria-hidden="true"
        />
      </div>
      <div class="flex items-center justify-between gap-2 text-xs text-attenue">
        <span id="seuil-des-noms-aide">Zoom actuel : {{ zoomEnPourcent }} % · défaut {{ SEUIL_DEFAUT }} %</span>
        <button
          v-if="zoomEnPourcent >= SEUIL_MIN && zoomEnPourcent < seuil"
          type="button"
          class="min-h-8 font-semibold text-accent"
          @click="emit('seuilAuZoom')"
        >
          Dès ce zoom
        </button>
        <button v-else-if="seuil !== SEUIL_DEFAUT" type="button" class="min-h-8 font-semibold text-accent" @click="seuil = SEUIL_DEFAUT">
          Par défaut
        </button>
      </div>
      <p class="m-0 text-xs text-attenue">Plus bas, les noms restent écrits de plus loin : utile sur un grand écran ou une télévision.</p>
    </div>
  </section>

  <button
    type="button"
    data-aide-filtres
    class="flex min-h-11 items-center gap-2 self-start border-t border-ligne pt-3 text-left text-sm text-accent hover:underline"
    @click="emit('aide')"
  >
    <span class="flex size-5 flex-none items-center justify-center rounded-full border-[1.5px] border-current text-[11px] font-bold" aria-hidden="true">?</span>
    Voir la vidéo : comment marchent les filtres
  </button>
</template>

<style scoped>
.filtres-section {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.filtres-section + .filtres-section {
  border-top: 1px solid var(--color-ligne);
  padding-top: 0.875rem;
}
.filtres-titre {
  margin: 0;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-attenue);
}
.seuil-des-noms {
  accent-color: var(--color-accent);
}
</style>
