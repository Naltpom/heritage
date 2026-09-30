<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

use App\Module\Demarche\Enum\StatutDeDemarche;

final readonly class SuiviDeDemarche
{
    public function __construct(
        public StatutDeDemarche $statut,
        public ?string $resultat,
    ) {
    }
}
