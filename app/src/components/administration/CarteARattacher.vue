<script setup lang="ts">
/**
 * Une carte « À rattacher » : un compte inscrit **sans s'être trouvé** dans
 * l'arbre, et les trois décisions possibles (ADR 0013).
 *
 * Ce que la carte montre : le courriel, la date d'inscription, l'identité
 * déclarée (prénoms, nom, date de naissance exacte), la description de la
 * famille telle qu'elle a été écrite, la photo s'il y en a une, et la
 * suggestion de l'analyse automatique.
 *
 * **Et le message vocal**, s'il y en a un (2 octobre 2026) : la personne a pu
 * dire sa famille au lieu de l'écrire, ou en plus. Un `<audio controls>` le
 * joue depuis une route `ROLE_ADMIN` ; le texte et la voix coexistent, et l'un
 * peut manquer. L'analyse automatique ne l'entend pas — elle sait seulement
 * qu'il existe : c'est à l'administrateur de l'écouter.
 *
 * **La description parle de vivants.** Elle n'est lue qu'ici, sous
 * `/api/administration`, et elle n'est écrite dans aucun stockage du
 * navigateur : elle vit dans la charge, le temps que la carte est ouverte. Le
 * serveur la purge au rattachement comme au refus.
 *
 * Les trois décisions :
 *
 *  1. **Rattacher à une fiche** — celle de la suggestion, une autre piste de
 *     l'analyse, ou n'importe quelle fiche choisie au sélecteur de personne.
 *     `activerMembre(id, {personId})` : le compte passe `actif` et **obtient un
 *     cercle**, la photo en attente devient le portrait de la fiche.
 *  2. **Créer la fiche et rattacher** — une seule transaction côté serveur. Le
 *     corps de la demande est construit par le serveur ; la carte n'envoie que
 *     les parents choisis, **obligatoires** quand l'analyse n'a pas trouvé de
 *     lien recevable (`exigencesDeCreation`).
 *  3. **Refuser l'inscription** — après confirmation : le compte est suspendu,
 *     la déclaration et la photo effacées.
 *
 * Après une décision réussie, la carte **disparaît** (`traite`) : il n'y a plus
 * rien à décider, et la laisser affichée inviterait à décider deux fois.
 *
 * Toutes les écritures sont éteintes sous la bascule « voir comme »
 * (`ecrituresSuspendues`), comme sur les autres écrans de l'administration.
 */
import { computed, ref } from 'vue'
import {
  activerMembre,
  creerFicheEtRattacher,
  refuserInscription,
  type CompteARattacher,
  type MessageVocalARattacher,
} from '@/lib/api'
import { formaterChrono, tailleLisible } from '@/components/inscription/messageVocal'
import BoutonBase from '@/components/base/BoutonBase.vue'
import DialogueConfirmation, { type DemandeDeConfirmation } from '@/components/base/DialogueConfirmation.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SelecteurPersonne from '@/components/proposition/SelecteurPersonne.vue'
import SuggestionRattachement from './SuggestionRattachement.vue'
import {
  PARENTS_MAXIMUM,
  ajouterParent,
  creationPossible,
  dateDeclaree,
  exigencesDeCreation,
  identiteDeclaree,
  initialesDeclarees,
  type EtatDAnalyse,
  type ParentChoisi,
} from './rattachements'

const { compte, analyse, suspendu = false, infobulle } = defineProps<{
  compte: CompteARattacher
  analyse: EtatDAnalyse
  /** Écritures éteintes (bascule « voir comme »). */
  suspendu?: boolean
  infobulle?: string | undefined
}>()

const emit = defineEmits<{
  /** Une décision a abouti : la carte n'a plus lieu d'être. */
  traite: [memberId: number]
  relancer: [memberId: number]
}>()

/** Le panneau ouvert sous la carte : aucun, choisir une fiche, ou créer. */
const panneau = ref<'aucun' | 'autre_fiche' | 'creation'>('aucun')
const parents = ref<ParentChoisi[]>([])
const enCours = ref(false)
const erreur = ref<unknown>(null)
const confirmation = ref<DemandeDeConfirmation | null>(null)

const suggestion = computed(() => (analyse.etat === 'prete' ? analyse.suggestion : null))
const exigences = computed(() => exigencesDeCreation(suggestion.value))
const creationPrete = computed(() => creationPossible(suggestion.value, parents.value))
const identite = computed(() => identiteDeclaree(compte))

/** Enveloppe commune : un seul geste à la fois, erreur remontée telle quelle. */
async function agir(geste: () => Promise<unknown>): Promise<void> {
  if (suspendu || enCours.value) return
  enCours.value = true
  erreur.value = null
  try {
    await geste()
    emit('traite', compte.memberId)
  } catch (cause) {
    erreur.value = cause
  } finally {
    enCours.value = false
  }
}

function rattacher(personId: number): void {
  void agir(() => activerMembre(compte.memberId, { personId }))
}

/**
 * Le sélecteur rend un `ChoixDePersonne` ; avec `avecPropositions: false`, ce
 * n'est jamais une fiche proposée — mais on le vérifie plutôt que de le croire :
 * rattacher un compte à une fiche en attente n'a pas de sens.
 */
function personneDuChoix(choix: { libelle: string }): ParentChoisi | null {
  return 'personId' in choix && typeof choix.personId === 'number'
    ? { personId: choix.personId, libelle: choix.libelle }
    : null
}

function choisirAutreFiche(choix: { libelle: string }): void {
  const personne = personneDuChoix(choix)
  if (personne !== null) rattacher(personne.personId)
}

function choisirParent(choix: { libelle: string }): void {
  const personne = personneDuChoix(choix)
  if (personne !== null) parents.value = ajouterParent(parents.value, personne)
}

function retirerParent(personId: number): void {
  parents.value = parents.value.filter((parent) => parent.personId !== personId)
}

function creer(): void {
  if (!creationPrete.value) return
  void agir(() =>
    creerFicheEtRattacher(compte.memberId, { parents: parents.value.map((parent) => parent.personId) }),
  )
}

function demanderRefus(): void {
  confirmation.value = {
    titre: 'Refuser cette inscription ?',
    message:
      `Le compte ${compte.email} ne pourra plus se connecter ; sa description, son message vocal, son identité ` +
      'et sa photo seront effacées.',
    confirmer: 'Refuser l’inscription',
    danger: true,
  }
}

async function refuser(): Promise<void> {
  await agir(() => refuserInscription(compte.memberId))
  confirmation.value = null
}

function basculer(cible: 'autre_fiche' | 'creation'): void {
  panneau.value = panneau.value === cible ? 'aucun' : cible
}

/** « — 2:14, 540 ko » : la durée annoncée s'il y en a une, et la taille. */
function descriptionDuVocal(vocal: MessageVocalARattacher): string {
  const morceaux = [vocal.duree !== null ? formaterChrono(vocal.duree) : null, tailleLisible(vocal.taille)]
  return `— ${morceaux.filter((m) => m !== null).join(', ')}`
}

function dateLisible(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime())
    ? iso
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <article
    class="flex flex-col gap-3 rounded-panneau border border-ligne bg-panneau p-4"
    :data-compte="compte.memberId"
    :aria-label="`Inscription de ${identite}`"
  >
    <!-- ── L'en-tête : photo, identité déclarée, courriel ─────────────── -->
    <header class="flex items-start gap-3">
      <a
        v-if="compte.photo !== null"
        :href="compte.photo.url"
        target="_blank"
        rel="noopener"
        class="shrink-0"
        title="Voir la photo en grand"
      >
        <img
          :src="compte.photo.vignetteUrl"
          :alt="`Photo déposée par ${identite}`"
          class="size-16 rounded-full border border-ligne object-cover"
        />
      </a>
      <span
        v-else
        class="flex size-16 shrink-0 items-center justify-center rounded-full border border-ligne bg-fond text-lg font-semibold text-attenue"
        aria-hidden="true"
        data-initiales
      >
        {{ initialesDeclarees(compte) }}
      </span>

      <div class="min-w-0">
        <h3 class="text-base font-semibold">{{ identite }}</h3>
        <p class="text-sm">Née ou né le {{ dateDeclaree(compte.declaration.birthDate) }}</p>
        <p class="mt-0.5 text-sm break-all text-attenue">
          {{ compte.email }} — inscrit le {{ dateLisible(compte.inscritLe) }}
        </p>
        <p v-if="compte.photo === null" class="mt-0.5 text-xs text-attenue">Pas de photo déposée.</p>
        <p v-if="compte.invitationPersonLabel !== null" class="mt-0.5 text-xs text-attenue">
          Le lien utilisé désignait {{ compte.invitationPersonLabel }}.
        </p>
      </div>
    </header>

    <!-- ── La description, telle qu'elle a été écrite ────────────────── -->
    <blockquote
      v-if="compte.declaration.description !== null && compte.declaration.description !== ''"
      class="rounded-champ border-l-2 border-accent bg-fond px-3 py-2 text-sm whitespace-pre-line"
      data-description
    >
      {{ compte.declaration.description }}
    </blockquote>

    <!-- ── Et telle qu'elle a été dite ────────────────────────────────── -->
    <!-- Le son vient d'une route `ROLE_ADMIN` qui sert les requêtes
         partielles : `preload="none"`, rien n'est téléchargé avant qu'on
         appuie sur lecture — c'est une voix de vivant. -->
    <section
      v-if="compte.messageVocal !== null"
      class="flex flex-col gap-1.5 rounded-champ border-l-2 border-accent bg-fond px-3 py-2"
      data-message-vocal
    >
      <p class="text-sm font-medium">
        Message vocal
        <span class="tnum font-normal text-attenue">{{ descriptionDuVocal(compte.messageVocal) }}</span>
      </p>
      <audio
        :src="compte.messageVocal.url"
        controls
        preload="none"
        class="w-full"
        :aria-label="`Message vocal de ${identite}`"
      />
    </section>

    <SuggestionRattachement
      :analyse="analyse"
      :suspendu="suspendu"
      :occupe="enCours"
      @relancer="emit('relancer', compte.memberId)"
      @rattacher="(personId) => rattacher(personId)"
    />

    <MessageErreur v-if="erreur !== null" :erreur="erreur" />

    <!-- ── Les décisions ──────────────────────────────────────────────── -->
    <div class="flex flex-wrap gap-2">
      <BoutonBase
        variante="secondaire"
        :desactive="suspendu || enCours"
        :title="infobulle"
        :aria-expanded="panneau === 'autre_fiche'"
        @click="basculer('autre_fiche')"
      >
        Choisir une autre fiche
      </BoutonBase>
      <BoutonBase
        variante="secondaire"
        :desactive="suspendu || enCours"
        :title="infobulle"
        :aria-expanded="panneau === 'creation'"
        @click="basculer('creation')"
      >
        Créer la fiche et rattacher
      </BoutonBase>
      <BoutonBase variante="danger" :desactive="suspendu || enCours" :title="infobulle" @click="demanderRefus">
        Refuser l’inscription
      </BoutonBase>
    </div>

    <!-- Choisir une fiche quelconque de l'arbre. Seules les fiches validées :
         un compte ne se rattache pas à une fiche proposée. -->
    <div v-if="panneau === 'autre_fiche'" class="rounded-champ border border-ligne p-3">
      <SelecteurPersonne
        etiquette="Rattacher ce compte à…"
        aide="Le choix rattache et active le compte aussitôt."
        :avec-propositions="false"
        @choisir="choisirAutreFiche"
      />
    </div>

    <!-- Créer la fiche : aperçu de la création proposée, puis les parents. -->
    <div v-if="panneau === 'creation'" class="flex flex-col gap-3 rounded-champ border border-ligne p-3 text-sm">
      <template v-if="suggestion !== null && suggestion.creation !== null && suggestion.creation.changements.length > 0">
        <p class="text-xs font-semibold text-attenue">Ce qui sera créé</p>
        <ul class="flex flex-col divide-y divide-ligne rounded-champ border border-ligne">
          <li
            v-for="(changement, index) in suggestion.creation.changements"
            :key="index"
            class="grid gap-x-3 gap-y-0.5 px-3 py-1.5 md:grid-cols-[minmax(0,11rem)_minmax(0,1fr)]"
          >
            <span class="text-attenue">{{ changement.libelle }}</span>
            <span class="font-medium break-words">{{ changement.apres ?? '—' }}</span>
          </li>
        </ul>
      </template>
      <p v-else class="text-attenue">
        La fiche sera créée avec l’identité déclarée : {{ identite }}, né·e le
        {{ dateDeclaree(compte.declaration.birthDate) }}, vivant·e.
      </p>

      <p v-if="exigences.motif !== null" class="text-accent" role="status" data-parents-requis>
        {{ exigences.motif }}
      </p>

      <ul v-if="parents.length > 0" class="flex flex-col gap-1">
        <li
          v-for="parent in parents"
          :key="parent.personId"
          class="flex items-center justify-between gap-2 rounded-champ border border-ligne px-2.5 py-1.5"
        >
          <span>Enfant de <span class="font-medium">{{ parent.libelle }}</span></span>
          <BoutonBase variante="discret" :desactive="enCours" @click="retirerParent(parent.personId)">
            Retirer
          </BoutonBase>
        </li>
      </ul>

      <SelecteurPersonne
        v-if="parents.length < PARENTS_MAXIMUM"
        :etiquette="exigences.parentsRequis ? 'Parent (au moins un)' : 'Parents (facultatif)'"
        :aide="
          exigences.parentsRequis
            ? 'Un ou deux parents déjà dans l’arbre, pour placer la nouvelle fiche.'
            : 'Ils remplacent ceux que l’analyse propose.'
        "
        :deja-choisis="parents.map((parent) => `personne:${parent.personId}`)"
        :avec-propositions="false"
        @choisir="choisirParent"
      />

      <div>
        <BoutonBase :chargement="enCours" :desactive="suspendu || !creationPrete" :title="infobulle" @click="creer">
          Créer la fiche et rattacher
        </BoutonBase>
      </div>
    </div>

    <DialogueConfirmation
      :demande="confirmation"
      :chargement="enCours"
      @confirmer="refuser"
      @annuler="confirmation = null"
    />
  </article>
</template>
