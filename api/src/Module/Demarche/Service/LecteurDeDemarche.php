<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

final readonly class LecteurDeDemarche
{
    public function __construct(
        public int $userId,
        public string $libelle,
        public bool $administrateur,
    ) {
    }
}
