<script setup lang="ts">
/**
 * Les utilisateurs — § 10.1, en tableau : une ligne par compte, les gestes en
 * icônes (libellé au survol et pour les lecteurs d'écran).
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
 * **Les quotas d'usage** (analyses et questions de l'assistant, médias) : la
 * colonne « Quotas » lit, pour chaque compte, sa consommation sur sa limite
 * effective — lue sans être décomptée par le serveur —, et l'icône jauge ouvre
 * l'éditeur de ses limites personnelles (vide = défaut). Le rattachement
 * assisté compte sur une clé unique pour toute l'installation : il a son
 * panneau à lui, sous le tableau, et non une ligne par compte qui laisserait
 * croire qu'un administrateur a son propre compteur.
 *
 * **La réciprocité des dates de naissance** (ADR 0016). La colonne
 * « Rattachement » dit ce que le membre partage de sa date de naissance, et
 * l'icône calendrier lève — ou rétablit — pour ce compte la restriction : il
 * voit alors les dates des vivants sans que son propre choix les réduise. Les
 * **photos** n'en dépendent pas : sans photo de lui, elles restent floutées.
 *
 * **« Voir comme ce membre » a été retiré de cet écran** (01/10/2026) :
 * « Se connecter en tant que » (ADR 0010) montre la même chose, et davantage.
 * Une bascule restée active côté serveur se quitte toujours par le bandeau
 * permanent du cadre.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  activerMembre,
  incarnerUtilisateur,
  listerMembresEnAttente,
  listerQuotasGlobaux,
  listerUtilisateurs,
  modifierQuotasGlobaux,
  modifierQuotasUtilisateur,
  modifierUtilisateur,
  type LimitesDeQuotas,
  type PhotoARattacher,
  type QuotaGlobal,
  type UtilisateurAdministration,
} from '@/lib/api'
import { rafraichirSession, useSession } from '@/composables/useSession'
import BoutonIcone from '@/components/base/BoutonIcone.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import OngletsAdministration from './OngletsAdministration.vue'
import MentionDeRattachement from '@/components/administration/MentionDeRattachement.vue'
import PortraitAValider from '@/components/administration/PortraitAValider.vue'
import QuotasDuCompte from '@/components/administration/QuotasDuCompte.vue'
import EditeurDeQuotas, { type EditionDeQuotas } from '@/components/administration/EditeurDeQuotas.vue'
import { resumePartage } from '@/components/compte/partageNaissance'
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
/** Les quotas à clé globale (le rattachement assisté). */
const quotasGlobaux = ref<QuotaGlobal[]>([])
/** L'éditeur de quotas ouvert : sur un compte, ou sur les quotas globaux. */
const edition = ref<(EditionDeQuotas & { cible: number | 'installation' }) | null>(null)
const enregistrementDesQuotas = ref(false)
const erreurDesQuotas = ref<unknown>(null)

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))
const moi = computed(() => session.value?.utilisateur.id ?? null)

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    const [liste, file, globaux] = await Promise.all([
      listerUtilisateurs({ parPage: 100 }),
      listerMembresEnAttente(),
      listerQuotasGlobaux(),
    ])
    utilisateurs.value = liste.elements
    quotasGlobaux.value = globaux.quotas
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

/** Le nom de la fiche rattachée quand le cercle la laisse voir, sinon celui du compte. */
function nomDeLaLigne(utilisateur: UtilisateurAdministration): string {
  const fiche = utilisateur.membre?.personLabel
  return fiche != null && fiche.trim() !== '' ? fiche : utilisateur.displayName
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

/** ADR 0016 — absent d'un serveur antérieur : la restriction s'applique. */
function restrictionIgnoree(utilisateur: UtilisateurAdministration): boolean {
  return utilisateur.ignoreRestrictionNaissance === true
}

function basculerRestrictionNaissance(utilisateur: UtilisateurAdministration): void {
  void agir(utilisateur.id, () =>
    modifierUtilisateur(utilisateur.id, { ignoreRestrictionNaissance: !restrictionIgnoree(utilisateur) }),
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

function editerLesQuotas(utilisateur: UtilisateurAdministration): void {
  if (suspendu.value || enCours.value !== null) return
  erreurDesQuotas.value = null
  edition.value = {
    cible: utilisateur.id,
    titre: 'Quotas du compte',
    precision: `${nomDeLaLigne(utilisateur)} — ${utilisateur.email}`,
    quotas: utilisateur.quotas,
  }
}

function editerLesQuotasGlobaux(): void {
  if (suspendu.value) return
  erreurDesQuotas.value = null
  edition.value = {
    cible: 'installation',
    titre: 'Quotas de l’installation',
    precision: 'Un seul compteur pour tous les administrateurs.',
    quotas: quotasGlobaux.value,
  }
}

/**
 * Enregistre puis remplace **la seule ligne** concernée par la réponse : pas
 * de rechargement de la liste, qui ferait clignoter tout le tableau pour un
 * chiffre.
 */
async function enregistrerLesQuotas(limites: LimitesDeQuotas): Promise<void> {
  const cible = edition.value?.cible
  if (cible === undefined || enregistrementDesQuotas.value) return
  enregistrementDesQuotas.value = true
  erreurDesQuotas.value = null
  try {
    if (cible === 'installation') {
      quotasGlobaux.value = (await modifierQuotasGlobaux(limites)).quotas
    } else {
      const ligne = await modifierQuotasUtilisateur(cible, limites)
      utilisateurs.value = utilisateurs.value.map((u) => (u.id === ligne.id ? ligne : u))
    }
    edition.value = null
  } catch (cause) {
    erreurDesQuotas.value = cause
  } finally {
    enregistrementDesQuotas.value = false
  }
}

function dateLisible(valeur: string | null): string {
  if (valeur === null) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>

<template>
  <!-- La barre d'écran de l'administration : les trois sections en onglets,
       et rien d'autre ici — pas de filtre, donc pas de feuillet ni de bouton
       filtres sur téléphone. Voir `OngletsAdministration`. -->
  <BarreEcran ecran="Administration" :reinitialisable="false">
    <template #vue><OngletsAdministration /></template>
  </BarreEcran>

  <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-6">
    <!-- Une vue empruntée encore active éteint les gestes : on le redit ici, à
         côté des boutons éteints. On en sort par le bandeau du cadre. -->
    <p
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      {{ raisonSuspension }}
    </p>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="charger" />

    <PanneauBase titre="Comptes">
      <SqueletteChargement v-if="chargement && utilisateurs.length === 0" :lignes="4" />

      <!-- Le tableau défile à l'horizontale sur un écran étroit plutôt que
           d'écraser ses colonnes. -->
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[940px] border-collapse text-sm">
          <thead>
            <tr class="border-b border-ligne text-left text-xs text-attenue">
              <th scope="col" class="py-2 pr-3 font-semibold">Compte</th>
              <th scope="col" class="py-2 pr-3 font-semibold">État</th>
              <th scope="col" class="py-2 pr-3 font-semibold">Rattachement</th>
              <th scope="col" class="py-2 pr-3 font-semibold whitespace-nowrap">Dernière connexion</th>
              <th scope="col" class="py-2 pr-3 font-semibold">
                Quotas
                <span class="font-normal">(<span class="text-accent" aria-hidden="true">◆</span> personnalisé)</span>
              </th>
              <th scope="col" class="py-2 text-right font-semibold">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-ligne">
            <tr v-for="utilisateur in utilisateurs" :key="utilisateur.id" class="align-top">
              <td class="py-3 pr-3">
                <!-- Rattaché : le nom de **sa fiche** (prénoms et nom de l'arbre),
                     celui du compte en second s'il diffère. -->
                <p class="font-medium">{{ nomDeLaLigne(utilisateur) }}</p>
                <p
                  v-if="
                    nomDeLaLigne(utilisateur) !== utilisateur.displayName &&
                    utilisateur.displayName !== utilisateur.email
                  "
                  class="text-xs text-attenue"
                >
                  compte « {{ utilisateur.displayName }} »
                </p>
                <p class="text-xs text-attenue [overflow-wrap:anywhere]">{{ utilisateur.email }}</p>
                <p v-if="utilisateur.telephone" class="text-xs text-attenue">
                  <a :href="`tel:${utilisateur.telephone.replace(/[^0-9+]/g, '')}`" class="underline">{{ utilisateur.telephone }}</a>
                </p>
              </td>

              <td class="py-3 pr-3">
                <div class="flex flex-col items-start gap-1">
                  <!-- Les trois valeurs de `StatutCompte` sont celles de la
                       pastille, et la valeur affichée reste celle du modèle (§ 1.2). -->
                  <PastilleEtat :etat="utilisateur.status" />
                  <span v-if="estAdministrateur(utilisateur)" class="text-xs font-semibold text-accent">
                    Administrateur
                  </span>
                </div>
              </td>

              <td class="max-w-80 py-3 pr-3">
                <!-- Le rattachement, dans ses trois états — y compris celui où
                     il n'y en a aucun. Le détail est dans `rattachement.ts`. -->
                <MentionDeRattachement :membre="utilisateur.membre" />
                <p
                  v-if="utilisateur.membre?.partageNaissance"
                  class="mt-1 text-xs text-attenue"
                  data-partage-du-membre
                >
                  Date de naissance : {{ resumePartage(utilisateur.membre.partageNaissance) }}
                </p>
                <p
                  v-if="restrictionIgnoree(utilisateur)"
                  class="mt-1 text-xs font-semibold text-accent"
                  data-restriction-ignoree
                >
                  Restriction de date de naissance ignorée
                </p>
                <PortraitAValider :photo="portraitAValider(utilisateur)" :nom="utilisateur.displayName" />
              </td>

              <td class="py-3 pr-3 whitespace-nowrap text-attenue">
                {{ utilisateur.lastLoginAt ? dateLisible(utilisateur.lastLoginAt) : '—' }}
              </td>

              <td class="py-3 pr-3">
                <QuotasDuCompte :quotas="utilisateur.quotas" />
              </td>

              <td class="py-3">
                <div class="flex justify-end gap-1.5">
                  <!-- Un compte `a_rattacher` n'a pas de fiche : « Valider »
                       rendrait `422` faute de `personId`. La décision se prend
                       sur sa carte, avec la description et la suggestion. -->
                  <RouterLink
                    v-if="aRattacher(utilisateur)"
                    :to="{ name: 'administration.rattachements' }"
                    class="inline-flex size-8 items-center justify-center rounded-champ border border-accent text-accent hover:bg-accent-doux"
                    aria-label="Identifier sa fiche"
                    title="Identifier sa fiche"
                  >
                    <svg
                      viewBox="0 0 24 24"
                      class="size-4"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2"
                      stroke-linecap="round"
                      aria-hidden="true"
                    >
                      <circle cx="11" cy="11" r="6" />
                      <path d="M20 20l-4.5-4.5" />
                    </svg>
                  </RouterLink>

                  <BoutonIcone
                    v-else-if="utilisateur.membre !== null && utilisateur.membre.status === 'en_attente'"
                    libelle="Valider le rattachement"
                    variante="accent"
                    :chargement="enCours === utilisateur.id"
                    :desactive="suspendu"
                    :infobulle="infobulle"
                    @click="valider(utilisateur)"
                  >
                    <path d="M5 12.5l4.5 4.5L19 7.5" />
                  </BoutonIcone>

                  <BoutonIcone
                    v-if="incarnable(utilisateur) && vueEmpruntee === null"
                    libelle="Se connecter en tant que"
                    :chargement="enCours === utilisateur.id"
                    :desactive="suspendu || enCours !== null"
                    :infobulle="infobulle"
                    @click="incarner(utilisateur)"
                  >
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                    <path d="M10 17l5-5-5-5" />
                    <path d="M15 12H3" />
                  </BoutonIcone>

                  <BoutonIcone
                    :libelle="
                      restrictionIgnoree(utilisateur)
                        ? 'Rétablir la restriction de date de naissance'
                        : 'Ignorer la restriction de date de naissance'
                    "
                    :variante="restrictionIgnoree(utilisateur) ? 'accent' : 'neutre'"
                    :desactive="suspendu || enCours !== null"
                    :infobulle="infobulle"
                    @click="basculerRestrictionNaissance(utilisateur)"
                  >
                    <rect x="4" y="5" width="16" height="15" rx="2" />
                    <path d="M4 10h16M9 3v4M15 3v4" />
                    <path v-if="!restrictionIgnoree(utilisateur)" d="M12 13v4" />
                    <path v-else d="M9.5 15l2 2 3.5-3.5" />
                  </BoutonIcone>

                  <BoutonIcone
                    libelle="Modifier les quotas"
                    :desactive="suspendu || enCours !== null"
                    :infobulle="infobulle"
                    @click="editerLesQuotas(utilisateur)"
                  >
                    <path d="M4 15a8 8 0 1 1 16 0" />
                    <path d="M12 15l4-5" />
                    <path d="M4 19h16" />
                  </BoutonIcone>

                  <!-- On ne se suspend pas soi-même, et on ne se retire pas son
                       propre rôle : les deux sont des façons de se verrouiller
                       dehors, et le serveur n'attrape que la seconde. -->
                  <template v-if="utilisateur.id !== moi">
                    <BoutonIcone
                      :libelle="estAdministrateur(utilisateur) ? 'Retirer l’administration' : 'Rendre administrateur'"
                      :desactive="suspendu || enCours !== null"
                      :infobulle="infobulle"
                      @click="basculerRole(utilisateur)"
                    >
                      <path d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6z" />
                      <path v-if="estAdministrateur(utilisateur)" d="M9 12h6" />
                      <path v-else d="M12 9v6M9 12h6" />
                    </BoutonIcone>

                    <BoutonIcone
                      v-if="suspensionBasculable(utilisateur)"
                      :libelle="utilisateur.status === 'suspendu' ? 'Réactiver' : 'Suspendre'"
                      :variante="utilisateur.status === 'suspendu' ? 'neutre' : 'danger'"
                      :desactive="suspendu || enCours !== null"
                      :infobulle="infobulle"
                      @click="basculerSuspension(utilisateur)"
                    >
                      <path v-if="utilisateur.status === 'suspendu'" d="M8 5v14l11-7z" />
                      <template v-else>
                        <circle cx="12" cy="12" r="9" />
                        <path d="M10 9v6M14 9v6" />
                      </template>
                    </BoutonIcone>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanneauBase>

    <!-- Les quotas à clé globale : un compteur pour toute l'installation. -->
    <PanneauBase v-if="quotasGlobaux.length > 0" titre="Quotas de l’installation">
      <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 flex-col gap-1">
          <QuotasDuCompte :quotas="quotasGlobaux.map((q) => ({ ...q, exempte: false }))" class="max-w-80" />
          <p class="text-xs text-attenue">Partagés par tous les administrateurs, et non comptés par compte.</p>
        </div>
        <BoutonIcone
          libelle="Modifier les quotas de l’installation"
          :desactive="suspendu"
          :infobulle="infobulle"
          @click="editerLesQuotasGlobaux"
        >
          <path d="M4 15a8 8 0 1 1 16 0" />
          <path d="M12 15l4-5" />
          <path d="M4 19h16" />
        </BoutonIcone>
      </div>
    </PanneauBase>
  </div>

  <EditeurDeQuotas
    :edition="edition"
    :chargement="enregistrementDesQuotas"
    :erreur="erreurDesQuotas"
    @enregistrer="enregistrerLesQuotas"
    @annuler="edition = null"
  />
</template>
