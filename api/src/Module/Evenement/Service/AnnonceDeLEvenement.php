<?php

declare(strict_types=1);

namespace App\Module\Evenement\Service;

/**
 * Ce que l'organisateur écrit de son événement : le titre, les deux dates, le lieu et la
 * description. Les invités sont à part — ils s'ajoutent et se retirent un par un, chacun
 * avec sa notification.
 */
final readonly class AnnonceDeLEvenement
{
    public function __construct(
        public string $titre,
        public \DateTimeImmutable $debut,
        public \DateTimeImmutable $fin,
        public ?string $lieu,
        public ?string $description,
    ) {
    }
}
