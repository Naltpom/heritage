/**
 * Les demandes RGPD (ADR 0016, révision du 3 octobre 2026) — ce qui se calcule
 * sans monter de composant : les libellés, l'état d'une demande en une ligne,
 * le contrôle du formulaire avant l'envoi et le corps envoyé.
 *
 * Partagé par « Mon compte » (le membre fait sa demande) et par l'écran
 * d'administration (la file des demandes, appliquer ou refuser).
 */
import {
  LONGUEUR_MAX_MESSAGE_RGPD,
  type CorpsDemandeRgpd,
  type DemandeRgpd,
  type NatureDemandeRgpd,
  type PartageNaissance,
} from '@/lib/api'
import { dateDuVerrou, resumePartage } from './partageNaissance'

/** Les natures, dans l'ordre du menu. */
export const NATURES_RGPD: readonly { nature: NatureDemandeRgpd; libelle: string }[] = [
  { nature: 'retrait_partage', libelle: 'Retirer une date de naissance partagée' },
  { nature: 'rectification', libelle: 'Rectifier une donnée' },
  { nature: 'autre', libelle: 'Autre demande' },
]

export function libelleNature(nature: string): string {
  return NATURES_RGPD.find((n) => n.nature === nature)?.libelle ?? 'Demande'
}

/** « jour et mois » — ce qu'un retrait demande de retirer. */
export function composantesARetirer(demande: Pick<DemandeRgpd, 'nature' | 'aRetirer'>): string | null {
  return demande.nature === 'retrait_partage' ? resumePartage(demande.aRetirer) : null
}

/** « En attente », « Appliquée le 3 octobre 2026 », « Refusée le … ». */
export function etatDeDemande(demande: Pick<DemandeRgpd, 'decision' | 'traiteeLe'>): string {
  if (demande.decision === null || demande.traiteeLe === null) return 'En attente'
  const le = dateDuVerrou(demande.traiteeLe)
  return demande.decision === 'appliquee' ? `Appliquée le ${le}` : `Refusée le ${le}`
}

/** L'état de la pastille (`PastilleEtat`), dont `etatDeDemande` donne le libellé. */
export function pastilleDeDemande(demande: Pick<DemandeRgpd, 'decision'>): 'en_attente' | 'validee' | 'refusee' {
  if (demande.decision === 'appliquee') return 'validee'
  if (demande.decision === 'refusee') return 'refusee'
  return 'en_attente'
}

/** Ce que le formulaire tient avant l'envoi. */
export interface BrouillonDemandeRgpd {
  nature: NatureDemandeRgpd
  aRetirer: PartageNaissance
  message: string
}

export function brouillonVide(aRetirer?: Partial<PartageNaissance>): BrouillonDemandeRgpd {
  return {
    nature: 'retrait_partage',
    aRetirer: { annee: false, mois: false, jour: false, ...aRetirer },
    message: '',
  }
}

export type ErreursDemandeRgpd = Partial<Record<'nature' | 'composantes' | 'message', string>>

/**
 * Les mêmes refus que le serveur (`422` sur `nature`, `composantes`,
 * `message`), dits avant l'envoi : un retrait nomme au moins une composante ;
 * les autres demandes se décrivent ; 4 000 caractères au plus.
 */
export function controlerDemande(brouillon: BrouillonDemandeRgpd): ErreursDemandeRgpd {
  const erreurs: ErreursDemandeRgpd = {}
  if (!NATURES_RGPD.some((n) => n.nature === brouillon.nature)) {
    erreurs.nature = 'Choisissez le type de demande.'
  }
  const message = brouillon.message.trim()
  if (brouillon.nature === 'retrait_partage') {
    const { annee, mois, jour } = brouillon.aRetirer
    if (!annee && !mois && !jour) erreurs.composantes = 'Cochez au moins ce qu’il faut retirer.'
  } else if (message === '') {
    erreurs.message = 'Décrivez votre demande.'
  }
  if (message.length > LONGUEUR_MAX_MESSAGE_RGPD) {
    erreurs.message = `${LONGUEUR_MAX_MESSAGE_RGPD} caractères au maximum.`
  }
  return erreurs
}

/** Le corps envoyé : les composantes seulement pour un retrait, le message s'il y en a un. */
export function corpsDeDemande(brouillon: BrouillonDemandeRgpd): CorpsDemandeRgpd {
  const message = brouillon.message.trim()
  return {
    nature: brouillon.nature,
    ...(brouillon.nature === 'retrait_partage'
      ? { annee: brouillon.aRetirer.annee, mois: brouillon.aRetirer.mois, jour: brouillon.aRetirer.jour }
      : {}),
    ...(message !== '' ? { message } : {}),
  }
}
