<script setup lang="ts">
/**
 * Les sources — ADR 0012, § 10.7 du contrat.
 *
 * Une source est la **notice** d'un fonds — « État civil de Paris », « Fichier
 * des personnes décédées » —, pas une donnée de l'arbre : l'administration la
 * crée et la corrige ici, **hors de la file**. Ce qui engage l'arbre, c'est la
 * citation qui l'emploie, et elle reste une demande `ajout_citation`, qui
 * désigne la source par son numéro. C'est ce numéro que le tableau affiche.
 *
 * Un seul formulaire, en création ou en édition : « Modifier » le remplit avec
 * la ligne choisie, « Annuler » le remet à blanc. Pas de suppression (ADR
 * 0012) : une source jamais citée peut encore être désignée par une demande en
 * attente.
 *
 * **Une source venue du GEDCOM d'origine garde sa référence** (`gedcomXref`) : elle
 * n'est ni saisie ni modifiée ici, et l'application n'importe plus de GEDCOM qui
 * pourrait réécrire la notice (ADR 0017). Le formulaire le dit, à
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
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import OngletsAdministration from './OngletsAdministration.vue'
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
}

function annuler(): void {
  enEdition.value = null
  saisie.value = saisieVide()
  erreursChamps.value = {}
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
  <BarreEcran ecran="Administration" :reinitialisable="false">
    <template #vue><OngletsAdministration /></template>
    <template v-if="!chargement" #compteur>{{ libelleTotal }}</template>
  </BarreEcran>

  <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-6">
    <p
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      {{ raisonSuspension }}
    </p>

    <PanneauBase :titre="enEdition === null ? 'Nouvelle source' : `Modifier la source n° ${enEdition.id}`">
      <form novalidate class="flex flex-col gap-3.5" @submit.prevent="enregistrer">
        <p v-if="enEdition?.gedcomXref" class="text-xs text-attenue">
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

        <div class="flex flex-wrap gap-3">
          <label class="flex min-w-[12rem] flex-1 flex-col gap-1">
            <span class="text-sm font-medium">Type</span>
            <select
              v-model="saisie.sourceKind"
              class="rounded-champ border bg-fond px-2.5 py-1.5 text-sm text-texte"
              :class="erreursChamps['sourceKind'] ? 'border-alerte' : 'border-ligne'"
            >
              <option v-for="genre in GENRES_DE_SOURCE" :key="genre.valeur" :value="genre.valeur">
                {{ genre.libelle }}
              </option>
            </select>
            <span v-if="erreursChamps['sourceKind']" class="text-xs text-alerte">{{ erreursChamps['sourceKind'] }}</span>
          </label>

          <div class="min-w-[12rem] flex-1">
            <ChampTexte
              v-model="saisie.author"
              etiquette="Auteur"
              placeholder="Archives de Paris"
              :erreur="erreursChamps['author'] ?? ''"
            />
          </div>
        </div>

        <label class="flex flex-col gap-1">
          <span class="text-sm font-medium">Publication</span>
          <span class="text-xs text-attenue">L’éditeur, le site, les cotes, les conditions d’accès.</span>
          <textarea
            v-model="saisie.publication"
            rows="2"
            class="rounded-champ border bg-fond px-2.5 py-1.5 text-sm text-texte"
            :class="erreursChamps['publication'] ? 'border-alerte' : 'border-ligne'"
          />
          <span v-if="erreursChamps['publication']" class="text-xs text-alerte">{{ erreursChamps['publication'] }}</span>
        </label>

        <label class="flex flex-col gap-1">
          <span class="text-sm font-medium">Note</span>
          <textarea
            v-model="saisie.note"
            rows="2"
            class="rounded-champ border bg-fond px-2.5 py-1.5 text-sm text-texte"
            :class="erreursChamps['note'] ? 'border-alerte' : 'border-ligne'"
          />
          <span v-if="erreursChamps['note']" class="text-xs text-alerte">{{ erreursChamps['note'] }}</span>
        </label>

        <div class="flex flex-wrap gap-2">
          <BoutonBase type="submit" :chargement="envoi" :desactive="suspendu" :title="infobulle">
            {{ enEdition === null ? 'Créer la source' : 'Enregistrer' }}
          </BoutonBase>
          <BoutonBase v-if="enEdition !== null" variante="secondaire" @click="annuler">Annuler</BoutonBase>
        </div>

        <p v-if="confirmation !== null" class="text-sm text-ok" role="status">{{ confirmation }}</p>
      </form>
    </PanneauBase>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="charger" />

    <PanneauBase>
      <template #entete>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-sm font-semibold tracking-tight">Sources</h2>
          <input
            v-model="recherche"
            type="search"
            aria-label="Chercher une source par son titre"
            placeholder="Chercher par titre…"
            class="w-full max-w-xs rounded-champ border border-ligne bg-fond px-2.5 py-1 text-sm text-texte"
          />
        </div>
      </template>

      <SqueletteChargement v-if="chargement && sources.length === 0" :lignes="4" />

      <p v-else-if="sources.length === 0" class="text-sm text-attenue">
        {{ recherche.trim() === '' ? 'Aucune source.' : 'Aucune source ne porte ce titre.' }}
      </p>

      <table v-else class="w-full text-left text-sm">
        <thead class="text-xs text-attenue">
          <tr>
            <th scope="col" class="pb-2 font-medium tnum">N°</th>
            <th scope="col" class="pb-2 font-medium">Titre</th>
            <th scope="col" class="pb-2 font-medium max-md:hidden">Type</th>
            <th scope="col" class="pb-2 font-medium max-md:hidden">Auteur</th>
            <th scope="col" class="pb-2 font-medium">Citations</th>
            <th scope="col" class="pb-2"><span class="sr-only">Action</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-ligne">
          <tr v-for="source in sources" :key="source.id" :class="enEdition?.id === source.id ? 'bg-accent-doux' : ''">
            <td class="py-2 pr-3 align-top text-attenue tnum">{{ source.id }}</td>
            <td class="py-2 pr-3 align-top">
              <span class="font-medium">{{ source.title }}</span>
              <span class="block text-xs text-attenue md:hidden">{{ source.sourceKindLabel }}</span>
            </td>
            <td class="py-2 pr-3 align-top text-attenue max-md:hidden">{{ source.sourceKindLabel }}</td>
            <td class="py-2 pr-3 align-top text-attenue max-md:hidden">{{ source.author ?? '' }}</td>
            <td class="py-2 pr-3 align-top whitespace-nowrap text-attenue tnum">{{ libelleCitations(source.citationCount) }}</td>
            <td class="py-2 text-right align-top">
              <BoutonBase variante="discret" :desactive="suspendu" :title="infobulle" @click="editer(source)">
                Modifier
              </BoutonBase>
            </td>
          </tr>
        </tbody>
      </table>
    </PanneauBase>
  </div>
</template>
