<script setup lang="ts">
/**
 * Inscription, étape 3 — l'état du compte (§ 4.4).
 *
 * Cet écran a deux vies. Il termine l'inscription, et il reste ensuite
 * **la page d'un compte en attente de validation** — sa page par défaut
 * (`routeParDefaut`) et celle où la garde l'envoie quand il tente d'ouvrir
 * l'arbre, une fiche, Explorer ou l'accueil (`acces.ts`). Le renvoyer à la
 * connexion lui ferait croire que sa session est tombée, alors qu'il lui
 * manque seulement la validation.
 *
 * **Rien de l'arbre ici** (ADR 0003, révision du 01/10/2026). L'écran
 * proposait « Parcourir l'arbre » et annonçait « 256 fiches de personnes
 * décédées sur 274 » : un compte en attente lisait l'arbre ancien, et les
 * vivants en nœuds restreints, nom complet compris. Le lien d'invitation
 * circulant dans un groupe de messagerie, n'importe qui pouvait s'inscrire sous
 * un faux nom et lire les noms des vivants. Il ne voit donc plus rien de
 * l'arbre jusqu'à sa validation, et cet écran ne lui en promet rien :
 * L'API ne sert plus aucun chiffre de l'arbre à un compte en attente (`cequejevois` est retiré).
 *
 * Il exige donc une **session**, pas l'anonymat (`meta.acces: 'attente'`), et il
 * est rendu **avec** le cadre applicatif : son lecteur est connecté.
 *
 * **Le ton est tout le travail de cet écran.** L'attente peut durer plusieurs
 * jours. Il faut donc dire, dans cet ordre : ce qui se passe (« Votre
 * inscription est en cours de vérification »), comment on l'apprendra (« Vous
 * serez prévenu dès qu'elle est validée »), et ce qu'on peut faire en
 * attendant — sa photo, son compte. Rien qui ressemble à une panne, rien qui
 * ressemble à un refus.
 *
 * **La validation arrive pendant que l'écran est ouvert**, ou entre deux
 * visites : l'API répond alors `status: 'actif'` alors que la session du
 * navigateur dit encore « en attente ». On relit la session aussitôt
 * (`rafraichirSession()`) : la navigation complète réapparaît sans attendre le
 * clic sur « Entrer dans l'arbre ».
 *
 * **Aucune clé `personId` ni `personLabel`** tant que `status` vaut
 * `en_attente`, et il ne faut pas les chercher ailleurs : ni dans
 * `GET /api/session`, ni dans une notification. Le candidat a vu
 * « P. Romarin, 1980 » à l'étape 1b ; il ne reverra pas ce libellé ici, et
 * surtout pas le `displayName` réel de la fiche, qui peut porter un nom d'usage
 * ou un nom d'épouse qu'il ignore. Le pré-lien n'ouvre aucun accès : « confirmer
 * une date n'est pas la lire ».
 *
 * **Le compte sans fiche** (`matchKind: 'a_rattacher'`, « Je ne me trouve pas »)
 * a son propre texte : il n'y a pas de fiche « à confirmer », mais une fiche à
 * **identifier** — ou à créer — à partir de la description envoyée. Même régime
 * que tout compte en attente : rien de l'arbre.
 *
 * **La photo.** Tant que le compte attend, `portraitEnAttente` dit si sa photo a
 * été déposée. Si elle ne l'a pas été (l'étape a été passée), un bouton
 * « Ajouter votre photo » ouvre `ChoixPortrait` ; la clé absente ne propose rien.
 *
 * **Le compte sans rattachement** (correction du 01/10/2026). Un compte actif
 * qui n'a pas de membre — typiquement un ancien administrateur, créé en ligne
 * de commande sans fiche, à qui l'on a retiré le rôle — n'a pas de cercle : la
 * session le range en attente (`cercle.regime: 'aucun'`) et la garde l'envoie
 * ici. Mais `GET /api/inscription/etat` n'a rien à dire d'un compte sans
 * membre et répond `404`. Un « une erreur est survenue » avec un bouton
 * « Réessayer » serait une impasse : réessayer rendra toujours `404`. On dit
 * donc ce qui se passe et à qui s'adresser.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { estErreurApi, lireEtatInscription } from '@/lib/api'
import { useAppelApi } from '@/composables/useAppelApi'
import { rafraichirSession, useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import ChoixPortrait from '@/components/medias/ChoixPortrait.vue'

const { donnees: etat, erreur, chargement, executer } = useAppelApi(lireEtatInscription)

onMounted(executer)

/** `404` : aucun membre derrière ce compte — rien à attendre, quelqu'un doit agir. */
const sansRattachement = computed(() => estErreurApi(erreur.value) && erreur.value.statut === 404)

const enAttente = computed(() => etat.value?.status === 'en_attente')
/** Ouvert sans fiche : l'administrateur doit l'identifier, pas la confirmer. */
const sansFiche = computed(() => etat.value?.matchKind === 'a_rattacher')

const choixPortraitOuvert = ref(false)
/** Déposée depuis cet écran : on n'attend pas un rechargement pour le dire. */
const photoDeposeeIci = ref(false)
/** Proposer la photo seulement quand le serveur dit qu'il n'y en a pas — la clé
 *  absente (compte actif) ne propose rien. */
const proposerPhoto = computed(
  () => enAttente.value && etat.value?.portraitEnAttente === false && !photoDeposeeIci.value,
)

const { rattache } = useSession()

// Validé entre-temps : la session du navigateur l'ignore encore. On la relit
// une fois, sans attendre le clic — la barre de navigation et l'en-tête
// rendent alors l'arbre au nouveau membre. Un échec n'est pas grave : le
// bouton ci-dessous refait la même chose.
watch(
  () => etat.value?.status,
  (statut) => {
    if (statut === 'actif' && !rattache.value) void rafraichirSession().catch(() => undefined)
  },
)

/** Le compte vient d'être activé : la session ne le sait pas encore. */
async function entrerDansLArbre(): Promise<void> {
  // `rafraichirSession()` relit `GET /api/session` — qui porte désormais
  // `membre.personId` et un cercle — et oublie l'`ETag` du graphe au passage :
  // celui d'avant a été calculé pour un lecteur sans cercle.
  await rafraichirSession()
}
</script>

<template>
  <div class="mx-auto w-full max-w-2xl px-4 py-8">
    <h1 class="text-xl font-semibold tracking-tight">Votre inscription</h1>

    <div v-if="chargement && etat === null" class="mt-5">
      <PanneauBase>
        <p class="mb-3 text-sm text-attenue" role="status">Chargement de votre situation…</p>
        <SqueletteChargement :lignes="3" />
      </PanneauBase>
    </div>

    <!-- Un compte sans fiche ni demande de rattachement : pas une panne, une
         situation que seul un administrateur peut débloquer. -->
    <PanneauBase v-else-if="sansRattachement" class="mt-5 compte-sans-fiche">
      <template #entete>
        <h2 class="text-sm font-semibold tracking-tight">Votre compte n’est relié à aucune fiche</h2>
      </template>
      <p class="text-base font-semibold" role="status">
        Compte sans fiche : contactez une personne qui administre l’arbre.
      </p>
      <p class="mt-2.5 text-sm text-attenue">
        Votre compte existe, mais il n’est rattaché à aucune personne de l’arbre, et
        aucune demande de rattachement n’est en cours. Tant que ce n’est pas fait,
        l’arbre de la famille vous reste fermé, pour protéger les personnes qui y
        figurent.
      </p>
      <template #pied>
        <RouterLink :to="{ name: 'compte' }" class="text-sm font-semibold text-accent underline">
          Mon compte
        </RouterLink>
      </template>
    </PanneauBase>

    <MessageErreur v-else-if="erreur !== null" class="mt-5" :erreur="erreur" @action="executer" />

    <template v-else-if="etat !== null">
      <!-- ---------------------------------------------------------------- -->
      <!-- Compte en attente de validation                                   -->
      <!-- ---------------------------------------------------------------- -->
      <template v-if="enAttente">
        <PanneauBase class="mt-5">
          <template #entete>
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h2 class="text-sm font-semibold tracking-tight">Votre compte est créé</h2>
              <!-- La valeur d'état est celle du modèle, jamais traduite. -->
              <PastilleEtat etat="en_attente" />
            </div>
          </template>

          <!-- Le message, d'abord : ce qui se passe, et comment on l'apprendra. -->
          <p class="message-attente text-base font-semibold" role="status">
            Votre inscription est en cours de vérification. Vous serez prévenu dès
            qu’elle est validée.
          </p>

          <p v-if="sansFiche" class="mt-2.5 text-sm">
            Une personne qui administre l’arbre va retrouver votre fiche à partir de la
            description de famille que vous avez envoyée — ou la créer, si vous n’y
            figurez pas encore.
          </p>
          <p v-else class="mt-2.5 text-sm">
            Une personne qui administre l’arbre doit confirmer que la fiche à laquelle
            vous vous êtes rattaché·e est bien la vôtre.
          </p>

          <p class="mt-2.5 text-sm text-attenue">
            Cette vérification est faite à la main, par quelqu’un de la famille, et peut
            prendre quelques jours. L’arbre de la famille vous sera ouvert à ce moment-là :
            d’ici là, il reste fermé, pour protéger les personnes qui y figurent. Il n’y a
            rien à relancer, et vous pouvez fermer cette page sans rien perdre.
          </p>
        </PanneauBase>

        <!-- La photo, si l'étape a été passée. Elle attendra la validation pour
             rejoindre la fiche ; d'ici là, seuls les administrateurs la voient. -->
        <PanneauBase v-if="proposerPhoto" titre="Votre photo" class="mt-4">
          <p class="text-sm">
            Ajoutez une photo de vous, bien cadrée sur le visage : elle rejoindra votre
            fiche à la validation. D’ici là, seules les personnes qui administrent l’arbre
            la voient.
          </p>
          <template #pied>
            <BoutonBase variante="secondaire" @click="choixPortraitOuvert = true">
              Ajouter votre photo
            </BoutonBase>
          </template>
        </PanneauBase>
        <p v-if="photoDeposeeIci" class="mt-4 text-sm" role="status">
          Photo enregistrée : elle rejoindra votre fiche à la validation.
        </p>
        <ChoixPortrait
          :ouvert="choixPortraitOuvert"
          @fermer="choixPortraitOuvert = false"
          @depose="photoDeposeeIci = true"
        />

        <!-- En attendant : ce qui est à lui, et rien de l'arbre. -->
        <PanneauBase titre="En attendant" class="mt-4">
          <p class="text-sm">
            Vous pouvez déjà vérifier votre adresse de courriel ou changer votre mot de
            passe.
          </p>
          <template #pied>
            <RouterLink :to="{ name: 'compte' }" class="text-sm font-semibold text-accent underline">
              Mon compte
            </RouterLink>
          </template>
        </PanneauBase>
      </template>

      <!-- ---------------------------------------------------------------- -->
      <!-- Compte actif — rattachement certain, activation automatique       -->
      <!-- ---------------------------------------------------------------- -->
      <PanneauBase v-else class="mt-5">
        <template #entete>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold tracking-tight">Votre compte est actif</h2>
            <PastilleEtat etat="actif" />
          </div>
        </template>

        <p class="text-sm">
          Votre rattachement est confirmé. L’arbre vous est ouvert : les personnes
          décédées, et les personnes vivantes de votre entourage familial.
        </p>

        <template #pied>
          <RouterLink v-slot="{ navigate }" :to="{ name: 'accueil' }" custom>
            <BoutonBase @click="entrerDansLArbre().then(() => navigate())">
              Entrer dans l’arbre
            </BoutonBase>
          </RouterLink>
        </template>
      </PanneauBase>
    </template>
  </div>
</template>
