/**
 * Le **mode administration** (handoff « mode Administration », option 1c).
 *
 * Un administrateur bascule entre deux modes, Membre et Admin, par un
 * interrupteur : 5ᵉ emplacement de la barre du bas sur téléphone, segmenté à
 * gauche de l'avatar sur bureau. En mode admin, la navigation montre À traiter ·
 * Utilisateurs · Invitations · Sources, et l'en-tête passe en fond accent.
 *
 * **Le mode se déduit de la route, il n'est pas un état séparé.** Toute route
 * `administration.*` publie la section `administration` (`sectionDe`) ; ce
 * fichier ne fait que la lire. Ainsi un lien profond — une notification qui
 * porte `linkRoute: 'administration.rgpd'` — ouvre directement le mode admin,
 * et le bouton retour du navigateur en sort sans rien à resynchroniser.
 *
 * L'interrupteur **navigue** :
 *  - Membre → Admin : vers la dernière route admin visitée, sinon « À traiter » ;
 *  - Admin → Membre : vers la dernière route membre visitée, sinon l'accueil.
 *
 * Les deux dernières routes sont retenues par le routeur à chaque navigation
 * (`memoriserRoute`, appelé depuis `router/index.ts`), dans le
 * `sessionStorage` : elles survivent à un rechargement, pas à la fermeture de
 * l'onglet. Ce ne sont que des adresses de l'application.
 *
 * Le fichier tient aussi **le compte de ce qui attend** une décision — les
 * pastilles d'« À traiter » (barre du bas, onglet du bureau) et de Moi. Une
 * seule lecture pour toutes ; l'écran « À traiter », qui charge la file
 * entière, publie ses propres comptes (`publierCompteurs`) plutôt que d'en
 * déclencher une seconde.
 *
 * État de portée module, comme `entete.ts` : l'en-tête, la barre du bas et les
 * écrans ne sont pas dans la même branche de l'arbre de composants. Aucun
 * `useRoute()` ici : l'en-tête et la barre ne lisent pas la route.
 */
import { computed, readonly, ref, type ComputedRef, type DeepReadonly, type Ref } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import { listerDemandesAdministration, listerDemandesRgpd, listerFilsAdministration, listerRattachements } from '@/lib/api'
import { attendUneReponse } from '@/components/administration/fileATraiter'
import { useEntete, type Section } from './entete'

const CLE_ADMIN = 'heritage.mode.derniere-route-admin'
const CLE_MEMBRE = 'heritage.mode.derniere-route-membre'

function lire(cle: string): string | null {
  try {
    return globalThis.sessionStorage?.getItem(cle) ?? null
  } catch {
    // Stockage refusé (navigation privée stricte) : le défaut fera l'affaire.
    return null
  }
}

function ecrire(cle: string, valeur: string): void {
  try {
    globalThis.sessionStorage?.setItem(cle, valeur)
  } catch {
    // Idem : on perd seulement le retour à la dernière route.
  }
}

const derniereRouteAdmin = ref<string | null>(lire(CLE_ADMIN))
const derniereRouteMembre = ref<string | null>(lire(CLE_MEMBRE))

/**
 * Appelé par le routeur après chaque navigation. Une route admin est retenue
 * comme dernière route admin ; une route membre **qui allume une section**
 * (Accueil, Arbre, Explorer, Moi) comme dernière route membre. Les impasses
 * (page introuvable, accès refusé : section `autre`) et les écrans sans cadre
 * ne sont pas des endroits où revenir.
 */
export function memoriserRoute(section: Section, cheminComplet: string, avecCadre: boolean): void {
  if (!avecCadre || section === 'autre') return
  if (section === 'administration') {
    derniereRouteAdmin.value = cheminComplet
    ecrire(CLE_ADMIN, cheminComplet)
  } else {
    derniereRouteMembre.value = cheminComplet
    ecrire(CLE_MEMBRE, cheminComplet)
  }
}

/* ───────────────── Le compte de ce qui attend ───────────────── */

export interface CompteursATraiter {
  demandes: number
  rattachements: number
  rgpd: number
  /** Les conversations dont le dernier mot est au membre (module `Messagerie`). */
  messages: number
}

/** `null` tant que rien n'a été compté : la pastille n'invente pas de zéro. */
const compteurs = ref<CompteursATraiter | null>(null)
let comptageEnCours: Promise<void> | null = null

/** L'écran « À traiter » publie ce qu'il vient de charger, ou de décider. */
export function publierCompteurs(c: CompteursATraiter): void {
  compteurs.value = { ...c }
}

/**
 * Compte ce qui attend : les demandes en attente (le total seul, une ligne), les
 * inscriptions à rattacher, les demandes RGPD sans décision, les messages des
 * membres qui attendent une réponse. Chaque lecture échoue seule : sous une vue
 * empruntée, les rattachements, le RGPD et les messages rendent
 * `409` — ils comptent alors pour zéro, comme l'écran qui ne les montre pas.
 * Un comptage en cours n'est pas relancé : l'en-tête et la barre le demandent
 * ensemble au montage.
 */
export function compterATraiter(): Promise<void> {
  if (comptageEnCours !== null) return comptageEnCours
  comptageEnCours = (async () => {
    const [demandes, rattachements, rgpd, fils] = await Promise.allSettled([
      listerDemandesAdministration({ status: 'en_attente', parPage: 1 }),
      listerRattachements(),
      listerDemandesRgpd(),
      listerFilsAdministration({ statut: 'ouvert' }),
    ])
    compteurs.value = {
      demandes: demandes.status === 'fulfilled' ? demandes.value.total : 0,
      rattachements: rattachements.status === 'fulfilled' ? rattachements.value.elements.length : 0,
      rgpd: rgpd.status === 'fulfilled' ? rgpd.value.elements.filter((d) => d.decision === null).length : 0,
      messages: fils.status === 'fulfilled' ? fils.value.elements.filter(attendUneReponse).length : 0,
    }
  })().finally(() => {
    comptageEnCours = null
  })
  return comptageEnCours
}

/** Remise à zéro entre deux tests, ou à la déconnexion. */
export function oublierCompteurs(): void {
  compteurs.value = null
}

/* ───────────────── Ce que lisent l'en-tête et la barre ───────────────── */

/** Les quatre entrées du mode admin, dans l'ordre de la navigation. */
export const ENTREES_ADMIN = [
  { nom: 'administration.a-traiter', libelle: 'À traiter' },
  { nom: 'administration.utilisateurs', libelle: 'Utilisateurs' },
  { nom: 'administration.invitations', libelle: 'Invitations' },
  { nom: 'administration.sources', libelle: 'Sources' },
] as const

/**
 * L'entrée de navigation allumée pour une route d'administration. L'historique
 * des demandes et toutes les conversations des membres sont des prolongements
 * d'« À traiter » : c'est elle qui s'allume.
 */
export function entreeAdminActive(nomDeRoute: string): string | null {
  if (nomDeRoute === 'administration.demandes' || nomDeRoute === 'administration.messagerie') return 'administration.a-traiter'
  return ENTREES_ADMIN.some((e) => e.nom === nomDeRoute) ? nomDeRoute : null
}

export interface ModeAdmin {
  /** La route courante est-elle une route d'administration ? */
  estAdmin: ComputedRef<boolean>
  /** Où mène l'interrupteur depuis le mode courant. */
  cibleDeBascule: ComputedRef<RouteLocationRaw>
  /** Le total qui attend une décision ; `null` tant qu'il n'est pas compté. */
  aTraiter: ComputedRef<number | null>
  compteurs: DeepReadonly<Ref<CompteursATraiter | null>>
}

export function useModeAdmin(): ModeAdmin {
  const { section } = useEntete()
  const estAdmin = computed(() => section.value === 'administration')
  return {
    estAdmin,
    cibleDeBascule: computed<RouteLocationRaw>(() =>
      estAdmin.value
        ? (derniereRouteMembre.value ?? { name: 'accueil' })
        : (derniereRouteAdmin.value ?? { name: 'administration.a-traiter' }),
    ),
    aTraiter: computed(() =>
      compteurs.value === null
        ? null
        : compteurs.value.demandes + compteurs.value.rattachements + compteurs.value.rgpd + compteurs.value.messages,
    ),
    compteurs: readonly(compteurs),
  }
}

/** Remise à zéro entre deux tests. */
export function reinitialiserModeAdmin(): void {
  derniereRouteAdmin.value = null
  derniereRouteMembre.value = null
  compteurs.value = null
}
