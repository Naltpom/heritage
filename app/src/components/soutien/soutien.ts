/**
 * La mise en forme de la page « Soutenir le site » — séparée de la vue pour
 * être vérifiée sans monter de composant.
 */

/** `3250, 'EUR'` → « 32,50 € ». Les centimes ronds perdent leurs décimales : « 45 € ». */
export function formaterMontant(centimes: number, devise = 'EUR'): string {
  const rond = centimes % 100 === 0
  return new Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency: devise,
    minimumFractionDigits: rond ? 0 : 2,
    maximumFractionDigits: rond ? 0 : 2,
  }).format(centimes / 100)
}

/** `2026-10` → « octobre 2026 ». */
export function libelleDuMois(mois: string): string {
  const [annee, numero] = mois.split('-').map(Number)
  if (!annee || !numero) return mois
  return new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(
    new Date(Date.UTC(annee, numero - 1, 1)),
  )
}

/**
 * La part de l'objectif couverte, de 0 à 100 — bornée à 100 pour la barre,
 * le texte disant le vrai montant. `null` sans objectif.
 */
export function partDeLObjectif(centimesParMois: number, objectifEuros: number): number | null {
  if (objectifEuros <= 0) return null
  return Math.max(0, Math.min(100, Math.round((centimesParMois / (objectifEuros * 100)) * 100)))
}

/** Ce que Liberapay accepte dans un pseudo — la même règle que le serveur. */
export function pseudoValide(pseudo: string): boolean {
  return /^[A-Za-z0-9_.-]{1,32}$/.test(pseudo) && pseudo.replace(/\./g, '') !== ''
}
