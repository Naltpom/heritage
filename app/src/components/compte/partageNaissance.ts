/**
 * Le choix de partage de la date de naissance (ADR 0016) — ce qui se calcule
 * sans monter de composant : le choix par défaut, la comparaison, les refus du
 * serveur et le résumé court de l'écran d'administration.
 */
import { estErreurApi, type PartageNaissance, type VerrouDePartage } from '@/lib/api'

/**
 * **Rien de coché par défaut.** Partager est un consentement : il se donne, il
 * ne se présume pas. Le candidat qui passe sans lire ne partage rien — et ne
 * voit rien des dates des autres vivants, ce que la phrase sous les cases dit.
 */
export function partageParDefaut(): PartageNaissance {
  return { annee: false, mois: false, jour: false }
}

/** Les trois clés seulement : `modifieLe` n'entre pas dans un corps. */
export function partageSeul(partage: PartageNaissance): PartageNaissance {
  return { annee: partage.annee, mois: partage.mois, jour: partage.jour }
}

export function memePartage(a: PartageNaissance, b: PartageNaissance): boolean {
  return a.annee === b.annee && a.mois === b.mois && a.jour === b.jour
}

/**
 * Les refus du serveur sur le partage : il les rend sous des clés pointées
 * (`partageNaissance.annee`…) dans le `422`. On les remet sous la clé de la case.
 */
export function erreursDePartage(
  champs: Record<string, string> | null | undefined,
): Partial<Record<keyof PartageNaissance, string>> {
  const erreurs: Partial<Record<keyof PartageNaissance, string>> = {}
  for (const cle of ['annee', 'mois', 'jour'] as const) {
    const message = champs?.[`partageNaissance.${cle}`] ?? ''
    if (message.length > 0) erreurs[cle] = message
  }
  return erreurs
}

/**
 * Le choix en une phrase courte : « année, mois et jour », « jour et mois »,
 * « rien ». L'ordre est celui de la lecture d'une date française — jour, mois,
 * année —, sauf quand tout est partagé, dit dans l'ordre des cases.
 */
export function resumePartage(partage: PartageNaissance): string {
  if (partage.annee && partage.mois && partage.jour) return 'année, mois et jour'
  const parties = [partage.jour ? 'jour' : null, partage.mois ? 'mois' : null, partage.annee ? 'année' : null]
    .filter((p): p is string => p !== null)
  if (parties.length === 0) return 'rien'
  if (parties.length === 1) return parties[0]!
  return `${parties[0]} et ${parties[1]}`
}

/** Les initiales cochées, pour une pastille : « A M J », « M J », « — ». */
export function initialesPartage(partage: PartageNaissance): string {
  const lettres = [partage.annee ? 'A' : null, partage.mois ? 'M' : null, partage.jour ? 'J' : null]
    .filter((l): l is string => l !== null)
  return lettres.length === 0 ? '—' : lettres.join(' ')
}

/** Le refus d'un retrait trop tôt (`409`) : la composante est verrouillée. */
export const CODE_PARTAGE_VERROUILLE = 'partage_verrouille'

/**
 * Le verrou que porte un refus `409 partage_verrouille` (`details.jusquAu`),
 * ou `null` pour toute autre erreur. Les valeurs qui ne sont pas des chaînes
 * sont ignorées : on n'affiche pas une date qu'on ne sait pas lire.
 */
export function verrouDuRefus(erreur: unknown): VerrouDePartage | null {
  if (!estErreurApi(erreur) || erreur.code !== CODE_PARTAGE_VERROUILLE) return null
  const jusquAu = erreur.details?.['jusquAu']
  const verrou: VerrouDePartage = {}
  if (typeof jusquAu === 'object' && jusquAu !== null) {
    for (const cle of ['annee', 'mois', 'jour'] as const) {
      const date = (jusquAu as Record<string, unknown>)[cle]
      if (typeof date === 'string') verrou[cle] = date
    }
  }
  return verrou
}

/**
 * Une case **cochée** que le verrou mentionne ne se décoche pas : la date dit
 * jusqu'à quand, au minimum. Une case non cochée n'est jamais bloquée —
 * partager est immédiat.
 */
export function verrouillee(
  partage: PartageNaissance,
  verrou: VerrouDePartage,
  cle: keyof PartageNaissance,
): string | null {
  return partage[cle] && verrou[cle] !== undefined ? verrou[cle] : null
}

/** « 2 novembre 2026 » — la fin d'un verrou, en toutes lettres. */
export function dateDuVerrou(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime())
    ? iso
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}

/** Une composante non partagée hier l'est dans le choix en cours. */
export function ajouteUneComposante(avant: PartageNaissance, apres: PartageNaissance): boolean {
  return (!avant.annee && apres.annee) || (!avant.mois && apres.mois) || (!avant.jour && apres.jour)
}
