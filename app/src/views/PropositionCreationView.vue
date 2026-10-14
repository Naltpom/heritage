<script setup lang="ts">
/**
 * **Proposer l'ajout d'une personne** — § 6.3, § 6.4 du dossier.
 *
 * Dans les mots du commanditaire : *« permettre aux gens de proposer de
 * nouvelles personnes à ajouter, on a l'edit et **sur ce même principe**
 * permettre d'ajouter une personne ⇒ on sélectionne les parents et/ou enfant
 * et/ou conjoints (date de mariage si on a, ou de rupture, etc.) »*.
 *
 * **« Sur ce même principe » est l'instruction centrale**, et elle décide de
 * l'architecture bien plus que de l'écran : ce formulaire ne crée rien. Il
 * dépose une `ChangeRequest` de nature `creation_personne`, sur la même route,
 * dans la même file, avec le même message obligatoire au refus. « Un seul
 * mécanisme, pas deux » (ADR 0004) — et c'est pourquoi l'identité se saisit ici
 * avec le **même composant** que sur l'écran de modification.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ce que cet écran ne fait pas, et pourquoi.**
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
 * ────────────────────────────────────────────────────────────────────
 *
 * Après le dépôt, l'auteur est renvoyé vers le suivi de sa demande : c'est là
 * que se lira la décision, et **le motif s'il y a refus** — le commanditaire l'a
 * demandé explicitement, et le § 6.9 rend ce message obligatoire côté
 * administrateur.
 *
 * ────────────────────────────────────────────────────────────────────
 * **L'assistant IA est la porte d'entrée, le formulaire est caché.** La
 * famille propose surtout depuis un téléphone, et raconter « mon arrière-
 * grand-père s'appelait… » en joignant une photo d'acte y est plus naturel
 * qu'un formulaire à vingt champs (décision du 30/09/2026). L'assistant laisse
 * écrire librement, puis fait relire les demandes qu'il prépare
 * (`components/proposition/assistant/`).
 *
 * Le formulaire reste servi dans trois cas :
 *  - `?mode=formulaire` : c'est le bouton « Ajouter une personne » de l'accueil
 *    (05/10/2026 — « met moi plutôt ajouter une personne, et c'est une page
 *    formulaire ») ;
 *  - une reprise (`?refaire=`) : c'est lui qui sait reprendre une demande ;
 *  - la bascule « voir comme un membre » : l'assistant n'écrit rien lui-même,
 *    mais son analyse est une écriture pour l'API (quota, journal), qui la
 *    refuserait en `409`.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { creerDemande, invaliderGraphe, televerserMedia, type OrigineReprise } from '@/lib/api'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import AssistantProposition from '@/components/proposition/assistant/AssistantProposition.vue'
import QuestionsAssistant from '@/components/proposition/assistant/QuestionsAssistant.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
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

const route = useRoute()
const routeur = useRouter()
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
const demandeDeposee = ref<number | null>(null)

onBeforeUnmount(() => {
  for (const photo of photos.value) if (photo.apercu) URL.revokeObjectURL(photo.apercu)
})

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

/* --- Le mode : l'assistant, sauf reprise ou vue empruntée ------------ */

/**
 * `question` : « Poser une question » — l'assistant en **lecture seule**, une
 * conversation sur l'arbre (`?mode=question`). Il vit ici, à côté de la
 * proposition, parce que c'est le même assistant et la même porte d'entrée : on
 * demande « qui était le père de Juliette ? », on apprend qu'il manque, on le
 * propose — un onglet plus loin.
 *
 * Sous la bascule « voir comme un membre », il disparaît avec l'assistant : une
 * question écrit une conversation et consomme le quota, et l'API la refuserait
 * en `409`.
 */
type Mode = 'formulaire' | 'assistant' | 'question'

function premiereValeur(brut: unknown): string | undefined {
  const valeur = Array.isArray(brut) ? brut[0] : brut
  return typeof valeur === 'string' ? valeur : undefined
}

const mode = computed<Mode>(() => {
  if (suspendu.value || repriseDemandee.value !== null) return 'formulaire'
  const demande = premiereValeur(route.query['mode'])
  if (demande === 'formulaire') return 'formulaire'
  return demande === 'question' ? 'question' : 'assistant'
})

/** La fiche d'où l'on vient, passée à l'assistant comme contexte. */
const personneDeContexte = computed(() => {
  const valeur = Number(premiereValeur(route.query['personne']))
  return Number.isInteger(valeur) && valeur > 0 ? valeur : null
})

/** Le lien de l'onglet « Proposer » garde la fiche d'où l'on venait. */
const modeAssistant = computed(() =>
  personneDeContexte.value === null ? {} : { personne: String(personneDeContexte.value) },
)

const repriseDemandee = computed(() => {
  const brut = route.query['refaire']
  const valeur = Number(Array.isArray(brut) ? brut[0] : brut)
  return Number.isInteger(valeur) && valeur > 0 ? valeur : null
})

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
  chargement.value = true
  erreur.value = null
  try {
    const reprise = await chargerReprise(demandeId)
    if (reprise === null || reprise.kind !== 'creation_personne') return
    saisie.value = reprise.saisie
    message.value = reprise.message
    origine.value = reprise.origine
    avertissements.value = reprise.avertissements
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

const demandeAReprendre = repriseDemandee.value
if (demandeAReprendre !== null) void reprendre(demandeAReprendre)

/* --- Le dépôt ----------------------------------------------------- */

async function deposer(soumettre: boolean): Promise<void> {
  if (envoi.value) return
  envoi.value = true
  erreur.value = null
  conflit.value = null
  erreurs.value = {}

  try {
    // La demande est déjà là : seules des photos restaient à envoyer.
    if (demandeDeposee.value !== null) {
      await terminer(demandeDeposee.value)
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

    demandeDeposee.value = demande.id
    await terminer(demande.id)
  } catch (cause) {
    const reconnu = conflitDeDemande(cause)
    if (reconnu !== null) conflit.value = reconnu
    else erreur.value = cause
  } finally {
    envoi.value = false
  }
}

/** Envoie les photos, puis mène au suivi de la demande — sauf si l'une a échoué :
 *  on reste alors ici, la photo dit pourquoi, et « Réessayer » la renvoie. */
async function terminer(demandeId: number): Promise<void> {
  const echecs = await envoyerLesPhotos(photos.value, demandeId, televerserMedia)
  if (echecs === 0) await routeur.push({ name: 'demande.detail', params: { id: demandeId } })
}

const photosEnEchec = computed(() =>
  demandeDeposee.value === null ? 0 : photosAEnvoyer(photos.value).length,
)

function refaire(demandeId: number | null): void {
  if (demandeId === null) return
  void routeur.push({ name: 'proposition.creation', query: { refaire: String(demandeId) } })
}
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-8">
    <template v-if="mode === 'assistant' || mode === 'question'">
      <!-- Deux modes du même assistant. Des liens et non des boutons : chaque mode
           a son adresse, qu'on met en favori ou qu'on partage. -->
      <nav aria-label="Assistant" class="mb-4 flex gap-1 rounded-panneau border border-ligne bg-panneau p-1">
        <RouterLink
          :to="{ query: modeAssistant }"
          class="flex min-h-11 flex-1 items-center justify-center rounded-champ px-3 text-sm font-semibold md:min-h-9"
          :class="mode === 'assistant' ? 'bg-accent text-fond' : 'text-attenue hover:text-texte'"
          :aria-current="mode === 'assistant' ? 'page' : undefined"
        >
          Proposer
        </RouterLink>
        <RouterLink
          :to="{ query: { mode: 'question' } }"
          class="flex min-h-11 flex-1 items-center justify-center rounded-champ px-3 text-sm font-semibold md:min-h-9"
          :class="mode === 'question' ? 'bg-accent text-fond' : 'text-attenue hover:text-texte'"
          :aria-current="mode === 'question' ? 'page' : undefined"
        >
          Poser une question
        </RouterLink>
      </nav>

      <template v-if="mode === 'assistant'">
        <header class="mb-5">
          <h1 class="text-xl font-semibold tracking-tight">Proposer avec l’assistant</h1>
          <p class="mt-1.5 text-sm text-attenue">
            L’assistant prépare les demandes, vous relisez, un administrateur valide.
          </p>
        </header>

        <AssistantProposition :person-id="personneDeContexte" />
      </template>

      <template v-else>
        <header class="mb-5">
          <h1 class="text-xl font-semibold tracking-tight">Poser une question</h1>
          <p class="mt-1.5 text-sm text-attenue">
            L’assistant répond à partir des fiches, en les citant, et garde le fil.
          </p>
        </header>

        <QuestionsAssistant />
      </template>
    </template>

    <template v-else>
    <header class="mb-5">
      <h1 class="text-xl font-semibold tracking-tight">Ajouter une personne</h1>
      <p class="mt-1.5 text-sm text-attenue">
        Votre proposition part en validation, comme une correction de fiche. La personne
        apparaîtra dans votre arbre en attendant, signalée comme non validée — et pour vous seul.
        Vous pouvez la relier aux personnes de l’arbre comme à celles que vous avez déjà
        proposées, même pas encore validées.
      </p>
    </header>

    <p
      v-if="motifSuspendu !== null"
      class="mb-4 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
      role="status"
    >
      {{ motifSuspendu }}
    </p>

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
        @refaire="refaire"
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
          <RouterLink :to="{ name: 'arbre' }" class="text-sm text-attenue underline">
            Revenir à l’arbre
          </RouterLink>
        </div>
      </form>
    </template>
    </template>
  </div>
</template>
