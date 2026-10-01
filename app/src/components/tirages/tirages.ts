/**
 * Ce que les écrans des tirages décident, sorti des composants pour se tester
 * sans navigateur. Suite : `tirages.test.ts`.
 */

import type { DetailDeTirage, LectureParLien, MonAttribution, PresenceAuTirage, ResumeDeTirage } from '@/lib/api'

const MOIS = [
  'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
] as const

/** Les options du sélecteur de mois : `{ valeur: 12, libelle: 'décembre' }`. */
export const OPTIONS_DE_MOIS = MOIS.map((libelle, i) => ({ valeur: i + 1, libelle }))

export const PRESENCES: { valeur: PresenceAuTirage; libelle: string }[] = [
  { valeur: 'sur_place', libelle: 'Sur place' },
  { valeur: 'a_distance', libelle: 'À distance' },
  { valeur: 'absente', libelle: 'Ne participe pas' },
]

/** « 24 décembre », « 1er mai ». */
export function libelleDuJour(jour: number, mois: number): string {
  const nomDuMois = MOIS[mois - 1] ?? ''
  return `${jour === 1 ? '1er' : String(jour)} ${nomDuMois}`.trim()
}

/**
 * « jeudi 24 décembre 2026 », depuis `AAAA-MM-JJ`.
 *
 * On découpe la chaîne plutôt que de la confier à `new Date()` : une date sans
 * heure y est lue en **UTC**, et affichée à l'ouest de Greenwich elle tombe la
 * veille. Un Noël annoncé le 23 serait le pire défaut de cet écran.
 */
export function dateLongue(iso: string): string {
  const [a, m, j] = iso.split('-').map(Number)
  if (a === undefined || m === undefined || j === undefined || Number.isNaN(a + m + j)) return iso

  const jourDeSemaine = new Date(a, m - 1, j).toLocaleDateString('fr-FR', { weekday: 'long' })
  return `${jourDeSemaine} ${libelleDuJour(j, m)} ${a}`
}

/** La règle de mémoire, en français courant. */
export function libelleDeMemoire(memoire: number): string {
  if (memoire <= 0) return 'On peut retomber sur la même personne d’une année sur l’autre'
  if (memoire === 1) return 'Jamais la même personne que l’année précédente'
  return `Jamais la même personne pendant ${memoire} tirages`
}

/**
 * L'avertissement du réglage de mémoire, ou `null`.
 *
 * Au-delà de la mémoire conseillée, certains participants n'ont plus personne à
 * tirer : le tirage oubliera alors les années les plus anciennes. Le dire au
 * moment du réglage évite de le découvrir au moment du tirage.
 */
export function avertissementDeMemoire(memoire: number, conseillee: number): string | null {
  if (memoire <= conseillee) return null

  return conseillee === 0
    ? 'Avec si peu de participants, cette règle ne pourra pas être respectée : le tirage l’ignorera.'
    : `Avec ce nombre de participants, au-delà de ${conseillee} tirage${conseillee > 1 ? 's' : ''} la règle ne pourra pas toujours être respectée : le tirage oubliera alors les années les plus anciennes.`
}

/** « Vous offrez un cadeau à… » ou, pour un participant accompagné, « Léa offre un cadeau à… ». */
export function phraseDAttribution(attribution: MonAttribution, monPersonId: number | null): string {
  return attribution.donneur.personId === monPersonId
    ? 'Vous offrez un cadeau à'
    : `${attribution.donneur.libelle} offre un cadeau à`
}

/** Ce que le donneur doit faire du cadeau, selon qu'il vient ou non. */
export function consigneDeRemise(presenceDuDonneur: PresenceAuTirage): string {
  return presenceDuDonneur === 'a_distance'
    ? 'Vous ne serez pas là : faites transmettre le cadeau pour qu’il soit au rendez-vous le jour J.'
    : 'À apporter le jour J.'
}

/**
 * L'année que l'organisateur ouvrira vraisemblablement : celle-ci si son jour
 * n'est pas passé et qu'elle n'existe pas encore, sinon la suivante libre.
 */
export function anneeProposee(tirage: Pick<DetailDeTirage, 'jour' | 'mois' | 'editions'>, aujourdhui: Date): number {
  const existantes = new Set(tirage.editions.map((e) => e.annee))
  const cetteAnnee = aujourdhui.getFullYear()
  const jourDeCetteAnnee = new Date(cetteAnnee, tirage.mois - 1, tirage.jour)
  const debutDuJour = new Date(aujourdhui.getFullYear(), aujourdhui.getMonth(), aujourdhui.getDate())

  let annee = jourDeCetteAnnee >= debutDuJour ? cetteAnnee : cetteAnnee + 1
  while (existantes.has(annee)) annee += 1

  return annee
}

/** Le personId du lecteur, s'il participe — lu dans la liste, pas deviné. */
export function monPersonId(tirage: Pick<DetailDeTirage, 'participants'>): number | null {
  return tirage.participants.find((p) => p.estMoi)?.personId ?? null
}

/**
 * La ligne d'état d'un tirage dans la liste.
 *
 * **Elle ne nomme jamais le receveur**, même au lecteur : la liste s'ouvre
 * volontiers devant d'autres, un soir de famille, et c'est l'écran du tirage
 * qui le dévoile — derrière un geste.
 */
export function etatDansLaListe(tirage: ResumeDeTirage): string {
  const edition = tirage.prochaineEdition
  if (edition === null) return 'Aucune édition ouverte'
  if (edition.statut === 'tiree') {
    return edition.mesAttributions.length > 0
      ? `${edition.annee} · tirage fait — à découvrir`
      : `${edition.annee} · tirage fait`
  }
  return `${edition.annee} · tirage à venir`
}

/* ───────────────── Les étapes de validation ───────────────── */

export interface Confirmation {
  titre: string
  message: string
  confirmer: string
  danger?: boolean
}

const LIBELLES_DE_PRESENCE: Record<PresenceAuTirage, string> = {
  sur_place: 'sur place',
  a_distance: 'à distance',
  absente: 'ne participe pas',
}

/** Changer une présence : dire ce que ça change pour le tirage. */
export function confirmationDePresence(
  libelle: string,
  estMoi: boolean,
  presence: PresenceAuTirage,
  annee: number,
): Confirmation {
  const qui = estMoi ? 'Vous' : libelle
  const titre = estMoi
    ? `Passer à « ${LIBELLES_DE_PRESENCE[presence]} » pour ${annee} ?`
    : `${libelle} : « ${LIBELLES_DE_PRESENCE[presence]} » pour ${annee} ?`

  const message =
    presence === 'absente'
      ? `${qui} ${estMoi ? 'ne serez pas' : 'ne sera pas'} dans le tirage ${annee} : ${estMoi ? 'vous n’offrirez' : 'n’offrira'} rien, et personne ne ${estMoi ? 'vous aura' : 'l’aura'}.`
      : presence === 'a_distance'
        ? `${qui} ${estMoi ? 'restez' : 'reste'} dans le tirage ${annee}, mais le cadeau devra être transmis pour être là le jour J.`
        : `${qui} ${estMoi ? 'serez' : 'sera'} sur place le jour J, avec le cadeau.`

  return { titre, message, confirmer: 'Confirmer', danger: presence === 'absente' }
}

/** Quitter ou retirer quelqu'un du tirage. */
export function confirmationDeRetrait(libelle: string, estMoi: boolean): Confirmation {
  return estMoi
    ? {
        titre: 'Quitter ce tirage ?',
        message: 'Vous ne serez plus dans les prochains tirages. Les années passées gardent leur historique.',
        confirmer: 'Quitter le tirage',
        danger: true,
      }
    : {
        titre: `Retirer ${libelle} ?`,
        message: `${libelle} ne sera plus dans les prochains tirages. Les années passées gardent leur historique.`,
        confirmer: 'Retirer',
        danger: true,
      }
}

export function confirmationDuTirage(annee: number, participants: number): Confirmation {
  return {
    titre: `Lancer le tirage ${annee} ?`,
    message: `${participants} participant${participants > 1 ? 's' : ''} ${participants > 1 ? 'seront prévenus' : 'sera prévenu'}. Vérifiez d’abord qui vient : changer d’avis ensuite demandera d’annuler et de refaire le tirage.`,
    confirmer: 'Lancer le tirage',
  }
}

export function confirmationDAnnulation(annee: number): Confirmation {
  return {
    titre: `Annuler le tirage ${annee} ?`,
    message: 'Toutes les attributions de cette année seront effacées. Il faudra relancer le tirage, et chacun découvrira une nouvelle personne.',
    confirmer: 'Annuler le tirage',
    danger: true,
  }
}

export function confirmationDeSuppression(nom: string): Confirmation {
  return {
    titre: `Supprimer « ${nom} » ?`,
    message: 'Le tirage, toutes ses années et leur historique seront définitivement perdus.',
    confirmer: 'Supprimer définitivement',
    danger: true,
  }
}

/* ───────────────── Le lien personnel ───────────────── */

/**
 * L'adresse complète à envoyer, depuis l'`url` relative rendue par l'API.
 *
 * Absolue sur l'origine de l'écran : c'est la même que celle de l'API (proxy
 * Vite en développement, nginx en production), et c'est celle qu'on colle dans
 * un message. Une URL déjà absolue est rendue telle quelle.
 */
export function adresseDuLien(url: string, origine: string): string {
  if (/^https?:\/\//.test(url)) return url
  return `${origine.replace(/\/+$/, '')}${url.startsWith('/') ? url : `/${url}`}`
}

/**
 * La ligne d'état d'un tirage vu par son lien : où en est l'année, dit à la
 * personne elle-même.
 */
export function etatVuParLien(lecture: LectureParLien): string {
  const e = lecture.edition
  if (e === null) return 'Aucune année n’est ouverte pour l’instant : l’organisateur l’ouvrira avant le jour J.'
  if (e.statut === 'tiree') {
    if (lecture.attributions.length > 0) return 'Le tirage est fait.'
    return lecture.participant.presence === 'absente'
      ? 'Le tirage est fait. Vous n’y participez pas cette année.'
      : 'Le tirage est fait, sans vous : l’organisateur doit le refaire pour vous y inclure.'
  }
  if (e.passee) return 'Cette année est passée.'
  return 'Le tirage n’a pas encore eu lieu. Dites si vous venez : ceux qui ne participent pas n’y entrent pas.'
}

/**
 * « Voir à qui je fais le cadeau », ou, pour un participant qu'on accompagne,
 * « Voir à qui Léa fait le cadeau ».
 *
 * On reconnaît sa propre attribution au `personId` que le lien rend pour le
 * participant — jamais au libellé : deux homonymes se confondraient.
 */
export function boutonDeCadeauParLien(attribution: MonAttribution, personIdDuParticipant: number): string {
  return attribution.donneur.personId === personIdDuParticipant
    ? 'Voir à qui je fais le cadeau'
    : `Voir à qui ${attribution.donneur.libelle} fait le cadeau`
}

/** Créer un lien alors qu'un autre est actif : l'ancien meurt, il faut le dire. */
export function confirmationDeRegeneration(libelle: string): Confirmation {
  return {
    titre: `Nouveau lien pour ${libelle} ?`,
    message: `Le lien déjà envoyé à ${libelle} cessera aussitôt de fonctionner. Vous devrez lui envoyer le nouveau.`,
    confirmer: 'Régénérer le lien',
  }
}

export function confirmationDeRevocation(libelle: string): Confirmation {
  return {
    titre: `Révoquer le lien de ${libelle} ?`,
    message: `${libelle} ne pourra plus suivre le tirage par ce lien. Vous pourrez en créer un nouveau plus tard.`,
    confirmer: 'Révoquer',
    danger: true,
  }
}
