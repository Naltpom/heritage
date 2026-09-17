<script setup lang="ts">
/**
 * **Proposer des liens de parenté sur une fiche existante** — § 6.4 bis.
 *
 * Dans les mots du commanditaire : *« quand je demande une modification je peux
 * pas ajouter les parents les frères et sœur ni les enfants. Il faut que ces
 * deux éléments soit possible. »*
 *
 * C'était exact. `modification_personne` ne porte que des **ops de champ**, et
 * la parenté n'est pas un champ de `tree_person` : c'est une ligne de
 * `tree_child` ou de `tree_family`. La nature `ajout_lien` existait depuis le
 * début — constructeur, applicateur, projection dans le graphe — mais **aucun
 * écran ne la déposait**. Celui-ci le fait.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Trois décisions, et ce que coûterait chacune inversée.**
 *
 * 1. **Un écran à part, pas un bloc de l'écran de modification.** Une demande
 *    porte **une** nature. Un formulaire qui déposerait champs *et* liens
 *    déposerait deux demandes sous un seul bouton : deux lignes dans la file,
 *    deux décisions, et un administrateur libre de valider l'une et refuser
 *    l'autre — un résultat que personne n'a voulu. Les deux écrans se renvoient
 *    l'un à l'autre.
 * 2. **La fiche est relue à l'ouverture, et ses unions servent de choix.** Un
 *    enfant rattaché « au couple » n'est pas la même chose qu'un enfant
 *    rattaché « à cette personne seule » : la seconde forme crée une famille à
 *    un parent, et le graphe montrerait deux losanges pour un couple qui
 *    existe. On ne devine donc pas : on demande.
 * 3. **La fratrie exige une famille d'origine.** `LinkKind` compte trois
 *    valeurs, « ni plus ni moins » : une fratrie s'écrit « enfant du même
 *    couple ». Sans parent connu, il n'y a aucun couple à désigner — et l'écran
 *    le dit, avec le geste à faire d'abord, au lieu d'offrir un champ qui
 *    échouerait au dépôt.
 * ────────────────────────────────────────────────────────────────────
 *
 * Une fiche **restreinte** — personne vivante hors du cercle du lecteur — ne se
 * complète pas ici : le lecteur n'en voit ni les parents ni les unions, il ne
 * peut donc désigner ni couple ni union. C'est l'impasse cohérente du § 5.1, et
 * l'écran la dit en une phrase plutôt que d'afficher des listes vides.
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  creerDemande,
  invaliderGraphe,
  lireFichePersonne,
  type FichePersonne,
  type FichePersonneComplete,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import ChampDateIncertaine from '@/components/proposition/ChampDateIncertaine.vue'
import SelecteurPersonne from '@/components/proposition/SelecteurPersonne.vue'
import {
  cleDuChoix,
  erreursParChamp,
  estFicheProposee,
  unionVide,
  type ChoixDePersonne,
} from '@/components/proposition/creation'
import {
  corpsAjoutLien,
  dejaDesignes,
  liensVides,
  nombreDeLiensProposes,
  type SaisieLiens,
} from '@/components/proposition/liens'

const route = useRoute()
const routeur = useRouter()
const { vueEmpruntee } = useSession()

const fiche = ref<FichePersonneComplete | null>(null)
const restreinte = ref(false)
const saisie = ref<SaisieLiens>(liensVides(0))
const message = ref('')

const chargement = ref(false)
const envoi = ref(false)
const erreur = ref<unknown>(null)
const erreurs = ref<Record<string, string>>({})
/** Le refus d'un geste impossible — « cette fiche est déjà celle-ci ». Il n'a
 *  rien à faire dans `erreurs`, qui porte les messages du dépôt. */
const refus = ref<string | null>(null)

const identifiant = computed(() => Number(route.params['id']))

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  refus.value = null
  try {
    const recue: FichePersonne = await lireFichePersonne(identifiant.value)

    // Discriminer sur `visibilite` **avant** de lire un champ : une fiche
    // réduite porte sept clés, et `fiche.parents` n'y existe pas (§ 11.1).
    if (recue.visibilite === 'restreinte') {
      restreinte.value = true
      fiche.value = null
      return
    }

    restreinte.value = false
    fiche.value = recue
    saisie.value = liensVides(recue.id)
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

watch(identifiant, charger, { immediate: true })

/* --- Ce que la fiche offre comme points d'ancrage ----------------- */

/**
 * Les familles d'origine de la fiche, dédoublonnées et nommées par leurs
 * parents.
 *
 * `fiche.parents` porte une ligne **par parent**, chacune avec le `familyId` de
 * la filiation : deux parents d'un même couple donnent donc deux lignes et un
 * seul couple. Une fiche peut en avoir plusieurs — adoption, remariage —, et
 * c'est le seul cas où l'écran doit demander laquelle.
 */
const famillesDOrigine = computed(() => {
  const parGroupe = new Map<number, string[]>()
  for (const parent of fiche.value?.parents ?? []) {
    const noms = parGroupe.get(parent.familyId) ?? []
    noms.push(parent.displayName)
    parGroupe.set(parent.familyId, noms)
  }
  return [...parGroupe.entries()].map(([familleId, noms]) => ({
    familleId,
    libelle: noms.join(' et '),
  }))
})

/** Les unions de la fiche, nommées par le conjoint quand il est connu — trois
 *  familles réelles de cet arbre n'ont qu'un époux, et c'est un état du travail
 *  généalogique, pas une donnée manquante. */
const unionsExistantes = computed(() =>
  (fiche.value?.unions ?? []).map((union) => ({
    familleId: union.familyId,
    libelle: union.conjoint?.displayName ?? 'conjoint inconnu',
  })),
)

const dejaChoisis = computed(() => dejaDesignes(saisie.value))
const total = computed(() => nombreDeLiensProposes(saisie.value))

/* --- Les gestes --------------------------------------------------- */

/** On ne rattache pas une fiche à elle-même. Le sélecteur éteint déjà le
 *  bouton ; ceci est le filet, et il dit pourquoi plutôt que de ne rien faire. */
function refuseLaFicheElleMeme(choix: ChoixDePersonne): boolean {
  if (estFicheProposee(choix) || choix.personId !== identifiant.value) return false
  refus.value = 'Cette fiche est celle que vous complétez : elle ne peut pas être son propre parent.'
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
  // Une seule union : on la préselectionne. Plusieurs, ou aucune : on laisse le
  // choix « parent seul », qui est vrai jusqu'à ce que le contributeur dise
  // mieux — deviner l'union rattacherait l'enfant à un couple qu'il n'a pas
  // désigné.
  const seule = unionsExistantes.value.length === 1 ? unionsExistantes.value[0]!.familleId : null
  saisie.value.enfants.push({ personne, familleId: seule, rangFratrie: null })
}

function retirer(liste: { splice: (index: number, nombre: number) => unknown }, index: number): void {
  liste.splice(index, 1)
}

/** Un rang vide vaut `null` — « à la fin », et non zéro, qui voudrait dire
 *  « l'aîné ». La nuance a un sens généalogique. */
function rangDepuisSaisie(brut: string): number | null {
  const propre = brut.trim()
  return propre.length === 0 ? null : Number(propre)
}

/* --- Le dépôt ----------------------------------------------------- */

async function deposer(soumettre: boolean): Promise<void> {
  if (envoi.value || fiche.value === null) return

  envoi.value = true
  erreur.value = null
  erreurs.value = {}

  try {
    const resultat = corpsAjoutLien(saisie.value, { message: message.value, soumettre })

    if (!resultat.valide) {
      erreurs.value = erreursParChamp(resultat.erreurs)
      return
    }

    const demande = await creerDemande(resultat.corps)

    // Le graphe incorpore les liens en attente du lecteur : son `ETag` est
    // périmé dès qu'une demande naît (§ 5.2, § 13.4).
    invaliderGraphe()

    await routeur.push({ name: 'demande.detail', params: { id: demande.id } })
  } catch (cause) {
    erreur.value = cause
  } finally {
    envoi.value = false
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-8">
    <div v-if="chargement && fiche === null && !restreinte">
      <PanneauBase>
        <p class="mb-3 text-sm text-attenue" role="status">Chargement de la fiche…</p>
        <SqueletteChargement :lignes="5" />
      </PanneauBase>
    </div>

    <MessageErreur v-else-if="erreur !== null && fiche === null" :erreur="erreur" @action="charger" />

    <PanneauBase v-else-if="restreinte" titre="Cette fiche ne peut pas être complétée ici">
      <p class="text-sm">
        Vous voyez le nom de cette personne dans l’arbre, mais aucune de ses données :
        rattacher une parenté supposerait de savoir ce qu’elle porte déjà.
      </p>
      <p class="mt-2 text-sm text-attenue">
        Un administrateur peut vous rattacher autrement, ou déposer le lien à votre place.
      </p>
    </PanneauBase>

    <template v-else-if="fiche !== null">
      <header class="mb-5">
        <h1 class="text-xl font-semibold tracking-tight">
          Proposer des liens de parenté — {{ fiche.displayName }}
        </h1>
        <p class="mt-1.5 text-sm text-attenue">
          Parents, frères et sœurs, conjoints, enfants. Votre proposition part en validation,
          comme une correction de fiche ; rien n’est modifié dans l’arbre avant l’accord d’un
          administrateur.
        </p>
        <p class="mt-1.5 text-sm text-attenue">
          Pour corriger un nom ou une date, c’est
          <RouterLink
            :to="{ name: 'proposition.modification', params: { id: identifiant } }"
            class="underline"
          >
            l’autre formulaire
          </RouterLink>
          : une demande porte une seule nature, et les deux ne se valident pas ensemble.
        </p>
      </header>

      <p
        v-if="motifSuspendu !== null"
        class="mb-4 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
        role="status"
      >
        {{ motifSuspendu }}
      </p>

      <MessageErreur v-if="erreur !== null" :erreur="erreur" class="mb-4" />

      <p v-if="refus !== null" class="mb-4 text-sm text-alerte" role="status">{{ refus }}</p>

      <form class="flex flex-col gap-4" @submit.prevent="deposer(true)">
        <!-- ── Parents ────────────────────────────────────────────── -->
        <PanneauBase titre="Parents">
          <div class="flex flex-col gap-3">
            <ul v-if="saisie.parents.length > 0" class="flex flex-col gap-1.5">
              <li
                v-for="(parent, index) in saisie.parents"
                :key="cleDuChoix(parent)"
                class="flex flex-wrap items-center justify-between gap-2 rounded-champ border border-ligne px-2.5 py-1.5"
              >
                <span class="text-sm font-medium">
                  {{ parent.libelle }}
                  <span v-if="estFicheProposee(parent)" class="ml-1 text-xs font-normal text-attenue">
                    proposée, demande n° {{ parent.demandeId }}
                  </span>
                </span>
                <BoutonBase variante="discret" @click="retirer(saisie.parents, index)">
                  Retirer
                </BoutonBase>
              </li>
            </ul>

            <SelecteurPersonne
              v-if="saisie.parents.length < 2"
              etiquette="Ajouter un parent"
              aide="Un ou deux parents. Le service rapprochera le couple — ou le créera — au moment de la validation."
              :deja-choisis="dejaChoisis"
              @choisir="ajouterParent"
            />

            <p v-if="erreurs['parents']" class="text-xs text-alerte">{{ erreurs['parents'] }}</p>
          </div>
        </PanneauBase>

        <!-- ── Frères et sœurs ────────────────────────────────────── -->
        <PanneauBase titre="Frères et sœurs">
          <!-- Sans famille d'origine, il n'y a aucun couple à désigner : on le
               dit, avec le geste à faire d'abord, plutôt que d'offrir un champ
               qui échouerait au dépôt. -->
          <p v-if="famillesDOrigine.length === 0" class="text-sm text-attenue">
            Cette fiche n’a pas encore de parent connu. Une fratrie s’exprime par « enfant des
            mêmes parents » : commencez par ajouter un parent ci-dessus, puis revenez rattacher
            les frères et sœurs.
          </p>

          <div v-else class="flex flex-col gap-3">
            <article
              v-for="(ligne, index) in saisie.fratrie"
              :key="cleDuChoix(ligne.personne)"
              class="flex flex-col gap-2 rounded-champ border border-ligne px-3 py-2.5"
            >
              <header class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-sm font-medium">
                  {{ ligne.personne.libelle }}
                  <span
                    v-if="estFicheProposee(ligne.personne)"
                    class="ml-1 text-xs font-normal text-attenue"
                  >
                    proposé·e, demande n° {{ ligne.personne.demandeId }}
                  </span>
                </span>
                <BoutonBase variante="discret" @click="retirer(saisie.fratrie, index)">
                  Retirer
                </BoutonBase>
              </header>

              <label v-if="famillesDOrigine.length > 1" class="flex flex-col gap-1">
                <span class="text-xs text-attenue">Enfant de</span>
                <select
                  v-model="ligne.familleId"
                  class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
                >
                  <option v-for="famille in famillesDOrigine" :key="famille.familleId" :value="famille.familleId">
                    {{ famille.libelle }}
                  </option>
                </select>
              </label>
              <p v-else class="text-xs text-attenue">
                Enfant de {{ famillesDOrigine[0]!.libelle }}, comme cette fiche.
              </p>

              <label class="flex flex-col gap-1">
                <span class="text-xs text-attenue">Rang dans la fratrie</span>
                <input
                  :value="ligne.rangFratrie ?? ''"
                  type="number"
                  min="0"
                  inputmode="numeric"
                  placeholder="—"
                  class="w-20 rounded-champ border border-ligne bg-fond px-2.5 py-1.5 font-mono text-sm text-texte tnum"
                  @input="ligne.rangFratrie = rangDepuisSaisie(($event.target as HTMLInputElement).value)"
                />
              </label>
            </article>

            <SelecteurPersonne
              etiquette="Ajouter un frère ou une sœur"
              aide="Il sera rattaché aux mêmes parents que cette fiche. Le rang est facultatif."
              :deja-choisis="dejaChoisis"
              @choisir="ajouterFratrie"
            />
          </div>
        </PanneauBase>

        <!-- ── Conjoints ──────────────────────────────────────────── -->
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
                  <span
                    v-if="estFicheProposee(union.personne)"
                    class="ml-1 text-xs font-normal text-attenue"
                  >
                    proposé·e, demande n° {{ union.personne.demandeId }}
                  </span>
                </h3>
                <BoutonBase variante="discret" @click="retirer(saisie.unions, index)">
                  Retirer
                </BoutonBase>
              </header>

              <ChampDateIncertaine
                v-model="union.mariage"
                etiquette="Mariage"
                :erreur="erreurs[`unions.${index}.mariage`] ?? ''"
                aide="Facultatif : une union sans date reste une union."
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
                    Divorce ou séparation. La date peut rester inconnue.
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

        <!-- ── Enfants ────────────────────────────────────────────── -->
        <PanneauBase titre="Enfants">
          <div class="flex flex-col gap-3">
            <article
              v-for="(enfant, index) in saisie.enfants"
              :key="cleDuChoix(enfant.personne)"
              class="flex flex-col gap-2 rounded-champ border border-ligne px-3 py-2.5"
            >
              <header class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-sm font-medium">
                  {{ enfant.personne.libelle }}
                  <span
                    v-if="estFicheProposee(enfant.personne)"
                    class="ml-1 text-xs font-normal text-attenue"
                  >
                    proposé·e, demande n° {{ enfant.personne.demandeId }}
                  </span>
                </span>
                <BoutonBase variante="discret" @click="retirer(saisie.enfants, index)">
                  Retirer
                </BoutonBase>
              </header>

              <!-- Rattacher au couple ou à la personne seule n'est pas la même
                   chose : la seconde forme crée une famille à un parent. On
                   demande plutôt que de deviner. -->
              <label class="flex flex-col gap-1">
                <span class="text-xs text-attenue">Enfant de</span>
                <select
                  v-model="enfant.familleId"
                  class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
                >
                  <option
                    v-for="union in unionsExistantes"
                    :key="union.familleId"
                    :value="union.familleId"
                  >
                    {{ fiche.displayName }} et {{ union.libelle }}
                  </option>
                  <option :value="null">{{ fiche.displayName }} seul·e (autre parent inconnu)</option>
                </select>
              </label>

              <label class="flex flex-col gap-1">
                <span class="text-xs text-attenue">Rang dans la fratrie</span>
                <input
                  :value="enfant.rangFratrie ?? ''"
                  type="number"
                  min="0"
                  inputmode="numeric"
                  placeholder="—"
                  class="w-20 rounded-champ border border-ligne bg-fond px-2.5 py-1.5 font-mono text-sm text-texte tnum"
                  @input="enfant.rangFratrie = rangDepuisSaisie(($event.target as HTMLInputElement).value)"
                />
              </label>
            </article>

            <SelecteurPersonne
              etiquette="Ajouter un enfant"
              aide="Le rang est facultatif : sans lui, l’enfant est rattaché à la fin de la fratrie."
              :deja-choisis="dejaChoisis"
              @choisir="ajouterEnfant"
            />
          </div>
        </PanneauBase>

        <PanneauBase titre="Ce que vous voulez dire à l’administrateur">
          <label class="flex flex-col gap-1">
            <textarea
              v-model="message"
              rows="3"
              placeholder="« C’est ma grand-tante, j’ai le livret de famille. »"
              class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
            />
            <span class="text-xs text-attenue">
              Facultatif, mais c’est souvent ce qui emporte la décision.
            </span>
          </label>
        </PanneauBase>

        <!-- Le compte ne bloque rien : il rappelle la règle de la soumission. -->
        <p class="text-sm" :class="erreurs['liens'] ? 'text-alerte' : 'text-attenue'" role="status">
          <template v-if="erreurs['liens']">{{ erreurs['liens'] }}</template>
          <template v-else-if="total === 0">
            Aucun lien pour l’instant. Il en faudra au moins un pour envoyer la demande.
          </template>
          <template v-else>
            {{ total }} lien{{ total > 1 ? 's' : '' }} à proposer.
          </template>
        </p>

        <div class="flex flex-wrap items-center gap-2">
          <BoutonBase type="submit" :chargement="envoi" :desactive="suspendu">
            Envoyer la proposition
          </BoutonBase>
          <BoutonBase variante="secondaire" :desactive="suspendu || envoi" @click="deposer(false)">
            Enregistrer sans envoyer
          </BoutonBase>
          <RouterLink
            :to="{ name: 'personne.detail', params: { id: identifiant } }"
            class="text-sm text-attenue underline"
          >
            Revenir à la fiche
          </RouterLink>
        </div>
      </form>
    </template>
  </div>
</template>
