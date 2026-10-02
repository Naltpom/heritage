<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

use App\Module\Core\ValueObject\UncertainDate;

/**
 * Un fait daté ou non : une profession (« Tisserand ») ou une résidence. Le lieu est dans
 * `date->getPlace()`, comme partout ailleurs. Un fait de famille porte `familyId` et pas
 * de `personId`.
 */
final readonly class FaitDuReleve
{
    public function __construct(
        public NatureDuFait $nature,
        public ?int $personId,
        public ?int $familyId,
        public ?string $libelle,
        public UncertainDate $date,
    ) {
    }
}
