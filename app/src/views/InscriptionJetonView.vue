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
 *
 * **Un seul geste : « Commencer ».** Une fois le jeton reconnu — et seulement
 * alors : un lien refusé n'ouvre sur rien —, l'écran ne propose qu'un bouton.
 * La première fois, il lance la vidéo « Bienvenue », qui raconte le parcours
 * (se retrouver, créer son accès, être validé, explorer) ; c'est un clic, donc
 * le navigateur la laisse **parler**. À la fin, à la croix ou si elle ne charge
 * pas, le formulaire de rattachement s'ouvre directement. La vidéo n'est
 * **jamais** une étape :
 *
 * - elle ne passe **qu'une fois par navigateur** (`heritage.videos.bienvenue.vue`,
 *   voir `lib/videos.ts`) : ensuite, « Commencer » va droit au formulaire. La
 *   marque est posée quand le fichier s'est effectivement chargé (`chargee`),
 *   pas à l'ouverture : une vidéo absente ou un réseau coupé ne « consomment »
 *   pas la première fois ;
 * - elle ne s'ouvre plus d'elle-même à l'arrivée : sans geste, le navigateur
 *   l'aurait imposée muette, et il fallait chercher « Activer le son ».
 *
 * Rien de l'invitation n'est écrit pour autant : la marque dit « déjà vue sur
 * ce navigateur », pas qui l'a vue ni avec quel jeton (`fluxInscription.ts`).
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
import LecteurVideo from '@/components/video/LecteurVideo.vue'
import { bienvenueDejaVue, marquerBienvenueVue, videoParId } from '@/lib/videos'

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

/* ───────────────── « Commencer » : la vidéo, puis le formulaire ───────────────── */

const BIENVENUE = videoParId('bienvenue')
/** Le lecteur est affiché. Il se ferme de lui-même à la fin, à la croix, ou sur un échec. */
const videoOuverte = ref(false)

function versLeFormulaire(): void {
  videoOuverte.value = false
  void routeur.push({ name: 'inscription.rattachement' })
}

/** La vidéo la première fois ; le formulaire directement ensuite. */
function commencer(): void {
  if (bienvenueDejaVue()) versLeFormulaire()
  else videoOuverte.value = true
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
      <!-- Dire d'emblée qu'il s'agit de **se retrouver** — la fiche existe sans
           doute déjà — évite le malentendu le plus courant : « je crée mon
           profil ». Le reste, la vidéo le montre, puis le formulaire le demande. -->
      <p class="text-sm text-attenue">
        Retrouvez-vous dans l’arbre de la famille : deux minutes suffisent.
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

  <!-- Par-dessus l'écran, téléporté dans <body>. `chargee` : le fichier existe
       et se lit — c'est là, et pas avant, que « déjà vue » se retient. La fin,
       la croix et l'échec mènent tous au formulaire. -->
  <LecteurVideo
    v-if="videoOuverte && pret"
    :video="BIENVENUE"
    @chargee="marquerBienvenueVue()"
    @fin="versLeFormulaire"
    @fermer="versLeFormulaire"
  />
</template>
