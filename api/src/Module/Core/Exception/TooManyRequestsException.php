<?php

declare(strict_types=1);

namespace App\Module\Core\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * `429 trop_de_tentatives` — le dépassement d'un des limiteurs du § 1.9.
 *
 * Elle porte le délai en secondes, qui ressort **deux fois** : dans `details.retryAfter`
 * et dans l'en-tête `Retry-After` posé par le souscripteur d'exception. Le contrat exige
 * les deux (§ 1.8, § 15.2).
 *
 * Une exception nommée : `POST /api/inscription/rattachement` ne rend **jamais** cette
 * erreur sur son plafond par jeton — elle rend `200` avec zéro candidat, indistinguable
 * d'une recherche infructueuse (§ 4.2). C'est au module `Membership` de ne pas la lever
 * là, pas à celle-ci de le savoir.
 */
final class TooManyRequestsException extends DomainException
{
    public function __construct(
        private readonly int $retryAfter,
        string $message = 'Trop de tentatives. Réessayez dans un moment.',
    ) {
        parent::__construct($message);
    }

    public function statutHttp(): int
    {
        return Response::HTTP_TOO_MANY_REQUESTS;
    }

    public function codeErreur(): string
    {
        return 'trop_de_tentatives';
    }

    /** Toujours renseigné : c'est cette exception qui porte le délai (§ 15.2). */
    public function details(): array
    {
        return ['retryAfter' => $this->retryAfter];
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
