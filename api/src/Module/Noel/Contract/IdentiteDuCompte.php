<?php

declare(strict_types=1);

namespace App\Module\Noel\Contract;

/**
 * Qui est un compte, pour un tirage de Noël : un prénom, un nom, et de quoi calculer un
 * âge — la date déclarée à l'inscription, sinon l'année de naissance de sa fiche.
 */
final readonly class IdentiteDuCompte
{
    public function __construct(
        public int $userId,
        public string $prenom,
        public string $nom,
        public ?\DateTimeImmutable $naissance,
        public ?int $anneeNaissance,
        public ?int $personId,
    ) {
    }
}
