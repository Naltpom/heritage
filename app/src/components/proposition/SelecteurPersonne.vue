<script setup lang="ts">
/**
 * Désigner une personne : **celles de l'arbre, et celles qui sont proposées**.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Sélectionner une personne n'est pas y accéder — mais la chercher, si.**
 *
 * La recherche de l'arbre passe par `GET /api/arbre/recherche`, « jamais par un
 * index reconstruit depuis le graphe » : le serveur y applique le cercle de
 * visibilité, et une personne vivante hors cercle en est **absente** — « elle
 * n'est pas rendue en version réduite : elle est absente » (§ 5.5). Un index
 * local bâti sur les `displayName` des nœuds la ferait réapparaître et
 * contournerait la règle sans qu'on l'ait voulu.
 *
 * Trois conséquences tenues ici :
 *
 * 1. **On affiche ce que le serveur renvoie, rien de plus.** Aucune complétion
 *    locale, aucun repli sur une liste gardée en mémoire.
 * 2. **Aucun compteur.** Ni « 3 résultats sur 12 », ni total avant filtrage :
 *    « un `total` avant filtrage indiquerait combien de personnes on cache ».
 *    `tronque` se dit en mots — le plafond a été atteint **sur le jeu déjà
 *    filtré** — et ne laisse rien déduire.
 * 3. **Une recherche sans résultat est un résultat, pas une panne.** Elle
 *    s'affiche comme telle, sans ton d'erreur : sur un arbre de 274 personnes,
 *    ne rien trouver est ordinaire.
 * ────────────────────────────────────────────────────────────────────
 *
 * ── Le second groupe : les personnes **proposées** ───────────────────────────
 *
 * Le défaut réparé, dans les mots du commanditaire : « si je repousse une autre
 * personne et que je cherche le père que j'ai proposé juste avant, non validé
 * encore mais proposé par moi, il ne m'est pas proposé ». Une fiche en attente
 * n'a pas d'identifiant de personne : elle n'est pas dans l'arbre, donc pas dans
 * `GET /api/arbre/recherche`. Elle se cherche sur sa propre route,
 * `GET /api/demandes/brouillons/recherche`, filtrée par la règle du § 13.3 —
 * l'auteur et les administrateurs, personne d'autre.
 *
 * Les **brouillons non envoyés** du lecteur en font partie (05/10/2026 : « il
 * faut que la liste des personnes, même mes demandes non acceptées, fasse partie
 * de la liste aussi ») ; la ligne le dit, puisque `enAttente` y vaut `false`.
 *
 * **Deux appels et deux groupes à l'écran, pas une liste fusionnée.** Une ligne
 * de l'arbre et une fiche proposée ne sont pas la même chose : l'une existe,
 * l'autre attend une décision. Les mélanger ferait désigner « le père » sans
 * savoir que sa validation conditionne celle qu'on est en train d'écrire — ce
 * que la pastille du second groupe dit explicitement.
 *
 * **L'échec du second appel n'efface pas le premier.** Les propositions sont un
 * complément ; si leur route échoue, l'écran doit rester utilisable sur l'arbre
 * validé, qui est le cas courant. Une erreur commune aux deux listes rendrait
 * l'inverse : une panne du complément empêcherait le geste principal.
 *
 * `q` doit faire au moins deux caractères, sinon l'API rend `422` : « c'est à
 * l'appelant de ne pas appeler avant ».
 */
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import {
  rechercherBrouillons,
  rechercherPersonnes,
  type BrouillonTrouve,
  type ResultatDeRecherche,
} from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import { cleDuChoix, type ChoixDePersonne } from './creation'

const {
  etiquette,
  aide = '',
  dejaChoisis = [],
  avecPropositions = true,
  vivantsSeulement = false,
} = defineProps<{
  etiquette: string
  aide?: string
  /** Clés déjà désignées ailleurs dans le formulaire ({@link cleDuChoix}). Elles
   *  restent **affichées** — masquer un résultat serait cacher ce que le serveur
   *  a rendu — mais leur bouton est éteint et dit pourquoi. */
  dejaChoisis?: string[]
  /** `false` n'interroge pas les propositions. Le seul cas est un écran où une
   *  fiche en attente n'aurait pas de sens ; on ne le devine pas, on le dit. */
  avecPropositions?: boolean
  /**
   * `true` ne propose que des personnes vivantes (`?vivants=1`, filtré par le
   * serveur). **Seuls les tirages au sort le posent** : on ne tire pas au sort
   * un défunt. Ce composant sert aussi les formulaires de proposition et les
   * actes demandés, où désigner un défunt est le cas courant — d'où une option
   * explicite, jamais un défaut.
   */
  vivantsSeulement?: boolean
}>()

const emit = defineEmits<{ choisir: [personne: ChoixDePersonne] }>()

const terme = ref('')
const resultats = ref<ResultatDeRecherche[]>([])
const propositions = ref<BrouillonTrouve[]>([])
const tronque = ref(false)
const chargement = ref(false)
const erreur = ref<unknown>(null)
/** Une recherche a-t-elle abouti depuis la dernière frappe ? Sans ce drapeau,
 *  « aucun résultat » s'afficherait avant même le premier appel. */
const interroge = ref(false)

const aideParDefaut = computed(() =>
  vivantsSeulement
    ? 'Parmi les personnes vivantes que vous pouvez voir.'
    : 'Parmi les personnes que vous pouvez voir.',
)

const identifiant = useId()
const idAide = computed(() => `${identifiant}-aide`)

const MINIMUM = 2
const DELAI_MS = 250

let minuterie: ReturnType<typeof setTimeout> | null = null
let enCours: AbortController | null = null

function annuler(): void {
  if (minuterie !== null) clearTimeout(minuterie)
  minuterie = null
  enCours?.abort()
  enCours = null
}

async function chercher(q: string): Promise<void> {
  const controleur = new AbortController()
  enCours = controleur
  chargement.value = true
  erreur.value = null

  // Les deux appels partent **en parallèle** : ils n'ont rien à s'apprendre, et
  // les enchaîner doublerait le temps d'attente sous la frappe.
  const arbre = rechercherPersonnes(q, 10, controleur.signal, vivantsSeulement)
  const enAttente = avecPropositions
    ? rechercherBrouillons(q, 10, controleur.signal)
    : Promise.resolve({ elements: [], tronque: false })

  try {
    // `allSettled` et non `all` : l'échec des propositions ne doit pas emporter
    // le résultat de l'arbre, qui est le cas courant de cet écran.
    const [deLArbre, desDemandes] = await Promise.allSettled([arbre, enAttente])
    if (controleur.signal.aborted) return

    if (deLArbre.status === 'fulfilled') {
      resultats.value = deLArbre.value.elements
      tronque.value = deLArbre.value.tronque
    } else {
      resultats.value = []
      tronque.value = false
      erreur.value = deLArbre.reason
    }

    // Une panne du complément se tait : elle laisse le groupe vide, et l'écran
    // reste utilisable. La signaler comme une erreur de recherche ferait croire
    // que l'arbre n'a pas répondu.
    propositions.value = desDemandes.status === 'fulfilled' ? desDemandes.value.elements : []

    interroge.value = true
  } finally {
    if (enCours === controleur) {
      chargement.value = false
      enCours = null
    }
  }
}

watch(terme, (valeur) => {
  annuler()
  const q = valeur.trim()
  if (q.length < MINIMUM) {
    resultats.value = []
    propositions.value = []
    tronque.value = false
    interroge.value = false
    return
  }
  minuterie = setTimeout(() => void chercher(q), DELAI_MS)
})

onBeforeUnmount(annuler)

function choisir(choix: ChoixDePersonne): void {
  emit('choisir', choix)
  terme.value = ''
  resultats.value = []
  propositions.value = []
  interroge.value = false
}

function choisirPersonne(resultat: ResultatDeRecherche): void {
  choisir({ personId: resultat.personId, libelle: resultat.displayName })
}

function choisirProposition(brouillon: BrouillonTrouve): void {
  choisir({
    draftPersonId: brouillon.draftPersonId,
    demandeId: brouillon.demandeId,
    libelle: brouillon.displayName,
  })
}

function estDejaChoisi(choix: ChoixDePersonne): boolean {
  return dejaChoisis.includes(cleDuChoix(choix))
}

const aucunResultat = computed(
  () =>
    interroge.value &&
    !chargement.value &&
    erreur.value === null &&
    resultats.value.length === 0 &&
    propositions.value.length === 0,
)
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="identifiant" class="text-sm font-medium">{{ etiquette }}</label>

    <input
      :id="identifiant"
      v-model="terme"
      type="search"
      autocomplete="off"
      placeholder="Nom ou prénom, deux lettres au minimum"
      :aria-describedby="idAide"
      class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
    />

    <p :id="idAide" class="text-xs text-attenue">
      {{ aide.length > 0 ? aide : aideParDefaut }}
    </p>

    <p v-if="chargement" class="text-xs text-attenue" role="status">Recherche…</p>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="() => void chercher(terme.trim())" />

    <!-- Une absence de résultat est une réponse, pas un incident : ton neutre,
         pas de `role="alert"`, pas de cadre d'alerte. -->
    <p v-else-if="aucunResultat" class="text-xs text-attenue" role="status">
      Aucune personne ne correspond à cette recherche.
    </p>

    <template v-else>
      <ul v-if="resultats.length > 0" class="flex flex-col gap-1">
        <li
          v-for="resultat in resultats"
          :key="resultat.personId"
          class="flex flex-wrap items-center justify-between gap-2 rounded-champ border border-ligne bg-panneau px-2.5 py-1.5"
        >
          <span class="min-w-0 text-sm">
            <span class="font-medium">{{ resultat.displayName }}</span>
            <!-- `ligneDates` est **absente** d'un résultat restreint : on ne la
                 remplace pas par un tiret, qui laisserait croire à une lacune de
                 la donnée là où c'est une règle de confidentialité (§ 1.3). -->
            <span v-if="resultat.ligneDates" class="ml-1.5 font-mono text-xs text-attenue tnum">
              {{ resultat.ligneDates }}
            </span>
          </span>

          <button
            type="button"
            :disabled="estDejaChoisi({ personId: resultat.personId, libelle: resultat.displayName })"
            class="rounded-champ border border-ligne px-2.5 py-1 text-xs font-semibold transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-55"
            @click="choisirPersonne(resultat)"
          >
            {{
              estDejaChoisi({ personId: resultat.personId, libelle: resultat.displayName })
                ? 'Déjà désigné'
                : 'Choisir'
            }}
          </button>
        </li>
      </ul>

      <!-- ── Les personnes proposées, dans leur propre groupe ──────────── -->
      <div v-if="propositions.length > 0" class="mt-1.5 flex flex-col gap-1">
        <p class="flex flex-wrap items-center gap-2 text-xs text-attenue">
          <PastilleEtat etat="en_attente" />
          <span>
            Pas encore validées : votre demande attendra leur validation.
          </span>
        </p>

        <ul class="flex flex-col gap-1">
          <li
            v-for="brouillon in propositions"
            :key="brouillon.draftPersonId"
            class="flex flex-wrap items-center justify-between gap-2 rounded-champ border border-dashed border-ligne bg-panneau px-2.5 py-1.5"
          >
            <span class="min-w-0 text-sm">
              <span class="font-medium">{{ brouillon.displayName }}</span>
              <span v-if="brouillon.ligneDates" class="ml-1.5 font-mono text-xs text-attenue tnum">
                {{ brouillon.ligneDates }}
              </span>
              <span class="block text-xs text-attenue">
                <template v-if="brouillon.enAttente">
                  Demande n° {{ brouillon.demandeId }}, proposée par {{ brouillon.authorLabel }}
                </template>
                <!-- Un brouillon que son auteur n'a pas encore envoyé : il ne le voit
                     que lui, et la demande qui s'y appuie attendra son envoi. -->
                <template v-else>
                  Votre brouillon n° {{ brouillon.demandeId }}, pas encore envoyé
                </template>
              </span>
            </span>

            <button
              type="button"
              :disabled="
                estDejaChoisi({
                  draftPersonId: brouillon.draftPersonId,
                  demandeId: brouillon.demandeId,
                  libelle: brouillon.displayName,
                })
              "
              class="rounded-champ border border-ligne px-2.5 py-1 text-xs font-semibold transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-55"
              @click="choisirProposition(brouillon)"
            >
              {{
                estDejaChoisi({
                  draftPersonId: brouillon.draftPersonId,
                  demandeId: brouillon.demandeId,
                  libelle: brouillon.displayName,
                })
                  ? 'Déjà désigné'
                  : 'Choisir'
              }}
            </button>
          </li>
        </ul>
      </div>
    </template>

    <!-- Le plafond a été atteint **sur le jeu déjà filtré**. On le dit en mots :
         un nombre ici laisserait déduire ce qui n'est pas montré. -->
    <p v-if="tronque" class="text-xs text-attenue">
      Seuls les premiers résultats sont affichés. Précisez votre recherche.
    </p>
  </div>
</template>
