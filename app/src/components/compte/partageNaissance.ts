/**
 * Le choix de partage de la date de naissance (ADR 0016, révision du 7 octobre
 * 2026) — ce qui se calcule sans monter de composant : le choix par défaut, la
 * comparaison, les refus du serveur et le résumé court de l'écran
 * d'administration.
 */
import {
  estErreurApi,
  type ComposantesCochees,
  type NiveauDePartage,
  type PartageNaissance,
  type VerrouDePartage,
} from '@/lib/api'

/** Les trois niveaux, dans l'ordre des boutons : du plus ouvert au plus fermé. */
export const NIVEAUX: readonly { niveau: NiveauDePartage; libelle: string }[] = [
  { niveau: 'tous', libelle: 'Tous les vivants de l’arbre' },
  { niveau: 'proches', libelle: 'Mes proches' },
  { niveau: 'personne', libelle: 'Personne' },
]

/**
 * **Les proches par défaut.** Le candidat qui passe sans lire montre sa date à
 * ses proches — parents, enfants, frères et sœurs, conjoint, beaux-parents —,
 * qui la connaissent déjà, et voit la leur. Personne d'autre.
 */
export function partageParDefaut(): PartageNaissance {
  return { annee: 'proches', mois: 'proches', jour: 'proches' }
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
 * Des cases cochées en une phrase courte : « année, mois et jour », « jour et
 * mois », « rien ». L'ordre est celui de la lecture d'une date française —
 * jour, mois, année —, sauf quand tout est coché, dit dans l'ordre des cases.
 */
export function resumeComposantes(composantes: ComposantesCochees): string {
  if (composantes.annee && composantes.mois && composantes.jour) return 'année, mois et jour'
  const parties = [composantes.jour ? 'jour' : null, composantes.mois ? 'mois' : null, composantes.annee ? 'année' : null]
    .filter((p): p is string => p !== null)
  if (parties.length === 0) return 'rien'
  if (parties.length === 1) return parties[0]!
  return `${parties[0]} et ${parties[1]}`
}

const NIVEAU_COURT: Readonly<Record<NiveauDePartage, string>> = {
  tous: 'tous',
  proches: 'proches',
  personne: 'personne',
}

/**
 * Le choix en une ligne, par niveau, du plus ouvert au plus fermé :
 * « année, mois et jour : proches », « jour et mois : tous · année : personne ».
 */
export function resumePartage(partage: PartageNaissance): string {
  return NIVEAUX.map(({ niveau }) => {
    const composantes = {
      annee: partage.annee === niveau,
      mois: partage.mois === niveau,
      jour: partage.jour === niveau,
    }
    return composantes.annee || composantes.mois || composantes.jour
      ? `${resumeComposantes(composantes)} : ${NIVEAU_COURT[niveau]}`
      : null
  })
    .filter((p): p is string => p !== null)
    .join(' · ')
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
 * Une composante **à « tous »** que le verrou mentionne ne redescend pas : la
 * date dit jusqu'à quand, au minimum. Une composante à « proches » ou
 * « personne » n'est jamais bloquée — partager est immédiat, et entre ces deux
 * niveaux on va et vient librement.
 */
export function verrouillee(
  partage: PartageNaissance,
  verrou: VerrouDePartage,
  cle: keyof PartageNaissance,
): string | null {
  return partage[cle] === 'tous' && verrou[cle] !== undefined ? verrou[cle] : null
}

/** « 2 novembre 2026 » — la fin d'un verrou, en toutes lettres. */
export function dateDuVerrou(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime())
    ? iso
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}

/** Une composante qui n'était pas à « tous » y passe dans le choix en cours : le verrou va courir. */
export function ouvreATous(avant: PartageNaissance, apres: PartageNaissance): boolean {
  return (['annee', 'mois', 'jour'] as const).some((cle) => avant[cle] !== 'tous' && apres[cle] === 'tous')
}
