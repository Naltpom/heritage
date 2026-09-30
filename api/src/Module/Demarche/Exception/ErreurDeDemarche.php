<?php

declare(strict_types=1);

namespace App\Module\Demarche\Exception;

use App\Module\Core\Exception\DomainException;
use Symfony\Component\HttpFoundation\Response;

final class ErreurDeDemarche extends DomainException
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
     * « N'existe pas » et « vous ne voyez pas l'une des personnes concernées » rendent la
     * même chose : la réponse n'apprend pas qu'une démarche existe sur un vivant hors du
     * cercle du lecteur.
     */
    public static function introuvable(): self
    {
        return new self('ressource_introuvable', 'Démarche introuvable.', Response::HTTP_NOT_FOUND);
    }

    public static function echangeIntrouvable(): self
    {
        return new self('ressource_introuvable', 'Message introuvable dans cette démarche.', Response::HTTP_NOT_FOUND);
    }

    public static function reserveALAuteur(): self
    {
        return new self('reserve_a_l_auteur', 'Seul l’auteur de la démarche, ou un administrateur, peut la supprimer.', Response::HTTP_FORBIDDEN);
    }

    public static function reserveAQuiLASaisi(): self
    {
        return new self('reserve_a_l_auteur', 'Seul celui qui a saisi ce message, ou un administrateur, peut le retirer.', Response::HTTP_FORBIDDEN);
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
