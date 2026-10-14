/**
 * La pagination d'une liste servie par l'API (§ 1.5), en fonctions pures —
 * partagées par `PaginationListe.vue` et les écrans qui tiennent la page dans
 * leur adresse (`?page=2`).
 */

/** Le nombre de pages, jamais moins d'une : une liste vide a une page, vide. */
export function nombreDePages(total: number, parPage: number): number {
  return Math.max(1, Math.ceil(Math.max(0, total) / Math.max(1, parPage)))
}

/** « 26–50 » : la première et la dernière ligne de la page, comptées à partir de 1. */
export function bornesDeLaPage(page: number, parPage: number, total: number): { debut: number; fin: number } {
  if (total <= 0) return { debut: 0, fin: 0 }
  const debut = Math.min(total, (page - 1) * parPage + 1)
  return { debut, fin: Math.min(total, page * parPage) }
}

/**
 * `?page=` : un entier strictement positif, sinon la première page. Une adresse
 * bricolée (`page=abc`, `page=0`) ne doit pas produire une liste vide sans raison.
 */
export function pageDepuisRequete(valeur: unknown): number {
  const brut = Array.isArray(valeur) ? valeur[0] : valeur
  const page = Number(brut)
  return Number.isInteger(page) && page > 0 ? page : 1
}

/**
 * La page à relire quand la page courante s'est vidée (la dernière ligne vient
 * d'être décidée) : celle d'avant, sans descendre sous 1. Une page encore
 * peuplée reste où elle est.
 */
export function pageApresRetrait(page: number, restantesSurLaPage: number): number {
  return restantesSurLaPage === 0 && page > 1 ? page - 1 : page
}
