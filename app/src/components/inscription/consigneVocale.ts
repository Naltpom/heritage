/**
 * La **consigne parlée** de « Décrivez votre famille » — ce qui se joue, quand,
 * et une seule fois.
 *
 * Quand la recherche de l'étape 1b ne rend personne, une voix explique la
 * suite : on n'a pas trouvé de fiche exacte, ce n'est pas grave, racontez qui
 * vous êtes — par écrit ou à voix haute. Le fichier est statique
 * (`public/audio/aide-declaration.mp3`, servi par nginx, bloc `/audio/`), et
 * son texte exact est ci-dessous : il sert de transcription à l'écran et pour
 * les lecteurs d'écran. Le fichier se refabrique par `videos/audio/consigne.py`
 * (synthèse vocale) puis se recopie dans `app/public/audio/` : qui change
 * l'un change les trois — le script, le MP3 et `TEXTE_CONSIGNE`.
 *
 * Trois règles, que ce module rend testables sans navigateur :
 *
 * - **une fois par session d'onglet.** Pas à chaque rendu, pas à chaque
 *   nouvelle recherche vide : la personne qui corrige l'orthographe de son nom
 *   et relance n'a pas à réentendre l'explication. Le fait est retenu en
 *   `sessionStorage` — un booléen, rien d'autre : ni nom, ni date, rien qui
 *   parle d'une personne —, et chaque accès est sous `try/catch` (navigation
 *   privée, stockage bloqué : on rejoue plutôt que de casser) ;
 * - **un refus du navigateur n'est pas une erreur.** La lecture automatique
 *   avec le son peut être refusée (`NotAllowedError`) ; on montre alors le
 *   bouton « Écouter les explications », sans message ;
 * - **on ne parle pas par-dessus la personne** : la consigne s'arrête dès
 *   qu'elle tape ou qu'elle enregistre (le composant s'en charge).
 *
 * La consigne ne dit rien de la **cause** du résultat vide — plafond horaire,
 * date fausse, personne absente : c'est le même texte pour toutes, comme
 * l'écran qu'elle accompagne (§ 4.2, `ResultatCandidats.vue`).
 */

/** L'adresse du fichier, servi tel quel par nginx. */
export const URL_CONSIGNE = '/audio/aide-declaration.mp3'

/**
 * Le texte exact de l'enregistrement — transcription visible et alternative
 * pour les lecteurs d'écran. **À garder mot pour mot** avec le fichier : qui
 * lit doit lire ce qu'entend qui écoute.
 */
export const TEXTE_CONSIGNE =
  'Nous n’avons pas trouvé de fiche qui corresponde exactement à ce que vous avez saisi. ' +
  'Ce n’est pas grave : racontez-nous simplement qui vous êtes. ' +
  'Votre date de naissance, puis vos parents, avec leurs dates. ' +
  'Si vous avez des frères et sœurs, un conjoint ou des enfants, donnez aussi leurs dates. ' +
  'Vous pouvez l’écrire ci-dessous, ou appuyer sur le micro pour le dire à voix haute, comme vous préférez.'

/** La clé de session. Un booléen, et rien d'autre. */
export const CLE_CONSIGNE_JOUEE = 'heritage.inscription.consigne-jouee'

/** Ce qu'il faut d'un `sessionStorage` — un paramètre, pour les tests. */
export type StockageDeSession = Pick<Storage, 'getItem' | 'setItem'>

function stockageParDefaut(): StockageDeSession | null {
  try {
    return typeof sessionStorage === 'undefined' ? null : sessionStorage
  } catch {
    // Certains navigateurs lèvent dès l'accès à la propriété quand le stockage
    // est bloqué.
    return null
  }
}

/** La consigne a-t-elle déjà été entendue dans cet onglet ? */
export function consigneDejaJouee(stockage: StockageDeSession | null = stockageParDefaut()): boolean {
  if (stockage === null) return false
  try {
    return stockage.getItem(CLE_CONSIGNE_JOUEE) === '1'
  } catch {
    return false
  }
}

/** Retient qu'elle l'a été. Silencieux si le stockage refuse. */
export function retenirConsigneJouee(stockage: StockageDeSession | null = stockageParDefaut()): void {
  if (stockage === null) return
  try {
    stockage.setItem(CLE_CONSIGNE_JOUEE, '1')
  } catch {
    // Stockage plein ou interdit : au pire, la consigne se rejouera.
  }
}

/** La lecture automatique est-elle de mise ? Une fois, et seulement si demandée. */
export function doitJouerAutomatiquement(
  demandee: boolean,
  stockage: StockageDeSession | null = stockageParDefaut(),
): boolean {
  return demandee && !consigneDejaJouee(stockage)
}

/**
 * Un refus de lecture automatique — à taire — ou une vraie panne ?
 *
 * `NotAllowedError` : le navigateur exige un geste. `AbortError` : la lecture a
 * été interrompue (pause, démontage) avant de commencer. Ni l'un ni l'autre ne
 * mérite un message ; le bouton suffit.
 */
export function estUnRefusSilencieux(cause: unknown): boolean {
  const nom = typeof cause === 'object' && cause !== null && 'name' in cause ? String(cause.name) : ''
  return nom === 'NotAllowedError' || nom === 'AbortError'
}

/** La part écoulée, de 0 à 1 — pour la fine barre de progression. */
export function progression(courant: number, duree: number): number {
  if (!Number.isFinite(duree) || duree <= 0 || !Number.isFinite(courant)) return 0
  return Math.min(1, Math.max(0, courant / duree))
}

/* ------------------------------------------------------------------ */
/* L'élément audio, partagé — et « déverrouillé » au clic               */
/* ------------------------------------------------------------------ */

/**
 * **Un seul élément `<audio>` pour toute la page**, créé à la demande.
 *
 * La consigne se lance **après** la réponse de la recherche, donc après un
 * `await` : le geste de la personne (« Rechercher ma fiche ») est déjà loin.
 * Chrome et Firefox s'en contentent — ils retiennent qu'il y a eu une
 * interaction —, mais Safari sur iPhone veut que l'élément ait été lancé
 * **pendant** le geste. D'où {@see deverrouillerLaConsigne()}, appelée au clic,
 * qui lance l'élément en muet et l'arrête aussitôt : il est alors permis de le
 * relancer, avec le son, une fois la réponse arrivée.
 */
let element: HTMLAudioElement | null = null

export function elementDeConsigne(): HTMLAudioElement | null {
  if (typeof Audio === 'undefined') return null
  if (element === null) {
    element = new Audio(URL_CONSIGNE)
    element.preload = 'none'
  }
  return element
}

/**
 * À appeler **dans** le gestionnaire du clic qui lance la recherche, avant tout
 * `await`. Sans effet si la consigne a déjà été entendue : on ne charge pas un
 * fichier qu'on ne jouera pas.
 */
export function deverrouillerLaConsigne(): void {
  if (consigneDejaJouee()) return
  const audio = elementDeConsigne()
  if (audio === null) return
  audio.muted = true
  // `play()` rend une promesse (navigateurs actuels) ou rien (anciens) : les
  // deux cas sont tenus, et un refus ici ne change rien — on réessaiera.
  const essai = audio.play() as Promise<void> | undefined
  const remettre = (): void => {
    // Le lecteur a déjà pris la main (la réponse est arrivée avant la fin de ce
    // préchauffage, et il a rendu le son) : surtout ne pas l'interrompre.
    if (!audio.muted) return
    audio.pause()
    audio.currentTime = 0
    audio.muted = false
  }
  if (essai !== undefined && typeof essai.then === 'function') essai.then(remettre, remettre)
  else remettre()
}
