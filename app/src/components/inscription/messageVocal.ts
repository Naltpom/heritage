/**
 * Le **message vocal** de « Décrivez votre famille » — la logique pure.
 *
 * À côté du champ de texte, qui reste et suffit à lui seul, un bouton micro
 * permet de **dire** sa famille. Le navigateur enregistre (`MediaRecorder`), la
 * personne réécoute, recommence ou supprime, et le fichier part avec la
 * déclaration, dans la même requête que le reste (`creerCompte()`, en
 * `multipart`). Ce module tient ce qui se décide sans micro — et se teste donc
 * sans navigateur :
 *
 * - **le format** : `audio/webm;codecs=opus` (Chrome, Edge, Firefox, Android),
 *   sinon `audio/mp4` (Safari, iPhone compris), sinon ce que le navigateur
 *   propose. Le serveur relit les octets de toute façon et n'accepte que WebM,
 *   Ogg et MP4 ;
 * - **les bornes** : cinq minutes (l'enregistrement s'arrête seul, et l'écran
 *   le dit), 10 Mo — à 32 kbit/s, cinq minutes en font à peine 1,2 ;
 * - **la disponibilité** : page non sécurisée, navigateur sans `MediaRecorder`
 *   ou sans micro — un message clair, et le texte reste possible ;
 * - **le chrono** : « 0:42 », « 4:59 ».
 *
 * **Ce qui est enregistré parle de vivants.** Le fichier ne vit qu'en mémoire,
 * dans l'onglet, jusqu'à l'envoi — ni `localStorage`, ni IndexedDB, ni URL —, et
 * l'URL `blob:` de réécoute est révoquée dès qu'elle ne sert plus.
 */

/** Cinq minutes, puis l'enregistrement s'arrête seul. */
export const DUREE_MAX_SECONDES = 5 * 60

/** Le plafond du serveur (`MessageVocalDeDeclaration::TAILLE_MAX`). */
export const TAILLE_MAX_OCTETS = 10 * 1024 * 1024

/** La voix n'en demande pas plus ; 10 Mo tiennent alors plus de quarante minutes. */
export const DEBIT_AUDIO = 32_000

/**
 * Les formats demandés à `MediaRecorder`, dans l'ordre. WebM/Opus d'abord :
 * c'est le format natif de Chromium et Firefox le produit aussi ; MP4 ensuite,
 * le seul que sache Safari ; Ogg en dernier recours.
 */
export const FORMATS_PREFERES = [
  'audio/webm;codecs=opus',
  'audio/webm',
  'audio/mp4',
  'audio/ogg;codecs=opus',
] as const

/**
 * Le premier format pris en charge, ou `''` — on laisse alors le navigateur
 * choisir (`new MediaRecorder(flux)` sans `mimeType`).
 *
 * `estPris` est `MediaRecorder.isTypeSupported`, passé en paramètre pour que les
 * tests n'aient pas à simuler un navigateur. Il peut lever sur un vieux moteur :
 * on le traite comme un refus.
 */
export function choisirTypeMime(estPris: (mime: string) => boolean): string {
  for (const mime of FORMATS_PREFERES) {
    try {
      if (estPris(mime)) return mime
    } catch {
      // Un moteur qui ne sait pas répondre ne sait pas non plus enregistrer ce format.
    }
  }
  return ''
}

/**
 * Le type du fichier produit, sans ses paramètres : `audio/webm;codecs=opus` →
 * `audio/webm`. Certains Chrome annoncent `video/mp4` pour un enregistrement
 * sans image : c'est de l'audio, et on le nomme ainsi.
 */
export function typeDuFichier(mimeProduit: string): string {
  const nu = mimeProduit.split(';')[0]?.trim().toLowerCase() ?? ''
  if (nu === '') return 'audio/webm'
  return nu.replace(/^video\//, 'audio/')
}

/** L'extension du nom envoyé — indicative : le serveur ne lit que les octets. */
export function extensionDuType(type: string): string {
  if (type.includes('mp4')) return 'm4a'
  if (type.includes('ogg')) return 'ogg'
  return 'webm'
}

/** « 0:07 », « 1:05 », « 5:00 ». */
export function formaterChrono(secondes: number): string {
  const s = Number.isFinite(secondes) ? Math.max(0, Math.floor(secondes)) : 0
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}

/** Ce que le navigateur permet — décidé au montage, jamais au rendu serveur. */
export type DisponibiliteMicro = 'disponible' | 'non_securise' | 'sans_enregistreur' | 'sans_micro'

export interface EnvironnementMicro {
  /** `window.isSecureContext` : le micro n'est offert qu'en HTTPS (ou `localhost`). */
  contexteSecurise: boolean
  /** `typeof MediaRecorder !== 'undefined'`. */
  aUnEnregistreur: boolean
  /** `!!navigator.mediaDevices?.getUserMedia`. */
  aUnMicro: boolean
}

export function disponibiliteMicro(env: EnvironnementMicro): DisponibiliteMicro {
  if (!env.contexteSecurise) return 'non_securise'
  if (!env.aUnMicro) return 'sans_micro'
  if (!env.aUnEnregistreur) return 'sans_enregistreur'
  return 'disponible'
}

/** Le message qui remplace le bouton quand le micro n'est pas offert. */
export function messageIndisponible(etat: Exclude<DisponibiliteMicro, 'disponible'>): string {
  switch (etat) {
    case 'non_securise':
      return 'L’enregistrement vocal demande une connexion sécurisée (https). Vous pouvez écrire votre réponse.'
    case 'sans_micro':
      return 'Aucun micro n’est accessible sur cet appareil. Vous pouvez écrire votre réponse.'
    case 'sans_enregistreur':
      return 'Ce navigateur ne sait pas enregistrer la voix. Vous pouvez écrire votre réponse.'
  }
}

/**
 * Le message d'un refus de `getUserMedia`. La personne a peut-être cliqué
 * « Bloquer » par réflexe : on dit comment revenir dessus, et que le texte
 * reste possible.
 */
export function messageDErreurMicro(cause: unknown): string {
  const nom = typeof cause === 'object' && cause !== null && 'name' in cause ? String(cause.name) : ''
  if (nom === 'NotAllowedError' || nom === 'SecurityError') {
    return 'Le micro a été refusé. Autorisez-le pour ce site dans les réglages du navigateur, ou écrivez votre réponse.'
  }
  if (nom === 'NotFoundError' || nom === 'OverconstrainedError') {
    return 'Aucun micro n’a été trouvé. Branchez-en un, ou écrivez votre réponse.'
  }
  if (nom === 'NotReadableError') {
    return 'Le micro est occupé par une autre application. Fermez-la, ou écrivez votre réponse.'
  }
  return 'Le micro n’a pas pu démarrer. Réessayez, ou écrivez votre réponse.'
}

/** Le refus d'un enregistrement fini, ou `null` s'il peut partir. */
export function erreurMessageVocal(taille: number, secondes: number): string | null {
  if (taille <= 0) return 'L’enregistrement est vide : recommencez.'
  if (taille > TAILLE_MAX_OCTETS) return 'L’enregistrement dépasse 10 Mo : recommencez plus court.'
  if (secondes > DUREE_MAX_SECONDES + 10) return 'L’enregistrement dépasse cinq minutes : recommencez plus court.'
  return null
}

/** « 1,2 Mo », « 340 ko » — pour l'écran de l'administrateur comme celui du candidat. */
export function tailleLisible(octets: number): string {
  if (octets < 1024 * 1024) return `${Math.max(1, Math.round(octets / 1024)).toLocaleString('fr-FR')} ko`
  return `${(octets / (1024 * 1024)).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} Mo`
}
