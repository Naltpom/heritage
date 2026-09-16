<script setup lang="ts">
/**
 * Désigner une personne **qui existe déjà** dans l'arbre.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Sélectionner une personne n'est pas y accéder — mais la chercher, si.**
 *
 * La recherche passe par `GET /api/arbre/recherche`, « jamais par un index
 * reconstruit depuis le graphe » : le serveur y applique le cercle de
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
 * `q` doit faire au moins deux caractères, sinon l'API rend `422` : « c'est à
 * l'appelant de ne pas appeler avant ».
 */
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import { rechercherPersonnes, type ResultatDeRecherche } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import type { PersonneChoisie } from './creation'

const { etiquette, aide = '', dejaChoisis = [] } = defineProps<{
  etiquette: string
  aide?: string
  /** Identifiants déjà désignés ailleurs dans le formulaire. Ils restent
   *  **affichés** — masquer un résultat serait cacher ce que le serveur a
   *  rendu — mais leur bouton est éteint et dit pourquoi. */
  dejaChoisis?: number[]
}>()

const emit = defineEmits<{ choisir: [personne: PersonneChoisie] }>()

const terme = ref('')
const resultats = ref<ResultatDeRecherche[]>([])
const tronque = ref(false)
const chargement = ref(false)
const erreur = ref<unknown>(null)
/** Une recherche a-t-elle abouti depuis la dernière frappe ? Sans ce drapeau,
 *  « aucun résultat » s'afficherait avant même le premier appel. */
const interroge = ref(false)

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
  try {
    const reponse = await rechercherPersonnes(q, 10, controleur.signal)
    if (controleur.signal.aborted) return
    resultats.value = reponse.elements
    tronque.value = reponse.tronque
    interroge.value = true
  } catch (cause) {
    if (!controleur.signal.aborted) erreur.value = cause
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
    tronque.value = false
    interroge.value = false
    return
  }
  minuterie = setTimeout(() => void chercher(q), DELAI_MS)
})

onBeforeUnmount(annuler)

function choisir(resultat: ResultatDeRecherche): void {
  emit('choisir', { personId: resultat.personId, libelle: resultat.displayName })
  terme.value = ''
  resultats.value = []
  interroge.value = false
}

function estDejaChoisi(resultat: ResultatDeRecherche): boolean {
  return dejaChoisis.includes(resultat.personId)
}

const aucunResultat = computed(
  () => interroge.value && !chargement.value && erreur.value === null && resultats.value.length === 0,
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
      {{ aide.length > 0 ? aide : 'La recherche porte sur les personnes de l’arbre que vous pouvez consulter.' }}
    </p>

    <p v-if="chargement" class="text-xs text-attenue" role="status">Recherche…</p>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="() => void chercher(terme.trim())" />

    <!-- Une absence de résultat est une réponse, pas un incident : ton neutre,
         pas de `role="alert"`, pas de cadre d'alerte. -->
    <p v-else-if="aucunResultat" class="text-xs text-attenue" role="status">
      Aucune personne ne correspond à cette recherche.
    </p>

    <ul v-else-if="resultats.length > 0" class="flex flex-col gap-1">
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
          :disabled="estDejaChoisi(resultat)"
          class="rounded-champ border border-ligne px-2.5 py-1 text-xs font-semibold transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-55"
          @click="choisir(resultat)"
        >
          {{ estDejaChoisi(resultat) ? 'Déjà désigné' : 'Choisir' }}
        </button>
      </li>
    </ul>

    <!-- Le plafond a été atteint **sur le jeu déjà filtré**. On le dit en mots :
         un nombre ici laisserait déduire ce qui n'est pas montré. -->
    <p v-if="tronque" class="text-xs text-attenue">
      Seuls les premiers résultats sont affichés. Précisez votre recherche.
    </p>
  </div>
</template>
