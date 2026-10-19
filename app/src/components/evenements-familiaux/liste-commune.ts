/**
 * **Une seule liste d'événements** — rassemblements et tirages de Noël mêlés.
 *
 * Demande du commanditaire (02/10/2026) : « le tirage au sort, c'est un
 * événement en soi ». Côté utilisateur, il n'y a donc qu'une entrée
 * « Événements », une liste, un bouton « Créer un événement » qui demande
 * d'abord le type.
 *
 * Le tirage au sort propre à heritage (module `Tirage`) a depuis été retiré :
 * les tirages qu'on montre sont ceux du **site de Noël** (noel2026, module
 * `Noel` du même serveur). Ils vivent là-bas — on y répond, on y règle — et
 * heritage ne fait que les montrer à côté des rassemblements. Côté serveur,
 * rien n'est fusionné : la fusion se fait **ici**, à la lecture, à partir de
 * deux appels (`GET /api/evenements`, `GET /api/noel/…`).
 *
 * Deux appels parallèles coûtent le temps d'un ; une panne de l'un laisse
 * l'autre s'afficher.
 *
 * **La date qui range un tirage de Noël est celle de la remise des cadeaux**
 * (`dateEvenement`), pas celle du tirage : c'est le jour où la famille se
 * retrouve, celui qu'on cherche dans une liste d'événements ; la date du
 * tirage, elle, est dite sur la carte (« Tirage dans 12 jours »). Une fois la
 * remise passée, le tirage rejoint les passés.
 *
 * Ce fichier ne charge rien : il range. Suite : `liste-commune.test.ts`.
 */

import type { ResumeDEvenement, ResumeDuTirageDeNoel } from '@/lib/api'
import { aMidi } from '@/components/accueil/jours'
import { etatDeLEvenement, lireDate } from './evenements'
import { estPasse, lireInstant } from './noel'

/** Les deux sortes d'événements. Le mot « rassemblement » est celui du commanditaire. */
export type TypeDEvenement = 'rassemblement' | 'noel'

export const TYPES_D_EVENEMENT: readonly { valeur: TypeDEvenement; libelle: string; description: string }[] = [
  {
    valeur: 'rassemblement',
    libelle: 'Rassemblement',
    description: 'Une cousinade, un anniversaire, des retrouvailles : on invite, chacun dit s’il vient.',
  },
  {
    valeur: 'noel',
    libelle: 'Tirage de Noël',
    description: 'Chacun tire au sort à qui offrir un cadeau. Il se crée et se règle sur le site de Noël.',
  },
]

export function libelleDuType(type: TypeDEvenement): string {
  return type === 'noel' ? 'Noël' : 'Rassemblement'
}

/**
 * Un type lu dans l'adresse (`?type=noel`). `tirage` — l'ancien filtre, que
 * portent des favoris et l'ancienne adresse `/tirages` — vaut `noel`. Tout le
 * reste — absent, inconnu, répété — vaut `null` : la liste montre tout, la
 * création demande le type.
 */
export function lireTypeDEvenement(brut: unknown): TypeDEvenement | null {
  if (brut === 'rassemblement' || brut === 'noel') return brut
  return brut === 'tirage' ? 'noel' : null
}

/** Un élément de la liste commune : l'un ou l'autre, avec la date qui le range. */
export type ElementDeLaListe =
  | { type: 'rassemblement'; cle: string; date: Date; evenement: ResumeDEvenement }
  | { type: 'noel'; cle: string; date: Date; tirage: ResumeDuTirageDeNoel }

function rassemblement(e: ResumeDEvenement): ElementDeLaListe {
  return { type: 'rassemblement', cle: `rassemblement-${e.id}`, date: lireDate(e.debut) ?? aMidi(new Date(0)), evenement: e }
}

function noel(t: ResumeDuTirageDeNoel): ElementDeLaListe {
  return { type: 'noel', cle: `noel-${t.code}`, date: lireInstant(t.dateEvenement) ?? aMidi(new Date(0)), tirage: t }
}

export function titreDeLElement(e: ElementDeLaListe): string {
  return e.type === 'noel' ? e.tirage.titre : e.evenement.titre
}

/** Le plus proche d'abord ; à date égale, par titre — un ordre qui ne saute pas d'un chargement à l'autre. */
function parDate(a: ElementDeLaListe, b: ElementDeLaListe): number {
  return a.date.getTime() - b.date.getTime() || titreDeLElement(a).localeCompare(titreDeLElement(b), 'fr')
}

/**
 * Les deux listes de l'écran « Événements » :
 *
 * - **à venir** — les rassemblements à venir ou en cours, les tirages de Noël
 *   dont la remise n'est pas passée, le plus proche d'abord ;
 * - **passés et annulés** — le reste, le plus récent d'abord.
 *
 * `type` restreint à une sorte (`null` : les deux).
 */
export function fusionner(
  evenements: readonly ResumeDEvenement[],
  tirages: readonly ResumeDuTirageDeNoel[],
  aujourdhui: Date,
  type: TypeDEvenement | null = null,
): { aVenir: ElementDeLaListe[]; passes: ElementDeLaListe[] } {
  const aVenir: ElementDeLaListe[] = []
  const passes: ElementDeLaListe[] = []

  if (type !== 'noel') {
    for (const e of evenements) {
      const etat = etatDeLEvenement(e, aujourdhui)
      if (etat === 'a_venir' || etat === 'en_cours') aVenir.push(rassemblement(e))
      else passes.push(rassemblement(e))
    }
  }
  if (type !== 'rassemblement') {
    for (const t of tirages) (estPasse(t, aujourdhui) ? passes : aVenir).push(noel(t))
  }

  aVenir.sort(parDate)
  passes.sort((a, b) => -parDate(a, b))
  return { aVenir, passes }
}

/**
 * **Mes événements**, pour l'accueil : tout ce qui m'attend, rassemblements et
 * tirages de Noël, le plus proche d'abord.
 *
 * - un rassemblement à venir ou en cours, **où je suis invité ou que
 *   j'organise** (ni annulé, ni terminé) ;
 * - un tirage de Noël à venir de `GET /api/noel/mes-tirages` — déjà « les
 *   miens » : créés, ou auxquels j'ai répondu (même « non » : la carte le dit,
 *   et tant que c'est ouvert on peut changer d'avis sur le site de Noël).
 */
export function mesEvenements(
  evenements: readonly ResumeDEvenement[],
  tirages: readonly ResumeDuTirageDeNoel[],
  aujourdhui: Date,
): ElementDeLaListe[] {
  return fusionner(
    evenements.filter((e) => e.suisInvite || e.organisateur.estMoi),
    tirages,
    aujourdhui,
  ).aVenir
}
