/**
 * Ce que les écrans des événements de famille décident, sorti des composants
 * pour se tester sans navigateur. Suite : `evenements.test.ts`.
 *
 * Le dossier s'appelle `evenements-familiaux` et non `evenements` : celui-là
 * porte les filtres de l'Almanach (naissances, mariages…), qui sont un autre
 * sens du mot.
 */

import type {
  AnnonceDEvenement,
  DetailDEvenement,
  LigneDeSuivi,
  ReponseAInvitation,
  ResumeDEvenement,
  SuiviDEvenement,
} from '@/lib/api'
import { dateRelative } from '@/lib/notifications'
import { ecartEnJours } from '@/components/accueil/jours'

const JOURS_COURTS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'] as const

const MOIS = [
  'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
] as const

/**
 * Une date `AAAA-MM-JJ`, **locale et à midi**.
 *
 * On découpe la chaîne plutôt que de la confier à `new Date()` : une date sans
 * heure y est lue en UTC, et affichée à l'ouest de Greenwich elle tombe la
 * veille. Une cousinade annoncée un jour trop tôt serait le pire défaut de
 * l'écran. `null` pour une chaîne qui n'est pas une date.
 */
export function lireDate(iso: string): Date | null {
  const morceaux = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)
  if (morceaux === null) return null
  const date = new Date(Number(morceaux[1]), Number(morceaux[2]) - 1, Number(morceaux[3]), 12)
  return Number.isNaN(date.getTime()) ? null : date
}

/** « sam. 17 », « mar. 1er ». */
function jour(date: Date): string {
  const quantieme = date.getDate()
  return `${JOURS_COURTS[date.getDay()]} ${quantieme === 1 ? '1er' : String(quantieme)}`
}

function mois(date: Date): string {
  return MOIS[date.getMonth()] ?? ''
}

/**
 * La période d'un événement, en une ligne — sans répéter ce qui est commun :
 *
 * - « le sam. 17 juillet 2027 » (un jour) ;
 * - « du sam. 17 au dim. 18 juillet 2027 » (même mois) ;
 * - « du ven. 30 juillet au dim. 1er août 2027 » (même année) ;
 * - « du jeu. 31 décembre 2026 au ven. 1er janvier 2027 ».
 *
 * Une date illisible rend la chaîne brute plutôt que « Invalid Date ».
 */
export function periode(debut: string, fin: string): string {
  const d = lireDate(debut)
  const f = lireDate(fin)
  if (d === null || f === null) return `${debut} – ${fin}`

  if (debut === fin) return `le ${jour(d)} ${mois(d)} ${d.getFullYear()}`
  if (d.getFullYear() !== f.getFullYear()) {
    return `du ${jour(d)} ${mois(d)} ${d.getFullYear()} au ${jour(f)} ${mois(f)} ${f.getFullYear()}`
  }
  if (d.getMonth() !== f.getMonth()) return `du ${jour(d)} ${mois(d)} au ${jour(f)} ${mois(f)} ${f.getFullYear()}`
  return `du ${jour(d)} au ${jour(f)} ${mois(f)} ${f.getFullYear()}`
}

/** La même, avec une majuscule : en tête de ligne. */
export function periodeEnTete(debut: string, fin: string): string {
  const texte = periode(debut, fin)
  return texte.charAt(0).toUpperCase() + texte.slice(1)
}

export type EtatDEvenement = 'a_venir' | 'en_cours' | 'passe' | 'annule'

/** Où en est l'événement aujourd'hui. L'annulation l'emporte sur tout le reste. */
export function etatDeLEvenement(e: Pick<ResumeDEvenement, 'debut' | 'fin' | 'annule'>, aujourdhui: Date): EtatDEvenement {
  if (e.annule) return 'annule'
  const debut = lireDate(e.debut)
  const fin = lireDate(e.fin)
  if (debut === null || fin === null) return 'a_venir'
  if (ecartEnJours(aujourdhui, fin) < 0) return 'passe'
  if (ecartEnJours(aujourdhui, debut) <= 0) return 'en_cours'
  return 'a_venir'
}

/** « aujourd'hui », « demain », « dans 12 jours », « en cours », « terminé », « annulé ». */
export function quand(e: Pick<ResumeDEvenement, 'debut' | 'fin' | 'annule'>, aujourdhui: Date): string {
  const etat = etatDeLEvenement(e, aujourdhui)
  if (etat === 'annule') return 'annulé'
  if (etat === 'passe') return 'terminé'
  if (etat === 'en_cours') return 'en cours'
  const debut = lireDate(e.debut)
  const ecart = debut === null ? 0 : ecartEnJours(aujourdhui, debut)
  if (ecart === 1) return 'demain'
  return `dans ${ecart} jours`
}

/**
 * Les deux listes de l'écran : **à venir** (en cours compris), la plus proche
 * d'abord ; **passés et annulés**, le plus récent d'abord. Un événement annulé
 * quitte les « à venir » : il n'y a plus rien à y préparer.
 */
export function repartir<T extends Pick<ResumeDEvenement, 'debut' | 'fin' | 'annule'>>(
  evenements: readonly T[],
  aujourdhui: Date,
): { aVenir: T[]; passes: T[] } {
  const aVenir: T[] = []
  const passes: T[] = []
  for (const e of evenements) {
    const etat = etatDeLEvenement(e, aujourdhui)
    if (etat === 'a_venir' || etat === 'en_cours') aVenir.push(e)
    else passes.push(e)
  }
  aVenir.sort((a, b) => a.debut.localeCompare(b.debut))
  passes.sort((a, b) => b.debut.localeCompare(a.debut))
  return { aVenir, passes }
}

export const REPONSES: { valeur: ReponseAInvitation; libelle: string }[] = [
  { valeur: 'present', libelle: 'Présent' },
  { valeur: 'absent', libelle: 'Absent' },
]

/**
 * Le bandeau de l'invité dont la réponse précède un changement de dates : elle
 * tient toujours, mais l'organisateur compte sur une confirmation.
 */
export const DATES_MODIFIEES = 'Les dates ont changé : confirmez votre réponse.'

/** « Réponse notée par Opale Tilleul. » — l'organisateur l'a saisie, de vive voix. */
export function phraseDeReponseNotee(par: string | null): string | null {
  return par === null ? null : `Réponse notée par ${par}.`
}

/** Ce que l'invité a répondu, dit à lui-même. */
export function phraseDeMaReponse(reponse: ReponseAInvitation | null): string {
  if (reponse === 'present') return 'Vous avez dit : présent.'
  if (reponse === 'absent') return 'Vous avez dit : absent.'
  return 'Vous n’avez pas encore répondu.'
}

/** « 37 invités », « 1 invité », « Aucun invité ». */
export function compteDInvites(n: number): string {
  if (n <= 0) return 'Aucun invité'
  return n === 1 ? '1 invité' : `${n} invités`
}

/** « 3 présents sur 12 invités », « 1 présent sur 1 invité ». */
export function bilan(presents: number, invites: number): string {
  return `${presents} présent${presents > 1 ? 's' : ''} sur ${invites} invité${invites > 1 ? 's' : ''}`
}

/** « 12 viennent », « 1 vient », « Personne n'a encore dit oui ». */
export function compteDePresents(n: number): string {
  if (n <= 0) return 'Personne n’a encore dit oui'
  return n === 1 ? '1 vient' : `${n} viennent`
}

/* ───────────────── Le suivi de l'organisateur ───────────────── */

export type GroupeDeSuivi = 'presents' | 'absents' | 'fantomes' | 'nonVus'

export const GROUPES_DU_SUIVI: { cle: GroupeDeSuivi; titre: string; aide: string }[] = [
  { cle: 'presents', titre: 'Présents', aide: 'Ils viennent.' },
  { cle: 'absents', titre: 'Absents', aide: 'Ils ne viendront pas.' },
  { cle: 'fantomes', titre: 'Vu, sans réponse 👻', aide: 'Ils ont ouvert l’annonce et n’ont rien répondu.' },
  { cle: 'nonVus', titre: 'Pas encore vu', aide: 'Ils n’ont pas encore ouvert l’annonce.' },
]

/**
 * Le détail d'une ligne du suivi, en relatif : « a répondu il y a 2 j »,
 * « vu il y a 3 h », « pas de compte · lien envoyé ». `maintenant` est un
 * paramètre — c'est ce qui rend la fonction testable sans horloge.
 */
export function detailDeLaLigne(ligne: LigneDeSuivi, groupe: GroupeDeSuivi, maintenant: Date): string {
  const morceaux: string[] = []

  if ((groupe === 'presents' || groupe === 'absents') && ligne.reponduLe !== null) {
    // Notée par l'organisateur : on le dit, discrètement — l'invité n'a rien ouvert.
    morceaux.push(
      ligne.noteePar === null
        ? `a répondu ${dateRelative(ligne.reponduLe, maintenant)}`
        : `noté par ${ligne.noteePar} ${dateRelative(ligne.reponduLe, maintenant)}`,
    )
    if (ligne.aConfirmer) morceaux.push('à confirmer (dates changées)')
  }
  if (groupe === 'fantomes' && ligne.vuLe !== null) morceaux.push(`vu ${dateRelative(ligne.vuLe, maintenant)}`)
  if (groupe === 'nonVus') morceaux.push(ligne.aUnCompte ? 'a un compte' : ligne.lienActif ? 'sans compte · lien créé' : 'sans compte · pas encore de lien')
  if (groupe === 'fantomes' && !ligne.aUnCompte) morceaux.push('par son lien')
  if ((groupe === 'fantomes' || groupe === 'nonVus') && ligne.relanceLe !== null) {
    morceaux.push(`relancé ${dateRelative(ligne.relanceLe, maintenant)}`)
  }

  return morceaux.join(' · ')
}

/**
 * Le libellé du bouton « Relancer », et s'il est actif.
 *
 * Trois cas, dans cet ordre : la relance d'hier n'a pas 24 h (on dit quand),
 * personne n'est à relancer par l'application, ou « Relancer 3 invités ».
 */
export function etatDeLaRelance(
  suivi: Pick<SuiviDEvenement, 'aRelancer' | 'prochaineRelancePossibleLe'>,
  maintenant: Date,
): { libelle: string; actif: boolean; aide: string } {
  if (suivi.prochaineRelancePossibleLe !== null) {
    const prochaine = new Date(suivi.prochaineRelancePossibleLe)
    const heures = Math.max(1, Math.ceil((prochaine.getTime() - maintenant.getTime()) / 3_600_000))
    return {
      libelle: 'Relancer',
      actif: false,
      aide: `Une relance est partie il y a moins d’un jour. Prochaine possible dans ${heures} h.`,
    }
  }
  if (suivi.aRelancer <= 0) {
    return { libelle: 'Relancer', actif: false, aide: 'Aucun invité avec un compte n’attend de réponse.' }
  }
  return {
    libelle: suivi.aRelancer === 1 ? 'Relancer 1 invité' : `Relancer ${suivi.aRelancer} invités`,
    actif: true,
    aide: 'Une notification à ceux qui ont un compte et n’ont pas répondu. Une relance par jour au plus.',
  }
}

/**
 * Le rappel pour ceux que l'application ne peut pas relancer : les invités sans
 * compte qui n'ont pas répondu. `null` quand il n'y en a pas.
 */
export function rappelDesLiens(suivi: Pick<SuiviDEvenement, 'fantomes' | 'nonVus'>): string | null {
  const n = [...suivi.fantomes, ...suivi.nonVus].filter((l) => !l.aUnCompte).length
  if (n === 0) return null
  return n === 1
    ? '1 invité sans compte n’a pas répondu : renvoyez-lui son lien vous-même.'
    : `${n} invités sans compte n’ont pas répondu : renvoyez-leur leur lien vous-même.`
}

/* ───────────────── L'annonce ───────────────── */

export function annonceVide(): AnnonceDEvenement {
  return { titre: '', debut: '', fin: '', lieu: null, description: null }
}

export function annonceDe(e: Pick<DetailDEvenement, keyof AnnonceDEvenement>): AnnonceDEvenement {
  return { titre: e.titre, debut: e.debut, fin: e.fin, lieu: e.lieu, description: e.description }
}

/**
 * Les erreurs que l'écran voit avant d'envoyer — les mêmes champs, les mêmes
 * mots que le serveur, qui reste juge. Une date de fin vide prend celle du
 * début : un événement d'un jour.
 */
export function verifierAnnonce(annonce: AnnonceDEvenement, aujourdhui: Date): Record<string, string> {
  const erreurs: Record<string, string> = {}
  if (annonce.titre.trim() === '') erreurs['titre'] = 'Donnez un titre à l’événement.'
  const debut = lireDate(annonce.debut)
  const fin = lireDate(annonce.fin === '' ? annonce.debut : annonce.fin)
  if (debut === null) erreurs['debut'] = 'Choisissez la date de début.'
  // Une fin vide suit le début : sans début, c'est lui qui manque, pas elle.
  if (fin === null && annonce.fin === '') return erreurs
  if (fin === null) erreurs['fin'] = 'Choisissez la date de fin.'
  else if (debut !== null && fin < debut) erreurs['fin'] = 'La fin ne peut pas précéder le début. Pour un seul jour, mettez la même date.'
  else if (ecartEnJours(aujourdhui, fin) < 0) erreurs['fin'] = 'Cette date est passée : on n’annonce qu’un événement à venir.'
  return erreurs
}

/** Ce qui part au serveur : blancs retirés, champs vides à `null`, fin vide = début. */
export function annonceAEnvoyer(annonce: AnnonceDEvenement): AnnonceDEvenement {
  const lieu = annonce.lieu?.trim() ?? ''
  const description = annonce.description?.trim() ?? ''
  return {
    titre: annonce.titre.trim(),
    debut: annonce.debut,
    fin: annonce.fin === '' ? annonce.debut : annonce.fin,
    lieu: lieu === '' ? null : lieu,
    description: description === '' ? null : description,
  }
}

/* ───────────────── Le lien personnel ───────────────── */

/**
 * Le message qui accompagne le lien partagé : ce qu'on enverrait par SMS soi-même.
 * Il nomme l'invité et l'événement, rien d'autre.
 */
export function messageDePartage(titre: string, libelle: string, debut: string, fin: string): string {
  return `${libelle}, vous êtes invité·e : ${titre}, ${periode(debut, fin)}. Dites si vous venez en ouvrant ce lien :`
}

/* ───────────────── Les étapes de validation ───────────────── */

export interface Confirmation {
  titre: string
  message: string
  confirmer: string
  danger?: boolean
}

export function confirmationDAnnulation(titre: string, aPrevenir: number): Confirmation {
  return {
    titre: `Annuler « ${titre} » ?`,
    message:
      aPrevenir > 0
        ? `Les invités qui ont vu l’annonce ou répondu seront prévenus. L’événement restera visible, marqué annulé.`
        : 'L’événement restera visible, marqué annulé. On ne peut pas le rétablir.',
    confirmer: 'Annuler l’événement',
    danger: true,
  }
}

export function confirmationDeRetrait(libelle: string): Confirmation {
  return {
    titre: `Retirer ${libelle} des invités ?`,
    message: `${libelle} ne verra plus l’événement, et son lien éventuel cessera de marcher. Aucune notification ne part : dites-le-lui vous-même si besoin.`,
    confirmer: 'Retirer',
    danger: true,
  }
}

export function confirmationDeRelance(nombre: number): Confirmation {
  return {
    titre: nombre === 1 ? 'Relancer 1 invité ?' : `Relancer ${nombre} invités ?`,
    message: 'Ceux qui ont un compte et n’ont pas répondu recevront un rappel. Vous ne pourrez pas relancer de nouveau avant demain.',
    confirmer: 'Relancer',
  }
}

/** Créer un lien alors qu'un autre est actif : l'ancien meurt, il faut le dire. */
export function confirmationDeRegeneration(libelle: string): Confirmation {
  return {
    titre: `Nouveau lien pour ${libelle} ?`,
    message: `Le lien déjà envoyé à ${libelle} ne marchera plus : il faudra lui envoyer le nouveau.`,
    confirmer: 'Créer un nouveau lien',
  }
}

export function confirmationDeRevocation(libelle: string): Confirmation {
  return {
    titre: `Révoquer le lien de ${libelle} ?`,
    message: `${libelle} ne pourra plus répondre par ce lien. Vous pourrez en créer un nouveau plus tard.`,
    confirmer: 'Révoquer',
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
