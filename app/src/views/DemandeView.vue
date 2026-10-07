<script setup lang="ts">
/**
 * Détail d'une demande — § 6.5 pour son auteur, § 6.7 pour l'administration.
 *
 * **Le nom de cette route est cité par le contrat** : les notifications portent
 * `linkRoute: "demande.detail"` avec `linkParams: {id}`. Renommer la route rend
 * muettes les notifications déjà écrites en base — on ajoute, on ne renomme pas.
 *
 * **Deux routes lisent la même demande**, et l'écran choisit selon le lecteur :
 * `GET /api/demandes/{id}` pour son auteur, `GET /api/administration/demandes/{id}`
 * pour l'administration, qui ajoute de quoi décider — `valeurCourante` et
 * `aBouge` par op, le bloc `cible`, la `resolutionPrevue`, les doublons.
 *
 * > **Choisir la route selon le rôle est légitime ; choisir l'affichage selon le
 * > rôle ne l'est pas.**
 *
 * C'est toute la nuance du § 10.4, et elle est facile à rater. Une fois la
 * charge reçue, **rien ici ne teste le rôle du lecteur pour décider quoi
 * dessiner** : chaque bloc s'affiche parce que la charge le porte. Un
 * `v-if="administrateur"` devant un champ que la réponse n'a pas envoyé serait
 * la version front du `if ($estAdmin) { return $tout; }` qu'il a fallu retirer
 * du serveur — et il n'afficherait de toute façon qu'un `undefined`.
 *
 * Le seul `v-if` sur le rôle est celui du **panneau de décision** : valider et
 * refuser sont des actions réservées, pas des champs de données. Et même là, le
 * serveur ne compte pas dessus.
 *
 * Deux statuts qu'il ne faut pas confondre, parce qu'ils appellent deux gestes :
 * une demande **refusée** l'a été par quelqu'un, avec un motif que son auteur
 * peut relire ; une demande **caduque** n'a été refusée par personne — la fiche
 * a changé sous elle (ADR 0004). Le libellé des pastilles vient du socle et ne
 * se réécrit pas ici.
 *
 * ────────────────────────────────────────────────────────────────────
 * **« Voir la fiche »** (`fiche-de-la-demande.ts`). La demande se juge contre la
 * fiche qu'elle touche, et cet écran ne la montrait pas. Le bouton l'ouvre :
 *
 * - **sur téléphone**, en modale plein écran — la fiche en lecture seule, un
 *   lien vers la vraie page, et « × » pour revenir à la demande ;
 * - **sur ordinateur**, l'écran se **partage** : la fiche à gauche, la demande
 *   — « Ce qui changerait » et le reste — à droite. La colonne de la fiche est
 *   collante et défile seule, et s'arrête au-dessus de la barre de décision
 *   fixée en bas, qui reste à portée de main.
 *
 * Les deux présentations sont dans le gabarit (`md:hidden` / `max-md:hidden`)
 * et lisent **le même état** : une seule lecture, et une fenêtre qu'on élargit
 * passe de l'une à l'autre sans rien relire. Le bouton n'existe que si la
 * demande désigne une fiche (`ficheVisee()`) ; une création encore en attente
 * montre celle du parent ou du conjoint auquel elle se rattache.
 *
 * **« Annuler ma demande »** (`retrait.ts`), à droite de la pastille : pour son
 * auteur seulement, tant que le statut le permet. Ce n'est pas un test de rôle
 * sur l'affichage — c'est une **action**, comme valider et refuser, et elle est
 * réservée à l'auteur par le serveur comme elles le sont à l'administration.
 * ────────────────────────────────────────────────────────────────────
 */
import { LIBELLES_NATURE } from '@/components/proposition/mes-demandes'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  lireDemande,
  lireDemandeAdministration,
  listerDemandesAdministration,
  type DemandeAdministration,
  type DependanceDeDemande,
} from '@/lib/api'
import { contexteDepuisRequete, demandeSuivante, natureDeLOnglet, requeteDeContexte } from '@/components/administration/file'
import { annonceEnchainement, issueDepuisRequete, type IssueDecision } from '@/components/administration/decision'
import { legendeDeLaFiche, useFicheDeLaDemande } from '@/components/administration/fiche-de-la-demande'
import { peutRetirer, useRetraitDeDemande } from '@/components/proposition/retrait'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import DialogueConfirmation from '@/components/base/DialogueConfirmation.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import DecisionDemande from '@/components/administration/DecisionDemande.vue'
import DiffDemande from '@/components/administration/DiffDemande.vue'
import DoublonsProbables from '@/components/administration/DoublonsProbables.vue'
import FicheDeLaDemande from '@/components/administration/FicheDeLaDemande.vue'
import FicheProposee from '@/components/administration/FicheProposee.vue'
import LoupeMedia from '@/components/medias/LoupeMedia.vue'
import type { CibleLoupe } from '@/components/medias/medias'

const route = useRoute()
const { administrateur } = useSession()

const demande = ref<DemandeAdministration | null>(null)
const erreur = ref<unknown>(null)
const chargement = ref(false)

const identifiant = computed(() => Number(route.params['id']))

/** Le document d'un `ajout_media` en grand. Tenu ici, et non dans le diff : une
 *  seule image d'origine décodée à la fois, quel que soit le nombre de pièces
 *  que la demande propose. */
const loupe = ref<CibleLoupe | null>(null)

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    // Le rôle choisit la **route**, pas l'affichage : l'administration a droit à
    // une charge plus riche, et c'est le serveur qui en décide.
    demande.value = administrateur.value
      ? await lireDemandeAdministration(identifiant.value)
      : await lireDemande(identifiant.value)
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

watch(identifiant, charger, { immediate: true })

/* ------------------------------------------------------------------ */
/* « Valider & suivant », « Refuser & suivant »                        */
/* ------------------------------------------------------------------ */

const routeur = useRouter()

/** La file d'où l'on vient — filtre et tri —, portée par l'adresse. */
const contexte = computed(() => contexteDepuisRequete(route.query))

/** « Demande n° 12 validée. Voici la suivante », si l'on arrive par un enchaînement. */
const annonceDeLaPrecedente = computed(() => {
  const precedente = Number(route.query['precedente'])
  const issue = issueDepuisRequete(route.query['issue'])
  return Number.isInteger(precedente) && precedente > 0 && issue !== null
    ? annonceEnchainement(precedente, issue, false)
    : null
})

/**
 * Après une décision. Sans enchaînement, on relit la demande. Avec, on relit la
 * file **avec le même filtre** — la décision a pu en rendre d'autres caduques —
 * et on ouvre la suivante dans l'ordre affiché ; s'il n'y en a plus, retour à
 * la file, qui le dit.
 */
async function apresDecision(enchainement?: IssueDecision): Promise<void> {
  const courante = demande.value
  if (enchainement === undefined || courante === null) {
    await charger()
    return
  }

  const { etat, auteur, tri, onglet, recherche } = contexte.value
  const requete = requeteDeContexte(contexte.value)
  try {
    const file = await listerDemandesAdministration({
      status: etat === '' ? undefined : etat,
      auteur: auteur === 0 ? undefined : auteur,
      kind: natureDeLOnglet(onglet),
      q: recherche,
      parPage: 100,
    })
    const suivante = demandeSuivante(file.elements, courante, tri)
    const trace = { precedente: String(courante.id), issue: enchainement }

    // En fin de file : retour à « À traiter » quand on venait de la file par
    // défaut (les demandes en attente), à l'historique filtré sinon.
    const finDeFile =
      Object.keys(requete).length === 0
        ? { name: 'administration.a-traiter', query: { type: 'demande', ...trace } }
        : { name: 'administration.demandes', query: { ...requete, ...trace } }
    await (suivante === null
      ? routeur.push(finDeFile)
      : routeur.push({ name: 'demande.detail', params: { id: suivante }, query: { ...requete, ...trace } }))
  } catch (cause) {
    // La décision est partie ; seule la relecture de la file a échoué. On
    // reste sur la demande, relue, et l'erreur se voit.
    await charger()
    erreur.value = cause
  }
}

/** Les libellés de nature, partagés avec « Mes demandes » : une seule table, complète
 *  par construction (`Record<NatureDemande, string>`). */
const NATURES: Record<string, string> = LIBELLES_NATURE

/** Les statuts que `PastilleEtat` connaît ; les autres passent en neutre avec
 *  leur libellé propre. La **valeur** reste celle du modèle dans tous les cas. */
const PASTILLES = ['en_attente', 'validee', 'refusee', 'caduque'] as const
type EtatPastille = (typeof PASTILLES)[number] | 'neutre'

const etatPastille = computed<EtatPastille>(() => {
  const statut = demande.value?.status
  return statut !== undefined && (PASTILLES as readonly string[]).includes(statut)
    ? (statut as EtatPastille)
    : 'neutre'
})

const LIBELLES_HORS_PASTILLE: Record<string, string> = {
  brouillon: 'Brouillon',
  annulee: 'Annulée par son auteur',
}

const libellePastille = computed(() =>
  etatPastille.value === 'neutre' ? (LIBELLES_HORS_PASTILLE[demande.value?.status ?? ''] ?? '') : '',
)

/**
 * Le bloc des fiches et des liens proposés.
 *
 * > **La condition porte sur les liens autant que sur les fiches.**
 *
 * Elle ne regardait que `brouillons`, et une demande `ajout_lien` — « ils étaient frère
 * et sœur », « elle s'est remariée avec lui » — n'en porte aucun : elle décrit des liens
 * entre deux personnes qui **existent déjà**. Elle n'a pas non plus d'`ops`. Cet écran
 * lui rendait donc **aucun bloc de contenu** : un en-tête, un message, et deux boutons de
 * décision sur du vide. Constaté en relisant la condition, pas par un test — c'est le
 * genre de page qu'on ne visite qu'une fois la nature déjà déposée.
 */
const proposeUneParente = computed(
  () =>
    demande.value !== null &&
    (demande.value.brouillons.length > 0 || demande.value.liens.length > 0),
)

/**
 * Le titre du bloc, accordé sur ce que la demande porte réellement.
 *
 * Trois cas, et aucun n'est décoratif. **Zéro fiche** : un `ajout_lien`, dont le
 * bloc ne contient que des liens — le titrer « Lien proposé » juste au-dessus du
 * sous-titre « Liens proposés » se répétait bêtement. **Une** fiche : le cas
 * d'hier, et le singulier était juste. **Plusieurs** : le cas d'aujourd'hui —
 * une branche entière se dépose en un seul geste, et annoncer « Personne
 * proposée » au-dessus de vingt-trois fiches sous-entend qu'on n'en juge qu'une.
 */
const titreDeLaParente = computed(() => {
  const fiches = demande.value?.brouillons.length ?? 0

  if (fiches === 0) return 'Ce qui est proposé'

  return fiches === 1 ? 'Personne proposée' : `${fiches} personnes proposées`
})
const doublons = computed(() => demande.value?.doublonsProbables ?? [])
const concurrentes = computed(() => demande.value?.demandesConcurrentes ?? [])
/**
 * Les fiches proposées **par d'autres demandes** que celle-ci désigne (§ 6.7).
 *
 * Elles sont affichées **toutes**, résolues comprises : « s'appuie sur la
 * demande n° 12, validée » est un contexte utile à qui décide, et le taire
 * laisserait croire qu'une demande à l'arrêt l'est sans raison. C'est
 * `resolutionPrevue` qui dit ce que le clic fera, pas cette liste.
 */
const dependances = computed(() => demande.value?.dependances ?? [])

/** Ce que chaque état veut dire, en une phrase qui tient sur la ligne. */
const ETATS_DE_DEPENDANCE: Record<DependanceDeDemande['etat'], string> = {
  resolue: 'validée : la personne est dans l’arbre',
  en_attente: 'en attente : à valider avant celle-ci',
  morte: 'refusée ou annulée : la personne ne sera pas créée',
  disparue: 'la fiche proposée n’existe plus',
}
/** Présent uniquement sur la charge d'administration : on teste la clé. */
const cible = computed(() => demande.value?.cible ?? null)
/**
 * Le panneau de décision n'apparaît **que** sur une demande `en_attente`.
 *
 * Le test sur le statut n'est pas une simple politesse d'affichage, et il est
 * plus important qu'il n'en a l'air : vérifié sur l'API, une demande déjà
 * validée continue de porter un `resolutionPrevue` — valant `"caducite"`, parce
 * que la validation a elle-même déplacé la valeur que l'op remplaçait. Un écran
 * qui afficherait la résolution sans regarder le statut annoncerait donc
 * « cette demande est devenue caduque » à propos d'une demande qu'il vient de
 * valider avec succès.
 */
const decidable = computed(
  () => administrateur.value && demande.value !== null && demande.value.status === 'en_attente',
)

/* ------------------------------------------------------------------ */
/* « Voir la fiche »                                                   */
/* ------------------------------------------------------------------ */

const ficheDeLaDemande = useFicheDeLaDemande(demande)
const {
  visee: ficheVisee,
  ouverte: ficheOuverte,
  fiche,
  chargement: chargementFiche,
  erreur: erreurFiche,
} = ficheDeLaDemande

/** Ouverte **et** désignée : une demande sans fiche ne garde pas un partage vide. */
const ficheAffichee = computed(() => ficheOuverte.value && ficheVisee.value !== null)
const legende = computed(() => (ficheVisee.value === null ? '' : legendeDeLaFiche(ficheVisee.value.raison)))

/** Échap ferme la modale du téléphone — et le partage, qui est le même état. Un
 *  dialogue ouvert par-dessus (confirmation, loupe) prend Échap pour lui. */
function auClavier(evenement: KeyboardEvent): void {
  if (evenement.key !== 'Escape' || !ficheAffichee.value) return
  if (loupe.value !== null || retrait.confirmation.value !== null) return
  ficheDeLaDemande.fermer()
}
watch(ficheAffichee, (affichee) => {
  if (affichee) window.addEventListener('keydown', auClavier)
  else window.removeEventListener('keydown', auClavier)
})
onBeforeUnmount(() => window.removeEventListener('keydown', auClavier))

/* ------------------------------------------------------------------ */
/* « Annuler ma demande »                                              */
/* ------------------------------------------------------------------ */

/**
 * Réussi : le serveur rend la demande, on en reprend le statut — sans relire,
 * et sans perdre les blocs que seule la charge d'administration porte. Échoué
 * (`409 demande_deja_decidee`, le plus souvent) : on relit, pour que la
 * pastille dise ce qui s'est réellement passé.
 */
const retrait = useRetraitDeDemande(async (_id, retiree) => {
  if (retiree !== null && demande.value !== null) {
    demande.value = { ...demande.value, status: retiree.status }
    return
  }
  await charger()
})
const { confirmation, envoi: retraitEnCours, erreur: erreurRetrait, suspendu, infobulle, lecteurId } = retrait

const retirable = computed(() => demande.value !== null && peutRetirer(demande.value, lecteurId.value))

// Une autre demande (« & suivant », lien d'une dépendance) : l'échec de la
// précédente n'a plus rien à faire à l'écran.
watch(identifiant, () => {
  erreurRetrait.value = null
})

function dateLisible(valeur: string | null | undefined): string {
  if (typeof valeur !== 'string' || valeur.length === 0) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <!-- `overflow-wrap: anywhere` : une adresse web ou une cote sans espace
       (« https://…/deaths-f-1930-1934.pdf#page=12 ») se coupe au lieu d'élargir
       la page, qui débordait alors de l'écran sur mobile. -->
  <!-- Partagé, l'écran s'élargit (`max-w-6xl`) : deux colonnes de la largeur
       d'une seule auraient tassé la demande comme la fiche. -->
  <div
    class="mx-auto w-full min-w-0 px-4 py-8 [overflow-wrap:anywhere]"
    :class="ficheAffichee ? 'max-w-3xl md:max-w-6xl' : 'max-w-3xl'"
  >
    <div v-if="chargement && demande === null">
      <PanneauBase>
        <p class="mb-3 text-sm text-attenue" role="status">Chargement de la demande…</p>
        <SqueletteChargement :lignes="4" />
      </PanneauBase>
    </div>

    <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="charger" />

    <template v-else-if="demande !== null">
      <p
        v-if="annonceDeLaPrecedente !== null"
        class="mb-4 rounded-panneau border border-ok-bord bg-ok-doux px-3.5 py-3 text-sm"
        role="status"
      >
        {{ annonceDeLaPrecedente }}
      </p>

      <header class="mb-5">
        <div class="flex flex-wrap items-center gap-2">
          <h1 class="text-xl font-semibold tracking-tight">
            {{ NATURES[demande.kind] ?? demande.kind }}
          </h1>
          <PastilleEtat :etat="etatPastille" :libelle="libellePastille" />
          <!-- Une action de l'auteur, pas un champ : éteinte, et non cachée,
               sous la vue empruntée — le motif est dans l'infobulle. -->
          <BoutonBase
            v-if="retirable"
            variante="danger"
            data-retirer
            class="px-2.5 py-1 text-[13px]"
            :desactive="suspendu"
            :chargement="retraitEnCours === demande.id"
            :title="infobulle"
            @click="retrait.demander(demande)"
          >
            Annuler ma demande
          </BoutonBase>
        </div>

        <p class="mt-1.5 text-sm text-attenue">
          Demande n° {{ demande.id }}, proposée par {{ demande.authorLabel }}
          <template v-if="demande.submittedAt">
            le {{ dateLisible(demande.submittedAt) }}
          </template>
          <template v-if="demande.targetLabel"> — sur {{ demande.targetLabel }}</template>
        </p>

        <!-- Sur téléphone, il ouvre la modale ; sur ordinateur, il ouvre et
             ferme le partage — d'où l'état annoncé (`aria-expanded`). -->
        <BoutonBase
          v-if="ficheVisee !== null"
          variante="secondaire"
          data-voir-fiche
          class="mt-3"
          :aria-expanded="ficheAffichee"
          @click="ficheDeLaDemande.basculer()"
        >
          <svg viewBox="0 0 24 24" class="size-4 fill-none stroke-current stroke-2" aria-hidden="true">
            <rect x="3.5" y="4.5" width="17" height="15" rx="2" />
            <path d="M10 4.5v15" />
          </svg>
          <span class="md:hidden">Voir la fiche</span>
          <span class="max-md:hidden">{{ ficheAffichee ? 'Masquer la fiche' : 'Voir la fiche' }}</span>
        </BoutonBase>
      </header>

      <MessageErreur
        v-if="erreurRetrait !== null && erreurRetrait.id === demande.id"
        class="mb-4"
        :erreur="erreurRetrait.cause"
        titre="Votre demande n’a pas pu être annulée."
        @action="charger"
      />

      <div
        :class="
          ficheAffichee ? 'md:grid md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] md:items-start md:gap-6' : ''
        "
      >
        <!-- Ordinateur : la fiche à gauche, collante, qui défile seule. Sa
             hauteur s'arrête au-dessus de la barre de décision fixée en bas
             (h-24 réservés par `DecisionDemande`) quand elle est là. -->
        <aside
          v-if="ficheAffichee"
          data-fiche-partagee
          class="max-md:hidden md:sticky md:top-[72px] md:overflow-y-auto md:overscroll-contain md:pr-1"
          :class="decidable ? 'md:max-h-[calc(100dvh-72px-7rem)]' : 'md:max-h-[calc(100dvh-72px-1rem)]'"
          aria-label="Fiche de la personne"
        >
          <FicheDeLaDemande
            :fiche="fiche"
            :legende="legende"
            :chargement="chargementFiche"
            :erreur="erreurFiche"
            @fermer="ficheDeLaDemande.fermer()"
            @recharger="ficheDeLaDemande.charger()"
            @agrandir="loupe = $event"
          />
        </aside>

        <!-- « Ce qui changerait », et tout ce que la demande porte. -->
        <div class="min-w-0">

      <!-- « Une écriture qu'aucun humain n'a réexaminée doit se voir » (§ 2.7).
           `autoValidated` couvre les deux cas où cela arrive : valider sa propre
           demande, et éditer directement (§ 6.1). Vérifié sur l'API : valider sa
           propre demande pose bien le drapeau. Sans cette ligne, rien à l'écran
           ne distingue une décision prise à deux d'une décision prise seul. -->
      <p
        v-if="demande.autoValidated"
        class="mb-4 rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
        role="status"
      >
        <strong class="font-semibold">Validée par son propre auteur.</strong>
        Personne d’autre ne l’a relue.
      </p>

      <!-- Le mot du demandeur. Souvent la pièce qui emporte la décision. -->
      <PanneauBase v-if="demande.message" titre="Ce que dit l’auteur" class="mb-4">
        <p class="text-sm whitespace-pre-line">{{ demande.message }}</p>
      </PanneauBase>

      <!-- Le bandeau du § 9.6 : la fiche a changé depuis le dépôt. Il ne
           s'affiche que si la charge porte le bloc `cible` — l'auteur ne le
           reçoit pas, et on ne l'invente pas pour lui. -->
      <p
        v-if="cible !== null && cible.aChangeDepuisSoumission"
        class="mb-4 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
        role="status"
      >
        <strong class="font-semibold">Cette fiche a changé depuis le dépôt de la demande.</strong>
        Les valeurs concernées sont signalées ci-dessous.
      </p>

      <PanneauBase v-if="proposeUneParente" :titre="titreDeLaParente" class="mb-4">
        <FicheProposee :brouillons="demande.brouillons" :liens="demande.liens" />
      </PanneauBase>

      <PanneauBase v-if="demande.ops.length > 0" titre="Ce qui changerait" class="mb-4">
        <DiffDemande :ops="demande.ops" @agrandir="loupe = $event" />
      </PanneauBase>

      <PanneauBase v-if="doublons.length > 0" titre="Doublons probables" class="mb-4">
        <DoublonsProbables :doublons="doublons" />
      </PanneauBase>

      <!-- Sur quoi cette demande s'appuie. Sans ce bloc, une demande
           « à valider après l'autre » n'indiquerait pas **laquelle**, et
           l'administrateur devrait la chercher dans la file. -->
      <PanneauBase v-if="dependances.length > 0" titre="Cette demande s’appuie sur" class="mb-4">
        <ul class="flex flex-col gap-1.5 text-sm">
          <li
            v-for="dependance in dependances"
            :key="dependance.draftPersonId"
            class="flex flex-wrap gap-x-2"
          >
            <span class="font-semibold">
              {{ dependance.libelle.length > 0 ? dependance.libelle : 'une personne proposée' }}
            </span>
            <RouterLink
              v-if="dependance.demandeId !== null"
              :to="{ name: 'demande.detail', params: { id: dependance.demandeId } }"
              class="text-accent underline"
            >
              demande n° {{ dependance.demandeId }}
            </RouterLink>
            <span class="text-attenue">— {{ ETATS_DE_DEPENDANCE[dependance.etat] }}</span>
          </li>
        </ul>
      </PanneauBase>

      <!-- Les autres demandes en attente sur la même cible : décider de
           celle-ci sans les voir, c'est se préparer une caducité. -->
      <PanneauBase v-if="concurrentes.length > 0" titre="Autres demandes en attente" class="mb-4">
        <ul class="flex flex-col gap-1.5 text-sm">
          <li v-for="autre in concurrentes" :key="autre.id" class="flex flex-wrap gap-x-2">
            <RouterLink
              :to="{ name: 'demande.detail', params: { id: autre.id } }"
              class="font-semibold text-accent underline"
            >
              Demande n° {{ autre.id }}
            </RouterLink>
            <span class="text-attenue">
              par {{ autre.authorLabel }} — touche {{ autre.champs.join(', ') }}
            </span>
          </li>
        </ul>
      </PanneauBase>

      <!-- Une demande refusée reste consultable **avec son motif** : c'est ce
           qui la distingue d'une contribution perdue (§ 6.3 du dossier). -->
      <PanneauBase v-if="demande.decisionMessage" titre="Motif de la décision" class="mb-4">
        <p class="text-sm whitespace-pre-line">{{ demande.decisionMessage }}</p>
        <p v-if="demande.decidedByLabel" class="mt-2 text-xs text-attenue">
          {{ demande.decidedByLabel }}, le {{ dateLisible(demande.decidedAt) }}
        </p>
      </PanneauBase>

      <!-- Ce que la validation a réellement produit. -->
      <PanneauBase v-if="demande.revisions.length > 0" titre="Ce qui a été écrit" class="mb-4">
        <ul class="flex flex-col gap-1 text-sm">
          <li v-for="revision in demande.revisions" :key="revision.id">
            {{ revision.operation }} — {{ revision.subjectLabel ?? revision.subjectKind }}
            <span v-if="revision.versionAfter !== null && revision.versionAfter !== undefined" class="text-attenue">
              (version {{ revision.versionBefore }} → {{ revision.versionAfter }})
            </span>
          </li>
        </ul>
      </PanneauBase>

      <!-- Valider et refuser sont des **actions** réservées, pas des champs de
           données : c'est le seul endroit de cet écran où le rôle décide. -->
      <!-- `:key` : « & suivant » change de demande sans quitter l'écran ; sans
           clé, le motif et l'annonce de la précédente resteraient affichés. -->
      <DecisionDemande v-if="decidable" :key="demande.id" :demande="demande" @decidee="apresDecision" />
        </div>
      </div>

      <!-- Téléphone : la même fiche, en plein écran, par-dessus la barre du
           bas et la barre de décision (`z-40`, sous la loupe en `z-50`). -->
      <div
        v-if="ficheAffichee"
        data-fiche-modale
        class="fixed inset-0 z-40 overflow-y-auto overscroll-contain bg-fond px-4 pt-[max(16px,env(safe-area-inset-top))] pb-[max(16px,env(safe-area-inset-bottom))] md:hidden"
        role="dialog"
        aria-modal="true"
        aria-label="Fiche de la personne"
      >
        <FicheDeLaDemande
          :fiche="fiche"
          :legende="legende"
          :chargement="chargementFiche"
          :erreur="erreurFiche"
          @fermer="ficheDeLaDemande.fermer()"
          @recharger="ficheDeLaDemande.charger()"
          @agrandir="loupe = $event"
        />
      </div>
    </template>
  </div>

  <DialogueConfirmation
    :demande="confirmation"
    :chargement="retraitEnCours !== null"
    @confirmer="retrait.confirmer"
    @annuler="retrait.abandonner"
  />

  <LoupeMedia :cible="loupe" @fermer="loupe = null" />
</template>
