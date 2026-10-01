<script setup lang="ts">
/**
 * Inscription, étape 1a — vérification du jeton (§ 4.1).
 *
 * C'est la toute première page que voit une personne invitée. Elle arrive par un
 * lien reçu par courriel, et elle n'a rien demandé : l'écran doit dire où elle
 * est et ce qu'on attend d'elle, avant tout formulaire.
 *
 * **Le jeton arrive sous deux formes.** Le contrat bâtit le lien avec le jeton
 * dans le chemin — `https://heritage.local/inscription/kJ8s-2Qb…` (§ 3.2) —, et
 * la forme `?jeton=…` est celle qu'on écrit à la main. `jetonDeLaRoute()` lit
 * l'une ou l'autre. Il ne juge **pas** de la forme du jeton : trier le mal formé
 * ici le rendrait distinguable de l'inconnu, ce que le `404` uniforme du serveur
 * empêche précisément.
 *
 * **Jeton inconnu, mal formé, expiré, révoqué ou déjà consommé : `404`**, corps
 * d'erreur identique dans les cinq cas, même message. L'écran ne les distingue
 * pas, et ne propose rien à réessayer — voir `LienNonValide.vue`, où c'est
 * détaillé.
 *
 * La réponse porte `mode`, et **rien sur la personne pré-liée** : ni `personId`,
 * ni `personLabel`, pas même une initiale. Le porteur du jeton apprend qu'on
 * l'attendait, pas **qui** on attendait. Les deux modes demandent ensuite les
 * mêmes trois champs — un formulaire qui ne réclamerait que la date en mode
 * confirmation dirait au porteur « nous savons déjà ton nom ».
 */
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { verifierJeton } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import CadreEntree from '@/components/inscription/CadreEntree.vue'
import LienNonValide from '@/components/inscription/LienNonValide.vue'
import { estLienNonValide, jetonDeLaRoute } from '@/components/inscription/lienInvitation'
import { fermerFlux, ouvrirFlux } from '@/components/inscription/fluxInscription'

const route = useRoute()
const routeur = useRouter()

const chargement = ref(true)
const erreur = ref<unknown>(null)
/** Vrai dès qu'on sait que ce lien ne mène à rien — quelle qu'en soit la cause. */
const lienRefuse = ref(false)
/** Vrai quand la vérification a réussi : on attend le geste du candidat. */
const pret = ref(false)

async function verifier(): Promise<void> {
  chargement.value = true
  erreur.value = null
  lienRefuse.value = false
  pret.value = false

  const jeton = jetonDeLaRoute(route)
  if (jeton === null) {
    // Pas de jeton du tout : même écran que le jeton refusé. Une page
    // « introuvable » serait un écran différent pour une cause que le candidat,
    // lui, ne peut pas distinguer — il a collé ce qu'il avait reçu.
    fermerFlux()
    lienRefuse.value = true
    chargement.value = false
    return
  }

  try {
    ouvrirFlux(jeton, await verifierJeton(jeton))
    pret.value = true
  } catch (cause) {
    fermerFlux()
    if (estLienNonValide(cause)) lienRefuse.value = true
    else erreur.value = cause
  } finally {
    chargement.value = false
  }
}

onMounted(verifier)

function commencer(): void {
  // Le jeton reste en mémoire et ne repart pas dans l'URL : le semer dans
  // l'historique et les `Referer` de trois écrans de plus n'apporterait rien.
  void routeur.push({ name: 'inscription.rattachement' })
}
</script>

<template>
  <CadreEntree
    titre="Vous êtes invité·e"
    sousTitre="L’arbre généalogique privé de la famille."
  >
    <PanneauBase v-if="chargement">
      <p class="mb-3 text-sm text-attenue" role="status">Vérification de votre lien…</p>
      <SqueletteChargement :lignes="2" />
    </PanneauBase>

    <!-- Les cinq causes de refus, un seul écran, aucun bouton. -->
    <LienNonValide v-else-if="lienRefuse" />

    <!-- Une panne, elle, se réessaie : elle ne dit rien du jeton. -->
    <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="verifier" />

    <PanneauBase v-else-if="pret">
      <h2 class="text-base font-semibold">Avant de commencer</h2>

      <p class="mt-2 text-sm text-attenue">
        Retrouvez-vous dans l’arbre, puis choisissez un mot de passe : deux minutes suffisent.
      </p>

      <!-- Dire d'emblée que la fiche existe peut-être déjà évite le malentendu
           le plus courant : « je crée mon profil ». Non — on rattache une
           personne déjà présente dans l'arbre à un compte. -->
      <p class="mt-2 text-sm text-attenue">
        Votre date de naissance sert seulement à vérifier que c’est bien vous.
      </p>

      <div class="mt-5">
        <BoutonBase pleineLargeur @click="commencer">Commencer</BoutonBase>
      </div>
    </PanneauBase>

    <template #pied>
      <p class="mt-5 text-center text-xs text-attenue">
        Vous avez déjà un compte ?
        <RouterLink :to="{ name: 'connexion' }" class="text-accent underline">
          Se connecter
        </RouterLink>
      </p>
    </template>
  </CadreEntree>
</template>
