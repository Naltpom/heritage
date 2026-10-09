/**
 * « Installer Heritage sur votre téléphone », proposé **de lui-même**, à
 * l'arrivée, dans une fenêtre — celle d'avant était un bandeau sous l'en-tête,
 * qui débordait sur les petits écrans et qu'on écartait d'un « Plus tard »
 * définitif sans l'avoir lu.
 *
 * Même mécanique que l'invitation aux notifications (`piloterInvitationPush`) :
 * une fonction pure décide, un pilote attend un instant, revérifie et ouvre.
 *
 * Elle ne s'ouvre que sur **téléphone** (ou iPhone, iPad), à une session
 * ouverte — compte en attente compris —, hors incarnation, sur un écran avec
 * cadre, et seulement s'il y a quelque chose à installer :
 *
 * - **`android`** : le navigateur a proposé l'installation (`beforeinstallprompt`
 *   retenu par `installation.ts` — Chrome, Edge, Samsung Internet, sur Android
 *   surtout). « Installer » rejoue sa boîte, **dans le clic** ;
 * - **`ios`** : Safari sur iPhone ou iPad, qui n'a pas d'événement. On dit les
 *   deux gestes (Partager → Sur l'écran d'accueil), et un seul bouton,
 *   « Compris ». C'est d'ailleurs la seule façon d'y recevoir les
 *   notifications : l'invitation aux notifications ne le redit plus.
 *
 * « Plus tard », « Compris », Échap ou un refus dans la boîte du navigateur la
 * font taire **sept jours** sur cet appareil ; et une seule fois par
 * chargement, quoi qu'il arrive. L'ancien « Plus tard » du bandeau, retenu pour
 * toujours, est ignoré — et effacé : le bandeau mal affiché a pu être écarté
 * sans avoir été lu.
 *
 * **Sa place parmi les fenêtres automatiques** (`fenetresAutomatiques.ts`) :
 * après la clé d'accès, avant les notifications. Elle tient sa place dès que
 * son attente démarre et jusqu'à sa fermeture ; elle cède à la clé d'accès en
 * route, et aux autres fenêtres seulement **ouvertes** (`doitCeder`).
 */
import { getCurrentScope, onScopeDispose, readonly, shallowRef, watch, type Ref } from 'vue'
import { useSession } from '@/composables/useSession'
import { useInstallation } from '@/composables/installation'
import { doitCeder, liberer, marquerOuverte, occuper } from '@/composables/fenetresAutomatiques'

/** Ce que la fenêtre propose : rejouer la boîte du navigateur, ou dire les
 *  gestes de Safari. */
export type FormeInvitationInstallation = 'android' | 'ios'

/** Clé `localStorage` : quand le lecteur a répondu « Plus tard » (ms epoch). */
const CLE_PLUS_TARD = 'heritage.installation.invitation.plus-tard'

/** L'ancienne clé du bandeau (« 1 », pour toujours). Effacée au premier
 *  pilotage : elle ne dit plus rien. */
const ANCIENNE_CLE_BANDEAU = 'heritage.installation.plus-tard'

/** Silence après « Plus tard ». */
export const SILENCE_INVITATION_INSTALLATION_MS = 7 * 24 * 60 * 60 * 1000

/** Même délai que les autres fenêtres automatiques : laisser l'écran
 *  s'afficher avant de le couvrir. */
export const DELAI_INVITATION_INSTALLATION_MS = 1_500

/** Lu protégé : stockage bloqué (navigation privée de Safari) = jamais répondu. */
export function lirePlusTardInstallation(): number | null {
  try {
    const brut = globalThis.localStorage?.getItem(CLE_PLUS_TARD)
    if (brut == null) return null
    const date = Number(brut)
    return Number.isFinite(date) ? date : null
  } catch {
    return null
  }
}

export function ecrirePlusTardInstallation(date: number): void {
  try {
    globalThis.localStorage?.setItem(CLE_PLUS_TARD, String(date))
  } catch {
    // Stockage bloqué : la fenêtre reviendra au prochain chargement, et non
    // dans sept jours. « Une fois par chargement » reste tenu.
  }
}

function oublierLAncienBandeau(): void {
  try {
    globalThis.localStorage?.removeItem(ANCIENNE_CLE_BANDEAU)
  } catch {
    // Stockage bloqué : il n'y avait rien à effacer.
  }
}

/** Tout ce dont dépend la décision — rien d'autre n'est lu. */
export interface ContexteInvitationInstallation {
  /** Session ouverte, compte en attente compris. */
  connecte: boolean
  incarnation: boolean
  /** Écran avec en-tête : pas la connexion, l'inscription, ni `/t/…`. */
  avecCadre: boolean
  /** Largeur de téléphone (`useEstMobile`). */
  mobile: boolean
  /** Safari sur iPhone ou iPad — un iPad est large, il est invité quand même. */
  ios: boolean
  /** Ouverte depuis l'écran d'accueil (`display-mode: standalone`). */
  installee: boolean
  /** Le navigateur a proposé l'installation (`beforeinstallprompt` retenu). */
  installable: boolean
  /** Le dernier « Plus tard », ou `null`. */
  plusTardLe: number | null
  maintenant: number
  /** Une fenêtre automatique passe avant (`doitCeder('installation')`).
   *  Facultatif : absent vaut « non ». */
  doitCeder?: boolean
}

/**
 * La fenêtre doit-elle s'ouvrir, et sous quelle forme ? Fonction pure : c'est
 * elle que les tests passent au crible, cas par cas. Ne tient **pas** compte
 * du « une fois par chargement » — c'est l'affaire du pilote.
 */
export function formeInvitationInstallation(c: ContexteInvitationInstallation): FormeInvitationInstallation | null {
  if (!c.connecte || c.incarnation || !c.avecCadre || c.doitCeder === true) return null
  // Sur bureau, le panneau de « Moi » et de « Mon compte » suffit.
  if (!c.mobile && !c.ios) return null
  if (c.installee) return null
  if (c.plusTardLe !== null && c.maintenant - c.plusTardLe < SILENCE_INVITATION_INSTALLATION_MS) return null
  if (c.installable) return 'android'
  if (c.ios) return 'ios'
  return null
}

/** L'invitation a-t-elle déjà été montrée depuis le chargement de l'application ? */
let invitationProposee = false
/** L'ancienne clé du bandeau n'est effacée qu'une fois par chargement. */
let ancienBandeauOublie = false

/** Remet l'état à zéro — un « chargement » neuf, pour les tests, qui partagent
 *  le module. */
export function reinitialiserInvitationInstallation(): void {
  invitationProposee = false
  ancienBandeauOublie = false
  liberer('installation')
}

export interface PilotageInvitationInstallation {
  /** La forme ouverte, ou `null` quand la fenêtre est fermée. */
  forme: Readonly<Ref<FormeInvitationInstallation | null>>
  /** La boîte du navigateur est ouverte : on attend sa réponse. */
  enCours: Readonly<Ref<boolean>>
  /** « Installer » (forme `android`) — à appeler **dans** le clic. */
  installer: () => Promise<void>
  /** « Plus tard », « Compris » ou Échap : sept jours de silence. */
  plusTard: () => void
}

/**
 * Le pilote de la fenêtre, pour `InvitationInstallation`.
 *
 * Il observe les conditions ; quand elles sont réunies, il attend
 * `DELAI_INVITATION_INSTALLATION_MS`, **revérifie** (le lecteur a pu changer
 * d'écran, une autre fenêtre a pu s'ouvrir) et ouvre. Une condition qui tombe
 * pendant l'attente annule l'ouverture. La session qui tombe, une incarnation
 * qui commence ou une installation faite ailleurs (menu du navigateur) ferment
 * la fenêtre ouverte — sans valoir « Plus tard ».
 *
 * `lireMobile` vient du composant (`useEstMobile`, qui a besoin d'un montage),
 * comme pour le format des fiches. À appeler dans un `setup` (ou un
 * `effectScope`) : les minuteries tombent avec lui.
 *
 * Le « Plus tard » est relu à chaque calcul et non mis en cache : un autre
 * onglet a pu répondre entre-temps.
 */
export function piloterInvitationInstallation(
  lireAvecCadre: () => boolean,
  lireMobile: () => boolean,
  horloge: () => number = Date.now,
): PilotageInvitationInstallation {
  const forme = shallowRef<FormeInvitationInstallation | null>(null)
  const enCours = shallowRef(false)
  let attente: ReturnType<typeof setTimeout> | null = null
  const session = useSession()

  if (!ancienBandeauOublie) {
    ancienBandeauOublie = true
    oublierLAncienBandeau()
  }

  function calculer(): FormeInvitationInstallation | null {
    const { invitation, installee, ios } = useInstallation()
    return formeInvitationInstallation({
      connecte: session.connecte.value,
      incarnation: session.incarnation?.value != null,
      avecCadre: lireAvecCadre(),
      mobile: lireMobile(),
      ios,
      installee: installee.value,
      installable: invitation.value !== null,
      plusTardLe: lirePlusTardInstallation(),
      maintenant: horloge(),
      // Jamais sa propre inscription : elle n'y compte pas (voir le registre).
      doitCeder: doitCeder('installation'),
    })
  }

  function annulerAttente(): void {
    if (attente !== null) clearTimeout(attente)
    attente = null
  }

  /** La place est tenue tant qu'une attente court ou que la fenêtre est
   *  ouverte, et rendue dès que ni l'une ni l'autre. Appelée après chaque
   *  changement de l'une des deux. */
  function tenirLaPlace(): void {
    if (attente !== null || forme.value !== null) occuper('installation')
    else liberer('installation')
    marquerOuverte('installation', forme.value !== null)
  }

  function fermer(): void {
    forme.value = null
    tenirLaPlace()
  }

  const arreter = watch(
    calculer,
    (prevue) => {
      annulerAttente()
      if (prevue !== null && !invitationProposee && forme.value === null) {
        attente = setTimeout(() => {
          attente = null
          const confirmee = calculer()
          if (confirmee !== null && !invitationProposee) {
            invitationProposee = true
            forme.value = confirmee
          }
          tenirLaPlace()
        }, DELAI_INVITATION_INSTALLATION_MS)
      }
      tenirLaPlace()
    },
    { immediate: true },
  )

  const arreterFermeture = watch(
    () => !session.connecte.value || session.incarnation?.value != null || useInstallation().installee.value,
    (plusLieuDEtre) => {
      // Pendant la boîte du navigateur, c'est sa réponse qui ferme.
      if (plusLieuDEtre && forme.value !== null && !enCours.value) fermer()
    },
  )

  function plusTard(): void {
    if (forme.value === null || enCours.value) return
    ecrirePlusTardInstallation(horloge())
    fermer()
  }

  function installer(): Promise<void> {
    if (forme.value !== 'android' || enCours.value) return Promise.resolve()
    enCours.value = true
    // Synchrone jusqu'à `prompt()` : le geste du clic court encore.
    return useInstallation()
      .installer()
      .then((issue) => {
        enCours.value = false
        // Non dans la boîte du navigateur : une réponse, comme « Plus tard ».
        // Accepté : `appinstalled` suivra, et `installee` passera à vrai.
        // Impossible : rien à retenir — le prochain chargement réessaiera.
        if (issue === 'refusee') ecrirePlusTardInstallation(horloge())
        fermer()
      })
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      arreter()
      arreterFermeture()
      annulerAttente()
      liberer('installation')
    })
  }

  return {
    forme: readonly(forme),
    enCours: readonly(enCours),
    installer,
    plusTard,
  }
}
