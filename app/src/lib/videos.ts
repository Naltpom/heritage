/**
 * Les vidéos de présentation — le catalogue, et les quelques décisions qui
 * l'entourent, en fonctions pures.
 *
 * Ce sont des vidéos **verticales** (9:16, 1080 × 1920), en MP4 H.264 + AAC,
 * avec une voix off française et des sous-titres « karaoké » **incrustés dans
 * l'image**. Elles vivent dans `public/videos/`, servies telles quelles par
 * nginx (voir `app/nginx.conf`, bloc `/videos/`) — pas par le bundler : un MP4
 * de quelques mégaoctets n'a rien à faire dans le graphe de Vite, et ses
 * adresses doivent rester fixes pour qu'un lien `?video=<id>` se partage.
 *
 * Chaque vidéo `<id>` est faite de **trois fichiers**, et les trois portent
 * l'identifiant dans leur nom — c'est ce qui permet de les déduire au lieu de
 * les recopier (`cheminsDe`) :
 *
 *     /videos/<id>.mp4   la vidéo
 *     /videos/<id>.jpg   l'affiche (première image, ou une image choisie)
 *     /videos/<id>.vtt   les sous-titres WebVTT, en français
 *
 * **L'identifiant est un contrat**, comme un nom de route : la page Aide
 * l'accepte en `?video=<id>`, et un lien partagé dans un groupe de messagerie
 * doit encore ouvrir la bonne vidéo dans six mois. On ajoute, on ne renomme pas.
 *
 * Les durées sont **arrondies** : elles ne servent qu'à l'étiquette
 * « 0:35 » des cartes, et le lecteur suit la vraie durée du fichier pour sa
 * barre de progression. Les corriger ici, à l'œil, quand les montages bougent.
 */

/** Le dossier public des vidéos, tel que le navigateur le demande. */
export const DOSSIER_VIDEOS = '/videos/'

/**
 * Où la vidéo est rangée sur la page Aide.
 *
 * - `bien-commencer` : les deux présentations générales, en tête ;
 * - `fonctionnalite` : un chapitre par écran ou par geste.
 */
export type GroupeVideo = 'bien-commencer' | 'fonctionnalite'

/** L'écran dont parle un chapitre : la page Aide offre d'y aller. */
export interface EcranDeLaVideo {
  /** Un NOM de route (`router/routes.ts`), jamais une URL : les noms sont un contrat. */
  nom: string
  /** Le texte du lien, « Ouvrir l'arbre ». */
  libelle: string
}

export interface VideoAide {
  /** Identifiant stable : nom des fichiers, et valeur de `?video=`. */
  id: string
  titre: string
  /** Une phrase, affichée sous le titre de la carte. */
  description: string
  /** Durée approximative, en secondes — l'étiquette de la carte seulement. */
  dureeSecondes: number
  groupe: GroupeVideo
  /** Pour un chapitre : l'écran concerné, s'il y en a un. */
  ecran?: EcranDeLaVideo
}

/**
 * Pourquoi le lecteur s'est fermé avant la fin : la croix, la touche Échap, ou
 * un **échec de chargement** — fichier absent, réseau coupé. Ce dernier ferme
 * sans bruit, et ne compte pas comme « vue » (voir `marquerBienvenueVue`).
 */
export type MotifFermeture = 'croix' | 'echap' | 'echec'

/** Les trois fichiers d'une vidéo, déduits de son identifiant. */
export interface CheminsVideo {
  mp4: string
  affiche: string
  sousTitres: string
}

/**
 * Les durées, à part et en tête, pour qu'on les retouche sans relire le reste.
 * Approximations : voir l'en-tête du fichier.
 */
const DUREES: Record<string, number> = {
  bienvenue: 37,
  decouvrir: 28,
  'lire-l-arbre': 35,
  'filtres-de-l-arbre': 38,
  'proposer-une-correction': 33,
  'sources-et-actes': 30,
  'notifications-et-calendrier': 30,
  'carte-et-chronologie': 27,
}

/** La durée approximative d'une vidéo ; 30 s pour un identifiant oublié ci-dessus. */
function duree(id: string): number {
  return DUREES[id] ?? 30
}

/**
 * Le catalogue, **dans l'ordre d'affichage**. L'ordre compte : la page Aide le
 * suit à l'intérieur de chaque groupe, et « Bienvenue » ouvre la liste parce que
 * c'est celle qu'on montre à l'arrivée par le lien d'invitation.
 */
export const VIDEOS: readonly VideoAide[] = [
  {
    id: 'bienvenue',
    titre: 'Bienvenue dans Heritage',
    description: 'Se retrouver dans l’arbre, créer son accès, être validé par la famille, puis explorer.',
    dureeSecondes: duree('bienvenue'),
    groupe: 'bien-commencer',
  },
  {
    id: 'decouvrir',
    titre: 'Découvrir Heritage',
    description: 'Le tour de l’application en moins d’une minute.',
    dureeSecondes: duree('decouvrir'),
    groupe: 'bien-commencer',
  },
  {
    id: 'lire-l-arbre',
    titre: 'Lire l’arbre',
    description: 'Les couleurs des familles, le volet d’une fiche et la ligne directe.',
    dureeSecondes: duree('lire-l-arbre'),
    groupe: 'fonctionnalite',
    ecran: { nom: 'arbre', libelle: 'Ouvrir l’arbre' },
  },
  {
    id: 'filtres-de-l-arbre',
    titre: 'Les filtres de l’arbre',
    description: 'Familles par alliance, fratries par ordre de naissance et ligne directe.',
    dureeSecondes: duree('filtres-de-l-arbre'),
    groupe: 'fonctionnalite',
    ecran: { nom: 'arbre', libelle: 'Ouvrir l’arbre' },
  },
  {
    id: 'proposer-une-correction',
    titre: 'Proposer une correction',
    description: 'Corriger une date, ajouter un parent : la famille valide la demande.',
    dureeSecondes: duree('proposer-une-correction'),
    groupe: 'fonctionnalite',
    ecran: { nom: 'demandes.miennes', libelle: 'Voir mes demandes' },
  },
  {
    id: 'sources-et-actes',
    titre: 'Les sources et les actes',
    description: 'Ce que veut dire « vérifié par acte », et d’où viennent les dates.',
    dureeSecondes: duree('sources-et-actes'),
    groupe: 'fonctionnalite',
    ecran: { nom: 'demarches', libelle: 'Voir les actes demandés' },
  },
  {
    id: 'notifications-et-calendrier',
    titre: 'Notifications et calendrier',
    description: 'La cloche, et les anniversaires de la famille à ne pas manquer.',
    dureeSecondes: duree('notifications-et-calendrier'),
    groupe: 'fonctionnalite',
    ecran: { nom: 'anniversaires', libelle: 'Ouvrir le calendrier' },
  },
  {
    id: 'carte-et-chronologie',
    titre: 'Carte et chronologie',
    description: 'Où la famille a vécu, et ce qui s’est passé, siècle après siècle.',
    dureeSecondes: duree('carte-et-chronologie'),
    groupe: 'fonctionnalite',
    ecran: { nom: 'carte', libelle: 'Ouvrir la carte' },
  },
]

/** Les trois fichiers d'une vidéo. */
export function cheminsDe(video: Pick<VideoAide, 'id'>): CheminsVideo {
  const base = `${DOSSIER_VIDEOS}${video.id}`
  return { mp4: `${base}.mp4`, affiche: `${base}.jpg`, sousTitres: `${base}.vtt` }
}

/** Les vidéos d'un groupe, dans l'ordre du catalogue. */
export function videosDuGroupe(groupe: GroupeVideo): VideoAide[] {
  return VIDEOS.filter((v) => v.groupe === groupe)
}

/**
 * La vidéo désignée par un paramètre de requête (`?video=<id>`), ou `null`.
 *
 * Accepte ce que `vue-router` met dans `route.query` — une chaîne, un tableau
 * quand le paramètre est répété (`?video=a&video=b` : on prend le premier), ou
 * rien. Un identifiant inconnu rend `null` **sans erreur** : un lien partagé
 * vers une vidéo retirée doit ouvrir la page Aide, pas un écran d'erreur.
 */
export function videoDeLaRequete(valeur: unknown): VideoAide | null {
  const brut = Array.isArray(valeur) ? valeur[0] : valeur
  if (typeof brut !== 'string' || brut === '') return null
  return VIDEOS.find((v) => v.id === brut) ?? null
}

/** Une vidéo par son identifiant ; lève si elle n'existe pas (usage interne, au chargement). */
export function videoParId(id: string): VideoAide {
  const trouvee = VIDEOS.find((v) => v.id === id)
  if (trouvee === undefined) throw new Error(`Vidéo inconnue : ${id}`)
  return trouvee
}

/**
 * « 0:35 », « 1:05 » : la durée telle qu'un lecteur vidéo l'écrit. Arrondie à
 * la seconde ; jamais négative.
 */
export function formaterDuree(secondes: number): string {
  const total = Math.max(0, Math.round(Number.isFinite(secondes) ? secondes : 0))
  const minutes = Math.floor(total / 60)
  const reste = total % 60
  return `${minutes}:${String(reste).padStart(2, '0')}`
}

/** La durée dite en toutes lettres, pour un lecteur d'écran : « 35 secondes », « 1 minute 5 ». */
export function dureeEnToutesLettres(secondes: number): string {
  const total = Math.max(0, Math.round(Number.isFinite(secondes) ? secondes : 0))
  const minutes = Math.floor(total / 60)
  const reste = total % 60
  if (minutes === 0) return `${reste} seconde${reste > 1 ? 's' : ''}`
  const debut = `${minutes} minute${minutes > 1 ? 's' : ''}`
  return reste === 0 ? debut : `${debut} ${reste}`
}

/* ------------------------------------------------------------------ */
/* « Bienvenue », une seule fois par navigateur                        */
/* ------------------------------------------------------------------ */

/**
 * Clé `localStorage` : la vidéo « Bienvenue » a déjà été montrée d'elle-même
 * sur ce navigateur. Valeur `'1'`. Même forme que les autres clés du produit
 * (`heritage.<domaine>.<chose>`).
 *
 * **Une préférence de l'appareil, pas du compte** — et pour cause : on la
 * montre sur l'écran du lien d'invitation, où personne n'est encore connecté.
 * Elle ne dit rien de qui que ce soit : ni jeton, ni nom, seulement « déjà vue
 * ici » (la règle de `fluxInscription.ts` — rien de l'invitation ne se stocke —
 * est donc respectée).
 */
export const CLE_BIENVENUE_VUE = 'heritage.videos.bienvenue.vue'

/** Le strict nécessaire d'un `Storage`, pour que les tests en passent un faux. */
export type Stockage = Pick<Storage, 'getItem' | 'setItem'>

/**
 * Le `localStorage` du navigateur, ou `null`.
 *
 * **Le simple accès peut lever** — et pas seulement l'écriture : Safari en
 * navigation privée ancienne, Firefox avec les cookies bloqués, un iframe
 * sandboxé lèvent une `SecurityError` dès qu'on lit `window.localStorage`. Le
 * cas se traite ici, une fois, plutôt qu'à chaque appel.
 */
function stockageDuNavigateur(): Stockage | null {
  try {
    return globalThis.localStorage ?? null
  } catch {
    return null
  }
}

/**
 * La vidéo « Bienvenue » a-t-elle déjà été montrée d'elle-même ici ?
 *
 * Sans stockage lisible, on répond **oui** : un navigateur qui n'écrit rien la
 * rejouerait à chaque visite du lien, et une vidéo qui revient à chaque fois
 * qu'on rouvre l'invitation est une vidéo qu'on apprend à fermer sans la
 * regarder. Le lien « Revoir la vidéo » reste là pour qui la veut.
 */
export function bienvenueDejaVue(stockage: Stockage | null = stockageDuNavigateur()): boolean {
  if (stockage === null) return true
  try {
    return stockage.getItem(CLE_BIENVENUE_VUE) === '1'
  } catch {
    return true
  }
}

/** Retient que « Bienvenue » a été montrée. Silencieux si le stockage refuse. */
export function marquerBienvenueVue(stockage: Stockage | null = stockageDuNavigateur()): void {
  if (stockage === null) return
  try {
    stockage.setItem(CLE_BIENVENUE_VUE, '1')
  } catch {
    /* quota plein, navigation privée : la vidéo reviendra, ce n'est pas grave */
  }
}

/**
 * Le système demande-t-il moins d'animations ? Alors **aucune lecture
 * automatique** : le lecteur montre l'affiche et un bouton « Lire ». Une vidéo
 * qui démarre seule est exactement le mouvement non sollicité que ce réglage
 * existe pour éviter (WCAG 2.2.2).
 *
 * `false` hors navigateur (tests, rendu serveur).
 */
export function prefereMoinsDAnimations(): boolean {
  try {
    return globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
  } catch {
    return false
  }
}

/**
 * Comment la lecture a pu démarrer : avec le son, en muet, ou pas du tout (il
 * faut un geste — mode économie d'énergie, réglage du navigateur).
 */
export type Demarrage = 'son' | 'muet' | 'geste' | 'echec'

/** Ce que `demarrerLaLecture` touche d'un `<video>` : de quoi le simuler en test. */
export interface LecturePilotable {
  muted: boolean
  play(): Promise<void>
}

const refuse = (cause: unknown): boolean => cause instanceof Error && cause.name === 'NotAllowedError'

/**
 * Le démarrage : **d'abord avec le son**, en muet seulement si le navigateur refuse.
 *
 * Un navigateur laisse une vidéo parler d'emblée quand un geste vient de la
 * demander — le clic sur une carte de la page Aide, sur « ? Voir la vidéo » — ou
 * sur un site que la personne fréquente déjà. Il refuse à l'arrivée par un lien
 * d'invitation : premier passage, aucun geste. Alors seulement on retombe sur la
 * lecture muette (toujours permise) et le gros bouton « Activer le son ». Couper
 * d'office aurait privé de la voix ceux qui pouvaient l'entendre.
 *
 * Un refus qui n'est pas une question de permission (`AbortError` d'un
 * démontage, fichier illisible) rend `echec` : l'élément dira lui-même son
 * erreur par l'événement `error`, que le lecteur écoute déjà.
 */
export async function demarrerLaLecture(video: LecturePilotable): Promise<Demarrage> {
  video.muted = false
  try {
    await video.play()
    return 'son'
  } catch (cause) {
    if (!refuse(cause)) return 'echec'
  }
  video.muted = true
  try {
    await video.play()
    return 'muet'
  } catch (cause) {
    return refuse(cause) ? 'geste' : 'echec'
  }
}
