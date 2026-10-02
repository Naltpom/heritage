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
 *
 * **« Je ne me trouve pas ».** Après toute recherche, un bouton sous le résultat
 * ouvre la description de famille (`DeclarationFamille.vue`) : le candidat qui
 * n'a pas de fiche — ou dont la fiche porte d'autres données que les siennes —
 * peut quand même ouvrir un compte, en attente et **sans fiche**, qu'un
 * administrateur rattachera. Le bouton vit **ici**, et non dans
 * `ResultatCandidats.vue` : l'écran du résultat vide reste la même chaîne d'HTML
 * pour ses trois causes, et le bouton lui-même ne dépend que du nombre de
 * candidats affichés (`libelleSansFiche()`) — il est donc identique, lui aussi,
 * quelle que soit la cause d'un résultat vide.
 *
 * Les candidats décédés ne sont plus proposés (le serveur les écarte) : un
 * compte se rattache à une personne vivante, ou se déclare sans fiche.
 *
 * **Un résultat vide ouvre la description d'emblée** (2 octobre 2026), et une
 * voix l'explique (`ConsigneParlee.vue`, une fois par onglet). La décision ne
 * dépend, elle aussi, que du nombre de candidats affichés : elle est donc la
 * même pour les trois causes d'un résultat vide, et ne rouvre aucun canal. Le
 * clic sur « Rechercher ma fiche » « déverrouille » au passage la consigne
 * (`deverrouillerLaConsigne()`), pour que Safari la laisse parler une fois la
 * réponse arrivée.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { prefereMoinsDAnimations } from '@/lib/videos'
import { useRouter } from 'vue-router'
import {
  rechercherRattachement,
  type CandidatRattachement,
  type DeclarationDeFamille,
  type MessageVocal,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import CadreEntree from '@/components/inscription/CadreEntree.vue'
import LienNonValide from '@/components/inscription/LienNonValide.vue'
import ResultatCandidats from '@/components/inscription/ResultatCandidats.vue'
import DeclarationFamille from '@/components/inscription/DeclarationFamille.vue'
import { libelleSansFiche, normaliserDeclaration } from '@/components/inscription/declaration'
import { deverrouillerLaConsigne } from '@/components/inscription/consigneVocale'
import { estLienNonValide } from '@/components/inscription/lienInvitation'
import {
  choisirCandidat,
  fermerFlux,
  retenirCandidats,
  retenirDeclaration,
  useFluxInscription,
} from '@/components/inscription/fluxInscription'

const routeur = useRouter()
const flux = useFluxInscription()

// Un retour depuis l'étape 2 (bouton « précédent ») retrouve la déclaration
// retenue : on ne fait pas réécrire au candidat ce qu'il vient de décrire.
const surname = ref(flux.declaration.value?.surname ?? '')
const givenNames = ref(flux.declaration.value?.givenNames ?? '')
const birthDate = ref(flux.declaration.value?.birthDate ?? '')
/** Le brouillon de la description : il survit à la fermeture du panneau. */
const description = ref(flux.declaration.value?.description ?? '')
/** Le message vocal enregistré, s'il y en a un : il survit, lui aussi, au panneau refermé. */
const messageVocal = ref<MessageVocal | null>(flux.declaration.value?.messageVocal ?? null)
/** Le panneau « Décrivez votre famille » est-il ouvert ? Il l'est d'emblée au
 *  retour de l'étape 2 si c'est par lui que le candidat était passé. */
const declarer = ref(flux.declaration.value !== null)

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

  // **Avant tout `await`** : c'est encore le geste de la personne, le seul
  // moment où Safari permet d'amorcer un son qu'on jouera après la réponse.
  deverrouillerLaConsigne()

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
    // Rien trouvé : la description s'ouvre d'elle-même — même décision pour
    // toutes les causes d'un résultat vide, puisqu'elle ne lit que le nombre.
    if (reponse.candidats.length === 0) declarer.value = true
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

/** Choisir un candidat referme la description : les deux sont exclusifs. */
watch(refChoisi, (valeur) => {
  if (valeur !== null) declarer.value = false
})

/**
 * La description s'ouvre **sous** le formulaire et les résultats : sur un
 * téléphone, elle naît hors de l'écran, et la consigne parlée qui démarre
 * parlerait d'une carte qu'on ne voit pas. On l'amène donc sous les yeux — en
 * douceur, sauf pour qui demande moins d'animations.
 */
const carteDeclaration = ref<HTMLElement | null>(null)
watch(declarer, async (ouverte) => {
  if (!ouverte) return
  await nextTick()
  const carte = carteDeclaration.value
  if (carte === null || typeof carte.scrollIntoView !== 'function') return
  carte.scrollIntoView({ behavior: prefereMoinsDAnimations() ? 'auto' : 'smooth', block: 'start' })
})

function ouvrirDeclaration(): void {
  refChoisi.value = null
  declarer.value = true
}

function continuerSansFiche(entree: DeclarationDeFamille): void {
  retenirDeclaration(normaliserDeclaration(entree))
  // Comme le `ref`, la description ne part pas dans l'URL : elle parle de
  // personnes vivantes.
  void routeur.push({ name: 'inscription.compte' })
}

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
    sousTitre="Pour retrouver votre fiche."
    :etape="1"
  >
    <PanneauBase>
      <form novalidate @submit.prevent="chercher">
        <div class="flex flex-col gap-3.5">
          <ChampTexte
            v-model="surname"
            etiquette="Nom de famille"
            aide="Votre nom de naissance."
            autocomplete="family-name"
            requis
          />

          <ChampTexte
            v-model="givenNames"
            etiquette="Prénom·s"
            aide="Tous, dans l’ordre de l’état civil."
            autocomplete="given-name"
            requis
          />

          <ChampTexte
            v-model="birthDate"
            etiquette="Date de naissance"
            aide="Seulement pour vérifier que c’est bien vous."
            type="date"
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

    <!-- Le recours « sans fiche », après toute recherche. Son libellé ne dépend
         que du nombre de candidats affichés. -->
    <div v-if="resultat !== null && !declarer" class="mt-4">
      <BoutonBase variante="secondaire" pleineLargeur @click="ouvrirDeclaration">
        {{ libelleSansFiche(resultat.candidats.length) }}
      </BoutonBase>
    </div>

    <div v-if="declarer" ref="carteDeclaration" class="mt-4 scroll-mt-4">
      <DeclarationFamille
        v-model:description="description"
        v-model:messageVocal="messageVocal"
        consigneAuto
        :surname="surname"
        :givenNames="givenNames"
        :birthDate="birthDate"
        @continuer="continuerSansFiche"
        @annuler="declarer = false"
      />
    </div>
  </CadreEntree>
</template>
