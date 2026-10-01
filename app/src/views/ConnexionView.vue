<script setup lang="ts">
/**
 * Connexion — § 2 du contrat.
 *
 * C'est la porte la plus sensible du produit, et la première page que voit
 * quelqu'un qui revient. Quatre points qui ne se devinent pas :
 *
 * - **`POST /api/connexion` prend `{email, password}`** : ce sont les clés de
 *   `json_login` (`username_path`, `password_path`). On ne les francise pas,
 *   bien que tout le reste du projet le soit.
 * - **Ses `401` sont métier** (`identifiants_invalides`, `compte_suspendu`) et
 *   ne doivent surtout pas déconnecter : `connecter()` passe `sansRattrapage`,
 *   et le rattrapage global branche de toute façon sur le **code**
 *   `authentification_requise`, jamais sur le statut (§ 2.1).
 * - **La session est un cookie `HttpOnly`.** Cet écran ne manipule aucun jeton,
 *   n'en lit aucun, n'en stocke aucun — il n'y a rien à retenir après un succès
 *   qu'un `GET /api/session` ne redise. On ne garde pas non plus le courriel
 *   saisi : `autocomplete` est l'affaire du navigateur, pas la nôtre.
 * - **Aucune inscription ouverte** : pas de lien « créer un compte », pas de
 *   « mot de passe oublié » tant qu'aucune route ne le sert. On entre par un
 *   lien d'invitation, un point c'est tout (§ 6.1 du dossier).
 *
 * Le cas qui demande le plus de soin est le **plafond de cinq tentatives par
 * quart d'heure** (§ 1.9). Il rend `429 trop_de_tentatives` avec un
 * `Retry-After`, et c'est une vraie erreur de débit — rien à voir avec le
 * silence délibéré de la recherche de rattachement (§ 4.2). Ici, dire ce qui se
 * passe est la bonne conduite : quelqu'un qui hésite entre deux mots de passe
 * doit comprendre qu'il est refusé **pour l'instant**, et savoir quand revenir,
 * sans quoi il conclut que son compte est perdu.
 *
 * ── La clé d'accès (Face ID, empreinte, Windows Hello) ────────────────────────
 *
 * Deux portes de plus, **à côté** du mot de passe, qui reste en secours :
 *
 * - un **bouton**, montré seulement si le navigateur sait faire du WebAuthn ;
 *   son libellé ne promet Face ID ou l'empreinte que si l'appareil a de quoi ;
 * - l'**autoremplissage** : quand le navigateur le permet, le champ courriel
 *   porte `autocomplete="username webauthn"` et propose les clés du site dans
 *   sa liste de suggestions — on touche le champ, on choisit son nom, Face ID,
 *   c'est fini.
 *
 * Les deux mènent à la **même** suite que le mot de passe (`?suite=`), et
 * leurs refus serveur (`401`, `429`) s'affichent au même endroit, de la même
 * façon : `surErreur` les range dans `erreur`. Ce que dit le **navigateur**
 * (« annulé ») s'écrit à part, en une phrase : ce n'est pas une erreur du
 * compte, et le formulaire reste là. La mécanique est dans
 * `piloterConnexionCleDAcces()`.
 *
 * Après une connexion **par mot de passe**, `annoncerConnexionParMotDePasse()`
 * prévient l'invitation « Se connecter plus vite la prochaine fois ? », qui
 * s'ouvrira sur l'écran d'arrivée si l'appareil s'y prête.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { analyserErreur } from '@/lib/api'
import { connecter, useSession } from '@/composables/useSession'
import { regimeDeSession } from '@/router/acces'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import CadreEntree from '@/components/inscription/CadreEntree.vue'
import { annoncerConnexionParMotDePasse, piloterConnexionCleDAcces } from '@/composables/useClesDAcces'
import { delaiAvantNouvelEssai, destinationApresConnexion } from './connexion'

const route = useRoute()
const routeur = useRouter()
const { session } = useSession()

const email = ref('')
const motDePasse = ref('')
const envoi = ref(false)
const erreur = ref<unknown>(null)

const analyse = computed(() => (erreur.value === null ? null : analyserErreur(erreur.value)))

/** Le plafond de débit : traité à part, pour dire **quand** réessayer. */
const plafondAtteint = computed(() => analyse.value?.conduite === 'patienter')

/** Message d'un champ, quand le serveur a rempli `champs` sur une `422`. */
function erreurDeChamp(nom: string): string {
  return analyse.value?.champs[nom] ?? ''
}

const peutEnvoyer = computed(
  () => email.value.trim().length > 0 && motDePasse.value.length > 0 && !envoi.value,
)

/** La suite, commune aux deux portes. `?suite=` vient de l'URL, donc de
 *  n'importe qui : `destinationApresConnexion` n'accepte qu'un chemin interne.
 *  Voir `connexion.ts`. */
async function poursuivre(): Promise<void> {
  await routeur.replace(destinationApresConnexion(route.query['suite'], regimeDeSession(session.value)))
}

const cle = piloterConnexionCleDAcces({
  apres: poursuivre,
  surErreur: (cause) => {
    erreur.value = cause
  },
  surDebut: () => {
    erreur.value = null
  },
})
const cleEnCours = cle.enCours
const messageCle = cle.message
const libelleCle = cle.libelle
const autoremplissage = cle.conditionnel

/** `webauthn` en fin de liste : c'est ce jeton qui fait proposer les clés dans
 *  les suggestions du champ. Seulement si l'autoremplissage est armé. */
const autocompleteCourriel = computed(() => (autoremplissage.value ? 'username webauthn' : 'username'))

onMounted(() => void cle.demarrer())

async function soumettre(): Promise<void> {
  if (!peutEnvoyer.value) return

  envoi.value = true
  erreur.value = null
  cle.effacerMessage()
  try {
    await connecter(email.value.trim(), motDePasse.value)
    // Le mot de passe ne traîne pas en mémoire au-delà de son usage.
    motDePasse.value = ''
    // Avant de quitter l'écran : l'invitation doit savoir, dès l'arrivée, que
    // la session vient d'un mot de passe — et passer avant les notifications.
    annoncerConnexionParMotDePasse()
    await poursuivre()
  } catch (cause) {
    erreur.value = cause
  } finally {
    envoi.value = false
  }
}
</script>

<template>
  <CadreEntree titre="Connexion" sousTitre="L’arbre de la famille, pour la famille.">
    <PanneauBase>
      <form novalidate @submit.prevent="soumettre">
        <div class="flex flex-col gap-3.5">
          <ChampTexte
            v-model="email"
            etiquette="Adresse de courriel"
            type="email"
            :autocomplete="autocompleteCourriel"
            :erreur="erreurDeChamp('email')"
            requis
          />

          <ChampTexte
            v-model="motDePasse"
            etiquette="Mot de passe"
            type="password"
            autocomplete="current-password"
            :erreur="erreurDeChamp('password')"
            requis
          />
        </div>

        <!-- Le plafond de débit, dit en clair. « Nouvel essai possible dans 847
             secondes » est exact et inutilisable : on donne le délai en minutes,
             arrondi vers le haut, et on dit que le compte n'est pas en cause. -->
        <div
          v-if="plafondAtteint"
          class="mt-4 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
          role="alert"
        >
          <p class="font-semibold">Trop d’essais successifs</p>
          <p class="mt-1">
            Par sécurité, les connexions depuis cet appareil sont suspendues un moment.
            Vous pourrez réessayer {{ delaiAvantNouvelEssai(analyse?.attenteSecondes ?? null) }}.
          </p>
          <p class="mt-1.5 text-attenue">
            Votre compte n’est ni bloqué ni supprimé : seule cette attente s’applique.
          </p>
        </div>

        <!-- Tout le reste : identifiants refusés, compte suspendu, serveur
             muet. Le message vient du serveur et s'affiche tel quel ; la
             conduite à tenir est calculée par `erreurs.ts`, jamais ici. -->
        <MessageErreur
          v-else-if="erreur !== null"
          class="mt-4"
          :erreur="erreur"
          @action="soumettre"
        />

        <div class="mt-5">
          <BoutonBase type="submit" :chargement="envoi" :desactive="!peutEnvoyer" pleineLargeur>
            Se connecter
          </BoutonBase>
        </div>
      </form>

      <!-- La clé d'accès : seulement si le navigateur sait faire. Hors du
           `<form>` — Entrée dans le mot de passe ne doit pas la déclencher. -->
      <div v-if="cle.possible" class="mt-4 flex flex-col gap-3" data-connexion-cle-d-acces>
        <div class="flex items-center gap-3 text-xs text-attenue" aria-hidden="true">
          <span class="h-px flex-1 bg-ligne" />
          ou
          <span class="h-px flex-1 bg-ligne" />
        </div>
        <BoutonBase
          variante="secondaire"
          :chargement="cleEnCours"
          :desactive="envoi"
          pleineLargeur
          @click="cle.seConnecter"
        >
          {{ libelleCle }}
        </BoutonBase>
        <!-- Annulé, ou impossible sur cet appareil : une phrase, sans alarme.
             Le mot de passe est juste au-dessus. -->
        <p v-if="messageCle !== null" class="m-0 text-sm text-attenue" role="status">{{ messageCle }}</p>
      </div>
    </PanneauBase>

    <template #pied>
      <!-- Pas de « créer un compte » : il n'y a pas d'inscription ouverte, et
           proposer un lien qui n'existe pas ferait chercher pour rien. -->
      <p class="mt-5 text-center text-xs text-attenue">
        On entre sur cet arbre par une invitation personnelle. Si vous en attendez une,
        écrivez à la personne qui tient l’arbre.
      </p>
    </template>
  </CadreEntree>
</template>
