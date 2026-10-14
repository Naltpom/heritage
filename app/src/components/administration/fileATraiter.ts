/**
 * « À traiter » — la file unique du mode administration (handoff « mode
 * Administration », § 4.1), en fonctions pures.
 *
 * Trois sources, une file : les **demandes** en attente, les inscriptions **à
 * rattacher**, les demandes **RGPD** sans décision. Chaque élément a une clé
 * stable, qui voyage dans l'adresse (`?sel=d:42`) : un lien profond ouvre
 * l'élément, le bouton retour du téléphone referme le détail.
 *
 * Les éléments sont rangés **du plus ancien au plus récent** par défaut : c'est
 * celui qui attend depuis le plus longtemps qui passe en premier, quelle que
 * soit sa nature. Le tri « N° décroissant » renverse l'ordre.
 *
 * **Les demandes sont paginées par l'API** (2026-10-14) : il y en avait plus de
 * cent, et la file n'en lisait que cent. Elles arrivent page par page, déjà
 * triées par numéro et filtrées par auteur. Les inscriptions et le RGPD, rares
 * et servis sans pagination, se mêlent par date à la **première** page.
 */
import type { AuteurDeLaFile, CompteARattacher, Demande, DemandeRgpdAdministration } from '@/lib/api'
import { libelleNature } from '@/components/compte/demandesRgpd'
import { LIBELLES_NATURE } from '@/components/proposition/mes-demandes'
import type { SensDeTri } from './file'

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

/** `?tri=` : `recentes` (n° décroissant), sinon `anciennes` — le défaut. */
export function triDepuisRequete(valeur: unknown): SensDeTri {
  return premiere(valeur) === 'recentes' ? 'recentes' : 'anciennes'
}

/** `?auteur=` : un identifiant de compte, ou `0` — tout le monde. */
export function auteurDepuisRequete(valeur: unknown): number {
  const n = Number(premiere(valeur))
  return Number.isInteger(n) && n > 0 ? n : 0
}

/**
 * La file affichée : les demandes **dans l'ordre où l'API les rend** (par
 * numéro, dans le sens `sens`), et les inscriptions et le RGPD mêlés par date,
 * dans le même sens. Une fusion et non un tri : le numéro des demandes reste
 * leur ordre, même quand une demande déposée tard porte un petit numéro.
 *
 * Les demandes déjà décidées et le RGPD déjà tranché n'y entrent pas.
 */
export function construireFile(
  demandes: readonly Demande[],
  comptes: readonly CompteARattacher[],
  rgpd: readonly DemandeRgpdAdministration[],
  sens: SensDeTri = 'anciennes',
): ElementATraiter[] {
  const lesDemandes: ElementATraiter[] = demandes
    .filter((d) => d.status === 'en_attente')
    .map((demande) => ({
      cle: cleDe('demande', demande.id),
      type: 'demande' as const,
      date: demande.submittedAt ?? demande.createdAt,
      demande,
    }))
  const signe = sens === 'anciennes' ? 1 : -1
  const lesAutres: ElementATraiter[] = [
    ...comptes.map((compte) => ({
      cle: cleDe('rattachement', compte.memberId),
      type: 'rattachement' as const,
      date: compte.inscritLe,
      compte,
    })),
    ...rgpd
      .filter((g) => g.decision === null)
      .map((g) => ({ cle: cleDe('rgpd', g.id), type: 'rgpd' as const, date: g.creeeLe, rgpd: g })),
  ].sort((a, b) => signe * (horodatage(a.date) - horodatage(b.date)) || a.cle.localeCompare(b.cle))

  // La fusion : à date égale, la demande passe d'abord.
  const file: ElementATraiter[] = []
  let i = 0
  let j = 0
  for (;;) {
    const demande = lesDemandes[i]
    const autre = lesAutres[j]
    if (demande === undefined && autre === undefined) return file
    if (autre === undefined || (demande !== undefined && signe * (horodatage(demande.date) - horodatage(autre.date)) <= 0)) {
      file.push(demande as ElementATraiter)
      i++
    } else {
      file.push(autre)
      j++
    }
  }
}

/**
 * Le filtre « Demandé par » : qui a déposé une demande (la route des auteurs de
 * la file), qui a fait une demande RGPD en attente, qui s'est inscrit sans
 * fiche. Un compte n'y paraît qu'une fois, rangé par nom.
 */
export function auteursDeLaFile(
  deposants: readonly AuteurDeLaFile[],
  comptes: readonly CompteARattacher[],
  rgpd: readonly DemandeRgpdAdministration[],
): AuteurDeLaFile[] {
  const parCompte = new Map<number, string>()
  for (const a of deposants) parCompte.set(a.userId, a.libelle)
  for (const g of rgpd) if (g.decision === null && !parCompte.has(g.userId)) parCompte.set(g.userId, g.userEmail)
  for (const c of comptes) if (!parCompte.has(c.userId)) parCompte.set(c.userId, nomDeclare(c))
  return [...parCompte]
    .map(([userId, libelle]) => ({ userId, libelle }))
    .sort((a, b) => a.libelle.localeCompare(b.libelle, 'fr'))
}

/** Ce qu'un compte a demandé ; `0` — tout le monde — laisse tout passer. */
export function demandesDe<T extends { userId: number }>(elements: readonly T[], auteur: number): T[] {
  return auteur === 0 ? [...elements] : elements.filter((e) => e.userId === auteur)
}

function horodatage(iso: string): number {
  const t = new Date(iso).getTime()
  return Number.isNaN(t) ? 0 : t
}

export function filtrer(file: readonly ElementATraiter[], filtre: FiltreATraiter): ElementATraiter[] {
  return filtre === 'tout' ? [...file] : file.filter((e) => e.type === filtre)
}

/**
 * Les compteurs des puces. Les demandes se comptent **sur le total de l'API**,
 * pas sur la page affichée ; les inscriptions et le RGPD, lus en entier, sur
 * leur liste.
 */
export function compter(nombres: Readonly<Record<TypeATraiter, number>>): Record<FiltreATraiter, number> {
  return { tout: nombres.demande + nombres.rattachement + nombres.rgpd, ...nombres }
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
 * suivait dans la liste affichée ; sinon celui qui vient d'arriver en bas de la
 * page — la page relue après la décision remonte la première de la page
 * suivante ; sinon celui d'avant ; sinon aucun. `avant` est la liste **telle
 * qu'elle était affichée** avant la décision.
 */
export function suivantApres(avant: readonly ElementATraiter[], cle: string, apres: readonly ElementATraiter[]): string | null {
  const position = avant.findIndex((e) => e.cle === cle)
  const restants = new Set(apres.map((e) => e.cle))
  if (position >= 0) {
    for (const e of avant.slice(position + 1)) if (restants.has(e.cle)) return e.cle
    const connus = new Set(avant.map((e) => e.cle))
    const arrivee = apres.find((e) => !connus.has(e.cle))
    if (arrivee !== undefined) return arrivee.cle
    for (const e of avant.slice(0, position).reverse()) if (restants.has(e.cle)) return e.cle
  }
  return apres[0]?.cle ?? null
}

/** « 8 à traiter », pour le résumé de l'en-tête du téléphone. */
export function resumeATraiter(n: number): string {
  return n === 0 ? 'Rien à traiter' : `${n} à traiter`
}
