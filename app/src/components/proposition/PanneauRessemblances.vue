<script setup lang="ts">
/**
 * **« Est-ce déjà… ? »** — le panneau des ressemblances du formulaire « Ajouter
 * une personne », entre « Naissance et décès » et les liens.
 *
 * Il n'existe qu'avec un résultat : le formulaire ne le pose pas sans. Ce qu'il
 * montre arrive tel que le serveur l'a rendu (`useRessemblances`) — trié, filtré
 * par le cercle du lecteur, les raisons déjà écrites en français. Il n'en retire
 * que ce que le lecteur a écarté et ce qu'il a déjà désigné dans ses liens.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Deux sortes de cartes.**
 *
 * - **Une personne de l'arbre** : son nom, ses dates, son lieu de naissance, et
 *   pourquoi elle ressemble, en pastilles. « C'est elle : compléter sa fiche »
 *   déplie la complétion **dans la carte** (`CompletionDeFiche`) ; « Voir » ouvre
 *   sa fiche dans un nouvel onglet — sur la page, la saisie reste ici — ou, dans le
 *   volet de `/arbre`, la montre dans l'arbre à côté ; « Ce n'est pas elle »
 *   l'écarte pour cette saisie.
 * - **Une proposition en attente**, sous la forme que le serveur a choisie :
 *   `complete` — la sienne, celle d'un défunt, ou toutes pour un administrateur —
 *   en clair ; `discrete` — un vivant proposé par un autre membre — **une phrase
 *   et rien d'autre**. On n'y reconstruit ni nom, ni date, ni auteur : il n'y en a
 *   pas, et c'est voulu (décision du 09/10/2026).
 *
 *   Son bouton suit l'`action` **calculée par le serveur** (ADR 0017) :
 *   `completer` — « Compléter cette proposition », le formulaire passe en mode
 *   complétion sur elle ; `complement` — « Ajouter mes informations à cette
 *   proposition », le dépôt devient un complément que l'administrateur fusionnera.
 *   Une forme discrète porte toujours `complement`, et rien de plus que son
 *   `draftPersonId` ne remonte.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Le rappel avant l'envoi** (`rappel`) : le formulaire l'allume au premier
 * « Envoyer la proposition » quand une ressemblance forte reste affichée, et fait
 * défiler jusqu'ici. « Envoyer quand même » envoie ; le rappel ne revient pas.
 */
import { ref } from 'vue'
import type { RessemblanceDansLArbre, RessemblanceProposition, RessemblancePropositionComplete } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import CompletionDeFiche from './CompletionDeFiche.vue'
import type { PhotoAJoindre } from './PhotosAJoindre.vue'
import type { SaisieCreation } from './creation'
import type { PersonneDeposee } from './depot'
import {
  complementDepuisRessemblance,
  completionDepuisRessemblance,
  type ComplementChoisi,
  type DemandeDeCompletion,
} from './fusion'
import { cleDeRessemblance, estDuLecteur, estForte } from './ressemblances'

const {
  arbre,
  propositions,
  contexte,
  ouverte = null,
  rappel = false,
  saisie,
  message,
  photos,
  mesDemandes = [],
} = defineProps<{
  arbre: RessemblanceDansLArbre[]
  propositions: RessemblanceProposition[]
  /** `page` : les liens s'ouvrent dans un nouvel onglet ; `volet` : l'arbre est à côté. */
  contexte: 'page' | 'volet'
  /** La personne dont la complétion est dépliée, ou `null`. */
  ouverte?: number | null
  /** « Vérifiez d'abord ces personnes » : le premier envoi a été retenu. */
  rappel?: boolean
  saisie: SaisieCreation
  message: string
  photos: PhotoAJoindre[]
  /** Les demandes en attente du lecteur, pour dire « votre proposition ». */
  mesDemandes?: number[]
}>()

const emit = defineEmits<{
  /** « Ce n'est pas elle » : la clé de la carte (`cleDeRessemblance`). */
  ecarter: [cle: string]
  ouvrir: [personId: number]
  fermer: []
  /** Volet : montrer la personne dans l'arbre ; `repondre(false)` si elle n'y est pas dessinée. */
  'voir-personne': [personId: number, repondre: (dessinee: boolean) => void]
  /** Volet : choisir le nœud de la proposition dans l'arbre, même réponse. */
  'voir-proposition': [noeudId: string, repondre: (dessinee: boolean) => void]
  'envoyer-quand-meme': []
  deposee: [depot: PersonneDeposee]
  /** « Compléter cette proposition » : le formulaire la complète sur place. */
  completer: [cible: DemandeDeCompletion]
  /** « Ajouter mes informations à cette proposition » : le dépôt devient un complément. */
  complement: [choisi: ComplementChoisi]
}>()

/** Les cartes dont l'arbre a répondu « pas dessinée ». */
const nonDessinees = ref<string[]>([])

function marquer(cle: string, dessinee: boolean): void {
  nonDessinees.value = dessinee ? nonDessinees.value.filter((c) => c !== cle) : [...new Set([...nonDessinees.value, cle])]
}

function voirPersonne(r: RessemblanceDansLArbre): void {
  const cle = cleDeRessemblance(r)
  emit('voir-personne', r.personId, (dessinee) => marquer(cle, dessinee))
}

function voirProposition(r: RessemblancePropositionComplete): void {
  if (r.noeudId === null) return
  const cle = cleDeRessemblance(r)
  emit('voir-proposition', r.noeudId, (dessinee) => marquer(cle, dessinee))
}

function ecarter(r: RessemblanceDansLArbre | RessemblanceProposition): void {
  if (!('forme' in r) && ouverte === r.personId) emit('fermer')
  emit('ecarter', cleDeRessemblance(r))
}

const LIEN_SECONDAIRE =
  'inline-flex items-center justify-center gap-1 rounded-champ border border-ligne bg-panneau px-3.5 py-1.5 text-sm font-semibold text-texte transition-colors hover:border-accent'
</script>

<template>
  <PanneauBase data-ressemblances>
    <template #entete>
      <h2 class="text-sm font-semibold tracking-tight">Est-ce déjà quelqu’un de connu ?</h2>
      <p class="mt-0.5 text-xs text-attenue">
        Ces fiches ressemblent à votre saisie. Si c’est l’une d’elles, complétez-la plutôt que d’en
        créer une seconde.
      </p>
    </template>

    <div class="flex flex-col gap-3">
      <div
        v-if="rappel"
        class="flex flex-wrap items-center gap-2 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
        role="alert"
        data-rappel
      >
        <span class="mr-auto font-semibold">Vérifiez d’abord ces personnes.</span>
        <BoutonBase variante="secondaire" @click="emit('envoyer-quand-meme')">Envoyer quand même</BoutonBase>
      </div>

      <!-- Dans l'arbre -->
      <section v-if="arbre.length > 0" class="flex flex-col gap-2" aria-label="Dans l’arbre">
        <h3 class="m-0 text-xs font-semibold tracking-wide text-attenue uppercase">Dans l’arbre</h3>
        <ul class="m-0 flex list-none flex-col gap-2.5 p-0">
          <li
            v-for="r in arbre"
            :key="r.personId"
            class="flex flex-col gap-2 rounded-champ border bg-fond px-3 py-2.5"
            :class="estForte(r) ? 'border-accent' : 'border-ligne'"
            :data-ressemblance="cleDeRessemblance(r)"
            :data-forte="estForte(r)"
          >
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
              <span class="font-semibold">{{ r.displayName }}</span>
              <span v-if="r.ligneDates" class="font-mono text-xs text-attenue">{{ r.ligneDates }}</span>
            </div>
            <p v-if="r.lieuNaissance !== null" class="m-0 text-xs text-attenue">Lieu de naissance : {{ r.lieuNaissance }}</p>
            <ul class="m-0 flex list-none flex-wrap gap-1 p-0" aria-label="Ce qui ressemble">
              <li
                v-for="raison in r.raisons"
                :key="raison"
                class="rounded-pastille border border-ligne bg-panneau px-2 py-0.5 text-[11.5px]"
                data-raison
              >
                {{ raison }}
              </li>
            </ul>
            <div class="flex flex-wrap items-center gap-2">
              <BoutonBase v-if="ouverte !== r.personId" @click="emit('ouvrir', r.personId)">
                C’est elle : compléter sa fiche
              </BoutonBase>
              <RouterLink
                v-if="contexte === 'page'"
                :to="{ name: 'personne.detail', params: { id: r.personId } }"
                target="_blank"
                rel="noopener"
                :class="LIEN_SECONDAIRE"
              >
                Voir<span class="sr-only"> sa fiche, dans un nouvel onglet</span>
              </RouterLink>
              <BoutonBase v-else variante="secondaire" @click="voirPersonne(r)">Voir dans l’arbre</BoutonBase>
              <BoutonBase variante="discret" @click="ecarter(r)">Ce n’est pas elle</BoutonBase>
            </div>
            <p v-if="nonDessinees.includes(cleDeRessemblance(r))" class="m-0 text-sm text-attenue" role="status">
              Elle n’est pas dessinée dans l’arbre avec vos réglages.
              <RouterLink
                :to="{ name: 'personne.detail', params: { id: r.personId } }"
                target="_blank"
                rel="noopener"
                class="font-semibold text-accent underline"
              >
                Ouvrir sa fiche dans un nouvel onglet
              </RouterLink>
            </p>
            <CompletionDeFiche
              v-if="ouverte === r.personId"
              :person-id="r.personId"
              :libelle="r.displayName"
              :saisie="saisie"
              :message="message"
              :photos="photos"
              @fermer="emit('fermer')"
              @deposee="(depot) => emit('deposee', depot)"
            />
          </li>
        </ul>
      </section>

      <!-- Déjà proposées -->
      <section v-if="propositions.length > 0" class="flex flex-col gap-2" aria-label="Déjà proposées">
        <h3 class="m-0 text-xs font-semibold tracking-wide text-attenue uppercase">Déjà proposées</h3>
        <ul class="m-0 flex list-none flex-col gap-2.5 p-0">
          <li
            v-for="r in propositions"
            :key="cleDeRessemblance(r)"
            class="flex flex-col gap-2 rounded-champ border bg-fond px-3 py-2.5"
            :class="estForte(r) ? 'border-accent' : 'border-ligne'"
            :data-ressemblance="cleDeRessemblance(r)"
            :data-forme="r.forme"
            :data-forte="estForte(r)"
          >
            <!-- Une proposition d'un vivant faite par un autre membre : la phrase,
                 et rien que le serveur n'ait pas déjà rendu générique. -->
            <template v-if="r.forme === 'discrete'">
              <p class="m-0 text-sm font-semibold">Une proposition semblable attend déjà la validation.</p>
              <p class="m-0 text-sm">
                Si c’est elle, ajoutez-y vos informations : l’administrateur les fusionnera.
              </p>
            </template>
            <template v-else>
              <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span class="font-semibold">{{ r.displayName }}</span>
                <span v-if="r.ligneDates" class="font-mono text-xs text-attenue">{{ r.ligneDates }}</span>
                <PastilleEtat v-if="r.statut === 'en_attente'" etat="en_attente" />
                <PastilleEtat v-else etat="neutre" libelle="Brouillon non envoyé" />
              </div>
              <p v-if="r.lieuNaissance !== null" class="m-0 text-xs text-attenue">Lieu de naissance : {{ r.lieuNaissance }}</p>
              <p class="m-0 text-xs text-attenue">
                {{ estDuLecteur(r, mesDemandes) ? 'Votre proposition' : `Proposée par ${r.authorLabel}` }}
              </p>
            </template>

            <ul class="m-0 flex list-none flex-wrap gap-1 p-0" aria-label="Ce qui ressemble">
              <li
                v-for="raison in r.raisons"
                :key="raison"
                class="rounded-pastille border border-ligne bg-panneau px-2 py-0.5 text-[11.5px]"
                data-raison
              >
                {{ raison }}
              </li>
            </ul>

            <p v-if="r.forme === 'complete'" class="m-0 text-sm">
              {{
                r.action === 'complement'
                  ? 'Une proposition semblable est déjà en attente : si c’est elle, ajoutez-y vos informations, l’administrateur les fusionnera.'
                  : r.statut === 'brouillon'
                    ? 'Vous avez déjà une proposition semblable en brouillon : complétez-la plutôt que d’en commencer une seconde.'
                    : 'Une proposition semblable est déjà en attente : complétez-la plutôt que d’en envoyer une seconde.'
              }}
            </p>

            <div class="flex flex-wrap items-center gap-2">
              <!-- Le geste suit l'`action` du serveur : compléter sur place (l'auteur,
                   l'administrateur), ou déposer un complément (tous les autres). -->
              <BoutonBase
                v-if="r.forme === 'complete' && r.action === 'completer'"
                data-completer
                @click="emit('completer', completionDepuisRessemblance(r))"
              >
                Compléter cette proposition
              </BoutonBase>
              <BoutonBase v-else-if="r.action === 'complement'" data-complement @click="emit('complement', complementDepuisRessemblance(r))">
                Ajouter mes informations à cette proposition
              </BoutonBase>
              <!-- Seuls l'auteur et l'administrateur lisent une demande ; c'est
                   aussi à eux que le serveur offre `completer`. -->
              <RouterLink
                v-if="r.forme === 'complete' && r.action === 'completer'"
                :to="{ name: 'demande.detail', params: { id: r.demandeId } }"
                target="_blank"
                rel="noopener"
                :class="LIEN_SECONDAIRE"
              >
                Voir la demande n° {{ r.demandeId }}<span class="sr-only">, dans un nouvel onglet</span>
              </RouterLink>
              <BoutonBase
                v-if="r.forme === 'complete' && contexte === 'volet' && r.noeudId !== null"
                variante="secondaire"
                @click="voirProposition(r)"
              >
                Voir dans l’arbre
              </BoutonBase>
              <BoutonBase variante="discret" @click="ecarter(r)">Ce n’est pas elle</BoutonBase>
            </div>
            <p v-if="nonDessinees.includes(cleDeRessemblance(r))" class="m-0 text-sm text-attenue" role="status">
              Elle n’est pas dessinée dans l’arbre avec vos réglages.
            </p>
          </li>
        </ul>
      </section>
    </div>
  </PanneauBase>
</template>
