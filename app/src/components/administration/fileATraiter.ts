/**
 * « À traiter » — la file unique du mode administration (handoff « mode
 * Administration », § 4.1), en fonctions pures.
 *
 * Trois sources, une file : les **demandes** en attente, les inscriptions **à
 * rattacher**, les demandes **RGPD** sans décision. Chaque élément a une clé
 * stable, qui voyage dans l'adresse (`?sel=d:42`) : un lien profond ouvre
 * l'élément, le bouton retour du téléphone referme le détail.
 *
 * Les éléments sont rangés **du plus ancien au plus récent** : c'est celui qui
 * attend depuis le plus longtemps qui passe en premier, quelle que soit sa
 * nature.
 */
import type { CompteARattacher, Demande, DemandeRgpdAdministration } from '@/lib/api'
import { libelleNature } from '@/components/compte/demandesRgpd'
import { LIBELLES_NATURE } from '@/components/proposition/mes-demandes'

export type TypeATraiter = 'demande' | 'rattachement' | 'rgpd'
/** Le filtre des puces : un type, ou tout. */
export type FiltreATraiter = 'tout' | TypeATraiter

export type ElementATraiter =
  | { cle: string; type: 'demande'; date: string; demande: Demande }
  | { cle: string; type: 'rattachement'; date: string; compte: CompteARattacher }
  | { cle: string; type: 'rgpd'; date: string; rgpd: DemandeRgpdAdministration }

export const PUCES: readonly { valeur: FiltreATraiter; libelle: string }[] = [
  { valeur: 'tout', libelle: 'Tout' },
  { valeur: 'demande', libelle: 'Demandes' },
  { valeur: 'rattachement', libelle: 'À rattacher' },
  { valeur: 'rgpd', libelle: 'RGPD' },
]

/** La pastille de chaque type : son libellé et le ton de `PastilleEtat`. */
export const PASTILLES_DE_TYPE: Readonly<
  Record<TypeATraiter, { libelle: string; ton: 'en_attente' | 'validee' | 'neutre' }>
> = {
  demande: { libelle: 'Demande', ton: 'en_attente' },
  rattachement: { libelle: 'À rattacher', ton: 'validee' },
  rgpd: { libelle: 'RGPD', ton: 'neutre' },
}

const PREFIXES: Readonly<Record<TypeATraiter, string>> = { demande: 'd', rattachement: 'r', rgpd: 'g' }

export function cleDe(type: TypeATraiter, id: number): string {
  return `${PREFIXES[type]}:${id}`
}

function premiere(valeur: unknown): unknown {
  return Array.isArray(valeur) ? valeur[0] : valeur
}

/** `?type=` : une valeur inconnue vaut « tout », plutôt qu'une file vide sans raison. */
export function filtreDepuisRequete(valeur: unknown): FiltreATraiter {
  const brut = premiere(valeur)
  return brut === 'demande' || brut === 'rattachement' || brut === 'rgpd' ? brut : 'tout'
}

/** `?sel=` : la clé d'un élément, ou `null`. */
export function selectionDepuisRequete(valeur: unknown): string | null {
  const brut = premiere(valeur)
  return typeof brut === 'string' && /^[drg]:\d+$/.test(brut) ? brut : null
}

/** Toute la file, rangée du plus ancien au plus récent. */
export function construireFile(
  demandes: readonly Demande[],
  comptes: readonly CompteARattacher[],
  rgpd: readonly DemandeRgpdAdministration[],
): ElementATraiter[] {
  const elements: ElementATraiter[] = [
    ...demandes
      .filter((d) => d.status === 'en_attente')
      .map((demande) => ({
        cle: cleDe('demande', demande.id),
        type: 'demande' as const,
        date: demande.submittedAt ?? demande.createdAt,
        demande,
      })),
    ...comptes.map((compte) => ({
      cle: cleDe('rattachement', compte.memberId),
      type: 'rattachement' as const,
      date: compte.inscritLe,
      compte,
    })),
    ...rgpd
      .filter((g) => g.decision === null)
      .map((g) => ({ cle: cleDe('rgpd', g.id), type: 'rgpd' as const, date: g.creeeLe, rgpd: g })),
  ]
  return elements.sort((a, b) => horodatage(a.date) - horodatage(b.date) || a.cle.localeCompare(b.cle))
}

function horodatage(iso: string): number {
  const t = new Date(iso).getTime()
  return Number.isNaN(t) ? 0 : t
}

export function filtrer(file: readonly ElementATraiter[], filtre: FiltreATraiter): ElementATraiter[] {
  return filtre === 'tout' ? [...file] : file.filter((e) => e.type === filtre)
}

export function compter(file: readonly ElementATraiter[]): Record<FiltreATraiter, number> {
  const n = (type: TypeATraiter): number => file.filter((e) => e.type === type).length
  return { tout: file.length, demande: n('demande'), rattachement: n('rattachement'), rgpd: n('rgpd') }
}

/** Le nom déclaré d'une inscription : « Léa Serpolet ». */
export function nomDeclare(compte: CompteARattacher): string {
  const nom = [compte.declaration.givenNames, compte.declaration.surname].filter((p) => p.trim() !== '').join(' ')
  return nom === '' ? compte.email : nom
}

/** Les trois lignes d'un élément de liste : le titre, le sous-titre. */
export function titreDe(element: ElementATraiter): string {
  switch (element.type) {
    case 'demande':
      return `${LIBELLES_NATURE[element.demande.kind] ?? element.demande.kind} · n° ${element.demande.id}`
    case 'rattachement':
      return nomDeclare(element.compte)
    case 'rgpd':
      return libelleNature(element.rgpd.nature)
  }
}

export function sousTitreDe(element: ElementATraiter): string {
  switch (element.type) {
    case 'demande': {
      const cible = element.demande.targetLabel
      const nouvelles = element.demande.nouvellesPersonnes ?? []
      if (cible !== null && cible !== '') return cible
      return nouvelles.length > 0 ? nouvelles.join(', ') : element.demande.authorLabel
    }
    case 'rattachement':
      return element.compte.email
    case 'rgpd':
      return element.rgpd.userEmail
  }
}

/** « 3 oct. » */
export function dateCourte(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? iso : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
}

/**
 * L'élément à ouvrir quand `cle` vient de quitter la file : celui qui le
 * suivait dans la liste affichée, sinon celui d'avant, sinon aucun. `avant` est
 * la liste **telle qu'elle était affichée** avant la décision.
 */
export function suivantApres(avant: readonly ElementATraiter[], cle: string, apres: readonly ElementATraiter[]): string | null {
  const position = avant.findIndex((e) => e.cle === cle)
  const restants = new Set(apres.map((e) => e.cle))
  if (position >= 0) {
    for (const e of avant.slice(position + 1)) if (restants.has(e.cle)) return e.cle
    for (const e of avant.slice(0, position).reverse()) if (restants.has(e.cle)) return e.cle
  }
  return apres[0]?.cle ?? null
}

/** « 8 à traiter », pour le résumé de l'en-tête du téléphone. */
export function resumeATraiter(n: number): string {
  return n === 0 ? 'Rien à traiter' : `${n} à traiter`
}
