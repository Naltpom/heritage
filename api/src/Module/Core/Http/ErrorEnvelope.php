<?php

declare(strict_types=1);

namespace App\Module\Core\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * L'**enveloppe d'erreur unique** du § 15.1 du contrat d'API.
 *
 * Le dépôt de référence en émet deux — `{"error": "…"}` et `{"errors": {…}}` — et son
 * point d'entrée 401 émet la première. Heritage n'en a qu'une, et c'est ici qu'elle est
 * construite : point d'entrée 401, gestionnaire d'échec de connexion, souscripteur
 * d'exception et contrôleurs passent tous par ce fichier. Un second endroit qui
 * composerait la même forme à la main finirait par diverger.
 *
 * `champs` et `details` sont **présents à `null`** plutôt qu'absents : c'est la seule
 * exception assumée à la règle « absent contre null » du § 1.3 — un front qui doit tester
 * la présence de deux clés avant de les lire finira par oublier un test.
 */
final class ErrorEnvelope
{
    /**
     * @param array<string, string>|null $champs  Renseigné sur les 422 uniquement
     * @param array<string, mixed>|null  $details Structure propre au code d'erreur
     *
     * @return array{erreur: array{code: string, message: string, champs: array<string, string>|null, details: array<string, mixed>|null}}
     */
    public static function payload(string $code, string $message, ?array $champs = null, ?array $details = null): array
    {
        return [
            'erreur' => [
                'code' => $code,
                'message' => $message,
                'champs' => $champs,
                'details' => $details,
            ],
        ];
    }

    /**
     * @param array<string, string>|null $champs
     * @param array<string, mixed>|null  $details
     */
    public static function response(
        int $statut,
        string $code,
        string $message,
        ?array $champs = null,
        ?array $details = null,
    ): JsonResponse {
        return new JsonResponse(self::payload($code, $message, $champs, $details), $statut);
    }
}
