<script setup lang="ts">
/**
 * Inscription, étape 2 — création du compte (§ 4.3).
 *
 * Courriel et mot de passe, **12 caractères minimum**. Le serveur refuse en
 * `422` avec `champs: {"password": "…"}` ; la vérification faite ici ne le
 * remplace pas, elle évite un aller-retour et permet de répondre pendant la
 * saisie (`reglesMotDePasse.ts`). **Quand le serveur a parlé, c'est son message
 * qui s'affiche** : c'est lui la règle réellement appliquée.
 *
 * `choix` est le `ref` opaque retenu à l'étape 1b, à usage unique et valable
 * quinze minutes. Expiré, inconnu, ou issu d'une autre tentative : `404`, et non
 * `422` — confirmer « ce `ref` a existé » dirait déjà quelque chose de l'étape
 * précédente. L'écran traite donc ce `404` comme les autres : « ce lien n'est
 * pas valide », sans le distinguer d'un jeton refusé.
 *
 * Deux conflits propres à cette étape :
 *
 * - **`409 courriel_deja_pris`** — le seul endroit de l'API qui admette de
 *   révéler l'existence d'un compte, et c'est inévitable : la contrainte
 *   d'unicité refuserait de toute façon. Le risque est borné, il faut déjà un
 *   jeton d'invitation valide pour poser la question ;
 * - **`409 personne_deja_rattachee`** — la personne a été réclamée entre
 *   l'étape 1b et maintenant. Une personne ne peut être réclamée que par un
 *   compte. Le recours est de reprendre la recherche, pas de recharger l'écran.
 *
 * **Succès : `201`, et la session est déjà ouverte** — le jeton est consommé, le
 * cookie est posé, le front n'enchaîne pas sur `POST /api/connexion`. La charge
 * rendue est exactement celle du § 2.2. On relit malgré tout `GET /api/session`
 * par `rafraichirSession()` : c'est ce qui met le magasin de session, les gardes
 * de route et l'`ETag` du graphe d'accord entre eux, par un seul chemin.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { analyserErreur, creerCompte } from '@/lib/api'
import { rafraichirSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import CadreEntree from '@/components/inscription/CadreEntree.vue'
import LienNonValide from '@/components/inscription/LienNonValide.vue'
import { estLienNonValide } from '@/components/inscription/lienInvitation'
import { fermerFlux, useFluxInscription } from '@/components/inscription/fluxInscription'
import {
  LONGUEUR_MINIMALE,
  erreurCourriel,
  erreurMotDePasse,
  peutSoumettreCompte,
} from '@/components/inscription/reglesMotDePasse'

const routeur = useRouter()
const flux = useFluxInscription()

const email = ref('')
const motDePasse = ref('')
const envoi = ref(false)
const erreur = ref<unknown>(null)
const lienRefuse = ref(false)
/** Vrai après une première soumission refusée : on ne souligne pas un champ
 *  que le candidat n'a pas encore fini de remplir. */
const aTenteEnvoi = ref(false)

const analyse = computed(() => (erreur.value === null ? null : analyserErreur(erreur.value)))

/** Le parcours tient-il encore ? Un rechargement de page le perd — rien n'est
 *  stocké, par choix (`fluxInscription.ts`). */
const parcoursPerdu = computed(() => !flux.ouvert.value || flux.choix.value === null)

const refusCourriel = computed(() => erreurCourriel(email.value))
const refusMotDePasse = computed(() => erreurMotDePasse(motDePasse.value))

/** Le message du serveur l'emporte sur le nôtre : c'est la règle appliquée. */
function messageCourriel(): string {
  const duServeur = analyse.value?.champs['email'] ?? ''
  if (duServeur.length > 0) return duServeur
  return aTenteEnvoi.value ? (refusCourriel.value ?? '') : ''
}

function messageMotDePasse(): string {
  const duServeur = analyse.value?.champs['password'] ?? ''
  if (duServeur.length > 0) return duServeur
  // Celui-ci s'affiche dès la saisie : compter les caractères qui manquent n'a
  // d'intérêt que pendant qu'on tape.
  return motDePasse.value.length > 0 || aTenteEnvoi.value ? (refusMotDePasse.value ?? '') : ''
}

async function soumettre(): Promise<void> {
  aTenteEnvoi.value = true

  const jeton = flux.jeton.value
  const choix = flux.choix.value
  if (jeton === null || choix === null) {
    lienRefuse.value = true
    return
  }

  // **Refus avant l'envoi.** Un mot de passe trop court n'a pas à faire un
  // aller-retour pour revenir avec la même réponse, et le message d'ici dit ce
  // qu'il manque plutôt que de constater un refus. L'ordre de ces deux lignes
  // est vérifié par `reglesMotDePasse.test.ts` : la garde passe avant l'appel.
  if (!peutSoumettreCompte(email.value, motDePasse.value)) return
  if (envoi.value) return

  envoi.value = true
  erreur.value = null
  try {
    await creerCompte({
      jeton,
      choix: choix.ref,
      email: email.value.trim(),
      password: motDePasse.value,
    })
    // Le mot de passe et le jeton ont fait leur office : ni l'un ni l'autre n'a
    // de raison de rester en mémoire. Le jeton vient d'être consommé.
    motDePasse.value = ''
    fermerFlux()

    await rafraichirSession()
    // L'étape 3 dit la suite dans les deux cas — compte actif, ou en attente de
    // validation par un administrateur.
    await routeur.replace({ name: 'inscription.etat' })
  } catch (cause) {
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

/**
 * Ce que propose le bouton de `<MessageErreur>`, traduit en geste d'écran.
 *
 * On ne réinterprète pas le **code** — c'est `erreurs.ts` qui en tire la
 * conduite — mais « recharger » n'a pas de sens ici : sur
 * `personne_deja_rattachee`, ce qui est périmé, c'est le candidat retenu à
 * l'étape précédente. On y retourne.
 */
function suivreConduite(conduite: ReturnType<typeof analyserErreur>['conduite']): void {
  if (conduite === 'recharger') void routeur.push({ name: 'inscription.rattachement' })
  else void soumettre()
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
    v-else-if="parcoursPerdu"
    titre="Reprenons depuis le début"
    sousTitre="Votre inscription n’a pas été conservée."
    :etape="2"
  >
    <PanneauBase>
      <p class="text-sm text-attenue">
        Pour votre sécurité, rien de ce que vous saisissez ici n’est conservé d’une page à
        l’autre. Rouvrez le lien d’invitation que vous avez reçu : votre compte n’a pas
        encore été créé, rien n’est perdu.
      </p>
    </PanneauBase>
  </CadreEntree>

  <CadreEntree
    v-else
    titre="Choisir vos identifiants"
    sousTitre="Ce sont eux qui vous permettront de revenir sur l’arbre."
    :etape="2"
  >
    <PanneauBase>
      <form novalidate @submit.prevent="soumettre">
        <div class="flex flex-col gap-3.5">
          <ChampTexte
            v-model="email"
            etiquette="Adresse de courriel"
            type="email"
            aide="Elle servira à vous connecter, et à vous prévenir si quelque chose vous concerne."
            autocomplete="email"
            :erreur="messageCourriel()"
            requis
          />

          <ChampTexte
            v-model="motDePasse"
            etiquette="Mot de passe"
            type="password"
            :aide="`${LONGUEUR_MINIMALE} caractères au minimum. Une phrase de quelques mots ordinaires fait un très bon mot de passe, et se retient mieux qu’un mot compliqué.`"
            autocomplete="new-password"
            :erreur="messageMotDePasse()"
            requis
          />
        </div>

        <div class="mt-5">
          <BoutonBase type="submit" :chargement="envoi" :desactive="envoi" pleineLargeur>
            Créer mon compte
          </BoutonBase>
        </div>
      </form>
    </PanneauBase>

    <MessageErreur
      v-if="erreur !== null"
      class="mt-4"
      :erreur="erreur"
      @action="suivreConduite"
    />
  </CadreEntree>
</template>
