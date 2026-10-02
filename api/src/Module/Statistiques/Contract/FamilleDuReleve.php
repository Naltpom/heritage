<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

use App\Module\Core\ValueObject\UncertainDate;

/**
 * Une union : ses deux époux (l'un peut manquer), ses dates, ses enfants dans l'ordre de
 * la fratrie. `generation` est la colonne dérivée de `tree_family`, lue depuis la racine.
 */
final readonly class FamilleDuReleve
{
    /**
     * @param list<int> $enfants identifiants de personnes, dans l'ordre de la fratrie
     */
    public function __construct(
        public int $id,
        public ?int $mariId,
        public ?int $epouseId,
        public UncertainDate $mariage,
        public bool $divorce,
        public UncertainDate $dateDuDivorce,
        public array $enfants,
        public ?int $generation,
    ) {
    }
}
