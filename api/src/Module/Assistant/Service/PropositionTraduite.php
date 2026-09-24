<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

/**
 * Une demande proposée par le modèle, traduite en corps bien formé — **pas encore
 * vérifiée** par `Proposal`.
 */
final readonly class PropositionTraduite
{
    /**
     * @param 'haute'|'moyenne'|'faible'|string $confiance
     * @param array<string, mixed>              $corps
     */
    public function __construct(
        public string $titre,
        public string $justification,
        public string $confiance,
        public array $corps,
    ) {
    }
}
