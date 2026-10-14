<script setup lang="ts">
/**
 * Le détail d'une **demande** dans « À traiter » (handoff « mode
 * Administration », § 4.1) : ce qu'il faut pour décider sans quitter la file.
 *
 * - l'avertissement « N autre(s) demande(s) en attente sur cette fiche :
 *   décidez-les ensemble » (`autresSurLaMemeFiche`) ;
 * - l'aperçu du diff, une ligne par champ : l'ancienne valeur barrée, la
 *   nouvelle en vert (`diff.ts`) — un document proposé s'y montre ;
 * - le message de l'auteur ;
 * - « Ouvrir la demande complète », vers `demande.detail` avec le contexte de
 *   la file : les fiches proposées, les liens, les révisions y sont.
 *
 * La décision tient les règles de `DecisionDemande` : **le motif est exigé pour
 * refuser** (`peutRefuser`, vérifié avant l'appel), le bouton « Valider » suit
 * `resolutionPrevue` (une demande caduque ne se valide pas, et on dit
 * pourquoi), rien ne s'écrit sous une vue empruntée. Les erreurs passent par
 * `MessageErreur` : rejouer l'appel, ou relire la file.
 *
 * La demande de la liste n'a pas de quoi décider (`resolutionPrevue`, valeurs
 * actuelles) : la charge de l'administration est relue à l'ouverture.
 */
import { computed, ref, useId, watch } from 'vue'
import {
  invaliderGraphe,
  lireDemandeAdministration,
  refuserDemande,
  validerDemande,
  type ConduiteATenir,
  type Demande,
  type DemandeAdministration,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import DocumentPropose from './DocumentPropose.vue'
import { actionValidation, issueDeValidation, peutRefuser, type IssueDecision } from './decision'
import { lignesDeDiff } from './diff'

const { demande, autres = 0, suspendu = false, infobulle, requeteDeLaFile = {} } = defineProps<{
  demande: Demande
  /** Les autres demandes en attente sur la même fiche. */
  autres?: number
  suspendu?: boolean
  infobulle?: string | undefined
  /** Ce que « Ouvrir la demande complète » emporte de la file. */
  requeteDeLaFile?: Record<string, string>
}>()

const emit = defineEmits<{
  /** La demande a quitté la file. */
  decidee: [issue: IssueDecision]
  /** L'état affiché est périmé : relire la file. */
  recharger: []
  agrandir: [cible: CibleLoupe]
}>()

const complete = ref<DemandeAdministration | null>(null)
const lecture = ref(false)
const erreurDeLecture = ref<unknown>(null)
const envoi = ref<'valider' | 'refuser' | null>(null)
const erreur = ref<unknown>(null)
const motif = ref('')
const motifManquant = ref(false)
const idMotif = useId()

async function lire(): Promise<void> {
  const id = demande.id
  complete.value = null
  erreurDeLecture.value = null
  erreur.value = null
  motif.value = ''
  motifManquant.value = false
  lecture.value = true
  try {
    const lue = await lireDemandeAdministration(id)
    if (demande.id === id) complete.value = lue
  } catch (cause) {
    if (demande.id === id) erreurDeLecture.value = cause
  } finally {
    if (demande.id === id) lecture.value = false
  }
}

watch(() => demande.id, lire, { immediate: true })

const lignes = computed(() => (complete.value === null ? [] : lignesDeDiff(complete.value.ops)))
const validation = computed(() =>
  actionValidation(complete.value?.resolutionPrevue, complete.value?.complementDe ?? null),
)
/** Un complément (ADR 0017) : le numéro qu'il complète, lu sur la ligne de la file. */
const completeLa = computed(() => demande.complementDeDemandeId ?? null)
/** Une proposition qui a des compléments en attente : elle se fusionne d'abord. */
const complementsEnAttente = computed(
  () => (complete.value?.complements ?? []).filter((complement) => complement.status === 'en_attente').length,
)
const nouvelles = computed(() => demande.nouvellesPersonnes ?? [])

function saisir(valeur: string): void {
  motif.value = valeur
  if (valeur.trim() !== '') motifManquant.value = false
}

async function valider(): Promise<void> {
  if (suspendu || envoi.value !== null || complete.value === null || validation.value.desactive) return
  envoi.value = 'valider'
  erreur.value = null
  try {
    // L'empreinte de la demande relue à l'ouverture : complétée depuis, elle ne
    // se valide pas à l'aveugle (ADR 0017).
    const resultat = await validerDemande(demande.id, complete.value.empreinte)
    // L'arbre a changé : l'`ETag` du graphe est périmé (§ 5.2).
    invaliderGraphe()
    emit('decidee', issueDeValidation(resultat))
  } catch (cause) {
    erreur.value = cause
  } finally {
    envoi.value = null
  }
}

async function refuser(): Promise<void> {
  if (suspendu || envoi.value !== null) return
  // La garde, avant l'appel. Un refus sans motif ne doit pas atteindre le réseau.
  if (!peutRefuser(motif.value)) {
    motifManquant.value = true
    return
  }
  envoi.value = 'refuser'
  erreur.value = null
  try {
    await refuserDemande(demande.id, { message: motif.value.trim() })
    emit('decidee', 'refusee')
  } catch (cause) {
    erreur.value = cause
  } finally {
    envoi.value = null
  }
}

/** `reessayer` : rien n'a été écrit, le même appel peut repartir. Sinon l'état est périmé. */
function suivreConduite(conduite: ConduiteATenir): void {
  if (conduite === 'reessayer') void valider()
  else emit('recharger')
}
</script>

<template>
  <p
    v-if="autres > 0"
    class="rounded-champ border border-alerte-bord bg-alerte-doux px-3 py-2 text-[13px] text-alerte"
    data-autres-sur-la-fiche
  >
    {{ autres }} autre{{ autres > 1 ? 's' : '' }} demande{{ autres > 1 ? 's' : '' }} en attente sur cette fiche :
    décidez-les ensemble
  </p>

  <p v-if="nouvelles.length > 0" class="text-sm [overflow-wrap:anywhere]">
    <span class="text-attenue">Nouvelle{{ nouvelles.length > 1 ? 's' : '' }} personne{{ nouvelles.length > 1 ? 's' : '' }} :</span>
    {{ nouvelles.join(', ') }}
  </p>

  <!-- Un complément se fusionne dans sa proposition, depuis la demande complète. -->
  <p
    v-if="completeLa !== null"
    class="rounded-champ border border-accent bg-accent-doux px-3 py-2 text-[13px]"
    data-complete-la
  >
    Complète la <RouterLink
      :to="{ name: 'demande.detail', params: { id: completeLa } }"
      class="font-semibold text-accent underline"
    >demande n° {{ completeLa }}</RouterLink> : le geste attendu est la fusion, depuis la demande complète.
  </p>
  <p
    v-if="complementsEnAttente > 0"
    class="rounded-champ border border-accent bg-accent-doux px-3 py-2 text-[13px]"
    data-complements-a-fusionner
  >
    {{ complementsEnAttente > 1 ? `${complementsEnAttente} compléments attendent` : 'Un complément attend' }} sur cette
    proposition : fusionnez-{{ complementsEnAttente > 1 ? 'les' : 'le' }} depuis la demande complète avant de la valider.
  </p>

  <SqueletteChargement v-if="lecture" :lignes="2" />
  <MessageErreur v-else-if="erreurDeLecture !== null" :erreur="erreurDeLecture" @action="lire" />

  <!-- L'aperçu du diff : le nom du champ, l'ancienne valeur barrée → la nouvelle. -->
  <div
    v-else-if="lignes.length > 0"
    class="flex flex-col overflow-hidden rounded-champ border border-ligne"
    data-apercu-diff
  >
    <div
      v-for="ligne in lignes"
      :key="ligne.seq"
      class="flex flex-wrap gap-x-3 gap-y-0.5 border-b border-ligne px-3 py-2.5 text-[13.5px] last:border-b-0"
    >
      <span class="flex-[0_0_120px] text-attenue [overflow-wrap:anywhere]">{{ ligne.libelle }}</span>
      <span class="flex min-w-0 flex-[1_1_180px] flex-wrap gap-x-2 gap-y-1 [overflow-wrap:anywhere]">
        <template v-if="ligne.ancienne !== '(vide)'">
          <span class="text-alerte line-through">{{ ligne.ancienne }}</span>
          <span class="text-attenue" aria-hidden="true">→</span>
        </template>
        <span class="font-semibold text-ok">{{ ligne.proposee }}</span>
        <span v-if="ligne.aBouge === true" class="w-full text-xs font-semibold text-alerte">
          Cette valeur a changé depuis le dépôt de la demande.
        </span>
        <DocumentPropose v-if="ligne.mediaId !== null" :media-id="ligne.mediaId" @agrandir="emit('agrandir', $event)" />
      </span>
    </div>
  </div>
  <p v-else-if="complete !== null && nouvelles.length === 0" class="text-sm text-attenue">
    Cette demande ne modifie aucun champ existant.
  </p>

  <p v-if="demande.message" class="text-attenue whitespace-pre-line [overflow-wrap:anywhere]">« {{ demande.message }} »</p>

  <RouterLink
    :to="{ name: 'demande.detail', params: { id: demande.id }, query: requeteDeLaFile }"
    class="self-start text-[13.5px] text-accent underline"
  >
    Ouvrir la demande complète
  </RouterLink>

  <!-- Ce que la validation fera au clic, dit avant le clic. -->
  <p v-if="complete !== null && validation.explication !== null" class="text-sm text-attenue" data-explication>
    {{ validation.explication }}
  </p>

  <label class="flex flex-col gap-1">
    <span class="text-[12.5px] font-medium">Message à l’auteur (exigé pour refuser)</span>
    <textarea
      :id="idMotif"
      :value="motif"
      rows="2"
      class="resize-y rounded-champ border bg-fond px-2.5 py-2 text-sm text-texte"
      :class="motifManquant ? 'border-alerte' : 'border-ligne'"
      :aria-invalid="motifManquant ? 'true' : undefined"
      :disabled="suspendu"
      data-motif
      @input="saisir(($event.target as HTMLTextAreaElement).value)"
    />
  </label>
  <p v-if="motifManquant" class="text-[12.5px] text-alerte" role="alert" data-motif-manquant>
    Un message est exigé pour refuser.
  </p>

  <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="suivreConduite" />

  <div class="barre-actions-a-traiter" :title="infobulle">
    <BoutonBase
      class="min-h-11 flex-auto md:min-h-9"
      :chargement="envoi === 'valider'"
      :desactive="suspendu || envoi !== null || complete === null || validation.desactive"
      @click="valider"
    >
      {{ complete === null ? 'Valider' : validation.libelle }}
    </BoutonBase>
    <BoutonBase
      variante="danger"
      class="min-h-11 flex-auto md:min-h-9"
      :chargement="envoi === 'refuser'"
      :desactive="suspendu || envoi !== null"
      @click="refuser"
    >
      Refuser
    </BoutonBase>
  </div>
</template>
