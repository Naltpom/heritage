<script lang="ts">
/**
 * Les deux décisions de cet écran qui se prouvent **sans navigateur**.
 *
 * Elles sont dans un bloc `<script>` ordinaire, et non dans le `<script setup>`
 * ci-dessous, pour la raison mécanique qu'expliquent `ArbreView` et
 * `AnniversairesView` : un `<script setup>` se compile en une fermeture sur
 * l'état du composant, que rien ne peut appeler du dehors. Or ce que ces deux
 * fonctions décident ne se voit pas en lisant le gabarit, et se casse sans
 * bruit. Leur suite est `questions-rendu.test.ts`.
 */
import type { Question } from '@/lib/api'

export interface EtatDeLEcran {
  /** Ce que le corps de l'écran montre. */
  corps: 'squelette' | 'panne' | 'vide' | 'contenu'
  /** Un bandeau d'erreur d'une ligne, **au-dessus** d'un contenu qui reste. */
  bandeau: boolean
}

/**
 * Ce que l'écran montre, selon ce qu'il a et ce qui a échoué.
 *
 * ────────────────────────────────────────────────────────────────────
 * **DES QUESTIONS DÉJÀ AFFICHÉES NE SONT JAMAIS REMPLACÉES PAR UNE ERREUR.**
 *
 * C'est la règle de `ChronologieView` et d'`AnniversairesView`, et elle compte
 * davantage ici : le membre a peut-être **déjà tapé une réponse** dans l'un des
 * champs. Effacer la liste pour y mettre une alerte lui ferait perdre son
 * texte — pas un affichage, son travail. L'erreur est donc dite sur une ligne,
 * au-dessus, et les cartes restent.
 *
 * Symétriquement, une erreur **sans rien en main** remplace bien l'écran : il
 * n'y a rien à préserver.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Une liste vide n'est pas une panne, et c'est le cas le plus fréquent après
 * quelques semaines.** Zéro question veut dire « nous n'avons rien à vous
 * demander pour l'instant » — un état normal et même désirable. Le confondre
 * avec un squelette qui tourne pour toujours, ou avec une erreur, ferait
 * conclure à un écran cassé.
 */
export function etatDeLEcran(questions: Question[] | null, erreur: unknown): EtatDeLEcran {
  const enPanne = erreur !== null && erreur !== undefined

  if (questions === null) return { corps: enPanne ? 'panne' : 'squelette', bandeau: false }
  if (questions.length === 0) return { corps: enPanne ? 'panne' : 'vide', bandeau: false }

  return { corps: 'contenu', bandeau: enPanne }
}

/**
 * Ce que dit l'en-tête sur l'origine des questions, ou `null` s'il n'y a rien
 * à dire.
 *
 * **La mention s'affiche une fois, en tête — jamais sous chaque question.**
 * Répétée vingt fois, elle cesse d'être lue, et c'est précisément la mention
 * qu'on ne veut pas voir devenir du décor : l'ADR 0008 la décide parce qu'« un
 * membre qui découvre par hasard que ses données familiales sont passées chez
 * un tiers le vit autrement que celui à qui on l'a dit ».
 *
 * Elle dit **combien**, et non « certaines » : sur vingt questions, savoir
 * qu'il y en a deux ou dix-neuf n'est pas la même information.
 */
export function mentionDuModele(total: number, redigeesParModele: number): string | null {
  if (redigeesParModele <= 0) return null
  if (redigeesParModele >= total) return 'Ces questions ont été rédigées par l’IA.'

  return `${redigeesParModele} de ces questions sur ${total} ont été rédigées par l’IA.`
}
</script>

<script setup lang="ts">
/**
 * Questions — les trous de l'arbre que **vous** pourriez combler (ADR 0008).
 *
 * ────────────────────────────────────────────────────────────────────
 * CE QUI SE DÉCIDE ICI, ET NULLE PART AILLEURS :
 *
 * 1. **Un seul appel, au montage.** `GET /api/questions` rejoue la détection
 *    côté serveur et crée les lignes manquantes — voir l'en-tête de
 *    `lib/api/questions.ts`. L'appel est idempotent, mais il **écrit** : on ne
 *    le déclenche donc ni sur un filtre, ni sur un défilement, ni en rafale.
 *
 * 2. **Une carte répondue disparaît de la liste, tout de suite.** Le serveur
 *    ne la servirait plus au prochain chargement ; la laisser à l'écran ferait
 *    croire que rien n'a été pris. On ne recharge pas la page pour autant : un
 *    second `GET` ferait perdre les réponses en cours de frappe dans les
 *    autres cartes.
 *
 * 3. **La photo se téléverse AVANT la réponse**, en deux appels. `POST
 *    /api/medias` rend un média `en_attente`, puis son identifiant accompagne
 *    la réponse. C'est le circuit ordinaire du module `Media` : aucun octet ne
 *    passe par les routes de cette page.
 *
 * 4. **`capture` sur l'entrée de fichier.** Rien de tel n'existe ailleurs dans
 *    ce front — c'est un attribut neuf, pas une adaptation. Il demande à un
 *    mobile d'ouvrir l'appareil photo plutôt que la galerie, ce qui est le
 *    geste attendu quand on a l'acte ou la photo **sous les yeux**. Il est
 *    ignoré sans dommage sur un poste fixe, où il n'y a pas d'appareil.
 *
 *    Attention au piège, et c'est pourquoi la valeur n'est pas `"camera"` :
 *    `capture` n'accepte que `user` (caméra avant) et `environment` (caméra
 *    arrière). Une valeur inconnue **reste valide en HTML** et se comporte
 *    comme `capture` nu — le navigateur choisit, et il choisit souvent mal.
 *    `environment` est le bon défaut ici : on photographie un document ou une
 *    photo posée, jamais soi-même.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { estPivotable, pivoterFichier } from '@/components/medias/pivotement'

import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BoutonsPhotos from '@/components/medias/BoutonsPhotos.vue'
import {
  chargerLesQuestions,
  jeNeSaisPas,
  plusTard,
  repondre,
  televerserMedia,
} from '@/lib/api'

// `Question` n'est PAS réimporté ici : les deux blocs `<script>` d'un composant
// mono-fichier sont fusionnés, et le type importé plus haut est déjà en portée.
// L'importer deux fois rend `TS2300: Duplicate identifier`.

const questions = shallowRef<Question[] | null>(null)
const total = ref(0)
const redigeesParModele = ref(0)
const erreur = shallowRef<unknown>(null)

/** Le texte en cours de frappe, par question. Jamais envoyé sans un geste. */
const brouillons = ref<Record<number, string>>({})
/** La photo choisie, par question — pas encore téléversée. */
const photos = ref<Record<number, File | null>>({})
/** La question dont un envoi est en cours : elle seule montre son attente. */
const enCours = ref<number | null>(null)
/** Ce qu'une réponse a déposé, gardé à l'écran le temps d'être lu. */
const deposees = ref<Record<number, number[]>>({})

/**
 * Un seul avorteur pour toute la vue.
 *
 * Il sert au démontage, et à rien d'autre : les trois actions ne s'annulent
 * pas entre elles — deux réponses à deux questions différentes sont deux
 * contributions, pas une qui remplace l'autre.
 */
let avorteur: AbortController | null = null

const etat = computed(() => etatDeLEcran(questions.value, erreur.value))
const mention = computed(() => mentionDuModele(total.value, redigeesParModele.value))

async function charger(): Promise<void> {
  erreur.value = null
  avorteur?.abort()
  avorteur = new AbortController()

  try {
    const page = await chargerLesQuestions(avorteur.signal)
    questions.value = page.questions
    total.value = page.total
    redigeesParModele.value = page.redigeesParModele
  } catch (e) {
    erreur.value = e
  }
}

/** Retire la carte de la liste sans toucher aux autres — voir la décision 2. */
function retirer(id: number): void {
  questions.value = (questions.value ?? []).filter((q) => q.id !== id)
  total.value = questions.value.length
}

async function surJeNeSaisPas(question: Question): Promise<void> {
  await agir(question, async () => {
    await jeNeSaisPas(question.id)
    retirer(question.id)
  })
}

async function surPlusTard(question: Question): Promise<void> {
  await agir(question, async () => {
    await plusTard(question.id)
    retirer(question.id)
  })
}

async function surRepondre(question: Question): Promise<void> {
  await agir(question, async () => {
    const texte = (brouillons.value[question.id] ?? '').trim()
    const fichier = photos.value[question.id] ?? null

    // Le téléversement d'abord : sans identifiant de média, la réponse ne peut
    // pas le désigner. Si celui-ci échoue, rien n'est déposé — et le texte
    // reste dans le champ, intact.
    const mediaId =
      fichier === null
        ? undefined
        : (
            await televerserMedia({
              fichier,
              kind: 'preuve',
              subjectKind: 'personne',
              subjectId: question.sujet.id,
              caption: question.texte,
            })
          ).id

    const repondue = await repondre(question.id, {
      ...(texte.length > 0 ? { texte } : {}),
      ...(mediaId === undefined ? {} : { mediaId }),
    })

    deposees.value = {
      ...deposees.value,
      [question.id]: repondue.demandes.map((d) => d.id),
    }
    retirer(question.id)
  })
}

/**
 * Le tronc commun des trois gestes : une seule question en attente à la fois,
 * et **l'erreur reste sur la carte**.
 *
 * Elle n'est pas hissée dans le bandeau général : un refus de réponse porte sur
 * une question précise, et l'afficher en tête ferait chercher laquelle.
 */
async function agir(question: Question, geste: () => Promise<void>): Promise<void> {
  if (enCours.value !== null) return

  enCours.value = question.id
  erreursParQuestion.value = { ...erreursParQuestion.value, [question.id]: null }

  try {
    await geste()
  } catch (e) {
    erreursParQuestion.value = { ...erreursParQuestion.value, [question.id]: e }
  } finally {
    enCours.value = null
  }
}

const erreursParQuestion = ref<Record<number, unknown>>({})

/** L'aperçu de chaque photo choisie, pour qu'on voie si elle est couchée. */
const apercusPhotos = ref<Record<number, string>>({})

function poserPhoto(id: number, fichier: File | null): void {
  const ancien = apercusPhotos.value[id]
  if (ancien) URL.revokeObjectURL(ancien)
  const apercus = { ...apercusPhotos.value }
  delete apercus[id]
  if (fichier !== null && estPivotable(fichier)) apercus[id] = URL.createObjectURL(fichier)
  apercusPhotos.value = apercus
  photos.value = { ...photos.value, [id]: fichier }
}

async function pivoterPhoto(id: number): Promise<void> {
  const fichier = photos.value[id]
  if (!fichier) return
  poserPhoto(id, await pivoterFichier(fichier, 90))
}

onBeforeUnmount(() => {
  for (const url of Object.values(apercusPhotos.value)) URL.revokeObjectURL(url)
})

/**
 * Le bouton « Envoyer » n'a de sens qu'avec quelque chose à envoyer.
 *
 * Le serveur refuse une réponse vide en `422` — il le doit, une réponse vide
 * n'étant **pas** « je ne sais pas ». Mais faire découvrir ce refus au membre
 * après un aller-retour serait le lui reprocher : le bouton est désactivé tant
 * qu'il n'y a ni texte ni photo.
 */
function peutEnvoyer(question: Question): boolean {
  const texte = (brouillons.value[question.id] ?? '').trim()

  return texte.length > 0 || (photos.value[question.id] ?? null) !== null
}

onMounted(charger)
onBeforeUnmount(() => avorteur?.abort())
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-6">
    <header class="mb-6">
      <h1 class="text-xl font-semibold text-texte">Questions</h1>
      <p class="mt-1 text-sm text-attenue">
        Ce que l’arbre ne sait pas encore. Chaque réponse est relue avant d’être ajoutée.
      </p>
      <p v-if="mention !== null" class="mt-2 text-xs text-attenue">{{ mention }}</p>
    </header>

    <div v-if="etat.bandeau" class="mb-4">
      <MessageErreur
        :erreur="erreur"
        titre="Les questions affichées restent valables, mais la mise à jour a échoué"
        @action="charger"
      />
    </div>

    <SqueletteChargement v-if="etat.corps === 'squelette'" :lignes="4" />

    <MessageErreur
      v-else-if="etat.corps === 'panne'"
      :erreur="erreur"
      titre="Les questions n’ont pas pu être chargées"
      @action="charger"
    />

    <p v-else-if="etat.corps === 'vide'" class="rounded-panneau border border-ligne bg-panneau p-6 text-sm text-attenue">
      Rien à vous demander pour l’instant.
    </p>

    <ul v-else class="flex flex-col gap-4">
      <li
        v-for="question in questions ?? []"
        :key="question.id"
        class="rounded-panneau border border-ligne bg-panneau p-4"
      >
        <p class="text-base text-texte">{{ question.texte }}</p>
        <p class="mt-1 text-xs text-attenue">
          {{ question.sujet.libelle }} · {{ question.natureLibelle }}
          <span v-if="question.reports > 0"> · déjà repoussée {{ question.reports }} fois</span>
        </p>

        <div class="mt-3 flex flex-wrap gap-2">
          <BoutonBase
            variante="secondaire"
            :desactive="enCours !== null"
            :chargement="enCours === question.id"
            @click="surJeNeSaisPas(question)"
          >
            Je ne sais pas
          </BoutonBase>
          <BoutonBase
            variante="secondaire"
            :desactive="enCours !== null"
            @click="surPlusTard(question)"
          >
            Je vais me renseigner
          </BoutonBase>
        </div>

        <div class="mt-3 flex flex-col gap-2">
          <label class="sr-only" :for="`reponse-${question.id}`">Votre réponse</label>
          <textarea
            :id="`reponse-${question.id}`"
            v-model="brouillons[question.id]"
            rows="2"
            class="w-full rounded-champ border border-ligne bg-fond px-3 py-2 text-sm text-texte"
            placeholder="Ce dont vous vous souvenez, même approximatif."
          />

          <div class="flex flex-col gap-1.5">
            <span class="text-xs text-attenue">{{ question.attendPhoto ? 'Une photo' : 'Une photo, si vous en avez une' }}</span>
            <!--
              Les deux boutons communs (`BoutonsPhotos`) : « Prendre une photo »
              pose `capture="environment"` — l'appareil photo arrière, on
              photographie un document ou une photo posée sur la table. Voir la
              décision 4 de l'en-tête.
            -->
            <BoutonsPhotos
              :multiple="false"
              :importer="apercusPhotos[question.id] ? 'Choisir une autre photo' : 'Importer une photo'"
              @fichiers="(f) => poserPhoto(question.id, f[0] ?? null)"
            />
          </div>

          <div v-if="apercusPhotos[question.id]" class="flex items-center gap-2">
            <img
              :src="apercusPhotos[question.id]"
              alt="La photo choisie"
              class="size-20 rounded-champ border border-ligne bg-fond object-contain"
            />
            <BoutonBase variante="discret" aria-label="Pivoter la photo d’un quart de tour" @click="pivoterPhoto(question.id)">
              ↻ Pivoter
            </BoutonBase>
          </div>

          <div class="flex items-center gap-3">
            <BoutonBase
              :desactive="enCours !== null || !peutEnvoyer(question)"
              :chargement="enCours === question.id"
              @click="surRepondre(question)"
            >
              Envoyer
            </BoutonBase>
            <span v-if="(deposees[question.id] ?? []).length > 0" class="text-xs text-ok">
              Votre réponse est partie pour validation.
            </span>
          </div>

          <MessageErreur
            v-if="erreursParQuestion[question.id]"
            :erreur="erreursParQuestion[question.id]"
            titre="Cette réponse n’a pas pu être enregistrée"
          />
        </div>
      </li>
    </ul>
  </div>
</template>
