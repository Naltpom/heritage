<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

use App\Module\Core\Contract\ReferenceInterModules;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Service\ReleveurDeReferences;

/** `tml_preference.user_id` — la seule table du module, la seule flèche. */
final readonly class ReferencesSortantesDeLaChronologie implements ReferencesInterModulesInterface
{
    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function references(): array
    {
        return [
            new ReferenceInterModules('tml_preference.user_id', CibleDeReference::Compte, $this->releveur->occurrences('tml_preference', 'user_id')),
        ];
    }
}
