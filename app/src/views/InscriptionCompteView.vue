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
 *
 * **Deux façons d'arriver ici** : avec un candidat retenu (`choix`), ou avec une
 * famille décrite (`declaration`, « Je ne me trouve pas »). La seconde ouvre un
 * compte en attente et **sans fiche** ; ses erreurs de champ reviennent sous des
 * clés `declaration.*`, que cet écran résume en renvoyant à l'étape précédente,
 * où la description se corrige. Le serveur borne aussi ces comptes à cinq par
 * heure et par adresse : c'est une vraie `429`, qui passe par `<MessageErreur>`.
 *
 * **Le partage de la date de naissance** (ADR 0016) se choisit ici, avant la
 * création : trois cases indépendantes, aucune cochée d'avance — un
 * consentement se donne. Ses refus reviennent sous `partageNaissance.*` et
 * s'affichent sous les cases. Il se change ensuite dans « Mon compte ».
 *
 * **Après le `201` vient la photo** (`EtapePhoto.vue`) : un panneau « Votre
 * photo », attendu mais qu'on peut passer d'un lien discret. Qu'elle soit
 * déposée ou passée, la suite est l'écran d'état.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { analyserErreur, creerCompte, type RattachementDemande } from '@/lib/api'
import { rafraichirSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import CadreEntree from '@/components/inscription/CadreEntree.vue'
import LienNonValide from '@/components/inscription/LienNonValide.vue'
import EtapePhoto from '@/components/inscription/EtapePhoto.vue'
import PartageNaissance from '@/components/compte/PartageNaissance.vue'
import { erreursDePartage, partageParDefaut } from '@/components/compte/partageNaissance'
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
/** Facultatif : pour l'annuaire des proches de l'administrateur. */
const telephone = ref('')
const motDePasse = ref('')
/** ADR 0016 — rien de coché d'avance. */
const partage = ref(partageParDefaut())
const envoi = ref(false)
const erreur = ref<unknown>(null)
const lienRefuse = ref(false)
/** Vrai après une première soumission refusée : on ne souligne pas un champ
 *  que le candidat n'a pas encore fini de remplir. */
const aTenteEnvoi = ref(false)
/** `formulaire`, puis `photo` une fois le compte créé. */
const phase = ref<'formulaire' | 'photo'>('formulaire')
/** Le compte créé attend-il sa validation ? Nuance seulement le panneau photo. */
const compteEnAttente = ref(true)

const analyse = computed(() => (erreur.value === null ? null : analyserErreur(erreur.value)))

/** Le parcours tient-il encore ? Un rechargement de page le perd — rien n'est
 *  stocké, par choix (`fluxInscription.ts`). */
const parcoursPerdu = computed(
  () => !flux.ouvert.value || (flux.choix.value === null && flux.declaration.value === null),
)

/** Le candidat vient-il de « Je ne me trouve pas » ? */
const sansFiche = computed(() => flux.declaration.value !== null)
/** … et a-t-il laissé un message vocal ? Il part avec la déclaration, en `multipart`. */
const avecMessageVocal = computed(() => (flux.declaration.value?.messageVocal ?? null) !== null)

/**
 * Les refus du serveur sur la déclaration (`declaration.surname`,
 * `declaration.description`…). Les champs sont à l'étape précédente : on les
 * résume ici, avec le chemin pour les corriger, plutôt que de les perdre dans
 * un message général.
 */
const refusDeclaration = computed<string[]>(() => {
  const champs = analyse.value?.champs ?? {}
  return Object.entries(champs)
    .filter(([cle, message]) => cle.startsWith('declaration') && message.length > 0)
    .map(([, message]) => message)
})

const refusPartage = computed(() => erreursDePartage(analyse.value?.champs))

const refusCourriel = computed(() => erreurCourriel(email.value))
const refusMotDePasse = computed(() => erreurMotDePasse(motDePasse.value))

/** Le message du serveur l'emporte sur le nôtre : c'est la règle appliquée. */
function messageCourriel(): string {
  const duServeur = analyse.value?.champs['email'] ?? ''
  if (duServeur.length > 0) return duServeur
  return aTenteEnvoi.value ? (refusCourriel.value ?? '') : ''
}

function messageTelephone(): string {
  return analyse.value?.champs['telephone'] ?? ''
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
  const declaration = flux.declaration.value
  // L'une **ou** l'autre, jamais les deux : `fluxInscription` les tient
  // exclusives, et le serveur refuserait le mélange.
  const rattachement: RattachementDemande | null =
    declaration !== null ? { declaration } : choix !== null ? { choix: choix.ref } : null
  if (jeton === null || rattachement === null) {
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
    const charge = await creerCompte({
      jeton,
      ...rattachement,
      email: email.value.trim(),
      password: motDePasse.value,
      ...(telephone.value.trim() ? { telephone: telephone.value.trim() } : {}),
      partageNaissance: partage.value,
    })
    // Le mot de passe, le jeton et la description ont fait leur office : aucun
    // n'a de raison de rester en mémoire. Le jeton vient d'être consommé.
    motDePasse.value = ''
    fermerFlux()

    await rafraichirSession()
    compteEnAttente.value = charge.membre?.status !== 'actif'
    // La photo d'abord ; l'étape 3 dit ensuite la suite dans tous les cas —
    // compte actif, ou en attente de validation par un administrateur.
    phase.value = 'photo'
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
/** Photo déposée ou étape passée : l'écran d'état prend le relais. */
function allerALEtat(): void {
  void routeur.replace({ name: 'inscription.etat' })
}

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

  <!-- Le compte existe : la photo. Placé avant `parcoursPerdu`, que la
       fermeture du parcours rend vrai dès la création réussie. -->
  <CadreEntree
    v-else-if="phase === 'photo'"
    titre="Votre compte est créé"
    sousTitre="Une dernière chose avant d’entrer."
    :etape="2"
  >
    <EtapePhoto :enAttente="compteEnAttente" @terminer="allerALEtat" />
  </CadreEntree>

  <CadreEntree
    v-else-if="parcoursPerdu"
    titre="Reprenons depuis le début"
    sousTitre="Votre inscription n’a pas été conservée."
    :etape="2"
  >
    <PanneauBase>
      <p class="text-sm text-attenue">
        Rouvrez le lien d’invitation reçu : votre compte n’est pas encore créé, rien n’est perdu.
      </p>
    </PanneauBase>
  </CadreEntree>

  <CadreEntree
    v-else
    titre="Choisir vos identifiants"
    sousTitre="Pour revenir sur l’arbre."
    :etape="2"
  >
    <PanneauBase>
      <form novalidate @submit.prevent="soumettre">
        <div class="flex flex-col gap-3.5">
          <ChampTexte
            v-model="email"
            etiquette="Adresse de courriel"
            type="email"
            aide="Pour vous connecter et être prévenu."
            autocomplete="email"
            :erreur="messageCourriel()"
            requis
          />

          <ChampTexte
            v-model="telephone"
            etiquette="Téléphone"
            type="tel"
            aide="Seul l’administrateur le voit, pour garder le contact avec la famille."
            autocomplete="tel"
            :erreur="messageTelephone()"
          />

          <ChampTexte
            v-model="motDePasse"
            etiquette="Mot de passe"
            type="password"
            :aide="`${LONGUEUR_MINIMALE} caractères au minimum. Une petite phrase fait un bon mot de passe.`"
            autocomplete="new-password"
            :erreur="messageMotDePasse()"
            requis
          />

          <PartageNaissance v-model="partage" :erreurs="refusPartage" />
        </div>

        <div class="mt-5">
          <BoutonBase type="submit" :chargement="envoi" :desactive="envoi" pleineLargeur>
            Créer mon compte
          </BoutonBase>
        </div>
      </form>
    </PanneauBase>

    <p v-if="sansFiche" class="mt-3 text-xs text-attenue">
      Votre fiche sera retrouvée ou créée à partir de votre description{{ avecMessageVocal ? ' et de votre message vocal' : '' }}.
      <RouterLink :to="{ name: 'inscription.rattachement' }" class="underline">
        Modifier ma description
      </RouterLink>
    </p>

    <!-- Les refus sur la description : elle se corrige à l'étape d'avant. -->
    <PanneauBase v-if="refusDeclaration.length > 0" class="mt-4">
      <p class="text-sm font-medium text-alerte">Votre description doit être corrigée :</p>
      <ul class="mt-1.5 list-disc pl-5 text-sm text-alerte">
        <li v-for="message in refusDeclaration" :key="message">{{ message }}</li>
      </ul>
      <RouterLink
        :to="{ name: 'inscription.rattachement' }"
        class="mt-2 inline-block text-sm font-semibold text-accent underline"
      >
        Revenir à ma description
      </RouterLink>
    </PanneauBase>

    <MessageErreur
      v-else-if="erreur !== null"
      class="mt-4"
      :erreur="erreur"
      @action="suivreConduite"
    />
  </CadreEntree>
</template>
