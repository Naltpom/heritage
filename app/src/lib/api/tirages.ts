/**
 * Les appels d'API des tirages au sort récurrents — ADR 0009.
 *
 * « Noël côté papa, le 24 décembre » : un tirage porte des participants (des
 * personnes de l'arbre) et, chaque année, une **édition** qui dit qui offre à
 * qui.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le serveur ne rend jamais que ses propres attributions au lecteur.**
 *
 * `mesAttributions` contient celle du lecteur et celles des participants dont
 * il est le référent — rien d'autre, pas même pour l'organisateur. Le front
 * n'a donc rien à masquer : ce qu'il ne reçoit pas, il ne peut pas l'afficher
 * par erreur.
 * ────────────────────────────────────────────────────────────────────
 *
 * Chaque écriture rend le tirage **relu**, sérialisé pour le lecteur : l'écran
 * remplace son état par la réponse, sans recoller de morceaux.
 */

import { requeteApi } from './client'

export type PresenceAuTirage = 'sur_place' | 'a_distance' | 'absente'

export type StatutDEdition = 'ouverte' | 'tiree'

export interface PersonneDuTirage {
  personId: number
  libelle: string
}

export interface MonAttribution {
  donneur: PersonneDuTirage
  receveur: PersonneDuTirage
  /** La présence de celui qui offre : sur place, il apporte ; à distance, il fait transmettre. */
  presenceDuDonneur: PresenceAuTirage
}

export interface PresenceDUnParticipant extends PersonneDuTirage {
  presence: PresenceAuTirage
  presenceLibelle: string
}

export interface EditionDuTirage {
  annee: number
  /** `AAAA-MM-JJ`. */
  date: string
  passee: boolean
  statut: StatutDEdition
  statutLibelle: string
  /** Les années passées réellement respectées ; `null` avant le tirage. */
  memoireAppliquee: number | null
  tireeLe: string | null
  nombreDAttributions: number
  presences: PresenceDUnParticipant[]
  /** Ajoutés après le tirage : ils n'ont personne à qui offrir tant qu'il n'est pas refait. */
  arrivesApresLeTirage: number[]
  mesAttributions: MonAttribution[]
}

export interface ReglesDuTirage {
  nom: string
  jour: number
  mois: number
  lieu: string | null
  ouvert: boolean
  memoire: number
  exclureConjoints: boolean
}

export interface ResumeDeTirage extends ReglesDuTirage {
  id: number
  organisateur: { libelle: string; estMoi: boolean }
  peutGerer: boolean
  suisParticipant: boolean
  peutRejoindre: boolean
  nombreDeParticipants: number
  prochaineEdition: EditionDuTirage | null
}

export interface ParticipantDuTirage extends PersonneDuTirage {
  referent: PersonneDuTirage | null
  estMoi: boolean
  /** Le lecteur lit son attribution : c'est lui, ou il en est le référent. */
  suivi: boolean
  /**
   * Un lien personnel est actif pour ce participant (voir plus bas, « Le lien
   * personnel »). Un booléen, jamais la clé : le serveur ne la connaît plus.
   */
  lienActif: boolean
  /**
   * Le lecteur peut créer, régénérer ou révoquer ce lien : il est le référent
   * de ce participant, ou il organise (ou administre) et le participant n'a ni
   * compte ni référent. Créer le lien, c'est pouvoir lire l'attribution : le
   * secret d'un participant qui a son compte tient contre l'organisateur
   * (ADR 0009). Le serveur en décide.
   */
  peutPartagerLien: boolean
}

export interface DetailDeTirage extends ResumeDeTirage {
  /** Au-delà, le tirage oubliera des années à coup sûr. */
  memoireConseillee: number
  participantsMin: number
  participants: ParticipantDuTirage[]
  editions: EditionDuTirage[]
}

export interface ListeDeTirages {
  /** `null` : le compte n'est rattaché à aucune personne, il ne peut rien rejoindre. */
  moi: PersonneDuTirage | null
  tirages: ResumeDeTirage[]
}

export function listerTirages(signal?: AbortSignal): Promise<ListeDeTirages> {
  return requeteApi<ListeDeTirages>('/tirages', signal === undefined ? {} : { signal })
}

export function lireTirage(id: number, signal?: AbortSignal): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}`, signal === undefined ? {} : { signal })
}

export function creerTirage(regles: ReglesDuTirage, participants: number[]): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>('/tirages', { methode: 'POST', corps: { ...regles, participants } })
}

export function reglerTirage(id: number, regles: ReglesDuTirage): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}`, { methode: 'PUT', corps: regles })
}

export function supprimerTirage(id: number): Promise<void> {
  return requeteApi<void>(`/tirages/${id}`, { methode: 'DELETE' })
}

export function ajouterParticipant(
  id: number,
  personId: number,
  referentPersonId: number | null = null,
): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/participants`, {
    methode: 'POST',
    corps: { personId, referentPersonId },
  })
}

export function retirerParticipant(id: number, personId: number): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/participants/${personId}`, { methode: 'DELETE' })
}

export function rejoindreTirage(id: number): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/rejoindre`, { methode: 'POST' })
}

/** `date` omise : le jour habituel du tirage, cette année-là. */
export function ouvrirEdition(id: number, annee: number, date: string | null = null): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/editions`, {
    methode: 'POST',
    corps: date === null ? { annee } : { annee, date },
  })
}

export function definirPresence(
  id: number,
  annee: number,
  personId: number,
  presence: PresenceAuTirage,
): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/editions/${annee}/presences/${personId}`, {
    methode: 'PUT',
    corps: { presence },
  })
}

export function lancerLeTirage(id: number, annee: number): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/editions/${annee}/tirage`, { methode: 'POST' })
}

export function annulerLeTirage(id: number, annee: number): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/editions/${annee}/tirage`, { methode: 'DELETE' })
}

/* ------------------------------------------------------------------ */
/* Le lien personnel d'un participant                                  */
/* ------------------------------------------------------------------ */

/*
 * ────────────────────────────────────────────────────────────────────
 * **Un participant sans compte suit SON tirage par un lien : `/t/<clé>`.**
 *
 * Son référent — ou, s'il n'a ni compte ni référent, l'organisateur — le crée
 * depuis la page du tirage et le lui envoie. Le lien vaut pour toutes les éditions, jusqu'à ce
 * qu'on le révoque, qu'on en régénère un autre, que le participant soit retiré
 * ou le tirage supprimé.
 *
 * Deux propriétés, et l'écran est bâti sur elles :
 *
 * 1. **La clé n'existe en clair qu'au moment où on la crée.** Le serveur n'en
 *    garde qu'une empreinte, comme pour les jetons d'invitation : il ne peut pas
 *    la redonner. « Copier son lien » après coup, c'est donc **en fabriquer un
 *    nouveau** — et l'ancien cesse de marcher.
 * 2. **Les deux routes publiques ne disent rien d'autre que ce participant.**
 *    Ni la liste des participants, ni rien de l'arbre : son tirage, sa
 *    présence, et — une fois le tirage fait — son receveur. Une clé inconnue,
 *    révoquée ou remplacée rend la même `404` : l'écran ne cherche pas à les
 *    distinguer.
 * ────────────────────────────────────────────────────────────────────
 */

/** Ce qu'un lien personnel donne à lire. Rien de plus n'est rendu. */
export interface LectureParLien {
  tirage: { nom: string; lieu: string | null }
  /** L'édition courante ; `null` tant qu'aucune année n'est ouverte. */
  edition: { annee: number; date: string; statut: StatutDEdition; passee: boolean } | null
  participant: {
    /** Pour reconnaître SA ligne parmi les attributions, sans comparer des noms. */
    personId: number
    libelle: string
    /** `null` quand il n'y a pas d'édition où être présent. */
    presence: PresenceAuTirage | null
    peutChangerPresence: boolean
  }
  /** La sienne, et celle des participants dont il est le référent — comme `mesAttributions`. */
  attributions: MonAttribution[]
}

/** Rendu une seule fois, à la création : la clé ne se redemande pas. */
export interface LienCree {
  cle: string
  /** Relatif : `/t/<clé>`. L'écran le rend absolu sur sa propre origine. */
  url: string
}

/**
 * `GET /api/tirages/lien/{clé}` — route **publique**.
 *
 * `sansRattrapage` : sans session, une 401 y serait un défaut de câblage, pas
 * une session perdue. Le rattrapage global renverrait vers la connexion une
 * personne qui, précisément, n'a pas de compte.
 */
export function lireParLien(cle: string, signal?: AbortSignal): Promise<LectureParLien> {
  return requeteApi<LectureParLien>(`/tirages/lien/${encodeURIComponent(cle)}`, {
    sansRattrapage: true,
    ...(signal === undefined ? {} : { signal }),
  })
}

/** `PUT /api/tirages/lien/{clé}/presence` — publique, tant que l'édition est ouverte et à venir. */
export function definirPresenceParLien(cle: string, presence: PresenceAuTirage): Promise<LectureParLien> {
  return requeteApi<LectureParLien>(`/tirages/lien/${encodeURIComponent(cle)}/presence`, {
    methode: 'PUT',
    corps: { presence },
    sansRattrapage: true,
  })
}

/**
 * `POST /api/tirages/{id}/participants/{personId}/lien` — crée le lien, ou le
 * **régénère** : l'ancien, s'il y en avait un, cesse aussitôt de marcher.
 */
export function creerLienDuParticipant(id: number, personId: number): Promise<LienCree> {
  return requeteApi<LienCree>(`/tirages/${id}/participants/${personId}/lien`, { methode: 'POST' })
}

/** `DELETE` même chemin — le lien actif cesse de marcher, et il n'y en a plus. */
export function revoquerLienDuParticipant(id: number, personId: number): Promise<void> {
  return requeteApi<void>(`/tirages/${id}/participants/${personId}/lien`, { methode: 'DELETE' })
}
