<script setup lang="ts">
/**
 * Inscription, étape 1b — rattachement (§ 4.2).
 *
 * **C'est le principal risque du produit.** Sans garde-fou, le lien d'invitation
 * devient un moyen d'énumérer les personnes vivantes de l'arbre. Les garde-fous
 * sont côté serveur ; le travail de cet écran est de **ne pas les contredire**.
 *
 * Trois règles, appliquées ailleurs, que rien ici ne doit défaire :
 *
 * - **au plus 3 candidats**, et au plus 1 en mode confirmation ;
 * - **affichage minimal** — « P. Romarin, 1980 » : initiale, nom, année. Jamais
 *   un prénom entier, jamais une date complète, jamais un `personId` ;
 * - **5 tentatives par heure et par jeton**, dont le dépassement rend
 *   `200 { "candidats": [] }` — la réponse exacte d'une recherche infructueuse.
 *
 * **Cet écran ne cherche pas à savoir dans lequel des deux cas il se trouve.**
 * Il ne le peut pas, c'est voulu, et toute tentative de le deviner — compter les
 * envois côté client, chronométrer la réponse, guetter un en-tête — rouvrirait
 * la fuite que le serveur vient de fermer. Le résultat vide a donc **un seul
 * écran**, dans `ResultatCandidats.vue`, et rien ici n'affiche de compteur.
 *
 * Le limiteur **par IP** (30 / h), lui, rend une vraie `429`, assortie d'un
 * délai d'attente : il ne dit rien de la validité d'un jeton ni de l'existence
 * d'une personne, seulement qu'une adresse s'acharne. Elle passe donc par
 * `<MessageErreur>` comme n'importe quelle autre erreur — le socle en tire
 * « patienter », et c'est exact. Cet écran ne lit **aucun** en-tête de quota :
 * il n'en reçoit pas sur le plafond par jeton, et aller les chercher serait le
 * premier pas vers la distinction qu'on s'interdit.
 *
 * Les deux modes demandent **les mêmes trois champs**. En mode confirmation, la
 * personne est déjà pré-liée côté serveur et il suffirait techniquement de la
 * date ; ne demander que celle-ci dirait au porteur « nous savons déjà ton
 * nom ». Le formulaire est donc identique, et le libellé de l'écran ne trahit
 * pas le mode.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { rechercherRattachement, type CandidatRattachement } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import CadreEntree from '@/components/inscription/CadreEntree.vue'
import LienNonValide from '@/components/inscription/LienNonValide.vue'
import ResultatCandidats from '@/components/inscription/ResultatCandidats.vue'
import { estLienNonValide } from '@/components/inscription/lienInvitation'
import {
  choisirCandidat,
  fermerFlux,
  retenirCandidats,
  useFluxInscription,
} from '@/components/inscription/fluxInscription'

const routeur = useRouter()
const flux = useFluxInscription()

const surname = ref('')
const givenNames = ref('')
const birthDate = ref('')

const envoi = ref(false)
const erreur = ref<unknown>(null)
const lienRefuse = ref(false)
/** Une recherche a-t-elle abouti ? Distingue « rien demandé » de « rien trouvé »
 *  — deux écrans différents, alors que les causes d'un « rien trouvé », elles,
 *  n'en font qu'un. */
const aCherche = ref(false)
const refChoisi = ref<string | null>(null)

/** La dernière réponse du serveur, ou `null` si rien n'a encore été demandé. */
const resultat = computed(() => (aCherche.value ? { candidats: flux.candidats.value } : null))

const formulaireComplet = computed(
  () =>
    surname.value.trim().length > 0 &&
    givenNames.value.trim().length > 0 &&
    birthDate.value.length > 0,
)

onMounted(() => {
  // Le parcours n'a pas survécu à un rechargement : rien n'est stocké, par
  // choix (voir `fluxInscription.ts`). On le dit, plutôt que d'envoyer une
  // requête sans jeton qui reviendrait en 404 et ferait croire à un lien mort.
  if (!flux.ouvert.value) lienRefuse.value = true
})

async function chercher(): Promise<void> {
  const jeton = flux.jeton.value
  if (jeton === null) {
    lienRefuse.value = true
    return
  }
  if (!formulaireComplet.value || envoi.value) return

  envoi.value = true
  erreur.value = null
  refChoisi.value = null
  try {
    const reponse = await rechercherRattachement({
      jeton,
      surname: surname.value.trim(),
      givenNames: givenNames.value.trim(),
      // Date ferme `YYYY-MM-DD` : une preuve de connaissance, pas une donnée
      // généalogique. C'est la seule date ferme de toute l'API — elle ne passe
      // pas par un bloc date du § 14 et ne s'affiche jamais en fourchette.
      birthDate: birthDate.value,
    })
    retenirCandidats(reponse.candidats)
    aCherche.value = true
  } catch (cause) {
    aCherche.value = false
    retenirCandidats([])
    if (estLienNonValide(cause)) {
      fermerFlux()
      lienRefuse.value = true
    } else {
      erreur.value = cause
    }
  } finally {
    envoi.value = false
  }
}

const candidatRetenu = computed<CandidatRattachement | null>(
  () => flux.candidats.value.find((candidat) => candidat.ref === refChoisi.value) ?? null,
)

function continuer(): void {
  const candidat = candidatRetenu.value
  if (candidat === null) return
  choisirCandidat(candidat)
  // Le `ref` ne part pas dans l'URL : il désigne une personne potentiellement
  // vivante et ne doit pas se retrouver dans un historique de navigation.
  void routeur.push({ name: 'inscription.compte' })
}
</script>

<template>
  <CadreEntree
    v-if="lienRefuse"
    titre="Inscription"
    sousTitre="Reprenez depuis le lien d’invitation que vous avez reçu."
  >
    <LienNonValide />
  </CadreEntree>

  <CadreEntree
    v-else
    titre="Vous situer dans l’arbre"
    sousTitre="Ces informations servent à retrouver la fiche qui vous concerne, et à vérifier que c’est bien vous."
    :etape="1"
  >
    <PanneauBase>
      <form novalidate @submit.prevent="chercher">
        <div class="flex flex-col gap-3.5">
          <ChampTexte
            v-model="surname"
            etiquette="Nom de famille"
            aide="Le nom de naissance, s’il diffère de celui que vous portez aujourd’hui."
            autocomplete="family-name"
            requis
          />

          <ChampTexte
            v-model="givenNames"
            etiquette="Prénom·s"
            aide="Tous vos prénoms, dans l’ordre de l’état civil."
            autocomplete="given-name"
            requis
          />

          <ChampTexte
            v-model="birthDate"
            etiquette="Date de naissance"
            type="date"
            aide="Jour, mois et année."
            autocomplete="bday"
            requis
          />
        </div>

        <div class="mt-5">
          <BoutonBase
            type="submit"
            :chargement="envoi"
            :desactive="!formulaireComplet || envoi"
            pleineLargeur
          >
            Rechercher ma fiche
          </BoutonBase>
        </div>
      </form>
    </PanneauBase>

    <!-- Une panne ou un plafond par adresse : ni l'un ni l'autre ne dit quoi
         que ce soit du jeton ni d'une personne. Le message et la conduite à
         tenir viennent d'`erreurs.ts`, jamais d'ici. -->
    <MessageErreur v-if="erreur !== null" class="mt-4" :erreur="erreur" @action="chercher" />

    <!-- Le résultat. Réponse vide : un seul écran pour toutes ses causes. -->
    <div v-if="resultat !== null" class="mt-4">
      <ResultatCandidats v-model:refChoisi="refChoisi" :resultat="resultat" />
    </div>

    <div v-if="candidatRetenu !== null" class="mt-4">
      <BoutonBase pleineLargeur @click="continuer">Continuer</BoutonBase>
    </div>
  </CadreEntree>
</template>
