<script setup lang="ts">
/**
 * Les utilisateurs — § 10.1, **en cartes** (handoff « mode Administration »,
 * § 4.2) : une carte par compte, un bouton principal contextuel (« Valider »,
 * « Identifier sa fiche ») et, derrière « Actions ▾ » (« ⋯ » sur téléphone),
 * un **feuillet** qui nomme chaque geste et dit ce qu'il fait. Le tableau de
 * 940 px et ses six icônes sans libellé visible ont disparu : sur téléphone, il
 * fallait le faire défiler pour atteindre les gestes, et l'on devinait le geste
 * à l'icône.
 *
 * Une recherche (nom, courriel, n° de fiche) et des puces d'état — Tous · En
 * attente · Actifs · Suspendus · Admins — filtrent la liste, sur place.
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
 * trouvé, il n'a pas de fiche à valider. Sa carte renvoie à « À traiter »,
 * filtrée sur les rattachements et ouverte sur lui, où se lisent sa
 * description et la suggestion.
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
 * **Les quotas d'usage** (analyses et questions de l'assistant, médias) :
 * « Modifier les quotas », dans le feuillet, ouvre l'éditeur des limites
 * personnelles du compte (vide = défaut) ; l'éditeur montre la consommation sur
 * la limite effective. Le rattachement assisté compte sur une clé unique pour
 * toute l'installation : il a son panneau à lui, sous la liste, et non une
 * ligne par compte qui laisserait croire qu'un administrateur a son propre
 * compteur.
 *
 * **La réciprocité des dates de naissance** (ADR 0016). La colonne
 * de rattachement dit ce que le membre partage de sa date de naissance, et le
 * feuillet lève — ou rétablit — pour ce compte la restriction : il
 * voit alors les dates des vivants sans que son propre choix les réduise. Les
 * **photos** n'en dépendent pas : sans photo de lui, elles restent floutées.
 *
 * **« Voir comme ce membre » a été retiré de cet écran** (01/10/2026) :
 * « Se connecter en tant que » (ADR 0010) montre la même chose, et davantage.
 * Une bascule restée active côté serveur se quitte toujours par le bandeau
 * permanent du cadre.
 */
import { computed, onMounted, ref } from 'vue'
import { useEstMobile } from '@/composables/useTiroirs'
import { cleDe } from '@/components/administration/fileATraiter'
import { quotaDesRattachements } from '@/components/administration/rattachements'
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
import BoutonBase from '@/components/base/BoutonBase.vue'
import Feuillet from '@/components/base/Feuillet.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import MentionDeRattachement from '@/components/administration/MentionDeRattachement.vue'
import PortraitAValider from '@/components/administration/PortraitAValider.vue'
import EditeurDeQuotas, { type EditionDeQuotas } from '@/components/administration/EditeurDeQuotas.vue'
import { resumePartage } from '@/components/compte/partageNaissance'
import {
  ecrituresSuspendues,
  infobulleSuspension,
  motifSuspension,
} from '@/components/administration/ecriture'

const { session, vueEmpruntee } = useSession()
const routeur = useRouter()
const estMobile = useEstMobile()

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

/** Inscrit sans fiche, pas encore décidé : il se traite dans « À traiter ». */
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

/* ───────────────── La recherche et les puces ───────────────── */

type Puce = 'tous' | 'en_attente' | 'actif' | 'suspendu' | 'admins'
const PUCES: { valeur: Puce; libelle: string }[] = [
  { valeur: 'tous', libelle: 'Tous' },
  { valeur: 'en_attente', libelle: 'En attente' },
  { valeur: 'actif', libelle: 'Actifs' },
  { valeur: 'suspendu', libelle: 'Suspendus' },
  { valeur: 'admins', libelle: 'Admins' },
]
const puce = ref<Puce>('tous')
const recherche = ref('')

function dansLaPuce(utilisateur: UtilisateurAdministration, valeur: Puce): boolean {
  if (valeur === 'tous') return true
  if (valeur === 'admins') return estAdministrateur(utilisateur)
  return utilisateur.status === valeur
}

const comptesParPuce = computed(
  () => Object.fromEntries(PUCES.map((p) => [p.valeur, utilisateurs.value.filter((u) => dansLaPuce(u, p.valeur)).length])) as Record<Puce, number>,
)

/** Nom de la fiche ou du compte, courriel, n° de fiche : sans accents ni casse. */
function normaliser(texte: string): string {
  return texte.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}

const affiches = computed(() => {
  const terme = normaliser(recherche.value.trim().replace(/^(n°|#)\s*/i, ''))
  return utilisateurs.value.filter((u) => {
    if (!dansLaPuce(u, puce.value)) return false
    if (terme === '') return true
    const fiche = u.membre?.personId != null ? String(u.membre.personId) : ''
    return [nomDeLaLigne(u), u.displayName, u.email, fiche].some((champ) => normaliser(champ).includes(terme))
  })
})

/** Les initiales de l'avatar : deux lettres, du nom affiché. */
function initiales(utilisateur: UtilisateurAdministration): string {
  const mots = nomDeLaLigne(utilisateur).split(/[\s@.-]+/).filter((m) => m !== '')
  return mots
    .slice(0, 2)
    .map((m) => m[0]?.toUpperCase() ?? '')
    .join('')
}

/** « Date de naissance : … · vu le … » — ce qui se diagnostique d'un coup d'œil. */
function ligneDeDetail(utilisateur: UtilisateurAdministration): string {
  const morceaux: string[] = []
  if (utilisateur.membre?.partageNaissance) morceaux.push(`Date de naissance : ${resumePartage(utilisateur.membre.partageNaissance)}`)
  morceaux.push(utilisateur.lastLoginAt ? `vu le ${dateLisible(utilisateur.lastLoginAt)}` : 'jamais connecté')
  return morceaux.join(' · ')
}

/** Le rattachement assisté : « 37 restantes sur 40 (24 h) ». */
const quotaInstallation = computed(() => {
  const restant = quotaDesRattachements(quotasGlobaux.value)
  if (restant !== null) return `${restant.restant} restantes sur ${restant.limite} (${restant.fenetre})`
  const premier = quotasGlobaux.value[0]
  return premier === undefined ? '' : `${premier.consomme} sur ${premier.limite} ${premier.fenetre}`
})

/* ───────────────── Le feuillet d'actions ───────────────── */

const actionsDe = ref<UtilisateurAdministration | null>(null)

function ouvrirActions(utilisateur: UtilisateurAdministration): void {
  actionsDe.value = utilisateur
}

/** Ferme le feuillet, puis fait le geste : la liste relue ne doit pas trouver un feuillet sur une ligne périmée. */
function depuisLeFeuillet(geste: (u: UtilisateurAdministration) => unknown): void {
  const utilisateur = actionsDe.value
  actionsDe.value = null
  if (utilisateur !== null) void geste(utilisateur)
}

/** Vers « À traiter », filtrée sur les rattachements et ouverte sur ce compte. */
function lienDIdentification(utilisateur: UtilisateurAdministration) {
  const membre = utilisateur.membre
  return {
    name: 'administration.a-traiter',
    query: membre === null ? { type: 'rattachement' } : { type: 'rattachement', sel: cleDe('rattachement', membre.id) },
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <!-- Une vue empruntée encore active éteint les gestes : on le redit ici, à
         côté des boutons éteints. On en sort par le bandeau du cadre. -->
    <p
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      {{ raisonSuspension }}
    </p>

    <label class="flex min-h-11 items-center gap-2 rounded-champ border border-ligne bg-panneau px-3 md:min-h-9">
      <span class="text-attenue" aria-hidden="true">⌕</span>
      <span class="sr-only">Chercher un compte</span>
      <!-- `:value` + `@input` plutôt que `v-model` : l'écran se monte aussi dans
           le rendu en mémoire des tests, qui ne connaît pas les directives. -->
      <input
        :value="recherche"
        type="search"
        placeholder="Nom, e-mail, n° de fiche…"
        class="h-8 min-w-0 flex-1 bg-transparent text-sm focus:outline-none"
        @input="recherche = ($event.target as HTMLInputElement).value"
      />
    </label>

    <div class="puces-defilantes flex gap-2 overflow-x-auto overflow-y-hidden py-0.5" role="group" aria-label="État des comptes affichés">
      <button
        v-for="p in PUCES"
        :key="p.valeur"
        type="button"
        class="flex min-h-11 flex-none items-center gap-1.5 rounded-pastille border px-[13px] text-[13px] whitespace-nowrap md:min-h-9"
        :class="p.valeur === puce ? 'border-accent bg-accent-doux font-semibold text-texte' : 'border-ligne bg-panneau text-attenue'"
        :aria-pressed="p.valeur === puce"
        @click="puce = p.valeur"
      >
        {{ p.libelle }}<span class="font-medium text-attenue tnum">{{ comptesParPuce[p.valeur] }}</span>
      </button>
    </div>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="charger" />

    <SqueletteChargement v-if="chargement && utilisateurs.length === 0" :lignes="4" />

    <p
      v-else-if="affiches.length === 0"
      class="rounded-panneau border border-ligne bg-panneau px-4 py-7 text-center text-attenue"
    >
      Aucun compte ne correspond.
    </p>

    <ul v-else class="m-0 flex list-none flex-col gap-px overflow-hidden rounded-panneau border border-ligne bg-ligne p-0">
      <li
        v-for="utilisateur in affiches"
        :key="utilisateur.id"
        class="flex flex-wrap items-center gap-x-4 gap-y-2.5 bg-panneau px-4 py-3"
        :data-utilisateur="utilisateur.id"
      >
        <!-- L'identité : initiales, nom (celui de la fiche quand le cercle la
             laisse voir), état, « Admin », courriel. -->
        <div class="flex min-w-0 flex-[1_1_230px] items-center gap-3">
          <span
            class="flex size-[38px] flex-none items-center justify-center rounded-full bg-inconnu text-[13px] font-bold"
            aria-hidden="true"
          >{{ initiales(utilisateur) }}</span>
          <span class="flex min-w-0 flex-col">
            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span class="font-semibold">{{ nomDeLaLigne(utilisateur) }}</span>
              <!-- Les trois valeurs de `StatutCompte` sont celles de la
                   pastille, et la valeur affichée reste celle du modèle (§ 1.2). -->
              <PastilleEtat :etat="utilisateur.status" />
              <span v-if="estAdministrateur(utilisateur)" class="text-[11.5px] font-semibold text-accent">Admin</span>
            </span>
            <span class="text-[12.5px] text-attenue [overflow-wrap:anywhere]">{{ utilisateur.email }}</span>
            <span v-if="utilisateur.telephone" class="text-[12.5px] text-attenue">
              <a :href="`tel:${utilisateur.telephone.replace(/[^0-9+]/g, '')}`" class="underline">{{ utilisateur.telephone }}</a>
            </span>
          </span>
        </div>

        <!-- Le rattachement, dans ses trois états — y compris celui où il n'y
             en a aucun. Le détail est dans `rattachement.ts`. -->
        <div class="flex min-w-0 flex-[1_1_200px] flex-col text-[13px]">
          <MentionDeRattachement :membre="utilisateur.membre" />
          <span class="text-[12.5px] text-attenue" :data-partage-du-membre="utilisateur.membre?.partageNaissance ? '' : undefined">
            {{ ligneDeDetail(utilisateur) }}
          </span>
          <span
            v-if="restrictionIgnoree(utilisateur)"
            class="text-[12.5px] font-semibold text-accent"
            data-restriction-ignoree
          >
            Restriction de date de naissance ignorée
          </span>
        </div>

        <div v-if="portraitAValider(utilisateur) !== null" class="flex-[0_1_210px] [&>*]:mt-0">
          <PortraitAValider :photo="portraitAValider(utilisateur)" :nom="utilisateur.displayName" />
        </div>

        <div class="ml-auto flex items-center gap-2">
          <!-- Un compte `a_rattacher` n'a pas de fiche : « Valider » rendrait
               `422` faute de `personId`. La décision se prend dans « À
               traiter », avec la description et la suggestion. -->
          <RouterLink
            v-if="aRattacher(utilisateur)"
            :to="lienDIdentification(utilisateur)"
            class="inline-flex min-h-11 items-center rounded-champ border border-accent bg-accent px-3.5 text-[13.5px] font-semibold whitespace-nowrap text-fond hover:opacity-90 md:min-h-9"
          >
            Identifier sa fiche
          </RouterLink>
          <BoutonBase
            v-else-if="utilisateur.membre !== null && utilisateur.membre.status === 'en_attente'"
            class="min-h-11 md:min-h-9"
            :chargement="enCours === utilisateur.id"
            :desactive="suspendu || enCours !== null"
            :title="infobulle"
            @click="valider(utilisateur)"
          >
            Valider
          </BoutonBase>

          <button
            type="button"
            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-champ border border-ligne bg-panneau px-2.5 text-[13.5px] whitespace-nowrap hover:border-accent md:min-h-9"
            aria-label="Autres actions"
            aria-haspopup="dialog"
            @click="ouvrirActions(utilisateur)"
          >
            {{ estMobile ? '⋯' : 'Actions ▾' }}
          </button>
        </div>
      </li>
    </ul>

    <!-- Les quotas à clé globale : un compteur pour toute l'installation. -->
    <div
      v-if="quotasGlobaux.length > 0"
      class="flex flex-wrap items-center justify-between gap-2 rounded-panneau border border-ligne bg-panneau px-4 py-3"
    >
      <span class="flex flex-col text-[13px]">
        <span class="font-semibold">Quotas de l’installation</span>
        <span class="text-attenue">
          Rattachement assisté : {{ quotaInstallation }}. Partagé par tous les administrateurs.
        </span>
      </span>
      <BoutonBase
        variante="secondaire"
        class="min-h-11 md:min-h-9"
        :desactive="suspendu"
        :title="infobulle"
        @click="editerLesQuotasGlobaux"
      >
        Modifier
      </BoutonBase>
    </div>
  </div>

  <!-- Le feuillet d'actions d'un compte : chaque geste nommé, et ce qu'il fait. -->
  <Feuillet :ouvert="actionsDe !== null" :titre="actionsDe === null ? '' : nomDeLaLigne(actionsDe)" @fermer="actionsDe = null">
    <template v-if="actionsDe !== null">
      <p class="-mt-2 text-[13px] text-attenue [overflow-wrap:anywhere]">{{ actionsDe.email }}</p>
      <p v-if="raisonSuspension !== null" class="text-[13px] text-accent">{{ raisonSuspension }}</p>
      <div class="flex flex-col gap-px overflow-hidden rounded-panneau border border-ligne bg-ligne">
        <button
          v-if="incarnable(actionsDe) && vueEmpruntee === null"
          type="button"
          class="ligne-action"
          :disabled="suspendu || enCours !== null"
          data-action="incarner"
          @click="depuisLeFeuillet(incarner)"
        >
          <span class="font-semibold">Se connecter en tant que</span>
          <span class="text-[12.5px] text-attenue">Voir l’arbre avec ses droits</span>
        </button>
        <button
          type="button"
          class="ligne-action"
          :disabled="suspendu || enCours !== null"
          data-action="quotas"
          @click="depuisLeFeuillet(editerLesQuotas)"
        >
          <span class="font-semibold">Modifier les quotas</span>
          <span class="text-[12.5px] text-attenue">Analyses, questions, médias</span>
        </button>
        <button
          type="button"
          class="ligne-action"
          :disabled="suspendu || enCours !== null"
          data-action="restriction"
          @click="depuisLeFeuillet(basculerRestrictionNaissance)"
        >
          <span class="font-semibold">
            {{ restrictionIgnoree(actionsDe) ? 'Rétablir la restriction de date de naissance' : 'Ignorer la restriction de date de naissance' }}
          </span>
          <span class="text-[12.5px] text-attenue">
            {{
              restrictionIgnoree(actionsDe)
                ? 'Ses propres choix de partage limitent de nouveau ce qu’il voit'
                : 'Il voit les dates des vivants, quel que soit son propre partage'
            }}
          </span>
        </button>
        <!-- On ne se suspend pas soi-même, et on ne se retire pas son propre
             rôle : les deux sont des façons de se verrouiller dehors, et le
             serveur n'attrape que la seconde. -->
        <template v-if="actionsDe.id !== moi">
          <button
            type="button"
            class="ligne-action"
            :disabled="suspendu || enCours !== null"
            data-action="role"
            @click="depuisLeFeuillet(basculerRole)"
          >
            <span class="font-semibold">{{ estAdministrateur(actionsDe) ? 'Retirer l’administration' : 'Rendre administrateur' }}</span>
            <span class="text-[12.5px] text-attenue">
              {{ estAdministrateur(actionsDe) ? 'Il redevient un membre' : 'Il accède à ce mode, et à tout ce qu’il décide' }}
            </span>
          </button>
          <button
            v-if="suspensionBasculable(actionsDe)"
            type="button"
            class="ligne-action"
            :class="actionsDe.status === 'suspendu' ? '' : 'text-alerte'"
            :disabled="suspendu || enCours !== null"
            data-action="suspension"
            @click="depuisLeFeuillet(basculerSuspension)"
          >
            <span class="font-semibold">{{ actionsDe.status === 'suspendu' ? 'Réactiver le compte' : 'Suspendre le compte' }}</span>
            <span class="text-[12.5px] text-attenue">
              {{ actionsDe.status === 'suspendu' ? 'Il peut de nouveau se connecter' : 'Il ne peut plus se connecter' }}
            </span>
          </button>
        </template>
      </div>
    </template>
  </Feuillet>

  <EditeurDeQuotas
    :edition="edition"
    :chargement="enregistrementDesQuotas"
    :erreur="erreurDesQuotas"
    @enregistrer="enregistrerLesQuotas"
    @annuler="edition = null"
  />
</template>

<style scoped>
.ligne-action {
  display: flex;
  min-height: 52px;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  padding: 8px 16px;
  background: var(--color-panneau);
  text-align: left;
}
.ligne-action:hover:not(:disabled) {
  background: color-mix(in srgb, var(--h-accent-doux) 40%, var(--h-panneau));
}
.ligne-action:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
</style>
