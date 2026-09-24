<script setup lang="ts">
/**
 * **Proposer avec l'assistant** — écrire librement, joindre des actes, relire,
 * déposer.
 *
 * Quatre temps, un seul composant, parce qu'ils partagent les mêmes documents :
 * ceux qu'on joint à l'analyse sont aussi ceux qu'on joint, ensuite, comme
 * preuves aux fiches visées.
 *
 * 1. **Saisie** — un grand champ de texte, les documents (Importer / Prendre en
 *    photo), la mention du quota et « Analyser ».
 * 2. **Attente** — 20 à 60 secondes, dites comme telles, avec le temps écoulé
 *    et « Annuler » : une attente qu'on ne peut ni mesurer ni interrompre
 *    ressemble à une panne dès la quinzième seconde.
 * 3. **Relecture** — le résumé, les avertissements, une carte par demande,
 *    cochée par défaut. Rien n'est parti.
 * 4. **Dépôt** — au clic, et à ce clic seulement : une demande après l'autre,
 *    chacune avec son état ; un échec n'arrête pas les suivantes. Puis la liste
 *    des demandes créées, avec leur lien de suivi.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le dépôt ne reposte pas le corps affiché.** Il passe par l'analyse gardée
 * côté serveur (`deposerDemandeAssistant`) : le corps que l'écran reçoit est une
 * vue d'affichage, sans les valeurs que le lecteur n'a pas le droit de lire.
 * Voir `lib/api/assistant.ts`.
 *
 * **Sous la bascule « voir comme un membre », rien ne s'écrit** (§ 10.4) : toute
 * écriture y rend `409`, l'analyse comprise. L'écran le dit et éteint ses
 * boutons — par honnêteté, pas par sécurité : l'API refuse de toute façon.
 * ────────────────────────────────────────────────────────────────────
 *
 * Les URL d'objet des aperçus sont révoquées au retrait d'un document et au
 * démontage : un aperçu oublié garde l'image entière en mémoire jusqu'à la
 * fermeture de l'onglet.
 */
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue'
import {
  analyserAvecAssistant,
  analyserErreur,
  deposerDemandeAssistant,
  invaliderGraphe,
  televerserMedia,
  type AnalyseAssistant,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import CarteDemandeProposee from './CarteDemandeProposee.vue'
import DocumentsAssistant, { type DocumentAffiche } from './DocumentsAssistant.vue'
import {
  TEXTE_MAX,
  bilanDepot,
  clesCocheesParDefaut,
  estAnnulation,
  estApercuImage,
  estPdf,
  executerDepot,
  libelleDepot,
  mentionQuota,
  messageEchecDepot,
  peutAnalyser,
  planDeDepot,
  titreErreurAnalyse,
  trierDocuments,
} from './assistant'
import { reduireSiImage } from './reduireImage'
import {
  type EtapeDepot,
} from './assistant'

const { personId = null } = defineProps<{
  /** La fiche d'où l'on vient (`?personne=`) : un contexte pour l'assistant. */
  personId?: number | null
}>()

const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

/* --- Saisie --------------------------------------------------------- */

const texte = ref('')
const documents = ref<DocumentAffiche[]>([])
const refus = ref<string[]>([])
let compteur = 0

async function ajouterDocuments(fichiers: File[]): Promise<void> {
  // Les photos sont allégées d'abord (2000 px, JPEG) : c'est leur poids réduit qui compte.
  const prepares = await Promise.all(fichiers.map(reduireSiImage))
  const dejaJoint = documents.value.reduce((t, d) => t + d.fichier.size, 0)
  const verdict = trierDocuments(documents.value.length, prepares, dejaJoint)
  refus.value = verdict.refus
  for (const fichier of verdict.acceptes) {
    documents.value.push({
      cle: ++compteur,
      fichier,
      apercu: estApercuImage(fichier) && typeof URL !== 'undefined' ? URL.createObjectURL(fichier) : null,
      pdf: estPdf(fichier),
    })
  }
}

function retirerDocument(cle: number): void {
  const document = documents.value.find((d) => d.cle === cle)
  if (document?.apercu) URL.revokeObjectURL(document.apercu)
  documents.value = documents.value.filter((d) => d.cle !== cle)
  refus.value = []
}

/* --- Analyse -------------------------------------------------------- */

type Phase = 'saisie' | 'analyse' | 'relecture' | 'depot'
const phase = ref<Phase>('saisie')
const analyse = shallowRef<AnalyseAssistant | null>(null)
const erreur = ref<unknown>(null)
const restant = ref<number | null>(null)
const secondes = ref(0)

let controleur: AbortController | null = null
let minuterie: ReturnType<typeof setInterval> | null = null

function arreterMinuterie(): void {
  if (minuterie !== null) clearInterval(minuterie)
  minuterie = null
}

const analysable = computed(
  () => !suspendu.value && peutAnalyser(texte.value, documents.value.length),
)

async function analyser(): Promise<void> {
  if (phase.value === 'analyse' || !analysable.value) return
  erreur.value = null
  phase.value = 'analyse'
  secondes.value = 0
  minuterie = setInterval(() => secondes.value++, 1000)
  controleur = new AbortController()

  try {
    const reponse = await analyserAvecAssistant(
      { texte: texte.value, fichiers: documents.value.map((d) => d.fichier), personId },
      controleur.signal,
    )
    analyse.value = reponse
    restant.value = reponse.restant
    cochees.value = clesCocheesParDefaut(reponse.demandes)
    phase.value = 'relecture'
  } catch (cause) {
    phase.value = 'saisie'
    if (!estAnnulation(cause)) {
      erreur.value = cause
      if (analyserErreur(cause).statut === 429) restant.value = 0
    }
  } finally {
    arreterMinuterie()
    controleur = null
  }
}

function annuler(): void {
  controleur?.abort()
}

function surActionErreur(conduite: string): void {
  if (conduite === 'reessayer') void analyser()
  else if (conduite === 'recharger' && typeof window !== 'undefined') window.location.reload()
}

const titreErreur = computed(() => titreErreurAnalyse(erreur.value) ?? '')

/* --- Relecture ------------------------------------------------------ */

const cochees = ref<Set<string>>(new Set())
const joindrePreuves = ref(true)
const message = ref('')

function basculer(cle: string, coche: boolean): void {
  const suivant = new Set(cochees.value)
  if (coche) suivant.add(cle)
  else suivant.delete(cle)
  cochees.value = suivant
}

const nombreCochees = computed(() => cochees.value.size)
const avecCibleExistante = computed(
  () =>
    analyse.value?.demandes.some(
      (d) => cochees.value.has(d.cle) && d.corps.kind !== 'creation_personne',
    ) ?? false,
)

function revenirALaSaisie(): void {
  phase.value = 'saisie'
}

/* --- Dépôt ---------------------------------------------------------- */

const plan = ref<EtapeDepot[]>([])
const depotEnCours = ref(false)
const bilan = computed(() => bilanDepot(plan.value))

async function deposer(): Promise<void> {
  const courante = analyse.value
  if (courante === null || depotEnCours.value || suspendu.value || nombreCochees.value === 0) return
  const fichiers = documents.value.map((d) => d.fichier)
  plan.value = planDeDepot(courante.demandes, cochees.value, fichiers, joindrePreuves.value)
  phase.value = 'depot'
  await relancerDepot()
}

/** Rejoue les étapes qui ne sont pas passées. `executerDepot` saute ce qui a
 *  réussi : une demande ne se dépose qu'une fois. */
async function relancerDepot(): Promise<void> {
  const courante = analyse.value
  if (courante === null || depotEnCours.value) return
  depotEnCours.value = true
  const fichiers = documents.value.map((d) => d.fichier)
  try {
    await executerDepot(plan.value, {
      deposer: (cle) => deposerDemandeAssistant(courante.analyseId, cle, message.value),
      televerser: ({ index, personId: cible, legende }) => {
        const fichier = fichiers[index]
        if (fichier === undefined) return Promise.reject(new Error('Document introuvable.'))
        return televerserMedia({
          fichier,
          kind: 'preuve',
          subjectKind: 'personne',
          subjectId: cible,
          caption: legende,
        })
      },
    })
  } finally {
    depotEnCours.value = false
    // Une création apparaît comme nœud en attente dans le graphe de son auteur :
    // l'`ETag` du graphe est périmé dès qu'une demande est passée.
    if (bilan.value.reussis > 0) invaliderGraphe()
  }
}

function recommencer(): void {
  for (const document of documents.value) if (document.apercu) URL.revokeObjectURL(document.apercu)
  documents.value = []
  refus.value = []
  texte.value = ''
  message.value = ''
  analyse.value = null
  plan.value = []
  erreur.value = null
  phase.value = 'saisie'
}

function etatPreuves(etape: EtapeDepot): string {
  const faites = etape.preuves.filter((p) => p.etat === 'reussi').length
  return `${faites} document${faites > 1 ? 's' : ''} joint${faites > 1 ? 's' : ''} sur ${etape.preuves.length}`
}

onBeforeUnmount(() => {
  controleur?.abort()
  arreterMinuterie()
  for (const document of documents.value) if (document.apercu) URL.revokeObjectURL(document.apercu)
})
</script>

<template>
  <div class="flex flex-col gap-4">
    <p
      v-if="motifSuspendu !== null"
      class="rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
      role="status"
    >
      {{ motifSuspendu }}
    </p>

    <!-- 1 et 2 : la saisie, puis l'attente, au même endroit -->
    <template v-if="phase === 'saisie' || phase === 'analyse'">
      <MessageErreur
        v-if="erreur !== null"
        :erreur="erreur"
        :titre="titreErreur"
        @action="surActionErreur"
      />

      <PanneauBase titre="Racontez ce que vous savez">
        <div class="flex flex-col gap-4">
          <label class="flex flex-col gap-1">
            <span class="text-sm text-attenue">
              Écrivez librement : qui, quand, où, et d’où vous le tenez. L’assistant retrouvera les
              personnes dans l’arbre.
            </span>
            <textarea
              v-model="texte"
              rows="7"
              :maxlength="TEXTE_MAX"
              :disabled="phase === 'analyse' || suspendu"
              placeholder="« Ma grand-tante Marie Dupont est née le 3 mars 1921 à Brest, fille de Jean Dupont. J’ai son acte de naissance. »"
              class="min-h-40 rounded-champ border border-ligne bg-fond px-3 py-2 text-[15px] leading-relaxed text-texte disabled:opacity-60"
            />
            <span class="self-end text-[11px] text-attenue tabular-nums">
              {{ texte.length }} / {{ TEXTE_MAX }}
            </span>
          </label>

          <div class="flex flex-col gap-1.5">
            <span class="text-sm font-medium">Documents</span>
            <DocumentsAssistant
              :documents="documents"
              :refus="refus"
              :desactive="phase === 'analyse' || suspendu"
              @ajouter="ajouterDocuments"
              @retirer="retirerDocument"
            />
          </div>
        </div>
      </PanneauBase>

      <div
        v-if="phase === 'analyse'"
        class="flex flex-col gap-3 rounded-panneau border border-accent bg-accent-doux px-4 py-3.5"
        role="status"
        aria-live="polite"
      >
        <div class="flex items-start gap-3">
          <span
            class="mt-0.5 size-4 flex-none animate-spin rounded-full border-2 border-accent border-t-transparent"
            aria-hidden="true"
          />
          <div class="min-w-0">
            <p class="m-0 text-sm font-semibold">
              L’assistant lit vos documents et parcourt l’arbre…
            </p>
            <p class="m-0 mt-0.5 text-xs text-attenue">
              Cela prend en général 20 à 60 secondes. Vous pouvez rester sur cette page.
              <span class="tabular-nums">({{ secondes }} s)</span>
            </p>
          </div>
        </div>
        <div>
          <BoutonBase variante="secondaire" class="max-md:min-h-11 max-md:w-full" @click="annuler">
            Annuler l’analyse
          </BoutonBase>
        </div>
      </div>

      <div v-else class="flex flex-wrap items-center gap-3 max-md:flex-col max-md:items-stretch">
        <BoutonBase
          :desactive="!analysable || restant === 0"
          class="max-md:min-h-11"
          @click="analyser"
        >
          Analyser
        </BoutonBase>
        <span class="text-xs text-attenue max-md:text-center">{{ mentionQuota(restant) }}</span>
      </div>

      <p class="m-0 text-xs text-attenue">
        L’assistant ne modifie rien : il prépare des demandes que vous relisez, puis qu’un
        administrateur valide. Vos documents ne sont conservés que si vous les joignez comme
        preuves au moment du dépôt.
      </p>
    </template>

    <!-- 3 : la relecture -->
    <template v-else-if="phase === 'relecture' && analyse !== null">
      <PanneauBase titre="Ce que l’assistant a compris">
        <p class="m-0 text-sm">{{ analyse.resume }}</p>
        <p class="m-0 mt-2 text-xs text-attenue">{{ mentionQuota(restant) }}</p>
      </PanneauBase>

      <PanneauBase v-if="analyse.avertissements.length > 0" titre="À savoir">
        <ul class="m-0 list-disc space-y-1 pl-5 text-sm">
          <li v-for="(avertissement, index) in analyse.avertissements" :key="index">
            {{ avertissement }}
          </li>
        </ul>
      </PanneauBase>

      <p
        v-if="analyse.demandes.length === 0"
        class="m-0 rounded-panneau border border-ligne bg-panneau px-4 py-3 text-sm"
      >
        L’assistant n’a trouvé aucune demande à proposer. Précisez votre texte ou ajoutez un
        document, puis relancez l’analyse.
      </p>

      <template v-else>
        <h2 class="m-0 text-base font-semibold">
          {{ analyse.demandes.length }} demande<template v-if="analyse.demandes.length > 1">s</template>
          proposée<template v-if="analyse.demandes.length > 1">s</template>
        </h2>
        <p class="-mt-2 mb-0 text-sm text-attenue">
          Décochez ce que vous ne voulez pas déposer. Rien ne part avant votre clic.
        </p>

        <CarteDemandeProposee
          v-for="demande in analyse.demandes"
          :key="demande.cle"
          :demande="demande"
          :cochee="cochees.has(demande.cle)"
          :desactive="suspendu"
          @basculer="basculer(demande.cle, $event)"
        />

        <PanneauBase>
          <div class="flex flex-col gap-3">
            <label
              v-if="documents.length > 0"
              class="flex min-h-11 cursor-pointer items-start gap-2.5 text-sm"
            >
              <input
                v-model="joindrePreuves"
                type="checkbox"
                class="mt-0.5 size-[18px] flex-none cursor-pointer accent-accent"
              />
              <span>
                Joindre les documents comme preuves
                <span class="block text-xs text-attenue">
                  <template v-if="avecCibleExistante">
                    Ils seront ajoutés aux fiches existantes concernées, en attente de validation.
                  </template>
                  <template v-else>
                    Aucune demande cochée ne vise une fiche existante : rien ne sera joint.
                  </template>
                </span>
              </span>
            </label>

            <label class="flex flex-col gap-1">
              <span class="text-sm font-medium">Un mot pour l’administrateur</span>
              <textarea
                v-model="message"
                rows="2"
                placeholder="Facultatif — joint à chaque demande déposée."
                class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
              />
            </label>
          </div>
        </PanneauBase>
      </template>

      <div class="flex flex-wrap items-center gap-2 max-md:flex-col max-md:items-stretch">
        <BoutonBase
          v-if="analyse.demandes.length > 0"
          :desactive="nombreCochees === 0 || suspendu"
          class="max-md:min-h-11"
          @click="deposer"
        >
          {{ libelleDepot(nombreCochees) }}
        </BoutonBase>
        <BoutonBase variante="secondaire" class="max-md:min-h-11" @click="revenirALaSaisie">
          Modifier le texte et relancer
        </BoutonBase>
      </div>
    </template>

    <!-- 4 : le dépôt, puis la fin -->
    <template v-else-if="phase === 'depot'">
      <PanneauBase :titre="depotEnCours ? 'Dépôt en cours…' : 'Dépôt terminé'">
        <p v-if="!depotEnCours" class="m-0 mb-3 text-sm" role="status">
          {{ bilan.reussis }} demande<template v-if="bilan.reussis > 1">s</template>
          déposée<template v-if="bilan.reussis > 1">s</template>
          <template v-if="bilan.echecs > 0">, {{ bilan.echecs }} en échec</template>.
          <template v-if="bilan.reussis > 0">Elles attendent la validation d’un administrateur.</template>
        </p>

        <ol class="m-0 flex list-none flex-col gap-2 p-0">
          <li
            v-for="etape in plan"
            :key="etape.cle"
            class="rounded-champ border px-3 py-2.5"
            :class="
              etape.etat === 'echec'
                ? 'border-alerte-bord bg-alerte-doux'
                : etape.etat === 'reussi'
                  ? 'border-ok-bord bg-ok-doux'
                  : 'border-ligne'
            "
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="min-w-0 text-sm font-medium">{{ etape.titre }}</span>
              <span v-if="etape.etat === 'attente'" class="text-xs text-attenue">En file</span>
              <span v-else-if="etape.etat === 'en_cours'" class="text-xs text-attenue" role="status">
                Dépôt…
              </span>
              <RouterLink
                v-else-if="etape.etat === 'reussi' && etape.demandeId !== null"
                :to="{ name: 'demande.detail', params: { id: etape.demandeId } }"
                class="inline-flex min-h-9 items-center text-sm font-semibold text-accent underline max-md:min-h-11"
              >
                Suivre la demande n° {{ etape.demandeId }}
              </RouterLink>
            </div>

            <p v-if="etape.etat === 'echec'" class="m-0 mt-1 text-xs text-alerte" role="alert">
              {{ messageEchecDepot(etape.erreur) }}
            </p>

            <div v-if="etape.preuves.length > 0 && etape.etat === 'reussi'" class="mt-1.5">
              <p class="m-0 text-xs text-attenue">{{ etatPreuves(etape) }}</p>
              <ul class="m-0 mt-1 flex list-none flex-col gap-0.5 p-0">
                <li
                  v-for="preuve in etape.preuves.filter((p) => p.etat === 'echec')"
                  :key="preuve.index"
                  class="text-xs text-alerte"
                  role="alert"
                >
                  « {{ preuve.nom }} » : {{ analyserErreur(preuve.erreur).message }}
                </li>
              </ul>
            </div>
          </li>
        </ol>
      </PanneauBase>

      <div
        v-if="!depotEnCours"
        class="flex flex-wrap items-center gap-2 max-md:flex-col max-md:items-stretch"
      >
        <BoutonBase
          v-if="bilan.echecs > 0 || plan.some((e) => e.preuves.some((p) => p.etat === 'echec'))"
          variante="secondaire"
          class="max-md:min-h-11"
          @click="relancerDepot"
        >
          Réessayer ce qui a échoué
        </BoutonBase>
        <RouterLink
          :to="{ name: 'demandes.miennes' }"
          class="inline-flex min-h-11 items-center justify-center rounded-champ border border-ligne bg-panneau px-3.5 text-sm font-semibold hover:border-accent md:min-h-9"
        >
          Mes demandes
        </RouterLink>
        <BoutonBase variante="discret" class="max-md:min-h-11" @click="recommencer">
          Faire une autre analyse
        </BoutonBase>
      </div>
    </template>
  </div>
</template>
