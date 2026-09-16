<script setup lang="ts">
/**
 * Fiche d'une personne — § 5.3, § 11.1, § 13 du contrat.
 *
 * Trois règles qui décident de tout l'écran :
 *
 * - **Absent contre `null`** (§ 1.3). `'birth' in fiche === false` veut dire
 *   *interdit* : on n'affiche **pas la ligne du tout**, jamais un « — ». Un
 *   `fiche.birth === null` veut dire *inconnu* : la ligne existe, la valeur est
 *   vide. Le premier est une règle de confidentialité, le second une lacune de
 *   la donnée, et les confondre efface la distinction.
 * - **Fiche réduite, pas erreur.** Une personne vivante hors cercle rend `200`
 *   avec sept clés — son nœud est visible dans le graphe, cliquer dessus doit
 *   donner un nom et une impasse claire, pas une panne apparente.
 * - **Le calque « en attente »** (§ 13.2) : si `enAttente[champ]` existe,
 *   afficher `propose` avec la pastille « en attente de validation » et rendre
 *   `actuel` accessible ; sinon afficher `fiche[champ]`. La valeur validée reste
 *   dans le corps principal — **le calque est à côté, jamais à la place**.
 *   `actuel` peut différer de `fiche[champ]` : c'est ce qui annonce une caducité
 *   à venir, il ne faut pas supposer qu'ils sont égaux.
 *
 * Et les fourchettes de dates ne s'aplatissent pas : « entre 1901 et 1909 »
 * s'affiche tel quel, jamais « 1901 ».
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Ce que cette vue fait, et ce qu'elle délègue
 *
 * Elle charge **une** route — `GET /api/arbre/personnes/{id}` — et répartit la
 * charge utile. Les trois règles ci-dessus ne se décident pas ici : elles vivent
 * dans `components/personne/champs.ts`, un module pur que `champs.test.ts`
 * éprouve sur les charges utiles du contrat, sans monter de composant.
 *
 * La galerie de preuves charge ses propres médias (§ 7.3) : la fiche ne porte
 * que la vue **validée**, et la moitié de ce qu'il faut montrer est justement ce
 * qui attend une décision. Voir `GalerieMedias.vue`.
 *
 * ## Le rôle du lecteur choisit la route, jamais l'affichage (§ 10.4)
 *
 * Une fois la charge reçue, **rien ici ne teste le rôle pour décider quoi
 * dessiner** : chaque bloc s'affiche parce que la charge le porte. Un
 * `v-if="administrateur"` devant un champ que la réponse n'a pas envoyé serait
 * la version front du `if ($estAdmin) { return $tout; }` qu'il a fallu retirer
 * du serveur — et il n'afficherait de toute façon qu'un `undefined`.
 *
 * Seules les **actions réservées** se cachent par rôle, et aucune n'est ici :
 * le dépôt et la suppression d'un média sont dans la galerie, la proposition de
 * modification dans son emplacement. Chacun porte sa condition et l'explique.
 *
 * ## Ce qui n'est volontairement pas branché
 *
 * `GET /api/demandes/historique/personne/{id}` (§ 6.11) : c'est une route du
 * module `Proposal`, dont la fonction d'appel appartient à
 * `lib/api/propositions.ts`. À la place, `demandesEnAttente` — que la fiche
 * porte déjà — renvoie vers `demande.detail`, qui existe.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { lireFichePersonne, type FichePersonne, type FichePersonneComplete } from '@/lib/api'
import { useAppelApi } from '@/composables/useAppelApi'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import EnTeteFiche from '@/components/personne/EnTeteFiche.vue'
import FicheRestreinte from '@/components/personne/FicheRestreinte.vue'
import IdentiteFiche from '@/components/personne/IdentiteFiche.vue'
import ParenteFiche from '@/components/personne/ParenteFiche.vue'
import PointProposition from '@/components/personne/PointProposition.vue'
import SourcesFiche from '@/components/personne/SourcesFiche.vue'
import GalerieMedias from '@/components/medias/GalerieMedias.vue'
import LoupeMedia from '@/components/medias/LoupeMedia.vue'
import type { CibleLoupe } from '@/components/medias/medias'

const route = useRoute()

/** Le routeur contraint déjà `:id(\d+)` : un identifiant provisoire (`#12/p1`)
 *  ne correspond à aucune route côté API et n'en a pas ici non plus (§ 13.4). */
const identifiant = computed(() => Number(route.params['id']))

const {
  donnees: fiche,
  erreur,
  chargement,
  executer,
} = useAppelApi<FichePersonne>(() => lireFichePersonne(identifiant.value))

/**
 * La discrimination, faite **une fois** pour tout le gabarit.
 *
 * `FichePersonne` est une union discriminée sur `visibilite`, et le typeur
 * refuse `fiche.birth` tant qu'on n'a pas discriminé. C'est délibéré : sans
 * cela, `fiche.birth ?? '—'` compilerait et afficherait un tiret — « on ne sait
 * pas » — là où la vérité est « vous n'avez pas le droit de savoir ».
 */
const complete = computed<FichePersonneComplete | null>(() =>
  fiche.value !== null && fiche.value.visibilite === 'complete' ? fiche.value : null,
)

const restreinte = computed(() =>
  fiche.value !== null && fiche.value.visibilite === 'restreinte' ? fiche.value : null,
)

/** Les demandes en attente sur cette fiche, que la charge utile nomme (§ 5.3).
 *  Absentes d'une fiche réduite, comme le calque lui-même (§ 11.1). */
const demandesEnAttente = computed(() => complete.value?.demandesEnAttente ?? [])

/** Une seule image d'origine décodée à la fois, quel que soit le nombre de
 *  vignettes à l'écran. La loupe est tenue ici pour cette raison. */
const loupe = ref<CibleLoupe | null>(null)

// Changer de fiche pendant qu'une loupe est ouverte laisserait à l'écran
// l'image de la fiche précédente.
watch(identifiant, () => {
  loupe.value = null
  void executer()
}, { immediate: true })
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-8">
    <!-- Chargement : la place du contenu à venir, pas une page blanche. -->
    <PanneauBase v-if="chargement && fiche === null">
      <p class="mb-3 text-sm text-attenue" role="status">Chargement de la fiche…</p>
      <SqueletteChargement :lignes="6" />
    </PanneauBase>

    <!-- Une vraie erreur : `404` sur un identifiant qui n'existe pas, réseau,
         session perdue. Une fiche réduite n'arrive **jamais** ici — elle est un
         `200`, et c'est tout le point du § 5.1. -->
    <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="executer" />

    <template v-else-if="fiche !== null">
      <EnTeteFiche :fiche="fiche" @agrandir="loupe = $event" />

      <!-- ------------------------------------------------------- -->
      <!-- L'impasse assumée : sept clés, un nom, une raison        -->
      <!-- ------------------------------------------------------- -->
      <FicheRestreinte v-if="restreinte !== null" :fiche="restreinte" />

      <!-- ------------------------------------------------------- -->
      <!-- La fiche complète                                        -->
      <!-- ------------------------------------------------------- -->
      <template v-else-if="complete !== null">
        <PanneauBase titre="Identité" class="mb-4">
          <IdentiteFiche :fiche="complete" />
        </PanneauBase>

        <!-- Les demandes en cours sur cette fiche. Le calque les signale champ
             par champ ; ce bloc les rassemble, parce qu'une demande peut porter
             sur autre chose qu'un champ affiché ici (un lien, un média). -->
        <PanneauBase
          v-if="demandesEnAttente.length > 0"
          titre="Demandes en attente sur cette fiche"
          class="mb-4"
        >
          <ul class="m-0 flex list-none flex-col gap-1 p-0 text-sm">
            <li v-for="demande in demandesEnAttente" :key="demande">
              <RouterLink
                :to="{ name: 'demande.detail', params: { id: demande } }"
                class="font-semibold text-accent underline"
              >
                Demande n° {{ demande }}
              </RouterLink>
            </li>
          </ul>
        </PanneauBase>

        <PanneauBase titre="Parenté" class="mb-4">
          <ParenteFiche :fiche="complete" />
        </PanneauBase>

        <!-- Les preuves : demande n° 3 du commanditaire, seconde moitié. Le
             portrait, lui, est en tête de fiche. -->
        <PanneauBase titre="Preuves et documents" class="mb-4">
          <GalerieMedias
            :person-id="complete.id"
            :nom="complete.displayName"
            @agrandir="loupe = $event"
          />
        </PanneauBase>

        <PanneauBase titre="Sources et notes" class="mb-4">
          <SourcesFiche :fiche="complete" @agrandir="loupe = $event" />
        </PanneauBase>

        <!-- L'emplacement réservé au formulaire de proposition. Il n'est pas
             implémenté ici : voir `PointProposition.vue` pour son contrat de
             greffe (créneau `action`, données `personId` / `version` /
             `displayName`). -->
        <PanneauBase titre="Proposer une modification" class="mb-4">
          <PointProposition :fiche="complete" />
        </PanneauBase>
      </template>

      <p class="mt-6 text-sm">
        <RouterLink :to="{ name: 'arbre' }" class="text-accent underline">
          Revenir à l’arbre
        </RouterLink>
      </p>
    </template>
  </div>

  <LoupeMedia :cible="loupe" @fermer="loupe = null" />
</template>
