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
 */
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { creerDemande, invaliderGraphe, televerserMedia, type OrigineReprise } from '@/lib/api'
import { useSession } from '@/composables/useSession'
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

const { contexte, refaire = null } = defineProps<{
  /** `page` : `/personnes/proposer` ; `volet` : le volet gauche de `/arbre`, 440 px. */
  contexte: 'page' | 'volet'
  /** La demande caduque ou refusée à reprendre (§ 6.10), lue à la création. */
  refaire?: number | null
}>()

const emit = defineEmits<{
  /** La demande est déposée **et** ses photos sont parties. */
  deposee: [depot: PersonneDeposee]
  /** « Refaire cette demande » : la demande morte dont on repart. */
  refaire: [demandeId: number]
  /** La saisie s'écarte (ou non) d'un formulaire vierge — le volet n'offre
   *  « Effacer » que lorsqu'il y a quelque chose à effacer. */
  sale: [sale: boolean]
}>()

const { vueEmpruntee } = useSession()

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
