<script setup lang="ts">
/**
 * Valider ou refuser une demande — l'écran où l'administration écrit.
 *
 * Quatre règles y sont tenues, et aucune n'est cosmétique.
 *
 * **1. Le motif de refus est obligatoire.** Exigence explicite du commanditaire.
 * L'auteur le reçoit en notification, et la demande refusée lui reste
 * consultable avec son motif — c'est ce qui fait la différence entre un refus et
 * une contribution perdue. La garde `peutRefuser()` passe **avant** l'appel ;
 * `decision.test.ts` vérifie cet ordre dans le fichier, faute de pouvoir monter
 * un navigateur.
 *
 * **2. Les deux `409` ne sont pas retraitées ici.** `verrou_concurrent` dit
 * « réessayer », `demande_caduque` dit le contraire — et c'est `erreurs.ts` qui
 * le sait. Cet écran passe l'erreur à `<MessageErreur>` et se contente de
 * traduire la **conduite** rendue en geste : rejouer l'appel, ou recharger. Il
 * ne teste aucun code, ce qui est vérifié aussi : le jour où le contrat en
 * ajoute un troisième, il n'y a qu'un fichier à toucher.
 *
 * **3. Le rejeu automatique s'annonce.** `resultat: "rejouee"` est un succès,
 * mais c'est une écriture qu'aucun humain n'a réexaminée. « Validée » tout court
 * effacerait ce que le journal, lui, conserve.
 *
 * **4. Rien ne s'écrit sous une vue empruntée.** Le serveur rend
 * `409 vue_comme_membre_active` sur toute écriture tant que la bascule est
 * active ; les boutons sont éteints **et** la raison est écrite, parce qu'un
 * bouton éteint sans explication se lit comme une panne.
 *
 * Le bouton « Valider » suit `resolutionPrevue` (§ 6.7) : sans ce calcul,
 * l'administrateur découvrirait la caducité en cliquant.
 *
 * **« Valider & suivant » et « Refuser & suivant »** font exactement le même
 * appel — même garde, même motif obligatoire — puis demandent au parent
 * d'ouvrir la demande suivante de la file (`decidee` porte alors l'issue). Ce
 * n'est pas un second chemin : `valider()` et `refuser()` restent les seuls
 * appels, un drapeau dit seulement ce qu'on fait après.
 */
import { computed, nextTick, ref } from 'vue'
import {
  analyserErreur,
  invaliderGraphe,
  refuserDemande,
  validerDemande,
  type ConduiteATenir,
  type DemandeAdministration,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import {
  actionValidation,
  annonceValidation,
  erreurMotifRefus,
  issueDeValidation,
  peutRefuser,
  type IssueDecision,
} from './decision'
import { ecrituresSuspendues, infobulleSuspension, motifSuspension } from './ecriture'

const { demande } = defineProps<{ demande: DemandeAdministration }>()

const emit = defineEmits<{
  /**
   * La demande a changé d'état. Sans argument, le parent la recharge ; avec une
   * issue, l'administrateur a demandé « & suivant » et le parent ouvre la
   * demande suivante de la file.
   */
  decidee: [enchainement?: IssueDecision]
}>()

const { vueEmpruntee } = useSession()

const envoi = ref(false)
const erreur = ref<unknown>(null)
const annonce = ref<ReturnType<typeof annonceValidation> | null>(null)
const enRefus = ref(false)
const motif = ref('')
const doublonDeId = ref<number | null>(null)
const aTenteRefus = ref(false)
/** Le refus en cours enchaînera-t-il sur la demande suivante ? */
const refusEnchaine = ref(false)

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))

const validation = computed(() => actionValidation(demande.resolutionPrevue))
const decidable = computed(() => demande.status === 'en_attente')
const doublons = computed(() => demande.doublonsProbables ?? [])

/** La barre fixée en bas porte les quatre décisions tant qu'aucun refus n'est en cours. */
const barreVisible = computed(() => decidable.value && !enRefus.value)
const panneauUtile = computed(
  () =>
    !barreVisible.value ||
    raisonSuspension.value !== null ||
    validation.value.explication !== null ||
    annonce.value !== null ||
    erreur.value !== null,
)

/** Le message du serveur l'emporte ; le nôtre ne parle qu'avant l'envoi. */
const messageMotif = computed(() => {
  const duServeur = erreur.value === null ? '' : (analyserErreur(erreur.value).champs['message'] ?? '')
  if (duServeur.length > 0) return duServeur
  return aTenteRefus.value ? (erreurMotifRefus(motif.value) ?? '') : ''
})

async function valider(enchainer = false): Promise<void> {
  if (suspendu.value || envoi.value || validation.value.desactive) return

  envoi.value = true
  erreur.value = null
  try {
    const resultat = await validerDemande(demande.id)
    annonce.value = annonceValidation(resultat)
    // L'arbre a changé : l'`ETag` du graphe est périmé (§ 5.2).
    invaliderGraphe()
    if (enchainer) emit('decidee', issueDeValidation(resultat))
    else emit('decidee')
  } catch (cause) {
    erreur.value = cause
  } finally {
    envoi.value = false
  }
}

function ouvrirRefus(enchainer: boolean): void {
  refusEnchaine.value = enchainer
  enRefus.value = true
  // La barre est en bas, le formulaire dans la page : on y amène le regard.
  void nextTick(() => {
    const champ = document.getElementById('motif-refus')
    champ?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    champ?.focus({ preventScroll: true })
  })
}

async function refuser(): Promise<void> {
  aTenteRefus.value = true
  if (suspendu.value || envoi.value) return
  // La garde, avant l'appel. Un refus sans motif ne doit pas atteindre le réseau.
  if (!peutRefuser(motif.value)) return

  envoi.value = true
  erreur.value = null
  try {
    await refuserDemande(demande.id, {
      message: motif.value.trim(),
      doublonDeId: doublonDeId.value ?? undefined,
    })
    enRefus.value = false
    motif.value = ''
    if (refusEnchaine.value) emit('decidee', 'refusee')
    else emit('decidee')
  } catch (cause) {
    erreur.value = cause
  } finally {
    envoi.value = false
  }
}

/**
 * La conduite rendue par le socle, traduite en geste d'écran.
 *
 * On ne relit aucun code d'erreur : `reessayer` veut dire que rien n'a été
 * écrit et que le même appel peut repartir ; tout le reste veut dire que l'état
 * affiché est périmé, et la seule chose sensée est alors de relire la demande.
 */
function suivreConduite(conduite: ConduiteATenir): void {
  if (conduite === 'reessayer') void valider()
  else emit('decidee')
}
</script>

<template>
  <!-- Le panneau ne s'affiche que s'il a quelque chose à dire : quand la barre
       porte les décisions, un panneau vide ne serait qu'un trou dans la page. -->
  <PanneauBase v-if="panneauUtile" titre="Décision">
    <!-- La bascule suspend toute écriture. On l'écrit ici, à côté des boutons
         éteints : le bandeau permanent dit que la vue est empruntée, celui-ci
         dit ce que ça empêche, là où la question se pose. -->
    <p
      v-if="raisonSuspension !== null"
      class="mb-3 rounded-champ border border-accent bg-accent-doux px-3 py-2 text-sm text-accent"
      role="status"
    >
      {{ raisonSuspension }}
    </p>

    <!-- Ce que la validation fera au clic, dit avant le clic. -->
    <p v-if="decidable && validation.explication !== null" class="mb-3 text-sm text-attenue">
      {{ validation.explication }}
    </p>

    <p v-if="!decidable" class="text-sm text-attenue">
      Cette demande a déjà été traitée.
    </p>


    <!-- ------------------------------------------------------------------ -->
    <!-- Le formulaire de refus. Le motif y est le sujet, pas une option.    -->
    <!-- ------------------------------------------------------------------ -->
    <form v-else-if="enRefus" novalidate @submit.prevent="refuser">
      <div class="flex flex-col gap-1">
        <label for="motif-refus" class="text-sm font-medium">
          Motif du refus
          <span class="text-alerte" aria-hidden="true">*</span>
          <span class="lecteur-ecran">(obligatoire)</span>
        </label>
        <p id="motif-refus-aide" class="text-xs text-attenue">
          Envoyé à l’auteur : c’est tout ce qu’il recevra.
        </p>
        <textarea
          id="motif-refus"
          v-model="motif"
          rows="3"
          required
          aria-describedby="motif-refus-aide"
          :aria-invalid="messageMotif.length > 0 ? 'true' : undefined"
          class="rounded-champ border bg-fond px-2.5 py-1.5 text-sm text-texte"
          :class="messageMotif.length > 0 ? 'border-alerte' : 'border-ligne'"
        />
        <p v-if="messageMotif.length > 0" class="text-xs text-alerte">{{ messageMotif }}</p>
      </div>

      <!-- Le rapprochement de doublons : refuser en renvoyant à l'autre
           demande, plutôt que de refuser sec. L'auteur lit « votre proposition
           rejoint la demande n° 11 ». -->
      <div v-if="doublons.length > 0" class="mt-3 flex flex-col gap-1">
        <label for="doublon-de" class="text-sm font-medium">
          Cette demande fait doublon avec (facultatif)
        </label>
        <select
          id="doublon-de"
          v-model="doublonDeId"
          class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
        >
          <option :value="null">Aucune</option>
          <option v-for="doublon in doublons" :key="doublon.id" :value="doublon.id">
            n° {{ doublon.id }} — {{ doublon.libelle }} ({{ doublon.authorLabel }})
          </option>
        </select>
      </div>

      <!-- Deux boutons d'envoi pour un seul `refuser()` : le clic pose le
           drapeau d'enchaînement, puis soumet le formulaire — la garde du
           motif s'applique aux deux. -->
      <div class="mt-4 flex flex-wrap gap-2">
        <BoutonBase
          type="submit"
          variante="danger"
          :chargement="envoi && !refusEnchaine"
          :desactive="suspendu || (envoi && refusEnchaine)"
          :title="infobulle"
          @click="refusEnchaine = false"
        >
          Envoyer le refus
        </BoutonBase>
        <BoutonBase
          type="submit"
          variante="danger"
          :chargement="envoi && refusEnchaine"
          :desactive="suspendu || (envoi && !refusEnchaine)"
          :title="infobulle"
          @click="refusEnchaine = true"
        >
          Refuser &amp; suivant
        </BoutonBase>
        <BoutonBase variante="secondaire" @click="enRefus = false">Annuler</BoutonBase>
      </div>
    </form>

    <!-- ------------------------------------------------------------------ -->
    <!-- Après coup                                                          -->
    <!-- ------------------------------------------------------------------ -->
    <div
      v-if="annonce !== null"
      class="mt-4 rounded-champ border px-3 py-2.5 text-sm"
      :class="annonce.rejoue ? 'border-accent bg-accent-doux' : 'border-ok-bord bg-ok-doux'"
      role="status"
    >
      <p class="font-semibold">{{ annonce.titre }}</p>
      <p v-if="annonce.detail !== null" class="mt-1">{{ annonce.detail }}</p>
    </div>

    <MessageErreur v-if="erreur !== null" class="mt-4" :erreur="erreur" @action="suivreConduite" />
  </PanneauBase>

  <!-- Réserve la hauteur de la barre sous la page : sans elle, la fin de la page
       (le panneau « Décision » compris) passerait dessous. -->
  <div v-if="barreVisible" class="h-24" aria-hidden="true" />

    <!-- La barre de décision, fixée en bas : quatre icônes, dans l'ordre
         « oui », « oui & suivant », « non », « non & suivant ». Le libellé
         vit dans `title` (survol) et `aria-label` (lecteur d'écran). -->
    <div
      role="toolbar"
      aria-label="Décision"
      class="fixed inset-x-0 bottom-[var(--hauteur-barre-bas,0px)] z-30 flex justify-center gap-3 border-t border-ligne bg-panneau px-4 pt-3 pb-[max(12px,calc(env(safe-area-inset-bottom)_-_var(--hauteur-barre-bas,0px)))] shadow-panneau"
    >
      <button
        type="button"
        class="bouton-decision border-accent bg-accent text-fond"
        :disabled="suspendu || envoi || validation.desactive"
        :aria-busy="envoi"
        :title="infobulle ?? validation.libelle"
        :aria-label="validation.libelle"
        @click="valider()"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
      </button>

      <!-- Même appel, puis la demande suivante de la file. Éteint dans les
           mêmes cas que « Valider » : enchaîner ne contourne rien. -->
      <button
        type="button"
        class="bouton-decision border-accent bg-panneau text-accent"
        :disabled="suspendu || envoi || validation.desactive"
        :title="infobulle ?? 'Valider & suivant'"
        aria-label="Valider & suivant"
        @click="valider(true)"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12.5l3.5 3.5L13 9" /><path d="M14 7l5 5-5 5" /></svg>
      </button>

      <button
        type="button"
        class="bouton-decision border-alerte-bord bg-alerte-doux text-alerte"
        :disabled="suspendu || envoi"
        :title="infobulle ?? 'Refuser'"
        aria-label="Refuser"
        @click="ouvrirRefus(false)"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
      </button>

      <!-- Ouvre le même formulaire : le motif reste obligatoire. -->
      <button
        type="button"
        class="bouton-decision border-alerte-bord bg-panneau text-alerte"
        :disabled="suspendu || envoi"
        :title="infobulle ?? 'Refuser & suivant'"
        aria-label="Refuser & suivant"
        @click="ouvrirRefus(true)"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8l7 7M10 8l-7 7" /><path d="M14 7l5 5-5 5" /></svg>
      </button>
    </div>
</template>

<style scoped>
.bouton-decision {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  border-width: 1px;
  border-radius: 9999px;
  transition: opacity 0.15s, transform 0.1s;
}
.bouton-decision:hover:not(:disabled) {
  transform: translateY(-1px);
}
.bouton-decision:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
.bouton-decision[aria-busy='true'] svg {
  animation: pulse 1s ease-in-out infinite;
}
.bouton-decision svg {
  width: 1.5rem;
  height: 1.5rem;
  fill: none;
  stroke: currentColor;
  stroke-width: 2.2;
  stroke-linecap: round;
  stroke-linejoin: round;
}
@keyframes pulse {
  50% {
    opacity: 0.4;
  }
}
</style>
