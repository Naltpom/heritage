<script setup lang="ts">
/**
 * La parenté d'une fiche existante, dans le formulaire de modification.
 *
 * Chaque bloc montre ce que la fiche porte déjà, puis offre d'en ajouter. Les
 * ajouts partent en demande `ajout_lien`, distincte des ops de champ : c'est la
 * vue qui dépose les deux (§ 6.4 bis).
 *
 * Deux règles tenues ici, et qui se paient si on les oublie :
 * - la fratrie s'écrit « enfant du même couple » : sans parent connu, aucun
 *   couple à désigner, donc pas de sélecteur ;
 * - un enfant rattaché « au couple » ou « à la personne seule » n'est pas la
 *   même chose : on demande, on ne devine pas.
 */
import { computed, ref } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import ChampDateIncertaine from './ChampDateIncertaine.vue'
import SelecteurPersonne from './SelecteurPersonne.vue'
import { cleDuChoix, estFicheProposee, unionVide, type ChoixDePersonne } from './creation'
import { dejaDesignes, type SaisieLiens } from './liens'

const { fiche, erreurs = {} } = defineProps<{
  fiche: FichePersonneComplete
  erreurs?: Record<string, string>
}>()

const saisie = defineModel<SaisieLiens>({ required: true })

/** « Cette fiche ne peut pas être son propre parent. » */
const refus = ref<string | null>(null)

/** Les familles d'origine, une par couple : `fiche.parents` porte une ligne
 *  **par parent**. Plusieurs couples (adoption, remariage) : on demande lequel. */
const famillesDOrigine = computed(() => {
  const parGroupe = new Map<number, string[]>()
  for (const parent of fiche.parents) {
    const noms = parGroupe.get(parent.familyId) ?? []
    noms.push(parent.displayName)
    parGroupe.set(parent.familyId, noms)
  }
  return [...parGroupe.entries()].map(([familleId, noms]) => ({ familleId, libelle: noms.join(' et ') }))
})

const unionsExistantes = computed(() =>
  fiche.unions.map((union) => ({
    familleId: union.familyId,
    libelle: union.conjoint?.displayName ?? 'conjoint inconnu',
  })),
)

const enfantsExistants = computed(() => fiche.unions.flatMap((union) => union.enfants))

const dejaChoisis = computed(() => [
  ...dejaDesignes(saisie.value),
  // Les proches déjà rattachés : le sélecteur les montre, bouton éteint.
  ...fiche.parents.map((p) => `personne:${p.personId}`),
  ...fiche.fratrie.map((p) => `personne:${p.personId}`),
  ...fiche.unions.flatMap((u) => (u.conjoint === null ? [] : [`personne:${u.conjoint.personId}`])),
  ...enfantsExistants.value.map((p) => `personne:${p.personId}`),
])

function refuseLaFicheElleMeme(choix: ChoixDePersonne): boolean {
  if (estFicheProposee(choix) || choix.personId !== fiche.id) return false
  refus.value = 'C’est la personne que vous modifiez.'
  return true
}

function ajouterParent(personne: ChoixDePersonne): void {
  if (refuseLaFicheElleMeme(personne) || saisie.value.parents.length >= 2) return
  refus.value = null
  saisie.value.parents.push(personne)
}

function ajouterFratrie(personne: ChoixDePersonne): void {
  const premiere = famillesDOrigine.value[0]
  if (refuseLaFicheElleMeme(personne) || premiere === undefined) return
  refus.value = null
  saisie.value.fratrie.push({ personne, familleId: premiere.familleId, rangFratrie: null })
}

function ajouterUnion(personne: ChoixDePersonne): void {
  if (refuseLaFicheElleMeme(personne)) return
  refus.value = null
  saisie.value.unions.push(unionVide(personne))
}

function ajouterEnfant(personne: ChoixDePersonne): void {
  if (refuseLaFicheElleMeme(personne)) return
  refus.value = null
  // Une seule union : préselectionnée. Sinon « parent seul », vrai jusqu'à ce
  // que le contributeur dise mieux.
  const seule = unionsExistantes.value.length === 1 ? unionsExistantes.value[0]!.familleId : null
  saisie.value.enfants.push({ personne, familleId: seule, rangFratrie: null })
}

function retirer(liste: { splice: (index: number, nombre: number) => unknown }, index: number): void {
  liste.splice(index, 1)
}

/** Vide vaut `null` — « à la fin » —, et non zéro, qui voudrait dire l'aîné. */
function rangDepuisSaisie(brut: string): number | null {
  const propre = brut.trim()
  return propre.length === 0 ? null : Number(propre)
}

const classeChamp = 'rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte'
const classeRang = 'w-16 rounded-champ border border-ligne bg-fond px-2 py-1 font-mono text-sm text-texte tnum'
</script>

<template>
  <PanneauBase id="parente" titre="Parenté" class="scroll-mt-4">
    <div class="flex flex-col gap-5">
      <p v-if="refus !== null" class="text-sm text-alerte" role="status">{{ refus }}</p>

      <!-- ── Parents ──────────────────────────────────────────────── -->
      <section class="flex flex-col gap-2">
        <h3 class="text-sm font-semibold">Parents</h3>
        <ul v-if="fiche.parents.length > 0" class="flex flex-wrap gap-1.5">
          <li v-for="parent in fiche.parents" :key="parent.personId" class="rounded-pastille border border-ligne px-2 py-0.5 text-sm">
            {{ parent.displayName }}
          </li>
        </ul>
        <ul v-if="saisie.parents.length > 0" class="flex flex-col gap-1.5">
          <li
            v-for="(parent, index) in saisie.parents"
            :key="cleDuChoix(parent)"
            class="flex items-center justify-between gap-2 rounded-champ border border-accent bg-accent-doux px-2.5 py-1"
          >
            <span class="text-sm font-medium">+ {{ parent.libelle }}</span>
            <BoutonBase variante="discret" @click="retirer(saisie.parents, index)">Retirer</BoutonBase>
          </li>
        </ul>
        <SelecteurPersonne
          v-if="fiche.parents.length + saisie.parents.length < 2"
          etiquette="Ajouter un parent"
          :deja-choisis="dejaChoisis"
          @choisir="ajouterParent"
        />
        <p v-if="erreurs['parents']" class="text-xs text-alerte">{{ erreurs['parents'] }}</p>
      </section>

      <!-- ── Frères et sœurs ──────────────────────────────────────── -->
      <section class="flex flex-col gap-2">
        <h3 class="text-sm font-semibold">Frères et sœurs</h3>
        <ul v-if="fiche.fratrie.length > 0" class="flex flex-wrap gap-1.5">
          <li v-for="proche in fiche.fratrie" :key="proche.personId" class="rounded-pastille border border-ligne px-2 py-0.5 text-sm">
            {{ proche.displayName }}
          </li>
        </ul>
        <div
          v-for="(ligne, index) in saisie.fratrie"
          :key="cleDuChoix(ligne.personne)"
          class="flex flex-wrap items-center gap-2 rounded-champ border border-accent bg-accent-doux px-2.5 py-1.5"
        >
          <span class="flex-1 text-sm font-medium">+ {{ ligne.personne.libelle }}</span>
          <select v-if="famillesDOrigine.length > 1" v-model="ligne.familleId" :class="classeChamp" aria-label="Enfant de">
            <option v-for="famille in famillesDOrigine" :key="famille.familleId" :value="famille.familleId">
              Enfant de {{ famille.libelle }}
            </option>
          </select>
          <input
            :value="ligne.rangFratrie ?? ''"
            type="number"
            min="0"
            inputmode="numeric"
            placeholder="Rang"
            aria-label="Rang dans la fratrie"
            :class="classeRang"
            @input="ligne.rangFratrie = rangDepuisSaisie(($event.target as HTMLInputElement).value)"
          />
          <BoutonBase variante="discret" @click="retirer(saisie.fratrie, index)">Retirer</BoutonBase>
        </div>
        <SelecteurPersonne
          v-if="famillesDOrigine.length > 0"
          etiquette="Ajouter un frère ou une sœur"
          :deja-choisis="dejaChoisis"
          @choisir="ajouterFratrie"
        />
        <p v-else class="text-xs text-attenue">Ajoutez d’abord un parent.</p>
      </section>

      <!-- ── Conjoints ────────────────────────────────────────────── -->
      <section class="flex flex-col gap-2">
        <h3 class="text-sm font-semibold">Conjoints</h3>
        <ul v-if="unionsExistantes.length > 0" class="flex flex-wrap gap-1.5">
          <li v-for="union in unionsExistantes" :key="union.familleId" class="rounded-pastille border border-ligne px-2 py-0.5 text-sm">
            {{ union.libelle }}
          </li>
        </ul>
        <article
          v-for="(union, index) in saisie.unions"
          :key="cleDuChoix(union.personne)"
          class="flex flex-col gap-3 rounded-champ border border-accent bg-accent-doux px-3 py-2.5"
        >
          <header class="flex items-center justify-between gap-2">
            <span class="text-sm font-medium">+ {{ union.personne.libelle }}</span>
            <BoutonBase variante="discret" @click="retirer(saisie.unions, index)">Retirer</BoutonBase>
          </header>
          <ChampDateIncertaine
            v-model="union.mariage"
            etiquette="Mariage"
            :erreur="erreurs[`unions.${index}.mariage`] ?? ''"
          />
          <label class="flex items-center gap-2 text-sm">
            <input v-model="union.rompue" type="checkbox" class="size-4 accent-[var(--color-accent)]" />
            Divorce ou séparation
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
        </article>
        <SelecteurPersonne etiquette="Ajouter un conjoint" :deja-choisis="dejaChoisis" @choisir="ajouterUnion" />
      </section>

      <!-- ── Enfants ──────────────────────────────────────────────── -->
      <section class="flex flex-col gap-2">
        <h3 class="text-sm font-semibold">Enfants</h3>
        <ul v-if="enfantsExistants.length > 0" class="flex flex-wrap gap-1.5">
          <li v-for="enfant in enfantsExistants" :key="enfant.personId" class="rounded-pastille border border-ligne px-2 py-0.5 text-sm">
            {{ enfant.displayName }}
          </li>
        </ul>
        <div
          v-for="(enfant, index) in saisie.enfants"
          :key="cleDuChoix(enfant.personne)"
          class="flex flex-wrap items-center gap-2 rounded-champ border border-accent bg-accent-doux px-2.5 py-1.5"
        >
          <span class="flex-1 text-sm font-medium">+ {{ enfant.personne.libelle }}</span>
          <select v-model="enfant.familleId" :class="classeChamp" aria-label="Enfant de">
            <option v-for="union in unionsExistantes" :key="union.familleId" :value="union.familleId">
              avec {{ union.libelle }}
            </option>
            <option :value="null">autre parent inconnu</option>
          </select>
          <input
            :value="enfant.rangFratrie ?? ''"
            type="number"
            min="0"
            inputmode="numeric"
            placeholder="Rang"
            aria-label="Rang dans la fratrie"
            :class="classeRang"
            @input="enfant.rangFratrie = rangDepuisSaisie(($event.target as HTMLInputElement).value)"
          />
          <BoutonBase variante="discret" @click="retirer(saisie.enfants, index)">Retirer</BoutonBase>
        </div>
        <SelecteurPersonne etiquette="Ajouter un enfant" :deja-choisis="dejaChoisis" @choisir="ajouterEnfant" />
      </section>

      <p v-if="erreurs['liens']" class="text-xs text-alerte">{{ erreurs['liens'] }}</p>
    </div>
  </PanneauBase>
</template>
