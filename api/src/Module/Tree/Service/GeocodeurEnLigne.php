<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

/**
 * Le recours **en ligne** de la résolution des lieux : situer une chaîne `PLAC` que les
 * référentiels locaux n'ont pas su lire (passe F de {@see AppariementDesLieux}).
 *
 * ## Pourquoi une interface
 *
 * Pour qu'aucun test ne touche jamais le réseau. L'implémentation réelle,
 * {@see GeocodeurNominatim}, reçoit un `HttpClientInterface` que les tests remplacent
 * par un `MockHttpClient` ; la commande, elle, ne connaît que ce contrat — et ignore
 * donc tout de l'URL, de l'en-tête `User-Agent` et du format de la réponse du tiers.
 *
 * ## Le contrat, en trois issues
 *
 * - un tableau **de la forme exacte** de {@see AppariementDesLieux::apparier()} : le lieu
 *   est situé, au niveau d'une commune ou plus fin ;
 * - `null` : le tiers a répondu, et **rien d'acceptable** — aucun résultat, ou un
 *   résultat trop grossier (un pays, une région pour une chaîne qui nomme une commune).
 *   C'est un verdict, et la commande le **mémorise** ;
 * - {@see GeocodageIndisponible} : le tiers n'a **pas** répondu — réseau, délai, `429`,
 *   `5xx`, corps illisible. Ce n'est **pas** un verdict : rien n'est mémorisé, et la
 *   prochaine exécution redemandera.
 *
 * Confondre les deux dernières serait le défaut à éviter : une panne d'une nuit
 * mémorisée comme « introuvable » ferait taire la question pendant trente jours.
 */
interface GeocodeurEnLigne
{
    /** Le nom de la passe, tel que la commande le rapporte. */
    public const string PASSE = 'en ligne';

    /** La confiance d'un lieu situé en ligne : sous toutes les passes locales. */
    public const int CONFIANCE = 50;

    /**
     * @param string $lieu la chaîne `PLAC` verbatim — **et rien d'autre** ne doit sortir
     *
     * @return array{libelle: string, commune: string|null, subdivision: string|null, pays: string|null, lat: float|null, lon: float|null, source: string, confiance: int, passe: string, candidats: list<string>}|null
     *
     * @throws GeocodageIndisponible si le tiers n'a pas répondu utilement
     */
    public function chercher(string $lieu): ?array;
}
