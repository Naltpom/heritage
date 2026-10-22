<script setup lang="ts">
/**
 * La famille **en mode édition** : une croix par lien, une recherche pour en
 * ajouter.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## La croix retire le lien, pas la personne
 *
 * Barrer un parent, un conjoint ou un enfant ne supprime aucune fiche : le lien
 * part en demande `retrait_lien` (§ 6.4 quinquies), et c'est le serveur qui
 * mesure ce qu'il fera — l'union conservée tant qu'elle porte quoi que ce soit,
 * supprimée seulement si elle ne porte plus rien. Ce que chaque croix veut dire
 * est écrit dans son infobulle, et dans l'en-tête de `edition.ts`.
 *
 * Une croix ne retire rien tout de suite : la ligne se barre, et la même place
 * offre « Rétablir ». Tout part ensemble, au « Corriger », après le récapitulatif.
 *
 * ## Les frères et sœurs n'ont pas de croix
 *
 * Ils se déduisent des parents — « enfant du même couple » — et n'ont aucun lien
 * propre à retirer. Une croix ici devrait deviner lequel des deux parents ils ne
 * partagent pas ; l'écran le dit au lieu de deviner.
 *
 * ## Ajouter une personne qui existe
 *
 * `SelecteurPersonne`, la recherche de l'arbre (§ 5.5) — **sans** les fiches
 * proposées : on relie ici des personnes de l'arbre. Un enfant s'ajoute **sous
 * une union** (il rejoint le couple), ou à part, avec la fiche pour seul parent
 * connu : c'est le choix que `liens.ts` explique, et la seconde forme ne doit pas
 * être le défaut quand une union existe.
 *
 * ## Une union : sa nature, son mariage, sa rupture
 *
 * Sous chaque union — existante ou ajoutée — `EditionDUneUnion` : « Mariés : oui /
 * non (union libre) / on ne sait pas », la date et le lieu du mariage, la rupture
 * même sans date — « divorcés » ou « séparés » selon la nature — et sa date
 * (`edition.ts`, en-tête ; ADR 0020).
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, ref } from 'vue'
import type { FichePersonneComplete } from '@/lib/api'
import SelecteurPersonne from '@/components/proposition/SelecteurPersonne.vue'
import { cleDuChoix, type ChoixDePersonne } from '@/components/proposition/creation'
import EditionDUneUnion from './EditionDUneUnion.vue'
import {
  basculerRetrait,
  cleDEnfant,
  cleDeConjoint,
  cleDeParent,
  cleDUnion,
  cleDUnionAjoutee,
  datesDUnionVides,
  dejaLies,
  parentsApresEdition,
  type AjoutsDeLiens,
  type DatesDUnion,
} from './edition'

const { fiche } = defineProps<{ fiche: FichePersonneComplete }>()

const retraits = defineModel<string[]>('retraits', { required: true })
const ajouts = defineModel<AjoutsDeLiens>('ajouts', { required: true })
const unions = defineModel<Record<string, DatesDUnion>>('unions', { default: () => ({}) })

/** Les cases d'une union ; une clé encore absente se crée vide à la première frappe. */
function dates(cle: string): DatesDUnion {
  return unions.value[cle] ?? datesDUnionVides()
}
function remplacer(cle: string, valeur: DatesDUnion): void {
  unions.value = { ...unions.value, [cle]: valeur }
}

/** La recherche ouverte — une seule à la fois, pour ne pas noyer la fiche. */
const recherche = ref<string | null>(null)

const etat = computed(() => ({ retraits: retraits.value, ajouts: ajouts.value }))
const exclus = computed(() => dejaLies(fiche, etat.value))
const peutAjouterUnParent = computed(() => parentsApresEdition(fiche, etat.value) < 2)

function barre(cle: string): boolean {
  return retraits.value.includes(cle)
}

function basculer(cle: string): void {
  retraits.value = basculerRetrait(retraits.value, cle)
}

function ouvrir(cle: string): void {
  recherche.value = recherche.value === cle ? null : cle
}

function ajouterParent(personne: ChoixDePersonne): void {
  ajouts.value = { ...ajouts.value, parents: [...ajouts.value.parents, personne] }
  recherche.value = null
}
function ajouterConjoint(personne: ChoixDePersonne): void {
  ajouts.value = { ...ajouts.value, conjoints: [...ajouts.value.conjoints, personne] }
  unions.value = { ...unions.value, [cleDUnionAjoutee(personne)]: datesDUnionVides() }
  recherche.value = null
}
function ajouterEnfant(personne: ChoixDePersonne, familleId: number | null): void {
  ajouts.value = { ...ajouts.value, enfants: [...ajouts.value.enfants, { personne, familleId }] }
  recherche.value = null
}
function oublier(type: 'parents' | 'conjoints' | 'enfants', cle: string): void {
  if (type === 'conjoints') {
    unions.value = Object.fromEntries(Object.entries(unions.value).filter(([c]) => c !== `ajout:${cle}`))
  }
  ajouts.value = {
    ...ajouts.value,
    parents: type === 'parents' ? ajouts.value.parents.filter((p) => cleDuChoix(p) !== cle) : ajouts.value.parents,
    conjoints: type === 'conjoints' ? ajouts.value.conjoints.filter((p) => cleDuChoix(p) !== cle) : ajouts.value.conjoints,
    enfants: type === 'enfants' ? ajouts.value.enfants.filter((e) => cleDuChoix(e.personne) !== cle) : ajouts.value.enfants,
  }
}

const enfantsAjoutes = (familleId: number | null) => ajouts.value.enfants.filter((e) => e.familleId === familleId)

/** Ce que la croix d'un parent fera, dit avant qu'on clique. */
function aideParent(nom: string, familleId: number): string {
  const autres = fiche.parents.filter((p) => p.familyId === familleId && !barre(cleDeParent(p.personId, familleId)))
  const autre = autres.find((p) => p.displayName !== nom)
  return autre
    ? `Retirer ce parent (${autre.displayName} reste parent)`
    : 'Retirer ce parent'
}
</script>

<template>
  <div class="flex flex-col gap-5 text-sm" data-edition-parente>
    <!-- Unions ---------------------------------------------------- -->
    <section>
      <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">Unions</h3>

      <div
        v-for="union in fiche.unions"
        :key="union.familyId"
        class="mb-3 border-l-2 border-edition-bord pl-3"
      >
        <div v-if="union.conjoint !== null" class="flex items-center justify-between gap-2" :data-lien="cleDeConjoint(union.conjoint.personId, union.familyId)">
          <span :class="barre(cleDeConjoint(union.conjoint.personId, union.familyId)) ? 'text-attenue line-through' : 'font-semibold'">
            {{ union.conjoint.displayName }}
          </span>
          <button
            type="button"
            class="inline-flex min-h-9 min-w-9 items-center justify-center rounded-champ border text-xs font-semibold"
            :class="barre(cleDeConjoint(union.conjoint.personId, union.familyId)) ? 'border-ligne px-2 text-texte' : 'border-alerte-bord text-alerte hover:bg-alerte-doux'"
            :aria-label="barre(cleDeConjoint(union.conjoint.personId, union.familyId)) ? `Garder ${union.conjoint.displayName} comme conjoint` : `Retirer ${union.conjoint.displayName} comme conjoint`"
            :title="
              barre(cleDeConjoint(union.conjoint.personId, union.familyId))
                ? 'Rétablir ce lien'
                : `Retirer ce conjoint (les enfants restent ceux de ${fiche.displayName})`
            "
            data-croix="conjoint"
            @click="basculer(cleDeConjoint(union.conjoint.personId, union.familyId))"
          >
            <template v-if="barre(cleDeConjoint(union.conjoint.personId, union.familyId))">Rétablir</template>
            <svg v-else viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
          </button>
        </div>
        <p v-else class="m-0 text-attenue italic">conjoint inconnu</p>

        <div
          v-if="union.conjoint === null || !barre(cleDeConjoint(union.conjoint.personId, union.familyId))"
          class="mt-2"
          :data-dates-union="union.familyId"
        >
          <EditionDUneUnion
            :model-value="dates(cleDUnion(union.familyId))"
            @update:model-value="remplacer(cleDUnion(union.familyId), $event)"
          />
        </div>

        <ul class="m-0 mt-1 flex list-none flex-col gap-0.5 p-0">
          <li
            v-for="enfant in union.enfants"
            :key="enfant.personId"
            class="flex items-center justify-between gap-2"
            :data-lien="cleDEnfant(enfant.personId, union.familyId)"
          >
            <span :class="barre(cleDEnfant(enfant.personId, union.familyId)) ? 'text-attenue line-through' : ''">
              Enfant : {{ enfant.displayName }}
            </span>
            <button
              type="button"
              class="inline-flex min-h-9 min-w-9 items-center justify-center rounded-champ border text-xs font-semibold"
              :class="barre(cleDEnfant(enfant.personId, union.familyId)) ? 'border-ligne px-2 text-texte' : 'border-alerte-bord text-alerte hover:bg-alerte-doux'"
              :aria-label="barre(cleDEnfant(enfant.personId, union.familyId)) ? `Garder ${enfant.displayName} comme enfant` : `Retirer ${enfant.displayName} comme enfant`"
              :title="
                barre(cleDEnfant(enfant.personId, union.familyId))
                  ? 'Rétablir ce lien'
                  : 'Retirer cet enfant du couple'
              "
              data-croix="enfant"
              @click="basculer(cleDEnfant(enfant.personId, union.familyId))"
            >
              <template v-if="barre(cleDEnfant(enfant.personId, union.familyId))">Rétablir</template>
              <svg v-else viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
            </button>
          </li>
          <li
            v-for="ajoute in enfantsAjoutes(union.familyId)"
            :key="cleDuChoix(ajoute.personne)"
            class="flex items-center justify-between gap-2"
            data-ajout="enfant"
          >
            <span><span class="font-semibold text-edition">+</span> Enfant : {{ ajoute.personne.libelle }}</span>
            <button type="button" class="min-h-9 rounded-champ border border-ligne px-2 text-xs" :aria-label="`Ne pas ajouter ${ajoute.personne.libelle}`" title="Ne pas ajouter" @click="oublier('enfants', cleDuChoix(ajoute.personne))">Retirer</button>
          </li>
        </ul>

        <button
          type="button"
          class="mt-1 text-xs font-semibold text-edition underline"
          :aria-expanded="recherche === `enfant:${union.familyId}`"
          @click="ouvrir(`enfant:${union.familyId}`)"
        >
          + Ajouter un enfant de ce couple
        </button>
        <div v-if="recherche === `enfant:${union.familyId}`" class="mt-2">
          <SelecteurPersonne
            etiquette="Enfant à rattacher à ce couple"
            :deja-choisis="exclus"
            :avec-propositions="false"
            @choisir="ajouterEnfant($event, union.familyId)"
          />
        </div>
      </div>

      <ul v-if="ajouts.conjoints.length > 0" class="m-0 mb-2 flex list-none flex-col gap-0.5 p-0">
        <li v-for="conjoint in ajouts.conjoints" :key="cleDuChoix(conjoint)" class="mb-2 flex flex-col gap-2 border-l-2 border-edition-bord pl-3" data-ajout="conjoint">
          <div class="flex items-center justify-between gap-2">
            <span><span class="font-semibold text-edition">+</span> Conjoint : {{ conjoint.libelle }}</span>
            <button type="button" class="min-h-9 rounded-champ border border-ligne px-2 text-xs" :aria-label="`Ne pas ajouter ${conjoint.libelle}`" title="Ne pas ajouter" @click="oublier('conjoints', cleDuChoix(conjoint))">Retirer</button>
          </div>
          <EditionDUneUnion
            :model-value="dates(cleDUnionAjoutee(conjoint))"
            @update:model-value="remplacer(cleDUnionAjoutee(conjoint), $event)"
          />
        </li>
      </ul>
      <ul v-if="enfantsAjoutes(null).length > 0" class="m-0 mb-2 flex list-none flex-col gap-0.5 p-0">
        <li v-for="ajoute in enfantsAjoutes(null)" :key="cleDuChoix(ajoute.personne)" class="flex items-center justify-between gap-2" data-ajout="enfant">
          <span><span class="font-semibold text-edition">+</span> Enfant (autre parent inconnu) : {{ ajoute.personne.libelle }}</span>
          <button type="button" class="min-h-9 rounded-champ border border-ligne px-2 text-xs" :aria-label="`Ne pas ajouter ${ajoute.personne.libelle}`" title="Ne pas ajouter" @click="oublier('enfants', cleDuChoix(ajoute.personne))">Retirer</button>
        </li>
      </ul>

      <div class="flex flex-wrap gap-x-4 gap-y-1">
        <button type="button" class="text-xs font-semibold text-edition underline" :aria-expanded="recherche === 'conjoint'" @click="ouvrir('conjoint')">
          + Ajouter un conjoint
        </button>
        <button type="button" class="text-xs font-semibold text-edition underline" :aria-expanded="recherche === 'enfant-seul'" @click="ouvrir('enfant-seul')">
          + Ajouter un enfant d’un autre parent
        </button>
      </div>
      <div v-if="recherche === 'conjoint'" class="mt-2">
        <SelecteurPersonne etiquette="Conjoint à ajouter" :deja-choisis="exclus" :avec-propositions="false" @choisir="ajouterConjoint" />
      </div>
      <div v-if="recherche === 'enfant-seul'" class="mt-2">
        <SelecteurPersonne
          etiquette="Enfant à ajouter"
          aide="L’autre parent restera inconnu. Pour l’enfant d’un couple ci-dessus, passez par ce couple."
          :deja-choisis="exclus"
          :avec-propositions="false"
          @choisir="ajouterEnfant($event, null)"
        />
      </div>
    </section>

    <!-- Parents --------------------------------------------------- -->
    <section>
      <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">Parents</h3>
      <ul class="m-0 flex list-none flex-col gap-0.5 p-0">
        <li
          v-for="parent in fiche.parents"
          :key="cleDeParent(parent.personId, parent.familyId)"
          class="flex items-center justify-between gap-2"
          :data-lien="cleDeParent(parent.personId, parent.familyId)"
        >
          <span :class="barre(cleDeParent(parent.personId, parent.familyId)) ? 'text-attenue line-through' : ''">
            {{ parent.displayName }}
          </span>
          <button
            type="button"
            class="inline-flex min-h-9 min-w-9 items-center justify-center rounded-champ border text-xs font-semibold"
            :class="barre(cleDeParent(parent.personId, parent.familyId)) ? 'border-ligne px-2 text-texte' : 'border-alerte-bord text-alerte hover:bg-alerte-doux'"
            :aria-label="barre(cleDeParent(parent.personId, parent.familyId)) ? `Garder ${parent.displayName} comme parent` : `Retirer ${parent.displayName} comme parent`"
            :title="barre(cleDeParent(parent.personId, parent.familyId)) ? 'Rétablir ce lien' : aideParent(parent.displayName, parent.familyId)"
            data-croix="parent"
            @click="basculer(cleDeParent(parent.personId, parent.familyId))"
          >
            <template v-if="barre(cleDeParent(parent.personId, parent.familyId))">Rétablir</template>
            <svg v-else viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
          </button>
        </li>
        <li v-for="parent in ajouts.parents" :key="cleDuChoix(parent)" class="flex items-center justify-between gap-2" data-ajout="parent">
          <span><span class="font-semibold text-edition">+</span> {{ parent.libelle }}</span>
          <button type="button" class="min-h-9 rounded-champ border border-ligne px-2 text-xs" :aria-label="`Ne pas ajouter ${parent.libelle}`" title="Ne pas ajouter" @click="oublier('parents', cleDuChoix(parent))">Retirer</button>
        </li>
      </ul>

      <template v-if="peutAjouterUnParent">
        <button type="button" class="mt-1 text-xs font-semibold text-edition underline" :aria-expanded="recherche === 'parent'" @click="ouvrir('parent')">
          + Ajouter un parent
        </button>
        <div v-if="recherche === 'parent'" class="mt-2">
          <SelecteurPersonne etiquette="Parent à ajouter" :deja-choisis="exclus" :avec-propositions="false" @choisir="ajouterParent" />
        </div>
      </template>
      <p v-else class="m-0 mt-1 text-xs text-attenue">Deux parents au plus : retirez-en un pour en désigner un autre.</p>
    </section>

    <!-- Fratrie -------------------------------------------------- -->
    <section v-if="fiche.fratrie.length > 0">
      <h3 class="mb-2 text-[11px] tracking-wider text-attenue uppercase">Frères et sœurs</h3>
      <ul class="m-0 list-none p-0">
        <li v-for="membre in fiche.fratrie" :key="membre.personId" class="py-0.5">{{ membre.displayName }}</li>
      </ul>
      <p class="m-0 mt-1 text-xs text-attenue" data-aide-fratrie>
        Pour les modifier, passez par leur fiche ou celle d’un parent.
      </p>
    </section>
  </div>
</template>
