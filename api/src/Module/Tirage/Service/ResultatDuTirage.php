<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

final readonly class ResultatDuTirage
{
    /**
     * @param array<int, int> $attributions     donneur ⇒ receveur, par identifiant de personne
     * @param int             $memoireAppliquee le nombre d'années passées effectivement respectées
     */
    public function __construct(
        public array $attributions,
        public int $memoireAppliquee,
    ) {
    }
}
