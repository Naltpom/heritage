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
 * ## Les onglets (trois depuis le 30/09/2026)
 *
 * - **Identité et famille** : `IdentiteFiche` (où se dessine le calque « en
 *   attente », à côté de la valeur validée), pour un administrateur et une
 *   personne vivante l'autorisation signée (`AutorisationNaissance`, ADR 0016),
 *   les faits, puis `ParenteFiche` ;
 * - **Sources et documents** (ordre du 05/10/2026) : `GalerieMedias` — le
 *   dépôt en tête (ancre `#depot`), le carrousel des portraits, les preuves ;
 *   elle charge ses propres médias, montée seulement quand l'onglet est
 *   ouvert —, puis `SourcesFiche` (sources, puis notes), puis les actes
 *   demandés (`ListeDemarches`) ;
 * - **Historique**, toujours présent : en tête, les `demandesEnAttente` que la
 *   fiche porte, vers `demande.detail` (quand il y en a) ; puis la frise de
 *   `HistoriqueFiche` — sources, notes, documents et révisions appliquées, du
 *   plus récent au plus ancien —, montée seulement quand l'onglet est ouvert.
 *
 * Le contenu **coulisse** d'un onglet à l'autre (`ContenuGlissant`) : vers la
 * gauche quand on avance dans la rangée, vers la droite quand on recule — au
 * clic sur un onglet comme au glissement du doigt, qui changent la même URL.
 *
 * ## Le rôle choisit la route, jamais l'affichage (§ 10.4)
 *
 * Chaque bloc s'affiche parce que la charge le porte. Seules les **actions
 * réservées** se cachent, et chacune porte sa condition (`gestes.ts`). La barre
 * d'action mobile n'existe que si elle a au moins un geste à offrir — elle ne
 * teste pas un rôle, elle additionne ce qui est permis. Elle porte les mêmes
 * icônes que le bandeau desktop (`ActionsFiche`), et se pose au-dessus de la
 * barre de navigation du bas (`--hauteur-barre-bas`).
 *
 * Le bandeau porte aussi « Générer l'affiche » (`AfficheFiche`) et « Partager »
 * (`PartageFiche`), sur toutes les largeurs : ni l'un ni l'autre ne modifie rien,
 * ils n'attendent donc aucun droit — seulement qu'on ne soit pas en train de
 * corriger.
 *
 * ## Le mode édition (crayon, 02/10/2026)
 *
 * C'est **ici** qu'il vit, parce qu'il touche tout l'écran : le bandeau et les
 * panneaux passent au violet, l'identité et la famille deviennent des champs
 * (`EditionIdentite`, `EditionParente`), les onglets se retirent, et les icônes
 * deviennent « Annuler » et « Corriger » — au même endroit, sur ordinateur comme
 * sur téléphone. L'état, le récapitulatif et les corps des demandes sont dans
 * `edition.ts`, un module pur ; la session — entrer, abandonner, déposer — dans
 * `useEditionFiche`. Cette page ne fait que les afficher.
 *
 * « Confirmer » dépose les demandes **une par une, dans l'ordre** (champs,
 * retraits, ajouts), et retient celles qui sont parties : un second clic après un
 * échec partiel ne redépose pas ce qui est déjà dans la file. Au succès, on sort
 * du mode édition et on annonce les demandes, avec un lien vers chacune ; la
 * fiche est relue, pour que le calque « en attente » montre ce qui vient d'être
 * proposé.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import type { FichePersonneComplete } from '@/lib/api'
import DialogueConfirmation from '@/components/base/DialogueConfirmation.vue'
import ContenuGlissant from '@/components/mise-en-page/ContenuGlissant.vue'
import GalerieMedias from '@/components/medias/GalerieMedias.vue'
import ListeDemarches from '@/components/demarches/ListeDemarches.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import ActionsFiche from './ActionsFiche.vue'
import AfficheFiche from './AfficheFiche.vue'
import AutorisationNaissance from './AutorisationNaissance.vue'
import BandeauFiche from './BandeauFiche.vue'
import { couleurBranche, generationLisible, nomBranche } from './champs'
import { LIBELLES_DEPOT } from './edition'
import EditionIdentite from './EditionIdentite.vue'
import EditionParente from './EditionParente.vue'
import FaitsFiche from './FaitsFiche.vue'
import HistoriqueFiche from './HistoriqueFiche.vue'
import { useGestesFiche } from './gestes'
import IdentiteFiche from './IdentiteFiche.vue'
import ModaleCorrection from './ModaleCorrection.vue'
import { useEditionFiche } from './useEditionFiche'
import { ongletsDeFiche, ongletVoisin, requeteDOnglet, sensDuGlissement, type OngletFiche } from './onglets'
import ParenteFiche from './ParenteFiche.vue'
import PartageFiche from './PartageFiche.vue'
import SectionFiche from './SectionFiche.vue'
import SourcesFiche from './SourcesFiche.vue'

const { fiche, onglet } = defineProps<{
  fiche: FichePersonneComplete
  onglet: OngletFiche
}>()

const emit = defineEmits<{
  agrandir: [cible: CibleLoupe]
  /** Des demandes viennent d'être déposées : la fiche doit être relue (calque). */
  recharger: []
}>()

const onglets = computed(() => ongletsDeFiche(fiche))
/** L'ordre de la rangée : c'est lui qui donne son sens au coulissement. */
const ordre = computed(() => onglets.value.map((o) => o.cle))

/*
 * Téléphone : glisser le doigt à gauche ou à droite sur le contenu passe à
 * l'onglet voisin (Identité → Sources → Historique), et le contenu coulisse
 * dans le sens du doigt. Même URL que le clic sur l'onglet ; `replace` pour ne
 * pas remplir l'historique d'un aller-retour par geste.
 */
const routeur = useRouter()
let depart: { x: number; y: number } | null = null
function surToucheDebut(e: TouchEvent): void {
  // En édition, un glissement de doigt dans un champ ne doit pas changer d'onglet.
  if (edition.value !== null) return
  const t = e.touches[0]
  depart = e.touches.length === 1 && t ? { x: t.clientX, y: t.clientY } : null
}
function surToucheFin(e: TouchEvent): void {
  const t = e.changedTouches[0]
  if (!depart || !t) return
  const sens = sensDuGlissement(t.clientX - depart.x, t.clientY - depart.y)
  depart = null
  if (sens === 0) return
  const cible = ongletVoisin(onglets.value, onglet, sens)
  if (cible) void routeur.replace({ name: 'personne.detail', params: { id: fiche.id }, query: requeteDOnglet(cible) })
}

const { peutProposer, peutDeposer, peutDemanderSuppression, peutGererAutorisationNaissance } = useGestesFiche()

/** La barre du bas se remplit par ce qui est permis ; vide, elle n'existe pas. */
const barreAction = computed(
  () => peutProposer.value || peutDeposer.value || peutDemanderSuppression.value,
)

const demandes = computed(() => fiche.demandesEnAttente)

/* --- Le mode édition ------------------------------------------------ */

/*
 * La session vit dans `useEditionFiche` (éprouvée seule) ; la page ne fait que
 * l'afficher. Entrer amène l'onglet Identité, qui porte ce qu'on corrige ;
 * déposer fait relire la fiche, pour que le calque montre ce qui vient de partir.
 */
const {
  edition,
  recap,
  message,
  envoi,
  erreurEnvoi,
  erreursSaisie,
  erreursChamps,
  annonce,
  confirmationAnnulation,
  entrer,
  quitter,
  annuler,
  corriger,
  confirmer,
} = useEditionFiche(() => fiche, {
  surEntree: () => {
    if (onglet !== 'identite') {
      void routeur.replace({ name: 'personne.detail', params: { id: fiche.id }, query: requeteDOnglet('identite') })
    }
  },
  surDepot: () => emit('recharger'),
})

function pluriel(n: number, mot: string): string {
  return `${n} ${mot}${n > 1 ? 's' : ''}`
}
</script>

<template>
  <div class="flex flex-col" :class="barreAction ? 'max-md:pb-20' : ''" :data-mode-edition="edition !== null ? '' : undefined">
    <BandeauFiche
      :fiche="fiche"
      :onglet="onglet"
      :edition="edition !== null"
      @agrandir="emit('agrandir', $event)"
    >
      <!-- Partager et l'affiche ne modifient rien : offerts à tout lecteur, retirés en édition. -->
      <template #partage>
        <AfficheFiche v-if="edition === null" :fiche="fiche" />
        <PartageFiche v-if="edition === null" :fiche="fiche" />
      </template>
      <template #actions>
        <ActionsFiche
          :fiche="fiche"
          :en-edition="edition !== null"
          :envoi="envoi"
          @editer="entrer"
          @annuler="annuler"
          @corriger="corriger"
        />
      </template>
    </BandeauFiche>

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
        <!-- L'annonce de fin d'édition : ce qui est parti, et où le suivre. -->
        <section
          v-if="annonce.length > 0"
          class="flex flex-col gap-1.5 rounded-panneau border border-edition-bord bg-edition-doux px-4 py-3 text-[13.5px]"
          role="status"
          data-annonce-depot
        >
          <p class="m-0 font-semibold">
            {{ annonce.length > 1 ? 'Corrections envoyées : un administrateur va les relire.' : 'Correction envoyée : un administrateur va la relire.' }}
          </p>
          <ul class="m-0 flex list-none flex-col gap-0.5 p-0">
            <li v-for="depot in annonce" :key="depot.id">
              {{ LIBELLES_DEPOT[depot.kind] }} :
              <RouterLink :to="{ name: 'demande.detail', params: { id: depot.id } }" class="font-semibold text-edition underline">
                demande n° {{ depot.id }}
              </RouterLink>
            </li>
          </ul>
          <button type="button" class="self-start text-xs text-attenue underline" @click="annonce = []">Fermer</button>
        </section>

        <!-- Mobile : les demandes en attente, au-dessus du contenu. -->
        <RouterLink
          v-if="demandes.length > 0 && onglet !== 'historique' && edition === null"
          :to="{ name: 'personne.detail', params: { id: fiche.id }, query: requeteDOnglet('historique') }"
          class="flex items-center justify-between gap-3 rounded-panneau bg-accent-doux px-3.5 py-2.5 text-[13px] md:hidden"
        >
          <span>
            <b>{{ pluriel(demandes.length, 'demande') }}</b> en attente sur cette fiche
          </span>
          <span class="font-semibold text-accent">Voir</span>
        </RouterLink>

        <ContenuGlissant :ordre="ordre" :actif="edition !== null ? 'identite' : onglet">
          <div class="flex flex-col gap-3.5 max-md:gap-3">
            <!-- Le mode édition : les mêmes panneaux, en violet, devenus des champs. -->
            <template v-if="edition !== null">
              <SectionFiche titre="Identité" edition data-panneau="identite">
                <EditionIdentite v-model="edition.saisie" :fiche="fiche" :erreurs="erreursChamps" />
              </SectionFiche>
              <SectionFiche titre="Famille" edition data-panneau="famille">
                <EditionParente
                  v-model:retraits="edition.retraits"
                  v-model:ajouts="edition.ajouts"
                  v-model:unions="edition.unions"
                  :fiche="fiche"
                />
              </SectionFiche>
            </template>

            <template v-else-if="onglet === 'identite'">
              <SectionFiche titre="Identité" data-panneau="identite">
                <IdentiteFiche :fiche="fiche" />
              </SectionFiche>
              <!-- ADR 0016 : l'autorisation signée d'un vivant sans compte. -->
              <SectionFiche
                v-if="fiche.vivant && peutGererAutorisationNaissance"
                titre="Date de naissance — autorisation signée"
                data-panneau="autorisation-naissance"
              >
                <AutorisationNaissance :person-id="fiche.id" />
              </SectionFiche>
              <SectionFiche v-if="fiche.faits.length > 0" titre="Faits">
                <FaitsFiche :fiche="fiche" />
              </SectionFiche>
              <SectionFiche titre="Famille" data-panneau="famille">
                <ParenteFiche :fiche="fiche" />
              </SectionFiche>
            </template>

            <template v-else-if="onglet === 'sources'">
              <!-- D'abord le dépôt, puis les portraits en carrousel, puis les
                   preuves (`GalerieMedias`, qui porte l'ancre `#depot`) ;
                   ensuite les sources et les notes. Un changement de portrait
                   fait relire la fiche : l'en-tête le montre. -->
              <GalerieMedias
                :person-id="fiche.id"
                :nom="fiche.displayName"
                @agrandir="emit('agrandir', $event)"
                @portrait="emit('recharger')"
              />

              <SectionFiche titre="Sources et notes" data-panneau="sources">
                <SourcesFiche :fiche="fiche" @agrandir="emit('agrandir', $event)" />
              </SectionFiche>

              <!-- Les actes demandés pour cette personne, et où ils en sont : une
                   pièce qui manque ici est peut-être déjà en route. -->
              <SectionFiche titre="Actes demandés" data-panneau="actes">
                <ListeDemarches :personne="{ personId: fiche.id, libelle: fiche.displayName }" />
              </SectionFiche>
            </template>

            <template v-else>
              <!-- Le calque les signale champ par champ ; cet onglet les
                   rassemble, en tête, parce qu'une demande peut porter sur
                   autre chose qu'un champ affiché (un lien, un média). -->
              <SectionFiche v-if="demandes.length > 0" titre="Demandes en attente" data-panneau="demandes">
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

              <!-- Puis tout ce qui a été ajouté ou changé, du plus récent au
                   plus ancien. -->
              <SectionFiche titre="Historique" data-panneau="historique">
                <HistoriqueFiche :fiche="fiche" @agrandir="emit('agrandir', $event)" />
              </SectionFiche>
            </template>
          </div>
        </ContenuGlissant>
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
    <!-- Les mêmes icônes que le bandeau desktop, réparties sur la largeur. La
         barre se pose au-dessus de la navigation du bas (`--hauteur-barre-bas`,
         0 tant qu'elle n'est pas là), et passe au violet en mode édition. -->
    <div
      v-if="barreAction"
      data-barre-action
      class="fixed inset-x-0 bottom-[var(--hauteur-barre-bas,0px)] z-20 flex gap-2 px-4 pt-3 pb-3 md:hidden"
      :class="edition !== null ? 'border-t-2 border-edition bg-panneau' : 'border-t border-ligne bg-panneau'"
    >
      <ActionsFiche
        :fiche="fiche"
        disposition="barre"
        :en-edition="edition !== null"
        :envoi="envoi"
        @editer="entrer"
        @annuler="annuler"
        @corriger="corriger"
      />
    </div>

    <ModaleCorrection
      v-model:message="message"
      :changements="recap"
      :envoi="envoi"
      :erreur="erreurEnvoi"
      :erreurs-saisie="erreursSaisie"
      @confirmer="confirmer"
      @annuler="recap = null"
    />
    <DialogueConfirmation
      :demande="confirmationAnnulation"
      @confirmer="quitter"
      @annuler="confirmationAnnulation = null"
    />
  </div>
</template>
