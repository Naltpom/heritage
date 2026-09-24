<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Contract\AccesAuModele;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * L'appel HTTP à Gemini — même discipline que `Question\Service\RedactionParGemini`.
 *
 * - **La clé voyage en en-tête, jamais dans l'URL** : une URL entre dans les journaux
 *   d'accès des mandataires et les rapports d'erreur, un en-tête non.
 * - **`max_duration` borne l'appel entier**, établissement compris : sans lui, un tiers
 *   qui répond lentement mais régulièrement tiendrait la requête indéfiniment.
 * - **La réponse n'est jamais journalisée** : elle cite les documents du membre.
 */
final readonly class TransportGemini implements TransportDuModele
{
    public function __construct(
        private AccesAuModele $acces,
        private HttpClientInterface $http,
    ) {
    }

    public function generer(string $modele, array $charge, float $delai): array
    {
        $reponse = $this->http->request(
            'POST',
            \sprintf('%s/models/%s:generateContent', $this->acces->pointDacces(), $modele),
            [
                'headers' => ['x-goog-api-key' => $this->acces->cleApi()],
                'json' => $charge,
                'timeout' => $delai,
                'max_duration' => $delai,
            ],
        );

        // `toArray()` lève sur un statut ≥ 300 comme sur un corps non JSON : les deux
        // deviennent une `502` chez l'appelant, sans que la réponse soit lue ailleurs.
        return $reponse->toArray();
    }
}
