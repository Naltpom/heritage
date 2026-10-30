<script setup lang="ts">
/**
 * La **fin de recherche** d'une personne (ADR 0023) — onglet Identité, juste après
 * la Famille, en lecture et en saisie.
 *
 * Une fin de recherche dit qu'on a cherché les parents de ce côté et qu'on s'arrête
 * faute d'archives : « registres inexistants avant 1668 », « lacune »… Elle est **par
 * côté** (père, mère), et elle passe par le circuit des demandes comme un nom ou une
 * date : l'encart dépose lui-même sa demande (`modification_personne`), hors du mode
 * édition, et la fiche relue montre alors le calque — la valeur validée, puis la
 * proposition « en attente de validation » (§ 13.2).
 *
 * Cinq choses se lisent, et `fin-de-recherche.ts` les décide (pur, testé) :
 *
 * - **close** : le motif, le texte, « Close le … par … » et un petit mur de brique ;
 * - **close, et un parent est lié depuis** : la même chose, plus la mention que l'arbre
 *   ne la montre plus — et la levée mise en avant : la fin de recherche reste
 *   enregistrée jusqu'à ce qu'on la lève ;
 * - **parent connu**, rien d'autre : une ligne discrète ;
 * - **pas encore cherché** ;
 * - **une demande en attente** : le calque, dessiné comme celui de `LigneChamp`, et
 *   aucun geste sur ce côté tant qu'elle attend.
 *
 * Les gestes (« Clore la recherche », « Modifier », « Rouvrir la recherche ») ne sont
 * offerts qu'à qui peut proposer (`useGestesFiche`). Rouvrir demande confirmation.
 *
 * La couleur brique est le jeton `--h-fin-de-recherche` de la feuille de style, avec la
 * valeur claire en repli : un mur ne se lit pas comme une alerte (orange), ni comme une
 * erreur de saisie.
 */
import { computed, reactive, ref, useId } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import {
  descriptionDuMotif,
  libelleDuCote,
  libelleDuMotif,
  LONGUEUR_MAX_DU_TEXTE,
  motifsDuChoix,
  phraseDeFinDeRecherche,
  PREMIERE_ANNEE,
  TRACE_DU_MUR,
  type ChoixDeCote,
} from '@/lib/finDeRecherche'
import BoutonBase from '@/components/base/BoutonBase.vue'
import DialogueConfirmation, { type DemandeDeConfirmation } from '@/components/base/DialogueConfirmation.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import { horodatageLisible } from './champs'
import {
  choixDeClotureOfferts,
  deposerFinDeRecherche,
  etatsDeFiche,
  gestesDuCote,
  natureDuCalque,
  saisieDepuis,
  valeurDeSaisie,
  type ErreursDeFormulaire,
  type EtatDeCote,
} from './fin-de-recherche'
import { useGestesFiche } from './gestes'

const { fiche } = defineProps<{ fiche: FichePersonneComplete }>()

const emit = defineEmits<{
  /** Une demande vient de partir : la fiche doit être relue pour que le calque la montre. */
  recharger: []
}>()

const { peutProposer } = useGestesFiche()
const id = useId()

const etats = computed(() => etatsDeFiche(fiche))
const choixDeCloture = computed(() => choixDeClotureOfferts(etats.value, peutProposer.value))

const TITRES: Record<'pere' | 'mere', string> = { pere: 'Côté père', mere: 'Côté mère' }

/** Brique, avec repli : le jeton est créé dans `styles/main.css` par l'écran de l'arbre. */
const BRIQUE = 'var(--h-fin-de-recherche, #9b3426)'
const BRIQUE_BORD = 'var(--h-fin-de-recherche-bord, #9b342659)'
const BRIQUE_DOUX = 'var(--h-fin-de-recherche-doux, #9b342614)'

function dateLisible(iso: string): string {
  return horodatageLisible(iso)
}

/* --- Le formulaire --------------------------------------------------- */

/** Ce que le formulaire fait : clore (un côté ou les deux) ou modifier (un côté). */
interface Formulaire {
  mode: 'clore' | 'modifier'
  choix: ChoixDeCote
}

const formulaire = ref<Formulaire | null>(null)
const saisie = reactive({ reason: '', beforeYear: '', body: '' })
const message = ref('')
const erreurs = ref<ErreursDeFormulaire>({})
const envoi = ref(false)
const erreurInattendue = ref<unknown>(null)
const conflit = ref(false)
const annonce = ref<{ demandeId: number } | null>(null)
const aucunChangement = ref(false)
const anneeCourante = new Date().getFullYear()

/** Les motifs du côté choisi — « Père inconnu » ne se propose que pour le père. */
const motifs = computed(() => (formulaire.value === null ? [] : motifsDuChoix(formulaire.value.choix)))
const motifChoisi = computed(() => descriptionDuMotif(saisie.reason))

function reinitialiser(): void {
  erreurs.value = {}
  erreurInattendue.value = null
  conflit.value = false
  aucunChangement.value = false
}

function ouvrirClore(): void {
  reinitialiser()
  annonce.value = null
  Object.assign(saisie, saisieDepuis(null))
  message.value = ''
  formulaire.value = { mode: 'clore', choix: choixDeCloture.value.find((c) => c !== 'les-deux') ?? choixDeCloture.value[0] ?? 'pere' }
}

function ouvrirModifier(etat: EtatDeCote): void {
  reinitialiser()
  annonce.value = null
  Object.assign(saisie, saisieDepuis(etat.fin))
  message.value = ''
  formulaire.value = { mode: 'modifier', choix: etat.cote }
}

function fermer(): void {
  formulaire.value = null
  reinitialiser()
}

/** Changer de côté peut retirer le motif choisi (« Père inconnu » n'a pas de sens pour la mère). */
function choisirCote(choix: ChoixDeCote): void {
  if (formulaire.value === null) return
  formulaire.value.choix = choix
  if (!motifsDuChoix(choix).some((m) => m.code === saisie.reason)) saisie.reason = ''
}

/** Un motif sans année n'emporte pas celle de l'ancien. */
function choisirMotif(code: string): void {
  saisie.reason = code
  if (descriptionDuMotif(code)?.avecAnnee !== true) saisie.beforeYear = ''
}

async function depotEn(choix: ChoixDeCote, nouvelle: ReturnType<typeof valeurDeSaisie>, texte: string): Promise<boolean> {
  envoi.value = true
  reinitialiser()
  try {
    const issue = await deposerFinDeRecherche(fiche, choix, nouvelle, texte, anneeCourante)
    switch (issue.type) {
      case 'envoyee':
        annonce.value = { demandeId: issue.demandeId }
        emit('recharger')
        return true
      case 'inchangee':
        aucunChangement.value = true
        return false
      case 'refusee':
        erreurs.value = issue.erreurs
        return false
      case 'conflit':
        conflit.value = true
        return false
      case 'echec':
        erreurInattendue.value = issue.erreur
        return false
    }
  } finally {
    envoi.value = false
  }
}

async function envoyer(): Promise<void> {
  const ouvert = formulaire.value
  if (ouvert === null || envoi.value) return
  const nouvelle = valeurDeSaisie(saisie)
  // Aucun motif : on le dit sur le champ, sans aller-retour.
  if (nouvelle === null) {
    erreurs.value = { reason: 'Choisissez un motif.' }
    return
  }
  if (await depotEn(ouvert.choix, nouvelle, message.value)) fermer()
}

/* --- Rouvrir la recherche -------------------------------------------- */

const levee = ref<EtatDeCote | null>(null)
const confirmationLevee = computed<DemandeDeConfirmation | null>(() =>
  levee.value === null
    ? null
    : {
        titre: 'Rouvrir la recherche ?',
        message: `La fin de recherche ${libelleDuCote(levee.value.cote)} sera levée une fois la demande validée par un administrateur.`,
        confirmer: 'Rouvrir la recherche',
      },
)

async function confirmerLevee(): Promise<void> {
  const etat = levee.value
  if (etat === null) return
  annonce.value = null
  await depotEn(etat.cote, null, '')
  levee.value = null
}
</script>

<template>
  <div class="flex flex-col gap-3" data-fin-de-recherche>
    <div
      v-for="etat in etats"
      :key="etat.cote"
      class="flex flex-col gap-1.5"
      :data-cote="etat.cote"
      :data-etat="etat.etat"
    >
      <h3 class="m-0 text-sm font-semibold">{{ TITRES[etat.cote] }}</h3>

      <!-- ---------------------------------------------------------- -->
      <!-- La valeur validée : une fin de recherche, un parent, rien. -->
      <!-- ---------------------------------------------------------- -->
      <div
        v-if="etat.fin !== null"
        class="flex items-start gap-2.5 rounded-champ border px-3 py-2 text-sm"
        :style="{ borderColor: BRIQUE_BORD, backgroundColor: BRIQUE_DOUX }"
        data-fin
      >
        <svg
          viewBox="0 0 24 24"
          width="20"
          height="20"
          fill="none"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round"
          class="mt-0.5 shrink-0"
          :style="{ stroke: BRIQUE }"
          aria-hidden="true"
        >
          <path :d="TRACE_DU_MUR" />
        </svg>
        <div class="flex min-w-0 flex-col gap-0.5">
          <span class="font-semibold" :style="{ color: BRIQUE }">{{ libelleDuMotif(etat.fin.reason, etat.fin.beforeYear) }}</span>
          <span v-if="etat.fin.body" class="whitespace-pre-line break-words">{{ etat.fin.body }}</span>
          <span class="text-xs text-attenue">
            Close le {{ dateLisible(etat.fin.closedAt) }} par {{ etat.fin.authorLabel }}
          </span>
          <span v-if="etat.parentConnu" class="mt-1 text-xs" data-parent-lie>
            Un parent est désormais lié de ce côté : l’arbre ne montre plus cette fin de recherche.
          </span>
        </div>
      </div>
      <p v-else-if="etat.etat === 'connus'" class="m-0 text-sm text-attenue">Parent connu</p>
      <p v-else class="m-0 text-sm text-attenue">Pas encore cherché</p>

      <!-- ---------------------------------------------------------- -->
      <!-- Le calque : la demande en attente, à côté de la valeur validée. -->
      <!-- ---------------------------------------------------------- -->
      <div
        v-if="etat.enAttente !== null"
        data-calque
        class="flex flex-col items-start gap-0.5 rounded-champ border border-accent bg-accent-doux px-3 py-2 text-[13px]"
      >
        <PastilleEtat etat="en_attente" />
        <span v-if="etat.enAttente.propose === null" class="font-semibold" data-levee>Rouvrir la recherche</span>
        <span v-else class="font-semibold">{{ phraseDeFinDeRecherche(etat.enAttente.propose) }}</span>
        <span class="text-xs text-attenue">
          Proposé par {{ etat.enAttente.auteur }}<template v-if="etat.enAttente.depuis">
            le {{ dateLisible(etat.enAttente.depuis) }}</template
          >
          ·
          <RouterLink
            :to="{ name: 'demande.detail', params: { id: etat.enAttente.demandeId } }"
            class="text-accent underline"
          >
            Demande n° {{ etat.enAttente.demandeId }}
          </RouterLink>
        </span>
        <span v-if="natureDuCalque(etat.enAttente) === 'modification'" class="text-xs text-attenue">
          Elle remplace la fin de recherche validée ci-dessus.
        </span>
      </div>
      <p v-if="gestesDuCote(etat, peutProposer).bloque" class="m-0 text-xs text-attenue" data-bloque>
        Une demande attend déjà la validation pour ce côté.
      </p>

      <!-- ---------------------------------------------------------- -->
      <!-- Les gestes de ce côté.                                       -->
      <!-- ---------------------------------------------------------- -->
      <div v-if="gestesDuCote(etat, peutProposer).modifier" class="flex flex-wrap items-center gap-2">
        <BoutonBase
          v-if="etat.parentConnu"
          variante="principal"
          :desactive="envoi"
          data-geste="rouvrir"
          @click="levee = etat"
        >
          Rouvrir la recherche
        </BoutonBase>
        <BoutonBase variante="secondaire" :desactive="envoi" data-geste="modifier" @click="ouvrirModifier(etat)">
          Modifier
        </BoutonBase>
        <BoutonBase
          v-if="!etat.parentConnu"
          variante="secondaire"
          :desactive="envoi"
          data-geste="rouvrir"
          @click="levee = etat"
        >
          Rouvrir la recherche
        </BoutonBase>
      </div>
    </div>

    <!-- ------------------------------------------------------------ -->
    <!-- Clore : un côté libre au moins, et le droit de proposer.       -->
    <!-- ------------------------------------------------------------ -->
    <BoutonBase
      v-if="formulaire === null && choixDeCloture.length > 0"
      class="self-start"
      variante="secondaire"
      :desactive="envoi"
      data-geste="clore"
      @click="ouvrirClore"
    >
      Clore la recherche
    </BoutonBase>

    <form
      v-if="formulaire !== null"
      class="flex flex-col gap-3 border-t border-ligne pt-3"
      novalidate
      data-formulaire
      @submit.prevent="envoyer"
    >
      <fieldset v-if="formulaire.mode === 'clore' && choixDeCloture.length > 1" class="m-0 flex flex-wrap gap-x-4 gap-y-1 border-0 p-0">
        <legend class="mb-1 p-0 text-sm font-medium">Côté</legend>
        <label v-for="choix in choixDeCloture" :key="choix" class="flex items-center gap-1.5 text-sm">
          <input
            type="radio"
            :name="`${id}-cote`"
            :value="choix"
            :checked="formulaire.choix === choix"
            @change="choisirCote(choix)"
          />
          {{ choix === 'les-deux' ? 'Les deux' : choix === 'pere' ? 'Père' : 'Mère' }}
        </label>
      </fieldset>
      <p v-else class="m-0 text-sm font-medium">
        {{ formulaire.choix === 'les-deux' ? 'Les deux côtés' : TITRES[formulaire.choix] }}
      </p>

      <label class="flex flex-col gap-1">
        <span class="text-sm font-medium">Motif</span>
        <select
          :value="saisie.reason"
          class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
          :aria-invalid="erreurs.reason ? 'true' : undefined"
          @change="choisirMotif(($event.target as HTMLSelectElement).value)"
        >
          <option value="" disabled>Choisir un motif</option>
          <option v-for="motif in motifs" :key="motif.code" :value="motif.code">{{ motif.libelle }}</option>
        </select>
        <span v-if="erreurs.reason" class="text-xs text-alerte" data-erreur="reason">{{ erreurs.reason }}</span>
      </label>

      <label v-if="motifChoisi?.avecAnnee" class="flex flex-col gap-1">
        <span class="text-sm font-medium">Année</span>
        <input
          v-model="saisie.beforeYear"
          type="number"
          inputmode="numeric"
          :min="PREMIERE_ANNEE"
          :max="anneeCourante"
          placeholder="Ex. : 1668"
          class="w-32 rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
          :aria-invalid="erreurs.beforeYear ? 'true' : undefined"
        />
        <span class="text-xs text-attenue">Les registres n’existent pas avant cette année.</span>
        <span v-if="erreurs.beforeYear" class="text-xs text-alerte" data-erreur="beforeYear">{{ erreurs.beforeYear }}</span>
      </label>

      <label class="flex flex-col gap-1">
        <span class="text-sm font-medium">
          Précisions<template v-if="!motifChoisi?.texteObligatoire"> (facultatif)</template>
        </span>
        <textarea
          v-model="saisie.body"
          rows="3"
          :maxlength="LONGUEUR_MAX_DU_TEXTE"
          placeholder="Ex. : les registres de Saint-Martin commencent en 1668"
          class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
          :aria-invalid="erreurs.body ? 'true' : undefined"
        />
        <span v-if="erreurs.body" class="text-xs text-alerte" data-erreur="body">{{ erreurs.body }}</span>
      </label>

      <label class="flex flex-col gap-1">
        <span class="text-sm font-medium">Message à l’administrateur (facultatif)</span>
        <input
          v-model="message"
          type="text"
          class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
        />
        <span v-if="erreurs.message" class="text-xs text-alerte" data-erreur="message">{{ erreurs.message }}</span>
      </label>

      <p v-if="erreurs.general" class="m-0 text-xs text-alerte" data-erreur="general">{{ erreurs.general }}</p>
      <p v-if="aucunChangement" class="m-0 text-xs text-attenue" data-aucun-changement>
        Rien ne change : cette fin de recherche est déjà celle de la fiche.
      </p>

      <div class="flex flex-wrap items-center gap-2">
        <BoutonBase type="submit" :chargement="envoi" :desactive="envoi">Envoyer la demande</BoutonBase>
        <BoutonBase variante="discret" :desactive="envoi" @click="fermer">Annuler</BoutonBase>
      </div>
    </form>

    <!-- Hors du formulaire : la levée se confirme sans lui. -->
    <p v-if="conflit" class="m-0 text-xs text-alerte" role="alert" data-conflit>
      La fiche a changé depuis son affichage : rechargez la page, puis recommencez.
    </p>
    <MessageErreur v-if="erreurInattendue !== null" :erreur="erreurInattendue" />

    <section
      v-if="annonce !== null"
      class="flex flex-col gap-1 rounded-panneau border border-edition-bord bg-edition-doux px-4 py-3 text-[13.5px]"
      role="status"
      data-annonce-fin-de-recherche
    >
      <p class="m-0 font-semibold">Demande envoyée : un administrateur va la relire.</p>
      <p class="m-0">
        <RouterLink :to="{ name: 'demande.detail', params: { id: annonce.demandeId } }" class="font-semibold text-edition underline">
          demande n° {{ annonce.demandeId }}
        </RouterLink>
      </p>
      <button type="button" class="self-start text-xs text-attenue underline" @click="annonce = null">Fermer</button>
    </section>

    <DialogueConfirmation
      :demande="confirmationLevee"
      :chargement="envoi"
      @confirmer="confirmerLevee"
      @annuler="levee = null"
    />
  </div>
</template>
