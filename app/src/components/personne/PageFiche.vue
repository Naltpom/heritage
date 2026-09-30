<script setup lang="ts">
/**
 * La page d'une **fiche complète** : bandeau, onglet courant, colonne « Dans
 * l'arbre », et, sur mobile, la barre d'action fixe en bas.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Ce que ce composant reçoit, et ce qu'il ne tient pas
 *
 * Il reçoit la fiche **déjà discriminée** (`FichePersonneComplete`) et l'onglet
 * **déjà décidé** : la vue lit `?onglet=` et le passe ici (`onglets.ts`). Il ne
 * lit pas la route, ce qui le rend montable seul dans un test de rendu.
 *
 * Il ne tient pas la loupe : portrait, images d'archive et documents émettent
 * `agrandir`, et la vue garde **une seule** cible — une seule image d'origine
 * décodée à la fois —, qu'elle ferme quand on change de fiche.
 *
 * ## Les onglets
 *
 * - **Identité** : `IdentiteFiche` (où se dessine le calque « en attente », à
 *   côté de la valeur validée) et les faits ;
 * - **Famille** : `ParenteFiche` ;
 * - **Sources et notes** : `SourcesFiche`, avec `appui` / `appuiLabel` ;
 * - **Preuves et documents** : `GalerieMedias`, qui charge ses propres médias —
 *   monté seulement quand l'onglet est ouvert ;
 * - **Demandes** : les `demandesEnAttente` que la fiche porte, vers
 *   `demande.detail`. L'onglet n'existe que s'il y en a.
 *
 * ## Le rôle choisit la route, jamais l'affichage (§ 10.4)
 *
 * Chaque bloc s'affiche parce que la charge le porte. Seules les **actions
 * réservées** se cachent, et chacune porte sa condition (`gestes.ts`). La barre
 * d'action mobile n'existe que si elle a au moins un geste à offrir — elle ne
 * teste pas un rôle, elle additionne ce qui est permis.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import GalerieMedias from '@/components/medias/GalerieMedias.vue'
import ListeDemarches from '@/components/demarches/ListeDemarches.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import BandeauFiche from './BandeauFiche.vue'
import { couleurBranche, generationLisible, nomBranche } from './champs'
import FaitsFiche from './FaitsFiche.vue'
import { useGestesFiche } from './gestes'
import IdentiteFiche from './IdentiteFiche.vue'
import MenuFiche from './MenuFiche.vue'
import { ongletsDeFiche, ongletVoisin, requeteDOnglet, sensDuGlissement, type OngletFiche } from './onglets'
import { useRouter } from 'vue-router'
import ParenteFiche from './ParenteFiche.vue'
import PointProposition from './PointProposition.vue'
import SectionFiche from './SectionFiche.vue'
import SourcesFiche from './SourcesFiche.vue'

const { fiche, onglet, retourMemorise = false } = defineProps<{
  fiche: FichePersonneComplete
  onglet: OngletFiche
  retourMemorise?: boolean
}>()

const emit = defineEmits<{ agrandir: [cible: CibleLoupe] }>()

/*
 * Téléphone : glisser le doigt à gauche ou à droite sur le contenu passe à
 * l'onglet voisin (Identité → Famille → Sources → Documents → Demandes). Même
 * URL que le clic sur l'onglet ; `replace` pour ne pas remplir l'historique
 * d'un aller-retour par geste.
 */
const routeur = useRouter()
let depart: { x: number; y: number } | null = null
function surToucheDebut(e: TouchEvent): void {
  const t = e.touches[0]
  depart = e.touches.length === 1 && t ? { x: t.clientX, y: t.clientY } : null
}
function surToucheFin(e: TouchEvent): void {
  const t = e.changedTouches[0]
  if (!depart || !t) return
  const sens = sensDuGlissement(t.clientX - depart.x, t.clientY - depart.y)
  depart = null
  if (sens === 0) return
  const cible = ongletVoisin(ongletsDeFiche(fiche), onglet, sens)
  if (cible) void routeur.replace({ name: 'personne.detail', params: { id: fiche.id }, query: requeteDOnglet(cible) })
}

const { peutProposer, peutDeposer, peutDemanderSuppression } = useGestesFiche()

/** La barre du bas se remplit par ce qui est permis ; vide, elle n'existe pas. */
const barreAction = computed(
  () => peutProposer.value || peutDeposer.value || peutDemanderSuppression.value,
)

const demandes = computed(() => fiche.demandesEnAttente)

function pluriel(n: number, mot: string): string {
  return `${n} ${mot}${n > 1 ? 's' : ''}`
}
</script>

<template>
  <div class="flex flex-col" :class="barreAction ? 'max-md:pb-[88px]' : ''">
    <BandeauFiche
      :fiche="fiche"
      :onglet="onglet"
      :retour-memorise="retourMemorise"
      @agrandir="emit('agrandir', $event)"
    />

    <div
      class="mx-auto grid w-full max-w-[1040px] grid-cols-[minmax(0,1fr)_300px] items-start gap-5 px-8 py-5 max-md:grid-cols-1 max-md:gap-3 max-md:px-4 max-md:py-3.5"
    >
      <!-- ------------------------------------------------------- -->
      <!-- Colonne principale : l'onglet courant                    -->
      <!-- ------------------------------------------------------- -->
      <div
        class="flex min-w-0 flex-col gap-3.5 max-md:gap-3"
        @touchstart.passive="surToucheDebut"
        @touchend.passive="surToucheFin"
      >
        <!-- Mobile : les demandes en attente, au-dessus du contenu. -->
        <RouterLink
          v-if="demandes.length > 0 && onglet !== 'demandes'"
          :to="{ name: 'personne.detail', params: { id: fiche.id }, query: requeteDOnglet('demandes') }"
          class="flex items-center justify-between gap-3 rounded-panneau bg-accent-doux px-3.5 py-2.5 text-[13px] md:hidden"
        >
          <span>
            <b>{{ pluriel(demandes.length, 'demande') }}</b> en attente sur cette fiche
          </span>
          <span class="font-semibold text-accent">Voir</span>
        </RouterLink>

        <template v-if="onglet === 'identite'">
          <SectionFiche titre="Identité" data-panneau="identite">
            <IdentiteFiche :fiche="fiche" />
          </SectionFiche>
          <SectionFiche v-if="fiche.faits.length > 0" titre="Faits">
            <FaitsFiche :fiche="fiche" />
          </SectionFiche>
        </template>

        <SectionFiche v-else-if="onglet === 'famille'" titre="Famille" data-panneau="famille">
          <ParenteFiche :fiche="fiche" />
        </SectionFiche>

        <SectionFiche v-else-if="onglet === 'sources'" titre="Sources et notes" data-panneau="sources">
          <SourcesFiche :fiche="fiche" @agrandir="emit('agrandir', $event)" />
        </SectionFiche>

        <SectionFiche
          v-else-if="onglet === 'documents'"
          titre="Preuves et documents"
          data-panneau="documents"
        >
          <GalerieMedias
            :person-id="fiche.id"
            :nom="fiche.displayName"
            @agrandir="emit('agrandir', $event)"
          />

          <!-- Les actes demandés pour cette personne, et où ils en sont : une
               pièce qui manque ici est peut-être déjà en route. -->
          <h3 class="mb-2 mt-6 text-[11px] uppercase tracking-wider text-attenue">Actes demandés</h3>
          <ListeDemarches :personne="{ personId: fiche.id, libelle: fiche.displayName }" />
        </SectionFiche>

        <!-- Le calque les signale champ par champ ; cet onglet les rassemble,
             parce qu'une demande peut porter sur autre chose qu'un champ
             affiché (un lien, un média). -->
        <SectionFiche v-else titre="Demandes en attente" data-panneau="demandes">
          <ul class="m-0 flex list-none flex-col gap-1.5 p-0 text-sm">
            <li v-for="demande in demandes" :key="demande">
              <RouterLink
                :to="{ name: 'demande.detail', params: { id: demande } }"
                class="font-semibold text-accent underline"
              >
                Demande n° {{ demande }}
              </RouterLink>
            </li>
          </ul>
        </SectionFiche>
      </div>

      <!-- ------------------------------------------------------- -->
      <!-- Colonne latérale (desktop)                               -->
      <!-- ------------------------------------------------------- -->
      <aside class="flex flex-col gap-3.5 max-md:hidden" aria-label="Place dans l’arbre">
        <section
          class="flex flex-col gap-2 rounded-panneau border border-ligne bg-panneau px-4 py-3.5"
        >
          <h2 class="m-0 text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">
            Dans l’arbre
          </h2>
          <p class="m-0 flex flex-wrap items-center gap-x-1.5 text-[13px]">
            <span>Génération <span class="font-mono tnum">{{ generationLisible(fiche.generation) }}</span></span>
            <span aria-hidden="true">·</span>
            <span
              class="inline-block size-2 rounded-[2px]"
              :style="{ background: couleurBranche(fiche.branch) }"
              aria-hidden="true"
            />
            <span>{{ nomBranche(fiche.branch) }}</span>
          </p>
          <RouterLink
            :to="{ name: 'arbre', query: { personne: fiche.id } }"
            class="flex h-[34px] items-center justify-center rounded-champ border border-ligne text-[13px] font-semibold transition-colors hover:border-accent"
          >
            Voir dans l’arbre
          </RouterLink>
        </section>

        <section
          v-if="demandes.length > 0"
          class="flex flex-col gap-1.5 rounded-panneau bg-accent-doux px-4 py-3.5 text-[13px]"
        >
          <h2 class="m-0 text-[13px] font-bold">
            {{ pluriel(demandes.length, 'demande') }} en attente
          </h2>
          <RouterLink
            v-for="demande in demandes"
            :key="demande"
            :to="{ name: 'demande.detail', params: { id: demande } }"
            class="text-accent hover:underline"
          >
            Demande n° {{ demande }}
          </RouterLink>
        </section>
      </aside>
    </div>

    <!-- ------------------------------------------------------- -->
    <!-- Mobile : la barre d'action fixe                          -->
    <!-- ------------------------------------------------------- -->
    <!-- La suppression n'y est pas à côté de « Proposer une modification » :
         elle est dans le menu ⋯, derrière un filet. Deux gestes, dont un seul
         détruit. -->
    <div
      v-if="barreAction"
      data-barre-action
      class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-ligne bg-panneau px-4 pt-3 pb-[max(20px,env(safe-area-inset-bottom))] md:hidden"
    >
      <PointProposition :fiche="fiche" disposition="barre" />
      <span v-if="!peutProposer" class="flex-1" />
      <MenuFiche :fiche="fiche" sens="haut" />
    </div>
  </div>
</template>
