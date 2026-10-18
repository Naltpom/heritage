<?php

declare(strict_types=1);

namespace App\Module\Noel\Service;

/** Ce que l'organisateur règle. `null` : inchangé (modification). */
final readonly class ReglagesDuTirageNoel
{
    public function __construct(
        public ?string $titre,
        public ?\DateTimeImmutable $dateTirage,
        public ?\DateTimeImmutable $dateEvenement,
        public ?int $budget,
        public ?string $lieu,
        public ?string $message,
        public bool $lieuFourni = false,
        public bool $messageFourni = false,
    ) {
    }
}
