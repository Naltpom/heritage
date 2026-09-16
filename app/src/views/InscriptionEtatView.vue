<script setup lang="ts">
/**
 * Inscription, étape 3 — l'état du compte (§ 4.4).
 *
 * Cet écran a deux vies. Il termine l'inscription, et il reste ensuite
 * l'**écran d'accueil d'un compte en attente de validation** : c'est là que la
 * garde de route envoie un compte non validé qui tente d'ouvrir une route de
 * membre (`acces.ts`). Le renvoyer à la connexion lui ferait croire que sa
 * session est tombée, alors qu'il lui manque seulement un rattachement.
 *
 * Il exige donc une **session**, pas l'anonymat (`meta.acces: 'attente'`), et il
 * est rendu **avec** le cadre applicatif : son lecteur est connecté.
 *
 * **Le ton est tout le travail de cet écran.** L'attente peut durer plusieurs
 * jours, et « un écran vide pousse à l'abandon » (§ 4.5). Il faut donc dire
 * trois choses, dans cet ordre : ce qui s'est bien passé, ce qui manque encore
 * et qui s'en charge, et ce qu'on peut faire en attendant. Rien qui ressemble à
 * une panne, rien qui ressemble à un refus.
 *
 * `cequejevois` porte **deux nombres, pas une liste** : « 256 personnes décédées
 * sur 274 ». C'est ce qui donne du sens à l'attente sans rien exposer. Il
 * disparaît une fois le compte activé — d'où le rendu conditionnel, et non un
 * « 0 sur 0 » (§ 1.3 : ce qui est absent de la charge utile ne s'affiche pas).
 *
 * **Aucune clé `personId` ni `personLabel`** tant que `status` vaut
 * `en_attente`, et il ne faut pas les chercher ailleurs : ni dans
 * `GET /api/session`, ni dans une notification. Le candidat a vu
 * « P. Romarin, 1980 » à l'étape 1b ; il ne reverra pas ce libellé ici, et
 * surtout pas le `displayName` réel de la fiche, qui peut porter un nom d'usage
 * ou un nom d'épouse qu'il ignore. Le pré-lien n'ouvre aucun accès : « confirmer
 * une date n'est pas la lire ».
 */
import { computed, onMounted } from 'vue'
import { lireEtatInscription } from '@/lib/api'
import { useAppelApi } from '@/composables/useAppelApi'
import { rafraichirSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'

const { donnees: etat, erreur, chargement, executer } = useAppelApi(lireEtatInscription)

onMounted(executer)

const enAttente = computed(() => etat.value?.status === 'en_attente')
const vu = computed(() => etat.value?.cequejevois ?? null)

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

          <p class="text-sm">
            Votre compte existe et vous êtes bien connecté·e. Il reste une étape, et elle
            ne dépend pas de vous : une personne qui administre l’arbre doit confirmer
            que la fiche à laquelle vous vous êtes rattaché·e est bien la vôtre.
          </p>

          <p class="mt-2.5 text-sm text-attenue">
            Cette vérification est faite à la main, par quelqu’un de la famille. Elle peut
            prendre quelques jours. Vous recevrez un courriel dès qu’elle sera faite — il
            n’y a rien à relancer, et vous pouvez fermer cette page sans rien perdre.
          </p>
        </PanneauBase>

        <!-- « Un écran vide pousse à l'abandon » : deux nombres suffisent à
             donner du sens à l'attente, là où une liste exposerait des gens. -->
        <PanneauBase v-if="vu !== null" titre="En attendant" class="mt-4">
          <p class="text-sm">
            L’arbre ancien vous est déjà ouvert :
            <strong class="tnum">{{ vu.personnesDecedees }}</strong> fiches de personnes
            décédées, sur <strong class="tnum">{{ vu.personnesTotales }}</strong> au total.
            Ce sont des faits historiques — naissances, mariages, lieux — et il y a de quoi
            lire.
          </p>

          <p class="mt-2.5 text-sm text-attenue">
            Les fiches des personnes vivantes, elles, restent fermées jusqu’à la
            validation. C’est vrai pour tout le monde dans cette situation, y compris pour
            la fiche à laquelle vous vous rattachez.
          </p>

          <template #pied>
            <RouterLink
              :to="{ name: 'arbre' }"
              class="text-sm font-semibold text-accent underline"
            >
              Parcourir l’arbre
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
          <RouterLink v-slot="{ navigate }" :to="{ name: 'arbre' }" custom>
            <BoutonBase @click="entrerDansLArbre().then(() => navigate())">
              Entrer dans l’arbre
            </BoutonBase>
          </RouterLink>
        </template>
      </PanneauBase>
    </template>
  </div>
</template>
