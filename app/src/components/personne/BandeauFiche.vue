<script setup lang="ts">
/**
 * Le bandeau de la page fiche (maquette 4c) : retour, portrait, nom, ligne de
 * dates, pastilles, actions, et la rangée d'onglets.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Ce qu'il affiche, et d'où chaque chose vient
 *
 * - **`ligneDates`** : la forme compacte que le serveur écrit (§ 14.4), telle
 *   quelle. On n'en compose aucune : « 1901-1909 – 1987 » ne se fabrique pas à
 *   partir de deux bornes sans réimplémenter le formateur du serveur, et en
 *   diverger. Une fourchette ne s'aplatit pas.
 * - **La pastille de parenté** : `parente`, calculée par le serveur relativement
 *   au lecteur. `null` : pas de pastille, plutôt qu'une parenté inventée.
 * - **La pastille documentaire** : `regimeDocumentaire()`. « Vérifié » veut dire
 *   **appuyé sur un acte** (`bestQuay ≥ 2`), jamais « a une source ». Desktop :
 *   la phrase entière ; mobile : la forme courte.
 * - **La note de confidentialité** : `noteDeConfidentialite()`, commandée par
 *   `vivant` — jamais par `deceased`, ni par l'absence de dates.
 *
 * ## Les actions : un seul bouton, « Proposer »
 *
 * À droite, sans rétrécir : le bouton « Proposer » (`MenuFiche`), qui ouvre
 * « Corriger cette fiche », « Ajouter un parent, un enfant ou un conjoint »,
 * « Joindre un document » puis, **séparée par un filet** et en couleur
 * d'alerte, « Demander la suppression ». Chaque geste porte sa condition
 * (`gestes.ts`) ; sur mobile, le même bouton descend dans la barre d'action
 * fixe de `PageFiche`, et le bandeau n'en montre aucun.
 *
 * ## Les onglets
 *
 * `OngletsFiche` : trois onglets dont le soulignement glisse (refonte du
 * 30/09/2026). Le contenu qui coulisse dessous est l'affaire de `PageFiche`.
 *
 * ## « ‹ Retour à l'arbre »
 *
 * Toujours présent sur desktop — sur mobile, l'en-tête de l'application porte
 * déjà « ‹ Arbre ». Il mène à `{ name: 'arbre' }` : quand on vient de l'arbre,
 * celui-ci relit la vue mémorisée (`histoire/retour.ts`) et se remonte **sur la
 * vue exacte qu'on avait quittée** ; sinon, il s'ouvre ajusté. `retourMemorise`
 * ne décide donc que de l'infobulle, pas de la présence du lien.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import type { CibleLoupe } from '@/components/medias/medias'
import { noteDeConfidentialite, regimeDocumentaire } from './champs'
import MenuFiche from './MenuFiche.vue'
import OngletsFiche from './OngletsFiche.vue'
import PortraitFiche from './PortraitFiche.vue'
import type { OngletFiche } from './onglets'

const { fiche, onglet, retourMemorise = false } = defineProps<{
  fiche: FichePersonneComplete
  onglet: OngletFiche
  /** L'arbre a mémorisé la vue qu'on a quittée : le retour la restaurera. */
  retourMemorise?: boolean
}>()

const emit = defineEmits<{ agrandir: [cible: CibleLoupe] }>()

const documentaire = computed(() => regimeDocumentaire(fiche))
const confidentialite = computed(() => noteDeConfidentialite(fiche))
</script>

<template>
  <div class="border-b border-ligne bg-panneau">
    <div
      class="mx-auto flex w-full max-w-[1040px] flex-col gap-3.5 px-8 pt-[18px] max-md:gap-3 max-md:px-5 max-md:pt-1"
    >
      <RouterLink
        :to="{ name: 'arbre' }"
        class="self-start text-[13px] text-accent hover:underline max-md:hidden"
        :title="
          retourMemorise
            ? 'Revenir à l’arbre, sur la vue que vous aviez quittée'
            : 'Aller à l’arbre'
        "
      >
        <span aria-hidden="true">‹ </span>Retour à l’arbre
      </RouterLink>

      <div class="flex items-center gap-5 max-md:gap-3.5">
        <PortraitFiche
          :portrait="fiche.portrait"
          :nom="fiche.displayName"
          :sexe="fiche.sex"
          @agrandir="emit('agrandir', $event)"
        />

        <div class="flex min-w-0 flex-1 flex-col gap-1.5 max-md:gap-1">
          <h1
            class="m-0 text-[28px] leading-[1.1] font-bold tracking-[-.02em] break-words max-md:text-[22px] max-md:leading-[1.15]"
          >
            {{ fiche.displayName }}
          </h1>

          <!-- La forme compacte du serveur, telle quelle. Jamais recomposée. -->
          <p
            v-if="fiche.ligneDates"
            class="m-0 font-mono text-sm tnum text-attenue max-md:text-[13px]"
          >
            {{ fiche.ligneDates }}
          </p>

          <div
            v-if="fiche.parente || documentaire !== null"
            class="flex flex-wrap gap-1.5"
          >
            <span
              v-if="fiche.parente"
              class="rounded-pastille bg-accent-doux px-2.5 py-0.5 text-xs font-semibold text-accent"
            >
              {{ fiche.parente }}
            </span>
            <span
              v-if="documentaire !== null"
              data-regime
              class="rounded-pastille border px-2.5 py-px text-xs font-semibold"
              :class="
                documentaire.ton === 'bon'
                  ? 'border-ok-bord bg-ok-doux text-ok'
                  : 'border-alerte-bord bg-alerte-doux text-alerte'
              "
              :title="documentaire.texte"
            >
              <span class="max-md:hidden">{{ documentaire.texte }}</span>
              <span class="md:hidden">{{ documentaire.court }}</span>
            </span>
          </div>

          <p v-if="confidentialite !== null" class="m-0 text-xs leading-relaxed text-attenue">
            {{ confidentialite }}
          </p>
        </div>

        <!-- Les actions desktop. Sur mobile, elles sont dans la barre du bas. -->
        <div class="flex flex-none self-start max-md:hidden">
          <MenuFiche :fiche="fiche" />
        </div>
      </div>

      <OngletsFiche :fiche="fiche" :actif="onglet" />
    </div>
  </div>
</template>
