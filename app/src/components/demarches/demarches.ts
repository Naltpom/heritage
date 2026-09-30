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

export const STATUTS: ReadonlyArray<{ valeur: StatutDeDemarche; libelle: string }> = [
  { valeur: 'envoyee', libelle: 'Envoyée, en attente' },
  { valeur: 'relancee', libelle: 'Relancée, en attente' },
  { valeur: 'recue', libelle: 'Reçue' },
  { valeur: 'refusee', libelle: 'Refusée' },
  { valeur: 'sans_suite', libelle: 'Abandonnée' },
]

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
 * Au-delà, une démarche en attente est signalée « à relancer ». Un mois : c'est
 * le délai au-delà duquel une mairie qui n'a pas répondu a le plus souvent
 * perdu la demande, pas seulement pris du retard.
 */
export const JOURS_AVANT_RELANCE = 30

export function aRelancer(demarche: Pick<ResumeDeDemarche, 'enAttente' | 'joursDAttente'>): boolean {
  return demarche.enAttente && demarche.joursDAttente !== null && demarche.joursDAttente >= JOURS_AVANT_RELANCE
}

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
