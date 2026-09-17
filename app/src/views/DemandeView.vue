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
 */
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  lireDemande,
  lireDemandeAdministration,
  type DemandeAdministration,
  type DependanceDeDemande,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import DecisionDemande from '@/components/administration/DecisionDemande.vue'
import DiffDemande from '@/components/administration/DiffDemande.vue'
import DoublonsProbables from '@/components/administration/DoublonsProbables.vue'
import FicheProposee from '@/components/administration/FicheProposee.vue'

const route = useRoute()
const { administrateur } = useSession()

const demande = ref<DemandeAdministration | null>(null)
const erreur = ref<unknown>(null)
const chargement = ref(false)

const identifiant = computed(() => Number(route.params['id']))

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

const NATURES: Record<string, string> = {
  modification_personne: 'Modification d’une fiche',
  modification_famille: 'Modification d’une famille',
  modification_citation: 'Modification d’une source',
  creation_personne: 'Ajout d’une personne',
  ajout_lien: 'Ajout d’un lien',
  ajout_media: 'Ajout d’un document',
}

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
  annulee: 'Retirée par son auteur',
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
  resolue: 'validée : la personne existe désormais dans l’arbre',
  en_attente: 'encore en attente — à valider avant celle-ci',
  morte: 'refusée, retirée ou caduque : la personne n’existera jamais',
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

function dateLisible(valeur: string | null | undefined): string {
  if (typeof valeur !== 'string' || valeur.length === 0) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-8">
    <div v-if="chargement && demande === null">
      <PanneauBase>
        <p class="mb-3 text-sm text-attenue" role="status">Chargement de la demande…</p>
        <SqueletteChargement :lignes="4" />
      </PanneauBase>
    </div>

    <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="charger" />

    <template v-else-if="demande !== null">
      <header class="mb-5">
        <div class="flex flex-wrap items-center gap-2">
          <h1 class="text-xl font-semibold tracking-tight">
            {{ NATURES[demande.kind] ?? demande.kind }}
          </h1>
          <PastilleEtat :etat="etatPastille" :libelle="libellePastille" />
        </div>

        <p class="mt-1.5 text-sm text-attenue">
          Demande n° {{ demande.id }}, proposée par {{ demande.authorLabel }}
          <template v-if="demande.submittedAt">
            le {{ dateLisible(demande.submittedAt) }}
          </template>
          <template v-if="demande.targetLabel"> — sur {{ demande.targetLabel }}</template>
        </p>
      </header>

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
        Personne d’autre n’a relu cette modification avant qu’elle soit écrite dans
        l’arbre. C’est tracé au journal.
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
        Elle était en version {{ cible.versionALaSoumission }}, elle est aujourd’hui en version
        {{ cible.version }}. Les valeurs concernées sont signalées ci-dessous.
      </p>

      <PanneauBase v-if="proposeUneParente" :titre="titreDeLaParente" class="mb-4">
        <FicheProposee :brouillons="demande.brouillons" :liens="demande.liens" />
      </PanneauBase>

      <PanneauBase v-if="demande.ops.length > 0" titre="Ce qui changerait" class="mb-4">
        <DiffDemande :ops="demande.ops" />
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
      <DecisionDemande v-if="decidable" :demande="demande" @decidee="charger" />
    </template>
  </div>
</template>
