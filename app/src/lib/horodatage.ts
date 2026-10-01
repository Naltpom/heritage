/**
 * Un horodatage d'API (`createdAt`, `appliedAt` — ATOM, § 1.4), rendu **avec son
 * heure** : « 12 sept. 2026, 18:04 ».
 *
 * C'est la forme des éléments d'une fiche — notes, sources, documents, et les
 * entrées de son historique. Une seule fonction pour tous, pour que deux blocs
 * de la même page ne datent pas la même chose de deux façons.
 *
 * Comme `horodatageLisible()` (`components/personne/champs.ts`), ce n'est **pas**
 * une date généalogique : c'est un instant d'horloge, sans qualificatif ni
 * fourchette. Une valeur illisible est rendue telle quelle plutôt que
 * « Invalid Date » : mieux vaut la chaîne du serveur qu'un message de moteur.
 */
export function horodatageAvecHeure(valeur: string): string {
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleString('fr-FR', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
}
