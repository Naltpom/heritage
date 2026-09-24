<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

/**
 * Le géocodeur en ligne n'a **pas répondu** — réseau, délai, quota, erreur serveur,
 * réponse illisible.
 *
 * Distinct à dessein d'un « rien trouvé » (`null` de {@see GeocodeurEnLigne::chercher()}) :
 * celui-ci n'est pas un verdict sur le lieu, et ne doit donc pas être mémorisé comme tel.
 * La commande l'attrape, **cesse d'interroger le tiers pour le reste de l'exécution** —
 * une panne ne se sonde pas cent fois de suite —, le rapporte, et sort quand même
 * `SUCCESS` : une bulle qui manque n'est pas une panne du produit.
 */
final class GeocodageIndisponible extends \RuntimeException
{
}
