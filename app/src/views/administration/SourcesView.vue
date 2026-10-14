<script setup lang="ts">
/**
 * Les sources — ADR 0012, § 10.7 du contrat.
 *
 * Une source est la **notice** d'un fonds — « État civil de Paris », « Fichier
 * des personnes décédées » —, pas une donnée de l'arbre : l'administration la
 * crée et la corrige ici, **hors de la file**. Ce qui engage l'arbre, c'est la
 * citation qui l'emploie, et elle reste une demande `ajout_citation`, qui
 * désigne la source par son numéro. C'est ce numéro que la liste affiche.
 *
 * Un seul formulaire, en création ou en édition, dans un **feuillet** (handoff
 * « mode Administration », § 4.4) : « + Nouvelle source » l'ouvre à blanc, et
 * **toute la ligne** d'une source l'ouvre rempli. Pas de suppression (ADR
 * 0012) : une source jamais citée peut encore être désignée par une demande en
 * attente.
 *
 * **Une source venue du GEDCOM d'origine garde sa référence** (`gedcomXref`) : elle
 * n'est ni saisie ni modifiée ici, et l'application n'importe plus de GEDCOM qui
 * pourrait réécrire la notice (ADR 0018). Le formulaire le dit, à
 * côté des champs, plutôt que de laisser croire qu'une correction est définitive.
 */
import { computed, onMounted, ref, watch } from 'vue'
import {
  GENRES_DE_SOURCE,
  creerSource,
  listerSources,
  modifierSource,
  type SourceAdministration,
} from '@/lib/api'
import { analyserErreur } from '@/lib/api/erreurs'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import Feuillet from '@/components/base/Feuillet.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { annoncer } from '@/composables/toast'
import { ecrituresSuspendues, infobulleSuspension, motifSuspension } from '@/components/administration/ecriture'
import {
  fautesDeSaisie,
  libelleCitations,
  noticeDepuisSaisie,
  saisieDepuisSource,
  saisieVide,
  type SaisieDeSource,
} from '@/components/administration/sources'

const { vueEmpruntee } = useSession()

const sources = ref<SourceAdministration[]>([])
const total = ref(0)
const chargement = ref(false)
const erreur = ref<unknown>(null)
const recherche = ref('')

/** Le feuillet du formulaire est-il ouvert ? */
const formulaireOuvert = ref(false)
/** La source en cours d'édition ; `null` : le formulaire crée. */
const enEdition = ref<SourceAdministration | null>(null)
const saisie = ref<SaisieDeSource>(saisieVide())
const erreursChamps = ref<Record<string, string>>({})
const envoi = ref(false)
/** Ce qui vient d'être enregistré, dit une fois sous le formulaire. */
const confirmation = ref<string | null>(null)

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    const terme = recherche.value.trim()
    const liste = await listerSources({ q: terme === '' ? undefined : terme, parPage: 100 })
    sources.value = liste.elements
    total.value = liste.total
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

onMounted(charger)

/** La recherche attend qu'on ait fini de taper. */
let minuterie: ReturnType<typeof setTimeout> | undefined
watch(recherche, () => {
  clearTimeout(minuterie)
  minuterie = setTimeout(() => void charger(), 250)
})

function editer(source: SourceAdministration): void {
  enEdition.value = source
  saisie.value = saisieDepuisSource(source)
  erreursChamps.value = {}
  confirmation.value = null
  formulaireOuvert.value = true
}

function nouvelle(): void {
  annuler()
  confirmation.value = null
  formulaireOuvert.value = true
}

function annuler(): void {
  enEdition.value = null
  saisie.value = saisieVide()
  erreursChamps.value = {}
  formulaireOuvert.value = false
}

async function enregistrer(): Promise<void> {
  if (suspendu.value || envoi.value) return

  erreursChamps.value = fautesDeSaisie(saisie.value)
  if (Object.keys(erreursChamps.value).length > 0) return

  envoi.value = true
  erreur.value = null
  confirmation.value = null
  try {
    const notice = noticeDepuisSaisie(saisie.value)
    const source =
      enEdition.value === null ? await creerSource(notice) : await modifierSource(enEdition.value, notice)
    confirmation.value =
      enEdition.value === null
        ? `Source n° ${source.id} créée : « ${source.title} ». Elle peut désormais être citée.`
        : `Source n° ${source.id} enregistrée.`
    annoncer(confirmation.value)
    annuler()
    await charger()
  } catch (cause) {
    const champs = analyserErreur(cause).champs
    if (Object.keys(champs).length > 0) erreursChamps.value = champs
    else erreur.value = cause
  } finally {
    envoi.value = false
  }
}

const libelleTotal = computed(() => `${total.value} source${total.value > 1 ? 's' : ''}`)
</script>

<template>
  <div class="flex flex-col gap-4">
    <p
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      {{ raisonSuspension }}
    </p>

    <div class="flex flex-wrap items-center gap-2">
      <label class="flex min-h-11 flex-[1_1_240px] items-center gap-2 rounded-champ border border-ligne bg-panneau px-3 md:min-h-9">
        <span class="text-attenue" aria-hidden="true">⌕</span>
        <input
          v-model="recherche"
          type="search"
          aria-label="Chercher une source par son titre"
          placeholder="Chercher par titre…"
          class="h-8 min-w-0 flex-1 bg-transparent text-sm focus:outline-none"
        />
      </label>
      <BoutonBase class="min-h-11 whitespace-nowrap md:min-h-9" :desactive="suspendu" :title="infobulle" @click="nouvelle">
        + Nouvelle source
      </BoutonBase>
    </div>

    <span v-if="!chargement" class="text-[13px] text-attenue">{{ libelleTotal }}</span>

    <MessageErreur v-if="erreur !== null && !formulaireOuvert" :erreur="erreur" @action="charger" />

    <SqueletteChargement v-if="chargement && sources.length === 0" :lignes="4" />

    <p
      v-else-if="sources.length === 0"
      class="rounded-panneau border border-ligne bg-panneau px-4 py-7 text-center text-attenue"
    >
      {{ recherche.trim() === '' ? 'Aucune source.' : 'Aucune source ne porte ce titre.' }}
    </p>

    <ul v-else class="m-0 flex list-none flex-col gap-px overflow-hidden rounded-panneau border border-ligne bg-ligne p-0">
      <li v-for="source in sources" :key="source.id">
        <!-- Toute la ligne ouvre l'édition. -->
        <button
          type="button"
          class="flex w-full items-center gap-3 px-4 py-3 text-left disabled:cursor-not-allowed"
          :class="enEdition?.id === source.id ? 'bg-selection' : 'bg-panneau hover:bg-survol'"
          :disabled="suspendu"
          :title="infobulle"
          :aria-label="`Modifier la source n° ${source.id} : ${source.title}`"
          @click="editer(source)"
        >
          <span class="w-[34px] flex-none font-mono text-[12.5px] text-attenue">{{ source.id }}</span>
          <span class="flex min-w-0 flex-1 flex-col">
            <span class="font-semibold [overflow-wrap:anywhere]">{{ source.title }}</span>
            <span class="text-[12.5px] text-attenue">
              {{ source.sourceKindLabel }}<template v-if="source.author"> · {{ source.author }}</template>
            </span>
          </span>
          <span class="text-[12.5px] whitespace-nowrap text-attenue tnum">{{ libelleCitations(source.citationCount) }}</span>
          <span class="text-lg text-attenue" aria-hidden="true">›</span>
        </button>
      </li>
    </ul>
  </div>

  <Feuillet
    :ouvert="formulaireOuvert"
    :titre="enEdition === null ? 'Nouvelle source' : `Modifier la source n° ${enEdition.id}`"
    @fermer="annuler"
  >
    <form novalidate class="flex flex-col gap-3.5" @submit.prevent="enregistrer">
      <p v-if="enEdition?.gedcomXref" class="text-[12.5px] text-attenue">
        Source venue du GEDCOM d’origine (<span class="font-mono">{{ enEdition.gedcomXref }}</span>) :
        sa référence ne change pas.
      </p>

      <ChampTexte
        v-model="saisie.title"
        etiquette="Titre"
        requis
        placeholder="État civil de Paris"
        :erreur="erreursChamps['title'] ?? ''"
      />

      <fieldset class="flex flex-col">
        <legend class="mb-1.5 text-[13.5px] font-medium">Type</legend>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="genre in GENRES_DE_SOURCE"
            :key="genre.valeur"
            type="button"
            class="flex min-h-11 items-center rounded-pastille border px-[13px] text-[13px] md:min-h-9"
            :class="genre.valeur === saisie.sourceKind ? 'border-accent bg-accent-doux font-semibold text-texte' : 'border-ligne bg-panneau text-attenue'"
            :aria-pressed="genre.valeur === saisie.sourceKind"
            @click="saisie.sourceKind = genre.valeur"
          >
            {{ genre.libelle }}
          </button>
        </div>
        <span v-if="erreursChamps['sourceKind']" class="mt-1 text-xs text-alerte">{{ erreursChamps['sourceKind'] }}</span>
      </fieldset>

      <ChampTexte
        v-model="saisie.author"
        etiquette="Auteur"
        placeholder="Archives de Paris"
        :erreur="erreursChamps['author'] ?? ''"
      />

      <label class="flex flex-col gap-1">
        <span class="text-[13.5px] font-medium">Publication</span>
        <span class="text-[12.5px] text-attenue">L’éditeur, le site, les cotes, les conditions d’accès.</span>
        <textarea
          v-model="saisie.publication"
          rows="2"
          class="resize-y rounded-champ border bg-fond px-2.5 py-2 text-sm text-texte"
          :class="erreursChamps['publication'] ? 'border-alerte' : 'border-ligne'"
        />
        <span v-if="erreursChamps['publication']" class="text-xs text-alerte">{{ erreursChamps['publication'] }}</span>
      </label>

      <label class="flex flex-col gap-1">
        <span class="text-[13.5px] font-medium">Note</span>
        <textarea
          v-model="saisie.note"
          rows="2"
          class="resize-y rounded-champ border bg-fond px-2.5 py-2 text-sm text-texte"
          :class="erreursChamps['note'] ? 'border-alerte' : 'border-ligne'"
        />
        <span v-if="erreursChamps['note']" class="text-xs text-alerte">{{ erreursChamps['note'] }}</span>
      </label>

      <MessageErreur v-if="erreur !== null" :erreur="erreur" />

      <BoutonBase type="submit" class="min-h-11" :chargement="envoi" :desactive="suspendu" :title="infobulle">
        {{ enEdition === null ? 'Créer la source' : 'Enregistrer' }}
      </BoutonBase>
    </form>
  </Feuillet>
</template>
