<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

/** Ce que l'organisateur règle : le nom, le jour, le lieu et les deux règles du tirage. */
final readonly class ReglesDuTirage
{
    public function __construct(
        public string $nom,
        public int $jour,
        public int $mois,
        public ?string $lieu,
        public bool $ouvert,
        public int $memoire,
        public bool $exclureConjoints,
    ) {
    }
}
