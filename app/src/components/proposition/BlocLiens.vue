<script setup lang="ts">
/**
 * Les liens d'une personne proposée — parents, conjoints, enfants.
 *
 * « On sélectionne les parents et/ou enfant et/ou conjoints (date de mariage si
 * on a, ou de rupture, etc.) » : c'est la demande du commanditaire, et c'est
 * l'écran le plus dense des deux formulaires. Trois règles de fond le
 * gouvernent, toutes déjà tenues par `creation.ts` — ce composant ne fait que
 * ne pas les contredire à l'écran :
 *
 * - **Au moins un lien pour soumettre**, « sinon la personne flotterait hors de
 *   l'arbre ». Le compte est rappelé en pied de bloc, sans jamais bloquer
 *   l'enregistrement d'un brouillon incomplet.
 * - **Deux parents au plus, et pas deux fois le même.** Le second parent est
 *   `other2PersonId` sur **le même lien**, pas un second lien : c'est ce que le
 *   modèle appelle « le second parent ».
 * - **Les dates n'existent que sur une union.** Un enfant n'a ni date de
 *   mariage ni date de rupture, et le gabarit n'en propose pas : ce n'est pas
 *   une omission, c'est `ck_prp_link_dates_union`.
 *
 * ── Une extrémité peut être une personne **proposée** ───────────────────────
 *
 * Le sélecteur rend deux groupes : l'arbre validé, et les fiches encore en
 * attente que le lecteur a le droit de voir. Désigner une fiche en attente est
 * permis — c'est tout l'objet du correctif : « le père que j'ai proposé juste
 * avant ne m'est pas offert » — et l'écran le **dit** à côté du nom, parce
 * qu'une demande qui s'appuie sur une autre attendra sa validation.
 *
 * **Une union sans date reste une union, une rupture sans date reste une
 * rupture.** Les champs de date sont donc facultatifs partout, et la case
 * « union rompue » est indépendante de la date de rupture — sur un arbre ancien,
 * on sait souvent qu'il y a eu séparation sans savoir quand.
 *
 * **Et un mariage sans date reste un mariage** (ADR 0020) : « Mariés : oui / non
 * (union libre) / on ne sait pas » se répond à part de la date, que « oui »
 * n'exige pas — mais qu'une date saisie impose.
 *
 * Au téléphone, chaque lien occupe sa propre carte et chaque champ sa propre
 * ligne : quatre blocs de liens mis côte à côte deviennent illisibles sous
 * 400 px, et rien ici ne force une largeur qui déborderait.
 *
 * ── En mode complétion d'une proposition (ADR 0017) ─────────────────────────
 *
 * Le bloc ne porte plus que les liens **à ajouter** : ceux de la proposition
 * sont au-dessus, en lecture seule (`titre="Liens à ajouter"`). Trois réglages le
 * disent : `dejaDesignes` éteint dans le sélecteur les proches déjà nommés,
 * `placesDeParent` borne les parents à ce qui reste libre — une personne n'en a
 * pas trois —, et `dejaRelies` compte les liens existants dans « au moins un ».
 */
import { computed } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import { aideDeLaRupture, natureDeLaSaisie, saisieAtteste } from '@/components/personne/union'
import ChampDateIncertaine from './ChampDateIncertaine.vue'
import ChoixNatureUnion from './ChoixNatureUnion.vue'
import SelecteurPersonne from './SelecteurPersonne.vue'
import {
  cleDuChoix,
  estFicheProposee,
  nombreDeLiens,
  unionVide,
  type ChoixDePersonne,
  type SaisieCreation,
} from './creation'

const {
  erreurs = {},
  titre = '',
  dejaDesignes = [],
  dejaRelies = 0,
  placesDeParent = 2,
} = defineProps<{
  /** Messages indexés par `filiation`, `liens`, `unions.<n>.mariage`… */
  erreurs?: Record<string, string>
  /** Un titre au-dessus des trois blocs — « Liens à ajouter » en complétion. */
  titre?: string
  /** Les proches que la proposition nomme déjà (`cleDuChoix`) : éteints au sélecteur. */
  dejaDesignes?: string[]
  /** Les liens que la proposition porte déjà : ils comptent dans « au moins un ». */
  dejaRelies?: number
  /** Les places de parent encore libres : 2, sauf en complétion. */
  placesDeParent?: number
}>()

const saisie = defineModel<SaisieCreation>({ required: true })

/** Toutes les personnes déjà désignées : le sélecteur les affiche quand même —
 *  masquer un résultat serait cacher ce que le serveur a rendu — mais éteint
 *  leur bouton. */
const dejaChoisis = computed(() => [
  ...dejaDesignes,
  ...saisie.value.filiation.parents.map(cleDuChoix),
  ...saisie.value.unions.map((union) => cleDuChoix(union.personne)),
  ...saisie.value.enfants.map((enfant) => cleDuChoix(enfant.personne)),
])

const total = computed(() => nombreDeLiens(saisie.value))

function ajouterParent(personne: ChoixDePersonne): void {
  if (saisie.value.filiation.parents.length >= placesDeParent) return
  saisie.value.filiation.parents.push(personne)
}

function retirerParent(index: number): void {
  saisie.value.filiation.parents.splice(index, 1)
}

function retirerFamille(): void {
  saisie.value.filiation.famille = null
}

function ajouterUnion(personne: ChoixDePersonne): void {
  saisie.value.unions.push(unionVide(personne))
}

function retirerUnion(index: number): void {
  saisie.value.unions.splice(index, 1)
}

function ajouterEnfant(personne: ChoixDePersonne): void {
  saisie.value.enfants.push({ personne, rangFratrie: null })
}

function retirerEnfant(index: number): void {
  saisie.value.enfants.splice(index, 1)
}

/** Un rang vide vaut `null` — « à la fin », et non zéro, qui voudrait dire
 *  « l'aîné ». La nuance a un sens généalogique. */
function poserRang(index: number, brut: string): void {
  const propre = brut.trim()
  const enfant = saisie.value.enfants[index]
  if (enfant === undefined) return
  enfant.rangFratrie = propre.length === 0 ? null : Number(propre)
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <h2 v-if="titre !== ''" class="m-0 text-sm font-semibold tracking-tight" data-titre-liens>{{ titre }}</h2>

    <!-- ── Parents ────────────────────────────────────────────────── -->
    <PanneauBase titre="Parents">
      <div class="flex flex-col gap-3">
        <p
          v-if="placesDeParent === 0 && saisie.filiation.parents.length === 0 && saisie.filiation.famille === null"
          class="m-0 text-sm text-attenue"
        >
          Ses parents sont déjà dans la proposition.
        </p>
        <p v-if="saisie.filiation.famille !== null" class="flex flex-wrap items-center gap-2 text-sm">
          <span class="rounded-pastille border border-ligne px-2 py-0.5">
            Couple désigné : {{ saisie.filiation.famille.libelle }}
          </span>
          <BoutonBase variante="discret" @click="retirerFamille">Retirer</BoutonBase>
        </p>

        <ul v-if="saisie.filiation.parents.length > 0" class="flex flex-col gap-1.5">
          <li
            v-for="(parent, index) in saisie.filiation.parents"
            :key="cleDuChoix(parent)"
            class="flex flex-wrap items-center justify-between gap-2 rounded-champ border border-ligne px-2.5 py-1.5"
          >
            <span class="text-sm font-medium">
              {{ parent.libelle }}
              <span v-if="estFicheProposee(parent)" class="ml-1 text-xs font-normal text-attenue">
                proposée, demande n° {{ parent.demandeId }}
              </span>
            </span>
            <BoutonBase variante="discret" @click="retirerParent(index)">Retirer</BoutonBase>
          </li>
        </ul>

        <SelecteurPersonne
          v-if="saisie.filiation.famille === null && saisie.filiation.parents.length < placesDeParent"
          etiquette="Ajouter un parent"
          :aide="placesDeParent < 2 ? 'Le second parent : la proposition en nomme déjà un.' : 'Un ou deux parents.'"
          :deja-choisis="dejaChoisis"
          @choisir="ajouterParent"
        />

        <p v-if="erreurs['filiation']" class="text-xs text-alerte">{{ erreurs['filiation'] }}</p>
      </div>
    </PanneauBase>

    <!-- ── Conjoints ──────────────────────────────────────────────── -->
    <PanneauBase titre="Conjoints">
      <div class="flex flex-col gap-4">
        <article
          v-for="(union, index) in saisie.unions"
          :key="cleDuChoix(union.personne)"
          class="flex flex-col gap-3 rounded-champ border border-ligne px-3 py-3"
        >
          <header class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold">
              {{ union.personne.libelle }}
              <span v-if="estFicheProposee(union.personne)" class="ml-1 text-xs font-normal text-attenue">
                proposée, demande n° {{ union.personne.demandeId }}
              </span>
            </h3>
            <BoutonBase variante="discret" @click="retirerUnion(index)">Retirer</BoutonBase>
          </header>

          <ChoixNatureUnion v-model="union.marie" :impose="saisieAtteste(union.mariage)" />

          <!-- Pas de date de mariage pour une union libre : il n'y a pas de mariage
               à dater. Pour en saisir une, on répond d'abord « oui ». -->
          <ChampDateIncertaine
            v-if="natureDeLaSaisie(union.marie, union.mariage) !== false"
            v-model="union.mariage"
            etiquette="Mariage"
            :erreur="erreurs[`unions.${index}.mariage`] ?? ''"
            aide="Facultatif."
          />

          <label class="flex items-start gap-2">
            <input
              v-model="union.rompue"
              type="checkbox"
              class="mt-0.5 size-4 accent-[var(--color-accent)]"
            />
            <span class="text-sm">
              Union rompue
              <span class="block text-xs text-attenue">
                {{ aideDeLaRupture(natureDeLaSaisie(union.marie, union.mariage)) }}
              </span>
            </span>
          </label>

          <ChampDateIncertaine
            v-if="union.rompue"
            v-model="union.rupture"
            etiquette="Rupture"
            :erreur="erreurs[`unions.${index}.rupture`] ?? ''"
          />
          <p v-else-if="erreurs[`unions.${index}.rupture`]" class="text-xs text-alerte">
            {{ erreurs[`unions.${index}.rupture`] }}
          </p>

          <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">Ce qui vous fait dire cela</span>
            <textarea
              v-model="union.note"
              rows="2"
              placeholder="Livret de famille, acte de mariage, souvenir de famille…"
              class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
            />
          </label>
        </article>

        <SelecteurPersonne
          etiquette="Ajouter un conjoint"
          :deja-choisis="dejaChoisis"
          @choisir="ajouterUnion"
        />
      </div>
    </PanneauBase>

    <!-- ── Enfants ────────────────────────────────────────────────── -->
    <PanneauBase titre="Enfants">
      <div class="flex flex-col gap-3">
        <article
          v-for="(enfant, index) in saisie.enfants"
          :key="cleDuChoix(enfant.personne)"
          class="flex flex-wrap items-end justify-between gap-2 rounded-champ border border-ligne px-3 py-2.5"
        >
          <span class="text-sm font-medium">
            {{ enfant.personne.libelle }}
            <span v-if="estFicheProposee(enfant.personne)" class="ml-1 text-xs font-normal text-attenue">
              proposé·e, demande n° {{ enfant.personne.demandeId }}
            </span>
          </span>

          <div class="flex items-end gap-2">
            <label class="flex flex-col gap-1">
              <span class="text-xs text-attenue">Rang dans la fratrie</span>
              <input
                :value="enfant.rangFratrie ?? ''"
                type="number"
                min="0"
                inputmode="numeric"
                placeholder="—"
                class="w-20 rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte font-mono tnum"
                @input="poserRang(index, ($event.target as HTMLInputElement).value)"
              />
            </label>
            <BoutonBase variante="discret" @click="retirerEnfant(index)">Retirer</BoutonBase>
          </div>
        </article>

        <SelecteurPersonne
          etiquette="Ajouter un enfant"
          aide="Rang facultatif : sans lui, l’enfant est placé en dernier."
          :deja-choisis="dejaChoisis"
          @choisir="ajouterEnfant"
        />
      </div>
    </PanneauBase>

    <!-- Le compte ne bloque rien : il rappelle la règle de la soumission. -->
    <p
      class="text-sm"
      :class="erreurs['liens'] ? 'text-alerte' : 'text-attenue'"
      role="status"
    >
      <template v-if="erreurs['liens']">{{ erreurs['liens'] }}</template>
      <!-- En complétion, la proposition est déjà reliée : il n'y a peut-être rien à ajouter. -->
      <template v-else-if="dejaRelies > 0">
        {{
          total === 0
            ? 'Aucun lien à ajouter : la proposition est déjà reliée à l’arbre.'
            : `${total} lien${total > 1 ? 's' : ''} à ajouter à la proposition.`
        }}
      </template>
      <template v-else-if="total === 0">
        Ajoutez au moins un lien avant d’envoyer.
      </template>
      <template v-else>
        {{ total }} lien{{ total > 1 ? 's' : '' }} : la personne sera reliée à l’arbre.
      </template>
    </p>
  </div>
</template>
