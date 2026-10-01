/**
 * Les quotas d'usage dans l'écran Utilisateurs — la logique sans gabarit, pour
 * qu'elle se teste sans monter de composant.
 *
 * Deux règles portent tout :
 *
 * - **Champ vide = défaut.** L'éditeur ne montre pas le défaut dans le champ
 *   (il est rappelé à côté, et en indication grisée) : un champ pré-rempli à
 *   « 20 » enregistrerait 20 comme limite personnelle au premier clic, et le
 *   compte ne suivrait plus un changement du défaut. Vider le champ rend le
 *   défaut (`null` envoyé, ligne supprimée côté serveur).
 * - **On n'envoie que ce qui change.** Un enregistrement sans modification
 *   n'écrit rien, et une limite personnelle égale au défaut reste personnelle
 *   tant que l'administrateur ne vide pas son champ.
 *
 * La validation ici n'est qu'un confort : les bornes appliquées sont celles du
 * serveur, et ses messages (`champs["limites.<clé>"]`) s'affichent tels quels.
 */
import type { LimitesDeQuotas, QuotaGlobal } from '@/lib/api'

/** La borne haute du serveur (`QuotasDUsage::LIMITE_MAXIMALE`). */
export const LIMITE_MAXIMALE = 10_000

/** « 12 / 60 » — la consommation sur la limite effective. */
export function texteDuQuota(quota: QuotaGlobal): string {
  return `${quota.consomme} / ${quota.limite}`
}

/** L'usage est fermé (0) ou épuisé : la ligne se lit en alerte. */
export function quotaAtteint(quota: QuotaGlobal): boolean {
  return quota.consomme >= quota.limite
}

/** La phrase d'accessibilité et d'infobulle d'une ligne. */
export function descriptionDuQuota(quota: QuotaGlobal & { exempte?: boolean }): string {
  if (quota.exempte === true) return `${quota.libelle} : non compté pour un administrateur`
  const origine = quota.personnalisee
    ? `limite personnalisée (défaut ${quota.limiteParDefaut})`
    : 'limite par défaut'
  return `${quota.libelle} : ${quota.consomme} sur ${quota.limite} ${quota.fenetre}, ${origine}`
}

/** Le brouillon de l'éditeur : la limite personnelle, ou vide quand c'est le défaut. */
export function brouillonDesQuotas(quotas: readonly QuotaGlobal[]): Record<string, string> {
  return Object.fromEntries(quotas.map((q) => [q.cle, q.personnalisee ? String(q.limite) : '']))
}

export interface LectureDuBrouillon {
  /** Seulement les clés qui changent. Vide : rien à enregistrer. */
  limites: LimitesDeQuotas
  /** Par clé de quota, le message à afficher sous le champ. */
  erreurs: Record<string, string>
}

/**
 * Lit le brouillon. `valeur` peut arriver en nombre : `v-model` sur un champ
 * `type="number"` convertit de lui-même.
 */
export function lireLeBrouillon(
  quotas: readonly QuotaGlobal[],
  brouillon: Readonly<Record<string, string | number>>,
): LectureDuBrouillon {
  const limites: LimitesDeQuotas = {}
  const erreurs: Record<string, string> = {}

  for (const quota of quotas) {
    const brut = String(brouillon[quota.cle] ?? '').trim()
    const actuelle = quota.personnalisee ? quota.limite : null

    if (brut === '') {
      if (actuelle !== null) limites[quota.cle] = null
      continue
    }

    if (!/^\d+$/.test(brut) || Number(brut) > LIMITE_MAXIMALE) {
      erreurs[quota.cle] = `Un nombre entier de 0 à ${LIMITE_MAXIMALE}, ou vide pour le défaut.`
      continue
    }

    const valeur = Number(brut)
    if (valeur !== actuelle) limites[quota.cle] = valeur
  }

  return { limites, erreurs }
}

/** Les messages du serveur (`limites.<clé>`), ramenés à la clé du quota. */
export function erreursDuServeur(champs: Readonly<Record<string, string>> | null): Record<string, string> {
  if (champs === null) return {}
  return Object.fromEntries(
    Object.entries(champs)
      .filter(([champ]) => champ.startsWith('limites.'))
      .map(([champ, message]) => [champ.slice('limites.'.length), message]),
  )
}
