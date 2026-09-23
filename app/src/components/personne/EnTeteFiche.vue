<script setup lang="ts">
/**
 * L'en-tête de la fiche : le portrait, le nom, la ligne de dates, et ce que le
 * lecteur doit savoir du **régime** de cette fiche avant d'en lire le détail.
 *
 * **Forme compacte, pour l'aperçu de la file d'administration** (`ApercuFiche`).
 * La page `/personnes/:id` a son propre bandeau, `BandeauFiche.vue`, avec les
 * onglets et les actions ; les deux lisent les mêmes fonctions de `champs.ts`
 * et suivent les mêmes deux règles, ci-dessous.
 *
 * Deux points de contrat s'y jouent.
 *
 * **1. `ligneDates` est la forme compacte, et elle vient du serveur.** Le § 14.4
 * en fait la référence unique : une même date se rend de trois façons, qui ont
 * trois clés distinctes — la **prose** (`texte`, *dans* le bloc date, « entre
 * 1901 et 1909 »), la **compacte** (`ligneDates`, « 1901-1909 – 1987 ») et
 * l'**étiquette du moteur** (`etiquette`, sur un nœud du graphe et nulle part
 * ailleurs). On affiche celle qui arrive, telle qu'elle arrive. On n'en compose
 * aucune : il n'existe aucune façon correcte de fabriquer « 1901-1909 » à partir
 * de deux bornes sans réimplémenter `EtiquetteDeDateGedcom`, et en diverger.
 *
 * Sur une fiche réduite, `ligneDates` est **absente** — « c'est du formatage,
 * mais c'est du formatage *de dates* : la laisser reviendrait à envoyer la
 * naissance sous une autre orthographe » (§ 11.1). D'où le test d'existence de
 * la clé, et non un `?? ''` qui aurait rendu une chaîne vide indiscernable d'une
 * personne sans dates connues.
 *
 * **2. `vivant` est calculé, `deceased` est stocké** (§ 5.3). Ils ne disent pas
 * la même chose : `deceased` est le `DEAT` du GEDCOM, `vivant` est le résultat
 * de la règle de confidentialité — seuil, repli par génération, `forcePrivacy`.
 * C'est `vivant` qui commande le traitement réservé, jamais `deceased`, et
 * jamais l'absence de dates. « On ne sait pas si elle est morte » n'est pas une
 * autorisation de publier ses dates.
 */
import { computed } from 'vue'
import type { FichePersonne } from '@/lib/api'
import PortraitPersonne from '@/components/medias/PortraitPersonne.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import { noteDeConfidentialite, regimeDocumentaire } from './champs'

const { fiche } = defineProps<{ fiche: FichePersonne }>()

const emit = defineEmits<{
  /** Le lecteur veut voir le portrait en grand. La vue tient la loupe : une
   *  seule image d'origine décodée à la fois, quel que soit le nombre de
   *  vignettes à l'écran. */
  agrandir: [cible: CibleLoupe]
}>()

const complete = computed(() => (fiche.visibilite === 'complete' ? fiche : null))

/** Absente d'une fiche réduite : on teste la clé, on ne replie pas sur `''`. */
const ligneDates = computed<string | null>(() =>
  'ligneDates' in fiche ? (fiche.ligneDates ?? null) : null,
)

const portrait = computed(() => complete.value?.portrait ?? null)

const documentaire = computed(() => regimeDocumentaire(fiche))

/** Voir `noteDeConfidentialite()` : `vivant` commande, jamais `deceased`. */
const confidentialite = computed(() => noteDeConfidentialite(fiche))
</script>

<template>
  <header class="mb-5 flex flex-wrap items-start gap-4 sm:flex-nowrap">
    <PortraitPersonne
      v-if="portrait !== null"
      :portrait="portrait"
      :nom="fiche.displayName"
      @agrandir="emit('agrandir', $event)"
    />

    <div class="min-w-0 flex-1">
      <h1 class="text-xl font-semibold tracking-tight break-words">{{ fiche.displayName }}</h1>

      <!-- La forme compacte du serveur, telle quelle. Jamais recomposée. -->
      <p v-if="ligneDates" class="mt-0.5 font-mono text-sm tnum text-attenue">{{ ligneDates }}</p>

      <p
        v-if="confidentialite !== null"
        class="mt-3 rounded-panneau border border-ligne px-3 py-2 text-xs leading-relaxed"
      >
        {{ confidentialite }}
      </p>

      <!-- « Vérifié » veut dire *appuyé sur un acte ou un registre*, pas « a une
           source » : sur cet arbre, 242 citations sont à `QUAY 0` — des recopies
           d'autres arbres. Confondre les deux viderait l'indication de sens. -->
      <p
        v-if="documentaire !== null"
        class="mt-2 rounded-panneau px-3 py-2 text-xs leading-relaxed"
        :class="
          documentaire.ton === 'bon'
            ? 'border border-ok-bord bg-ok-doux'
            : 'border border-alerte-bord bg-alerte-doux'
        "
      >
        {{ documentaire.texte }}
      </p>
    </div>
  </header>
</template>
