/**
 * Le format des fiches de l'arbre — « Compact » ou « Portrait » — et la
 * fenêtre qui le propose, sur téléphone, à la première arrivée sur l'arbre.
 *
 * **La préférence.** Une valeur par appareil, en `localStorage` : c'est une
 * affaire d'écran (un portrait en grand se lit mieux sur un téléphone tenu
 * droit), pas de compte. La ref est de portée **module** : la vue de l'arbre,
 * le réglage des filtres et la fenêtre de choix lisent et écrivent la même,
 * sans plomberie. Le stockage peut manquer ou lever (navigation privée de
 * Safari, données de site bloquées) : on retombe alors sur « Compact », et la
 * page marche — le choix vaut pour le chargement, pas au-delà.
 *
 * **La fenêtre.** Elle ne s'ouvre que :
 *
 * - sur **téléphone** — sur bureau, il y a la place d'une fiche large et le
 *   réglage est sous la main dans le menu « Filtres » ;
 * - quand **aucun choix n'est enregistré** — ni par elle, ni par le réglage ;
 * - quand l'arbre est **dessiné** — choisir un affichage devant un écran vide
 *   ne veut rien dire ;
 * - quand **aucune autre fenêtre automatique** n'est en route (clé d'accès,
 *   notifications : voir `fenetresAutomatiques.ts`). Elle passe en dernier,
 *   et ne réserve sa place qu'en s'ouvrant.
 *
 * Toute réponse est **enregistrée**, y compris « Garder l'actuel », Échap et
 * un toucher sur le voile : la question ne revient pas. Avec un stockage
 * bloqué, elle ne revient pas non plus dans le même chargement.
 *
 * Le type vient de `histoire/cartes.ts`, en `import type` seulement : ce
 * module ne tire rien du rendu de l'arbre à l'exécution.
 */
import { getCurrentScope, onScopeDispose, readonly, shallowRef, watch, type Ref } from 'vue'
import type { FormatDeCarte } from '@/histoire/cartes'
import { liberer, occuper, uneAutreFenetreQue } from './fenetresAutomatiques'

/** Clé `localStorage` du format choisi sur cet appareil. */
export const CLE_FORMAT_DES_CARTES = 'heritage.arbre.formatDesCartes'

/** Le format tant que rien n'est choisi : l'affichage d'avant, qui ne surprend
 *  personne. Écrit ici plutôt qu'importé : seul le type traverse. */
const PAR_DEFAUT: FormatDeCarte = 'compact'

/** Même délai que les notifications (`DELAI_INVITATION_MS`) : laisser l'arbre
 *  s'afficher avant de le couvrir. */
export const DELAI_INVITATION_FORMAT_MS = 1_500

function estUnFormat(v: unknown): v is FormatDeCarte {
  return v === 'compact' || v === 'portrait'
}

/** Le format enregistré, ou `null` : rien d'écrit, valeur inconnue (une
 *  version future, une main dans les outils du navigateur) ou stockage qui lève. */
export function lireFormatEnregistre(): FormatDeCarte | null {
  try {
    const brut = globalThis.localStorage?.getItem(CLE_FORMAT_DES_CARTES)
    return estUnFormat(brut) ? brut : null
  } catch {
    return null
  }
}

function ecrireFormat(f: FormatDeCarte): void {
  try {
    globalThis.localStorage?.setItem(CLE_FORMAT_DES_CARTES, f)
  } catch {
    // Stockage bloqué : le choix vaut pour ce chargement. La fenêtre ne
    // revient pas avant le prochain (`choixFaitCeChargement`).
  }
}

const format = shallowRef<FormatDeCarte>(lireFormatEnregistre() ?? PAR_DEFAUT)

/** Un choix a été fait — ou la fenêtre proposée — depuis le chargement.
 *  Réactif : un choix dans le réglage pendant l'attente de la fenêtre doit
 *  l'annuler sur-le-champ. */
const choixFaitCeChargement = shallowRef(false)

/** Le lecteur a-t-il déjà répondu, ici ou avant ? Le stockage est relu à chaque
 *  appel : un autre onglet a pu choisir entre-temps. */
export function formatDejaChoisi(): boolean {
  return choixFaitCeChargement.value || lireFormatEnregistre() !== null
}

/** Change le format, l'enregistre, et compte comme une réponse. */
export function choisirFormat(f: FormatDeCarte): void {
  format.value = f
  choixFaitCeChargement.value = true
  ecrireFormat(f)
}

export interface FormatDesCartes {
  format: Readonly<Ref<FormatDeCarte>>
  choisirFormat: (f: FormatDeCarte) => void
}

export function useFormatDesCartes(): FormatDesCartes {
  return { format: readonly(format), choisirFormat }
}

/** Remet l'état à zéro — un « chargement » neuf, pour les tests, qui partagent
 *  le module. Relit le stockage, que les tests remplacent. */
export function reinitialiserFormatDesCartes(): void {
  format.value = lireFormatEnregistre() ?? PAR_DEFAUT
  choixFaitCeChargement.value = false
  liberer('format-des-cartes')
}

/* ------------------------------------------------------------------ */
/* La fenêtre de choix                                                 */
/* ------------------------------------------------------------------ */

/** Tout ce dont dépend la décision — rien d'autre n'est lu. */
export interface ContexteInvitationFormat {
  /** Largeur de téléphone (`useEstMobile`). */
  mobile: boolean
  dejaChoisi: boolean
  /** L'arbre est dessiné. */
  pret: boolean
  /** Une autre fenêtre automatique est en route. */
  autreFenetre: boolean
}

/** Fonction pure : c'est elle que les tests passent au crible, cas par cas. */
export function doitProposerLeChoix(c: ContexteInvitationFormat): boolean {
  return c.mobile && !c.dejaChoisi && c.pret && !c.autreFenetre
}

export interface PilotageInvitationFormat {
  ouverte: Readonly<Ref<boolean>>
  /** Une option touchée : ce format, enregistré. */
  choisir: (f: FormatDeCarte) => void
  /** « Garder l'actuel », Échap, le voile : le format courant, enregistré. */
  garder: () => void
}

/**
 * Le pilote de la fenêtre, pour `InvitationFormatDesCartes`. Même mécanique
 * que `piloterInvitationPush()` : il observe les conditions ; quand elles sont
 * réunies, il attend `DELAI_INVITATION_FORMAT_MS`, **revérifie** et ouvre. Une
 * condition qui tombe pendant l'attente l'annule.
 *
 * Il ne prend sa place dans le registre des fenêtres automatiques **qu'au
 * moment d'ouvrir** — voir l'en-tête de `fenetresAutomatiques.ts`.
 *
 * À appeler dans un `setup` (ou un `effectScope`) : quitter l'arbre annule
 * l'attente et rend la place.
 */
export function piloterInvitationFormat(
  lirePret: () => boolean,
  lireMobile: () => boolean,
): PilotageInvitationFormat {
  const ouverte = shallowRef(false)
  let attente: ReturnType<typeof setTimeout> | null = null

  function calculer(): boolean {
    return doitProposerLeChoix({
      mobile: lireMobile(),
      dejaChoisi: formatDejaChoisi(),
      pret: lirePret(),
      // Toute autre que la sienne — qu'elle ne prend d'ailleurs qu'ouverte.
      autreFenetre: uneAutreFenetreQue('format-des-cartes'),
    })
  }

  function annulerAttente(): void {
    if (attente !== null) clearTimeout(attente)
    attente = null
  }

  const arreter = watch(
    calculer,
    (prevue) => {
      annulerAttente()
      if (!prevue || ouverte.value) return
      attente = setTimeout(() => {
        attente = null
        if (!calculer()) return
        // Proposée = répondue pour ce chargement, même si le stockage est
        // bloqué et que rien ne s'écrit.
        choixFaitCeChargement.value = true
        occuper('format-des-cartes')
        ouverte.value = true
      }, DELAI_INVITATION_FORMAT_MS)
    },
    { immediate: true },
  )

  function fermer(f: FormatDeCarte): void {
    if (!ouverte.value) return
    choisirFormat(f)
    ouverte.value = false
    liberer('format-des-cartes')
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      arreter()
      annulerAttente()
      if (ouverte.value) {
        ouverte.value = false
        liberer('format-des-cartes')
      }
    })
  }

  return {
    ouverte: readonly(ouverte),
    choisir: fermer,
    garder: () => fermer(format.value),
  }
}
