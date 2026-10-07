<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Exception;

use App\Module\Core\Exception\DomainException;
use Symfony\Component\HttpFoundation\Response;

final class ErreurDeMessagerie extends DomainException
{
    /**
     * @param array<string, string>|null $champs
     */
    private function __construct(
        private readonly string $codeErreur,
        string $message,
        private readonly int $statut,
        private readonly ?array $champs = null,
    ) {
        parent::__construct($message);
    }

    /**
     * « N'existe pas » et « c'est le fil d'un autre membre » rendent la même chose : la
     * réponse n'apprend pas qu'un autre a écrit à l'administration.
     */
    public static function introuvable(): self
    {
        return new self('ressource_introuvable', 'Conversation introuvable.', Response::HTTP_NOT_FOUND);
    }

    /** @param array<string, string> $champs */
    public static function donneesInvalides(array $champs): self
    {
        return new self('donnees_invalides', 'Les données envoyées sont invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $champs);
    }

    public function statutHttp(): int
    {
        return $this->statut;
    }

    public function codeErreur(): string
    {
        return $this->codeErreur;
    }

    public function champs(): ?array
    {
        return $this->champs;
    }
}
