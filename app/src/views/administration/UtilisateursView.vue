<script setup lang="ts">
/**
 * Les utilisateurs — § 10.1, et la bascule « voir comme un membre » (§ 10.4).
 *
 * **C'est aussi le seul écran où se diagnostique un compte qui regarde l'arbre
 * de quelqu'un d'autre.** Le point de vue d'un visiteur se choisit d'après la
 * fiche à laquelle son compte est rattaché ; faute de rattachement *actif*, il
 * retombe sur la racine — et rien, côté visiteur, ne le lui dit. La mention de
 * rattachement est donc affichée dans **les trois** états, y compris celui où
 * il n'y a aucune ligne `mbr_member` : voir `administration/rattachement.ts`.
 *
 * Quatre gestes, et chacun a un piège propre.
 *
 * **Valider un compte en attente.** C'est le geste le plus important de cet
 * écran : c'est le moment exact où le régime change. Le compte passe `actif`,
 * **et il obtient un cercle** — il n'en avait pas, et ce n'était pas un cercle
 * réduit mais un régime distinct (§ 12). Quelqu'un attend derrière, parfois
 * depuis plusieurs jours.
 *
 * Sauf pour un compte **`a_rattacher`** (ADR 0013) : inscrit sans s'être
 * trouvé, il n'a pas de fiche à valider. Sa ligne renvoie à l'onglet
 * « À rattacher », où se lisent sa description et la suggestion.
 *
 * **La photo d'inscription se voit avant de valider.** Un compte en attente a
 * pu déposer sa photo ; la validation en fait **le portrait de sa fiche**, sans
 * autre geste. La liste des utilisateurs (servie par `Core`) ne la porte pas :
 * on lit donc aussi la file des membres en attente (`Membership`), qui la
 * porte, et la vignette s'affiche sur la ligne, avec la mention « deviendra le
 * portrait de sa fiche à la validation ». Les deux lectures sont faites
 * ensemble, et l'écran attend les deux : une ligne affichée sans sa photo
 * inviterait précisément à la validation à l'aveugle qu'on corrige.
 *
 * **Suspendre.** `status` accepte `actif` et `suspendu`, **jamais
 * `en_attente`** : on ne remet pas un compte en attente, on le suspend. Un
 * compte suspendu ne s'authentifie plus du tout — `POST /api/connexion` rend
 * `401 compte_suspendu` et n'ouvre pas de session qu'il faudrait ensuite
 * filtrer.
 *
 * **Changer les rôles.** Retirer `ROLE_ADMIN` au dernier administrateur rend
 * `409 dernier_administrateur` : se verrouiller dehors est un incident sans
 * porte de secours autre que la console. Le message vient du serveur.
 *
 * **La bascule.** Elle **remplace un cercle par un autre**, elle n'en supprime
 * jamais un : l'administrateur n'a pas de mode « sans filtre », il passe par un
 * cercle complet (ADR 0003). Deux conséquences qu'il faut traiter ici et pas
 * ailleurs : recharger la session (elle porte `voirComme` et le cercle) **et**
 * oublier l'`ETag` du graphe, qui incorpore le membre emprunté — sans quoi on
 * revalide, on reçoit `304`, et on regarde son propre graphe non filtré en
 * croyant tester celui de quelqu'un d'autre. `rafraichirSession()` fait les deux.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  activerMembre,
  activerVueComme,
  incarnerUtilisateur,
  listerMembresEnAttente,
  listerUtilisateurs,
  modifierUtilisateur,
  quitterVueComme,
  type PhotoARattacher,
  type UtilisateurAdministration,
} from '@/lib/api'
import { rafraichirSession, useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import OngletsAdministration from './OngletsAdministration.vue'
import MentionDeRattachement from '@/components/administration/MentionDeRattachement.vue'
import PortraitAValider from '@/components/administration/PortraitAValider.vue'
import {
  ecrituresSuspendues,
  infobulleSuspension,
  motifSuspension,
} from '@/components/administration/ecriture'

const { session, vueEmpruntee } = useSession()
const routeur = useRouter()

const utilisateurs = ref<UtilisateurAdministration[]>([])
/** Les photos d'inscription en attente, par identifiant de **membre**. */
const portraits = ref<ReadonlyMap<number, PhotoARattacher>>(new Map())
const chargement = ref(false)
const erreur = ref<unknown>(null)
const enCours = ref<number | null>(null)

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))
const moi = computed(() => session.value?.utilisateur.id ?? null)

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    const [liste, file] = await Promise.all([
      listerUtilisateurs({ parPage: 100 }),
      listerMembresEnAttente(),
    ])
    utilisateurs.value = liste.elements
    portraits.value = new Map(
      file.elements.flatMap((membre) =>
        membre.portraitEnAttente === null ? [] : [[membre.id, membre.portraitEnAttente] as const],
      ),
    )
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

onMounted(charger)

/** Enveloppe commune : un seul geste à la fois, erreur remontée telle quelle. */
async function agir(id: number, geste: () => Promise<unknown>): Promise<void> {
  if (suspendu.value || enCours.value !== null) return
  enCours.value = id
  erreur.value = null
  try {
    await geste()
    await charger()
  } catch (cause) {
    erreur.value = cause
  } finally {
    enCours.value = null
  }
}

function estAdministrateur(utilisateur: UtilisateurAdministration): boolean {
  return utilisateur.roles.includes('ROLE_ADMIN')
}

/** Inscrit sans fiche, pas encore décidé : il se traite dans « À rattacher ». */
function aRattacher(utilisateur: UtilisateurAdministration): boolean {
  const membre = utilisateur.membre
  return membre !== null && membre.status === 'en_attente' && membre.matchKind === 'a_rattacher'
}

/**
 * La photo qui deviendra le portrait de la fiche à la validation — seulement
 * pour un compte encore en attente. Sous une vue empruntée, la vignette se
 * lirait avec le cercle du membre simulé et ne s'afficherait pas : on ne la
 * montre pas, et « Valider » y est de toute façon suspendu.
 */
function portraitAValider(utilisateur: UtilisateurAdministration): PhotoARattacher | null {
  const membre = utilisateur.membre
  if (membre === null || membre.status !== 'en_attente' || vueEmpruntee.value !== null) return null
  return portraits.value.get(membre.id) ?? null
}

function valider(utilisateur: UtilisateurAdministration): void {
  const membre = utilisateur.membre
  if (membre === null) return
  void agir(utilisateur.id, () => activerMembre(membre.id))
}

/**
 * « Réactiver » n'a de sens que pour un compte dont le rattachement est établi
 * (membre actif) — ou qui n'a pas de membre du tout. Un compte **refusé**
 * (membre suspendu, sans fiche) ou un compte en attente qu'on a suspendu ne
 * redevient pas actif par ce bouton : `core_user` seul passerait `actif`, et le
 * serveur le refuse (`409 rattachement_non_etabli`). Le compte en attente garde
 * son propre geste — « Valider le rattachement » ou « Identifier sa fiche ».
 */
function suspensionBasculable(utilisateur: UtilisateurAdministration): boolean {
  const membre = utilisateur.membre
  return utilisateur.status !== 'suspendu' || membre === null || membre.status === 'actif'
}

function basculerSuspension(utilisateur: UtilisateurAdministration): void {
  void agir(utilisateur.id, () =>
    modifierUtilisateur(utilisateur.id, {
      status: utilisateur.status === 'suspendu' ? 'actif' : 'suspendu',
    }),
  )
}

function basculerRole(utilisateur: UtilisateurAdministration): void {
  void agir(utilisateur.id, () =>
    modifierUtilisateur(utilisateur.id, {
      roles: estAdministrateur(utilisateur) ? ['ROLE_USER'] : ['ROLE_ADMIN'],
    }),
  )
}

/**
 * Entrer dans la vue d'un membre.
 *
 * `rafraichirSession()` est indispensable et n'est pas une précaution : c'est
 * lui qui pose `voirComme` dans le magasin — donc le bandeau permanent et les
 * boutons éteints — **et** qui oublie l'`ETag` du graphe.
 */
async function voirComme(utilisateur: UtilisateurAdministration): Promise<void> {
  const membre = utilisateur.membre
  if (membre === null || suspendu.value) return
  erreur.value = null
  try {
    await activerVueComme(membre.id)
    await rafraichirSession()
  } catch (cause) {
    erreur.value = cause
  }
}

/**
 * Se connecter **en tant que** ce compte (ADR 0010) : on devient le membre,
 * avec ses droits et rien de plus — donc plus cet écran, réservé à
 * l'administration. D'où le départ vers l'arbre, qu'il voit comme lui.
 */
async function incarner(utilisateur: UtilisateurAdministration): Promise<void> {
  if (suspendu.value || enCours.value !== null) return
  enCours.value = utilisateur.id
  erreur.value = null
  try {
    await incarnerUtilisateur(utilisateur.id)
    await rafraichirSession()
    await routeur.push({ name: 'arbre' })
  } catch (cause) {
    erreur.value = cause
  } finally {
    enCours.value = null
  }
}

/** Ce qu'on n'incarne pas — le serveur le refuse de toute façon (`409`). */
function incarnable(utilisateur: UtilisateurAdministration): boolean {
  return utilisateur.id !== moi.value && !estAdministrateur(utilisateur) && utilisateur.status !== 'suspendu'
}

async function quitter(): Promise<void> {
  erreur.value = null
  try {
    await quitterVueComme()
    await rafraichirSession()
  } catch (cause) {
    erreur.value = cause
  }
}

function dateLisible(valeur: string | null): string {
  if (valeur === null) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <!-- La barre d'écran de l'administration : les trois sections en onglets,
       et rien d'autre ici — pas de filtre, donc pas de feuillet ni de bouton
       filtres sur téléphone. Voir `OngletsAdministration`. -->
  <BarreEcran ecran="Administration" :reinitialisable="false">
    <template #vue><OngletsAdministration /></template>
  </BarreEcran>

  <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-6">
    <!-- La bascule est active : on le redit ici, avec le moyen d'en sortir.
         Le bandeau du socle le dit déjà en haut de toutes les pages ; ce
         rappel-ci est à côté des boutons qu'il éteint. -->
    <div
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      <p>{{ raisonSuspension }}</p>
      <div class="mt-2">
        <BoutonBase variante="secondaire" @click="quitter">Quitter la vue empruntée</BoutonBase>
      </div>
    </div>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="charger" />

    <PanneauBase titre="Comptes">
      <SqueletteChargement v-if="chargement && utilisateurs.length === 0" :lignes="4" />

      <ul v-else class="flex flex-col divide-y divide-ligne">
        <li
          v-for="utilisateur in utilisateurs"
          :key="utilisateur.id"
          class="py-3 first:pt-0 last:pb-0"
        >
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium">{{ utilisateur.displayName }}</span>
            <!-- Les trois valeurs de `StatutCompte` sont celles de la pastille,
                 et la valeur affichée reste celle du modèle (§ 1.2). -->
            <PastilleEtat :etat="utilisateur.status" />
            <span v-if="estAdministrateur(utilisateur)" class="text-xs font-semibold text-accent">
              Administrateur
            </span>
          </div>

          <p class="mt-0.5 text-sm text-attenue">
            {{ utilisateur.email }}
            <template v-if="utilisateur.lastLoginAt">
              — dernière connexion le {{ dateLisible(utilisateur.lastLoginAt) }}
            </template>
          </p>

          <!-- Le rattachement, dans ses trois états — y compris celui où il n'y
               en a aucun. Ce bloc était auparavant sous `v-if="membre !== null"`
               et ne montrait donc **rien** pour le seul cas qu'on ne peut pas
               deviner autrement. Le détail est dans `rattachement.ts`. -->
          <MentionDeRattachement :membre="utilisateur.membre" />

          <PortraitAValider :photo="portraitAValider(utilisateur)" :nom="utilisateur.displayName" />

          <div class="mt-2 flex flex-wrap gap-2">
            <!-- Un compte `a_rattacher` n'a pas de fiche : « Valider » rendrait
                 `422` faute de `personId`. La décision se prend sur sa carte,
                 avec la description et la suggestion — on y renvoie. -->
            <RouterLink
              v-if="aRattacher(utilisateur)"
              :to="{ name: 'administration.rattachements' }"
              class="inline-flex items-center rounded-champ border border-accent px-3 py-1.5 text-sm font-semibold text-accent hover:bg-accent-doux"
            >
              Identifier sa fiche
            </RouterLink>

            <BoutonBase
              v-else-if="utilisateur.membre !== null && utilisateur.membre.status === 'en_attente'"
              :chargement="enCours === utilisateur.id"
              :desactive="suspendu"
              :title="infobulle"
              @click="valider(utilisateur)"
            >
              Valider le rattachement
            </BoutonBase>

            <BoutonBase
              v-if="
                utilisateur.membre !== null &&
                utilisateur.membre.status === 'actif' &&
                vueEmpruntee === null
              "
              variante="secondaire"
              :desactive="suspendu"
              :title="infobulle"
              @click="voirComme(utilisateur)"
            >
              Voir comme ce membre
            </BoutonBase>

            <BoutonBase
              v-if="incarnable(utilisateur) && vueEmpruntee === null"
              variante="secondaire"
              :chargement="enCours === utilisateur.id"
              :desactive="suspendu || enCours !== null"
              :title="infobulle"
              @click="incarner(utilisateur)"
            >
              Se connecter en tant que
            </BoutonBase>

            <!-- On ne se suspend pas soi-même, et on ne se retire pas son propre
                 rôle : les deux sont des façons de se verrouiller dehors, et le
                 serveur n'attrape que la seconde. -->
            <template v-if="utilisateur.id !== moi">
              <BoutonBase
                variante="secondaire"
                :desactive="suspendu || enCours !== null"
                :title="infobulle"
                @click="basculerRole(utilisateur)"
              >
                {{ estAdministrateur(utilisateur) ? 'Retirer l’administration' : 'Rendre administrateur' }}
              </BoutonBase>

              <BoutonBase
                v-if="suspensionBasculable(utilisateur)"
                :variante="utilisateur.status === 'suspendu' ? 'secondaire' : 'danger'"
                :desactive="suspendu || enCours !== null"
                :title="infobulle"
                @click="basculerSuspension(utilisateur)"
              >
                {{ utilisateur.status === 'suspendu' ? 'Réactiver' : 'Suspendre' }}
              </BoutonBase>
            </template>
          </div>
        </li>
      </ul>
    </PanneauBase>
  </div>
</template>
