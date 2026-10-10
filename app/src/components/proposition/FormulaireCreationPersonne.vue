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
 * **Compléter une proposition en attente** (ADR 0017, 10/10/2026). Deux gestes,
 * selon l'`action` que le serveur a calculée pour chaque proposition semblable :
 *
 * - **`completer`** — le lecteur en est l'auteur, ou il est administrateur : le
 *   formulaire passe en **mode complétion**. Il se remplit de la proposition
 *   (`fusion.ts` : elle l'emporte, la saisie remplit ses vides, les écarts
 *   s'affichent avec « Prendre ma valeur »), montre en lecture seule les liens
 *   qu'elle porte, ne garde de la saisie que les **liens à ajouter**, et
 *   enregistre en `PUT …/brouillons/{ref}` — empreinte comprise : si la
 *   proposition a changé entre-temps, on la **relit** au lieu de l'écraser.
 *   « Revenir à une nouvelle personne » rend la saisie d'avant, intacte.
 *   L'arbre y entre aussi (« Compléter », prop `completer`), sans fusionner : la
 *   saisie en cours parlait d'une autre personne, elle est mise de côté.
 * - **`complement`** — la proposition d'un autre membre : le dépôt reste une
 *   création ordinaire, qui porte `complementDe` ; l'administrateur la fusionnera.
 *
 * Les deux remontent en `deposee`, `nature: 'completion'` ou `'complement'`. Le
 * mode complétion s'annonce aussi en `completion` (la cible, puis `null`) : la
 * page le met dans son adresse, le volet dans son titre.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, useTemplateRef, watch } from 'vue'
import {
  CODE_DEMANDE_MODIFIEE,
  CODE_PROPOSITION_PLUS_EN_ATTENTE,
  creerDemande,
  estErreurApi,
  invaliderGraphe,
  lireDemande,
  lireDemandeAdministration,
  mettreAJourBrouillon,
  soumettreDemande,
  televerserMedia,
  type ConduiteATenir,
  type Demande,
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
  brouillonVide,
  corpsCreationPersonne,
  creationVide,
  erreursParChamp,
  type SaisieBrouillon,
  type SaisieCreation,
} from '@/components/proposition/creation'
import { chargerReprise, type Avertissement } from '@/components/proposition/reprise'
import { personneDeposee, propositionCompletee, type PersonneDeposee } from '@/components/proposition/depot'
import {
  copieDuBrouillon,
  corpsMiseAJour,
  designesParLaProposition,
  fusionnerBrouillons,
  joindreNotes,
  memeCible,
  phraseDuLienPropose,
  placesDeParent,
  prendreLaValeur,
  propositionDepuisDemande,
  raisonDeNePasCompleter,
  referenceSansDiese,
  retirerLiensDejaProposes,
  saisieDepuisBrouillonDemande,
  type ChampFusionne,
  type ComplementChoisi,
  type DemandeDeCompletion,
  type Divergence,
  type PropositionACompleter,
} from '@/components/proposition/fusion'
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

const { contexte, refaire = null, completer = null } = defineProps<{
  /** `page` : `/personnes/proposer` ; `volet` : le volet gauche de `/arbre`, 440 px. */
  contexte: 'page' | 'volet'
  /** La demande caduque ou refusée à reprendre (§ 6.10), lue à la création. */
  refaire?: number | null
  /**
   * « Complète cette fiche » : la proposition à compléter sur place — depuis
   * l'adresse de la page (`?completer=`), ou depuis le bouton « Compléter » d'un
   * nœud en attente de `/arbre`. La saisie en cours n'y est pas versée : elle est
   * mise de côté, et « Revenir à une nouvelle personne » la rend.
   */
  completer?: DemandeDeCompletion | null
}>()

const emit = defineEmits<{
  /** La demande est déposée **et** ses photos sont parties — ou, `nature: 'fiche'`,
   *  la saisie a complété une fiche existante ; `'completion'`, une proposition en
   *  attente ; `'complement'`, la proposition d'un autre membre. */
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
  /** Le mode complétion commence (la fiche, sans dièse) ou finit (`null`). */
  completion: [cible: DemandeDeCompletion | null]
}>()

const { vueEmpruntee, session, administrateur } = useSession()

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

function revoquerLesApercus(liste: readonly PhotoAJoindre[] = photos.value): void {
  for (const photo of liste) if (photo.apercu) URL.revokeObjectURL(photo.apercu)
}

onBeforeUnmount(() => {
  revoquerLesApercus()
  // Les photos de la saisie mise de côté pendant une complétion ne sont plus
  // affichées, mais leurs aperçus vivent encore.
  if (sauvegarde !== null) revoquerLesApercus(sauvegarde.photos.filter((p) => !photos.value.includes(p)))
})

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))

/** Le volet fait 440 px : l'identité s'y écrit en une colonne. */
const etroit = computed(() => contexte === 'volet')

/** L'utilisateur de la session : un brouillon d'autrui ne se complète pas. */
const lecteurId = computed(() => session?.value?.utilisateur.id ?? null)

/** Un vrai copié-collé, sans rien partager avec la saisie réactive. */
function copieProfonde<T>(valeur: T): T {
  return JSON.parse(JSON.stringify(valeur)) as T
}

/* --- Le complément (action `complement`) ---------------------------- */

/**
 * La proposition d'un autre membre que la saisie complète (§ 6.3 ter). Le dépôt
 * reste une création ordinaire — une fiche, au moins un lien —, qui porte
 * `complementDe` : l'administrateur la fusionnera dans l'origine.
 */
const complementDe = shallowRef<ComplementChoisi | null>(null)
/** `409 proposition_plus_en_attente` : l'origine a été tranchée entre-temps. */
const complementPerime = ref(false)

function choisirLeComplement(choisi: ComplementChoisi): void {
  complementDe.value = choisi
  complementPerime.value = false
  rappel.value = false
  ficheOuverte.value = null
}

/** Revenir à une proposition ordinaire : la saisie reste, la cible tombe. */
function quitterLeComplement(): void {
  complementDe.value = null
  complementPerime.value = false
}

/* --- La complétion sur place (action `completer`) ------------------- */

interface EtatCompletion {
  proposition: PropositionACompleter
  /** La proposition seule, pour « Garder celle de la proposition ». */
  proposee: SaisieBrouillon
  /** La saisie qu'on y a versée, pour « Prendre ma valeur ». */
  saisieAvant: SaisieBrouillon
  divergences: Divergence[]
  /** Un administrateur complète la proposition d'un autre : son auteur sera averti. */
  dAutrui: boolean
}

/** Ce qu'on retrouve sur « Revenir à une nouvelle personne ». */
interface Sauvegarde {
  saisie: SaisieCreation
  message: string
  origine: OrigineReprise | null
  avertissements: Avertissement[]
  photos: PhotoAJoindre[]
  complementDe: ComplementChoisi | null
}

/** La fiche qu'on complète — posée dès le clic, avant même la lecture. */
const cibleCompletion = shallowRef<DemandeDeCompletion | null>(null)
const completion = shallowRef<EtatCompletion | null>(null)
const lectureCompletion = ref(false)
const erreurCompletion = ref<unknown>(null)
/** Lue, mais pas complétable d'ici : une demande tranchée, le brouillon d'un autre. */
const incompletable = ref<string | null>(null)
/** `409 demande_modifiee_entre_temps` : la proposition a bougé depuis la lecture. */
const propositionChangee = ref(false)
/** Les divergences où le lecteur a pris sa valeur. */
const reprises = ref<ChampFusionne[]>([])
/** La saisie d'avant a été versée dans la proposition (depuis une ressemblance). */
const fusionnee = ref(false)
/**
 * Le `PUT` est passé — et peut-être la soumission —, mais une photo n'est pas
 * partie : un second clic ne réécrit pas la proposition, il renvoie ce qui manque.
 */
const completee = shallowRef<Demande | null>(null)
/** « Enregistrer… et envoyer », ou seulement enregistrer : le choix du dernier clic. */
let envoyerAuDernierClic = false
let sauvegarde: Sauvegarde | null = null
/**
 * La saisie à verser dans la proposition, tant qu'elle n'y est pas : si la
 * première lecture échoue, « Recharger » la verse encore — elle ne se perd pas
 * avec la lecture ratée.
 */
let fusionEnAttente: { saisie: SaisieCreation; mot: string } | null = null
/** La dernière lecture lancée : une réponse plus ancienne n'écrit rien. */
let jetonCompletion = 0

/** Le formulaire complète une proposition — de la lecture jusqu'à l'envoi. */
const enModeCompletion = computed(() => cibleCompletion.value !== null)

/** Un administrateur lit la demande d'un autre par la route d'administration. */
const lireEnAdministrateur = computed(() => administrateur.value && vueEmpruntee.value === null)

/**
 * Le formulaire passe en mode complétion sur `cible`.
 *
 * `fusionner` : depuis une ressemblance, la saisie **parle de la même personne** —
 * elle est versée dans la proposition. Depuis l'arbre ou l'adresse, elle parle
 * d'une autre : elle est mise de côté, photos comprises, et la proposition
 * s'ouvre seule.
 */
function completerLaProposition(cible: DemandeDeCompletion, fusionner: boolean): void {
  if (memeCible(cibleCompletion.value, cible)) return
  // Une création à moitié partie (ses photos restent à envoyer) ne se quitte pas.
  if (envoi.value || depot.value !== null) return

  // D'une complétion à une autre : on repart de la saisie mise de côté, sans
  // verser la première proposition dans la seconde.
  if (cibleCompletion.value !== null) {
    revenirALaSaisie(false)
    fusionner = false
  }

  sauvegarde = {
    saisie: copieProfonde(saisie.value),
    message: message.value,
    origine: origine.value,
    avertissements: [...avertissements.value],
    photos: [...photos.value],
    complementDe: complementDe.value,
  }
  fusionnee.value = fusionner
  // Les photos de l'autre personne restent avec sa saisie.
  if (!fusionner) photos.value = []

  fusionEnAttente = fusionner ? { saisie: copieProfonde(saisie.value), mot: message.value } : null

  cibleCompletion.value = { demandeId: cible.demandeId, ref: referenceSansDiese(cible.ref) }
  complementDe.value = null
  complementPerime.value = false
  rappel.value = false
  ficheOuverte.value = null
  erreur.value = null
  conflit.value = null
  erreurs.value = {}
  completee.value = null
  emit('completion', cibleCompletion.value)
  void lireLaProposition(fusionEnAttente?.saisie ?? null, fusionEnAttente?.mot ?? '')
}

/**
 * Lit la demande, y prend la fiche, et en remplit le formulaire — la saisie
 * `aFusionner` versée dedans s'il y en a une.
 *
 * Le message à l'administrateur ne part pas avec une complétion (le `PUT` n'en a
 * pas) : il rejoint la note de la fiche plutôt que de se perdre.
 */
async function lireLaProposition(aFusionner: SaisieCreation | null, motAFusionner: string): Promise<void> {
  const cible = cibleCompletion.value
  if (cible === null) return
  const jeton = ++jetonCompletion
  lectureCompletion.value = true
  erreurCompletion.value = null
  incompletable.value = null
  propositionChangee.value = false
  completion.value = null
  try {
    const demande = lireEnAdministrateur.value
      ? await lireDemandeAdministration(cible.demandeId)
      : await lireDemande(cible.demandeId)
    if (jeton !== jetonCompletion) return

    const proposition = propositionDepuisDemande(demande, cible.ref)
    if (proposition === null) {
      incompletable.value = `Cette fiche n’est pas dans la demande n° ${cible.demandeId} : elle ne se complète pas d’ici.`
      return
    }
    const raison = raisonDeNePasCompleter(proposition, lecteurId.value)
    if (raison !== null) {
      incompletable.value = raison
      return
    }

    const base: SaisieBrouillon | null =
      aFusionner === null ? null : { ...aFusionner.brouillon, note: joindreNotes(aFusionner.brouillon.note, motAFusionner) }
    const fusion = base === null ? null : fusionnerBrouillons(proposition.fiche, base)
    const proposee = fusion?.proposee ?? saisieDepuisBrouillonDemande(proposition.fiche)
    const liens =
      aFusionner === null
        ? { filiation: { famille: null, parents: [] }, unions: [], enfants: [] }
        : retirerLiensDejaProposes(aFusionner, proposition)

    saisie.value = {
      localRef: proposition.localRef,
      brouillon: fusion?.fusion ?? copieDuBrouillon(proposee),
      filiation: liens.filiation,
      unions: liens.unions,
      enfants: liens.enfants,
    }
    message.value = ''
    origine.value = null
    avertissements.value = []
    reprises.value = []
    // Versée : la saisie d'avant ne se reverse plus, c'est le formulaire qui la porte.
    fusionEnAttente = null
    completion.value = {
      proposition,
      proposee,
      saisieAvant: base ?? brouillonVide(),
      divergences: fusion?.divergences ?? [],
      dAutrui:
        proposition.authorUserId !== null && lecteurId.value !== null && proposition.authorUserId !== lecteurId.value,
    }
  } catch (cause) {
    if (jeton === jetonCompletion) erreurCompletion.value = cause
  } finally {
    if (jeton === jetonCompletion) lectureCompletion.value = false
  }
}

/**
 * « Relire la proposition » : la demande relue, et la saisie **en cours** versée
 * dedans — ce que le lecteur a écrit n'est pas perdu, et ce qui a bougé
 * reparaît en divergence.
 */
function relireLaProposition(): void {
  if (envoi.value) return
  if (completion.value !== null) void lireLaProposition(copieProfonde(saisie.value), '')
  // La première lecture n'a pas abouti : le formulaire ne porte encore rien.
  else void lireLaProposition(fusionEnAttente?.saisie ?? null, fusionEnAttente?.mot ?? '')
}

/**
 * « Revenir à une nouvelle personne » : la saisie d'avant la complétion, telle
 * quelle — photos comprises. Les photos ajoutées pendant la complétion s'en vont.
 */
function revenirALaSaisie(annoncer = true): void {
  jetonCompletion++
  const gardee = sauvegarde
  sauvegarde = null
  fusionEnAttente = null
  revoquerLesApercus(photos.value.filter((photo) => gardee === null || !gardee.photos.includes(photo)))
  if (gardee !== null) {
    saisie.value = gardee.saisie
    message.value = gardee.message
    origine.value = gardee.origine
    avertissements.value = gardee.avertissements
    photos.value = gardee.photos
    complementDe.value = gardee.complementDe
  } else {
    saisie.value = creationVide()
    message.value = ''
    origine.value = null
    avertissements.value = []
    photos.value = []
    complementDe.value = null
  }
  cibleCompletion.value = null
  completion.value = null
  lectureCompletion.value = false
  erreurCompletion.value = null
  incompletable.value = null
  propositionChangee.value = false
  reprises.value = []
  fusionnee.value = false
  completee.value = null
  erreur.value = null
  erreurs.value = {}
  if (annoncer) emit('completion', null)
}

/** « Prendre ma valeur » : la saisie d'avant, sur ce champ seulement. */
function prendreMaValeur(divergence: Divergence): void {
  const etat = completion.value
  if (etat === null) return
  saisie.value.brouillon = prendreLaValeur(saisie.value.brouillon, etat.saisieAvant, divergence.champ)
  reprises.value = [...reprises.value, divergence.champ]
}

/** « Garder celle de la proposition » : on revient sur sa décision. */
function garderLaProposition(divergence: Divergence): void {
  const etat = completion.value
  if (etat === null) return
  saisie.value.brouillon = prendreLaValeur(saisie.value.brouillon, etat.proposee, divergence.champ)
  reprises.value = reprises.value.filter((champ) => champ !== divergence.champ)
}

/** Les liens que la proposition porte, en mots — en lecture seule. */
const liensProposes = computed(() => {
  const etat = completion.value
  if (etat === null) return []
  return etat.proposition.liens.map((lien) => ({ seq: lien.seq, phrase: phraseDuLienPropose(lien, etat.proposition.localRef) }))
})
const dejaDesignes = computed(() => (completion.value === null ? [] : designesParLaProposition(completion.value.proposition)))
const placesLibres = computed(() => (completion.value === null ? 2 : placesDeParent(completion.value.proposition)))
const estUnBrouillon = computed(() => completion.value?.proposition.statut === 'brouillon')

/**
 * Enregistre les compléments (`PUT`), puis — sur un brouillon, si on le demande —
 * soumet la demande, puis envoie les photos vers **cette** fiche (`brouillonRef`).
 * Chaque étape faite n'est pas refaite au clic suivant.
 */
async function enregistrerLesComplements(envoyer: boolean): Promise<void> {
  const etat = completion.value
  if (etat === null || envoi.value) return
  const jeton = jetonCompletion
  envoyerAuDernierClic = envoyer
  envoi.value = true
  erreur.value = null
  erreurs.value = {}
  propositionChangee.value = false

  try {
    let demande = completee.value
    if (demande === null) {
      const resultat = corpsMiseAJour(saisie.value, {
        empreinte: etat.proposition.empreinte,
        liensExistants: etat.proposition.liens.length,
        soumettre: envoyer && etat.proposition.statut === 'brouillon',
      })
      if (!resultat.valide) {
        erreurs.value = erreursParChamp(resultat.erreurs)
        return
      }
      demande = await mettreAJourBrouillon(etat.proposition.demandeId, etat.proposition.brouillonRef, resultat.corps)
      // Le nœud de la proposition vient d'être réécrit, et sa demande touchée :
      // l'`ETag` du graphe est périmé (§ 5.2).
      invaliderGraphe()
      if (jeton !== jetonCompletion) return
      completee.value = demande
    }

    if (envoyer && demande.status === 'brouillon') {
      demande = await soumettreDemande(demande.id)
      invaliderGraphe()
      if (jeton !== jetonCompletion) return
      completee.value = demande
    }

    const echecs = await envoyerLesPhotos(photos.value, demande.id, televerserMedia, etat.proposition.brouillonRef)
    if (echecs > 0 || jeton !== jetonCompletion) return

    const fait = propositionCompletee(demande, etat.proposition.localRef)
    // Versée dans la proposition, la saisie a servi : on repart de zéro. Mise de
    // côté (l'arbre, l'adresse), elle revient — c'était une autre personne.
    if (fusionnee.value) sauvegarde = null
    revenirALaSaisie()
    emit('deposee', fait)
  } catch (cause) {
    if (jeton !== jetonCompletion) return
    // L'empreinte ne correspond plus : rien n'est écrit. On ne rejoue pas — on
    // relit, à la demande du lecteur, et sa saisie y est versée.
    if (estErreurApi(cause) && cause.code === CODE_DEMANDE_MODIFIEE) propositionChangee.value = true
    else erreur.value = cause
  } finally {
    envoi.value = false
  }
}

const photosEnEchecCompletion = computed(() => (completee.value === null ? 0 : photosAEnvoyer(photos.value).length))

/** Le bouton principal, selon ce qui reste à faire. */
const libelleEnregistrer = computed(() => {
  if (completee.value !== null) return 'Réessayer l’envoi'
  return estUnBrouillon.value ? 'Enregistrer les compléments et envoyer' : 'Enregistrer les compléments'
})

/* --- « Est-ce déjà… ? » --------------------------------------------- */

/**
 * La saisie a complété une fiche (« C'est elle ») : elle a servi. Le panneau
 * s'efface — et ses cercles dans l'arbre avec lui — jusqu'à la saisie suivante.
 */
const ficheDeposee = ref(false)

const ressemblances = useRessemblances(() => saisie.value.brouillon, {
  // Sous la bascule « voir comme un membre », « C'est elle » ne pourrait rien
  // écrire : on ne cherche pas. Une fois la demande déposée — il ne reste que
  // des photos à renvoyer — ou la fiche complétée, il n'y a plus rien à éviter ;
  // en complétant une proposition, ou en y ajoutant un complément, on a choisi.
  actif: () =>
    !suspendu.value &&
    !enModeCompletion.value &&
    complementDe.value === null &&
    depot.value === null &&
    !ficheDeposee.value,
  sauf: () => cibleCompletion.value?.demandeId ?? null,
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
 * fiche n'aurait plus de sens. Ni en complétant une proposition, ni en y
 * ajoutant un complément : le choix est fait.
 */
const panneauVisible = computed(
  () =>
    depot.value === null &&
    !ficheDeposee.value &&
    !enModeCompletion.value &&
    complementDe.value === null &&
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

// Une complétion demandée d'entrée l'emporte : la reprise, arrivée après, écrirait
// par-dessus la proposition ouverte.
if (refaire !== null && completer === null) void reprendre(refaire)

/* --- Le dépôt ----------------------------------------------------- */

async function deposer(soumettre: boolean): Promise<void> {
  if (envoi.value) return
  const tour = generation
  envoi.value = true
  erreur.value = null
  conflit.value = null
  erreurs.value = {}
  complementPerime.value = false
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
    const options = { message: message.value, soumettre, complementDe: complementDe.value?.draftPersonId ?? null }
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
    // La proposition complétée a été tranchée : le complément ne s'y rattache
    // plus. On le dit, et on offre d'en faire une proposition ordinaire.
    else if (estErreurApi(cause) && cause.code === CODE_PROPOSITION_PLUS_EN_ATTENTE && complementDe.value !== null)
      complementPerime.value = true
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

/** Le bouton « Envoyer » du formulaire, selon le mode. */
function soumettreLeFormulaire(): void {
  const etat = completion.value
  if (enModeCompletion.value) {
    if (etat !== null) void enregistrerLesComplements(completee.value !== null ? envoyerAuDernierClic : estUnBrouillon.value)
    return
  }
  void deposer(true)
}

/** Le bouton de `MessageErreur` : en complétion, « Recharger » relit la proposition. */
function surActionErreur(conduite: ConduiteATenir): void {
  if (enModeCompletion.value && conduite === 'recharger') relireLaProposition()
}

/* --- Repartir de zéro ---------------------------------------------- */

/**
 * Une saisie vierge : champs, liens, photos (leurs aperçus libérés), erreurs, et
 * la demande déposée oubliée. C'est « Ajouter une autre personne » et « Effacer »
 * du volet ; la page, elle, n'en a pas besoin — elle s'en va après le dépôt.
 * Une complétion en cours est abandonnée, et la saisie qu'elle avait mise de
 * côté avec elle.
 */
function reinitialiser(): void {
  generation++
  const enCompletion = cibleCompletion.value !== null
  // Tout s'en va, la saisie mise de côté par une complétion comprise.
  const deCote = sauvegarde?.photos.filter((photo) => !photos.value.includes(photo)) ?? []
  revoquerLesApercus([...photos.value, ...deCote])
  sauvegarde = null
  photos.value = []
  // Sans saisie mise de côté, revenir de la complétion, c'est repartir de zéro.
  revenirALaSaisie(false)
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
  complementPerime.value = false
  if (enCompletion) emit('completion', null)
}

defineExpose({ reinitialiser })

/*
 * « Complète cette fiche », demandé par l'hôte : à chaque nouvelle cible — ou
 * nouveau jeton, l'arbre en pose un par clic. Une cible déjà ouverte ne se relit
 * pas : la page remet dans son adresse celle qu'on vient d'ouvrir d'ici.
 */
watch(
  () => (completer === null ? null : `${completer.demandeId}/${referenceSansDiese(completer.ref)}/${completer.jeton ?? 0}`),
  (cle) => {
    if (cle !== null && completer !== null) completerLaProposition(completer, false)
  },
  { immediate: true },
)
</script>

<template>
  <div v-if="chargement">
    <PanneauBase>
      <p class="mb-3 text-sm text-attenue" role="status">Reprise de la demande précédente…</p>
      <SqueletteChargement :lignes="4" />
    </PanneauBase>
  </div>

  <template v-else>
    <!-- Le mode complétion s'annonce d'abord : on n'ajoute pas une personne, on
         en complète une qui attend déjà. -->
    <section
      v-if="cibleCompletion !== null"
      class="mb-4 flex flex-col gap-1.5 rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm"
      data-bandeau-completion
    >
      <p class="m-0 font-semibold" role="status">
        Vous complétez la proposition n° {{ cibleCompletion.demandeId }}<template v-if="completion !== null">
          — {{ completion.proposition.libelle }}</template>
      </p>
      <template v-if="completion !== null">
        <p v-if="completion.dAutrui" class="m-0">
          Proposée par {{ completion.proposition.auteur }} : son auteur en sera averti.
        </p>
        <p v-else-if="estUnBrouillon" class="m-0">
          Elle n’est pas encore envoyée : « Enregistrer les compléments et envoyer » la soumet à
          l’administrateur.
        </p>
        <p v-else class="m-0">
          Elle reste en attente de validation ; vos compléments s’y ajoutent, rien n’est créé en
          double.
        </p>
      </template>
      <div class="mt-1 flex flex-wrap gap-2">
        <BoutonBase variante="secondaire" data-revenir :desactive="envoi" @click="revenirALaSaisie()">
          Revenir à une nouvelle personne
        </BoutonBase>
      </div>
    </section>

    <template v-if="cibleCompletion !== null && completion === null">
      <PanneauBase v-if="lectureCompletion">
        <p class="mb-3 text-sm text-attenue" role="status">Lecture de la proposition…</p>
        <SqueletteChargement :lignes="4" />
      </PanneauBase>
      <MessageErreur
        v-else-if="erreurCompletion !== null"
        :erreur="erreurCompletion"
        titre="La proposition n’a pas pu être lue."
        @action="relireLaProposition"
      />
      <p
        v-else-if="incompletable !== null"
        class="m-0 rounded-panneau border border-ligne bg-panneau px-3.5 py-3 text-sm"
        role="status"
        data-incompletable
      >
        {{ incompletable }}
      </p>
    </template>

    <template v-else>
    <MessageErreur v-if="erreur !== null" :erreur="erreur" class="mb-4" @action="surActionErreur" />

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

    <!-- Un complément : la saisie ira rejoindre la proposition d'un autre membre.
         On n'en dit que ce que la ressemblance a rendu en clair. -->
    <section
      v-if="complementDe !== null"
      class="mb-4 flex flex-col gap-1.5 rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm"
      data-bandeau-complement
    >
      <p class="m-0 font-semibold">
        Vous complétez une proposition déjà en attente<template v-if="complementDe.libelle !== null">
          : {{ complementDe.libelle }}<template v-if="complementDe.auteur !== null">, proposée par {{ complementDe.auteur }}</template></template>.
      </p>
      <p class="m-0">Vos informations seront ajoutées à la proposition existante par l’administrateur.</p>
      <div
        v-if="complementPerime"
        class="mt-1 flex flex-wrap items-center gap-2 rounded-champ border border-alerte-bord bg-alerte-doux px-3 py-2"
        role="alert"
        data-complement-perime
      >
        <span class="mr-auto">
          Cette proposition n’attend plus de validation : votre complément ne peut plus s’y rattacher.
        </span>
        <BoutonBase variante="secondaire" @click="quitterLeComplement">En faire une nouvelle proposition</BoutonBase>
      </div>
      <div v-else class="mt-1">
        <BoutonBase variante="discret" :desactive="envoi" @click="quitterLeComplement">Ne plus la compléter</BoutonBase>
      </div>
    </section>

    <form class="flex flex-col gap-4" @submit.prevent="soumettreLeFormulaire">
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

      <!-- Complétion : là où la saisie disait autre chose que la proposition. La
           proposition est gardée ; le lecteur reprend sa valeur où elle est la bonne. -->
      <PanneauBase
        v-if="completion !== null && completion.divergences.length > 0"
        titre="Votre saisie diffère de la proposition"
        data-divergences
      >
        <div class="flex flex-col gap-2">
          <p class="m-0 text-xs text-attenue">
            La valeur de la proposition est gardée. Prenez la vôtre là où elle est la bonne.
          </p>
          <ul class="m-0 flex list-none flex-col gap-1.5 p-0">
            <li
              v-for="d in completion.divergences"
              :key="d.champ"
              class="flex flex-col gap-1 rounded-champ border border-alerte-bord bg-alerte-doux px-2.5 py-2 text-sm"
              :data-divergence="d.champ"
            >
              <span class="font-semibold">{{ d.etiquette }}</span>
              <span>Proposition : {{ d.proposition }}</span>
              <span>Votre saisie : {{ d.saisie }}</span>
              <span class="flex flex-wrap items-center gap-2">
                <BoutonBase v-if="!reprises.includes(d.champ)" variante="secondaire" :desactive="envoi" @click="prendreMaValeur(d)">
                  Prendre ma valeur
                </BoutonBase>
                <template v-else>
                  <span class="text-xs font-semibold">Votre valeur est reprise.</span>
                  <BoutonBase variante="discret" :desactive="envoi" @click="garderLaProposition(d)">
                    Garder celle de la proposition
                  </BoutonBase>
                </template>
              </span>
            </li>
          </ul>
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
          @completer="(cible) => completerLaProposition(cible, true)"
          @complement="choisirLeComplement"
        />
      </div>

      <!-- Complétion : ce que la proposition porte déjà. On n'y retire rien d'ici —
           retirer un lien, c'est une autre proposition. -->
      <PanneauBase v-if="completion !== null" titre="Déjà dans la proposition" data-deja-proposes>
        <p v-if="liensProposes.length === 0" class="m-0 text-sm text-attenue">
          Elle n’est encore reliée à personne.
        </p>
        <ul v-else class="m-0 flex list-none flex-col gap-1.5 p-0">
          <li
            v-for="lien in liensProposes"
            :key="lien.seq"
            class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm"
          >
            {{ lien.phrase }}
          </li>
        </ul>
      </PanneauBase>

      <!-- Les liens : c'est ce qui rattache la personne à l'arbre, et sans
           quoi elle y flotterait. En complétion, seulement ceux qu'on ajoute. -->
      <BlocLiens
        v-model="saisie"
        :erreurs="erreurs"
        :titre="completion !== null ? 'Liens à ajouter' : ''"
        :deja-designes="dejaDesignes"
        :deja-relies="completion?.proposition.liens.length ?? 0"
        :places-de-parent="placesLibres"
      />

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
              {{
                completion !== null
                  ? 'Cette note remplace celle de la proposition, dont elle reprend le texte : complétez-le.'
                  : 'Cette note reste attachée à la fiche proposée.'
              }}
            </span>
          </label>

          <!-- Une complétion ne porte pas de message (§ 6.6 ter) : ce que
               l'administrateur doit lire va dans la note. -->
          <p v-if="completion !== null" class="m-0 text-xs text-attenue" data-sans-message>
            Une complétion n’envoie pas de message à part : ce que l’administrateur doit savoir va dans
            la note.
          </p>
          <label v-else class="flex flex-col gap-1">
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

      <!-- Complétion : la proposition a changé depuis la lecture. Rien n'est écrit ;
           on relit, à la demande du lecteur — jamais en douce. -->
      <div
        v-if="propositionChangee"
        class="flex flex-wrap items-center gap-2 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
        role="alert"
        data-proposition-changee
      >
        <span class="mr-auto">
          La proposition a changé depuis que vous l’avez ouverte : relisez-la avant d’enregistrer.
          Votre saisie est gardée.
        </span>
        <BoutonBase variante="secondaire" @click="relireLaProposition">Relire la proposition</BoutonBase>
      </div>

      <p
        v-if="completion !== null && completee !== null && photosEnEchecCompletion > 0"
        class="m-0 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
        role="alert"
      >
        Vos compléments sont enregistrés dans la demande n° {{ completee.id }}, mais
        {{ photosEnEchecCompletion > 1 ? `${photosEnEchecCompletion} fichiers n’ont pas pu être envoyés` : 'un fichier n’a pas pu être envoyé' }}.
        <RouterLink :to="{ name: 'demande.detail', params: { id: completee.id } }" class="font-semibold underline">
          Voir la demande sans eux
        </RouterLink>
      </p>

      <div class="flex flex-wrap items-center gap-2">
        <template v-if="completion !== null">
          <BoutonBase type="submit" :chargement="envoi" :desactive="suspendu">{{ libelleEnregistrer }}</BoutonBase>
          <BoutonBase
            v-if="estUnBrouillon && completee === null"
            variante="secondaire"
            :desactive="suspendu || envoi"
            @click="enregistrerLesComplements(false)"
          >
            Enregistrer les compléments
          </BoutonBase>
        </template>
        <BoutonBase v-else-if="demandeDeposee !== null" type="submit" :chargement="envoi" :desactive="suspendu">
          Réessayer l’envoi des fichiers
        </BoutonBase>
        <template v-else>
          <BoutonBase type="submit" :chargement="envoi" :desactive="suspendu">
            {{ complementDe !== null ? 'Envoyer le complément' : 'Envoyer la proposition' }}
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
</template>
