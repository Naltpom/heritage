/**
 * Les actes demandés — ce qui se décide sans monter un composant.
 *
 * Les libellés des valeurs viennent du serveur sur chaque démarche
 * (`statutLibelle`, `canalLibelle`…). Ceux d'ici servent aux **listes de choix**
 * des formulaires, où aucune démarche n'est encore là pour les porter.
 */
import type {
  CanalDeMessage,
  DescriptionDeDemarche,
  FormeDActe,
  MessageASaisir,
  NatureDActe,
  ResumeDeDemarche,
  SensDeMessage,
  StatutDeDemarche,
} from '@/lib/api'

export const NATURES: ReadonlyArray<{ valeur: NatureDActe; libelle: string }> = [
  { valeur: 'naissance', libelle: 'Acte de naissance' },
  { valeur: 'mariage', libelle: 'Acte de mariage' },
  { valeur: 'deces', libelle: 'Acte de décès' },
  { valeur: 'autre', libelle: 'Autre pièce' },
]

export const FORMES: ReadonlyArray<{ valeur: FormeDActe; libelle: string }> = [
  { valeur: 'copie_integrale', libelle: 'Copie intégrale' },
  { valeur: 'extrait_filiation', libelle: 'Extrait avec filiation' },
  { valeur: 'extrait_sans_filiation', libelle: 'Extrait sans filiation' },
  { valeur: 'autre', libelle: 'Autre forme' },
]

/**
 * Les statuts, dans l'ordre de la vie d'une démarche. `court` sert aux
 * pastilles de filtre, où la place manque sur un téléphone ; `libelle` aux
 * listes de choix, où l'on doit lire ce que le statut veut dire.
 */
export const STATUTS: ReadonlyArray<{ valeur: StatutDeDemarche; libelle: string; court: string }> = [
  { valeur: 'envoyee', libelle: 'Envoyée, en attente', court: 'Envoyée' },
  { valeur: 'relancee', libelle: 'Relancée, en attente', court: 'Relancée' },
  { valeur: 'expediee', libelle: 'Expédiée par courrier, en attente de réception', court: 'Expédiée par courrier' },
  { valeur: 'recue', libelle: 'Reçue', court: 'Reçue' },
  { valeur: 'refusee', libelle: 'Refusée', court: 'Refusée' },
  { valeur: 'sans_suite', libelle: 'Abandonnée', court: 'Abandonnée' },
]

/** Tous les statuts, dans l’ordre de {@link STATUTS}. */
export const TOUS_LES_STATUTS: readonly StatutDeDemarche[] = STATUTS.map((s) => s.valeur)

/**
 * Ce qui attend encore la pièce — la sélection par défaut de la page « Actes
 * demandés » : c'est la question qu'on vient y poser. `expediee` en est : la
 * mairie a répondu, mais rien n'est arrivé.
 */
export const STATUTS_EN_ATTENTE: readonly StatutDeDemarche[] = ['envoyee', 'relancee', 'expediee']

/** Une démarche refusée ou abandonnée se **refait autrement** — autre canal, autre service. */
export function peutEtreRefaite(statut: StatutDeDemarche): boolean {
  return statut === 'refusee' || statut === 'sans_suite'
}

/** Combien de démarches par statut — les compteurs des pastilles. */
export type ComptesDeDemarches = { total: number; parStatut: Record<StatutDeDemarche, number> }

export function compterParStatut(demarches: ReadonlyArray<Pick<ResumeDeDemarche, 'statut'>>): ComptesDeDemarches {
  const parStatut = Object.fromEntries(TOUS_LES_STATUTS.map((s) => [s, 0])) as Record<StatutDeDemarche, number>
  for (const d of demarches) parStatut[d.statut] += 1
  return { total: demarches.length, parStatut }
}

/** Le total d'une sélection : « En attente (5) » = envoyées + relancées + expédiées. */
export function compteDe(comptes: ComptesDeDemarches, statuts: readonly StatutDeDemarche[]): number {
  return statuts.reduce((n, s) => n + comptes.parStatut[s], 0)
}

/** Deux sélections identiques, à l'ordre près. */
export function memesStatuts(a: readonly StatutDeDemarche[], b: readonly StatutDeDemarche[]): boolean {
  return a.length === b.length && a.every((s) => b.includes(s))
}

/** Une sélection rangée dans l'ordre de {@link STATUTS}, sans doublon. */
export function rangerStatuts(statuts: readonly StatutDeDemarche[]): StatutDeDemarche[] {
  return TOUS_LES_STATUTS.filter((s) => statuts.includes(s))
}

/**
 * Le filtre dans l'adresse — `?statuts=` —, pour qu'un lien partagé montre la
 * même chose à qui l'ouvre :
 *
 * - **absent** : la sélection par défaut, ce qui attend ({@link STATUTS_EN_ATTENTE}) ;
 * - `toutes` : tous les statuts ;
 * - une liste séparée par des virgules : `?statuts=recue,refusee` ;
 * - **vide** (`?statuts=`) : aucun statut coché.
 *
 * Une valeur inconnue est ignorée ; une liste qui n'en contient aucune de
 * connue retombe sur le défaut, plutôt que d'afficher une page vide sans
 * raison lisible.
 */
export function lireStatuts(valeur: unknown): StatutDeDemarche[] {
  const brute = Array.isArray(valeur) ? valeur[0] : valeur
  if (typeof brute !== 'string') return [...STATUTS_EN_ATTENTE]
  if (brute === 'toutes') return [...TOUS_LES_STATUTS]
  if (brute.trim() === '') return []

  const connus = rangerStatuts(brute.split(',').map((s) => s.trim()) as StatutDeDemarche[])
  return connus.length === 0 ? [...STATUTS_EN_ATTENTE] : connus
}

/** L'inverse de {@link lireStatuts} : `undefined` quand c'est le défaut — l'adresse reste nue. */
export function ecrireStatuts(statuts: readonly StatutDeDemarche[]): string | undefined {
  if (memesStatuts(statuts, STATUTS_EN_ATTENTE)) return undefined
  if (memesStatuts(statuts, TOUS_LES_STATUTS)) return 'toutes'
  return rangerStatuts(statuts).join(',')
}

/** Coche ou décoche un statut. */
export function basculerStatut(statuts: readonly StatutDeDemarche[], statut: StatutDeDemarche): StatutDeDemarche[] {
  return statuts.includes(statut) ? statuts.filter((s) => s !== statut) : rangerStatuts([...statuts, statut])
}

export const SENS: ReadonlyArray<{ valeur: SensDeMessage; libelle: string }> = [
  { valeur: 'envoye', libelle: 'Envoyé au service' },
  { valeur: 'recu', libelle: 'Reçu du service' },
]

export const CANAUX: ReadonlyArray<{ valeur: CanalDeMessage; libelle: string }> = [
  { valeur: 'courriel', libelle: 'Courriel' },
  { valeur: 'service_public', libelle: 'Service-public.gouv.fr' },
  { valeur: 'courrier', libelle: 'Courrier postal' },
  { valeur: 'guichet', libelle: 'Au guichet' },
  { valeur: 'telephone', libelle: 'Téléphone' },
  { valeur: 'autre', libelle: 'Autre' },
]

/**
 * « À relancer » n'est **pas** calculé ici : le serveur le dit (`aRelancer`),
 * parce qu'il ne suffit pas de compter les jours — une démarche expédiée par
 * courrier attend le facteur, pas la mairie, et ne se relance pas.
 */

/** « en attente depuis aujourd'hui », « depuis 1 jour », « depuis 12 jours ». */
export function libelleDAttente(jours: number | null): string | null {
  if (jours === null) return null
  if (jours <= 0) return 'envoyée aujourd’hui'
  return `en attente depuis ${jours} jour${jours > 1 ? 's' : ''}`
}

const DATE_LONGUE = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })

/** `2026-09-30` → « 30 septembre 2026 ». Une valeur mal formée est rendue telle quelle. */
export function dateLisible(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)
  if (m === null) return iso
  return DATE_LONGUE.format(new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))))
}

/** Aujourd'hui en `AAAA-MM-JJ`, **dans le fuseau du lecteur** — pas en UTC, qui
 *  daterait de la veille un message saisi à 1 h du matin à Paris. */
export function aujourdhuiIso(maintenant: Date = new Date()): string {
  const a = maintenant.getFullYear()
  const m = String(maintenant.getMonth() + 1).padStart(2, '0')
  const j = String(maintenant.getDate()).padStart(2, '0')
  return `${a}-${m}-${j}`
}

export function messageVide(sens: SensDeMessage = 'envoye'): MessageASaisir {
  return { sens, canal: 'courriel', date: aujourdhuiIso(), de: null, a: null, objet: null, texte: '', reference: null }
}

/**
 * La description d'une démarche **refaite autrement** : même acte, mêmes
 * personnes, même service par défaut — le cas courant est la même mairie, par
 * un autre canal (« nous ne traitons pas les demandes par courriel »). Tout se
 * corrige avant d'enregistrer.
 */
export function descriptionPourRefaire(
  demarche: Pick<ResumeDeDemarche, 'nature' | 'forme' | 'intitule' | 'commune' | 'dateDeLActe' | 'destinataire' | 'personnes'>,
): DescriptionDeDemarche {
  return {
    nature: demarche.nature,
    forme: demarche.forme,
    intitule: demarche.intitule,
    commune: demarche.commune,
    dateDeLActe: demarche.dateDeLActe,
    destinataire: demarche.destinataire,
    personIds: demarche.personnes.map((p) => p.personId),
  }
}

export function descriptionVide(personIds: number[] = []): DescriptionDeDemarche {
  return {
    nature: 'naissance',
    forme: 'copie_integrale',
    intitule: '',
    commune: null,
    dateDeLActe: null,
    destinataire: '',
    personIds,
  }
}

/** Une chaîne vide de formulaire devient `null` : le serveur distingue « rien » de « vide ». */
export function nulSiVide(valeur: string | null): string | null {
  if (valeur === null) return null
  const nette = valeur.trim()
  return nette.length === 0 ? null : nette
}

/** Le message tel qu'il part au serveur : champs facultatifs vides → `null`. */
export function messageAEnvoyer(message: MessageASaisir): MessageASaisir {
  return {
    ...message,
    de: nulSiVide(message.de),
    a: nulSiVide(message.a),
    objet: nulSiVide(message.objet),
    reference: nulSiVide(message.reference),
  }
}

export function descriptionAEnvoyer(description: DescriptionDeDemarche): DescriptionDeDemarche {
  return {
    ...description,
    intitule: description.intitule.trim(),
    destinataire: description.destinataire.trim(),
    commune: nulSiVide(description.commune),
    dateDeLActe: nulSiVide(description.dateDeLActe),
  }
}

/**
 * Les erreurs de champ du serveur pour le **premier message** d'une création
 * arrivent préfixées (`premierMessage.date`) : on les rend au formulaire du
 * message sans le préfixe, pour qu'il n'ait qu'une seule forme à connaître.
 */
export function erreursDuMessage(champs: Record<string, string>, prefixe = 'premierMessage.'): Record<string, string> {
  const retenues: Record<string, string> = {}
  for (const [cle, message] of Object.entries(champs)) {
    if (cle.startsWith(prefixe)) retenues[cle.slice(prefixe.length)] = message
  }
  return retenues
}

/** Minuscules, sans accents ni espaces répétés : « Périgueux » se trouve en tapant « perigueux ». */
export function normaliser(texte: string): string {
  return texte
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim()
}

/**
 * La démarche répond-elle à la recherche ? **Chaque mot** doit se trouver
 * quelque part — intitulé, personnes, commune, date, service, adresses des
 * messages, statut —, dans n'importe quel ordre : « provost fleurance » trouve
 * le mariage de 1957.
 *
 * Le texte des messages n'y est pas : la liste ne le transporte pas, et
 * l'adresse de l'expéditeur suffit à retrouver la démarche d'une réponse.
 */
export function correspondARecherche(demarche: ResumeDeDemarche, recherche: string): boolean {
  const mots = normaliser(recherche).split(' ').filter((m) => m.length > 0)
  if (mots.length === 0) return true

  const botte = normaliser(
    [
      demarche.intitule,
      demarche.natureLibelle,
      demarche.formeLibelle,
      demarche.commune ?? '',
      demarche.dateDeLActe ?? '',
      demarche.destinataire,
      demarche.statutLibelle,
      ...demarche.personnes.map((p) => p.libelle),
      ...demarche.correspondants,
    ].join(' '),
  )

  return mots.every((mot) => botte.includes(mot))
}
