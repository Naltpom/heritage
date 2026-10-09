<script setup lang="ts">
/**
 * **Le formulaire « Ajouter une personne »** — § 6.3, § 6.4 du dossier.
 *
 * Il vivait dans `PropositionCreationView` ; il en est sorti (09/10/2026) pour
 * servir à deux endroits sans changer de comportement :
 *  - la page `/personnes/proposer?mode=formulaire` (`contexte="page"`), où il est
 *    né — c'est le bouton « Ajouter une personne » de l'accueil ;
 *  - le volet gauche de `/arbre` (`contexte="volet"`, `VoletProposition.vue`), qui
 *    le garde ouvert à côté de l'arbre pendant qu'on y cherche les parents.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Il ne connaît pas le routeur** — ni `useRoute`, ni `useRouter`. Dans le volet,
 * quitter `/arbre` perdrait l'arbre et la saisie ; c'est donc à celui qui le monte
 * de décider où l'on va :
 *  - la reprise d'une demande caduque ou refusée (§ 6.10) arrive par la prop
 *    `refaire`, lue **une fois**, à la création — comme la page lisait `?refaire=` ;
 *  - « Refaire cette demande » (`PanneauConflit`) remonte en événement `refaire` :
 *    la page en fait une navigation, le volet recharge le formulaire sur place ;
 *  - le dépôt réussi remonte en événement `deposee`, **une fois les photos
 *    parties** : la page mène au suivi de la demande, le volet confirme et montre
 *    la personne dans l'arbre redessiné.
 *
 * Le lien « Revenir à l'arbre » n'a de sens que sur la page : elle le pose dans la
 * fente `actions`, au bout de la rangée des boutons.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Ce que ce formulaire ne fait pas, et pourquoi.**
 *
 * - **Il ne décrit aucune famille.** Désigner un conjoint sans famille existante
 *   en crée une ; désigner deux parents sans famille commune en crée une. Mais
 *   c'est le service qui les déduit **au moment de la validation**, pas la
 *   demande. Un `familleRef` envoyé d'ici serait refusé en `422`.
 * - **Il ne donne pas d'identifiant à la personne.** Elle vit sous `#p1` tant
 *   qu'elle n'est pas validée, et tout ce qui la référence — liens, médias —
 *   emploie cette référence provisoire. La validation la résout.
 * - **Il ne bloque pas un brouillon incomplet.** « Au moins un lien » n'est
 *   exigé qu'à la soumission : on commence un soir, on finit le lendemain.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Il cherche les doublons pendant la saisie** (10/10/2026). Dès qu'il y a un nom
 * et un prénom, les personnes de l'arbre et les propositions en attente qui
 * ressemblent à la saisie paraissent entre « Naissance et décès » et les liens
 * (`PanneauRessemblances`, `useRessemblances`). « C'est elle : compléter sa
 * fiche » reporte la saisie sur la fiche existante au lieu d'en créer une seconde ;
 * le dépôt remonte alors en `deposee` avec `nature: 'fiche'`.
 *
 * Une ressemblance **forte** retient le premier « Envoyer la proposition », une
 * fois : le formulaire défile jusqu'au panneau, qui dit « Vérifiez d'abord ces
 * personnes » et offre « Envoyer quand même ». « Enregistrer sans envoyer » n'est
 * pas freiné — un brouillon n'entre dans aucune file.
 *
 * Dans le volet de `/arbre`, trois événements de plus vont à l'arbre :
 * `voir-personne` et `voir-proposition` (montrer sans quitter la page, et dire si
 * c'est dessiné), et `en-evidence` (cercler les ressemblances en pointillé).
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, useTemplateRef, watch } from 'vue'
import {
  creerDemande,
  invaliderGraphe,
  televerserMedia,
  type OrigineReprise,
  type RessemblanceDansLArbre,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import { useRessemblances } from '@/composables/useRessemblances'
import { prefereMoinsDAnimations } from '@/lib/videos'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { ecrituresSuspendues } from '@/components/administration/ecriture'
import BlocLiens from '@/components/proposition/BlocLiens.vue'
import ChampDateIncertaine from '@/components/proposition/ChampDateIncertaine.vue'
import ChampsIdentite from '@/components/proposition/ChampsIdentite.vue'
import PanneauConflit from '@/components/proposition/PanneauConflit.vue'
import PhotosAJoindre, { type PhotoAJoindre } from '@/components/proposition/PhotosAJoindre.vue'
import { envoyerLesPhotos, photosAEnvoyer } from '@/components/proposition/photos'
import { conflitDeDemande, type ConflitDeDemande } from '@/components/proposition/conflit'
import {
  corpsCreationPersonne,
  creationVide,
  erreursParChamp,
  type SaisieCreation,
} from '@/components/proposition/creation'
import { chargerReprise, type Avertissement } from '@/components/proposition/reprise'
import { personneDeposee, type PersonneDeposee } from '@/components/proposition/depot'
import PanneauRessemblances from '@/components/proposition/PanneauRessemblances.vue'
import {
  designesDansLesLiens,
  enEvidence,
  estForte,
  garderLOuverte,
  resumeDesRessemblances,
  visibles,
  type EnEvidence,
} from '@/components/proposition/ressemblances'

const { contexte, refaire = null } = defineProps<{
  /** `page` : `/personnes/proposer` ; `volet` : le volet gauche de `/arbre`, 440 px. */
  contexte: 'page' | 'volet'
  /** La demande caduque ou refusée à reprendre (§ 6.10), lue à la création. */
  refaire?: number | null
}>()

const emit = defineEmits<{
  /** La demande est déposée **et** ses photos sont parties — ou, `nature: 'fiche'`,
   *  la saisie a complété une fiche existante. */
  deposee: [depot: PersonneDeposee]
  /** « Refaire cette demande » : la demande morte dont on repart. */
  refaire: [demandeId: number]
  /** La saisie s'écarte (ou non) d'un formulaire vierge — le volet n'offre
   *  « Effacer » que lorsqu'il y a quelque chose à effacer. */
  sale: [sale: boolean]
  /** Volet : montrer cette personne dans l'arbre, sans quitter `/arbre`.
   *  `repondre(false)` : elle n'y est pas dessinée avec les réglages du lecteur. */
  'voir-personne': [personId: number, repondre: (dessinee: boolean) => void]
  /** Volet : choisir ce nœud proposé dans l'arbre — même réponse. */
  'voir-proposition': [noeudId: string, repondre: (dessinee: boolean) => void]
  /** Les ressemblances affichées, que l'arbre cercle en pointillé. Vide quand il
   *  n'y en a plus. */
  'en-evidence': [cibles: EnEvidence]
}>()

const { vueEmpruntee, session } = useSession()

const saisie = ref<SaisieCreation>(creationVide())
const message = ref('')
const origine = ref<OrigineReprise | null>(null)
const avertissements = ref<Avertissement[]>([])

const chargement = ref(false)
const envoi = ref(false)
const erreur = ref<unknown>(null)
const conflit = ref<ConflitDeDemande | null>(null)
const erreurs = ref<Record<string, string>>({})

/** Les photos à joindre, chacune avec sa description. */
const photos = ref<PhotoAJoindre[]>([])
/**
 * La demande déjà déposée quand des photos n'ont pas pu partir. Un second clic
 * ne redépose pas la demande — elle existe — et ne renvoie que les photos
 * restées en échec.
 */
const depot = shallowRef<PersonneDeposee | null>(null)
const demandeDeposee = computed(() => depot.value?.demandeIds[0] ?? null)

/**
 * Le numéro de la saisie en cours : `reinitialiser()` en ouvre une nouvelle.
 * Une réponse qui arrive pour l'ancienne — une reprise lente, un dépôt parti
 * juste avant « Effacer » — ne doit rien écrire dans la nouvelle.
 */
let generation = 0

function revoquerLesApercus(): void {
  for (const photo of photos.value) if (photo.apercu) URL.revokeObjectURL(photo.apercu)
}

onBeforeUnmount(revoquerLesApercus)

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))

/** Le volet fait 440 px : l'identité s'y écrit en une colonne. */
const etroit = computed(() => contexte === 'volet')

/* --- « Est-ce déjà… ? » --------------------------------------------- */

/**
 * Le mode complétion d'une proposition en attente — la saisie y complète une
 * demande au lieu d'en créer une, et chercher des doublons n'y a plus de sens.
 * Il arrive avec l'API qui le permet ; d'ici là, il est éteint.
 */
const enModeCompletion = ref(false)

/**
 * La saisie a complété une fiche (« C'est elle ») : elle a servi. Le panneau
 * s'efface — et ses cercles dans l'arbre avec lui — jusqu'à la saisie suivante.
 */
const ficheDeposee = ref(false)

const ressemblances = useRessemblances(() => saisie.value.brouillon, {
  // Sous la bascule « voir comme un membre », « C'est elle » ne pourrait rien
  // écrire : on ne cherche pas. Une fois la demande déposée — il ne reste que
  // des photos à renvoyer — ou la fiche complétée, il n'y a plus rien à éviter.
  actif: () => !suspendu.value && !enModeCompletion.value && depot.value === null && !ficheDeposee.value,
})

/** La personne dont la complétion est dépliée. Elle reste à l'écran même si la
 *  dernière réponse ne la rend plus (`garderLOuverte`). */
const ficheOuverte = shallowRef<RessemblanceDansLArbre | null>(null)

const designes = computed(() => designesDansLesLiens(saisie.value))
const ressemblancesArbre = computed(() =>
  garderLOuverte(
    visibles(ressemblances.arbre.value, ressemblances.ecartees.value, designes.value),
    ficheOuverte.value,
  ),
)
const ressemblancesPropositions = computed(() =>
  visibles(ressemblances.propositions.value, ressemblances.ecartees.value, designes.value),
)

/**
 * Le panneau n'existe qu'avec un résultat, et plus du tout une fois la demande
 * déposée : il ne reste alors que des photos à renvoyer, et compléter une autre
 * fiche n'aurait plus de sens.
 */
const panneauVisible = computed(
  () =>
    depot.value === null &&
    !ficheDeposee.value &&
    !enModeCompletion.value &&
    ressemblancesArbre.value.length + ressemblancesPropositions.value.length > 0,
)

/** « C'est elle » a tout déposé : on l'annonce à l'hôte, qui décide de la suite. */
function surFicheCompletee(fait: PersonneDeposee): void {
  ficheDeposee.value = true
  ficheOuverte.value = null
  emit('deposee', fait)
}

/** Dit, sans prendre le focus, ce qui vient de paraître. */
const resume = computed(() =>
  panneauVisible.value
    ? resumeDesRessemblances(ressemblancesArbre.value.length, ressemblancesPropositions.value.length)
    : '',
)

/** Les demandes en attente du lecteur — « votre proposition ». */
const mesDemandes = computed(() => session?.value?.demandesEnAttente ?? [])

const AUCUNE_EVIDENCE: EnEvidence = { personIds: [], noeudIds: [] }
const evidence = computed<EnEvidence>(() =>
  panneauVisible.value ? enEvidence(ressemblancesArbre.value, ressemblancesPropositions.value) : AUCUNE_EVIDENCE,
)
watch(
  () => JSON.stringify(evidence.value),
  () => emit('en-evidence', evidence.value),
)
// Démonté (le volet rechargé sur une demande à refaire) : l'arbre ne garde pas
// des cercles que plus rien n'explique.
onBeforeUnmount(() => emit('en-evidence', AUCUNE_EVIDENCE))

function ouvrir(personId: number): void {
  ficheOuverte.value = ressemblancesArbre.value.find((r) => r.personId === personId) ?? null
}

/**
 * Le rappel avant l'envoi : **une fois** par saisie, au premier « Envoyer la
 * proposition », si une ressemblance forte reste affichée.
 */
const rappelFait = ref(false)
const rappel = ref(false)
const ancreDuPanneau = useTemplateRef<HTMLElement>('ancreDuPanneau')

const forteAffichee = computed(
  () =>
    panneauVisible.value &&
    [...ressemblancesArbre.value, ...ressemblancesPropositions.value].some(estForte),
)

async function montrerLeRappel(): Promise<void> {
  rappelFait.value = true
  rappel.value = true
  await nextTick()
  const ancre = ancreDuPanneau.value
  if (ancre === null || typeof ancre.scrollIntoView !== 'function') return
  ancre.scrollIntoView({ behavior: prefereMoinsDAnimations() ? 'auto' : 'smooth', block: 'start' })
}

/* --- Saisie entamée ? --------------------------------------------- */

const VIERGE = JSON.stringify(creationVide())
const sale = computed(
  () => JSON.stringify(saisie.value) !== VIERGE || message.value.trim() !== '' || photos.value.length > 0,
)
watch(sale, (valeur) => emit('sale', valeur))

/* --- La reprise ---------------------------------------------------- */

/**
 * Reprend une demande caduque ou refusée (§ 6.10).
 *
 * « La fiche et les liens repartent tels quels » : il n'y a ni `oldValue` ni
 * `baseVersion` à rafraîchir, puisque rien de ce que le brouillon décrit
 * n'existe encore — donc rien n'a pu bouger sous lui. Ce qui **peut** avoir
 * bougé, ce sont les personnes désignées à l'autre bout des liens : le serveur
 * retire du corps toute référence disparue et la signale en avertissement.
 */
async function reprendre(demandeId: number): Promise<void> {
  const tour = generation
  chargement.value = true
  erreur.value = null
  try {
    const reprise = await chargerReprise(demandeId)
    if (tour !== generation) return
    if (reprise === null || reprise.kind !== 'creation_personne') return
    saisie.value = reprise.saisie
    message.value = reprise.message
    origine.value = reprise.origine
    avertissements.value = reprise.avertissements
  } catch (cause) {
    if (tour === generation) erreur.value = cause
  } finally {
    if (tour === generation) chargement.value = false
  }
}

if (refaire !== null) void reprendre(refaire)

/* --- Le dépôt ----------------------------------------------------- */

async function deposer(soumettre: boolean): Promise<void> {
  if (envoi.value) return
  const tour = generation
  envoi.value = true
  erreur.value = null
  conflit.value = null
  erreurs.value = {}
  // Dépôt complet : le bouton reste occupé jusqu'à ce que la page s'en aille, ou
  // que le volet repasse à une saisie neuve (`reinitialiser`).
  let termine = false

  try {
    // La demande est déjà là : seules des photos restaient à envoyer.
    if (depot.value !== null) {
      termine = await terminer(depot.value, tour)
      return
    }

    const provenance = origine.value
    const options = { message: message.value, soumettre }
    const resultat = corpsCreationPersonne(
      saisie.value,
      provenance === null ? options : { ...options, origine: provenance },
    )

    if (!resultat.valide) {
      erreurs.value = erreursParChamp(resultat.erreurs)
      return
    }

    // Une saisie prête à partir, et une ressemblance forte encore affichée : on
    // retient ce premier envoi, le temps de regarder. Une fois — le second clic,
    // ou « Envoyer quand même », passe. Un brouillon n'est pas retenu.
    if (soumettre && !rappelFait.value && forteAffichee.value) {
      void montrerLeRappel()
      return
    }
    rappel.value = false

    const demande = await creerDemande(resultat.corps)

    // Une personne proposée apparaît comme **nœud en attente** dans le graphe
    // de son auteur (§ 13.4) : l'`ETag` du graphe est donc périmé.
    invaliderGraphe()

    if (tour !== generation) return
    const fait = personneDeposee(demande)
    depot.value = fait
    termine = await terminer(fait, tour)
  } catch (cause) {
    if (tour !== generation) return
    const reconnu = conflitDeDemande(cause)
    if (reconnu !== null) conflit.value = reconnu
    else erreur.value = cause
  } finally {
    if (tour === generation && !termine) envoi.value = false
  }
}

/** Envoie les photos, puis annonce le dépôt — sauf si l'une a échoué : on reste
 *  alors ici, la photo dit pourquoi, et « Réessayer » la renvoie. */
async function terminer(fait: PersonneDeposee, tour: number): Promise<boolean> {
  const echecs = await envoyerLesPhotos(photos.value, fait.demandeIds[0]!, televerserMedia)
  if (echecs > 0 || tour !== generation) return false
  emit('deposee', fait)
  return true
}

const photosEnEchec = computed(() =>
  demandeDeposee.value === null ? 0 : photosAEnvoyer(photos.value).length,
)

function refaireLaDemande(demandeId: number | null): void {
  if (demandeId !== null) emit('refaire', demandeId)
}

/* --- Repartir de zéro ---------------------------------------------- */

/**
 * Une saisie vierge : champs, liens, photos (leurs aperçus libérés), erreurs, et
 * la demande déposée oubliée. C'est « Ajouter une autre personne » et « Effacer »
 * du volet ; la page, elle, n'en a pas besoin — elle s'en va après le dépôt.
 */
function reinitialiser(): void {
  generation++
  revoquerLesApercus()
  photos.value = []
  saisie.value = creationVide()
  message.value = ''
  origine.value = null
  avertissements.value = []
  chargement.value = false
  envoi.value = false
  erreur.value = null
  conflit.value = null
  erreurs.value = {}
  depot.value = null
  ressemblances.reinitialiser()
  ficheOuverte.value = null
  ficheDeposee.value = false
  rappelFait.value = false
  rappel.value = false
}

defineExpose({ reinitialiser })
</script>

<template>
  <div v-if="chargement">
    <PanneauBase>
      <p class="mb-3 text-sm text-attenue" role="status">Reprise de la demande précédente…</p>
      <SqueletteChargement :lignes="4" />
    </PanneauBase>
  </div>

  <template v-else>
    <MessageErreur v-if="erreur !== null" :erreur="erreur" class="mb-4" />

    <PanneauConflit
      v-if="conflit !== null"
      :conflit="conflit"
      class="mb-4"
      @reessayer="deposer(true)"
      @refaire="refaireLaDemande"
    />

    <!-- Le serveur a retiré du corps les références disparues. Sans ce
         bandeau, on redéposerait une demande amputée sans s'en apercevoir. -->
    <PanneauBase v-if="avertissements.length > 0" titre="À relire avant d’envoyer" class="mb-4">
      <ul class="list-disc space-y-1 pl-5 text-sm">
        <li v-for="(avertissement, index) in avertissements" :key="index">
          {{ avertissement.message }}
        </li>
      </ul>
    </PanneauBase>

    <p
      v-if="origine !== null"
      class="mb-4 rounded-panneau border border-ligne bg-panneau px-3.5 py-3 text-sm"
    >
      Cette demande reprend la demande n° {{ origine.demandeId }}. Les pièces que vous y aviez
      jointes suivront.
    </p>

    <form class="flex flex-col gap-4" @submit.prevent="deposer(true)">
      <PanneauBase titre="Qui est cette personne">
        <ChampsIdentite
          v-model:given-names="saisie.brouillon.givenNames"
          v-model:surname="saisie.brouillon.surname"
          v-model:married-name="saisie.brouillon.marriedName"
          v-model:nickname="saisie.brouillon.nickname"
          v-model:sex="saisie.brouillon.sex"
          v-model:deceased="saisie.brouillon.deceased"
          :erreurs="erreurs"
          :une-colonne="etroit"
        />
      </PanneauBase>

      <PanneauBase titre="Naissance et décès">
        <div class="flex flex-col gap-4">
          <ChampDateIncertaine
            v-model="saisie.brouillon.naissance"
            etiquette="Naissance"
            :erreur="erreurs['naissance'] ?? ''"
          />
          <ChampDateIncertaine
            v-model="saisie.brouillon.deces"
            etiquette="Décès"
            :erreur="erreurs['deces'] ?? ''"
          />
        </div>
      </PanneauBase>

      <!-- « Est-ce déjà… ? » : la région vivante existe toujours — une région
           qui naît avec son texte n'est pas lue par tous les lecteurs d'écran —,
           le panneau seulement quand il y a quelque chose à montrer. -->
      <p class="sr-only" aria-live="polite" data-resume-ressemblances>{{ resume }}</p>
      <div v-if="panneauVisible" ref="ancreDuPanneau" class="scroll-mt-4">
        <PanneauRessemblances
          :arbre="ressemblancesArbre"
          :propositions="ressemblancesPropositions"
          :contexte="contexte"
          :ouverte="ficheOuverte?.personId ?? null"
          :rappel="rappel"
          :saisie="saisie"
          :message="message"
          :photos="photos"
          :mes-demandes="mesDemandes"
          @ecarter="ressemblances.ecarter"
          @ouvrir="ouvrir"
          @fermer="ficheOuverte = null"
          @voir-personne="(personId, repondre) => emit('voir-personne', personId, repondre)"
          @voir-proposition="(noeudId, repondre) => emit('voir-proposition', noeudId, repondre)"
          @envoyer-quand-meme="deposer(true)"
          @deposee="surFicheCompletee"
        />
      </div>

      <!-- Les liens : c'est ce qui rattache la personne à l'arbre, et sans
           quoi elle y flotterait. -->
      <BlocLiens v-model="saisie" :erreurs="erreurs" />

      <PanneauBase titre="Photos ou fichiers">
        <PhotosAJoindre v-model="photos" :desactive="suspendu || envoi" />
      </PanneauBase>

      <PanneauBase titre="Ce que vous savez">
        <div class="flex flex-col gap-3">
          <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">Sur quoi vous appuyez-vous ?</span>
            <textarea
              v-model="saisie.brouillon.note"
              rows="3"
              placeholder="« Livret de famille en ma possession, photographié. »"
              class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
            />
            <span class="text-xs text-attenue">
              Cette note reste attachée à la fiche proposée.
            </span>
          </label>

          <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">Ce que vous voulez dire à l’administrateur</span>
            <textarea
              v-model="message"
              rows="2"
              placeholder="« Épouse de Jargon, j’ai le livret de famille. »"
              class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
            />
            <span class="text-xs text-attenue">
              Facultatif, mais c’est souvent ce qui emporte la décision.
            </span>
          </label>
        </div>
      </PanneauBase>

      <p
        v-if="photosEnEchec > 0"
        class="m-0 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
        role="alert"
      >
        Votre demande n° {{ demandeDeposee }} est enregistrée, mais
        {{ photosEnEchec > 1 ? `${photosEnEchec} fichiers n’ont pas pu être envoyés` : 'un fichier n’a pas pu être envoyé' }}.
        <RouterLink :to="{ name: 'demande.detail', params: { id: demandeDeposee } }" class="font-semibold underline">
          Voir ma demande sans elles
        </RouterLink>
      </p>

      <div class="flex flex-wrap items-center gap-2">
        <BoutonBase v-if="demandeDeposee !== null" type="submit" :chargement="envoi" :desactive="suspendu">
          Réessayer l’envoi des fichiers
        </BoutonBase>
        <template v-else>
          <BoutonBase type="submit" :chargement="envoi" :desactive="suspendu">
            Envoyer la proposition
          </BoutonBase>
          <BoutonBase variante="secondaire" :desactive="suspendu || envoi" @click="deposer(false)">
            Enregistrer sans envoyer
          </BoutonBase>
        </template>
        <slot name="actions" />
      </div>
    </form>
  </template>
</template>
