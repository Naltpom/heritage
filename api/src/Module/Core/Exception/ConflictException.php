<?php

declare(strict_types=1);

namespace App\Module\Core\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * `409` — l'état courant est incompatible avec l'action demandée.
 *
 * Le code est passé au constructeur parce que le § 15.2 en compte une douzaine
 * (`demande_caduque`, `verrou_concurrent`, `vue_comme_membre_active`,
 * `dernier_administrateur`…) et qu'ils ne se distinguent que par lui : deux `409` du
 * contrat donnent des conseils **opposés** au front (§ 15.4), c'est la chaîne de code qui
 * les sépare, jamais le message.
 */
final class ConflictException extends DomainException
{
    /** @param array<string, mixed>|null $details */
    public function __construct(
        private readonly string $codeErreur,
        string $message,
        private readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public function statutHttp(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function codeErreur(): string
    {
        return $this->codeErreur;
    }

    public function details(): ?array
    {
        return $this->details;
    }
}
