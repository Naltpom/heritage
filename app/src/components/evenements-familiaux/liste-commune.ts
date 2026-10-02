/**
 * **Une seule liste d'événements** — rassemblements et tirages au sort mêlés.
 *
 * Demande du commanditaire (02/10/2026) : « le tirage au sort, c'est un
 * événement en soi ». Côté utilisateur, il n'y a donc plus qu'une entrée
 * « Événements », une liste, un bouton « Créer un événement » qui demande
 * d'abord le type. Côté serveur, rien n'est fusionné : les modules `Evenement`
 * et `Tirage` gardent leurs tables, leurs routes et leurs règles (ADR 0014,
 * addendum). La fusion se fait **ici**, à la lecture : deux appels
 * (`GET /api/evenements`, `GET /api/tirages`), une liste.
 *
 * Pourquoi pas un point d'entrée qui agrège côté API : il ne ferait que
 * recopier les deux sérialisations, et chaque module garde la main sur ce que
 * son lecteur voit (le secret des tirages, le suivi des rassemblements). Deux
 * appels parallèles coûtent le temps d'un ; une panne de l'un laisse l'autre
 * s'afficher.
 *
 * Ce fichier ne charge rien : il range. Suite : `liste-commune.test.ts`.
 */

import type { ResumeDEvenement, ResumeDeTirage } from '@/lib/api'
import { aMidi, ecartEnJours, prochaineOccurrence } from '@/components/accueil/jours'
import { etatDeLEvenement, lireDate } from './evenements'

/** Les deux sortes d'événements. Le mot « rassemblement » est celui du commanditaire. */
export type TypeDEvenement = 'rassemblement' | 'tirage'

export const TYPES_D_EVENEMENT: readonly { valeur: TypeDEvenement; libelle: string; description: string }[] = [
  {
    valeur: 'rassemblement',
    libelle: 'Rassemblement',
    description: 'Une cousinade, un anniversaire, des retrouvailles : on invite, chacun dit s’il vient.',
  },
  {
    valeur: 'tirage',
    libelle: 'Tirage au sort',
    description: 'Chacun tire au sort à qui offrir un cadeau — Noël, une fête. Tous les ans.',
  },
]

export function libelleDuType(type: TypeDEvenement): string {
  return type === 'tirage' ? 'Tirage au sort' : 'Rassemblement'
}

/**
 * Un type lu dans l'adresse (`?type=tirage`). Tout le reste — absent, inconnu,
 * répété — vaut `null` : la liste montre tout, la création demande le type.
 */
export function lireTypeDEvenement(brut: unknown): TypeDEvenement | null {
  return brut === 'rassemblement' || brut === 'tirage' ? brut : null
}

/** Un élément de la liste commune : l'un ou l'autre, avec la date qui le range. */
export type ElementDeLaListe =
  | { type: 'rassemblement'; cle: string; date: Date; evenement: ResumeDEvenement }
  | { type: 'tirage'; cle: string; date: Date; tirage: ResumeDeTirage }

/**
 * La prochaine date d'un tirage — il revient **tous les ans** : l'édition
 * ouverte quand elle est à venir (sa date peut différer du jour habituel, un
 * Noël fêté le 26), sinon la prochaine occurrence du jour habituel.
 */
export function dateDuTirage(tirage: Pick<ResumeDeTirage, 'jour' | 'mois' | 'prochaineEdition'>, aujourdhui: Date): Date {
  const edition = tirage.prochaineEdition
  if (edition !== null && !edition.passee) {
    const date = lireDate(edition.date)
    if (date !== null && ecartEnJours(aujourdhui, date) >= 0) return date
  }
  return prochaineOccurrence(tirage, aujourdhui)
}

function rassemblement(e: ResumeDEvenement): ElementDeLaListe {
  return { type: 'rassemblement', cle: `rassemblement-${e.id}`, date: lireDate(e.debut) ?? aMidi(new Date(0)), evenement: e }
}

function tirage(t: ResumeDeTirage, aujourdhui: Date): ElementDeLaListe {
  return { type: 'tirage', cle: `tirage-${t.id}`, date: dateDuTirage(t, aujourdhui), tirage: t }
}

export function titreDeLElement(e: ElementDeLaListe): string {
  return e.type === 'tirage' ? e.tirage.nom : e.evenement.titre
}

/** Le plus proche d'abord ; à date égale, par titre — un ordre qui ne saute pas d'un chargement à l'autre. */
function parDate(a: ElementDeLaListe, b: ElementDeLaListe): number {
  return a.date.getTime() - b.date.getTime() || titreDeLElement(a).localeCompare(titreDeLElement(b), 'fr')
}

/**
 * Les deux listes de l'écran « Événements » :
 *
 * - **à venir** — les rassemblements à venir ou en cours, et **tous** les
 *   tirages (ils reviennent chaque année : un tirage n'est jamais « passé »),
 *   le plus proche d'abord ;
 * - **passés et annulés** — les rassemblements seulement, le plus récent
 *   d'abord.
 *
 * `type` restreint à une sorte (`null` : les deux).
 */
export function fusionner(
  evenements: readonly ResumeDEvenement[],
  tirages: readonly ResumeDeTirage[],
  aujourdhui: Date,
  type: TypeDEvenement | null = null,
): { aVenir: ElementDeLaListe[]; passes: ElementDeLaListe[] } {
  const aVenir: ElementDeLaListe[] = []
  const passes: ElementDeLaListe[] = []

  if (type !== 'tirage') {
    for (const e of evenements) {
      const etat = etatDeLEvenement(e, aujourdhui)
      if (etat === 'a_venir' || etat === 'en_cours') aVenir.push(rassemblement(e))
      else passes.push(rassemblement(e))
    }
  }
  if (type !== 'rassemblement') {
    for (const t of tirages) aVenir.push(tirage(t, aujourdhui))
  }

  aVenir.sort(parDate)
  passes.sort((a, b) => -parDate(a, b))
  return { aVenir, passes }
}

/**
 * **Mes événements**, pour l'accueil : tout ce qui m'attend, rassemblements et
 * tirages, le plus proche d'abord.
 *
 * - un rassemblement à venir ou en cours, **où je suis invité ou que
 *   j'organise** (ni annulé, ni terminé) ;
 * - un tirage **dont je suis ou que j'organise** — pas un tirage simplement
 *   ouvert à la famille : il se rejoint depuis la liste, l'accueil parle de
 *   ce qui me concerne déjà.
 */
export function mesEvenements(
  evenements: readonly ResumeDEvenement[],
  tirages: readonly ResumeDeTirage[],
  aujourdhui: Date,
): ElementDeLaListe[] {
  return fusionner(
    evenements.filter((e) => e.suisInvite || e.organisateur.estMoi),
    tirages.filter((t) => t.suisParticipant || t.organisateur.estMoi),
    aujourdhui,
  ).aVenir
}
